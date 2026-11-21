<?php

namespace App\Services\Payments;

use App\Exceptions\PixKeyNotFoundException;
use App\Models\Customer;
use App\Models\Movement;
use App\Services\Credit\AutomaticCreditWallet;
use App\Services\Ledger\MovementLedger;
use App\Services\Invites\InviteDepositBonus;
use App\Services\Payments\Contracts\PaymentProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class AsaasPaymentProvider implements PaymentProvider
{
    public function __construct(
        private readonly MovementLedger $ledger,
        private readonly InviteDepositBonus $inviteDepositBonus,
        private readonly AutomaticCreditWallet $automaticCredit,
    )
    {
    }

    public function createPixDeposit(float $amount, array $metadata = []): PaymentOperation
    {
        $customer = Customer::findOrFail($metadata['customer_id'] ?? null);
        $asaasCustomerId = $this->asaasCustomerId($customer);
        $payment = $this->client()->post('/v3/payments', [
            'customer' => $asaasCustomerId,
            'billingType' => 'PIX',
            'value' => round($amount, 2),
            'dueDate' => now()->addDay()->toDateString(),
            'description' => 'Carga MpMM® · PIX',
            'externalReference' => 'mpmm-deposit-'.$metadata['conversation_session_id'],
        ])->throw()->json();
        $qrCode = $this->client()->get('/v3/payments/'.$payment['id'].'/pixQrCode')->throw()->json();

        return new PaymentOperation(
            provider: 'asaas',
            externalId: $payment['id'],
            receiptUrl: $payment['invoiceUrl'] ?? null,
            metadata: [
                'status' => 'pending',
                'payment_method' => 'pix',
                'copy_paste' => $qrCode['payload'],
                'expiration_date' => $qrCode['expirationDate'] ?? null,
                'asaas_customer_id' => $asaasCustomerId,
            ],
        );
    }

    public function handleWebhook(string $payload, array $headers = []): array
    {
        $expectedToken = (string) config('payments.providers.asaas.webhook_token');
        $receivedToken = (string) ($headers['asaas-access-token'][0] ?? $headers['Asaas-Access-Token'][0] ?? '');

        if ($expectedToken === '' || ! hash_equals($expectedToken, $receivedToken)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Invalid Asaas webhook token.');
        }

        $event = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        $payment = $event['payment'] ?? [];
        $transfer = $event['transfer'] ?? [];

        if (! empty($transfer['id']) && str_starts_with((string) ($event['event'] ?? ''), 'TRANSFER_')) {
            return $this->handleTransferWebhook($event, $transfer);
        }

        if (($event['event'] ?? null) !== 'PAYMENT_RECEIVED' || empty($payment['id'])) {
            return ['provider' => 'asaas', 'handled' => false, 'event' => $event['event'] ?? null];
        }

        return DB::transaction(function () use ($event, $payment) {
            $movement = Movement::where('external_provider', 'asaas')
                ->where('external_operation_id', $payment['id'])
                ->lockForUpdate()
                ->first();

            if (! $movement) {
                return ['provider' => 'asaas', 'handled' => false, 'duplicate' => false];
            }

            if ($movement->event === 'deposit.confirmed') {
                $metadata = $movement->metadata ?? [];

                return array_filter([
                    'provider' => 'asaas',
                    'handled' => false,
                    'duplicate' => true,
                    'movement_id' => $movement->id,
                    'notification' => ($metadata['notification_status'] ?? null) === 'sent'
                        ? null
                        : $this->depositNotification($movement),
                    'inviter_notification' => ($metadata['notification_status'] ?? null) === 'sent'
                        ? null
                        : $this->inviteDepositBonus->firstDepositNotification($movement),
                ], fn ($value) => $value !== null);
            }

            $amount = (float) ($payment['value'] ?? $movement->metadata['amount'] ?? 0);
            $customer = Customer::whereKey($movement->customer_id)->lockForUpdate()->firstOrFail();
            $balance = (float) $customer->balance + $amount;
            $metadata = $movement->metadata ?? [];

            $customer->forceFill(['balance' => $balance])->save();
            $movement->forceFill([
                'credit' => $amount,
                'balance' => $balance,
                'event' => 'deposit.confirmed',
                'metadata' => [
                    ...$metadata,
                    'status' => 'received',
                    'asaas_webhook_event_id' => $event['id'] ?? null,
                    'net_value' => $payment['netValue'] ?? null,
                    'received_at' => now()->toISOString(),
                    'notification_status' => 'pending',
                ],
            ])->save();

            $this->inviteDepositBonus->award($movement);

            return [
                'provider' => 'asaas',
                'handled' => true,
                'movement_id' => $movement->id,
                'notification' => $this->depositNotification($movement),
                'inviter_notification' => $this->inviteDepositBonus->firstDepositNotification($movement),
            ];
        });
    }

    public function recordDepositNotification(int $movementId, bool $sent, array $delivery = []): void
    {
        DB::transaction(function () use ($movementId, $sent, $delivery) {
            $movement = Movement::whereKey($movementId)->lockForUpdate()->first();

            if (! $movement || $movement->event !== 'deposit.confirmed') {
                return;
            }

            $metadata = $movement->metadata ?? [];

            if (($metadata['notification_status'] ?? null) === 'sent') {
                return;
            }

            $movement->forceFill([
                'metadata' => [
                    ...$metadata,
                    'notification_status' => $sent ? 'sent' : 'pending',
                    'notification_attempted_at' => now()->toISOString(),
                    'notification_sent_at' => $sent ? now()->toISOString() : null,
                    'notification_delivery' => $delivery,
                ],
            ])->save();
        });
    }

    private function depositNotification(Movement $movement): array
    {
        $movement->loadMissing('customer');

        return [
            'to' => $movement->customer?->phone,
            'amount' => (float) $movement->credit,
        ];
    }

    private function handleTransferWebhook(array $event, array $transfer): array
    {
        return DB::transaction(function () use ($event, $transfer) {
            $movement = Movement::where('external_provider', 'asaas')
                ->where('external_operation_id', $transfer['id'])
                ->lockForUpdate()
                ->first();

            if (! $movement) {
                return ['provider' => 'asaas', 'handled' => false, 'reason' => 'transfer_not_found'];
            }

            $eventName = (string) ($event['event'] ?? '');
            $metadata = $movement->metadata ?? [];

            if (in_array($eventName, ['TRANSFER_FAILED', 'TRANSFER_CANCELLED'], true)) {
                if ($movement->event === 'redeem_pix.failed') {
                    return ['provider' => 'asaas', 'handled' => false, 'duplicate' => true];
                }

                $customer = Customer::whereKey($movement->customer_id)->lockForUpdate()->firstOrFail();
                $this->ledger->credit($customer, (float) $movement->debit, 'redeem_pix.reversed', [
                    'conversation_session_id' => $movement->conversation_session_id,
                    'external_provider' => 'asaas',
                    'external_operation_id' => 'reversal-'.$transfer['id'],
                    'metadata' => [
                        'original_movement_id' => $movement->id,
                        'asaas_webhook_event_id' => $event['id'] ?? null,
                        'reason' => $transfer['failReason'] ?? $eventName,
                    ],
                ]);
                $this->automaticCredit->restoreFailedWithdrawal($customer, $movement);
                $movement->forceFill([
                    'event' => 'redeem_pix.failed',
                    'metadata' => [...$metadata, 'status' => $eventName, 'fail_reason' => $transfer['failReason'] ?? null],
                ])->save();

                return ['provider' => 'asaas', 'handled' => true, 'reversed' => true];
            }

            $movement->forceFill([
                'external_receipt_url' => $transfer['transactionReceiptUrl'] ?? $movement->external_receipt_url,
                'metadata' => [...$metadata, 'status' => $eventName],
            ])->save();

            return ['provider' => 'asaas', 'handled' => true, 'reversed' => false];
        });
    }

    public function createBoletoDeposit(float $amount, array $metadata = []): PaymentOperation
    {
        $customer = Customer::findOrFail($metadata['customer_id'] ?? null);
        $asaasCustomerId = $this->asaasCustomerId($customer);
        $document = preg_replace('/\D+/', '', (string) $customer->cpf) ?: '';
        $phone = preg_replace('/\D+/', '', $customer->phone) ?: '';
        $documentLabel = strlen($document) === 14 ? 'CNPJ' : 'CPF';
        $formattedAmount = number_format($amount, 2, ',', '.');
        $dueDate = now()->addDays(max(0, (int) config('payments.cash_in.boleto_due_days', 93)))->toDateString();
        $payment = $this->client()->post('/v3/payments', [
            'customer' => $asaasCustomerId,
            'billingType' => 'BOLETO',
            'value' => round($amount, 2),
            'dueDate' => $dueDate,
            'description' => "Boleto gerado pelo membro, {$documentLabel} {$document}, para carga de R$ {$formattedAmount} em seu Cartão +{$phone}. Saldo será atualizado e ficará disponível após a compensação bancária do pagamento, em até 3 dias úteis.",
            'externalReference' => 'mpmm-deposit-'.$metadata['conversation_session_id'],
        ])->throw()->json();
        $identification = $this->client()
            ->get('/v3/payments/'.$payment['id'].'/identificationField')
            ->throw()
            ->json();

        return new PaymentOperation(
            provider: 'asaas',
            externalId: $payment['id'],
            receiptUrl: $payment['bankSlipUrl'] ?? $payment['invoiceUrl'] ?? null,
            metadata: [
                'status' => 'pending',
                'payment_method' => 'boleto',
                'boleto_line' => $identification['identificationField'],
                'bar_code' => $identification['barCode'] ?? null,
                'asaas_customer_id' => $asaasCustomerId,
                'customer_document' => $document,
                'customer_whatsapp' => $phone,
                'due_date' => $dueDate,
            ],
        );
    }

    public function createCardDeposit(float $amount, array $metadata = []): PaymentOperation
    {
        $customer = Customer::findOrFail($metadata['customer_id'] ?? null);
        $asaasCustomerId = $this->asaasCustomerId($customer, true);
        $cardType = ($metadata['card_type'] ?? 'credit') === 'debit' ? 'debit' : 'credit';
        $paymentMethod = $cardType.'_card';
        $dueDate = now()->toDateString();
        $payment = $this->client()->post('/v3/payments', [
            'customer' => $asaasCustomerId,
            // The hosted Asaas invoice offers both credit and debit card payment.
            'billingType' => 'CREDIT_CARD',
            'value' => round($amount, 2),
            'dueDate' => $dueDate,
            'description' => 'Carga MpMM® por cartão de '.($cardType === 'debit' ? 'débito' : 'crédito'),
            'externalReference' => 'mpmm-deposit-'.($metadata['conversation_session_id'] ?? uniqid()).'-'.$cardType,
        ])->throw()->json();

        $paymentUrl = $payment['invoiceUrl'] ?? null;

        if (! is_string($paymentUrl) || $paymentUrl === '') {
            throw new \RuntimeException('Asaas did not return a hosted invoice URL for the card charge.');
        }

        return new PaymentOperation(
            provider: 'asaas',
            externalId: $payment['id'],
            receiptUrl: $paymentUrl,
            metadata: [
                'status' => 'pending',
                'payment_method' => $paymentMethod,
                'card_type' => $cardType,
                'billing_type' => 'CREDIT_CARD',
                'payment_url' => $paymentUrl,
                'asaas_customer_id' => $asaasCustomerId,
                'due_date' => $dueDate,
            ],
        );
    }

    public function sendPix(float $amount, string $pixKey, array $metadata = []): PaymentOperation
    {
        $phoneKey = $this->phonePixKey($pixKey);
        $this->assertPhonePixKeyExists($phoneKey);

        $transfer = $this->client()->post('/v3/transfers', [
            'value' => round($amount, 2),
            'operationType' => 'PIX',
            'pixAddressKey' => $phoneKey,
            'pixAddressKeyType' => 'PHONE',
            'description' => 'Resgate Pix MpMM®',
            'externalReference' => 'mpmm-redeem-'.($metadata['conversation_session_id'] ?? uniqid()),
        ])->throw()->json();

        if (in_array($transfer['status'] ?? null, ['FAILED', 'CANCELLED'], true)) {
            throw new \RuntimeException('Asaas refused the Pix transfer.');
        }

        return new PaymentOperation(
            provider: 'asaas',
            externalId: $transfer['id'],
            receiptUrl: $transfer['transactionReceiptUrl'] ?? null,
            metadata: [
                'status' => $transfer['status'] ?? 'PENDING',
                'operation_type' => 'PIX',
                'transfer_fee' => $transfer['transferFee'] ?? 0,
            ],
        );
    }

    private function assertPhonePixKeyExists(string $phoneKey): void
    {
        if (strlen($phoneKey) !== 11) {
            throw new PixKeyNotFoundException('The WhatsApp number is not a valid phone Pix key.');
        }

        $response = $this->client()->get('/v3/pix/addressKeys/external', [
            'type' => 'PHONE',
            'key' => $phoneKey,
        ]);

        if ($response->status() === 400 || $response->status() === 404) {
            throw new PixKeyNotFoundException('No Pix key exists for the WhatsApp number.');
        }

        $response->throw();
    }

    private function phonePixKey(string $pixKey): string
    {
        $digits = preg_replace('/\D+/', '', $pixKey) ?: '';

        return str_starts_with($digits, '55') ? substr($digits, 2) : $digits;
    }

    public function createDeposit(float $amount, array $metadata = []): PaymentOperation
    {
        return $this->createPixDeposit($amount, $metadata);
    }

    public function payPix(float $amount, string $pixKey, array $metadata = []): PaymentOperation
    {
        return $this->sendPix($amount, $pixKey, $metadata);
    }

    private function asaasCustomerId(Customer $customer, bool $useWhatsappAsName = false): string
    {
        $metadata = $customer->metadata ?? [];
        $phone = preg_replace('/\D+/', '', $customer->phone) ?: '';
        $whatsappName = '+'.$phone;

        if (! empty($metadata['asaas_customer_id'])) {
            if ($useWhatsappAsName && ($metadata['asaas_customer_name'] ?? null) !== $whatsappName) {
                $this->client()->put('/v3/customers/'.$metadata['asaas_customer_id'], [
                    'name' => $whatsappName,
                ])->throw();
                $metadata['asaas_customer_name'] = $whatsappName;
                $customer->forceFill(['metadata' => $metadata])->save();
            }

            return $metadata['asaas_customer_id'];
        }

        $response = $this->client()->post('/v3/customers', [
            'name' => $useWhatsappAsName
                ? $whatsappName
                : ($customer->name ?: $customer->whatsapp_profile_name ?: 'Membro MpMM '.$customer->id),
            'cpfCnpj' => preg_replace('/\D+/', '', (string) $customer->cpf),
            'mobilePhone' => $phone,
            'externalReference' => 'mpmm-customer-'.$customer->id,
            'notificationDisabled' => true,
        ])->throw()->json();

        $metadata['asaas_customer_id'] = $response['id'];
        if ($useWhatsappAsName) {
            $metadata['asaas_customer_name'] = $whatsappName;
        }
        $customer->forceFill(['metadata' => $metadata])->save();

        return $response['id'];
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('payments.providers.asaas.base_url'), '/'))
            ->connectTimeout(5)
            ->timeout(15)
            ->withOptions([
                'verify' => filter_var(
                    config('payments.providers.asaas.verify_ssl', true),
                    FILTER_VALIDATE_BOOLEAN,
                ),
            ])
            ->acceptJson()
            ->asJson()
            ->withHeaders(['access_token' => (string) config('payments.providers.asaas.api_key')]);
    }
}
