<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerWhatsappNameObservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'conversation_session_id',
        'external_message_id',
        'profile_name',
        'previous_profile_name',
        'name_changed',
        'observed_at',
    ];

    protected function casts(): array
    {
        return [
            'name_changed' => 'boolean',
            'observed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function conversationSession(): BelongsTo
    {
        return $this->belongsTo(ConversationSession::class);
    }
}
