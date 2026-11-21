<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Movement;
use Database\Seeders\GeneralContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashOutRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_enforces_configured_minimum_again_at_confirmation(): void
    {
        $this->seed(GeneralContentSeeder::class);
        config(['mpmm.cash_out.minimum_amount' => 5]);
        $customer = Customer::create(['phone' => '5551999990906', 'balance' => 10, 'rotative_balance' => 30]);
        Movement::create([
            'customer_id' => $customer->id,
            'credit' => 10,
            'balance' => 10,
            'currency' => 'BRL',
            'event' => 'deposit.confirmed',
        ]);

        $session = $this->postJson('/api/conversations/start', [
            'external_user_id' => $customer->phone,
        ])->assertCreated()->json('session_id');
        $this->postJson("/api/conversations/{$session}/messages", ['message' => 'Resgate Pix'])
            ->assertOk()->assertJsonPath('state', 'redeem_pix.waiting_intro_action');
        $this->postJson("/api/conversations/{$session}/messages", ['message' => 'Continuar'])
            ->assertOk()->assertJsonPath('expected_input.min', 5);
        $this->postJson("/api/conversations/{$session}/messages", ['message' => '4,99'])
            ->assertOk()->assertJsonPath('state', 'redeem_pix.waiting_amount');
        $this->postJson("/api/conversations/{$session}/messages", ['message' => '5'])
            ->assertOk()->assertJsonPath('state', 'redeem_pix.waiting_confirmation');

        config(['mpmm.cash_out.minimum_amount' => 6]);
        $this->postJson("/api/conversations/{$session}/messages", ['message' => 'confirmar'])
            ->assertOk()->assertJsonPath('state', 'redeem_pix.waiting_intro_action');
        $this->assertSame('10.00', $customer->fresh()->balance);
        $this->assertDatabaseMissing('movements', ['event' => 'redeem_pix.simulated']);
    }
}
