<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditDebt extends Model
{
    protected $fillable = ['credit_grant_id', 'debtor_customer_id', 'principal', 'outstanding_amount', 'status', 'settled_at'];

    protected function casts(): array
    {
        return ['principal' => 'decimal:2', 'outstanding_amount' => 'decimal:2', 'settled_at' => 'datetime'];
    }
}
