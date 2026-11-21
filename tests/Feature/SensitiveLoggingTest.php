<?php

namespace Tests\Feature;

use App\Services\WhatsApp\WhatsappClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SensitiveLoggingTest extends TestCase
{
    public function test_whatsapp_provider_error_does_not_log_phone_or_response_body(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('statement.pdf', '%PDF-test');
        config([
            'services.whatsapp.phone_number_id' => 'test-phone-id',
            'services.whatsapp.access_token' => 'test-access-token',
        ]);
        Http::fake(['*' => Http::response(['error' => ['message' => 'private-provider-response']], 500)]);
        Log::spy();

        app(WhatsappClient::class)->sendDocument(
            '5551999990000',
            Storage::disk('local')->path('statement.pdf'),
            'private-statement.pdf',
        );

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            $logged = json_encode($context);

            return $message === 'WhatsApp document delivery failed.'
                && ! str_contains($logged, '5551999990000')
                && ! str_contains($logged, 'private-provider-response')
                && ! str_contains($logged, 'private-statement.pdf');
        });
    }
}
