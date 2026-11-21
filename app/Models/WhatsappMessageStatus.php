<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappMessageStatus extends Model
{
    protected $fillable = ['provider_message_id', 'status', 'sent_at', 'delivered_at', 'read_at', 'failed_at'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
