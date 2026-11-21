<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsappClient
{
    public function downloadMedia(string $mediaId): string
    {
        $version = config('services.whatsapp.api_version', 'v23.0');
        $accessToken = (string) config('services.whatsapp.access_token');

        if ($mediaId === '' || $accessToken === '') {
            throw new \RuntimeException('Não foi possível autenticar o download da planilha.');
        }

        $metadata = Http::withToken($accessToken)
            ->withOptions(['verify' => filter_var(config('services.whatsapp.verify_ssl', true), FILTER_VALIDATE_BOOLEAN)])
            ->get("https://graph.facebook.com/{$version}/{$mediaId}")
            ->throw();
        $url = (string) $metadata->json('url');

        if ($url === '') {
            throw new \RuntimeException('O WhatsApp não retornou o endereço da planilha.');
        }

        return Http::withToken($accessToken)
            ->withOptions(['verify' => filter_var(config('services.whatsapp.verify_ssl', true), FILTER_VALIDATE_BOOLEAN)])
            ->get($url)
            ->throw()
            ->body();
    }

    public function sendDocument(string $to, string $path, string $filename, ?string $caption = null): array
    {
        $phoneNumberId = config('services.whatsapp.phone_number_id');
        $accessToken = config('services.whatsapp.access_token');

        if (! $phoneNumberId || ! $accessToken) {
            return [
                'channel' => 'whatsapp',
                'status' => 'skipped',
                'reason' => 'missing_credentials',
                'to' => $to,
            ];
        }

        if (! is_file($path) || ! is_readable($path)) {
            return [
                'channel' => 'whatsapp',
                'status' => 'failed',
                'reason' => 'document_not_readable',
                'to' => $to,
            ];
        }

        try {
            $upload = Http::withToken($accessToken)
                ->acceptJson()
                ->attach('file', fopen($path, 'rb'), $filename, ['Content-Type' => 'application/pdf'])
                ->post($this->mediaUrl($phoneNumberId), [
                    'messaging_product' => 'whatsapp',
                    'type' => 'application/pdf',
                ])
                ->throw();

            $mediaId = (string) $upload->json('id');
            if ($mediaId === '') {
                throw new \RuntimeException('WhatsApp media upload returned no media id.');
            }

            return $this->sendPayload($to, [
                'type' => 'document',
                'document' => array_filter([
                    'id' => $mediaId,
                    'filename' => $filename,
                    'caption' => $caption,
                ]),
            ]);
        } catch (Throwable $exception) {
            Log::warning('WhatsApp document delivery failed.', [
                'to' => $to,
                'filename' => $filename,
                'exception' => class_basename($exception),
                'message' => $exception->getMessage(),
            ]);

            return [
                'channel' => 'whatsapp',
                'status' => 'failed',
                'reason' => class_basename($exception),
                'message' => $exception->getMessage(),
                'to' => $to,
            ];
        }
    }

    public function sendImage(string $to, string $path, ?string $caption = null): array
    {
        $phoneNumberId = config('services.whatsapp.phone_number_id');
        $accessToken = config('services.whatsapp.access_token');

        if (! $phoneNumberId || ! $accessToken) {
            return [
                'channel' => 'whatsapp',
                'status' => 'skipped',
                'reason' => 'missing_credentials',
                'to' => $to,
            ];
        }

        if (! is_file($path) || ! is_readable($path)) {
            return [
                'channel' => 'whatsapp',
                'status' => 'failed',
                'reason' => 'image_not_readable',
                'to' => $to,
            ];
        }

        try {
            $upload = Http::withToken($accessToken)
                ->acceptJson()
                ->attach('file', fopen($path, 'rb'), basename($path), ['Content-Type' => 'image/png'])
                ->post($this->mediaUrl($phoneNumberId), [
                    'messaging_product' => 'whatsapp',
                    'type' => 'image/png',
                ])
                ->throw();

            $mediaId = (string) $upload->json('id');
            if ($mediaId === '') {
                throw new \RuntimeException('WhatsApp image upload returned no media id.');
            }

            return $this->sendPayload($to, [
                'type' => 'image',
                'image' => array_filter([
                    'id' => $mediaId,
                    'caption' => $caption,
                ]),
            ]);
        } catch (Throwable $exception) {
            Log::warning('WhatsApp image delivery failed.', [
                'to' => $to,
                'path' => $path,
                'exception' => class_basename($exception),
                'message' => $exception->getMessage(),
            ]);

            return [
                'channel' => 'whatsapp',
                'status' => 'failed',
                'reason' => class_basename($exception),
                'message' => $exception->getMessage(),
                'to' => $to,
            ];
        }
    }

    public function sendText(string $to, string $text): array
    {
        return $this->sendPayload($to, [
            'type' => 'text',
            'text' => [
                'body' => $text,
            ],
        ]);
    }

    public function sendPayload(string $to, array $payload): array
    {
        $phoneNumberId = config('services.whatsapp.phone_number_id');
        $accessToken = config('services.whatsapp.access_token');
        $requestPayload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            ...$payload,
        ];

        if (! $phoneNumberId || ! $accessToken) {
            return [
                'channel' => 'whatsapp',
                'status' => 'skipped',
                'reason' => 'missing_credentials',
                'to' => $to,
                'request' => $requestPayload,
            ];
        }

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->withOptions([
                    'verify' => filter_var(config('services.whatsapp.verify_ssl', true), FILTER_VALIDATE_BOOLEAN),
                ])
                ->post($this->messagesUrl($phoneNumberId), $requestPayload)
                ->throw();
        } catch (Throwable $exception) {
            $error = $exception instanceof \Illuminate\Http\Client\RequestException
                ? $exception->response->json('error')
                : null;

            return [
                'channel' => 'whatsapp',
                'status' => 'failed',
                'reason' => class_basename($exception),
                'message' => $exception->getMessage(),
                'error' => $error,
                'to' => $to,
                'request' => $requestPayload,
            ];
        }

        return [
            'channel' => 'whatsapp',
            'status' => 'sent',
            'accepted_at' => now()->toISOString(),
            'to' => $to,
            'request' => $requestPayload,
            'response' => $response->json(),
        ];
    }

    public function markMessageAsRead(string $messageId): array
    {
        $phoneNumberId = config('services.whatsapp.phone_number_id');
        $accessToken = config('services.whatsapp.access_token');
        $requestPayload = [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
        ];

        if (! $phoneNumberId || ! $accessToken) {
            return [
                'channel' => 'whatsapp',
                'status' => 'skipped',
                'reason' => 'missing_credentials',
                'message_id' => $messageId,
                'request' => $requestPayload,
            ];
        }

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->withOptions([
                    'verify' => filter_var(config('services.whatsapp.verify_ssl', true), FILTER_VALIDATE_BOOLEAN),
                ])
                ->post($this->messagesUrl($phoneNumberId), $requestPayload)
                ->throw();
        } catch (Throwable $exception) {
            return [
                'channel' => 'whatsapp',
                'status' => 'failed',
                'reason' => class_basename($exception),
                'message' => $exception->getMessage(),
                'message_id' => $messageId,
                'request' => $requestPayload,
            ];
        }

        return [
            'channel' => 'whatsapp',
            'status' => 'sent',
            'message_id' => $messageId,
            'request' => $requestPayload,
            'response' => $response->json(),
        ];
    }

    public function sendTemplate(string $to, string $name, string $languageCode, array $components = []): array
    {
        return $this->sendPayload($to, [
            'type' => 'template',
            'template' => array_filter([
                'name' => $name,
                'language' => [
                    'code' => $languageCode,
                ],
                'components' => $components ?: null,
            ]),
        ]);
    }

    private function messagesUrl(string $phoneNumberId): string
    {
        $version = config('services.whatsapp.api_version', 'v23.0');

        return "https://graph.facebook.com/{$version}/{$phoneNumberId}/messages";
    }

    private function mediaUrl(string $phoneNumberId): string
    {
        $version = config('services.whatsapp.api_version', 'v23.0');

        return "https://graph.facebook.com/{$version}/{$phoneNumberId}/media";
    }
}
