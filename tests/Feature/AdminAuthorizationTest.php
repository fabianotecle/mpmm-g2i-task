<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_manage_users(): void
    {
        $finance = User::factory()->create(['role' => 'finance']);
        Sanctum::actingAs($finance, ['admin']);

        $this->getJson('/api/admin/users')->assertForbidden();
        $this->postJson('/api/admin/users', [])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']), ['admin']);
        $this->getJson('/api/admin/users')->assertOk();
    }

    public function test_customer_writes_are_limited_to_admin_and_finance(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'tic']), ['admin']);
        $this->getJson('/api/admin/customers')->assertOk();
        $this->postJson('/api/admin/customers', [])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'finance']), ['admin']);
        $this->postJson('/api/admin/customers', [])->assertUnprocessable();
    }

    public function test_two_factor_can_only_be_disabled_by_the_owner_or_an_admin(): void
    {
        $owner = User::factory()->create(['role' => 'finance']);
        $other = User::factory()->create(['role' => 'tic']);
        Sanctum::actingAs($owner, ['admin']);

        $this->deleteJson("/api/admin/users/{$other->id}/two-factor")->assertForbidden();
        $this->deleteJson("/api/admin/users/{$owner->id}/two-factor")->assertOk();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']), ['admin']);
        $this->deleteJson("/api/admin/users/{$other->id}/two-factor")->assertOk();
    }
}
