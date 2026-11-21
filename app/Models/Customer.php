<?php

namespace App\Models;

use App\Services\Audit\CustomerAuditContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(fn () => app(CustomerAuditContext::class)->prepareModelWrite());
        static::deleting(fn () => app(CustomerAuditContext::class)->prepareModelWrite());
        static::created(fn (self $customer) => app(CustomerAuditContext::class)->recordModelEvent($customer, 'created'));
        static::updated(fn (self $customer) => app(CustomerAuditContext::class)->recordModelEvent($customer, 'updated'));
        static::deleted(fn (self $customer) => app(CustomerAuditContext::class)->recordModelEvent($customer, 'deleted'));
    }

    protected $fillable = [
        'phone',
        'whatsapp_id',
        'whatsapp_username',
        'whatsapp_profile_name',
        'invite_code',
        'timezone',
        'cpf',
        'name',
        'admin_notes',
        'birth_date',
        'status',
        'acquisition_origin',
        'balance',
        'rotative_balance',
        'redeemable_balance',
        'limit',
        'opt_in',
        'metadata',
        'legacy_last_access_at',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'balance' => 'decimal:2',
            'rotative_balance' => 'decimal:2',
            'bonus_credit_balance' => 'decimal:2',
            'redeemable_balance' => 'decimal:2',
            'limit' => 'decimal:2',
            'opt_in' => 'boolean',
            'metadata' => 'array',
            'legacy_last_access_at' => 'datetime',
        ];
    }

    public function conversationSessions(): HasMany
    {
        return $this->hasMany(ConversationSession::class);
    }

    public function accessSessions(): HasMany
    {
        return $this->conversationSessions()->whereHas('messages', fn ($messages) => $messages->where('direction', 'inbound'));
    }

    public function postLegacyAccessSessions(): HasMany
    {
        return $this->accessSessions()->whereDoesntHave('messages', fn ($messages) => $messages
            ->where('direction', 'inbound')
            ->whereRaw("COALESCE(CAST(JSON_EXTRACT(customers.metadata, '$.access_count') AS DECIMAL(20, 0)), 0) > 0 AND customers.legacy_last_access_at IS NOT NULL AND conversation_messages.created_at <= customers.legacy_last_access_at"));
    }

    public function whatsappNameObservations(): HasMany
    {
        return $this->hasMany(CustomerWhatsappNameObservation::class);
    }

    public function whatsappDisplayName(): ?string
    {
        $storedName = trim((string) $this->name);

        return $this->whatsappAccountName() ?? ($storedName !== '' ? $storedName : null);
    }

    public function whatsappAccountName(): ?string
    {
        foreach ([$this->whatsapp_profile_name, $this->whatsapp_username] as $name) {
            $name = trim((string) $name);

            if ($name !== '') {
                return $name;
            }
        }

        return null;
    }

    public function whatsappFirstName(): ?string
    {
        $name = $this->whatsappDisplayName();

        return $name ? strtok($name, ' ') : null;
    }

    public function financialOperations(): HasMany
    {
        return $this->movements();
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class);
    }

    public function financialMovements(): HasMany
    {
        return $this->movements()
            ->where(fn ($query) => $query->where('credit', '>', 0)->orWhere('debit', '>', 0))
            ->where('event', 'not like', '%.simulated')
            ->where('event', '<>', 'simulation.reversed');
    }

    public function legacyPaymentOperations(): HasMany
    {
        return $this->hasMany(LegacyPaymentOperation::class);
    }

    public function invitesSent(): HasMany
    {
        return $this->hasMany(CustomerInvite::class, 'inviter_customer_id');
    }

    public function invitesReceived(): HasMany
    {
        return $this->hasMany(CustomerInvite::class, 'recipient_customer_id');
    }
}
