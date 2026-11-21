<?php

namespace App\Services\Conversation\Flows;

use App\Models\ConversationSession;
use App\Models\Customer;
use App\Models\Movement;
use App\Services\Bonus\BonusAwardService;
use App\Services\Bonus\BonusEvents;
use App\Services\Conversation\Contracts\ConversationFlow;
use App\Services\Conversation\ConversationResponse;
use App\Services\Conversation\Flows\Concerns\ParsesConversationInput;
use App\Services\Ledger\MovementLedger;
use App\Services\Invites\InviteDepositBonus;
use App\Services\Payments\AsaasPaymentProvider;
use App\Services\Payments\Contracts\PaymentProvider;
use App\Services\Phone\PhoneNumberNormalizer;
use App\Support\BrazilianDocument;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

class DepositFlow implements ConversationFlow
{
    use ParsesConversationInput;

    public function __construct(
        private readonly MovementLedger $ledger,
        private readonly PaymentProvider $paymentProvider,
        private readonly AsaasPaymentProvider $asaas,
        private readonly BonusAwardService $bonuses,
        private readonly InviteDepositBonus $inviteDepositBonus,
    ) {}

    public function enter(ConversationSession $session): array
    {
        $session->loadMissing('customer');

        $session->forceFill([
            'current_step' => 'waiting_amount',
            'context' => [],
        ])->save();

        return ConversationResponse::make([
            ConversationResponse::options('💰 *QUANTOS R$ deseja carregar?*', $this->amountOptions($session->customer)),
        ], [
            'type' => 'money',
            'field' => 'valor_recarga',
            'min' => 10,
            'max' => 150000,
        ]);
    }

    public function handle(ConversationSession $session, string $message): array
    {
        if ($this->isCancel($message)) {
            return ConversationResponse::make([
                ConversationResponse::text('Depósito cancelado.'),
            ], null, null, 'main_menu');
        }

        return match ($session->current_step) {
            'waiting_amount' => $this->captureAmount($session, $message),
            'waiting_method' => $this->captureMethod($session, $message),
            'waiting_cpf' => $this->captureCpfAndProcess($session, $message),
            'waiting_cpf_action' => $this->handleCpfAction($session, $message),
            'waiting_payment_confirmation' => $this->handlePaymentConfirmation($session, $message),
            'waiting_success_action' => $this->handleSuccessAction($message),
            default => $this->enter($session),
        };
    }

    private function captureAmount(ConversationSession $session, string $message): array
    {
        $amount = $this->moneyFromMessage($message);

        if ($amount === null || $amount < 10 || $amount > 150000) {
            return ConversationResponse::make([
                ConversationResponse::text('Informe um valor entre R$ 10,00 e R$ 150.000,00.'),
            ], [
                'type' => 'money',
                'field' => 'valor_recarga',
                'min' => 10,
                'max' => 150000,
            ]);
        }

        $context = $session->context ?? [];
        $context['valor_recarga'] = $amount;

        if ($session->customer) {
            $metadata = $session->customer->metadata ?? [];
            $metadata['last_deposit_order_amount'] = $amount;
            $metadata['last_deposit_order_at'] = now()->toISOString();
            $session->customer->forceFill(['metadata' => $metadata])->save();
        }

        $session->forceFill([
            'current_step' => 'waiting_method',
            'context' => $context,
        ])->save();

        $formattedAmount = $this->formatMoney($amount);

        return ConversationResponse::make([
            ConversationResponse::options("💰 Carga de R$ {$formattedAmount}\n\nPor qual meio?", $this->methodOptions($session), 'Opções'),
        ], [
            'type' => 'option',
            'field' => 'meio_pagamento',
        ]);
    }

    private function canUseCard(ConversationSession $session): bool
    {
        $phone = app(PhoneNumberNormalizer::class)->normalizeKnownNumber($session->external_user_id);
        $allowed = array_filter(array_map('trim', explode(',', (string) config('payments.cash_in.card_test_phones', ''))));

        return $phone !== null && in_array($phone, $allowed, true);
    }

