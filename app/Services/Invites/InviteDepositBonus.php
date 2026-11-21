<?php

namespace App\Services\Invites;

use App\Models\Customer;
use App\Models\CustomerInvite;
use App\Models\Movement;
use App\Services\Bonus\BonusAwardService;
use App\Services\Bonus\BonusEvents;

class InviteDepositBonus
{
    public function __construct(private readonly BonusAwardService $bonuses)
    {
    }

    /** @return list<Movement> */
    public function award(Movement $deposit): array
    {
        if (! in_array($deposit->event, ['deposit.simulated', 'deposit.confirmed'], true)) {
            return [];
        }

        $invite = CustomerInvite::where('recipient_customer_id', $deposit->customer_id)
            ->whereNotNull('accepted_at')
            ->whereNull('revoked_at')
            ->oldest('accepted_at')
            ->first();

        if (! $invite || $invite->inviter_customer_id === $deposit->customer_id) {
            return [];
        }

        $inviter = Customer::find($invite->inviter_customer_id);

        if (! $inviter) {
            return [];
        }

        return $this->bonuses->award(BonusEvents::INVITE_ACCEPTED_DEPOSIT, $inviter, [
            'source_type' => 'customer_invite',
            'source_id' => $invite->id,
            'amount' => (float) $deposit->credit,
            'origin_customer_id' => $deposit->customer_id,
            'conversation_session_id' => $deposit->conversation_session_id,
        ]);
    }

    public function firstDepositNotification(Movement $deposit): ?array
    {
        if (Movement::where('customer_id', $deposit->customer_id)
            ->whereIn('event', ['deposit.simulated', 'deposit.confirmed'])
            ->whereKeyNot($deposit->id)
            ->exists()) {
            return null;
        }

        $invite = CustomerInvite::where('recipient_customer_id', $deposit->customer_id)
            ->whereNotNull('accepted_at')
            ->whereNull('revoked_at')
            ->oldest('accepted_at')
            ->first();
        $inviter = $invite ? Customer::find($invite->inviter_customer_id) : null;

        if (! $inviter) {
            return null;
        }

        return [
            'to' => $inviter->phone,
            'name' => $deposit->customer?->whatsappDisplayName() ?: 'O membro convidado',
            'amount' => (float) $deposit->credit,
        ];
    }
}
