<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappOrderedMenu extends Model
{
    protected $fillable = [
        'primary_message_id', 'recipient_phone', 'secondary_payload',
        'conversation_message_id', 'delivery_index', 'status', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'secondary_payload' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
