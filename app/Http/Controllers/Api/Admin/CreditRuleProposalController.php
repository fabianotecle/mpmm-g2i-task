<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Credit\CreditRuleProposalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditRuleProposalController extends Controller
{
    public function __invoke(Request $request, Customer $customer, CreditRuleProposalService $proposals): JsonResponse
    {
        abort_unless($proposals->visibleTo((string) $request->user()?->phone), 403);

        return response()->json(['data' => $proposals->forCustomer($customer)]);
    }
}
