<?php

namespace App\Services\Conversation\Flows;

use App\Models\ConversationSession;
use App\Models\Customer;
use App\Models\Movement;
use App\Services\CashOut\CashOutAvailability;
use App\Services\Conversation\Contracts\ConversationFlow;
use App\Services\Conversation\ConversationResponse;
use App\Services\Conversation\Flows\Concerns\ParsesConversationInput;
use App\Services\Credit\CreditLimitManager;
use App\Services\Documents\StatementPdfService;
use App\Services\Ledger\MovementLedger;
use App\Services\Payments\Contracts\PaymentProvider;
use App\Services\Phone\PhoneNumberNormalizer;

class AdminFlow implements ConversationFlow
{
    use ParsesConversationInput;

    public function __construct(
        private readonly PaymentProvider $paymentProvider,
        private readonly MovementLedger $ledger,
        private readonly PhoneNumberNormalizer $phones,
        private readonly CashOutAvailability $cashOut,
        private readonly StatementPdfService $statementPdf,
        private readonly CreditLimitManager $creditLimits,
    ) {}

    public function enter(ConversationSession $session): array
    {
        $session->loadMissing('customer');
        $options = [
            ['label' => '🔍 Membros', 'value' => 'consultar'],
            ['label' => '💳 Meu Cartão', 'value' => 'minha_conta'],
        ];

        $session->forceFill([
            'current_step' => 'home',
            'context' => [],
        ])->save();

        return ConversationResponse::make([
            [
                'type' => 'options',
                'text' => $this->homeText($session->customer),
                'header_image_url' => config('services.whatsapp.welcome_image_url'),
                'options' => $options,
            ],
        ], null, $options);
    }

    private function homeText(?Customer $customer): string
    {
        $name = $customer?->whatsappFirstName();
        $greeting = $name ? "Olá, {$name}, seja bem-vindo ao\n" : '';

        return $greeting
            ."Painel de controle *_MyPhone MyMoney®_*\n\n"
            .'Como posso te ajudar?';
    }

    public function handle(ConversationSession $session, string $message): array
    {
        if ($this->isCancel($message)) {
            return $this->enter($session);
        }

        return match ($session->current_step) {
            'home' => $this->routeFromHome($session, $message),
            'own_card' => $this->enter($session),
            'waiting_customer_phone' => $this->findCustomer($session, $message),
            'waiting_customer_action' => $this->routeCustomerAction($session, $message),
            'waiting_limit' => $this->adjustLimit($session, $message),
            'waiting_limit_confirmation' => $this->confirmLimit($session, $message),
            'waiting_pix_key' => $this->captureExternalPix($session, $message),
            'waiting_pix_amount' => $this->payExternalPix($session, $message),
            default => $this->enter($session),
        };
    }

    public function fromCashOutNotice(ConversationSession $session, int $customerId, string $action): array
    {
        $session->forceFill(['current_flow' => 'admin'])->save();
        if ($action === 'menu') {
            return $this->enter($session);
        }

        $customer = Customer::find($customerId);
        if (! $customer) {
            return $this->enter($session);
        }
        if ($action === 'search') {
            $session->forceFill(['current_step' => 'waiting_customer_phone', 'context' => []])->save();

            return ConversationResponse::make([
                ConversationResponse::text('Informe o número do WhatsApp do membro.'),
            ], ['type' => 'phone', 'field' => 'consulta_cliente']);
        }

        $session->forceFill([
            'current_step' => $action === 'adjust' ? 'waiting_limit' : 'waiting_customer_action',
            'context' => ['managed_customer_id' => $customer->id],
        ])->save();

        return $action === 'adjust'
            ? ConversationResponse::make([
                ConversationResponse::text('Informe o novo crédito concedido.'),
            ], ['type' => 'money', 'field' => 'novo_limite'])
            : $this->showCustomerStatement($session);
    }

