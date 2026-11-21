<?php

namespace App\Services\QrCodes;

use App\Models\Customer;
use App\Models\PaymentQrCode;
use App\Services\Phone\PhoneNumberFormatter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/** Creates opaque, expiring payloads that can safely be printed as QR codes. */
class PaymentQrCodeService
{
    public function __construct(private readonly PhoneNumberFormatter $phoneFormatter) {}

    public function create(Customer $recipient, string $type, ?float $amount = null): array
    {
        if (! in_array($type, ['transfer_open', 'transfer_fixed', 'cash_in'], true)) {
            throw new \InvalidArgumentException('Unsupported QR code type.');
        }

        if (($type === 'transfer_fixed' || $type === 'cash_in') && ($amount === null || $amount <= 0 || $amount > 150000)) {
            throw new \InvalidArgumentException('A valid amount is required for this QR code.');
        }

        $payload = [
            'v' => 1,
            'type' => $type,
            'recipient_customer_id' => $recipient->id,
            'amount' => $amount === null ? null : round($amount, 2),
            'expires_at' => now()->addYear()->timestamp,
            'nonce' => (string) Str::uuid(),
        ];

        $token = rtrim(strtr(base64_encode(Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR))), '+/', '-_'), '=');
        $reference = $this->createReference($token, $payload['expires_at']);

        return [
            'token' => $token,
            'reference' => $reference,
            'qr_payload' => $this->whatsappUrl($token, $reference, $recipient, $type, $payload['amount']),
            'type' => $type,
            'amount' => $payload['amount'],
            'expires_at' => now()->addYear()->toIso8601String(),
        ];
    }

    /**
     * A scan must enter the MPMM WhatsApp conversation, rather than expose an
     * API response in the scanner's browser. WhatsApp requires the user to
     * explicitly send the prefilled message; the webhook resolves this token.
     */
    public function whatsappUrl(
        string $token,
        ?string $reference = null,
        ?Customer $recipient = null,
        ?string $type = null,
        ?float $amount = null,
    ): string
    {
        $number = preg_replace('/\D+/', '', (string) config('services.whatsapp.public_number', ''));

        if ($number === '') {
            throw new \RuntimeException('The public WhatsApp number is not configured.');
        }

        if ($reference === null || $recipient === null || $type === null) {
            $paymentQr = PaymentQrCode::where('token', $token)->first();
            $payload = $this->resolve($token);
            $reference = $paymentQr?->reference;
            $recipient = $payload['recipient'];
            $type = $payload['type'];
            $amount = $payload['amount'];
        }

        // QR codes generated before the short reference was introduced remain
        // usable until they expire.
        if ($reference === null) {
            return 'https://wa.me/'.$number.'?text='.rawurlencode('mpmm:qr:'.$token);
        }

        return 'https://wa.me/'.$number.'?text='.rawurlencode(
            $this->paymentMessage($reference, $recipient, $type, $amount),
        );
    }

    public function resolve(string $token): array
    {
        try {
            $encrypted = base64_decode(strtr($token, '-_', '+/'), true);
            $payload = json_decode(Crypt::decryptString((string) $encrypted), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Invalid QR code.');
        }

        if (($payload['v'] ?? null) !== 1 || ($payload['expires_at'] ?? 0) < now()->timestamp) {
            throw new \InvalidArgumentException('Expired QR code.');
        }

        $recipient = Customer::find($payload['recipient_customer_id'] ?? null);
        if (! $recipient || $recipient->status !== 'active') {
            throw new \InvalidArgumentException('QR recipient is unavailable.');
        }

        return [...$payload, 'recipient' => $recipient];
    }

    public function resolveReference(string $reference): array
    {
        $paymentQr = PaymentQrCode::where('reference', Str::upper($reference))->first();

        if (! $paymentQr) {
            throw new \InvalidArgumentException('Invalid QR code.');
        }

        return $this->resolve($paymentQr->token);
    }

    private function createReference(string $token, int $expiresAt): string
    {
        do {
            $reference = Str::upper(Str::random(10));
        } while (PaymentQrCode::where('reference', $reference)->exists());

        PaymentQrCode::create([
            'reference' => $reference,
            'token' => $token,
            'expires_at' => now()->setTimestamp($expiresAt),
        ]);

        return $reference;
    }

    private function paymentMessage(
        string $reference,
        Customer $recipient,
        string $type,
        ?float $amount,
    ): string {
        $formattedAmount = $amount === null ? null : 'R$ '.number_format($amount, 2, ',', '.');

        if ($type !== 'cash_in') {
            $whatsappName = trim((string) $recipient->whatsapp_profile_name);

            if ($whatsappName === '') {
                $whatsappName = trim((string) $recipient->whatsapp_username);
            }

            if ($type === 'transfer_fixed') {
                $recipientDetails = ($whatsappName !== '' ? "{$whatsappName}\n" : '')
                    .$this->phoneFormatter->international($recipient->phone);

                return "Quero *transferir* para\n{$recipientDetails}\n*R$* "
                    .number_format($amount, 2, ',', '.')
                    ."\nRefª {$reference}";
            }

            $recipientDetails = $this->phoneFormatter->international($recipient->phone)
                .($whatsappName !== '' ? "\n{$whatsappName}" : '');
            return "Quero transferir um valor para\n{$recipientDetails}\n\nRefª MPMM {$reference}";
        }

        $name = $recipient->whatsappDisplayName() ?: 'este membro';
        $message = "Quero pagar uma recarga PIX de {$formattedAmount} para {$name}.";

        return "{$message} Ref. MPMM {$reference}";
    }
}
