<?php

namespace App\Services\Conversation\Flows;

use App\Exceptions\PixKeyNotFoundException;
use App\Models\ConversationSession;
use App\Models\Customer;
use App\Services\CashOut\CashOutAvailability;
use App\Services\Credit\AutomaticCreditWallet;
use App\Services\Conversation\Contracts\ConversationFlow;
use App\Services\Conversation\ConversationResponse;
use App\Services\Conversation\Flows\Concerns\ParsesConversationInput;
use App\Services\Ledger\MovementLedger;
use App\Services\Payments\AsaasPaymentProvider;
use App\Services\Payments\Contracts\PaymentProvider;
use App\Services\Phone\PhoneNumberFormatter;
use App\Support\BrazilianDocument;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

class RedeemPixFlow implements ConversationFlow
{
    use ParsesConversationInput;

    public function __construct(
        private readonly MovementLedger $ledger,
        private readonly PaymentProvider $paymentProvider,
        private readonly AsaasPaymentProvider $asaas,
        private readonly CashOutAvailability $cashOut,
        private readonly AutomaticCreditWallet $automaticCredit,
        private readonly PhoneNumberFormatter $phoneFormatter,
    ) {}

    public function enter(ConversationSession $session): array
    {
        $session->loadMissing('customer');
        $redeemable = $this->availableForRedeem($session);

        if ($redeemable <= 0) {
            return ConversationResponse::make([
                ConversationResponse::text('Sem saldo para resgatar.'),
            ], null, null, 'main_menu');
        }

        $session->forceFill([
            'current_step' => 'waiting_intro_action',
            'context' => ['customer_redeem' => $redeemable],
        ])->save();

        return ConversationResponse::make([
            ConversationResponse::options($this->introText($session), [
                ['label' => 'Continuar', 'value' => 'continuar'],
                ['label' => '🏡 Voltar ao Menu', 'value' => 'voltar_menu'],
            ]),
        ], [
            'type' => 'option',
            'field' => 'redeem_intro_action',
        ]);
    }

    public function handle(ConversationSession $session, string $message): array
    {
        if ($this->isCancel($message)) {
            return ConversationResponse::make([ConversationResponse::text('Operação cancelada.')], null, null, 'main_menu');
        }

        return match ($session->current_step) {
            'waiting_intro_action' => $this->handleIntroAction($session, $message),
            'waiting_amount' => $this->captureAmount($session, $message),
            'waiting_confirmation' => $this->confirm($session, $message),
            'waiting_cpf' => $this->captureCpfAndConfirm($session, $message),
            'waiting_cpf_action' => $this->handleCpfAction($session, $message),
            'waiting_success_action' => $this->handleSuccessAction($message),
            default => $this->enter($session),
        };
    }

    private function handleIntroAction(ConversationSession $session, string $message): array
    {
        $choice = $this->normalized($message);

        if (str_contains($choice, 'voltar') || str_contains($choice, 'menu')) {
            return ConversationResponse::make([], null, null, 'main_menu');
        }

        if (! str_contains($choice, 'continuar')) {
            return ConversationResponse::make([
                ConversationResponse::options('Escolha uma opção para continuar.', [
                    ['label' => 'Continuar', 'value' => 'continuar'],
                    ['label' => '🏡 Voltar ao Menu', 'value' => 'voltar_menu'],
                ]),
            ], [
                'type' => 'option',
                'field' => 'redeem_intro_action',
            ]);
        }

        $context = $session->context ?? [];

        $session->forceFill([
            'current_step' => 'waiting_amount',
            'context' => $context,
        ])->save();

        $maximum = $this->availableForRedeem($session);

        return ConversationResponse::make([
            ConversationResponse::text($this->amountPrompt($session, $maximum)),
        ], [
            'type' => 'money',
            'field' => 'valor_transf',
            'min' => $this->cashOut->minimumAmount(),
            'max' => $maximum,
        ]);
    }

