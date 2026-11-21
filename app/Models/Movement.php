<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Movement extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'origin_customer_id',
        'conversation_session_id',
        'debit',
        'credit',
        'balance',
        'fund_type',
        'bonus_credit_debit',
        'bonus_credit_credit',
        'rotative_debit',
        'rotative_credit',
        'rotative_balance',
        'currency',
        'event',
        'external_provider',
        'external_operation_id',
        'external_receipt_url',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'balance' => 'decimal:2',
            'rotative_debit' => 'decimal:2',
            'rotative_credit' => 'decimal:2',
            'rotative_balance' => 'decimal:2',
            'bonus_credit_debit' => 'decimal:2',
            'bonus_credit_credit' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function originCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'origin_customer_id');
    }

    public function conversationSession(): BelongsTo
    {
        return $this->belongsTo(ConversationSession::class);
    }
}
