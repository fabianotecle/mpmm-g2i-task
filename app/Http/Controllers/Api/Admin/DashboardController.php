<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CreditDebt;
use App\Models\Movement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    private const CASH_FLOW_EVENTS = [
        'deposit.confirmed',
        'redeem_pix.submitted',
        'redeem_pix.confirmed',
        'external_pix_payment.confirmed',
    ];

    public function __invoke(): JsonResponse
    {
        $customers = Customer::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active")
            ->selectRaw("COALESCE(SUM(balance), 0) as balance")
            ->selectRaw("COALESCE(SUM(rotative_balance), 0) as rotative_balance")
            ->selectRaw("COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) as positive_balance")
            ->selectRaw("COALESCE(SUM(CASE WHEN balance < 0 THEN balance ELSE 0 END), 0) as negative_balance")
            ->selectRaw("SUM(CASE WHEN balance > 0 THEN 1 ELSE 0 END) as positive_accounts")
            ->selectRaw("SUM(CASE WHEN balance < 0 THEN 1 ELSE 0 END) as negative_accounts")
            ->selectRaw("SUM(CASE WHEN balance = 0 THEN 1 ELSE 0 END) as zero_accounts")
            ->selectRaw('COALESCE(SUM(`limit`), 0) as credit_limit')
            ->first();

        $memberStatistics = app(\App\Services\MemberStatistics::class)->summarize(Customer::query());
        $origins = array_map(fn ($origin) => $origin['count'], $memberStatistics['origins']);

        $cashFlows = Movement::query()
            ->whereIn('event', self::CASH_FLOW_EVENTS)
            ->where(fn ($query) => $query->where('credit', '>', 0)->orWhere('debit', '>', 0));

        $cashIn = (float) (clone $cashFlows)
            ->where('event', 'deposit.confirmed')
            ->sum('credit');
        $cashOut = (float) (clone $cashFlows)
            ->whereIn('event', [
                'redeem_pix.submitted',
                'redeem_pix.confirmed',
                'external_pix_payment.confirmed',
            ])
            ->sum('debit');
        $platformBalance = round($cashIn - $cashOut, 2);
        $transactions = $this->transactions();
        $movementBreakdown = $this->movementBreakdown($transactions);
        $transferAudit = $this->transferAudit();
        $uniqueTransfers = (clone $transactions)->where('event', 'transfer.sent');
        $uniqueTransferCount = (clone $uniqueTransfers)->count();
        $uniqueTransferVolume = (float) (clone $uniqueTransfers)->sum('debit');

        $eventTotals = (clone $transactions)
            ->select('event', DB::raw('COUNT(*) as total'))
            ->groupBy('event')
            ->orderByDesc('total')
            ->limit(8)
            ->pluck('total', 'event');

        $recentTransactions = $this->transactions();

        $creditLimit = (float) $customers->credit_limit;
        $creditTaken = (float) CreditDebt::query()->where('status', 'open')->sum('outstanding_amount');
        $rotativeBalance = (float) $customers->rotative_balance;
        $creditUsed = $creditTaken;
        $positiveBalance = round((float) $customers->positive_balance, 2);
        $legacyBalance = round($positiveBalance - $platformBalance - $creditUsed, 2);

        return response()->json([
            'summary' => [
                'customers' => (int) $customers->total,
                'active_customers' => (int) $customers->active,
                'customer_balance' => $platformBalance,
                'registered_customer_balance' => (float) $customers->balance,
                'rotative_balances' => $rotativeBalance,
                'debit_balances' => (float) $customers->positive_balance,
                'total_balances' => round((float) $customers->balance + $rotativeBalance, 2),
                'credit_balances' => (float) $customers->negative_balance,
                'debit_accounts' => (int) $customers->positive_accounts,
                'credit_accounts' => (int) $customers->negative_accounts,
                'zero_balance_accounts' => (int) $customers->zero_accounts,
                'cash_in' => $cashIn,
                'cash_out' => $cashOut,
                'credit_limit' => $creditLimit,
                'credit_used' => $creditUsed,
                'credit_available' => max(0, $creditLimit - $creditUsed),
                'credit_utilization_percentage' => $creditLimit > 0
                    ? round(($creditUsed / $creditLimit) * 100, 2)
                    : 0,
                'movements' => $movementBreakdown['total'],
                'movement_breakdown' => $movementBreakdown,
                'unique_transfers' => $uniqueTransferCount,
                'unique_transfer_volume' => $uniqueTransferVolume,
                'transfer_audit' => $transferAudit,
            ],
            'balance_composition' => [
                'cash_in' => $cashIn,
                'cash_out' => $cashOut,
                'external_net_balance' => $platformBalance,
                'positive_balances' => $positiveBalance,
                'credit_taken' => $creditTaken,
                'internal_balance' => $rotativeBalance,
                'legacy_to_reconcile' => $legacyBalance,
                'reconciliation_status' => abs($legacyBalance) < 0.005 ? 'reconciled' : 'pending',
                'net_balance' => $platformBalance,
            ],
            'customer_origins' => $origins,
            'member_statistics' => $memberStatistics,
            'cash_in_methods' => $this->cashInMethods(),
            'movement_events' => $eventTotals,
            'recent_movements' => $recentTransactions
                ->with('customer:id,name,phone,whatsapp_profile_name,whatsapp_username')
                ->latest()
                ->latest('id')
                ->limit(8)
                ->get()
                ->map(fn (Movement $movement) => $this->movementPayload($movement)),
        ]);
    }

    private function cashInMethods(): array
    {
        $totals = array_fill_keys(['pix', 'boleto', 'debit_card', 'credit_card', 'unknown'], ['count' => 0, 'cents' => 0]);
        foreach (Movement::query()->where('event', 'deposit.confirmed')->where('credit', '>', 0)
            ->where('currency', 'BRL')->select(['credit', 'metadata'])->cursor() as $movement) {
            $metadata = $movement->metadata ?? [];
            $method = strtolower(trim((string) ($metadata['payment_method'] ?? $metadata['billing_type'] ?? '')));
            $method = match ($method) {
                'pix', 'boleto', 'debit_card', 'credit_card' => $method,
                'credit card', 'creditcard' => 'credit_card',
                'debit card', 'debitcard' => 'debit_card',
                default => 'unknown',
            };
            $totals[$method]['count']++;
            $totals[$method]['cents'] += (int) round((float) $movement->credit * 100);
        }

        $identified = ['count' => 0, 'cents' => 0];
        foreach (['pix', 'boleto', 'debit_card', 'credit_card'] as $method) {
            $identified['count'] += $totals[$method]['count'];
            $identified['cents'] += $totals[$method]['cents'];
        }
        $all = [
            'count' => $identified['count'] + $totals['unknown']['count'],
            'cents' => $identified['cents'] + $totals['unknown']['cents'],
        ];

        return array_map(fn ($total) => [
            'count' => $total['count'],
            'total' => $total['cents'] / 100,
            'average' => $total['count'] > 0 ? round($total['cents'] / $total['count'] / 100, 2) : 0,
        ], [...$totals, 'identified_total' => $identified, 'all' => $all]);
    }

    private function transactions(): Builder
    {
        return Movement::query()
            ->whereIn('event', [...self::CASH_FLOW_EVENTS, 'transfer.sent'])
            ->where(fn ($query) => $query->where('credit', '>', 0)->orWhere('debit', '>', 0));
    }

    private function movementBreakdown(Builder $query): array
    {
        $events = (clone $query)->select('event')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('event')
            ->pluck('total', 'event');
        $cashIn = (int) ($events['deposit.confirmed'] ?? 0);
        $cashOut = array_sum(array_map(
            fn ($event) => (int) ($events[$event] ?? 0),
            ['redeem_pix.submitted', 'redeem_pix.confirmed', 'external_pix_payment.confirmed'],
        ));
        $transfers = (int) ($events['transfer.sent'] ?? 0);

        return [
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'transfers' => $transfers,
            'total' => $cashIn + $cashOut + $transfers,
        ];
    }

    private function transferAudit(): array
    {
        $sent = Movement::query()->where('event', 'transfer.sent')
            ->where('debit', '>', 0)
            ->whereNull('metadata->legacy_transaction_id')
            ->get(['id', 'debit']);
        $received = Movement::query()->where('event', 'transfer.received')
            ->where('credit', '>', 0)
            ->whereNull('metadata->legacy_transaction_id')
            ->get(['id', 'credit', 'metadata']);
        $receivedSourceIds = $received
            ->pluck('metadata')
            ->map(fn ($metadata) => (int) ($metadata['source_movement_id'] ?? 0))
            ->filter()
            ->flip();
        $unpaired = $sent->reject(fn (Movement $movement) => $receivedSourceIds->has($movement->id));
        $sentById = $sent->keyBy('id');
        $unpairedReceived = $received->reject(function (Movement $movement) use ($sentById) {
            $source = $sentById->get((int) ($movement->metadata['source_movement_id'] ?? 0));

            return $source && abs((float) $source->debit - (float) $movement->credit) < 0.005;
        });
        $legacySent = Movement::query()->where('event', 'transfer.sent')->where('debit', '>', 0)
            ->whereNotNull('metadata->legacy_transaction_id');
        $legacyReceived = Movement::query()->where('event', 'transfer.received')->where('credit', '>', 0)
            ->whereNotNull('metadata->legacy_transaction_id');
        $legacyCountDifference = (clone $legacySent)->count() - (clone $legacyReceived)->count();
        $legacyAmountDifference = round(
            (float) $legacySent->sum('debit') - (float) $legacyReceived->sum('credit'), 2,
        );

        return [
            'unpaired_count' => $unpaired->count(),
            'unpaired_amount' => round((float) $unpaired->sum('debit'), 2),
            'unpaired_movement_ids' => $unpaired->pluck('id')->values(),
            'unpaired_received_count' => $unpairedReceived->count(),
            'unpaired_received_movement_ids' => $unpairedReceived->pluck('id')->values(),
            'legacy_count_difference' => $legacyCountDifference,
            'legacy_amount_difference' => $legacyAmountDifference,
            'has_mismatch' => $unpaired->isNotEmpty() || $unpairedReceived->isNotEmpty()
                || $legacyCountDifference !== 0 || abs($legacyAmountDifference) >= 0.005,
        ];
    }

    public function movements(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'type' => ['nullable', Rule::in(['cash_in', 'cash_out', 'transfer'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', Rule::in(['created_at', 'id', 'customer', 'type', 'amount'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'transaction_id' => ['nullable', 'integer', 'min:1'],
            'member' => ['nullable', 'string', 'max:200'],
            'amount_min' => ['nullable', 'numeric'],
            'amount_max' => ['nullable', 'numeric', ...($request->filled('amount_min') ? ['gte:amount_min'] : [])],
        ]);
        $query = $this->transactions()
            ->with('customer:id,name,phone,whatsapp_profile_name,whatsapp_username')
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->whereIn('event', match ($type) {
                'cash_in' => ['deposit.confirmed'],
                'transfer' => ['transfer.sent'],
                default => ['redeem_pix.submitted', 'redeem_pix.confirmed', 'external_pix_payment.confirmed'],
            }))
            ->when($filters['from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date));
        $query->when($filters['transaction_id'] ?? null, fn ($query, $id) => $query->where('id', $id))
            ->when($filters['amount_min'] ?? null, fn ($query, $value) => $query->whereRaw('(credit - debit) >= ?', [$value]))
            ->when($filters['amount_max'] ?? null, fn ($query, $value) => $query->whereRaw('(credit - debit) <= ?', [$value]));
        $member = trim($filters['member'] ?? '');
        if ($member !== '') {
            $digits = preg_replace('/\D/', '', $member);
            $query->whereHas('customer', function ($customer) use ($member, $digits) {
                $customer->where(function ($customer) use ($member, $digits) {
                    $customer->where('name', 'like', '%'.$member.'%')
                        ->orWhere('whatsapp_profile_name', 'like', '%'.$member.'%')
                        ->orWhere('whatsapp_username', 'like', '%'.$member.'%');
                    if ($digits !== '') {
                        $customer->orWhere('phone', 'like', '%'.$digits.'%');
                    }
                });
            });
        }
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $id = ltrim($search, '#0');
                $digits = preg_replace('/\D/', '', $search);
                $query->whereHas('customer', function ($customer) use ($search, $digits) {
                    $customer->where('name', 'like', '%'.$search.'%')
                        ->orWhere('whatsapp_profile_name', 'like', '%'.$search.'%')
                        ->orWhere('whatsapp_username', 'like', '%'.$search.'%');
                    if ($digits !== '') {
                        $customer->orWhere('phone', 'like', '%'.$digits.'%');
                    }
                });
                if (ctype_digit($id) && strlen($id) <= 18) {
                    $query->orWhere('id', $id);
                }
            });
        }
        $breakdown = $this->movementBreakdown($query);
        $direction = $filters['direction'] ?? 'desc';
        $sort = $filters['sort'] ?? 'created_at';
        match ($sort) {
            'id' => $query->orderBy('id', $direction),
            'customer' => $query->orderByRaw('(SELECT COALESCE(NULLIF(c.whatsapp_profile_name, \'\'), NULLIF(c.whatsapp_username, \'\'), NULLIF(c.name, \'\'), c.phone) FROM customers c WHERE c.id = movements.customer_id) '.$direction),
            'type' => $query->orderByRaw("(CASE WHEN event = 'deposit.confirmed' THEN 1 WHEN event = 'transfer.sent' THEN 3 ELSE 2 END) ".$direction),
            'amount' => $query->orderByRaw('(credit - debit) '.$direction),
            default => $query->orderBy('created_at', $direction),
        };
        if ($sort !== 'id') {
            $query->orderBy('id', $direction);
        }
        $page = $query->paginate($filters['per_page'] ?? 25, ['*'], 'page', $filters['page'] ?? 1);
        $page->through(function (Movement $movement) {
            $payload = $this->movementPayload($movement);
            // Private content stays behind the existing statement reveal control.
            if ($payload['privacy'] === 'private') {
                $payload['description'] = 'Transferência privada';
            }
            return $payload;
        });

        return response()->json([
            ...$page->toArray(),
            'breakdown' => $breakdown,
            'transfer_audit' => $this->transferAudit(),
        ]);
    }

    private function movementPayload(Movement $movement): array
    {
        $direction = (float) $movement->credit > 0 ? 'in' : 'out';
        $transactionType = match ($movement->event) {
            'deposit.confirmed' => 'cash_in',
            'transfer.sent' => 'transfer',
            default => 'cash_out',
        };

        return [
            'id' => $movement->id,
            'event' => $movement->event,
            'description' => $this->movementDescription($movement),
            'amount' => (float) ($movement->credit > 0 ? $movement->credit : -$movement->debit),
            'direction' => $direction,
            'transaction_type' => $transactionType,
            'privacy' => $transactionType === 'transfer'
                ? (($movement->metadata['anonymous'] ?? false) ? 'private' : 'public')
                : null,
            'balance' => (float) $movement->balance,
            'currency' => $movement->currency,
            'created_at' => $movement->created_at,
            'customer' => $movement->customer ? [
                'id' => $movement->customer->id,
                'name' => $movement->customer->whatsappDisplayName(),
                'phone' => $this->displayPhone($movement->customer),
                'timezone' => $movement->customer->timezone,
            ] : null,
        ];
    }

    private function displayPhone(Customer $customer): string
    {
        $stored = preg_replace('/\D+/', '', (string) $customer->phone) ?: '';
        $whatsappId = preg_replace('/\D+/', '', (string) $customer->whatsapp_id) ?: '';

        return $whatsappId !== '' && ! str_starts_with($stored, '55')
            ? $whatsappId
            : $stored;
    }

    private function movementDescription(Movement $movement): string
    {
        if (! empty($movement->metadata['description'])) {
            return $movement->metadata['description'];
        }

        if ($movement->event === 'deposit.confirmed') {
            $method = strtolower((string) ($movement->metadata['payment_method'] ?? $movement->metadata['billing_type'] ?? ''));
            $label = match ($method) {
                'boleto' => 'Boleto',
                'credit_card', 'credit card', 'creditcard' => 'Cartão de crédito',
                'debit_card', 'debit card', 'debitcard' => 'Cartão de débito',
                'pix' => 'PIX',
                default => 'origem não identificada',
            };

            return '💰 Cash-In · Carga via '.$label;
        }

        return str_replace(['.', '_'], ' ', $movement->event);
    }
}