    private function routeFromHome(ConversationSession $session, string $message): array
    {
        $choice = $this->normalized($message);

        if (in_array($choice, ['simular_credito', 'simular crédito', 'simular credito'], true)) {
            return $this->enter($session);
        }

        if (str_contains($choice, 'consult') || str_contains($choice, 'membros')) {
            $session->forceFill(['current_step' => 'waiting_customer_phone'])->save();

            return ConversationResponse::make([
                ConversationResponse::text('Informe o número do WhatsApp do membro.'),
            ], [
                'type' => 'phone',
                'field' => 'consulta_cliente',
            ]);
        }

        if (str_contains($choice, 'pix')) {
            $session->forceFill(['current_step' => 'waiting_pix_key'])->save();

            return ConversationResponse::make([
                ConversationResponse::text('Informe a chave PIX ou o código copia e cola.'),
            ], [
                'type' => 'text',
                'field' => 'chavePIX',
                'min_length' => 10,
                'max_length' => 300,
            ]);
        }

        if (str_contains($choice, 'minha') || str_contains($choice, 'conta') || str_contains($choice, 'cartao') || str_contains($choice, 'cartão')) {
            $this->attachOwnCustomerAccount($session);
            $session->load('customer');
            if (! $session->customer) {
                return ConversationResponse::make([
                    ConversationResponse::text('Não encontrei um cartão associado ao seu WhatsApp.'),
                ]);
            }

            return ConversationResponse::make([], null, null, 'main_menu');
        }

        return ConversationResponse::make([
            ConversationResponse::text('Cartão administrativo ativo.'),
        ]);
    }

    private function findCustomer(ConversationSession $session, string $message): array
    {
        $phone = $this->onlyDigits($message);
        $candidates = $this->phoneCandidates($phone);
        $customers = Customer::whereIn('phone', $candidates)->get()->keyBy('phone');
        $customer = null;

        foreach ($candidates as $candidate) {
            if ($customers->has($candidate)) {
                $customer = $customers->get($candidate);
                break;
            }
        }

        if (! $customer) {
            return ConversationResponse::make([
                ConversationResponse::text('Membro não encontrado. Informe outro telefone ou envie Cancelar.'),
            ]);
        }

        $context = $session->context ?? [];
        $context['managed_customer_id'] = $customer->id;

        $session->forceFill([
            'current_step' => 'waiting_customer_action',
            'context' => $context,
        ])->save();

        $customer->loadMissing('movements');

        return ConversationResponse::make([
            ConversationResponse::options($this->customerInfoText($customer), [
                ['label' => '💳 Ajustar crédito', 'value' => 'ajustar_limite'],
                ['label' => '🧾 Extrato do membro', 'value' => 'extrato_cliente'],
                ['label' => '🔍 Nova pesquisa', 'value' => 'nova_pesquisa'],
                ['label' => '🏡 Voltar ao Menu', 'value' => 'voltar_menu'],
            ]),
        ], [
            'type' => 'option',
            'field' => 'admin_customer_action',
        ]);
    }

    private function routeCustomerAction(ConversationSession $session, string $message): array
    {
        $choice = $this->normalized($message);

        if (str_contains($choice, 'ajustar') || str_contains($choice, 'limite') || str_contains($choice, 'credito')) {
            $session->forceFill(['current_step' => 'waiting_limit'])->save();

            return ConversationResponse::make([
                ConversationResponse::text('Informe o novo crédito concedido.'),
            ], [
                'type' => 'money',
                'field' => 'novo_limite',
            ]);
        }

        if (str_contains($choice, 'extrato')) {
            return $this->showCustomerStatement($session);
        }

        if (str_contains($choice, 'voltar') || str_contains($choice, 'menu')) {
            return $this->enter($session);
        }

        if (str_contains($choice, 'continuar') || str_contains($choice, 'pesquis') || str_contains($choice, 'nova')) {
            $session->forceFill([
                'current_step' => 'waiting_customer_phone',
                'context' => [],
            ])->save();

            return ConversationResponse::make([
                ConversationResponse::text('Informe o número do WhatsApp do membro.'),
            ], [
                'type' => 'phone',
                'field' => 'consulta_cliente',
            ]);
        }

        return ConversationResponse::make([
            ConversationResponse::text('Escolha 💳 Ajustar crédito, 🧾 Extrato do membro, 🔍 Nova pesquisa ou 🏡 Voltar ao Menu.'),
        ], [
            'type' => 'option',
            'field' => 'admin_customer_action',
        ]);
    }

