<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappNotificationDelivery extends Model
{
    protected $fillable = [
        'transfer_id', 'notification_key', 'recipient_phone', 'notification', 'delivered_steps', 'status', 'attempts',
        'last_error', 'last_attempted_at', 'next_attempt_at', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'notification' => 'array',
            'delivered_steps' => 'array',
            'last_attempted_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
