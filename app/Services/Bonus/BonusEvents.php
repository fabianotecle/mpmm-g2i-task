<?php

namespace App\Services\Bonus;

class BonusEvents
{
    public const CUSTOMER_AUTONOMOUS_ENTRY = 'customer.autonomous_entry';
    public const CUSTOMER_INVITE_ACCEPTED = 'customer.invite_accepted';
    public const INVITE_SENT = 'invite.sent';
    public const INVITE_SENT_ACCEPTED = 'invite.sent_accepted';
    public const INVITE_ACCEPTED_DEPOSIT = 'invite.accepted_deposit';
    public const DEPOSIT_FIRST = 'deposit.first';
    public const DEPOSIT_ABOVE_AMOUNT = 'deposit.above_amount';
    public const TRANSFER_SENT = 'transfer.sent';
    public const TRANSFER_RECEIVED = 'transfer.received';
    public const TRANSFER_VOLUME = 'transfer.volume';
    public const TRANSFER_ACCUMULATED_VALUE = 'transfer.accumulated_value';
    public const CUSTOMER_DDD = 'customer.ddd';
    public const BALANCE_INTEREST = 'balance.interest';
}
