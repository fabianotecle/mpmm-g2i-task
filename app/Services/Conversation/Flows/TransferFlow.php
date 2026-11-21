<?php

namespace App\Services\Conversation\Flows;

use App\Models\ConversationSession;
use App\Models\Customer;
use App\Models\WhatsappNotificationDelivery;
use App\Services\Bonus\BonusAwardService;
use App\Services\Bonus\BonusEvents;
use App\Services\Conversation\Contracts\ConversationFlow;
use App\Services\Conversation\ConversationResponse;
use App\Services\Conversation\Flows\Concerns\ParsesConversationInput;
use App\Services\Documents\TransferReceiptImageService;
use App\Services\Ledger\MovementLedger;
use App\Services\Installments\InstallmentService;
use App\Services\RotativeWallet\RotativeCreditService;
use App\Services\Phone\PhoneNumberFormatter;
use App\Services\Phone\PhoneNumberNormalizer;
use App\Services\Time\PhoneTimezoneResolver;
use App\Services\Transfers\BatchTransferSpreadsheet;
use App\Services\WhatsApp\WhatsappClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class TransferFlow implements ConversationFlow
{
    use ParsesConversationInput;

    public function __construct(
        private readonly MovementLedger $ledger,
        private readonly RotativeCreditService $rotativeCredits,
        private readonly PhoneNumberNormalizer $phones,
        private readonly PhoneNumberFormatter $phoneFormatter,
        private readonly PhoneTimezoneResolver $timezones,
        private readonly BonusAwardService $bonuses,
        private readonly BatchTransferSpreadsheet $batchSpreadsheet,
        private readonly WhatsappClient $whatsapp,
        private readonly TransferReceiptImageService $receiptImage,
        private readonly InstallmentService $installments,
    ) {}

    public function enter(ConversationSession $session): array
    {
        $session->forceFill([
            'current_step' => 'waiting_recipient',
            'context' => [],
        ])->save();

        return ConversationResponse::make([
            ConversationResponse::text("👤 *QUEM irá receber o valor?*\n\nVocê pode transferir para qualquer número de whatsapp.\n\n_Digite o número ou compartilhe o contato_."),
        ], [
            'type' => 'phone_or_file',
            'field' => 'destinatario',
        ]);
    }

    /** Prefills a transfer from a signed QR; the sender still confirms it. */
    public function enterFromQr(ConversationSession $session, Customer $recipient, ?float $amount): array
    {
        $context = [
            'recipient_customer_id' => $recipient->id,
            'destinatario' => $recipient->phone,
            'payment_qr' => ($session->context ?? [])['payment_qr'] ?? [],
        ];

        if ($amount !== null) {
            $context['valor_transf'] = $amount;
            $session->forceFill(['current_step' => 'waiting_confirmation', 'context' => $context])->save();

            return ConversationResponse::make([
                ConversationResponse::options($this->confirmationText($context), $this->confirmationOptions($session), 'Escolher opção'),
            ], ['type' => 'option', 'field' => 'transfer_confirmation']);
        }

        $session->forceFill(['current_step' => 'waiting_amount', 'context' => $context])->save();

        return ConversationResponse::make([
            ConversationResponse::text('â†—ï¸ *Informe o valor que deseja pagar.*'),
        ], $this->transferAmountInput($session));
    }

    public function handle(ConversationSession $session, string $message): array
    {
        if ($this->isCancel($message)) {
            return ConversationResponse::make([ConversationResponse::text('Operação cancelada.')], null, null, 'main_menu');
        }

        return match ($session->current_step) {
            'waiting_recipient' => $this->captureRecipient($session, $message),
            'waiting_batch_confirmation' => $this->confirmBatch($session, $message),
            'waiting_amount' => $this->captureAmount($session, $message),
            'waiting_confirmation' => $this->confirm($session, $message),
            'waiting_installment_count', 'waiting_installment_confirmation' => ConversationResponse::make([], null, null, 'installment'),
            'waiting_credit_confirmation' => $this->confirmCreditUse($session, $message),
            'waiting_transfer_result_action' => $this->handleTransferResultAction($session, $message),
            default => $this->enter($session),
        };
    }

    private function captureRecipient(ConversationSession $session, string $message): array
    {
        $context = $session->context ?? [];

        if ($document = $this->inboundDocument($context)) {
            return $this->captureBatchSpreadsheet($session, $document);
        }

        if ($this->normalized($message) === 'lote') {
            return ConversationResponse::make([
                ConversationResponse::text('Envie a planilha XLSX com a tabela "destinatarios" e as colunas DDI, Telefone e Valor.'),
            ], [
                'type' => 'file',
                'field' => 'arquivo_transf_lote',
                'accept' => '.xlsx',
            ]);
        }

        $phone = $this->phones->normalize($message, $session->external_user_id);

        if (! $phone) {
            return ConversationResponse::make([
                ConversationResponse::text('Não consegui identificar este telefone. Informe o número com DDI e DDD, ou somente o número local.'),
            ], [
                'type' => 'phone',
                'field' => 'destinatario',
            ]);
        }

        $recipient = $this->findRecipient($phone);

        $recipient ??= Customer::firstOrCreate(
            ['phone' => $phone],
            [
                'status' => 'active',
                'acquisition_origin' => 'network',
                'balance' => 0,
                'redeemable_balance' => 0,
                'limit' => 0,
                'timezone' => $this->timezones->resolve($phone),
                'metadata' => ['created_by_transfer' => true],
            ],
        );

        $context['recipient_customer_id'] = $recipient->id;
        $context['destinatario'] = $recipient->phone;
        $session->forceFill([
            'current_step' => 'waiting_amount',
            'context' => $context,
        ])->save();

        return ConversationResponse::make([
            ConversationResponse::text('↗️ *QUANTO deseja transferir?*'),
        ], [
            'type' => 'money',
            'field' => 'valor_transf',
            'min' => 0.01,
            'max' => $this->maxTransferableAmount($session->customer?->fresh()),
        ]);
    }

    private function captureBatchSpreadsheet(ConversationSession $session, array $document): array
    {
        $filename = (string) ($document['filename'] ?? '');
        $mimeType = (string) ($document['mime_type'] ?? '');
        $mediaId = (string) ($document['id'] ?? '');

        if (! str_ends_with(mb_strtolower($filename), '.xlsx')
            && $mimeType !== 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
            return ConversationResponse::make([
                ConversationResponse::text('Envie o arquivo no formato XLSX.'),
            ], ['type' => 'file', 'field' => 'arquivo_transf_lote', 'accept' => '.xlsx']);
        }

        try {
            $rows = $this->batchSpreadsheet->parse($this->whatsapp->downloadMedia($mediaId));
            $normalized = [];

            foreach ($rows as $index => $row) {
                $phone = $this->phones->normalizeKnownNumber($row['ddi'].$row['phone']);

                if (! $phone) {
                    throw new \RuntimeException('Telefone inválido na linha '.($index + 2).'.');
                }

                if ($phone === $session->external_user_id) {
                    throw new \RuntimeException('A planilha contém o telefone do próprio remetente na linha '.($index + 2).'.');
                }

                if (isset($normalized[$phone])) {
                    $normalized[$phone]['amount'] = round($normalized[$phone]['amount'] + $row['amount'], 2);
                } else {
                    $normalized[$phone] = ['phone' => $phone, 'amount' => $row['amount']];
                }
            }
        } catch (Throwable $exception) {
            return ConversationResponse::make([
                ConversationResponse::text('Não consegui importar a planilha: '.$exception->getMessage()),
            ], ['type' => 'file', 'field' => 'arquivo_transf_lote', 'accept' => '.xlsx']);
        }

        $rows = array_values($normalized);
        $total = round(array_sum(array_column($rows, 'amount')), 2);
        $context = [
            'batch_transfer' => [
                'filename' => $filename,
                'rows' => $rows,
                'total' => $total,
            ],
        ];

        $session->forceFill([
            'current_step' => 'waiting_batch_confirmation',
            'context' => $context,
        ])->save();

        return ConversationResponse::make([
            ConversationResponse::options(
                "✅ *Confirma a transferência em lote?*\n\n"
                .'Destinatários: '.count($rows)."\n"
                .'Valor total: R$ '.$this->formatMoney($total)."\n\n"
                .'*Estas transferências não poderão ser desfeitas.*',
                [
                    ['label' => '❌ Cancelar', 'value' => 'cancelar'],
                    ['label' => '✅ Confirmar', 'value' => 'confirmar'],
                ],
            ),
        ], ['type' => 'option', 'field' => 'batch_transfer_confirmation']);
    }

    private function confirmBatch(ConversationSession $session, string $message): array
    {
        if ($this->isCancel($message)) {
            return ConversationResponse::make([
                ConversationResponse::text('Transferência em lote cancelada.'),
            ], null, null, 'main_menu');
        }

        if (! $this->isConfirm($message)) {
            return ConversationResponse::make([
                ConversationResponse::text('Escolha Confirmar ou Cancelar.'),
            ], ['type' => 'option', 'field' => 'batch_transfer_confirmation']);
        }

        $batch = ($session->context ?? [])['batch_transfer'] ?? null;

        if (! is_array($batch) || empty($batch['rows'])) {
            return ConversationResponse::make([
                ConversationResponse::text('A planilha não está mais disponível. Envie o arquivo novamente.'),
            ], null, null, 'transfer');
        }

        $session->loadMissing('customer');
        $total = round((float) ($batch['total'] ?? 0), 2);

        if ($total <= 0 || $total > $this->maxTransferableAmount($session->customer)) {
            return ConversationResponse::make([
                $this->insufficientBalanceMessage($this->maxTransferableAmount($session->customer)),
            ], ['type' => 'option', 'field' => 'batch_transfer_confirmation']);
        }

        $batchId = (string) Str::uuid();
        $notifications = DB::transaction(function () use ($session, $batch, $total, $batchId) {
            $sender = Customer::whereKey($session->customer_id)->lockForUpdate()->firstOrFail();

            if ($total > $this->maxTransferableAmount($sender)) {
                return null;
            }

            $notifications = [];

            foreach ($batch['rows'] as $row) {
                $recipient = Customer::firstOrCreate(
                    ['phone' => $row['phone']],
                    [
                        'status' => 'active',
                        'acquisition_origin' => 'network',
                        'balance' => 0,
                        'redeemable_balance' => 0,
                        'limit' => 0,
                        'timezone' => $this->timezones->resolve($row['phone']),
                        'metadata' => ['created_by_transfer' => true],
                    ],
                );
                $recipient = Customer::whereKey($recipient->id)->lockForUpdate()->firstOrFail();
                $amount = round((float) $row['amount'], 2);
                $transferId = (string) Str::uuid();
                $walletTransfer = $this->rotativeCredits->transfer($sender, $recipient, $amount, [
                    'conversation_session_id' => $session->id,
                    'metadata' => [
                        'transfer_id' => $transferId,
                        'batch_transfer_id' => $batchId,
                        'recipient' => $recipient->phone,
                        'recipient_whatsapp_name' => $recipient->whatsappDisplayName(),
                        'anonymous' => false,
                    ],
                ], [
                    'origin_customer_id' => $sender->id,
                    'conversation_session_id' => $session->id,
                    'metadata' => [
                        'transfer_id' => $transferId,
                        'batch_transfer_id' => $batchId,
                        'sender_customer_id' => $sender->id,
                        'sender_whatsapp_name' => $sender->whatsappDisplayName(),
                    ],
                ]);
                $sent = $walletTransfer['sent'];
                $received = $walletTransfer['received'];
                $receivedMetadata = $received->metadata ?? [];
                $receivedMetadata['source_movement_id'] = $sent->id;
                $received->forceFill(['metadata' => $receivedMetadata])->save();

                $this->bonuses->award(BonusEvents::TRANSFER_SENT, $sender, [
                    'source_type' => 'movement', 'source_id' => $sent->id, 'amount' => $amount,
                    'origin_customer_id' => $recipient->id, 'conversation_session_id' => $session->id,
                ]);
                $this->bonuses->award(BonusEvents::TRANSFER_RECEIVED, $recipient, [
                    'source_type' => 'movement', 'source_id' => $received->id, 'amount' => $amount,
                    'origin_customer_id' => $sender->id, 'conversation_session_id' => $session->id,
                ]);
                $notification = $this->recipientNotification(
                    $session,
                    $recipient,
                    $amount,
                    false,
                    $this->needsWelcomeNotification($recipient),
                    null,
                    $transferId,
                );
                $this->persistRecipientNotification($notification);
                $notifications[] = $notification;
            }

            return $notifications;
        });

        if ($notifications === null) {
            return ConversationResponse::make([
                $this->insufficientBalanceMessage($this->maxTransferableAmount(Customer::find($session->customer_id))),
            ], ['type' => 'option', 'field' => 'batch_transfer_confirmation']);
        }

        return ConversationResponse::make([
            ConversationResponse::text(
                "✅ *Transferência em lote concluída!*\n\n"
                .'Destinatários: '.count($batch['rows'])."\n"
                .'Valor total: R$ '.$this->formatMoney($total),
            ),
        ], null, null, 'main_menu', $notifications);
    }

    private function inboundDocument(array $context): ?array
    {
        $document = $context['metadata']['raw']['document'] ?? null;

        return is_array($document) && ! empty($document['id']) ? $document : null;
    }

    private function captureAmount(ConversationSession $session, string $message): array
    {
        $amount = $this->moneyFromMessage($message);
        $cents = $amount === null ? 0 : (int) round($amount * 100);
        $available = $this->maxTransferableAmount($session->customer?->fresh());

        if ($cents < 1 || $cents > (int) round($available * 100)
            || abs(($amount ?? 0) - $cents / 100) > 0.000001) {
            return ConversationResponse::make([
                ConversationResponse::text('Informe um valor entre R$ 0,01 e R$ '.number_format($available, 2, ',', '.').'.'),
            ], $this->transferAmountInput($session));
        }

        $context = $session->context ?? [];
        $context['valor_transf'] = $amount;

        $session->forceFill([
            'current_step' => 'waiting_confirmation',
            'context' => $context,
        ])->save();

        return ConversationResponse::make([
            ConversationResponse::options($this->confirmationText($context), $this->confirmationOptions($session), 'Escolher opção'),
        ], [
            'type' => 'option',
            'field' => 'transfer_confirmation',
        ]);
    }

    private function confirm(ConversationSession $session, string $message): array
    {
        $choice = $this->normalized($message);

        if (in_array($choice, ['parcelar', '🗓️ parcelar'], true)
            && $session->customer) {
            $context = $session->context ?? [];

            return app(InstallmentFlow::class)->enterFromTransfer(
                $session,
                (float) ($context['valor_transf'] ?? 0),
                Customer::find($context['recipient_customer_id'] ?? null),
            );
        }

        if ($this->wantsToChangeRecipient($choice)) {
            return $this->changeRecipient($session);
        }

        if ($this->wantsToChangeAmount($choice)) {
            $context = $session->context ?? [];
            unset($context['valor_transf'], $context['anonymous'], $context['credit_warning_accepted_at']);

            $session->forceFill([
                'current_step' => 'waiting_amount',
                'context' => $context,
            ])->save();

            return ConversationResponse::make([
                ConversationResponse::text('↗️ *QUANTO deseja transferir?*'),
            ], [
                'type' => 'money',
                'field' => 'valor_transf',
                'min' => 0.01,
                'max' => $this->maxTransferableAmount($session->customer?->fresh()),
            ]);
        }

        $context = $session->context ?? [];
        $anonymous = ($context['anonymous'] ?? false) || in_array($choice, [
            'anonima',
            'anonimo',
            'anônima',
            'anônimo',
            'envio anonimo',
            'envio anônimo',
            '👤envio anônimo',
            'particular',
            'envio particular',
            '👤envio particular',
            'envio privado',
            '👤 envio privado',
        ], true);

        if (! $this->isConfirm($message) && ! $anonymous) {
            return ConversationResponse::make([ConversationResponse::text('Escolha Confirmar, Envio Particular, Alterar valor, Alterar Para Quem ou Cancelar.')]);
        }

        $session->loadMissing('customer');

        if (($context['recipient_customer_id'] ?? null) === $session->customer_id
            || in_array($session->external_user_id, $this->phoneCandidates($context['destinatario'] ?? ''), true)) {
            $restart = $this->enter($session);

            return ConversationResponse::make([
                ConversationResponse::text('⌚ Um momento, por favor... estamos processando seu pedido.'),
                ConversationResponse::text('Você não pode transferir valores para você mesmo. 😉'),
                ...$restart['messages'],
            ], $restart['expected_input']);
        }

        $available = $this->maxTransferableAmount($session->customer);

        if ((float) $context['valor_transf'] > $available) {
            $session->forceFill(['current_step' => 'waiting_transfer_result_action'])->save();

            return ConversationResponse::make([
                ConversationResponse::text('⌚ Um momento, por favor... estamos processando seu pedido.'),
                $this->insufficientBalanceMessage($available),
            ], [
                'type' => 'option',
                'field' => 'transfer_result_action',
            ]);
        }

        if ((float) $context['valor_transf'] > max(0, (float) $session->customer?->balance)
            + max(0, (float) $session->customer?->bonus_credit_balance)
            && empty($context['credit_warning_accepted_at'])) {
            $context['anonymous'] = $anonymous;
            $session->forceFill([
                'current_step' => 'waiting_credit_confirmation',
                'context' => $context,
            ])->save();

            return $this->creditUseWarningResponse();
        }

        $recipient = Customer::find($context['recipient_customer_id'] ?? null)
            ?? $this->findRecipient($context['destinatario'] ?? '');

        if (! $recipient) {
            return ConversationResponse::make([
                ConversationResponse::text('Não encontrei um Cartão ativo para este destinatário. A transferência não foi realizada.'),
            ], null, null, 'main_menu');
        }

        $transferId = (string) Str::uuid();

        $transfer = DB::transaction(function () use ($session, $recipient, $context, $anonymous, $transferId) {
            $sender = Customer::whereKey($session->customer_id)->lockForUpdate()->firstOrFail();
            $lockedRecipient = Customer::whereKey($recipient->id)->lockForUpdate()->firstOrFail();
            $amount = (float) $context['valor_transf'];
            $fundingType = $amount > max(0, (float) $sender->balance) ? 'Crédito' : 'Débito';
            $creditUsedAmount = max(0, $amount - max(0, (float) $sender->balance)
                - max(0, (float) $sender->bonus_credit_balance));

            if ($amount > $this->maxTransferableAmount($sender)) {
                return null;
            }

            if ($creditUsedAmount > 0 && empty($context['credit_warning_accepted_at'])) {
                return ['credit_confirmation_required' => true];
            }

            $walletTransfer = $this->rotativeCredits->transfer($sender, $lockedRecipient, $amount, [
                'conversation_session_id' => $session->id,
                'metadata' => [
                    'transfer_id' => $transferId,
                    'recipient' => $context['destinatario'],
                    'recipient_whatsapp_name' => $lockedRecipient->whatsappDisplayName(),
                    'anonymous' => $anonymous,
                    'template' => 'aviso_transferencia_v1',
                    'credit_used_amount' => $creditUsedAmount,
                    'credit_warning_accepted_at' => $context['credit_warning_accepted_at'] ?? null,
                ],
            ], [
                'origin_customer_id' => $session->customer_id,
                'conversation_session_id' => $session->id,
                'metadata' => [
                    'transfer_id' => $transferId,
                    'sender_customer_id' => $session->customer_id,
                    'sender_whatsapp_name' => $sender->whatsappDisplayName(),
                    'anonymous' => $anonymous,
                ],
            ]);
            $movement = $walletTransfer['sent'];
            $receivedMovement = $walletTransfer['received'];
            $receivedMetadata = $receivedMovement->metadata ?? [];
            $receivedMetadata['source_movement_id'] = $movement->id;
            $receivedMovement->forceFill(['metadata' => $receivedMetadata])->save();
            $fundingType = $walletTransfer['normal_amount'] > 0 && $walletTransfer['rotative_amount'] > 0
                ? 'Débito + Crédito'
                : ($walletTransfer['rotative_amount'] > 0 ? 'Crédito' : 'Débito');

            $this->persistRecipientNotification($this->recipientNotification(
                $session,
                $lockedRecipient,
                $amount,
                $anonymous,
                $this->needsWelcomeNotification($lockedRecipient),
                null,
                $transferId,
            ));

            return [
                'sent' => $movement,
                'received' => $receivedMovement,
                'funding_type' => $fundingType,
            ];
        });

        if (! $transfer) {
            $session->forceFill(['current_step' => 'waiting_transfer_result_action'])->save();

            return ConversationResponse::make([
                $this->insufficientBalanceMessage(
                    $this->maxTransferableAmount(Customer::find($session->customer_id)),
                ),
            ], ['type' => 'option', 'field' => 'transfer_result_action']);
        }

        if ($transfer['credit_confirmation_required'] ?? false) {
            $context['anonymous'] = $anonymous;
            $session->forceFill([
                'current_step' => 'waiting_credit_confirmation',
                'context' => $context,
            ])->save();

            return $this->creditUseWarningResponse();
        }

        $movement = $transfer['sent'];
        $receivedMovement = $transfer['received'];
        $context['funding_type'] = $transfer['funding_type'];
        $sender = Customer::find($session->customer_id);
        $recipient = Customer::find($recipient->id);

        if (! $sender || ! $recipient) {
            return ConversationResponse::make([
                ConversationResponse::text('NÃ£o foi possÃ­vel concluir os registros da transferÃªncia.'),
            ], null, null, 'main_menu');
        }

        $this->bonuses->award(BonusEvents::TRANSFER_SENT, $sender, [
            'source_type' => 'movement',
            'source_id' => $movement->id,
            'amount' => (float) $context['valor_transf'],
            'origin_customer_id' => $recipient->id,
            'conversation_session_id' => $session->id,
        ]);

        $this->bonuses->award(BonusEvents::TRANSFER_RECEIVED, $recipient, [
            'source_type' => 'movement',
            'source_id' => $receivedMovement->id,
            'amount' => (float) $context['valor_transf'],
            'origin_customer_id' => $session->customer_id,
            'conversation_session_id' => $session->id,
        ]);

        $session->forceFill([
            'current_step' => 'waiting_transfer_result_action',
            'context' => [
                ...$context,
                'movement_id' => $movement->id,
                'anonymous' => $anonymous,
            ],
        ])->save();

        $receiptImage = $this->receiptImage->generate(
            (float) $context['valor_transf'],
            $anonymous
                ? $this->maskedWhatsappNumber($session->external_user_id)
                : $this->formatWhatsappNumber($session->external_user_id),
            $anonymous
                ? $this->maskedWhatsappNumber($recipient->phone)
                : $this->formatWhatsappNumber($recipient->phone),
            ($movement->created_at ?? Carbon::now())->copy()->timezone($session->customer?->timezone ?: config('app.timezone')),
            $anonymous,
            $context['funding_type'] ?? 'Débito',
            'MPMM-'.$movement->id.'-'.$transferId,
            $session->customer?->whatsappDisplayName(),
            $recipient->whatsappDisplayName(),
        );
        $recipientReceiptImage = $this->receiptImage->generate(
            (float) $context['valor_transf'],
            $anonymous ? 'Particular' : $this->formatWhatsappNumber($session->external_user_id),
            $this->formatWhatsappNumber($recipient->phone),
            ($movement->created_at ?? Carbon::now())->copy()->timezone($recipient->timezone ?: config('app.timezone')),
            $anonymous,
            $context['funding_type'] ?? 'Débito',
            'MPMM-'.$movement->id.'-'.$transferId,
            $anonymous ? null : $session->customer?->whatsappDisplayName(),
            $anonymous ? null : $recipient->whatsappDisplayName(),
        );

        return ConversationResponse::make([
            ConversationResponse::text('⌚ Um momento, por favor... estamos processando seu pedido.'),
            $this->receiptImageMessage($receiptImage, $anonymous),
            ConversationResponse::options('Transferência concluída.', [
                ['label' => '🏡 Voltar ao Menu', 'value' => 'voltar_menu'],
            ]),
        ], [
            'type' => 'option',
            'field' => 'transfer_result_action',
        ], null, null, [$this->recipientNotification(
            $session,
            $recipient,
            (float) $context['valor_transf'],
            $anonymous,
            $this->needsWelcomeNotification($recipient),
            $recipientReceiptImage,
            $transferId,
        )]);
    }

    private function confirmationOptions(ConversationSession $session): array
    {
        $options = [
            ['label' => '❌ Cancelar', 'value' => 'cancelar'],
            ['label' => '🤔 Alterar valor', 'value' => 'alterar_valor'],
            ['label' => '🤔 Alterar Para Quem', 'value' => 'alterar_destinatario'],
        ];

        $recipient = Customer::find(($session->context ?? [])['recipient_customer_id'] ?? null);
        if ($session->customer && $recipient
            && (float) (($session->context ?? [])['valor_transf'] ?? 0) >= 0.02) {
            $options[] = ['label' => '🗓 Parcelado', 'value' => 'parcelar'];
        }

        $options[] = ['label' => '👤 Envio Privado', 'value' => 'anonima'];
        $options[] = ['label' => '✅ Confirmar', 'value' => 'confirmar'];

        return $options;
    }

    private function confirmationText(array $context): string
    {
        $recipient = Customer::find($context['recipient_customer_id'] ?? null)
            ?? $this->findRecipient($context['destinatario']);
        $whatsappName = trim((string) $recipient?->whatsapp_profile_name);

        if ($whatsappName === '') {
            $whatsappName = trim((string) $recipient?->whatsapp_username);
        }

        return "✅ *Confirma* a *transferência*?\n\n"
            .'Valor: R$ '.$this->formatMoney((float) $context['valor_transf'])."\n"
            .'Para: '.$this->formatRecipientPhone($context['destinatario'])
            .($whatsappName !== '' ? "\n{$whatsappName}" : '')."\n\n"
            .'*_Esta transferência não poderá ser desfeita._*';
    }

    private function confirmCreditUse(ConversationSession $session, string $message): array
    {
        $choice = $this->normalized($message);

        if ($this->wantsToChangeRecipient($choice)) {
            return $this->changeRecipient($session);
        }

        if ($this->wantsToChangeAmount($choice)) {
            $context = $session->context ?? [];
            unset($context['valor_transf'], $context['anonymous'], $context['credit_warning_accepted_at']);
            $session->forceFill([
                'current_step' => 'waiting_amount',
                'context' => $context,
            ])->save();

            return ConversationResponse::make([
                ConversationResponse::text('↗️ *QUANTO deseja transferir?*'),
            ], [
                'type' => 'money',
                'field' => 'valor_transf',
                'min' => 0.01,
                'max' => $this->maxTransferableAmount($session->customer?->fresh()),
            ]);
        }

        if (! str_contains($choice, 'concordo') && ! in_array($choice, ['ok', 'aceito', 'continuar'], true)) {
            return $this->creditUseWarningResponse();
        }

        $context = $session->context ?? [];
        $context['credit_warning_accepted_at'] = now()->toISOString();
        $session->forceFill([
            'current_step' => 'waiting_confirmation',
            'context' => $context,
        ])->save();

        return $this->confirm($session, 'confirmar');
    }

    private function creditUseWarningResponse(): array
    {
        return ConversationResponse::make([
            ConversationResponse::options(
                "❗ Saldo insuficiente para a transferência ❗\n\n"
                ."🚨 *SE continuar vai usar seu limite de crédito.* 🚨\n\n"
                ."Use o limite com responsabilidade, como empréstimo sem juros, colocando seu saldo a positivo até ao dia 05 do próximo mês, por carga ou recebimento.\n\n"
                ."🚨 *Depois do dia 5 há multa (R$50), mora (2%) e juros de 1%a.m. e de 12%a.a.* 🚨\n\n"
                ."✅ *Ao continuar você aceita este compromisso.*\n\n"
                .'⌚️ A pontualidade nos compromissos assumidos fortalece a confiança, estreita relações, e permite aumentar seu crescimento e seu crédito 💳💰',
                [
                    ['label' => '🤔 Alterar o Valor', 'value' => 'alterar_valor'],
                    ['label' => '🤔 Alterar Para Quem', 'value' => 'alterar_destinatario'],
                    ['label' => '👍 OK, concordo', 'value' => 'concordo_credito'],
                    ['label' => '❌ Cancelar', 'value' => 'cancelar'],
                ],
            ),
        ], [
            'type' => 'option',
            'field' => 'credit_use_confirmation',
        ]);
    }

    private function maxTransferableAmount(?Customer $customer): float
    {
        return $this->rotativeCredits->availableToTransfer($customer);
    }

    private function transferAmountInput(ConversationSession $session): array
    {
        return [
            'type' => 'money',
            'field' => 'valor_transf',
            'min' => 0.01,
            'max' => $this->maxTransferableAmount($session->customer?->fresh()),
        ];
    }

    private function changeRecipient(ConversationSession $session): array
    {
        $context = $session->context ?? [];
        unset(
            $context['recipient_customer_id'],
            $context['destinatario'],
            $context['valor_transf'],
            $context['anonymous'],
            $context['credit_warning_accepted_at'],
        );

        $session->forceFill([
            'current_step' => 'waiting_recipient',
            'context' => $context,
        ])->save();

        return ConversationResponse::make([
            ConversationResponse::text("👤 *QUEM irá receber o valor?*\n\nVocê pode transferir para qualquer número de whatsapp.\n\n_Digite o número ou compartilhe o contato_."),
        ], [
            'type' => 'phone_or_file',
            'field' => 'destinatario',
        ]);
    }

    private function wantsToChangeRecipient(string $choice): bool
    {
        return in_array($choice, ['alterar_destinatario', 'alterar destinatario', 'alterar para quem'], true)
            || (str_contains($choice, 'alterar')
                && (str_contains($choice, 'destinat') || str_contains($choice, 'para quem')));
    }

    private function wantsToChangeAmount(string $choice): bool
    {
        return in_array($choice, ['alterar_valor', 'alterar valor'], true)
            || (str_contains($choice, 'alterar') && str_contains($choice, 'valor'));
    }

    private function handleTransferResultAction(ConversationSession $session, string $message): array
    {
        $choice = $this->normalized($message);

        if (str_contains($choice, 'alterar') || in_array($choice, ['alterar_valor', 'alterar valor'], true)) {
            $context = $session->context ?? [];
            unset($context['valor_transf'], $context['anonymous']);

            $session->forceFill([
                'current_step' => 'waiting_amount',
                'context' => $context,
            ])->save();

            return ConversationResponse::make([
                ConversationResponse::text('↗️ *QUANTO deseja transferir?*'),
            ], [
                'type' => 'money',
                'field' => 'valor_transf',
                'min' => 0.01,
                'max' => $this->maxTransferableAmount($session->customer?->fresh()),
            ]);
        }

        if (str_contains($choice, 'voltar') || str_contains($choice, 'menu')) {
            return ConversationResponse::make([], null, null, 'main_menu');
        }

        return ConversationResponse::make([
            ConversationResponse::options('Operação concluída. Volte ao menu para continuar.', [
                ['label' => '🏡 Voltar ao Menu', 'value' => 'voltar_menu'],
            ]),
        ], [
            'type' => 'option',
            'field' => 'transfer_result_action',
        ]);
    }

    private function insufficientBalanceMessage(float $available): array
    {
        return ConversationResponse::options(
            "Saldo insuficiente para realizar esta transferência.\n\n"
            .'Saldo disponível: R$'.$this->formatMoney($available),
            [
                ['label' => '🤔 Alterar valor', 'value' => 'alterar_valor'],
                ['label' => '🏡 Voltar ao Menu', 'value' => 'voltar_menu'],
            ],
        );
    }

    private function receiptImageMessage(array $image, bool $private): array
    {
        return [
            'type' => 'image',
            'text' => 'Comprovante de transferência '.($private ? 'privada' : 'pública'),
            'url' => $image['url'],
            'path' => $image['path'],
        ];
    }

    private function recipientNotification(
        ConversationSession $session,
        Customer $recipient,
        float $amount,
        bool $anonymous,
        bool $sendWelcome = false,
        ?array $receiptImage = null,
        ?string $transferId = null,
    ): array {
        $templateName = config('services.whatsapp.transfer_notification_template', 'aviso_transferencia_v1');
        $templateLanguage = config('services.whatsapp.transfer_notification_template_language', 'pt_BR');

        return [
            'channel' => 'whatsapp',
            'to' => $recipient->phone,
            'transfer_id' => $transferId,
            'send_start_template' => $sendWelcome,
            'template_name' => $templateName,
            'template_language' => $templateLanguage,
            'template_components' => [[
                'type' => 'header',
                'parameters' => [[
                    'type' => 'image',
                    'image' => [
                        'link' => config('services.whatsapp.welcome_image_url'),
                    ],
                ]],
            ], [
                'type' => 'body',
                'parameters' => [[
                    'type' => 'text',
                    'text' => $this->formatMoney($amount),
                ], [
                    'type' => 'text',
                    'text' => $anonymous
                        ? 'Particular'
                        : $this->formatWhatsappNumber($session->external_user_id),
                ]],
            ]],
            'welcome_customer_id' => $sendWelcome ? $recipient->id : null,
            'messages' => [
                ...($receiptImage ? [$this->receiptImageMessage($receiptImage, $anonymous)] : []),
                ConversationResponse::options($this->receivedTransferText($session, $amount, $anonymous), [
                    ['label' => '🏡 Voltar ao Menu', 'value' => 'menu'],
                    ['label' => '↗️ Transferir', 'value' => 'transferir'],
                ]),
            ],
        ];
    }

    private function persistRecipientNotification(array $notification): void
    {
        WhatsappNotificationDelivery::updateOrCreate(
            [
                'transfer_id' => $notification['transfer_id'],
                'recipient_phone' => $notification['to'],
            ],
            [
                'notification' => $notification,
                'status' => 'pending',
                'next_attempt_at' => now(),
            ],
        );
    }

    private function needsWelcomeNotification(Customer $recipient): bool
    {
        $metadata = $recipient->metadata ?? [];

        return ! empty($metadata['created_by_transfer']) && empty($metadata['welcome_notified_at']);
    }

    private function receivedTransferText(ConversationSession $session, float $amount, bool $anonymous): string
    {
        $sender = $anonymous
            ? "*De:* 👤 Particular\n\n"
            : '*De:* '.$this->formatWhatsappNumber($session->external_user_id)."\n"
                .$this->senderWhatsappName($session)."\n\n";

        return "😀 *Transferência Recebida!*\n\n"
            .$sender
            ."Crédito\n"
            .'Valor: *R$ '.$this->formatMoney($amount)."*\n\n"
            .'👋 Sempre que precisar, é só dar um oi aqui.';
    }

    private function senderWhatsappName(ConversationSession $session): string
    {
        $session->loadMissing('customer');

        return $session->customer?->whatsappDisplayName()
            ?: $session->external_user_id;
    }

    private function recipientDisplayName(Customer $recipient): string
    {
        return $recipient->whatsappDisplayName()
            ?: $this->formatRecipientPhone($recipient->phone);
    }

    private function formatWhatsappNumber(string $phone): string
    {
        return $this->phoneFormatter->international($phone);
    }

    private function maskedWhatsappNumber(string $phone): string
    {
        return $this->phoneFormatter->privateMask($phone);
    }

    private function formatRecipientPhone(string $phone): string
    {
        return $this->phoneFormatter->international($phone);
    }

    private function findRecipient(string $phone): ?Customer
    {
        $candidates = $this->phoneCandidates($phone);
        $customers = Customer::whereIn('phone', $candidates)->get()->keyBy('phone');

        foreach ($candidates as $candidate) {
            if ($customers->has($candidate)) {
                return $customers->get($candidate);
            }
        }

        return null;
    }

    private function phoneCandidates(string $phone): array
    {
        $phone = $this->onlyDigits($phone);
        $canonical = $this->phones->normalizeKnownNumber($phone);
        $withoutCountry = str_starts_with($phone, '55') ? substr($phone, 2) : $phone;
        $withNinthDigit = strlen($withoutCountry) === 10
            ? substr($withoutCountry, 0, 2).'9'.substr($withoutCountry, 2)
            : null;
        $withoutNinthDigit = strlen($withoutCountry) === 11 && substr($withoutCountry, 2, 1) === '9'
            ? substr($withoutCountry, 0, 2).substr($withoutCountry, 3)
            : null;

        return array_values(array_unique(array_filter([
            $canonical,
            $withNinthDigit ? '55'.$withNinthDigit : null,
            strlen($withoutCountry) === 11 ? '55'.$withoutCountry : null,
            strlen($phone) === 13 && str_starts_with($phone, '55') ? $phone : null,
            $withoutNinthDigit ? '55'.$withoutNinthDigit : null,
            strlen($phone) === 12 && str_starts_with($phone, '55') ? $phone : null,
            $phone,
        ])));
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 2, ',', '.');
    }
}