    private function captureAmount(ConversationSession $session, string $message): array
    {
        $amount = $this->moneyFromMessage($message);
        $context = $session->context ?? [];
        $max = $this->availableForRedeem($session);
        $minimum = $this->cashOut->minimumAmount();

        if ($amount === null || abs($amount - round($amount, 2)) > 0.000001
            || $amount < $minimum || $amount > $max) {
            return ConversationResponse::make([
                ConversationResponse::text('Ops… Informe um valor entre R$'.$this->formatMoney($minimum).' e R$'.$this->formatMoney($max).'.'),
            ], [
                'type' => 'money',
                'field' => 'valor_transf',
                'min' => $minimum,
                'max' => $max,
            ]);
        }

        $context['valor_transf'] = $amount;
        $context['customer_redeem'] = $max;
        $session->forceFill([
            'current_step' => 'waiting_confirmation',
            'context' => $context,
        ])->save();

        return ConversationResponse::make([
            ConversationResponse::options($this->confirmationText($session, $amount), [
                ['label' => '💸 CONFIRMAR', 'value' => 'confirmar'],
                ['label' => '🟡 ALTERAR VALOR', 'value' => 'alterar_valor'],
                ['label' => '❌ CANCELAR', 'value' => 'cancelar'],
            ]),
        ], [
            'type' => 'option',
            'field' => 'redeem_confirmation',
        ]);
    }

    private function confirm(ConversationSession $session, string $message): array
    {
        $choice = $this->normalized($message);

        if (str_contains($choice, 'voltar') || str_contains($choice, 'menu')) {
            return ConversationResponse::make([], null, null, 'main_menu');
        }

        if (str_contains($choice, 'alterar') || in_array($choice, ['alterar_valor', 'alterar valor'], true)) {
            $context = $session->context ?? [];
            unset($context['valor_transf']);

            $session->forceFill([
                'current_step' => 'waiting_amount',
                'context' => $context,
            ])->save();

            $maximum = $this->availableForRedeem($session);

            return ConversationResponse::make([
                ConversationResponse::text($this->amountPrompt($session, $maximum)),
            ], [
                'type' => 'money',
                'field' => 'valor_transf',
                'min' => $this->cashOut->minimumAmount(),
                'max' => $maximum,
            ]);
        }

        if (! $this->isConfirm($message)) {
            return ConversationResponse::make([ConversationResponse::text('Escolha Confirmar, Alterar Valor ou Cancelar.')]);
        }

        $context = $session->context ?? [];
        $amount = (float) $context['valor_transf'];
        $pixKey = $this->formatPhone($session->external_user_id);
        $usesAsaas = config('payments.cash_out.pix_provider') === 'asaas';
        // ConversationEngine keeps this request in a transaction; the lock covers the final check and debit.
        $customer = Customer::query()->whereKey($session->customer_id)->lockForUpdate()->first();
        $session->setRelation('customer', $customer);

        if ($usesAsaas && (! $customer?->cpf || ! BrazilianDocument::isValid($customer->cpf))) {
            return $this->askForCpf($session, (bool) $customer?->cpf);
        }

        if (! $customer || abs($amount - round($amount, 2)) > 0.000001
            || $amount < $this->cashOut->minimumAmount()
            || $amount > $this->cashOut->availableFor($customer)) {
            return ConversationResponse::make([
                ConversationResponse::text('O valor disponível mudou. Informe um novo valor para resgate.'),
            ], null, null, 'redeem_pix');
        }

        $fee = $this->cashOut->feeFor($amount);

        try {
            $provider = config('payments.cash_out.pix_provider') === 'asaas'
                ? $this->asaas
                : $this->paymentProvider;
            $payment = $provider->sendPix($amount, $pixKey, [
                'conversation_session_id' => $session->id,
                'operation' => 'redeem_pix',
            ]);
        } catch (Throwable $exception) {
            Log::error('Payment provider failed to create a Pix cash-out.', [
                'conversation_session_id' => $session->id,
                'provider' => config('payments.cash_out.pix_provider'),
                'exception' => class_basename($exception),
                'http_status' => $exception instanceof \Illuminate\Http\Client\RequestException ? $exception->response->status() : null,
            ]);

            if ($exception instanceof PixKeyNotFoundException) {
                return $this->pixKeyNotFoundResponse($session);
            }

            if ($usesAsaas && $this->isInvalidDocumentResponse($exception)) {
                return ($context['correcting_cpf'] ?? false)
                    ? $this->cpfNotAccepted($session)
                    : $this->askForCpf($session, true);
            }

            if ($usesAsaas && $this->isInsufficientPermissionResponse($exception)) {
                return $this->cashOutUnavailableResponse($session);
            }

            return $this->failedCashOutResponse($session);
        }

        $movementEvent = $payment->provider === 'asaas' ? 'redeem_pix.submitted' : 'redeem_pix.simulated';
        $movement = $this->ledger->debit($customer, $amount, $movementEvent, [
            'conversation_session_id' => $session->id,
            'external_provider' => $payment->provider,
            'external_operation_id' => $payment->externalId,
            'external_receipt_url' => $payment->receiptUrl,
            'metadata' => [
                'provider_hint' => 'pix',
                'pix_key' => $pixKey,
                'cash_out_fee' => $fee,
                ...$payment->metadata,
            ],
        ]);

        if ($fee > 0) {
            $this->ledger->debit($customer->refresh(), $fee, 'cash_out.fee', [
                'conversation_session_id' => $session->id,
                'metadata' => [
                    'redeem_movement_id' => $movement->id,
                    'fixed_fee' => config('mpmm.cash_out.fixed_fee', 0),
                    'percentage_rate' => config('mpmm.cash_out.percentage_rate', 0),
                ],
            ]);
        }

        $redeemable = max(0, (float) $customer->redeemable_balance - $amount);
        $customer->forceFill(['redeemable_balance' => $redeemable])->save();

        if ($payment->provider === 'asaas') {
            $this->automaticCredit->reduceForWithdrawal($customer, $amount, $movement);
        }

        $session->forceFill([
            'current_step' => 'waiting_success_action',
            'context' => [
                ...$context,
                'movement_id' => $movement->id,
            ],
        ])->save();

        return ConversationResponse::make([
            ConversationResponse::options($this->successText($session), [
                ['label' => '🏡 Voltar ao Menu', 'value' => 'voltar_menu'],
            ]),
        ], [
            'type' => 'option',
            'field' => 'redeem_success_action',
        ], null, null, [$this->adminCashOutNotification($session, $amount, $payment->provider)]);
    }

