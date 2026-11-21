<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceEndpointTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'maintenance-test-token-with-32-characters';

    protected function setUp(): void
    {
        parent::setUp();
        config(['mpmm.maintenance.token' => $this->token]);
    }

    public function test_it_rejects_missing_or_invalid_tokens(): void
    {
        $this->postJson('/api/maintenance/run', ['action' => 'database_check'])->assertForbidden();
        $this->withToken('wrong-token')
            ->postJson('/api/maintenance/run', ['action' => 'database_check'])
            ->assertForbidden();
    }

    public function test_it_only_accepts_allowlisted_actions(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/maintenance/run', ['action' => 'shell_command'])
            ->assertUnprocessable();
    }

    public function test_it_checks_the_database_without_exposing_credentials(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/maintenance/run', ['action' => 'database_check'])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'action' => 'database_check',
                'connection' => 'sqlite',
            ])
            ->assertJsonMissingPath('password');
    }
}
