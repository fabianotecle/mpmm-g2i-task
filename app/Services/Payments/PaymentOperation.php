<?php

namespace App\Services\Payments;

class PaymentOperation
{
    public function __construct(
        public readonly string $provider,
        public readonly string $externalId,
        public readonly ?string $receiptUrl = null,
        public readonly array $metadata = [],
    ) {
    }
}
