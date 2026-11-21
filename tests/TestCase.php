<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'services.whatsapp.access_token' => null,
            'services.whatsapp.phone_number_id' => null,
            'services.whatsapp.app_secret' => 'test-meta-app-secret',
            'mpmm.conversation_api.token' => str_repeat('t', 64),
        ]);
        $this->withHeader('X-MPMM-Internal-Token', str_repeat('t', 64));
    }
}