    private function adjustLimit(ConversationSession $session, string $message): array
    {
        $context = $session->context ?? [];
        $customer = Customer::find($context['managed_customer_id'] ?? null);

        if (! $customer) {
            return $this->enter($session);
        }

        $limit = $this->moneyFromMessage($message);

        if ($limit === null || $limit < 0) {
            return ConversationResponse::make([
                ConversationResponse::text('Informe um limite válido.'),
            ]);
        }

        $context['novo_limite'] = $limit;

        $session->forceFill([
            'current_step' => 'waiting_limit_confirmation',
            'context' => $context,
        ])->save();

        return ConversationResponse::make([
            ConversationResponse::options(
                'Confirmar novo crédito de R$ '.$this->formatMoney($limit).' para '.($customer->whatsappDisplayName() ?: 'membro').'?',
                [
                    ['label' => '❌Cancelar', 'value' => 'cancelar'],
                    ['label' => '🤔 Alterar valor', 'value' => 'alterar_valor'],
                    ['label' => '✅Confirmar', 'value' => 'confirmar'],
                ],
            ),
        ], [
            'type' => 'option',
            'field' => 'admin_limit_confirmation',
        ]);
    }

    private function confirmLimit(ConversationSession $session, string $message): array
    {
        $choice = $this->normalized($message);

        if (str_contains($choice, 'alterar') && str_contains($choice, 'valor')) {
            $context = $session->context ?? [];
            unset($context['novo_limite']);

            $session->forceFill([
                'current_step' => 'waiting_limit',
                'context' => $context,
            ])->save();

            return ConversationResponse::make([
                ConversationResponse::text('Informe o novo crédito concedido.'),
            ], [
                'type' => 'money',
                'field' => 'novo_limite',
            ]);
        }

        if (! $this->isConfirm($message)) {
            return ConversationResponse::make([ConversationResponse::text('Escolha Alterar valor, Confirmar ou Cancelar.')]);
        }

        $context = $session->context ?? [];
        $customer = Customer::find($context['managed_customer_id'] ?? null);

        if (! $customer) {
            return $this->enter($session);
        }

        $limit = (float) ($context['novo_limite'] ?? 0);
        $this->creditLimits->set($customer, $limit, [
            'conversation_session_id' => $session->id,
            'updated_by' => $session->external_user_id,
            'source' => 'whatsapp_admin',
        ]);

        return ConversationResponse::make([
            ConversationResponse::text('Crédito concedido atualizado para R$ '.$this->formatMoney($limit).'.'),
        ], null, null, 'admin');
    }

    private function showCustomerStatement(ConversationSession $session): array
    {
        $context = $session->context ?? [];
        $customer = Customer::find($context['managed_customer_id'] ?? null);

        if (! $customer) {
            return $this->enter($session);
        }

        $session->forceFill(['current_step' => 'waiting_customer_action'])->save();

        $document = $this->statementPdf->generateAdminForCustomer($customer);
        $message = $document['has_financial_movements']
            ? 'Aqui está o extrato do membro.'
            : 'O membro ainda não possui movimentos financeiros. O extrato mostra a abertura do Cartão, com a data e hora do primeiro acesso ao MpMM® e saldo R$ 0,00.';

        return ConversationResponse::make([
            [
                'type' => 'document',
                'text' => substr($document['download_filename'], 0, -4),
                'filename' => $document['download_filename'],
                'url' => $document['url'],
                'path' => $document['path'],
            ],
            ConversationResponse::options($message, [
                ['label' => '🏡 Voltar ao Menu', 'value' => 'voltar_menu'],
                ['label' => '🔍 Nova pesquisa', 'value' => 'nova_pesquisa'],
            ]),
        ], [
            'type' => 'option',
            'field' => 'admin_statement_action',
        ]);
    }

    private function customerInfoText(Customer $customer): string
    {
        return "Informações do membro\n\n"
            .'Cartão: '.$this->formatPhone($customer->phone)."\n"
            .'Nome: '.($customer->whatsappDisplayName() ?? 'Não informado')."\n"
            .'CPF: '.($customer->cpf ?? 'Não informado')."\n\n"
            ."Saldo Atual:\n"
            .'R$ '.$this->formatMoney((float) $customer->balance)."\n\n"
            ."Crédito Concedido:\n"
            .'R$ '.$this->formatMoney((float) $customer->limit)."\n\n"
            .'O que gostaria de fazer?';
    }

