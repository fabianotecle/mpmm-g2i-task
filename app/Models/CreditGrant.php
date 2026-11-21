<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditGrant extends Model
{
    protected $fillable = ['customer_id', 'granted_by_user_id', 'amount', 'available_amount', 'status', 'reason', 'rules'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'available_amount' => 'decimal:2', 'rules' => 'array'];
    }
}