    private function methodOptions(ConversationSession $session): array
    {
        return [
            ['label' => '🏡 Voltar ao Menu', 'value' => 'menu'],
            ['label' => '🤔 Alterar valor', 'value' => 'alterar_valor'],
            ...($this->canUseCard($session) ? [
                ['label' => '💳 Cartão de Crédito', 'value' => 'credit_card'],
                ['label' => '💳 Cartão de débito', 'value' => 'debit_card'],
            ] : []),
            ['label' => '🧾 Boleto', 'value' => 'boleto'],
            ['label' => '🔑 Pix', 'value' => 'pix'],
        ];
    }

    private function cardUnavailable(ConversationSession $session): array
    {
        $context = $session->context ?? [];
        unset($context['meio_pagamento']);
        $session->forceFill(['current_step' => 'waiting_method', 'context' => $context])->save();

        return ConversationResponse::make([
            ConversationResponse::options('Carga por cartão temporariamente indisponível. Escolha PIX ou boleto.', $this->methodOptions($session), 'Opções'),
        ], ['type' => 'option', 'field' => 'meio_pagamento']);
    }

    private function amountOptions(?Customer $customer): array
    {
        $lastAmount = (float) (($customer?->metadata ?? [])['last_deposit_order_amount'] ?? 0);
        $options = [];

        if ($lastAmount >= 10 && $lastAmount <= 150000) {
            $options[] = [
                'label' => 'Última: R$ '.$this->formatMoney($lastAmount),
                'value' => (string) $lastAmount,
            ];
        }

        foreach ([200, 20] as $amount) {
            if (abs($lastAmount - $amount) < 0.001) {
                continue;
            }

            $options[] = ['label' => 'R$ '.$this->formatMoney($amount), 'value' => (string) $amount];
        }

        $options[] = ['label' => 'Outro valor', 'value' => 'outro'];

        return $options;
    }

    private function captureMethod(ConversationSession $session, string $message): array
    {
        $method = $this->paymentMethodFromMessage($message);

        if (str_contains($method, 'alterar') || in_array($method, ['alterar_valor', 'alterar valor'], true)) {
            return $this->enter($session);
        }

        if (in_array($method, ['credit_card', 'debit_card'], true) && ! $this->canUseCard($session)) {
            return $this->cardUnavailable($session);
        }

        if (! in_array($method, ['pix', 'boleto', 'credit_card', 'debit_card'], true)) {
            return ConversationResponse::make([
                ConversationResponse::options('Escolha um meio de carga ou altere o valor.', $this->methodOptions($session), 'Opções'),
            ]);
        }

        $context = $session->context ?? [];
        $context['meio_pagamento'] = $method;
        $session->forceFill(['context' => $context])->save();
        $session->loadMissing('customer');

        if (! $session->customer?->cpf || ($this->usesAsaas($method) && ! BrazilianDocument::isValid($session->customer->cpf))) {
            return $this->askForCpf($session, (bool) $session->customer?->cpf);
        }

        return $this->process($session);
    }

    private function captureCpfAndProcess(ConversationSession $session, string $message): array
    {
        if (in_array($session->context['meio_pagamento'] ?? null, ['credit_card', 'debit_card'], true)
            && ! $this->canUseCard($session)) {
            return $this->cardUnavailable($session);
        }
        $cpf = $this->onlyDigits($message);

        $method = (string) (($session->context ?? [])['meio_pagamento'] ?? '');

        if (! in_array(strlen($cpf), [11, 14], true)
            || ($this->usesAsaas($method) && ! BrazilianDocument::isValid($cpf))) {
            return $this->cpfNotAccepted($session);
        }

        $session->loadMissing('customer');

        try {
            $session->customer?->forceFill(['cpf' => $cpf])->save();
        } catch (QueryException $exception) {
            Log::warning('Customer document could not be updated for deposit.', [
                'conversation_session_id' => $session->id,
                'error_code' => $exception->getCode(),
            ]);

            return $this->cpfNotAccepted($session);
        }

        return $this->process($session);
    }

    private function usesAsaas(string $method): bool
    {
        return config("payments.cash_in.{$method}_provider") === 'asaas';
    }

    private function paymentMethodFromMessage(string $message): string
    {
        $method = $this->normalized($message);

        if (in_array($method, ['credit_card', 'cartao_credito', 'cartao de credito'], true)
            || (str_contains($method, 'cartao') && str_contains($method, 'credito'))) {
            return 'credit_card';
        }

        if (in_array($method, ['debit_card', 'cartao_debito', 'cartao de debito'], true)
            || (str_contains($method, 'cartao') && str_contains($method, 'debito'))) {
            return 'debit_card';
        }

        return $method;
    }

