<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentQrCode extends Model
{
    protected $fillable = [
        'reference',
        'token',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }
}
