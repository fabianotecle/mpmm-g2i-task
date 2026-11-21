<?php

namespace App\Services\WhatsApp;

use App\Services\Phone\PhoneNumberNormalizer;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class WhatsappWebhookParser
{
    public function __construct(private readonly PhoneNumberNormalizer $phones) {}

    /**
     * @return array<int, WhatsappInboundMessage>
     */
    public function messages(array $payload): array
    {
        $messages = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = Arr::get($change, 'value', []);
                $contacts = Arr::get($value, 'contacts', []);

                foreach (Arr::get($value, 'messages', []) as $message) {
                    $text = $this->messageText($message);

                    if (! is_string($text) || trim($text) === '') {
                        continue;
                    }

                    $rawFrom = trim((string) ($message['from'] ?? ''));
                    $contact = $this->contactForMessage($contacts, $rawFrom);
                    $rawWhatsappId = $this->stringOrNull(Arr::get($contact, 'wa_id')) ?? $rawFrom;
                    $phone = $this->phoneFrom($rawWhatsappId) ?? $this->phoneFrom($rawFrom);
                    $whatsappId = $phone ?? $rawWhatsappId;

                    $messages[] = new WhatsappInboundMessage(
                        from: $phone ?? $rawFrom ?: $whatsappId,
                        id: (string) ($message['id'] ?? ''),
                        text: trim($text),
                        timestamp: isset($message['timestamp']) ? Carbon::createFromTimestamp((int) $message['timestamp']) : null,
                        raw: $message,
                        whatsappId: $whatsappId ?: null,
                        phone: $phone,
                        username: $this->usernameFrom($message, $contact, $rawFrom),
                        profileName: $this->stringOrNull(Arr::get($contact, 'profile.name')),
                        replyTo: $rawFrom ?: $rawWhatsappId,
                    );
                }
            }
        }

        return array_values(array_filter(
            $messages,
            fn (WhatsappInboundMessage $message) => $message->from !== '' && $message->id !== '',
        ));
    }

    private function messageText(array $message): ?string
    {
        if (($message['type'] ?? null) === 'text') {
            return Arr::get($message, 'text.body');
        }

        if (($message['type'] ?? null) === 'interactive') {
            return Arr::get($message, 'interactive.button_reply.id')
                ?? Arr::get($message, 'interactive.button_reply.title')
                ?? Arr::get($message, 'interactive.list_reply.id')
                ?? Arr::get($message, 'interactive.list_reply.title');
        }

        if (($message['type'] ?? null) === 'button') {
            return Arr::get($message, 'button.payload')
                ?? Arr::get($message, 'button.text');
        }

        if (($message['type'] ?? null) === 'contacts') {
            return $this->sharedContactPhone($message);
        }

        if (($message['type'] ?? null) === 'document') {
            return Arr::get($message, 'document.filename') ?? 'documento.xlsx';
        }

        return null;
    }

    private function sharedContactPhone(array $message): ?string
    {
        $reference = (string) ($message['from'] ?? '');
        $fallback = null;
        foreach (['wa_id', 'phone'] as $field) {
            foreach (Arr::get($message, 'contacts', []) as $contact) {
                if (! is_array($contact)) {
                    continue;
                }

                $candidates = [Arr::get($contact, $field)];
                foreach (Arr::get($contact, 'phones', []) as $phone) {
                    if (is_array($phone)) {
                        $candidates[] = Arr::get($phone, $field);
                    }
                }

                foreach ($candidates as $candidate) {
                    $candidate = $this->stringOrNull($candidate);
                    if (! $candidate) {
                        continue;
                    }
                    $fallback ??= $candidate;
                    if ($normalized = $this->phones->normalize($candidate, $reference)) {
                        return $normalized;
                    }
                }
            }
        }

        return $fallback;
    }

    private function contactForMessage(array $contacts, string $from): ?array
    {
        foreach ($contacts as $contact) {
            if (! is_array($contact)) {
                continue;
            }

            if ((string) Arr::get($contact, 'wa_id') === $from) {
                return $contact;
            }
        }

        return count($contacts) === 1 && is_array($contacts[0]) ? $contacts[0] : null;
    }

    private function phoneFrom(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return $this->phones->normalizeKnownNumber($value);
    }

    private function usernameFrom(array $message, ?array $contact, string $from): ?string
    {
        $username = $this->stringOrNull(Arr::get($message, 'username'))
            ?? $this->stringOrNull(Arr::get($message, 'user_name'))
            ?? $this->stringOrNull(Arr::get($message, 'handle'))
            ?? $this->stringOrNull(Arr::get($contact, 'username'))
            ?? $this->stringOrNull(Arr::get($contact, 'user_name'))
            ?? $this->stringOrNull(Arr::get($contact, 'handle'));

        if (! $username && $from !== '' && ! $this->phoneFrom($from)) {
            $username = $from;
        }

        return $username ? ltrim($username, '@') : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
