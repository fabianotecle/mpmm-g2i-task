<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BonusRule extends Model
{
    protected $fillable = [
        'name',
        'event',
        'active',
        'reward_type',
        'reward_value',
        'min_event_amount',
        'max_awards_per_customer',
        'starts_at',
        'ends_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'reward_value' => 'decimal:4',
            'min_event_amount' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
