<?php

namespace App\Services\Invites;

use App\Models\Customer;
use App\Models\CustomerInvite;
use App\Services\Bonus\BonusAwardService;
use App\Services\Bonus\BonusEvents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class InviteTracker
{
    public function __construct(private readonly BonusAwardService $bonuses)
    {
    }

    public function recordLinkGenerated(Customer $inviter, string $inviteCode, array $metadata = []): CustomerInvite
    {
        return CustomerInvite::firstOrCreate(
            [
                'inviter_customer_id' => $inviter->id,
                'invite_code' => $inviteCode,
                'sent_to_phone' => null,
                'received_by_phone' => null,
                'recipient_customer_id' => null,
            ],
            [
                'metadata' => $metadata ?: null,
            ],
        );
    }

    public function recordLinkSent(Customer $inviter, string $inviteCode, array $recipient = [], array $metadata = []): CustomerInvite
    {
        $sentToPhone = $this->digits($recipient['phone'] ?? null);

        $invite = CustomerInvite::updateOrCreate(
            [
                'inviter_customer_id' => $inviter->id,
                'invite_code' => $inviteCode,
                'sent_to_phone' => $sentToPhone,
                'recipient_customer_id' => null,
            ],
            [
                'sent_to_name' => $this->stringOrNull($recipient['name'] ?? null),
                'sent_at' => Carbon::now(),
                'metadata' => $metadata ?: null,
            ],
        );

        $this->bonuses->award(BonusEvents::INVITE_SENT, $inviter, [
            'source_type' => 'customer_invite',
            'source_id' => $invite->id,
            'conversation_session_id' => $metadata['conversation_session_id'] ?? null,
        ]);

        return $invite;
    }

    public function createIndividual(Customer $inviter, string $phone, ?string $name = null, array $metadata = []): CustomerInvite
    {
        do {
            $code = Str::lower(Str::random(20));
        } while (CustomerInvite::where('individual_code', $code)->exists());

        $invite = CustomerInvite::create([
            'inviter_customer_id' => $inviter->id,
            'invite_code' => $inviter->invite_code,
            'individual_code' => $code,
            'status' => 'sent',
            'sent_to_phone' => $this->digits($phone),
            'sent_to_name' => $this->stringOrNull($name),
            'sent_at' => Carbon::now(),
            'expires_at' => null,
            'metadata' => $metadata ?: null,
        ]);

        $this->bonuses->award(BonusEvents::INVITE_SENT, $inviter, [
            'source_type' => 'customer_invite', 'source_id' => $invite->id,
            'conversation_session_id' => $metadata['conversation_session_id'] ?? null,
        ]);

        return $invite;
    }

    public function recordLinkReceived(string $inviteCode, string $receivedByPhone, array $metadata = []): ?CustomerInvite
    {
        $individual = CustomerInvite::where('individual_code', $inviteCode)->first();
        $inviter = $individual?->inviter ?? Customer::where('invite_code', $inviteCode)->first();

        if (! $inviter) {
            return null;
        }

        $receivedByPhone = $this->digits($receivedByPhone) ?: $receivedByPhone;
        if ($individual && ! $this->canBeUsedBy($individual, $receivedByPhone)) {
            return null;
        }

        $invite = $individual ?? $this->sentInviteForRecipient($inviter, $inviteCode, $receivedByPhone)
            ?? new CustomerInvite([
                'inviter_customer_id' => $inviter->id,
                'invite_code' => $inviteCode,
            ]);

        $invite->forceFill([
            'received_by_phone' => $receivedByPhone,
            'received_by_name' => $this->stringOrNull($metadata['name'] ?? $metadata['profile_name'] ?? null),
            'received_at' => $invite->received_at ?? Carbon::now(),
            'status' => 'received',
            'metadata' => array_filter([
                ...($invite->metadata ?? []),
                ...$metadata,
            ], fn ($value) => $value !== null && $value !== ''),
        ])->save();

        return $invite;
    }

    public function markAccepted(Customer $recipient, string $inviteCode, array $metadata = []): ?CustomerInvite
    {
        $individual = CustomerInvite::where('individual_code', $inviteCode)->first();
        $inviter = $individual?->inviter ?? Customer::where('invite_code', $inviteCode)->first();

        if (! $inviter || $inviter->is($recipient)) {
            return null;
        }

        if ($individual && ! $this->canBeUsedBy($individual, $recipient->phone)) {
            return null;
        }

        $invite = $individual ?? CustomerInvite::where('invite_code', $inviteCode)
            ->where('inviter_customer_id', $inviter->id)
            ->where(function ($query) use ($recipient) {
                $query->where('recipient_customer_id', $recipient->id)
                    ->orWhere('received_by_phone', $recipient->phone)
                    ->orWhere('sent_to_phone', $recipient->phone);
            })
            ->latest()
            ->first()
            ?? new CustomerInvite([
                'inviter_customer_id' => $inviter->id,
                'invite_code' => $inviteCode,
            ]);

        $alreadyAccepted = $invite->exists && $invite->accepted_at !== null;

        $invite->forceFill([
            'recipient_customer_id' => $recipient->id,
            'received_by_phone' => $invite->received_by_phone ?: $recipient->phone,
            'received_by_name' => $invite->received_by_name ?: $recipient->name,
            'received_at' => $invite->received_at ?? Carbon::now(),
            'accepted_at' => $invite->accepted_at ?? Carbon::now(),
            'status' => 'accepted',
            'metadata' => array_filter([
                ...($invite->metadata ?? []),
                ...$metadata,
                'accepted' => true,
            ], fn ($value) => $value !== null && $value !== ''),
        ])->save();

        $recipient->forceFill(['acquisition_origin' => 'invite'])->save();

        if (! $alreadyAccepted) {
            $this->bonuses->award(BonusEvents::CUSTOMER_INVITE_ACCEPTED, $recipient, [
                'source_type' => 'customer_invite',
                'source_id' => $invite->id,
                'origin_customer_id' => $inviter->id,
                'conversation_session_id' => $metadata['conversation_session_id'] ?? null,
            ]);

            $this->bonuses->award(BonusEvents::INVITE_SENT_ACCEPTED, $inviter, [
                'source_type' => 'customer_invite',
                'source_id' => $invite->id,
                'origin_customer_id' => $recipient->id,
                'conversation_session_id' => $metadata['conversation_session_id'] ?? null,
            ]);
        }

        $invite->setAttribute('acceptance_was_new', ! $alreadyAccepted);

        return $invite;
    }

    public function codeExists(string $code): bool
    {
        return CustomerInvite::where('individual_code', $code)->exists()
            || Customer::where('invite_code', $code)->exists();
    }

    private function canBeUsedBy(CustomerInvite $invite, string $phone): bool
    {
        return $invite->revoked_at === null
            && ($invite->accepted_at === null || $invite->recipient_customer_id !== null)
            && (! $invite->sent_to_phone || $invite->sent_to_phone === $this->digits($phone));
    }

    private function sentInviteForRecipient(Customer $inviter, string $inviteCode, string $phone): ?CustomerInvite
    {
        return CustomerInvite::where('inviter_customer_id', $inviter->id)
            ->where('invite_code', $inviteCode)
            ->where(function ($query) use ($phone) {
                $query->where('sent_to_phone', $phone)
                    ->orWhereNull('sent_to_phone');
            })
            ->whereNull('recipient_customer_id')
            ->latest()
            ->first();
    }

    private function digits(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $value) ?: '';

        return $digits !== '' ? $digits : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
