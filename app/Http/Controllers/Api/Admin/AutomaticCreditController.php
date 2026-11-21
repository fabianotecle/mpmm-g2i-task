<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Movement;
use App\Services\Credit\AutomaticCreditCalculator;
use App\Services\Credit\AutomaticCreditWallet;
use App\Services\RotativeWallet\RotativeWalletAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class AutomaticCreditController extends Controller
{
    public function show(Customer $customer, AutomaticCreditCalculator $calculator): JsonResponse
    {
        return response()->json(['data' => $calculator->forCustomer($customer)]);
    }

    public function store(
        Request $request,
        Customer $customer,
        AutomaticCreditCalculator $calculator,
        AutomaticCreditWallet $wallet,
        RotativeWalletAccess $access,
    ): JsonResponse {
        abort_unless($access->canManage($request->user()), 403, 'Somente o gestor da carteira rotativa pode conceder crédito automático.');
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:'.AutomaticCreditCalculator::MAX_LIMIT],
        ]);

        try {
            $result = DB::transaction(function () use ($request, $customer, $calculator, $wallet, $data) {
                $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
                $preview = $calculator->forCustomer($customer);
                $wasEnabled = (bool) ($customer->metadata['limit_auto'] ?? false);

                $wallet->adjustTo($customer, $request->user(), (float) $data['amount'], [
                    'source' => 'admin_panel_automatic_credit',
                    'admin_user_id' => $request->user()->id,
                    'admin_name' => $request->user()->name,
                    'updated_by' => $request->user()->email,
                    'ideal_limit' => $preview['ideal_limit'],
                    'net_deposits' => $preview['net_deposits'],
                ]);
                $customer->refresh();

                if (! $wasEnabled) {
                    $customer->forceFill(['metadata' => [...($customer->metadata ?? []), 'limit_auto' => true]])->save();
                    Movement::create([
                        'customer_id' => $customer->id,
                        'debit' => 0,
                        'credit' => 0,
                        'balance' => $customer->balance,
                        'currency' => 'BRL',
                        'event' => 'credit_automatic.updated',
                        'metadata' => [
                            'previous_enabled' => false,
                            'new_enabled' => true,
                            'admin_user_id' => $request->user()->id,
                            'admin_name' => $request->user()->name,
                            'updated_by' => $request->user()->email,
                            'source' => 'admin_panel_automatic_credit',
                        ],
                    ]);
                }

                return [
                    ...$preview,
                    'current_limit' => round((float) $customer->limit, 2),
                    'current_rotative_balance' => round((float) $customer->rotative_balance, 2),
                    'automatic_credit_issued' => $wallet->issuedAmount($customer),
                    'automatic_credit_enabled' => true,
                ];
            });
        } catch (LogicException $exception) {
            throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
        }

        return response()->json(['data' => $result]);
    }
}
