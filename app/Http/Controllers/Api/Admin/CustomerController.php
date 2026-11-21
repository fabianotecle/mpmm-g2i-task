<?php

namespace App\Http\Controllers\Api\Admin;

use App\Services\MemberStatistics;
use App\Services\CashOut\CashOutAvailability;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ConversationMessage;
use App\Models\Movement;
use App\Models\WhatsappMessageStatus;
use App\Services\Credit\CreditLimitManager;
use App\Services\Phone\PhoneNumberNormalizer;
use App\Services\Time\PhoneTimezoneResolver;
use App\Services\RotativeWallet\RotativeWalletAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', Rule::in(['name', 'phone', 'cpf', 'origin', 'created_at', 'accesses', 'last_access_at', 'timezone', 'last_movement_at', 'balance', 'limit', 'status'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'cpf' => ['nullable', 'string', 'max:20'],
            'origin' => ['nullable', Rule::in(['direct', 'invite', 'network', 'other'])],
            'timezone' => ['nullable', 'string', 'max:64'],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date'],
            'balance_min' => ['nullable', 'numeric'],
            'balance_max' => ['nullable', 'numeric'],
            'limit_min' => ['nullable', 'numeric', 'min:0'],
            'limit_max' => ['nullable', 'numeric', 'min:0'],
            'automatic' => ['nullable', Rule::in(['0', '1'])],
            'situation' => ['nullable', Rule::in(['with_balance', 'positive', 'negative', 'no_access', 'zero', 'no_movements'])],
            'accesses_min' => ['nullable', 'integer', 'min:0'],
            'last_access_from' => ['nullable', 'date'],
            'movement_from' => ['nullable', 'date'],
            'activity' => ['nullable', Rule::in(['high', 'medium', 'low'])],
        ]);

        $sortColumns = [
            'name' => 'name',
            'cpf' => 'cpf',
            'origin' => 'acquisition_origin',
            'created_at' => 'created_at',
            'accesses' => 'access_sessions_count',
            'last_access_at' => 'access_sessions_max_last_interaction_at',
            'balance' => 'balance',
            'status' => 'status',
            'phone' => 'phone',
            'timezone' => 'timezone',
            'last_movement_at' => 'movements_max_created_at',
            'limit' => 'limit',
        ];
        $sort = $validated['sort'] ?? 'created_at';
        $direction = $validated['direction'] ?? 'desc';
        $displayName = "COALESCE(NULLIF(TRIM(whatsapp_profile_name), ''), NULLIF(TRIM(whatsapp_username), ''), NULLIF(TRIM(name), ''))";
        $movementPercentiles = $this->movementPercentiles();

        $query = Customer::query()
            ->withCount(['movements', 'financialMovements', 'postLegacyAccessSessions'])
            ->withMax('movements', 'created_at')
            ->withMax('accessSessions', 'last_interaction_at')
            ->withExists('invitesReceived')
            ->when($validated['search'] ?? null, function ($query, $search) {
                $digits = preg_replace('/\D+/', '', $search);
                $query->where(function ($inner) use ($search, $digits) {
                    $inner
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('whatsapp_profile_name', 'like', "%{$search}%")
                        ->orWhere('whatsapp_username', 'like', "%{$search}%");

                    if ($digits !== '') {
                        $inner
                            ->orWhere('cpf', 'like', "%{$digits}%")
                            ->orWhere('phone', 'like', "%{$digits}%");
                        $inner->orWhere('whatsapp_id', 'like', "%{$digits}%");
                    }
                });
            })
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['name'] ?? null, fn ($query, $name) => $query->whereRaw("{$displayName} LIKE ?", ["%{$name}%"]))
            ->when($validated['phone'] ?? null, fn ($query, $phone) => $query->where('phone', 'like', '%'.preg_replace('/\D+/', '', $phone).'%'))
            ->when($validated['cpf'] ?? null, fn ($query, $cpf) => $query->where('cpf', 'like', '%'.preg_replace('/\D+/', '', $cpf).'%'))
            ->when($validated['origin'] ?? null, fn ($query, $origin) => $query->where('acquisition_origin', $origin))
            ->when($validated['timezone'] ?? null, fn ($query, $timezone) => $query->where('timezone', 'like', "%{$timezone}%"))
            ->when($validated['created_from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($validated['created_to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->when(isset($validated['balance_min']), fn ($query) => $query->where('balance', '>=', $validated['balance_min']))
            ->when(isset($validated['balance_max']), fn ($query) => $query->where('balance', '<=', $validated['balance_max']))
            ->when(isset($validated['limit_min']), fn ($query) => $query->where('limit', '>=', $validated['limit_min']))
            ->when(isset($validated['limit_max']), fn ($query) => $query->where('limit', '<=', $validated['limit_max']))
            ->when(isset($validated['automatic']), function ($query) use ($validated) {
                $enabled = $validated['automatic'] === '1';
                $query->where(function ($automatic) use ($enabled) {
                    $automatic->where('metadata->limit_auto', $enabled);

                    if (! $enabled) {
                        // Missing or null settings are OFF, as in customerPayload().
                        $automatic->orWhereNull('metadata->limit_auto');
                    }
                });
            })
            ->when($validated['situation'] ?? null, fn ($query, $situation) => $query->whereRaw(
                '('.MemberStatistics::SITUATION_SQL.') = ?', [$situation === 'with_balance' ? 'positive' : $situation]
            ))
            ->when(isset($validated['accesses_min']), fn ($query) => $query->whereRaw('('.MemberStatistics::ACCESS_COUNT_SQL.') >= ?', [(int) $validated['accesses_min']]))
            ->when($validated['last_access_from'] ?? null, fn ($query, $date) => $query->whereHas('accessSessions', fn ($sessions) => $sessions->whereDate('last_interaction_at', '>=', $date)))
            ->when($validated['movement_from'] ?? null, fn ($query, $date) => $query->whereHas('movements', fn ($movements) => $movements->whereDate('created_at', '>=', $date)))
            ->when($validated['activity'] ?? null, function ($query, $activity) use ($movementPercentiles) {
                $bottom = $movementPercentiles['bottom_40'] ?? 0;
                $top = $movementPercentiles['top_10'] ?? PHP_INT_MAX;
                if ($activity === 'high') {
                    $query->where('balance', '>', 0)->has('movements', '>=', $top)
                        ->whereHas('movements', fn ($movements) => $movements->where('created_at', '>=', now()->subDays(60)));
                } elseif ($activity === 'low') {
                    $query->where(fn ($low) => $low->has('movements', '=', 0)->orWhere('balance', 0)->orHas('movements', '<=', $bottom));
                } else {
                    $query->has('movements', '>', $bottom)->where('balance', '!=', 0)
                        ->where(fn ($medium) => $medium->where('balance', '<=', 0)
                            ->orHas('movements', '<', $top)
                            ->orWhereDoesntHave('movements', fn ($movements) => $movements->where('created_at', '>=', now()->subDays(60))));
                }
            })
            ->when(
                $sort === 'name',
                fn ($query) => $query
                    ->orderByRaw("CASE WHEN {$displayName} IS NULL THEN 0 ELSE 1 END {$direction}")
                    ->orderByRaw("{$displayName} {$direction}"),
                fn ($query) => $sort === 'accesses'
                    ? $query->orderByRaw('('.MemberStatistics::ACCESS_COUNT_SQL.") {$direction}")
                    : $query->orderBy($sortColumns[$sort], $direction),
            )
            ->orderByDesc('id');
        $statistics = app(\App\Services\MemberStatistics::class);
        $filtered = $statistics->summarize($query);
        $all = $statistics->summarize(Customer::query());
        $paginator = $query->paginate($validated['per_page'] ?? 25)
            ->through(fn (Customer $customer) => $this->customerPayload($customer, $movementPercentiles));

        return response()->json([
            ...$paginator->toArray(),
            'member_statistics' => ['all' => $all, 'filtered' => $filtered],
        ]);
    }

    public function store(
        Request $request,
        PhoneNumberNormalizer $phones,
        PhoneTimezoneResolver $timezones,
        CreditLimitManager $creditLimits,
    ): JsonResponse
    {
        $data = $this->validatedCustomer($request);
        $data['phone'] = $phones->normalizeKnownNumber($data['phone']) ?? preg_replace('/\D+/', '', $data['phone']);
        $manualTimezone = ! empty($data['timezone']);
        $data['timezone'] ??= $timezones->resolve($data['phone']);
        $automaticCredit = (bool) ($data['automatic_credit_enabled'] ?? false);
        unset($data['automatic_credit_enabled']);
        $data['metadata'] = [...($data['metadata'] ?? []), 'limit_auto' => $automaticCredit, 'timezone_source' => $manualTimezone ? 'manual' : 'phone'];
        if (Customer::query()->where('phone', $data['phone'])->exists()) {
            throw ValidationException::withMessages(['phone' => 'Este telefone já está cadastrado.']);
        }

        $limit = (float) ($data['limit'] ?? 0);
        $data['limit'] = 0;
        $customer = Customer::create($data);

        if ($limit > 0) {
            $creditLimits->set($customer, $limit, [
                ...$this->creditAuditMetadata($request),
            ]);
        }

        return response()->json(['data' => $this->customerPayload($customer->refresh())], 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        $customer->loadCount(['movements', 'financialMovements', 'postLegacyAccessSessions'])
            ->loadMax('movements', 'created_at')
            ->loadMax('accessSessions', 'last_interaction_at');

        return response()->json(['data' => $this->customerPayload($customer)]);
    }

    public function nameHistory(Customer $customer): JsonResponse
    {
        $first = $customer->whatsappNameObservations()
            ->orderBy('observed_at')->orderBy('id')->first();
        $changes = $customer->whatsappNameObservations()
            ->where('name_changed', true)
            ->orderByDesc('observed_at')->orderByDesc('id')
            ->get(['id', 'previous_profile_name', 'profile_name', 'observed_at']);

        return response()->json([
            'current_name' => $customer->whatsapp_profile_name,
            'initial_name' => $first?->previous_profile_name ?: $first?->profile_name,
            'initial_observed_at' => $first?->previous_profile_name ? null : $first?->observed_at,
            'changes' => $changes->map(fn ($change) => [
                'id' => $change->id,
                'previous_name' => $change->previous_profile_name,
                'new_name' => $change->profile_name,
                'changed_at' => $change->observed_at,
            ]),
        ]);
    }

    public function update(
        Request $request,
        Customer $customer,
        PhoneNumberNormalizer $phones,
        PhoneTimezoneResolver $timezones,
        CreditLimitManager $creditLimits,
    ): JsonResponse
    {
        $data = $this->validatedCustomer($request, $customer);
        $timezoneSource = ! empty($data['timezone']) ? 'manual' : null;
        if (isset($data['phone'])) {
            $data['phone'] = $phones->normalizeKnownNumber($data['phone']) ?? preg_replace('/\D+/', '', $data['phone']);
            if ($data['phone'] !== $customer->phone && ! $timezoneSource) {
                $data['timezone'] = $timezones->resolve($data['phone']);
                $timezoneSource = 'phone';
            }
            if (Customer::query()->where('phone', $data['phone'])->whereKeyNot($customer->id)->exists()) {
                throw ValidationException::withMessages(['phone' => 'Este telefone já está cadastrado.']);
            }
        }
        if (array_key_exists('timezone', $data) && ! $data['timezone']) {
            $data['timezone'] = $timezones->resolve($data['phone'] ?? $customer->phone);
            $timezoneSource = 'phone';
        }
        $previousAutomaticCredit = (bool) ($customer->metadata['limit_auto'] ?? false);
        $automaticCredit = null;
        if (array_key_exists('automatic_credit_enabled', $data)) {
            $automaticCredit = (bool) $data['automatic_credit_enabled'];
            unset($data['automatic_credit_enabled']);
        }
        if ($timezoneSource !== null || $automaticCredit !== null) {
            $data['metadata'] = [
                ...($customer->metadata ?? []),
                ...($timezoneSource !== null ? ['timezone_source' => $timezoneSource] : []),
                ...($automaticCredit !== null ? ['limit_auto' => $automaticCredit] : []),
            ];
        }
        $limit = array_key_exists('limit', $data) ? (float) $data['limit'] : null;
        unset($data['limit']);

        $customer->update($data);

        if ($limit !== null) {
            $creditLimits->set($customer, $limit, [
                ...$this->creditAuditMetadata($request),
            ]);
        }

        if ($automaticCredit !== null && $automaticCredit !== $previousAutomaticCredit) {
            Movement::create([
                'customer_id' => $customer->id,
                'debit' => 0,
                'credit' => 0,
                'balance' => $customer->balance,
                'currency' => 'BRL',
                'event' => 'credit_automatic.updated',
                'metadata' => [
                    'previous_enabled' => $previousAutomaticCredit,
                    'new_enabled' => $automaticCredit,
                    ...$this->creditAuditMetadata($request),
                ],
            ]);
        }

        return response()->json(['data' => $this->customerPayload($customer->refresh())]);
    }

    /** Restricted pilot control: only the configured o gestor autorizado account may change it. */
    public function updateRotativeWalletAccess(Request $request, Customer $customer, RotativeWalletAccess $access): JsonResponse
    {
        abort_unless($access->canManage($request->user()), 403, 'Somente o responsÃ¡vel pela carteira rotativa pode alterar esta permissÃ£o.');
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $metadata = $customer->metadata ?? [];
        $metadata['rotative_wallet_enabled'] = (bool) $data['enabled'];
        $metadata['rotative_wallet_access_updated_at'] = now()->toIso8601String();
        $metadata['rotative_wallet_access_updated_by'] = $request->user()->id;
        $customer->forceFill(['metadata' => $metadata])->save();

        return response()->json(['data' => $this->customerPayload($customer->refresh())]);
    }

    public function movements(Request $request, Customer $customer): JsonResponse
    {
        $movementId = $request->input('movement_id');
        if (is_string($movementId) && preg_match('/^0+[1-9][0-9]*$/D', $movementId)) {
            $request->merge(['movement_id' => ltrim($movementId, '0')]);
        }
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'movement_id' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in(['timestamp', 'nsu', 'currency', 'amount', 'balance'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'search' => ['nullable', 'string', 'max:150'],
        ]);
        $sort = $validated['sort'] ?? 'timestamp';
        $direction = $validated['direction'] ?? 'desc';
        $query = $customer->movements()
            ->with('originCustomer:id,name,phone,whatsapp_profile_name,whatsapp_username')
            ->when($validated['movement_id'] ?? null, fn ($query, $movementId) => $query->whereKey($movementId));
        $search = trim($validated['search'] ?? '');
        if ($search !== '' && ! $this->movementSearchMatchesCustomer($search, $customer)) {
            $this->filterMovements($query, $search);
        }

        match ($sort) {
            'nsu' => $query->orderBy('id', $direction),
            'currency' => $query->orderBy('currency', $direction)->orderBy('id', $direction),
            'amount' => $query->orderByRaw('(COALESCE(credit, 0) + COALESCE(rotative_credit, 0) - COALESCE(debit, 0) - COALESCE(rotative_debit, 0)) '.$direction)
                ->orderBy('id', $direction),
            'balance' => $query->orderByRaw('(COALESCE(balance, 0) + COALESCE(rotative_balance, 0)) '.$direction)
                ->orderBy('id', $direction),
            default => $query->orderBy('created_at', $direction)->orderBy('id', $direction),
        };

        $paginator = $query
            ->paginate($validated['per_page'] ?? 25)
            ->through(fn (Movement $movement) => $this->movementPayload($movement, $customer));

        return response()->json($paginator);
    }

    private function movementSearchMatchesCustomer(string $search, Customer $customer): bool
    {
        $term = mb_strtolower($search);
        $digits = preg_replace('/\D+/', '', $search) ?: '';

        return str_contains(mb_strtolower((string) $customer->whatsappDisplayName()), $term)
            || (strlen($digits) >= 4 && str_contains((string) $customer->phone, $digits));
    }

    private function filterMovements($query, string $search): void
    {
        $pattern = '%'.$search.'%';
        $digits = preg_replace('/\D+/', '', $search) ?: '';
        $phonePattern = strlen($digits) >= 4 ? '%'.$digits.'%' : null;
        $matchingCustomers = fn () => Customer::query()->select('phone')->where(function ($customers) use ($pattern, $phonePattern) {
            $customers->where('name', 'like', $pattern)
                ->orWhere('whatsapp_profile_name', 'like', $pattern)
                ->orWhere('whatsapp_username', 'like', $pattern);
            if ($phonePattern) {
                $customers->orWhere('phone', 'like', $phonePattern);
            }
        });

        $query->where(function ($movements) use ($pattern, $phonePattern, $matchingCustomers) {
            $movements->where('event', 'like', $pattern)
                ->orWhere('currency', 'like', $pattern)
                ->orWhere('external_provider', 'like', $pattern)
                ->orWhere('external_operation_id', 'like', $pattern)
                ->orWhere('metadata', 'like', $pattern)
                ->orWhere('created_at', 'like', $pattern)
                ->orWhereRaw('CAST(id AS CHAR) LIKE ?', [$pattern])
                ->orWhereRaw('CAST((COALESCE(credit, 0) + COALESCE(rotative_credit, 0) - COALESCE(debit, 0) - COALESCE(rotative_debit, 0)) AS CHAR) LIKE ?', [$pattern])
                ->orWhereRaw('CAST((COALESCE(balance, 0) + COALESCE(rotative_balance, 0)) AS CHAR) LIKE ?', [$pattern])
                ->orWhereHas('originCustomer', function ($customers) use ($pattern, $phonePattern) {
                    $customers->where('name', 'like', $pattern)
                        ->orWhere('whatsapp_profile_name', 'like', $pattern)
                        ->orWhere('whatsapp_username', 'like', $pattern);
                    if ($phonePattern) {
                        $customers->orWhere('phone', 'like', $phonePattern);
                    }
                })
                ->orWhereIn('metadata->recipient', $matchingCustomers());

            if ($phonePattern) {
                $movements->orWhere('metadata', 'like', $phonePattern);
            }
        });
    }

    private function movementPayload(Movement $movement, Customer $customer): array
    {
        [$from, $to] = $this->movementParties($movement, $customer);

        return [
                'id' => $movement->id,
                'nsu' => str_pad((string) $movement->id, 8, '0', STR_PAD_LEFT),
                'event' => $movement->event,
                'description' => $this->movementDescription($movement),
                'debit' => (float) $movement->debit,
                'credit' => (float) $movement->credit,
                'amount' => (float) $movement->credit + (float) $movement->rotative_credit
                    - (float) $movement->debit - (float) $movement->rotative_debit,
                'balance' => (float) $movement->balance + (float) $movement->rotative_balance,
                'currency' => $movement->currency,
                'created_at' => $movement->created_at,
                'origin_customer' => $movement->originCustomer,
                'metadata' => $movement->metadata,
                'from' => $from,
                'to' => $to,
                'private' => (bool) ($movement->metadata['anonymous'] ?? false),
            ];
    }

    private function movementParties(Movement $movement, Customer $customer): array
    {
        $member = $this->partyPayload($customer);

        if ($movement->event === 'transfer.received') {
            $sender = $movement->originCustomer ?? $this->legacyMovementCustomer($movement, 'origin');

            return [$this->partyPayload($sender, null, $movement->metadata['sender_whatsapp_name'] ?? null), $member];
        }

        if ($movement->event === 'transfer.sent') {
            $recipient = $this->legacyMovementCustomer($movement, 'destination');
            $recipientPhone = $movement->metadata['recipient'] ?? null;
            if (! $recipientPhone && preg_match('/\+?\d[\d\s().-]{8,}/', (string) ($movement->metadata['description'] ?? ''), $match)) {
                $recipientPhone = preg_replace('/\D+/', '', $match[0]);
            }
            if (! $recipient && $recipientPhone) {
                $digits = preg_replace('/\D+/', '', $recipientPhone);
                $recipient = Customer::query()->where('phone', $digits)->first();
                if (! $recipient) {
                    $normalized = app(PhoneNumberNormalizer::class)->normalize($recipientPhone, $customer->phone);
                    $recipient = $normalized ? Customer::query()->where('phone', $normalized)->first() : null;
                }
            }

            return [$member, $this->partyPayload($recipient, $recipientPhone, $movement->metadata['recipient_whatsapp_name'] ?? null)];
        }

        if (str_starts_with($movement->event, 'deposit.')) {
            return [[
                'id' => null,
                'name' => ucfirst((string) ($movement->external_provider ?: 'Origem externa')),
                'phone' => null,
            ], $member];
        }

        if (in_array($movement->event, ['redeem_pix.submitted', 'redeem_pix.confirmed', 'redeem_pix.simulated', 'external_pix_payment.confirmed', 'external_pix_payment.simulated'], true)) {
            return [$member, [
                'id' => null,
                'name' => 'Destino externo · PIX',
                'phone' => $movement->metadata['pix_key'] ?? null,
            ]];
        }

        return [$movement->credit > 0 ? null : $member, $movement->credit > 0 ? $member : null];
    }

    private function legacyMovementCustomer(Movement $movement, string $side): ?Customer
    {
        $legacyId = $movement->metadata[$side] ?? null;
        if (! $legacyId || ! isset($movement->metadata['legacy_transaction_id'])) {
            return null;
        }

        // Legacy account identifiers belong to a separate namespace from current IDs.
        return Customer::query()->where('metadata->legacy_customer_id', (string) $legacyId)->first();
    }

    private function partyPayload(?Customer $customer, ?string $phone = null, ?string $name = null): ?array
    {
        if (! $customer && ! $phone && ! $name) {
            return null;
        }

        return [
            'id' => $customer?->id,
            'name' => $customer?->whatsappDisplayName() ?: ($name ?: ($customer ? 'Membro #'.$customer->id : 'Membro sem cadastro localizado')),
            'phone' => $customer ? $this->displayPhone($customer) : $phone,
        ];
    }

    public function statistics(Customer $customer, CashOutAvailability $cashOutAvailability): JsonResponse
    {
        $movements = $customer->movements()
            ->where(fn ($query) => $query->where('credit', '>', 0)->orWhere('debit', '>', 0))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'event', 'credit', 'debit', 'balance', 'created_at']);

        $cashIn = round((float) $movements->where('event', 'deposit.confirmed')->sum('credit'), 2);
        $cashOutEvents = ['redeem_pix.submitted', 'redeem_pix.confirmed', 'external_pix_payment.confirmed'];
        $cashOutMovements = $movements->whereIn('event', $cashOutEvents)->where('debit', '>', 0);
        $cashOut = round((float) $cashOutMovements->sum('debit'), 2);
        $transfersReceived = $movements->where('event', 'transfer.received')->where('credit', '>', 0);
        $transfersSent = $movements->where('event', 'transfer.sent')->where('debit', '>', 0);
        $credits = round((float) $movements->sum('credit'), 2);
        $debits = round((float) $movements->sum('debit'), 2);
        $averageBalance = $this->timeWeightedAverageBalance($customer, $movements);
        $accessDates = array_filter([
            $customer->accessSessions()->max('last_interaction_at'),
            $customer->metadata['last_legacy_access_at'] ?? null,
        ]);
        $lastAccess = $accessDates
            ? collect($accessDates)->map(fn ($date) => Carbon::parse($date))->sortDesc()->first()
            : null;
        $creditApproved = (float) $customer->limit;
        $creditUsed = max(0, -(float) $customer->balance);
        $cashOutRules = $cashOutAvailability->summary($customer);

        return response()->json(['data' => [
            'currency' => 'BRL',
            'joined_at' => $customer->created_at,
            'last_access_at' => $lastAccess,
            'days_since_last_access' => $lastAccess ? (int) floor(abs(now()->diffInDays($lastAccess))) : null,
            'balance' => (float) $customer->balance,
            'cash_in' => $cashIn,
            'cash_in_transaction_count' => $movements->where('event', 'deposit.confirmed')->where('credit', '>', 0)->count(),
            'cash_out' => $cashOut,
            'cash_out_transaction_count' => $cashOutMovements->count(),
            'net_deposit' => round($cashIn - $cashOut, 2),
            'average_balance' => $averageBalance,
            'credit_approved' => $creditApproved,
            'credit_used' => $creditUsed,
            'credit_used_percentage' => $creditApproved > 0 ? round(($creditUsed / $creditApproved) * 100, 2) : 0,
            'cash_out_rules' => [
                'minimum_amount' => $cashOutRules['minimum_amount'],
                'available' => $cashOutRules['available'],
                'debit_free_of_credit_debt' => $cashOutRules['wallet_capacity'],
                'credit_debt_outstanding' => $cashOutRules['credit_debt_outstanding'],
                'converted_credit' => $cashOutRules['converted_credit'],
            ],
            'transaction_count' => $movements->count(),
            'transaction_volume' => round($credits + $debits, 2),
            'credits' => $credits,
            'debits' => $debits,
            'external_transaction_count' => $movements->whereIn('event', ['deposit.confirmed', ...$cashOutEvents])->count(),
            'internal_transaction_count' => $transfersReceived->count() + $transfersSent->count(),
            'transfers_received' => $transfersReceived->count(),
            'transfers_sent' => $transfersSent->count(),
            'received_sent_ratio' => $transfersSent->count() > 0
                ? round($transfersReceived->count() / $transfersSent->count(), 4)
                : null,
        ]]);
    }

    private function timeWeightedAverageBalance(Customer $customer, $movements): float
    {
        $start = $customer->created_at;
        $end = now();
        if (! $start || $start->greaterThanOrEqualTo($end)) {
            return (float) $customer->balance;
        }

        $cursor = $start->copy();
        $balance = 0.0;
        $weightedBalanceSeconds = 0.0;

        foreach ($movements as $movement) {
            $occurredAt = $movement->created_at;
            if ($occurredAt->lessThanOrEqualTo($start)) {
                $balance = (float) $movement->balance;
                continue;
            }

            $weightedBalanceSeconds += $balance * $cursor->diffInSeconds($occurredAt);
            $cursor = $occurredAt;
            $balance = (float) $movement->balance;
        }

        $weightedBalanceSeconds += $balance * $cursor->diffInSeconds($end);
        $totalSeconds = $start->diffInSeconds($end);

        return $totalSeconds > 0 ? round($weightedBalanceSeconds / $totalSeconds, 2) : (float) $customer->balance;
    }

    public function messages(Request $request, Customer $customer): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'before_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $limit = $validated['limit'] ?? 30;
        $beforeOrder = null;
        $beforeTime = null;
        if (isset($validated['before_id'])) {
            $cursor = ConversationMessage::query()
                ->whereKey($validated['before_id'])
                ->whereHas('conversationSession', fn ($query) => $query->where('customer_id', $customer->id))
                ->firstOrFail();
            $beforeOrder = $cursor->display_order ?? $cursor->id;
            $beforeTime = ($cursor->external_timestamp ?? $cursor->created_at)->format('Y-m-d H:i:s');
        }

        $messages = ConversationMessage::query()
            ->whereHas('conversationSession', fn ($query) => $query->where('customer_id', $customer->id))
            ->when($beforeTime, fn ($query) => $query->whereRaw(
                '(COALESCE(external_timestamp, created_at) < ? OR '
                .'(COALESCE(external_timestamp, created_at) = ? AND COALESCE(display_order, id) < ?))',
                [$beforeTime, $beforeTime, $beforeOrder],
            ))
            ->orderByRaw('COALESCE(external_timestamp, created_at) DESC')
            ->orderByRaw('COALESCE(display_order, id) DESC')
            ->limit($limit + 1)
            ->get();

        $hasMore = $messages->count() > $limit;
        $page = $messages->take($limit)->reverse()->values();
        $nextBeforeId = $hasMore ? $page->first()?->id : null;
        $providerIds = $page->flatMap(function (ConversationMessage $message) {
            $payload = $message->payload ?? [];

            return [
                ...collect($payload['_deliveries'] ?? [])->pluck('provider_message_id')->all(),
                $payload['provider_message_id'] ?? $message->external_message_id,
            ];
        })->filter()->unique()->values();
        $statuses = WhatsappMessageStatus::query()
            ->whereIn('provider_message_id', $providerIds)
            ->get()->keyBy('provider_message_id');
        $messages = $page
            ->flatMap(fn (ConversationMessage $message) => $this->conversationMessagePayloads($message, $statuses))
            ->values();

        return response()->json([
            'data' => $messages,
            'meta' => [
                'has_more' => $hasMore,
                'next_before_id' => $nextBeforeId,
            ],
        ]);
    }

    private function conversationMessagePayloads(ConversationMessage $message, $statuses): array
    {
        $payloads = $message->direction === 'outbound' && ! empty($message->payload['messages'])
            ? $message->payload['messages']
            : [$this->inboundDisplayPayload($message->payload ?? [])];
        $deliveries = $message->payload['_deliveries'] ?? null;

        return collect($payloads)->values()
            ->filter(fn (array $payload, int $index) => ! is_array($deliveries)
                || ($deliveries[$index]['status'] ?? null) === 'sent')
            ->map(function (array $payload, int $index) use ($message, $deliveries, $statuses): array {
                $delivery = $deliveries[$index] ?? null;
                $providerId = $delivery['provider_message_id']
                    ?? $payload['provider_message_id']
                    ?? ($message->external_channel === 'whatsapp' ? $message->external_message_id : null);
                $status = $providerId ? $statuses->get($providerId) : null;

                return [
                    'id' => $message->id,
                    'key' => $message->id.':'.$index,
                    'session_id' => $message->conversation_session_id,
                    'direction' => $message->direction,
                    'type' => $payload['type'] ?? $message->type,
                    'payload' => $payload,
                    'created_at' => $delivery['accepted_at'] ?? $message->external_timestamp ?? $message->created_at,
                    'delivery_status' => $status?->status
                        ?? ($delivery ? 'accepted' : ($payload['delivery_status'] ?? 'unverified')),
                    'delivered_at' => $status?->delivered_at,
                    'history_source' => $payload['history_source'] ?? null,
                    'time_estimated' => $payload['time_estimated'] ?? false,
                    'sequence' => $index,
                ];
            })->values()->all();
    }

    private function inboundDisplayPayload(array $payload): array
    {
        $raw = $payload['metadata']['raw'] ?? [];
        $visibleText = $raw['interactive']['button_reply']['title']
            ?? $raw['interactive']['list_reply']['title']
            ?? $payload['text']
            ?? null;

        return [...$payload, 'text' => $visibleText];
    }

    private function validatedCustomer(Request $request, ?Customer $customer = null): array
    {
        $required = $customer ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'nullable', 'string', 'max:255'],
            'admin_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'phone' => [$required, 'string', 'max:30'],
            'cpf' => ['sometimes', 'nullable', 'string', 'max:14', Rule::unique('customers')->ignore($customer)],
            'birth_date' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'acquisition_origin' => ['sometimes', Rule::in(['direct', 'invite', 'network', 'other'])],
            'timezone' => ['sometimes', 'nullable', 'timezone'],
            'limit' => ['sometimes', 'numeric', 'min:0'],
            'automatic_credit_enabled' => ['sometimes', 'boolean'],
        ]);
    }

    private function customerPayload(Customer $customer, array $movementPercentiles = []): array
    {
        if (! array_key_exists('financial_movements_count', $customer->getAttributes())
            || ! array_key_exists('post_legacy_access_sessions_count', $customer->getAttributes())) {
            $customer->loadCount(['movements', 'financialMovements', 'postLegacyAccessSessions'])
                ->loadMax('movements', 'created_at')
                ->loadMax('accessSessions', 'last_interaction_at');
        }

        $movementCount = (int) ($customer->movements_count ?? 0);
        $balance = (float) $customer->balance;
        $legacyAccesses = max(0, (int) ($customer->metadata['access_count'] ?? 0));
        $legacyLastAccess = $customer->metadata['last_legacy_access_at'] ?? null;
        $legacyLastAccess = is_string($legacyLastAccess) && trim($legacyLastAccess) !== '' ? $legacyLastAccess : null;
        $accesses = (int) ($customer->post_legacy_access_sessions_count ?? 0) + $legacyAccesses;
        $hasAccess = $accesses > 0 || $legacyLastAccess !== null;
        $accessDates = array_filter([$customer->access_sessions_max_last_interaction_at ?? null, $legacyLastAccess]);
        $lastAccess = $accessDates
            ? collect($accessDates)->map(fn ($date) => Carbon::parse($date))->sortDesc()->first()->toIso8601String()
            : null;
        $memberSituation = MemberStatistics::situation(
            $balance,
            (int) ($customer->financial_movements_count ?? 0),
            $hasAccess,
        );
        $recentMovement = $customer->movements_max_created_at
            && now()->subDays(60)->lte($customer->movements_max_created_at);
        $activity = match (true) {
            $balance > 0
                && $movementCount >= ($movementPercentiles['top_10'] ?? PHP_INT_MAX)
                && $recentMovement => 'high',
            $movementCount === 0,
            $balance == 0.0,
            $movementCount <= ($movementPercentiles['bottom_40'] ?? -1) => 'low',
            default => 'medium',
        };

        return [
            'id' => $customer->id,
            'name' => $customer->whatsappDisplayName(),
            'admin_notes' => $customer->admin_notes,
            'phone' => $this->displayPhone($customer),
            'cpf' => $customer->cpf,
            'status' => $customer->status,
            'balance' => $balance,
            'rotative_balance' => (float) $customer->rotative_balance,
            'total_balance' => round($balance + (float) $customer->rotative_balance, 2),
            'redeemable_balance' => (float) $customer->redeemable_balance,
            'limit' => (float) $customer->limit,
            'automatic_credit_enabled' => (bool) ($customer->metadata['limit_auto'] ?? false),
            'rotative_wallet_enabled' => app(RotativeWalletAccess::class)->enabledFor($customer),
            'timezone' => $customer->timezone ?: 'UTC',
            'currency' => 'BRL',
            'origin' => $customer->acquisition_origin,
            'accesses' => $accesses,
            'movements_count' => $movementCount,
            'member_situation' => $memberSituation,
            'activity' => $activity,
            'last_movement_at' => $customer->movements_max_created_at,
            'balance_updated_at' => $customer->movements_max_created_at,
            'last_access_at' => $lastAccess,
            'created_at' => $customer->created_at,
            'updated_at' => $customer->updated_at,
        ];
    }

    private function displayPhone(Customer $customer): string
    {
        $stored = preg_replace('/\D+/', '', (string) $customer->phone) ?: '';
        $whatsappId = preg_replace('/\D+/', '', (string) $customer->whatsapp_id) ?: '';

        // WhatsApp's identifier is authoritative when a legacy record retained
        // a Brazilian local number without its country code.
        if ($whatsappId !== '' && ! str_starts_with($stored, '55')) {
            return $whatsappId;
        }

        return $stored;
    }

    private function movementPercentiles(): array
    {
        $counts = Customer::query()
            ->where('status', 'active')
            ->withCount('movements')
            ->get()
            ->pluck('movements_count')
            ->sort()
            ->values();

        if ($counts->isEmpty()) {
            return ['bottom_40' => 0, 'top_10' => PHP_INT_MAX];
        }

        return [
            'bottom_40' => (int) $counts[(int) ceil($counts->count() * 0.4) - 1],
            'top_10' => (int) $counts[(int) ceil($counts->count() * 0.9) - 1],
        ];
    }

    private function movementDescription(Movement $movement): string
    {
        if (! empty($movement->metadata['description'])) {
            return $movement->metadata['description'];
        }

        if (str_starts_with($movement->event, 'deposit.')) {
            $method = $movement->metadata['payment_method'] ?? $movement->metadata['billing_type'] ?? null;
            $label = match (strtolower((string) $method)) {
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

    private function creditAuditMetadata(Request $request): array
    {
        return [
            'updated_by' => $request->user()?->email,
            'admin_user_id' => $request->user()?->id,
            'admin_name' => $request->user()?->name,
            'source' => 'admin_panel',
            'changed_at' => now()->toIso8601String(),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
        ];
    }
}