    private function handleSuccessAction(string $message): array
    {
        if (str_contains($this->normalized($message), 'voltar') || str_contains($this->normalized($message), 'menu')) {
            return ConversationResponse::make([], null, null, 'main_menu');
        }

        return ConversationResponse::make([
            ConversationResponse::options('Resgate agendado com sucesso!', [
                ['label' => '🏡 Voltar ao Menu', 'value' => 'voltar_menu'],
            ]),
        ], [
            'type' => 'option',
            'field' => 'redeem_success_action',
        ]);
    }

    private function captureCpfAndConfirm(ConversationSession $session, string $message): array
    {
        $document = $this->onlyDigits($message);

        if (! BrazilianDocument::isValid($document)) {
            return $this->cpfNotAccepted($session);
        }

        $session->loadMissing('customer');

        try {
            $session->customer?->forceFill(['cpf' => $document])->save();
        } catch (QueryException $exception) {
            Log::warning('Customer document could not be updated for Pix cash-out.', [
                'conversation_session_id' => $session->id,
                'error_code' => $exception->getCode(),
            ]);

            return $this->cpfNotAccepted($session);
        }

        return $this->confirm($session, 'confirmar');
    }

    private function askForCpf(ConversationSession $session, bool $correction = false): array
    {
        $context = $session->context ?? [];
        $context['correcting_cpf'] = $correction;

        $session->forceFill([
            'current_step' => 'waiting_cpf',
            'context' => $context,
        ])->save();

        return ConversationResponse::make([
            ConversationResponse::text($correction
                ? 'Não conseguimos validar o CPF ou CNPJ cadastrado. Informe novamente para fazermos mais uma tentativa.'
                : 'Qual o seu CPF ou CNPJ? (Precisamos dele para realizar o resgate pelo Asaas)'),
        ], [
            'type' => 'cpf',
            'field' => 'customer_cpf',
        ]);
    }

