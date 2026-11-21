<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallmentPayment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'due_at' => 'datetime', 'processed_at' => 'datetime'];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(InstallmentProposal::class, 'installment_proposal_id');
    }
}