    private function askForCpf(ConversationSession $session, bool $correction = false): array
    {
        $context = $session->context ?? [];
        $context['correcting_cpf'] = $correction;

        $session->forceFill([
            'current_step' => 'waiting_cpf',
            'context' => $context,
        ])->save();

        $message = $correction
            ? 'Não conseguimos validar o CPF ou CNPJ cadastrado. Informe novamente para fazermos mais uma tentativa.'
            : 'Qual o seu CPF ou CNPJ? (Para guardar e identificar no boleto)';

        return ConversationResponse::make([
            ConversationResponse::text($message),
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

    private function process(ConversationSession $session): array
    {
        if (in_array($session->context['meio_pagamento'] ?? null, ['credit_card', 'debit_card'], true)
            && ! $this->canUseCard($session)) {
            return $this->cardUnavailable($session);
        }
        $session->loadMissing('customer');
        $context = $session->context ?? [];
        $amount = (float) $context['valor_recarga'];
        $method = $context['meio_pagamento'];
        $providerName = config("payments.cash_in.{$method}_provider");
        $provider = $providerName === 'asaas'
            ? $this->asaas
            : $this->paymentProvider;
        $metadata = [
            'customer_id' => $session->customer?->id,
            'conversation_session_id' => $session->id,
            'customer_cpf' => $session->customer?->cpf,
            'customer_phone' => $session->customer?->phone,
            'payment_method' => $method,
            'card_type' => $method === 'debit_card' ? 'debit' : 'credit',
        ];

        try {
            $payment = match ($method) {
                'boleto' => $provider->createBoletoDeposit($amount, $metadata),
                'credit_card', 'debit_card' => $provider->createCardDeposit($amount, $metadata),
                default => $provider->createPixDeposit($amount, $metadata),
            };
        } catch (Throwable $exception) {
            Log::error('Payment provider failed to create a deposit charge.', [
                'conversation_session_id' => $session->id,
                'payment_method' => $method,
                'provider' => $providerName,
                'exception' => $exception,
            ]);

            if ($providerName === 'asaas' && $this->isInvalidDocumentResponse($exception)) {
                return ($context['correcting_cpf'] ?? false)
                    ? $this->cpfNotAccepted($session)
                    : $this->askForCpf($session, true);
            }

            return ConversationResponse::make([
                ConversationResponse::options(
                    "Não foi possível gerar a cobrança por {$method} agora. Tente novamente em instantes ou escolha outra opção.",
                    $this->methodOptions($session),
                    'Opções',
                ),
            ], [
                'type' => 'option',
                'field' => 'meio_pagamento',
            ]);
        }

        $isCard = in_array($method, ['credit_card', 'debit_card'], true);
        $paymentCode = $isCard ? null : ($payment->metadata['copy_paste']
            ?? $payment->metadata['boleto_line']
            ?? $this->paymentCode($method, $session->id, $amount));
        $received = ($payment->metadata['status'] ?? null) === 'received';
        $movementAttributes = [
            'conversation_session_id' => $session->id,
            'external_provider' => $payment->provider,
            'external_operation_id' => $payment->externalId,
            'external_receipt_url' => $payment->receiptUrl,
            'metadata' => [
                'payment_method' => $method,
                'payment_method_label' => $this->paymentMethodLabel($method),
                'description' => '💰 Cash-In · Carga via '.$this->paymentMethodLabel($method)
                    .($payment->provider === 'asaas' ? ' · Asaas' : ''),
                'copy_paste' => $paymentCode,
                'customer_cpf' => $session->customer?->cpf,
                ...$payment->metadata,
            ],
        ];

        $movement = $received
            ? $this->ledger->credit($session->customer, $amount, 'deposit.simulated', $movementAttributes)
            : Movement::create([
                'customer_id' => $session->customer->id,
                'debit' => 0,
                'credit' => 0,
                'balance' => $session->customer->balance,
                'currency' => 'BRL',
                'event' => 'deposit.pending',
                ...$movementAttributes,
            ]);

        $notifications = $received
            ? $this->awardDepositBonuses($session, $movement, $amount)
            : [];

        $context['movement_id'] = $movement->id;
        $context['payment_code'] = $paymentCode;
        $context['payment_document_url'] = $payment->receiptUrl;
        $context['payment_url'] = $payment->metadata['payment_url'] ?? $payment->receiptUrl;

        $session->forceFill([
            'current_step' => 'waiting_payment_confirmation',
            'context' => $context,
        ])->save();

        $response = match ($method) {
            'boleto' => $this->boletoChargeResponse($context),
            'credit_card', 'debit_card' => $this->cardChargeResponse($context, $method),
            default => $this->pixChargeResponse($context),
        };

        if ($notifications !== []) {
            $response['notifications'] = [
                ...($response['notifications'] ?? []),
                ...$notifications,
            ];
        }

        return $response;
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

    private function pixChargeResponse(array $context): array
    {
        return ConversationResponse::make([
            ConversationResponse::text('⌚ Um momento, por favor... estamos processando seu pedido.'),
            ...$this->visiblePaymentCodeMessages($context['payment_code'], 'pix'),
            $this->copyableCodeMessage($context['payment_code'], 'pix'),
            $this->forwardCodeMessage($context['payment_code']),
            ConversationResponse::options("👆 Copie o código acima e transfira pelo app ou site do seu banco, usando a opção Pix copia-cola.\n\n"
                ."Ou *envie* o código a quem quer *cobrar*.\n\n"
                .'*Assim que* a transferência acontecer nós notificamos você aqui.', [
                    ['label' => '♦️Já paguei!', 'value' => 'ja_paguei'],
                    ['label' => 'Continuar', 'value' => 'continuar'],
                ]),
        ], [
            'type' => 'option',
            'field' => 'payment_confirmation',
            'payment_method' => 'pix',
        ]);
    }

    private function boletoChargeResponse(array $context): array
    {
        return ConversationResponse::make([
            ConversationResponse::text('⌚ Um momento, por favor... estamos processando seu pedido.'),
            $this->boletoDocumentMessage($context['payment_document_url'] ?? null),
            ...$this->visiblePaymentCodeMessages($context['payment_code'], 'boleto'),
            $this->copyableCodeMessage($context['payment_code'], 'boleto'),
            $this->forwardCodeMessage($context['payment_code']),
            ConversationResponse::options("👆 Copie a linha digitável acima para transferir pelo app ou site do banco.\n\n"
                ."Ou *envie* o arquivo pdf ou a linha digitável do boleto a quem quer *cobrar*.\n\n"
                .'*Assim que* a transferência acontecer nós notificamos você aqui.', [
                    ['label' => '🏡 Voltar ao Menu', 'value' => 'voltar_menu'],
                ]),
        ], [
            'type' => 'option',
            'field' => 'payment_confirmation',
            'payment_method' => 'boleto',
        ]);
    }

    private function boletoDocumentMessage(?string $url = null): array
    {
        return [
            'type' => 'document',
            'text' => 'Boleto.pdf',
            'filename' => 'Boleto.pdf',
            'url' => $url ?: config('services.whatsapp.boleto_pdf_url'),
        ];
    }

    private function cardChargeResponse(array $context, string $method): array
    {
        return ConversationResponse::make([
            ConversationResponse::text('⌛ Um momento, por favor... estamos processando seu pedido.'),
            ConversationResponse::text("Link seguro para pagamento com cartão:\n\nToque e segure no link abaixo para copiar, se preferir."),
            ConversationResponse::text((string) $context['payment_url']),
            [
                'type' => 'cta_url',
                'text' => "💳 Carregue com cartão, de débito ou crédito, na página segura da Asaas Gestão Financeira - Instituição de pagamento S.A., no seu navegador.\n\n"
                    ."O MpMM® nunca solicita, nunca armazena e nunca movimenta quaisquer dados bancários, como do seu cartão.\n\n"
                    .'O saldo será atualizado depois da confirmação do pagamento.',
                'label' => 'Pagar no Asaas',
                'url' => $context['payment_url'],
            ],
            ConversationResponse::options(
                'Depois de concluir ou se quiser mudar a carga, escolha uma opção:',
                [
                    ['label' => '♦️Já paguei!', 'value' => 'ja_paguei'],
                    ['label' => 'Alterar valor', 'value' => 'alterar_valor'],
                    ['label' => '🏡 Voltar ao Menu', 'value' => 'voltar_menu'],
                ],
            ),
        ], [
            'type' => 'option',
            'field' => 'payment_confirmation',
            'payment_method' => $method,
        ]);
    }

    private function copyableCodeMessage(string $code, string $method): array
    {
        return [
            'type' => 'copyable_code',
            'text' => $code,
            'template_name' => config("services.whatsapp.{$method}_copy_code_template"),
            'language' => config('services.whatsapp.payment_code_template_language', 'pt_BR'),
        ];
    }

    private function visiblePaymentCodeMessages(string $code, string $method): array
    {
        $description = $method === 'boleto'
            ? 'a linha digitável do boleto'
            : 'o código Pix copia e cola';

        return [
            ConversationResponse::text("Aqui está {$description}. Toque e segure na próxima mensagem e escolha *Copiar*."),
            // Isolado para que copiar a mensagem não acrescente instruções ao código bancário.
            ConversationResponse::text($code),
        ];
    }

    private function forwardCodeMessage(string $code): array
    {
        return [
            'type' => 'cta_url',
            'text' => 'Opcional: use este botão somente se quiser enviar o código de pagamento para outro membro.',
            'label' => 'Compartilhar código',
            // O texto compartilhado deve ser idêntico ao código bancário.
            'url' => 'https://wa.me/?text='.rawurlencode($code),
        ];
    }

    private function handlePaymentConfirmation(ConversationSession $session, string $message): array
    {
        $choice = $this->normalized($message);

        if (in_array($choice, ['voltar_menu', 'voltar ao menu'], true)) {
            return ConversationResponse::make([], null, null, 'main_menu');
        }

        if (str_contains($choice, 'alterar') || in_array($choice, ['alterar_valor', 'alterar valor'], true)) {
            $this->cancelPendingDeposit($session);

            return $this->enter($session);
        }

        if (str_contains($choice, 'paguei') || in_array($choice, ['ja_paguei', 'continuar'], true)) {
            $context = $session->context ?? [];
            $movement = $this->confirmedDepositMovement($context);

            if ($movement) {
                $session->forceFill(['current_step' => 'waiting_success_action'])->save();

                return ConversationResponse::make([
                    ConversationResponse::text('⌚ Um momento, por favor... estamos verificando aqui.'),
                    ConversationResponse::options(
                        $this->depositReceivedText($movement, (float) ($context['valor_recarga'] ?? $movement->credit)),
                        [['label' => 'Continuar', 'value' => 'continuar']],
                    ),
                ], [
                    'type' => 'option',
                    'field' => 'deposit_success_action',
                ]);
            }

            return ConversationResponse::make([
                ConversationResponse::text('⌚ Um momento, por favor... estamos verificando aqui.'),
                ConversationResponse::text($this->depositPendingText()),
            ], null, null, 'main_menu');
        }

        if (in_array($choice, ['copy_pix', 'copiar codigo pix', 'copiar código pix', 'copy_boleto', 'copiar codigo do boleto', 'copiar código do boleto'], true)) {
            return ConversationResponse::make([
                ConversationResponse::options('O código está na mensagem acima. Para copiar no celular, toque e segure no texto do código e use Copiar.', [
                    ['label' => '♦️Já paguei!', 'value' => 'ja_paguei'],
                    ['label' => 'Continuar', 'value' => 'continuar'],
                ]),
            ], [
                'type' => 'option',
                'field' => 'payment_confirmation',
            ]);
        }

        if (in_array($choice, ['ja_paguei', 'ja paguei', 'já paguei', '♦️já paguei!'], true)) {
            return ConversationResponse::make([
                ConversationResponse::text('⌚ Um momento, por favor... estamos verificando aqui.'),
                ConversationResponse::text($this->depositPendingText()),
            ], null, null, 'main_menu');
        }

        return ConversationResponse::make([
            ConversationResponse::text('Escolha uma das opções da cobrança.'),
        ], [
            'type' => 'option',
            'field' => 'payment_confirmation',
        ]);
    }

    private function handleSuccessAction(string $message): array
    {
        if ($this->normalized($message) === 'continuar') {
            return ConversationResponse::make([], null, null, 'main_menu');
        }

        return ConversationResponse::make([
            ConversationResponse::options('Toque em Continuar para voltar ao menu.', [
                ['label' => 'Continuar', 'value' => 'continuar'],
            ]),
        ], [
            'type' => 'option',
            'field' => 'deposit_success_action',
        ]);
    }

    private function paymentCode(string $method, int $sessionId, float $amount): string
    {
        if ($method === 'boleto') {
            return '46191110000000000004254201037014315740000'.str_pad((string) (int) ($amount * 100), 8, '0', STR_PAD_LEFT);
        }

        return '00020126820014br.gov.bcb.pix01360f93c9da-1c89-4959-aa5c-a35cbcaa4e8302220MpMM'
            .$sessionId
            .'520400005303986540'
            .number_format($amount, 2, '.', '')
            .'5802BR5925MY PHONE MY MONEY6009PORTO ALEGRE6304SIMU';
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 2, ',', '.');
    }

    private function confirmedDepositMovement(array $context): ?Movement
    {
        $movementId = $context['movement_id'] ?? null;

        if (! $movementId) {
            return null;
        }

        return Movement::whereKey($movementId)
            ->where('event', 'deposit.simulated')
            ->first();
    }

    private function awardDepositBonuses(ConversationSession $session, Movement $movement, float $amount): array
    {
        $session->loadMissing('customer');

        if (! $session->customer) {
            return [];
        }

        $payload = [
            'source_type' => 'movement',
            'source_id' => $movement->id,
            'amount' => $amount,
            'conversation_session_id' => $session->id,
        ];

        $isFirstDeposit = $this->isFirstDeposit($movement);

        if ($isFirstDeposit) {
            $this->bonuses->award(BonusEvents::DEPOSIT_FIRST, $session->customer, $payload);
        }

        $this->bonuses->award(BonusEvents::DEPOSIT_ABOVE_AMOUNT, $session->customer, $payload);

        $inviterId = $session->customer->metadata['invited_by_customer_id'] ?? null;

        if (! $inviterId) {
            return [];
        }

        $inviter = Customer::find($inviterId);

        if (! $inviter) {
            return [];
        }

        $inviteAwards = $this->inviteDepositBonus->award($movement);

        if (! $isFirstDeposit) {
            return [];
        }

        $name = $session->customer->whatsappDisplayName() ?: 'O membro convidado';
        $bonusText = $inviteAwards !== [] ? ' O bônus de convite foi creditado.' : '';

        return [[
            'channel' => 'whatsapp',
            'to' => $inviter->phone,
            'messages' => [ConversationResponse::text(
                "💰 *Primeira carga de um convidado!*\n\n{$name} realizou a primeira carga de R$ "
                .$this->formatMoney($amount).'.'.$bonusText,
            )],
        ]];
    }

    private function isFirstDeposit(Movement $movement): bool
    {
        return ! Movement::where('customer_id', $movement->customer_id)
            ->where('event', 'deposit.simulated')
            ->whereKeyNot($movement->id)
            ->exists();
    }

    private function cancelPendingDeposit(ConversationSession $session): void
    {
        $session->loadMissing('customer');

        $movement = $this->confirmedDepositMovement($session->context ?? []);

        if (! $movement || ! $session->customer) {
            return;
        }

        $amount = (float) $movement->credit;
        $balance = (float) $session->customer->balance - $amount;
        $metadata = $movement->metadata ?? [];

        $session->customer->forceFill(['balance' => $balance])->save();
        $movement->forceFill([
            'event' => 'deposit.cancelled',
            'metadata' => [
                ...$metadata,
                'cancelled_reason' => 'customer_changed_amount',
                'cancelled_at' => now()->toISOString(),
            ],
        ])->save();
    }

    private function depositReceivedText(Movement $movement, float $amount): string
    {
        $amount = $amount > 0 ? $amount : (float) $movement->credit;

        return "😃 Carga recebida\n\n"
            .'*Valor:* R$*'.$this->formatMoney($amount)."*\n\n"
            .'👋 Sempre que precisar, é só dar um oi aqui.';
    }

    private function paymentMethodLabel(string $method): string
    {
        return match ($method) {
            'boleto' => 'Boleto',
            'credit_card' => 'Cartão de crédito',
            'debit_card' => 'Cartão de débito',
            default => 'PIX',
        };
    }

    private function depositPendingText(): string
    {
        return "Ainda não recebemos a confirmação do seu pagamento, mas esteja tranquilo, em seguida devemos receber.\n\n"
            .'Assim que identificarmos sua (re)Carga a gente te avisa aqui, ok?!';
    }
}