    private function cpfNotAccepted(ConversationSession $session): array
    {
        $session->forceFill(['current_step' => 'waiting_cpf_action'])->save();

        return ConversationResponse::make([
            ConversationResponse::options(
                'Não foi possível aceitar o CPF ou CNPJ informado. Confira os dados e escolha como deseja continuar.',
                [
                    ['label' => 'Informar novamente', 'value' => 'informar_novamente'],
                    ['label' => '🏡 Voltar ao Menu', 'value' => 'voltar_menu'],
                ],
            ),
        ], [
            'type' => 'option',
            'field' => 'cpf_action',
        ]);
    }

    private function handleCpfAction(ConversationSession $session, string $message): array
    {
        $choice = $this->normalized($message);

        if (in_array($choice, ['informar_novamente', 'informar novamente'], true)) {
            return $this->askForCpf($session, true);
        }

        if (in_array($choice, ['voltar_menu', 'voltar ao menu inicial', 'menu inicial'], true)) {
            return ConversationResponse::make([], null, null, 'main_menu');
        }

        return $this->cpfNotAccepted($session);
    }

    private function isInvalidDocumentResponse(Throwable $exception): bool
    {
        if (! $exception instanceof RequestException || $exception->response->status() !== 400) {
            return false;
        }

        foreach ($exception->response->json('errors', []) as $error) {
            $description = mb_strtolower((string) ($error['description'] ?? ''));

            if (($error['code'] ?? null) === 'invalid_object'
                && (str_contains($description, 'cpf') || str_contains($description, 'cnpj'))) {
                return true;
            }
        }

        return false;
    }

    private function isInsufficientPermissionResponse(Throwable $exception): bool
    {
        if (! $exception instanceof RequestException || $exception->response->status() !== 403) {
            return false;
        }

        return collect($exception->response->json('errors', []))
            ->contains(fn (array $error) => ($error['code'] ?? null) === 'insufficient_permission');
    }

    private function cashOutUnavailableResponse(ConversationSession $session): array
    {
        $session->forceFill(['current_step' => 'waiting_confirmation'])->save();

        return ConversationResponse::make([
            ConversationResponse::options(
                "O Resgate Pix está temporariamente indisponível.\n\nSeu saldo não foi alterado.",
                [['label' => '🏡 Voltar ao Menu', 'value' => 'voltar_menu']],
            ),
        ], [
            'type' => 'option',
            'field' => 'redeem_confirmation',
        ]);
    }

    private function failedCashOutResponse(ConversationSession $session): array
    {
        $session->forceFill(['current_step' => 'waiting_confirmation'])->save();

        return ConversationResponse::make([
            ConversationResponse::options(
                "Não foi possível concluir o Resgate Pix agora.\n\n"
                ."A chave PIX vinculada ao seu WhatsApp pode não estar cadastrada ou ativa no banco.\n\n"
                .'Seu saldo não foi alterado.',
                [
                    ['label' => '💸TENTAR NOVAMENTE', 'value' => 'confirmar'],
                    ['label' => '🟡ALTERAR VALOR', 'value' => 'alterar_valor'],
                    ['label' => '🏡 Voltar ao Menu', 'value' => 'voltar_menu'],
                ],
            ),
        ], [
            'type' => 'option',
            'field' => 'redeem_confirmation',
        ]);
    }

    private function pixKeyNotFoundResponse(ConversationSession $session): array
    {
        $session->forceFill(['current_step' => 'waiting_confirmation'])->save();

        return ConversationResponse::make([
            ConversationResponse::options(
                "Não encontramos uma chave PIX cadastrada com o seu número de WhatsApp.\n\n"
                ."O resgate não foi processado e seu saldo não foi alterado.\n\n"
                .'Para fazer o resgate, primeiro cadastre este número como chave PIX no seu banco.',
                [
                    ['label' => 'Tentar novamente', 'value' => 'confirmar'],
                    ['label' => '🏡 Voltar ao Menu', 'value' => 'voltar_menu'],
                ],
            ),
        ], [
            'type' => 'option',
            'field' => 'redeem_confirmation',
        ]);
    }

    private function amountPrompt(ConversationSession $session, float $maximum): string
    {
        return '💸 *QUANTOS R$* deseja resgatar para a 🔑 Chave Pix '.$this->formatPhone($session->external_user_id)."?\n\n"
            .'Descarga máxima disponível de *R$ '.$this->formatMoney($maximum)."*\n\n"
            .'_*Saques baixam o crédito concedido*._';
    }

