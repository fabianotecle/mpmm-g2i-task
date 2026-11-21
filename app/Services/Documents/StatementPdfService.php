<?php

namespace App\Services\Documents;

use App\Models\ConversationSession;
use App\Models\Customer;
use App\Models\Movement;
use App\Models\InstallmentProposal;
use App\Services\CashOut\CashOutAvailability;
use App\Services\Phone\PhoneNumberFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StatementPdfService
{
    private const FRONT_OFFICE_STATEMENT_EVENTS = [
        'deposit.confirmed',
        'deposit.reversed',
        'redeem_pix.confirmed',
        'redeem_pix.submitted',
        'redeem_pix.reversed',
        'external_pix_payment.confirmed',
        'transfer.received',
        'transfer.sent',
        'transfer.reversed',
    ];

    private const FIRST_PAGE_ROWS = 14;

    private const OTHER_PAGE_ROWS = 20;

    private const MAX_ROWS = 74;

    public function __construct(
        private readonly CashOutAvailability $cashOut,
        private readonly PhoneNumberFormatter $phones,
    ) {}

    public function generate(ConversationSession $session): array
    {
        $session->loadMissing('customer');

        return $this->generateForCustomer($session->customer);
    }

    public function generateForCustomer(Customer $customer): array
    {
        $statement = $this->dataForCustomer($customer);
        $timezone = $statement['timezone'];
        $movements = $statement['movements'];
        $hasFinancialMovements = $statement['has_financial_movements'];
        $firstAccessAt = $statement['first_access_at'];

        $token = (string) Str::uuid();
        $filename = "extrato-mpmm-{$token}.pdf";
        $path = "statements/{$filename}";
        $logoPath = public_path('assets/whatsapp/logo.jpg');
        $whatsappLogoPath = public_path('assets/whatsapp/whatsapp.png');

        $pdf = Pdf::loadView('documents.statement', [
            ...$statement,
            'logoData' => is_file($logoPath)
                ? 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($logoPath))
                : null,
            'whatsappLogoData' => is_file($whatsappLogoPath)
                ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($whatsappLogoPath))
                : null,
        ])->setPaper('a4', 'portrait');

        Storage::disk('local')->put($path, $pdf->output());

        $baseUrl = rtrim((string) config('services.documents.public_url', config('app.url')), '/');

        return [
            'filename' => $filename,
            'download_filename' => $this->downloadFilename($customer, $timezone),
            'path' => Storage::disk('local')->path($path),
            'url' => $baseUrl.'/api/documents/statements/'.$filename,
            'has_financial_movements' => $hasFinancialMovements,
            'opening_at' => $hasFinancialMovements ? null : $firstAccessAt?->toISOString(),
            'first_access_at' => $hasFinancialMovements ? null : $firstAccessAt?->toISOString(),
        ];
    }

    public function generateAdminForCustomer(Customer $customer): array
    {
        $statement = $this->dataForAdminCustomer($customer);
        $token = (string) Str::uuid();
        $filename = "extrato-admin-mpmm-{$token}.pdf";
        $path = "statements/{$filename}";
        $logoPath = public_path('assets/whatsapp/logo.jpg');
        $generatedAt = now();
        $pdf = Pdf::loadView('documents.admin-statement', [
            ...$statement,
            'logoData' => is_file($logoPath)
                ? 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($logoPath))
                : null,
            'generatedAt' => $generatedAt,
        ])->setPaper('a4', 'landscape');
        Storage::disk('local')->put($path, $pdf->output());
        $phone = preg_replace('/\D+/', '', $customer->phone) ?: $customer->phone;
        $baseUrl = rtrim((string) config('services.documents.public_url', config('app.url')), '/');

        return [
            'filename' => $filename,
            'download_filename' => "MpMM Extrato do Cartão {$phone} "
                .$generatedAt->timezone($statement['timezone'])->format('Ymd His').'.pdf',
            'path' => Storage::disk('local')->path($path),
            'url' => $baseUrl.'/api/documents/statements/'.$filename,
            'has_financial_movements' => $statement['movements']->isNotEmpty(),
        ];
    }

    private function downloadFilename(Customer $customer, string $timezone): string
    {
        $whatsappNumber = preg_replace('/\D+/', '', $customer->phone) ?: $customer->phone;
        $generatedAt = now($timezone)->format('Ymd His');

        return "MpMM extrato {$whatsappNumber} {$generatedAt}.pdf";
    }

    public function dataForCustomer(Customer $customer): array
    {
        $timezone = $customer->timezone ?: config('app.timezone');
        $query = Movement::where('customer_id', $customer->id)
            ->whereNotIn('event', [
                'deposit.pending',
                'deposit.cancelled',
                'deposit.simulated',
                'redeem_pix.simulated',
                'external_pix_payment.simulated',
                'simulation.reversed',
            ])
            ->where(function ($query) {
                $query->where('debit', '>', 0)->orWhere('credit', '>', 0)
                    ->orWhere('rotative_debit', '>', 0)->orWhere('rotative_credit', '>', 0);
            });
        $allMovements = (clone $query)
            ->select(['id', 'event', 'debit', 'credit', 'balance', 'rotative_debit', 'rotative_credit', 'rotative_balance', 'created_at'])
            ->latest('created_at')
            ->latest('id')
            ->get();
        $hasFinancialMovements = $allMovements->isNotEmpty();
        $movements = (clone $query)
            ->with('originCustomer')
            ->latest('created_at')
            ->latest('id')
            ->limit(self::MAX_ROWS)
            ->get();
        $firstAccessAt = $this->firstUsageAt($customer);

        if (! $hasFinancialMovements) {
            $opening = new Movement([
                'customer_id' => $customer->id,
                'event' => 'account.opened',
                'debit' => 0,
                'credit' => 0,
                'balance' => 0,
                'currency' => 'BRL',
                'metadata' => ['first_access_at' => $firstAccessAt?->toISOString()],
            ]);
            $opening->created_at = $firstAccessAt;
            $movements = collect([$opening]);
        }

        $legacyPhones = $this->legacyCustomerPhones();
        $installmentTotals = $this->installmentTotals($movements);
        $statementRows = $movements->map(fn (Movement $movement) => $this->statementRow(
            $movement,
            $timezone,
            $legacyPhones,
            $installmentTotals,
        ))->values();
        $statementRows = $statementRows->map(fn (array $row, int $index) => [
            ...$row,
            'month_break' => $index > 0 && $statementRows[$index - 1]['month'] !== $row['month'],
        ]);
        $pages = $this->paginate($statementRows);

        return [
            'customer' => $customer,
            'movements' => $movements,
            'pages' => $pages,
            'timezone' => $timezone,
            'summary' => $this->summary($customer, $allMovements),
            'whatsappName' => $customer->whatsappDisplayName() ?: 'Não informado',
            'formattedPhone' => $this->phones->international($customer->phone),
            'has_financial_movements' => $hasFinancialMovements,
            'first_access_at' => $firstAccessAt,
        ];
    }

    public function dataForAdminCustomer(Customer $customer): array
    {
        $timezone = $customer->timezone ?: config('app.timezone');
        $movements = Movement::where('customer_id', $customer->id)
            ->whereIn('event', self::FRONT_OFFICE_STATEMENT_EVENTS)
            ->with('originCustomer')
            ->latest('created_at')
            ->latest('id')
            ->get();
        $firstAccessAt = $this->firstUsageAt($customer);
        $lastAccessAt = ConversationSession::where('customer_id', $customer->id)
            ->latest('last_interaction_at')
            ->value('last_interaction_at');
        $legacyPhones = $this->legacyCustomerPhones();
        $installmentTotals = $this->installmentTotals($movements);
        $rows = $movements->map(function (Movement $movement) use ($timezone, $legacyPhones, $installmentTotals) {
            return [
                ...$this->statementRow($movement, $timezone, $legacyPhones, $installmentTotals),
                'id' => $movement->id,
                'event' => $movement->event,
                'debit' => (float) $movement->debit,
                'credit' => (float) $movement->credit,
                'provider' => $movement->external_provider,
                'external_id' => $movement->external_operation_id,
            ];
        })->values();
        $rows = $rows->map(fn (array $row, int $index) => [
            ...$row,
            'month_break' => $index > 0 && $rows[$index - 1]['month'] !== $row['month'],
        ]);

        $eventCounts = $movements->countBy('event')->sortDesc()->all();
        $activeDays = $movements->pluck('created_at')
            ->filter()
            ->map(fn ($date) => $date->copy()->timezone($timezone)->format('Y-m-d'))
            ->unique()
            ->count();

        return [
            'customer' => $customer,
            'movements' => $movements,
            'pages' => $rows->chunk(22)->values(),
            'timezone' => $timezone,
            'whatsappName' => $customer->whatsappDisplayName() ?: 'Não informado',
            'formattedPhone' => $this->phones->international($customer->phone),
            'firstAccessAt' => $firstAccessAt,
            'lastAccessAt' => $lastAccessAt,
            'statistics' => [
                'movement_count' => $movements->count(),
                'credits_count' => $movements->where('credit', '>', 0)->count(),
                'debits_count' => $movements->where('debit', '>', 0)->count(),
                'credits_total' => (float) $movements->sum(fn (Movement $movement) => (float) $movement->credit),
                'debits_total' => (float) $movements->sum(fn (Movement $movement) => (float) $movement->debit),
                'largest_credit' => (float) $movements->max('credit'),
                'largest_debit' => (float) $movements->max('debit'),
                'average_amount' => $movements->count() > 0
                    ? (float) $movements->avg(fn (Movement $movement) => max((float) $movement->credit, (float) $movement->debit))
                    : 0,
                'active_days' => $activeDays,
                'sessions_count' => ConversationSession::where('customer_id', $customer->id)->count(),
                'events' => $eventCounts,
            ],
        ];
    }

    private function summary(Customer $customer, Collection $movements): array
    {
        $oldest = $movements->last();
        $previousBalance = $oldest
            ? (float) $oldest->balance - (float) $oldest->credit + (float) $oldest->debit
            : 0;
        $deposits = (float) $movements
            ->where('event', 'deposit.confirmed')
            ->sum('credit') - (float) $movements->where('event', 'deposit.reversed')->sum('debit');
        $withdrawals = (float) $movements
            ->whereIn('event', [
                'redeem_pix.confirmed',
                'redeem_pix.submitted',
                'external_pix_payment.confirmed',
            ])->sum('debit');
        $transfersReceived = (float) $movements->where('event', 'transfer.received')
            ->sum(fn (Movement $movement) => (float) $movement->credit + (float) $movement->rotative_credit)
            - (float) $movements->where('event', 'transfer.reversed')
                ->sum(fn (Movement $movement) => (float) $movement->debit + (float) $movement->rotative_debit);
        $transfersSent = (float) $movements->where('event', 'transfer.sent')
            ->sum(fn (Movement $movement) => (float) $movement->debit + (float) $movement->rotative_debit)
            - (float) $movements->where('event', 'transfer.reversed')
                ->sum(fn (Movement $movement) => (float) $movement->credit + (float) $movement->rotative_credit);
        $creditUsed = (float) \App\Models\CreditDebt::where('debtor_customer_id', $customer->id)
            ->where('status', 'open')->sum('outstanding_amount');

        return [
            'previous_balance' => $previousBalance,
            'current_balance' => (float) $customer->balance,
            'debit_balance' => (float) $customer->balance,
            'rotative_balance' => (float) $customer->rotative_balance,
            'total_balance' => (float) $customer->balance + (float) $customer->rotative_balance,
            'credits' => (float) $movements->sum(fn (Movement $movement) => (float) $movement->credit + (float) $movement->rotative_credit),
            'debits' => (float) $movements->sum(fn (Movement $movement) => (float) $movement->debit + (float) $movement->rotative_debit),
            'deposits' => $deposits,
            'withdrawals' => $withdrawals,
            'net_deposits' => $deposits - $withdrawals,
            'cashback' => (float) $customer->redeemable_balance,
            'available_for_cash_out' => $this->cashOut->availableFor($customer),
            'transfers_received' => $transfersReceived,
            'transfers_sent' => $transfersSent,
            'credit_limit' => (float) $customer->limit,
            'credit_used' => $creditUsed,
            'credit_available' => max(0, (float) $customer->limit - $creditUsed),
            'count' => $movements->count(),
        ];
    }

    private function statementRow(Movement $movement, string $timezone, array $legacyPhones, array $installmentTotals = []): array
    {
        $isOpening = $movement->event === 'account.opened';
        $amount = (float) $movement->credit + (float) $movement->rotative_credit
            - (float) $movement->debit - (float) $movement->rotative_debit;
        $isCredit = $amount >= 0;

        return [
            'date' => $movement->created_at?->copy()->timezone($timezone)->format('d/m/Y H:i:s O'),
            'month' => $movement->created_at?->copy()->timezone($timezone)->format('Y-m'),
            'description' => $this->movementDescription($movement, $legacyPhones, $installmentTotals),
            'amount' => $isOpening ? null : $amount,
            'balance' => (float) $movement->balance + (float) $movement->rotative_balance,
            'fund_type' => $movement->fund_type,
            'direction' => $isOpening ? null : ($isCredit ? 'credit' : 'debit'),
            'is_private' => $this->isPrivateTransfer($movement),
        ];
    }

    private function isPrivateTransfer(Movement $movement): bool
    {
        return in_array($movement->event, ['transfer.received', 'transfer.sent'], true)
            && (bool) ($movement->metadata['anonymous'] ?? false);
    }

    private function movementDescription(Movement $movement, array $legacyPhones, array $installmentTotals = []): string
    {
        $metadata = $movement->metadata ?? [];
        $amount = max((float) $movement->credit, (float) $movement->debit, (float) $movement->rotative_credit, (float) $movement->rotative_debit);

        return match ($movement->event) {
            'deposit.confirmed', 'deposit.simulated' => sprintf(
                '💰 (re)Carga via %s · cadastro: %s · R$ %s',
                $this->depositMethod($movement),
                $movement->external_operation_id ?: ($metadata['nsu'] ?? $metadata['legacy_transaction_id'] ?? 'não informado'),
                number_format($amount, 2, ',', '.'),
            ),
            'transfer.received' => $this->transferDescription($movement, true, $legacyPhones, $installmentTotals),
            'transfer.sent' => $this->transferDescription($movement, false, $legacyPhones, $installmentTotals),
            'transfer.reversed' => (float) $movement->credit > 0
                ? '↩️ Devolução de transferência anulada · lançamento #'.($metadata['reverses_movement_id'] ?? 'não informado')
                : '↩️ Estorno de transferência recebida · lançamento #'.($metadata['reverses_movement_id'] ?? 'não informado'),
            'deposit.reversed' => '↩️ Estorno de (re)carga Pix não efetivada · lançamento #'.($metadata['reverses_movement_id'] ?? 'não informado'),
            'rotative_credit.granted' => '💳 Crédito rotativo concedido',
            'rotative_credit.debt_payment' => '💰 Pagamento intencional de dívida de crédito',
            'rotative_credit.released' => '💰 Crédito rotativo convertido em saldo disponível',
            'redeem_pix.confirmed', 'redeem_pix.submitted', 'redeem_pix.simulated',
            'external_pix_payment.confirmed', 'external_pix_payment.simulated' => sprintf(
                '💸 Resgate Pix para a 🔑 chave %s · R$ %s',
                $this->pixKey($movement),
                number_format($amount, 2, ',', '.'),
            ),
            'redeem_pix.reversed' => 'Estorno de descarga Pix',
            'cash_out.fee' => 'Tarifa de descarga',
            'bonus.cashback', 'bonus.transfer_cashback' => 'Bônus cashback',
            'bonus.referral', 'bonus.invite' => 'Bônus de indicação',
            'bonus.deposit' => 'Bônus de carga',
            'bonus.customer_welcome' => 'Bônus de boas-vindas',
            'account.opened' => 'Abertura do Cartão',
            default => $metadata['description'] ?? str_replace(['.', '_'], ' ', ucfirst($movement->event)),
        };
    }

    private function transferDescription(Movement $movement, bool $received, array $legacyPhones, array $installmentTotals): string
    {
        $metadata = $movement->metadata ?? [];
        $phone = $received
            ? ($movement->originCustomer?->phone ?? $legacyPhones[$metadata['origin'] ?? ''] ?? null)
            : ($metadata['recipient'] ?? $legacyPhones[$metadata['destination'] ?? ''] ?? null);
        $direction = $received ? 'recebida de' : 'enviada para';

        $installmentNumber = (int) ($metadata['installment_number'] ?? 0);
        $installmentTotal = (int) ($installmentTotals[$metadata['installment_proposal_id'] ?? ''] ?? 0);
        $installmentSuffix = $installmentNumber > 0 && $installmentTotal >= $installmentNumber
            ? " · Parcela {$installmentNumber}/{$installmentTotal}"
            : '';

        if ((bool) ($metadata['anonymous'] ?? false)) {
            $counterparty = $phone ? $this->phones->privateMask($phone) : 'número oculto';

            return ($received ? '↗️ ' : '↘️ ').'Transferência privada '.$direction.' '.$counterparty.$installmentSuffix;
        }

        $name = $received
            ? ($metadata['sender_whatsapp_name'] ?? $movement->originCustomer?->whatsappDisplayName())
            : ($metadata['recipient_whatsapp_name'] ?? null);
        $counterparty = $phone ? $this->phones->international($phone) : 'número não informado';

        if ($name) {
            $counterparty .= ' — '.$name;
        }

        return ($received ? '↗️ ' : '↘️ ').'Transferência '.$direction.' '.$counterparty.$installmentSuffix;
    }

    private function installmentTotals(Collection $movements): array
    {
        $ids = $movements->pluck('metadata.installment_proposal_id')->filter()->unique();

        return $ids->isEmpty() ? [] : InstallmentProposal::whereIn('id', $ids)->pluck('installments', 'id')->all();
    }

    private function depositMethod(Movement $movement): string
    {
        $metadata = $movement->metadata ?? [];
        $method = strtolower((string) ($metadata['payment_method'] ?? $metadata['provider_hint'] ?? ''));
        $description = mb_strtolower((string) ($metadata['description'] ?? ''));

        return match (true) {
            $method === 'pix', str_contains($description, 'pix') => 'PIX',
            $method === 'boleto', str_contains($description, 'boleto') => 'boleto',
            $method === 'debit_card' => 'cartão de débito',
            $method === 'credit_card' => 'cartão de crédito',
            in_array($method, ['card', 'cartao', 'cartão'], true),
            str_contains($description, 'mastercard'), str_contains($description, 'cart') => 'cartão',
            default => strtoupper((string) ($movement->external_provider ?: 'não informada')),
        };
    }

    private function pixKey(Movement $movement): string
    {
        $metadata = $movement->metadata ?? [];
        $key = $metadata['pix_key'] ?? null;
        if (! $key && preg_match('/PIX enviado para\s+(.+)$/iu', (string) ($metadata['description'] ?? ''), $match)) {
            $key = trim($match[1]);
        }

        return $key ?: 'não informada';
    }

    private function legacyCustomerPhones(): array
    {
        return Customer::query()
            ->whereNotNull('metadata')
            ->get(['phone', 'metadata'])
            ->mapWithKeys(fn (Customer $customer) => ($customer->metadata['legacy_customer_id'] ?? null)
                ? [$customer->metadata['legacy_customer_id'] => $customer->phone]
                : [])
            ->all();
    }

    public function firstUsageAt(Customer $customer): mixed
    {
        return collect([
            ConversationSession::where('customer_id', $customer->id)->min('created_at'),
            Movement::where('customer_id', $customer->id)->min('created_at'),
        ])->filter()->map(fn ($date) => \Illuminate\Support\Carbon::parse($date))->sort()->first();
    }

    private function paginate(Collection $rows): Collection
    {
        $pages = collect([$rows->take(self::FIRST_PAGE_ROWS)->values()]);
        $remaining = $rows->skip(self::FIRST_PAGE_ROWS)->values();

        foreach ($remaining->chunk(self::OTHER_PAGE_ROWS) as $chunk) {
            $pages->push($chunk->values());
        }

        return $pages->filter(fn (Collection $page) => $page->isNotEmpty())->values();
    }
}
