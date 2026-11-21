<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CreditDebt;
use App\Services\RotativeWallet\RotativeCreditService;
use App\Services\RotativeWallet\RotativeWalletAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RotativeCreditGrantController extends Controller
{
    public function index(Request $request, Customer $customer, RotativeWalletAccess $access): JsonResponse
    {
        abort_unless($access->canManage($request->user()), 403, 'Somente o gestor autorizado pode consultar crédito rotativo.');

        return response()->json(['data' => [
            'rotative_balance' => (float) $customer->rotative_balance,
            'total_balance' => round((float) $customer->balance + (float) $customer->rotative_balance, 2),
            'grants' => \App\Models\CreditGrant::where('customer_id', $customer->id)->latest()->get(),
            'lots' => \App\Models\RotativeLot::where('current_customer_id', $customer->id)->where('remaining_amount', '>', 0)->oldest('eligible_after')->get(),
            'debts' => \App\Models\CreditDebt::where('debtor_customer_id', $customer->id)->where('status', 'open')->get(),
        ]]);
    }

    public function store(Request $request, Customer $customer, RotativeWalletAccess $access, RotativeCreditService $credits): JsonResponse
    {
        abort_unless($access->canManage($request->user()), 403, 'Somente o gestor autorizado pode conceder crédito rotativo.');
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:150000'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $grant = $credits->grant($customer, $request->user(), (float) $data['amount'], $data['reason']);

        $customer = $customer->fresh();

        return response()->json(['data' => $grant->fresh(), 'customer' => [
            'id' => $customer->id,
            'balance' => (float) $customer->balance,
            'rotative_balance' => (float) $customer->rotative_balance,
            'total_balance' => round((float) $customer->balance + (float) $customer->rotative_balance, 2),
        ]], 201);
    }

    public function payDebt(Request $request, Customer $customer, CreditDebt $debt, RotativeWalletAccess $access, RotativeCreditService $credits): JsonResponse
    {
        abort_unless($access->canManage($request->user()), 403, 'Somente o gestor autorizado pode registrar pagamento de dívida.');
        abort_unless((int) $debt->debtor_customer_id === (int) $customer->id, 404);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:150000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $debt = $credits->payDebt($debt, $request->user(), (float) $data['amount'], $data['note'] ?? null);
        } catch (\LogicException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $debt]);
    }

    public function releaseEligible(Request $request, RotativeWalletAccess $access, RotativeCreditService $credits): JsonResponse
    {
        abort_unless($access->canManage($request->user()), 403, 'Somente o gestor autorizado pode liberar crédito rotativo.');

        return response()->json(['released_lots' => $credits->releaseEligibleLots()]);
    }
}