    private function introText(ConversationSession $session): string
    {
        $session->loadMissing('customer');
        $redeemable = (float) ($session->context['customer_redeem'] ?? $this->availableForRedeem($session));
        $pixKey = $this->formatPhone($session->external_user_id);

        return "🪙 *“a César o que é de César”*\n"
            ."✝️ *Bíblia: evangelhos sinóticos em Mateus 22:21, Marcos 12:13-17 e Lucas 20:20-26*\n\n"
            ."🔑 Chave PIX:\n{$pixKey}\n\n"
            ."*Resgate EXCLUSIVO para o banco onde o seu número de WhatsApp está cadastrado como Chave PIX 🔑*\n\n"
            ."> Antes de continuar, verifique se esta 🔑 chave PIX está cadastrada *em seu nome* e se você tem acesso ao banco de destino.\n\n"
            ."🤔 Lembre em qual banco a chave PIX está cadastrada, pois ela pode ser pouco usada.\n\n"
            .'Valor mínimo de resgate: R$ '.$this->formatMoney($this->cashOut->minimumAmount())."\n\n"
            ."Disponível para Resgate Pix até:\n```R$ ".$this->formatMoney($redeemable).'```';
    }

    private function confirmationText(ConversationSession $session, float $amount): string
    {
        return "💸 *CONFIRMA o Resgate Pix?*\n\n"
            ."Bancos e autoridades (como BACEN, COAF e Receita), serão informados.\n\n"
            .'Valor: R$ '.$this->formatMoney($amount)."\n"
            .'🔑 Chave PIX: '.$this->formatPhone($session->external_user_id)."\n\n"
            .'*Lembre de verificar o banco onde a chave PIX está cadastrada*, que pode não ser o banco que você usa habitualmente...'."\n\n"
            .'*Esta operação não poderá ser desfeita.*';
    }

    private function successText(ConversationSession $session): string
    {
        return "Resgate agendado com sucesso!\n\n"
            .'Por favor, confira o banco em que a chave PIX '
            .$this->formatPhone($session->external_user_id)
            .' está cadastrada. O valor será enviado para esse banco.';
    }

    private function adminCashOutNotification(ConversationSession $session, float $amount, string $provider): array
    {
        $title = $provider === 'asaas'
            ? '🏦 *Movimentação Asaas — Resgate/Saque solicitado*'
            : '🏦 *Resgate/Saque solicitado*';
        $session->loadMissing('customer');
        $whatsappName = $session->customer?->whatsappAccountName() ?? 'Não informado';

        return [
            'channel' => 'whatsapp',
            'to' => (string) config('services.whatsapp.asaas_activity_admin_phone', ''),
            'messages' => [
                ConversationResponse::options(
                    $title."\n\n"
                    .'*Valor:* R$ '.$this->formatMoney($amount)."\n"
                    .'*WhatsApp do membro:* +'.preg_replace('/\D+/', '', $session->external_user_id)."\n"
                    .'*Nome de WhatsApp do membro:* '.$whatsappName,
                    [
                        ['label' => 'Voltar ao Menu', 'value' => 'admin_cashout_menu_'.$session->customer_id],
                        ['label' => 'Ajustar Crédito', 'value' => 'admin_cashout_adjust_'.$session->customer_id],
                        ['label' => 'Extrato de Membro', 'value' => 'admin_cashout_statement_'.$session->customer_id],
                        ['label' => 'Nova Pesquisa', 'value' => 'admin_cashout_search_'.$session->customer_id],
                    ],
                    'Escolher opção',
                ),
            ],
        ];
    }

    private function formatPhoneForAdmin(string $phone): string
    {
        return $this->phoneFormatter->admin($phone);
    }

    private function formatPhone(string $phone): string
    {
        return $this->phoneFormatter->brazilianPixKey($phone);
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 2, ',', '.');
    }

    private function availableForRedeem(ConversationSession $session): float
    {
        $session->loadMissing('customer');

        if (! $session->customer) {
            return 0;
        }

        return $this->cashOut->availableFor($session->customer->refresh());
    }
}
