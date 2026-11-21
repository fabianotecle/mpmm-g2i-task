<?php

namespace App\Services\Payments\Contracts;

use App\Services\Payments\PaymentOperation;

interface PaymentProvider
{
    public function createPixDeposit(float $amount, array $metadata = []): PaymentOperation;

    public function createBoletoDeposit(float $amount, array $metadata = []): PaymentOperation;

    public function createCardDeposit(float $amount, array $metadata = []): PaymentOperation;

    public function sendPix(float $amount, string $pixKey, array $metadata = []): PaymentOperation;

    public function handleWebhook(string $payload, array $headers = []): array;

    public function createDeposit(float $amount, array $metadata = []): PaymentOperation;

    public function payPix(float $amount, string $pixKey, array $metadata = []): PaymentOperation;
}
