<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditDebtPayment extends Model
{
    protected $fillable = ['credit_debt_id', 'customer_id', 'recorded_by_user_id', 'amount', 'note'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