    private function adminAccountText(?Customer $customer): string
    {
        if (! $customer) {
            return 'Cartão administrativo ativo.';
        }

        return "Meu Cartão\n\n"
            .'Cartão: '.$this->formatPhone($customer->phone)."\n"
            .'Nome: '.($customer->whatsappDisplayName() ?? 'Não informado')."\n"
            .'CPF: '.($customer->cpf ?? 'Não informado')."\n\n"
            ."Saldo Atual:\n"
            .'R$ '.$this->formatMoney((float) $customer->balance)."\n\n"
            ."Crédito Concedido:\n"
            .'R$ '.$this->formatMoney((float) $customer->limit);
    }

    private function attachOwnCustomerAccount(ConversationSession $session): void
    {
        if ($session->customer_id) {
            return;
        }

        $customer = Customer::whereIn('phone', $this->phoneCandidates($session->external_user_id))->first();

        if (! $customer) {
            return;
        }

        $session->forceFill(['customer_id' => $customer->id])->save();
    }

    private function statementPreviewText(Customer $customer, $movements): string
    {
        if ($movements->isEmpty()) {
            $firstUsageAt = $this->statementPdf->firstUsageAt($customer);

            return "Extrato do Membro\n\n"
                .'Cartão: '.$this->formatPhone($customer->phone)."\n\n"
                .'Abertura do Cartão / primeiro acesso ao MpMM®: '
                .$firstUsageAt?->copy()->timezone($customer->timezone ?: 'UTC')->format('d/m/Y H:i:s')."\n"
                .'Valor: R$ 0,00 | Saldo: R$ 0,00';
        }

        $summary = $this->statementSummary($customer, $movements);
        $lines = $movements
            ->sortByDesc('created_at')
            ->take(15)
            ->map(fn (Movement $movement) => $this->movementLine($movement, $customer))
            ->implode("\n");

        return "Extrato do Cartão\n\n"
            .'Cartão: '.$this->formatPhone($customer->phone)."\n\n"
            .'Titular: '.($customer->whatsappDisplayName() ?? 'Não informado')."\n\n"
            .'Saldo Anterior: R$ '.$this->formatMoney($summary['previous_balance'])."\n"
            .'Saldo Atual: R$ '.$this->formatMoney((float) $customer->balance)."\n\n"
            .'1 Total de Depósitos: R$ '.$this->formatMoney($summary['deposits'])."\n"
            .'2 Total de Saques: R$ '.$this->formatMoney($summary['withdrawals'])."\n"
            .'3 Depósitos Líquidos: R$ '.$this->formatMoney($summary['net_deposits'])."\n"
            .'4 Cash Back: R$ '.$this->formatMoney((float) $customer->redeemable_balance)."\n"
            .'5 Disponível para Resgate: R$ '.$this->formatMoney($this->cashOut->availableFor($customer))."\n\n"
            .'6 Transferências Recebidas: R$ '.$this->formatMoney($summary['transfers_received'])."\n"
            .'7 Transferências Enviadas: R$ '.$this->formatMoney($summary['transfers_sent'])."\n"
            .'8 Limite de Crédito: R$ '.$this->formatMoney((float) $customer->limit)."\n"
            .'9 Crédito Utilizado: R$ '.$this->formatMoney($summary['credit_used'])."\n"
            .'10 Crédito Disponível: R$ '.$this->formatMoney($summary['credit_available'])."\n\n"
            ."Valores em REAIS (BRL)\n"
            ."Data da Operação | Descrição | Valor | Saldo Final\n"
            .$lines;
    }

    private function statementSummary(Customer $customer, $movements): array
    {
        $oldest = $movements->sortBy('created_at')->first();
        $previousBalance = $oldest
            ? (float) $oldest->balance - (float) $oldest->credit + (float) $oldest->debit
            : 0;
        $cashOutSummary = $this->cashOut->summary($customer);
        $transfersReceived = (float) $movements
            ->filter(fn (Movement $movement) => $movement->event === 'transfer.received')
            ->sum('credit');
        $transfersSent = (float) $movements
            ->filter(fn (Movement $movement) => in_array($movement->event, ['transfer.sent', 'external_pix_payment.simulated'], true))
            ->sum('debit');
        $creditUsed = max(0, -1 * (float) $customer->balance);
        $creditAvailable = max(0, (float) $customer->limit - $creditUsed);

        return [
            'previous_balance' => $previousBalance,
            'deposits' => $cashOutSummary['deposits'],
            'withdrawals' => $cashOutSummary['withdrawals'],
            'net_deposits' => $cashOutSummary['net_deposits'],
            'transfers_received' => $transfersReceived,
            'transfers_sent' => $transfersSent,
            'credit_used' => $creditUsed,
            'credit_available' => $creditAvailable,
        ];
    }

