<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RotativeLot extends Model
{
    protected $fillable = ['credit_grant_id', 'current_customer_id', 'credit_debt_id', 'amount', 'remaining_amount', 'received_at', 'eligible_after', 'status'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'remaining_amount' => 'decimal:2', 'received_at' => 'datetime', 'eligible_after' => 'datetime'];
    }
}
