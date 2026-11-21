<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerInvite extends Model
{
    protected $fillable = [
        'inviter_customer_id',
        'recipient_customer_id',
        'invite_code',
        'individual_code',
        'status',
        'sent_to_phone',
        'sent_to_name',
        'received_by_phone',
        'received_by_name',
        'sent_at',
        'received_at',
        'accepted_at',
        'expires_at',
        'revoked_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'accepted_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'inviter_customer_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'recipient_customer_id');
    }
}