    private function movementLine(Movement $movement, Customer $customer): string
    {
        $amount = (float) $movement->credit > 0
            ? (float) $movement->credit
            : -1 * (float) $movement->debit;

        return $movement->created_at?->copy()->timezone($customer->timezone ?: 'UTC')->format('d/m/Y H:i:s')
            .' | '.$this->movementDescription($movement)
            .' | R$ '.$this->formatMoney($amount)
            .' | R$ '.$this->formatMoney((float) $movement->balance);
    }

    private function movementDescription(Movement $movement): string
    {
        return match ($movement->event) {
            'deposit.simulated', 'deposit.confirmed' => 'Depósito confirmado',
            'deposit.pending' => 'Cobrança de depósito pendente (não creditada)',
            'deposit.cancelled' => 'Cobrança de depósito cancelada',
            'redeem_pix.simulated', 'redeem_pix.submitted' => 'Resgate Pix enviado',
            'transfer.received' => 'Transferência recebida',
            'transfer.sent' => 'Transferência enviada',
            'external_pix_payment.simulated' => 'PIX externo',
            'credit_limit.updated' => 'Limite de crédito atualizado',
            default => $movement->event,
        };
    }

    private function formatPhone(string $phone): string
    {
        if (strlen($phone) === 13 && str_starts_with($phone, '55')) {
            return '+55 ('.substr($phone, 2, 2).') '.substr($phone, 4, 5).'-'.substr($phone, 9);
        }

        return $phone;
    }

    private function phoneCandidates(string $phone): array
    {
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
            strlen($phone) === 11 ? '55'.$phone : null,
            strlen($phone) === 10 ? '55'.substr($phone, 0, 2).'9'.substr($phone, 2) : null,
            $phone,
            $withoutCountry,
            $withoutNinthDigit ? '55'.$withoutNinthDigit : null,
        ])));
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 2, ',', '.');
    }

    private function captureExternalPix(ConversationSession $session, string $message): array
    {
        $context = $session->context ?? [];
        $context['pix_key'] = trim($message);

        $session->forceFill([
            'current_step' => 'waiting_pix_amount',
            'context' => $context,
        ])->save();

        return ConversationResponse::make([
            ConversationResponse::text('PIX validado de forma simulada. Informe o valor.'),
        ], [
            'type' => 'money',
            'field' => 'valor_transf',
        ]);
    }

    private function payExternalPix(ConversationSession $session, string $message): array
    {
        $amount = $this->moneyFromMessage($message);

        if ($amount === null || $amount <= 0) {
            return ConversationResponse::make([
                ConversationResponse::text('Informe um valor válido.'),
            ]);
        }

        $context = $session->context ?? [];
        $session->loadMissing('customer');

        if (! $session->customer) {
            return ConversationResponse::make([
                ConversationResponse::text('Cartão administrativo não encontrado.'),
            ], null, null, 'admin');
        }

        $payment = $this->paymentProvider->sendPix($amount, $context['pix_key'] ?? '', [
            'conversation_session_id' => $session->id,
        ]);

        $movement = $this->ledger->debit($session->customer, $amount, 'external_pix_payment.simulated', [
            'conversation_session_id' => $session->id,
            'external_provider' => $payment->provider,
            'external_operation_id' => $payment->externalId,
            'external_receipt_url' => $payment->receiptUrl,
            'metadata' => [
                'pix_key' => $context['pix_key'] ?? null,
                ...$payment->metadata,
            ],
        ]);

        return ConversationResponse::make([
            ConversationResponse::text('PIX externo simulado. Movimentação #'.$movement->id.'.'),
        ], null, null, 'admin');
    }
}
