<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_session_id',
        'direction',
        'type',
        'payload',
        'external_channel',
        'external_message_id',
        'external_timestamp',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'external_timestamp' => 'datetime',
            'display_order' => 'integer',
        ];
    }

    public function conversationSession(): BelongsTo
    {
        return $this->belongsTo(ConversationSession::class);
    }
}
