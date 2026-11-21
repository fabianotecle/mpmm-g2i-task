<?php

namespace App\Services\Conversation;

use App\Services\Conversation\Contracts\ConversationFlow;
use App\Services\Conversation\Flows\AdminFlow;
use App\Services\Conversation\Flows\DepositFlow;
use App\Services\Conversation\Flows\InviteFlow;
use App\Services\Conversation\Flows\InstallmentFlow;
use App\Services\Conversation\Flows\MainMenuFlow;
use App\Services\Conversation\Flows\PaymentQrCodeFlow;
use App\Services\Conversation\Flows\RedeemPixFlow;
use App\Services\Conversation\Flows\RegisterCustomerFlow;
use App\Services\Conversation\Flows\StatementFlow;
use App\Services\Conversation\Flows\TransferFlow;
use InvalidArgumentException;

class FlowRegistry
{
    public function get(string $flow): ConversationFlow
    {
        return match ($flow) {
            'admin' => app(AdminFlow::class),
            'deposit' => app(DepositFlow::class),
            'invite' => app(InviteFlow::class),
            'installment' => app(InstallmentFlow::class),
            'main_menu' => app(MainMenuFlow::class),
            'payment_qr_code' => app(PaymentQrCodeFlow::class),
            'redeem_pix' => app(RedeemPixFlow::class),
            'register_customer' => app(RegisterCustomerFlow::class),
            'statement' => app(StatementFlow::class),
            'transfer' => app(TransferFlow::class),
            default => throw new InvalidArgumentException("Unknown conversation flow [{$flow}]."),
        };
    }
}
