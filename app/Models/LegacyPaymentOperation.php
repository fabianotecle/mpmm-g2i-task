<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegacyPaymentOperation extends Model
{
    protected $fillable = [
        'customer_id',
        'kind',
        'legacy_id',
        'provider',
        'status',
        'amount',
        'fee',
        'nsu',
        'external_operation_id',
        'external_receipt_url',
        'generated_at',
        'paid_at',
        'registered_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fee' => 'decimal:2',
            'generated_at' => 'datetime',
            'paid_at' => 'datetime',
            'registered_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
