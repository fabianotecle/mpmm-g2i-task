<?php

namespace Tests\Feature;

use Tests\TestCase;

class ConversationIngressSecurityTest extends TestCase
{
    public function test_direct_conversation_and_qr_start_require_a_private_token(): void
    {
        $this->withHeader('X-MPMM-Internal-Token', 'invalid');

        $this->postJson('/api/conversations/start', ['external_user_id' => '5551999990000'])->assertForbidden();
        $this->getJson('/api/conversations/1')->assertForbidden();
        $this->postJson('/api/conversations/1/messages', ['message' => 'Confirmar'])->assertForbidden();
        $this->postJson('/api/qr-codes/invalid/start', ['external_user_id' => '5551999990000'])->assertForbidden();
    }

    public function test_direct_conversation_fails_closed_without_a_configured_token(): void
    {
        config(['mpmm.conversation_api.token' => null]);

        $this->postJson('/api/conversations/start', ['external_user_id' => '5551999990000'])
            ->assertStatus(503);
    }

    public function test_whatsapp_webhook_rejects_unsigned_and_tampered_payloads(): void
    {
        $payload = ['entry' => []];

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertForbidden();
        $this->postJson('/api/webhooks/whatsapp', $payload, [
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', '{}', 'test-meta-app-secret'),
        ])->assertForbidden();
        $this->postJson('/api/webhooks/whatsapp', $payload, [
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', json_encode($payload), 'test-meta-app-secret'),
        ])->assertOk();
    }

    public function test_whatsapp_webhook_checks_the_configured_meta_phone_number_id(): void
    {
        config(['services.whatsapp.phone_number_id' => 'expected-id']);
        $payload = ['entry' => [['changes' => [[
            'value' => [
                'metadata' => ['phone_number_id' => 'other-id'],
                'messages' => [['id' => 'wamid.fake', 'from' => '5551999990000', 'type' => 'text', 'text' => ['body' => 'Oi']]],
            ],
        ]]]]];

        $this->postJson('/api/webhooks/whatsapp', $payload, [
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', json_encode($payload), 'test-meta-app-secret'),
        ])->assertForbidden();
    }

    public function test_whatsapp_webhook_fails_closed_without_app_secret(): void
    {
        config(['services.whatsapp.app_secret' => null]);

        $this->postJson('/api/webhooks/whatsapp', ['entry' => []])->assertStatus(503);
    }
}
