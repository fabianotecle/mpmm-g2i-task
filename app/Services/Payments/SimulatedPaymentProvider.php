<?php

namespace App\Services\Payments;

use App\Services\Payments\Contracts\PaymentProvider;
use Illuminate\Support\Str;

class SimulatedPaymentProvider implements PaymentProvider
{
    public function createPixDeposit(float $amount, array $metadata = []): PaymentOperation
    {
        return $this->operation('sim-pix-deposit', $amount, [
            'status' => 'received',
            'payment_method' => 'pix',
            'copy_paste' => '000201-mpmm-simulado-'.Str::uuid()->toString(),
            ...$metadata,
        ]);
    }

    public function createBoletoDeposit(float $amount, array $metadata = []): PaymentOperation
    {
        return $this->operation('sim-boleto-deposit', $amount, [
            'status' => 'received',
            'payment_method' => 'boleto',
            'boleto_line' => '46191110000000000004254201037014315740000'.str_pad((string) (int) ($amount * 100), 8, '0', STR_PAD_LEFT),
            ...$metadata,
        ]);
    }

    public function createCardDeposit(float $amount, array $metadata = []): PaymentOperation
    {
        $cardType = ($metadata['card_type'] ?? null) === 'debit'
            || ($metadata['payment_method'] ?? null) === 'debit_card'
            ? 'debit'
            : 'credit';

        return $this->operation('sim-card-deposit', $amount, [
            'status' => 'received',
            'payment_method' => $cardType.'_card',
            'card_type' => $cardType,
            'payment_url' => 'https://example.test/simulated-card/'.Str::uuid()->toString(),
            ...$metadata,
        ]);
    }

    public function sendPix(float $amount, string $pixKey, array $metadata = []): PaymentOperation
    {
        return new PaymentOperation(
            provider: 'simulated',
            externalId: 'sim-send-pix-'.Str::uuid()->toString(),
            metadata: [
                'amount' => $amount,
                'pix_key' => $pixKey,
                ...$metadata,
            ],
        );
    }

    public function handleWebhook(string $payload, array $headers = []): array
    {
        return [
            'provider' => 'simulated',
            'handled' => false,
            'payload' => $payload,
            'headers' => $headers,
        ];
    }

    public function createDeposit(float $amount, array $metadata = []): PaymentOperation
    {
        return match ($metadata['payment_method'] ?? null) {
            'boleto' => $this->createBoletoDeposit($amount, $metadata),
            'card', 'cartao', 'credit_card', 'debit_card' => $this->createCardDeposit($amount, $metadata),
            default => $this->createPixDeposit($amount, $metadata),
        };
    }

    public function payPix(float $amount, string $pixKey, array $metadata = []): PaymentOperation
    {
        return $this->sendPix($amount, $pixKey, $metadata);
    }

    private function operation(string $prefix, float $amount, array $metadata = []): PaymentOperation
    {
        return new PaymentOperation(
            provider: 'simulated',
            externalId: $prefix.'-'.Str::uuid()->toString(),
            metadata: [
                'amount' => $amount,
                ...$metadata,
            ],
        );
    }
}
