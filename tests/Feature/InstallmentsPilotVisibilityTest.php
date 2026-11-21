<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ConversationSession;
use App\Models\InstallmentPayment;
use App\Models\InstallmentProposal;
use App\Models\Movement;
use App\Models\WhatsappNotificationDelivery;
use App\Services\Conversation\ConversationEngine;
use App\Services\Installments\InstallmentService;
use App\Services\WhatsApp\WhatsappWebhookParser;
use App\Services\WhatsApp\WhatsappResponseRenderer;
use Database\Seeders\GeneralContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class InstallmentsPilotVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GeneralContentSeeder::class);
        Carbon::setTestNow('2026-10-05 12:00:00 UTC');
        config([
            'mpmm.admin_ids' => '5551999999999',
            'mpmm.installments.interval_days' => 30,
            'mpmm.installments.schedule_type' => 'daily',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_parcelado_is_only_in_transfer_confirmation_between_recipient_and_private_options(): void
    {
        $sender = Customer::create(['phone' => '5551999998101', 'balance' => 20]);
        $recipient = Customer::create(['phone' => '5551999998102']);
        $sessionId = $this->postJson('/api/conversations/start', [
            'external_user_id' => $sender->phone,
        ])->assertCreated()->assertJsonMissing(['value' => 'parcelado'])->json('session_id');

        $this->postJson("/api/conversations/{$sessionId}/messages", ['message' => 'transferir'])
            ->assertOk()->assertJsonPath('state', 'transfer.waiting_recipient');
        $this->postJson("/api/conversations/{$sessionId}/messages", ['message' => $recipient->phone])
            ->assertOk()->assertJsonPath('state', 'transfer.waiting_amount');
        $confirmation = $this->postJson("/api/conversations/{$sessionId}/messages", ['message' => '10'])
            ->assertOk()->assertJsonPath('state', 'transfer.waiting_confirmation')
            ->assertJsonPath('messages.0.list_button', 'Escolher opção')
            ->assertJsonCount(6, 'messages.0.options')
            ->assertJsonPath('messages.0.options.2.value', 'alterar_destinatario')
            ->assertJsonPath('messages.0.options.3.label', '🗓 Parcelado')
            ->assertJsonPath('messages.0.options.3.value', 'parcelar')
            ->assertJsonPath('messages.0.options.4.value', 'anonima')
            ->json();
        $rendered = app(WhatsappResponseRenderer::class)->render($confirmation);
        $rows = $rendered[0]['interactive']['action']['sections'][0]['rows'];
        $this->assertSame('list', $rendered[0]['interactive']['type']);
        $this->assertSame('🗓 Parcelado', $rows[3]['title']);

        $this->postJson("/api/conversations/{$sessionId}/messages", ['message' => 'parcelar'])
            ->assertOk()->assertJsonPath('state', 'installment.waiting_installment_count')
            ->assertJsonPath('context.installment_total_cents', 1000)
            ->assertJsonPath('context.recipient_customer_id', $recipient->id)
            ->assertJsonPath('expected_input.field', 'installment_count');
        $this->postJson("/api/conversations/{$sessionId}/messages", ['message' => '2'])
            ->assertOk()->assertJsonPath('state', 'installment.waiting_preview')
            ->assertJsonPath('messages.0.options.2.value', 'confirmar');
        $this->assertSame(0, InstallmentProposal::count());
        $this->assertSame(0, Movement::count());
    }

    public function test_transfer_of_thirteen_cents_keeps_amount_and_recipient_when_parceled(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.parcelado']]])]);
        $sender = Customer::create(['phone' => '5551999998101', 'balance' => 1]);
        $recipient = Customer::create(['phone' => '5551999998102']);
        $session = $this->postJson('/api/conversations/start', [
            'external_user_id' => $sender->phone,
        ])->assertCreated()->json('session_id');

        $this->postJson("/api/conversations/{$session}/messages", ['message' => 'transferir'])->assertOk();
        $this->postJson("/api/conversations/{$session}/messages", ['message' => $recipient->phone])->assertOk();
        $this->postJson("/api/conversations/{$session}/messages", ['message' => '0,13'])
            ->assertOk()->assertJsonPath('state', 'transfer.waiting_confirmation');
        $this->postJson("/api/conversations/{$session}/messages", ['message' => 'parcelar'])
            ->assertOk()->assertJsonPath('state', 'installment.waiting_installment_count')
            ->assertJsonPath('context.installment_total_cents', 13)
            ->assertJsonPath('context.recipient_customer_id', $recipient->id)
            ->assertJsonPath('expected_input.field', 'installment_count')
            ->assertJsonPath('expected_input.max', 12);

        $preview = $this->postJson("/api/conversations/{$session}/messages", ['message' => '12'])
            ->assertOk()->assertJsonPath('state', 'installment.waiting_preview')
            ->json('messages.0.text');
        $this->assertStringContainsString('Valor Total: R$ 0,13', $preview);
        $this->assertStringContainsString('+55 (51) 99999-8102', $preview);
        $this->assertLessThanOrEqual(1024, mb_strlen($preview));
        $this->assertStringContainsString('R$ 0,02', $preview);
        $this->assertStringContainsString('R$ 0,01', $preview);

        $this->postJson("/api/conversations/{$session}/messages", ['message' => 'alterar_pedido'])
            ->assertOk()->assertJsonPath('state', 'installment.waiting_installment_count')
            ->assertJsonPath('context.installment_total_cents', 13)
            ->assertJsonPath('context.recipient_customer_id', $recipient->id);
        $this->postJson("/api/conversations/{$session}/messages", ['message' => '12'])->assertOk();
        $this->postJson("/api/conversations/{$session}/messages", ['message' => 'confirmar'])
            ->assertOk()->assertJsonPath('state', 'installment.waiting_sent_ack');

        $proposal = InstallmentProposal::sole();
        $this->assertSame('0.13', $proposal->amount);
        $this->assertSame($recipient->id, $proposal->recipient_customer_id);
        $this->assertSame(12, $proposal->installments);
        $this->assertSame('accepted', $proposal->status);
        $amounts = $proposal->payments()->orderBy('number')->pluck('amount')->all();
        $this->assertSame('0.02', $amounts[0]);
        $this->assertSame(12, count($amounts));
        $this->assertEqualsWithDelta(0.13, array_sum($amounts), 0.000001);
    }

    public function test_continue_button_from_installment_notice_opens_main_menu(): void
    {
        $sender = Customer::create(['phone' => '5551999998101', 'balance' => 20]);
        $session = ConversationSession::create([
            'channel' => 'whatsapp', 'external_user_id' => $sender->phone,
            'customer_id' => $sender->id, 'current_flow' => 'installment',
            'current_step' => 'waiting_installment_count', 'status' => 'active',
            'context' => ['installment_total_cents' => 1000],
        ]);

        $response = app(ConversationEngine::class)->receive($session, 'continuar_mpmm');

        $this->assertSame('main_menu.home', $response['state']);
        $this->assertStringContainsString('Como posso te ajudar hoje?', $response['messages'][0]['text']);

        config(['mpmm.admin_ids' => $sender->phone]);
        $newSession = app(ConversationEngine::class)->start('whatsapp', $sender->phone,
            ['initial_message' => 'continuar_mpmm']);
        $this->assertSame('main_menu.home', $newSession['state']);
    }

    public function test_accepted_installment_status_shows_only_the_menu_button(): void
    {
        $sender = Customer::create(['phone' => '5551999998101', 'balance' => 20]);
        $recipient = Customer::create(['phone' => '5551999998102']);
        $service = app(InstallmentService::class);
        $proposal = $service->propose($sender, $recipient, 10, 2);
        $service->decide($proposal, $recipient, true);

        $entry = app(ConversationEngine::class)->start('whatsapp', $sender->phone,
            ['initial_message' => 'parcelado']);

        $this->assertSame('installment.waiting_status_ack', $entry['state']);
        $this->assertSame("🗓️ *Crédito Parcelado*\n\n"
            ."O parcelamento #{$proposal->id} foi confirmado no *MyPhone MyMoney®*.\n\n"
            ."A primeira parcela foi transferida e as restantes estão agendadas.\n\n"
            ."Lembre-se:\n"
            ."*Cumprir os compromissos assumidos fortalece a confiança e estreita relações.*",
            $entry['messages'][0]['text']);
        $this->assertSame([['label' => '🏡 Voltar ao Menu', 'value' => 'continuar_mpmm']],
            $entry['messages'][0]['options']);
        $whatsapp = app(WhatsappResponseRenderer::class)->render($entry);
        $this->assertSame('button', $whatsapp[0]['interactive']['type']);
        $this->assertSame('continuar_mpmm',
            $whatsapp[0]['interactive']['action']['buttons'][0]['reply']['id']);

        $menu = app(ConversationEngine::class)->receive(
            ConversationSession::findOrFail($entry['session_id']), 'continuar_mpmm');
        $this->assertSame('main_menu.home', $menu['state']);
    }

    public function test_proposal_expires_at_fifteen_minutes_and_cannot_be_accepted_afterward(): void
    {
        $sender = Customer::create(['phone' => '5551999998101', 'balance' => 20, 'name' => 'Proponente Teste']);
        $recipient = Customer::create(['phone' => '5551999998102', 'name' => 'Beneficiário Teste']);
        $service = app(InstallmentService::class);
        $proposal = $service->propose($sender, $recipient, 10, 2);

        $this->assertSame('2026-10-05 12:15:00', $proposal->expires_at->format('Y-m-d H:i:s'));
        Carbon::setTestNow('2026-10-05 12:14:59 UTC');
        $this->assertSame('pending', $proposal->fresh()->status);
        Carbon::setTestNow('2026-10-05 12:15:00 UTC');
        $response = app(ConversationEngine::class)->start('whatsapp', $recipient->phone,
            ['initial_message' => 'parcelado_aceitar_'.$proposal->id]);
        $this->assertStringContainsString('Crédito parcelado *Cancelado*', $response['messages'][0]['text']);
        $this->assertStringContainsString("DE:\n+55", $response['messages'][0]['text']);
        $this->assertStringContainsString('Proponente Teste', $response['messages'][0]['text']);
        $this->assertStringContainsString('*Cancelada* pelo MpMM®', $response['messages'][0]['text']);
        $this->assertSame('expired', $proposal->fresh()->status);
        $this->assertNotNull($proposal->fresh()->decided_at);
        $this->assertSame(0, InstallmentPayment::count());
        $this->assertSame(0, Movement::count());
        $this->assertSame('20.00', $sender->fresh()->balance);
        $this->assertSame(2, WhatsappNotificationDelivery::where('notification_key', 'installment-expired:'.$proposal->id)->count());

        $this->expectException(InvalidArgumentException::class);
        $service->decide($proposal, $recipient, true);
    }

    public function test_expiration_command_preserves_history_and_queues_one_notice_per_participant(): void
    {
        $sender = Customer::create(['phone' => '5551999998101', 'balance' => 20, 'name' => 'Proponente Teste']);
        $recipient = Customer::create(['phone' => '5551999998102', 'name' => 'Beneficiário Teste']);
        $service = app(InstallmentService::class);
        $expired = $service->propose($sender, $recipient, 10, 2);
        $accepted = $service->propose($sender, $recipient, 10, 2);
        $service->decide($accepted, $recipient, true);
        $rejected = $service->propose($sender, $recipient, 10, 2);
        $service->decide($rejected, $recipient, false);

        Carbon::setTestNow('2026-10-05 12:14:59 UTC');
        $this->assertSame(0, $service->expirePending());
        Carbon::setTestNow('2026-10-05 12:15:00 UTC');
        $this->artisan('installments:expire-pending')->assertExitCode(0);
        $this->assertSame(0, $service->expirePending());
        $this->assertSame(['expired', 'accepted', 'rejected'],
            InstallmentProposal::orderBy('id')->pluck('status')->all());
        $notices = WhatsappNotificationDelivery::where('notification_key', 'installment-expired:'.$expired->id)->get();
        $this->assertCount(2, $notices);
        $this->assertEqualsCanonicalizing([$sender->phone, $recipient->phone], $notices->pluck('recipient_phone')->all());
        $this->assertStringContainsString('15 minutos', $notices->first()->notification['messages'][0]['text']);
        $this->assertStringContainsString('*R$* 10,00', $notices->first()->notification['messages'][0]['text']);
        $senderNotice = $notices->firstWhere('recipient_phone', $sender->phone)->notification['messages'][0]['text'];
        $recipientNotice = $notices->firstWhere('recipient_phone', $recipient->phone)->notification['messages'][0]['text'];
        $this->assertStringContainsString("PARA:\n+55", $senderNotice);
        $this->assertStringContainsString('Beneficiário Teste', $senderNotice);
        $this->assertStringContainsString("DE:\n+55", $recipientNotice);
        $this->assertStringContainsString('Proponente Teste', $recipientNotice);
        $this->assertStringContainsString("Proposta #{$expired->id}\nValor total: *R$* 10,00\nem 2 parcelas", $senderNotice);
    }

    public function test_shared_contact_can_name_a_nonpilot_recipient_and_is_kept_in_history(): void
    {
        $sender = Customer::create(['phone' => '5551999998101', 'balance' => 50]);
        $recipient = Customer::create(['phone' => '5551999998103', 'name' => 'Destinatária cadastrada']);
        $session = ConversationSession::create([
            'channel' => 'whatsapp', 'external_user_id' => $sender->phone,
            'customer_id' => $sender->id, 'current_flow' => 'installment',
            'current_step' => 'waiting_recipient', 'status' => 'active',
            'context' => ['installment_total_cents' => 4000, 'installment_count' => 2],
        ]);
        $contact = [
            'from' => $sender->phone, 'id' => 'wamid.installment.contact',
            'timestamp' => (string) now()->timestamp, 'type' => 'contacts',
            'contacts' => [[
                'name' => ['formatted_name' => 'Nome salvo na agenda'],
                'phones' => [['phone' => '+55 51 99999-8103', 'type' => 'CELL']],
            ]],
        ];
        $payload = ['entry' => [['changes' => [['value' => ['messages' => [$contact]]]]]]];
        $inbound = app(WhatsappWebhookParser::class)->messages($payload)[0];

        $this->assertSame($recipient->phone, $inbound->text);
        $response = app(ConversationEngine::class)->receive(
            $session, $inbound->text, ['raw' => $inbound->raw], 'whatsapp', $inbound->id,
        );
        $this->assertSame('installment.waiting_preview', $response['state']);
        $this->assertSame($recipient->id, $session->fresh()->context['recipient_customer_id']);
        $this->assertSame('Destinatária cadastrada', $recipient->fresh()->name);
        $inboundRecord = $session->messages()->where('direction', 'inbound')->firstOrFail();
        $this->assertSame('Nome salvo na agenda',
            $inboundRecord->payload['metadata']['raw']['contacts'][0]['name']['formatted_name']);

        $confirmed = app(ConversationEngine::class)->receive($session, 'confirmar');
        $proposal = InstallmentProposal::sole();
        $this->assertSame($recipient->phone, $confirmed['notifications'][0]['to']);
        $this->assertSame('accepted', $proposal->fresh()->status);
        $this->assertSame('paid', $proposal->payments()->where('number', 1)->firstOrFail()->status);
    }

    public function test_valid_new_contact_creates_only_a_minimal_recipient_record(): void
    {
        $sender = Customer::create(['phone' => '5551999998101', 'balance' => 50]);
        $session = ConversationSession::create([
            'channel' => 'whatsapp', 'external_user_id' => $sender->phone,
            'customer_id' => $sender->id, 'current_flow' => 'installment',
            'current_step' => 'waiting_recipient', 'status' => 'active',
            'context' => ['installment_total_cents' => 4000, 'installment_count' => 2],
        ]);

        $response = app(ConversationEngine::class)->receive(
            $session, '+55 51 99999-8104',
            ['raw' => ['type' => 'contacts', 'contacts' => [[
                'name' => ['formatted_name' => 'Contato de teste'],
                'phones' => [['phone' => '+55 51 99999-8104']],
            ]]]],
            'whatsapp', 'wamid.installment.new-contact',
        );

        $recipient = Customer::where('phone', '5551999998104')->sole();
        $this->assertSame('installment.waiting_preview', $response['state']);
        $this->assertSame($recipient->id, $session->fresh()->context['recipient_customer_id']);
        $this->assertSame('0.00', $recipient->limit);
        $this->assertFalse($recipient->opt_in);
        $this->assertNull($recipient->name);
        $this->assertSame('Contato de teste',
            $session->messages()->where('direction', 'inbound')->firstOrFail()
                ->payload['metadata']['raw']['contacts'][0]['name']['formatted_name']);
        $this->assertSame(0, InstallmentProposal::count());
    }

    public function test_confirmation_transfers_first_installment_and_queues_the_full_plan(): void
    {
        $sender = Customer::create(['phone' => '5551999998101', 'balance' => 120]);
        $recipient = Customer::create(['phone' => '5551999998102']);
        $session = $this->postJson('/api/conversations/start', [
            'external_user_id' => $sender->phone,
        ])->assertCreated()->json('session_id');

        foreach (['parcelado', '20', '3', $recipient->phone] as $message) {
            $this->postJson("/api/conversations/{$session}/messages", ['message' => $message])->assertOk();
        }
        $preview = $this->postJson("/api/conversations/{$session}/messages", ['message' => 'mudar'])
            ->assertOk()->assertJsonPath('state', 'installment.waiting_installment_amount');
        foreach (['20', '3', $recipient->phone] as $message) {
            $preview = $this->postJson("/api/conversations/{$session}/messages", ['message' => $message])->assertOk();
        }
        $this->assertStringContainsString('*_Esta transferência e este plano de parcelamento não poderão ser desfeitos._*',
            $preview->json('messages.0.text'));
        $this->assertSame('✅ Confirmar e Enviar', $preview->json('messages.0.options.2.label'));
        $this->assertSame(0, Movement::count());

        $sent = $this->postJson("/api/conversations/{$session}/messages", ['message' => 'confirmar'])
            ->assertOk()->assertJsonPath('state', 'installment.waiting_sent_ack');
        $proposal = InstallmentProposal::sole();
        $this->assertSame('accepted', $proposal->status);
        $this->assertSame(['6.67', '6.67', '6.66'],
            $proposal->payments()->orderBy('number')->pluck('amount')->all());
        $this->assertSame('paid', $proposal->payments()->where('number', 1)->firstOrFail()->status);
        $this->assertSame(2, Movement::count());
        $this->assertSame('113.33', $sender->fresh()->balance);
        $this->assertSame('6.67', $recipient->fresh()->balance);
        $this->assertStringContainsString('primeira parcela foi transferida', $sent->json('messages.0.text'));
        $this->assertSame($recipient->phone, $sent->json('notifications.0.to'));
        $this->assertSame('aviso_transferencia_v1', $sent->json('notifications.0.template_name'));
        $this->assertSame('6,67', $sent->json('notifications.0.template_components.1.parameters.0.text'));
        $this->assertSame('installment-plan:'.$proposal->id, $sent->json('notifications.1.notification_key'));
        $plan = $sent->json('notifications.1.messages.0.text');
        $this->assertStringContainsString('Plano de parcelamento #'.$proposal->id, $plan);
        $this->assertStringContainsString('1ª  R$ 6,67', $plan);
        $this->assertStringContainsString('3ª  R$ 6,66', $plan);
        $this->assertStringNotContainsString('Aceitar', $plan);
    }

    public function test_confirmation_checks_total_debit_plus_credit_and_rolls_back_when_limit_falls(): void
    {
        $sender = Customer::create([
            'phone' => '5551999998101', 'balance' => 5,
            'rotative_balance' => 10,
        ]);
        $sender->forceFill(['bonus_credit_balance' => 10])->save();
        $recipient = Customer::create(['phone' => '5551999998102']);
        $service = app(InstallmentService::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Saldo disponível insuficiente para o valor total da proposta.');
        try {
            $service->createAndConfirm($sender, $recipient, 16, 2);
        } finally {
            $this->assertSame(0, InstallmentProposal::count());
            $this->assertSame(0, Movement::count());
        }
    }

    public function test_confirmation_accepts_total_within_debit_plus_credit(): void
    {
        $sender = Customer::create([
            'phone' => '5551999998101', 'balance' => 5,
            'rotative_balance' => 10,
        ]);
        $sender->forceFill(['bonus_credit_balance' => 10])->save();
        $recipient = Customer::create(['phone' => '5551999998102']);

        $proposal = app(InstallmentService::class)->createAndConfirm($sender, $recipient, 15, 2);

        $this->assertSame('accepted', $proposal->status);
        $this->assertSame('0.00', $sender->fresh()->balance);
        $this->assertSame('7.50', $sender->fresh()->rotative_balance);
        $this->assertEqualsWithDelta(7.5,
            (float) $recipient->fresh()->balance + (float) $recipient->fresh()->rotative_balance, 0.000001);
        $this->assertSame(2, Movement::count());
    }

    public function test_cancelling_the_preview_returns_to_the_main_menu_without_creating_a_proposal(): void
    {
        $sender = Customer::create(['phone' => '5551999998101']);
        $recipient = Customer::create(['phone' => '5551999998102']);
        $session = ConversationSession::create([
            'channel' => 'whatsapp', 'external_user_id' => $sender->phone,
            'customer_id' => $sender->id, 'current_flow' => 'installment',
            'current_step' => 'waiting_preview', 'status' => 'active',
            'context' => [
                'installment_total_cents' => 2000, 'installment_count' => 2,
                'recipient_customer_id' => $recipient->id,
            ],
            'last_interaction_at' => now(),
        ]);

        $response = app(ConversationEngine::class)->receive($session, 'cancelar');

        $this->assertSame('main_menu.home', $response['state']);
        $this->assertSame(0, InstallmentProposal::count());
    }

    public function test_monthly_wording_is_shown_only_for_monthly_schedules(): void
    {
        config(['mpmm.installments.schedule_type' => 'monthly']);
        $sender = Customer::create(['phone' => '5551999998101']);
        $recipient = Customer::create(['phone' => '5551999998102']);
        $session = ConversationSession::create([
            'channel' => 'whatsapp', 'external_user_id' => $sender->phone,
            'customer_id' => $sender->id, 'current_flow' => 'installment',
            'current_step' => 'waiting_recipient', 'status' => 'active',
            'context' => ['installment_total_cents' => 2000, 'installment_count' => 2],
            'last_interaction_at' => now(),
        ]);

        $response = app(ConversationEngine::class)->receive($session, $recipient->phone);

        $this->assertSame('installment.waiting_preview', $response['state']);
        $this->assertStringContainsString('As demais a cada mês, conforme as datas acima.', $response['messages'][0]['text']);
        $this->assertStringContainsString('05/11/2026', $response['messages'][0]['text']);
    }

    public function test_recipient_cannot_refuse_an_already_confirmed_plan(): void
    {
        $proposer = Customer::create(['phone' => '5551999998101']);
        $beneficiary = Customer::create(['phone' => '5551999998102', 'balance' => 10]);
        $session = $this->postJson('/api/conversations/start', [
            'external_user_id' => $beneficiary->phone,
        ])->assertCreated()->json('session_id');

        foreach (['parcelado', 'nova_proposta', '10', '2', $proposer->phone] as $message) {
            $this->postJson("/api/conversations/{$session}/messages", ['message' => $message])->assertOk();
        }
        $this->postJson("/api/conversations/{$session}/messages", ['message' => 'ok'])->assertOk();
        $proposal = InstallmentProposal::sole();
        $this->assertSame($beneficiary->id, $proposal->sender_customer_id);
        $this->assertSame($proposer->id, $proposal->recipient_customer_id);

        $response = app(ConversationEngine::class)->start('whatsapp', $proposer->phone,
            ['initial_message' => 'parcelado_recusar_'.$proposal->id]);
        $this->assertStringContainsString('não está disponível para aceite', $response['messages'][0]['text']);
        $this->assertEmpty($response['notifications'] ?? []);
        $this->assertSame('accepted', $proposal->fresh()->status);
        $this->assertSame(2, InstallmentPayment::count());
        $this->assertSame(2, Movement::count());

        $again = app(ConversationEngine::class)->start('whatsapp', $proposer->phone,
            ['initial_message' => 'parcelado_recusar_'.$proposal->id]);
        $this->assertEmpty($again['notifications'] ?? []);
        $this->assertSame(2, Movement::count());
    }

    public function test_completed_outgoing_and_active_incoming_do_not_block_a_new_pilot_proposal(): void
    {
        config(['mpmm.installments.schedule_type' => 'minute']);
        $proposer = Customer::create(['phone' => '5551999998101', 'balance' => 20]);
        $beneficiary = Customer::create(['phone' => '5551999998102', 'balance' => 20]);
        InstallmentProposal::create([
            'sender_customer_id' => $beneficiary->id, 'recipient_customer_id' => $proposer->id,
            'amount' => 4, 'installments' => 2, 'interval_days' => 1,
            'schedule_type' => 'daily', 'schedule_timezone' => 'UTC', 'status' => 'completed',
        ]);
        InstallmentProposal::create([
            'sender_customer_id' => $proposer->id, 'recipient_customer_id' => $beneficiary->id,
            'amount' => 8, 'installments' => 2, 'interval_days' => 1,
            'schedule_type' => 'daily', 'schedule_timezone' => 'UTC', 'status' => 'accepted',
        ]);

        $session = ConversationSession::findOrFail(app(ConversationEngine::class)
            ->start('whatsapp', $beneficiary->phone)['session_id']);
        $menu = app(ConversationEngine::class)->receive($session, 'parcelado');
        $this->assertSame('installment.waiting_installment_amount', $menu['state']);
        foreach (['10', '2', $proposer->phone] as $message) {
            app(ConversationEngine::class)->receive($session, $message);
        }
        $confirmation = app(ConversationEngine::class)->receive($session, 'confirmar');
        $proposal = InstallmentProposal::latest('id')->firstOrFail();
        $this->assertSame('accepted', $proposal->status);
        $this->assertSame($proposer->phone, $confirmation['notifications'][0]['to']);
        $this->assertSame(2, Movement::count());
        $this->assertStringContainsString('Parcela 1 de 2', $confirmation['notifications'][0]['messages'][0]['text']);
        $this->assertSame('15.00', $beneficiary->fresh()->balance);
        $this->assertSame('25.00', $proposer->fresh()->balance);
        $this->assertSame(2, Movement::count());

        Carbon::setTestNow($proposal->fresh()->accepted_at->copy()->addMinute());
        $this->assertSame(['paid' => 1, 'failed' => 0], app(InstallmentService::class)->processDue());
        $this->assertSame('completed', $proposal->fresh()->status);
        $this->assertSame('10.00', $beneficiary->fresh()->balance);
        $this->assertSame('30.00', $proposer->fresh()->balance);
        $this->assertSame(4, Movement::count());
        $notices = WhatsappNotificationDelivery::where('notification_key', 'like', 'installment-payment:%')
            ->get()->pluck('notification', 'recipient_phone');
        $this->assertCount(2, $notices);
        $lastSent = $notices[$beneficiary->phone]['messages'][0];
        $this->assertStringContainsString('↗️ *Transferência Enviada*', $lastSent['text']);
        $this->assertStringContainsString('Parcela 2 de 2', $lastSent['text']);
        $this->assertStringContainsString('*Parcelamento concluído.*', $lastSent['text']);
        $this->assertSame('continuar_mpmm', $lastSent['options'][0]['value']);
        $this->assertStringContainsString('Parcela 2 de 2', $notices[$proposer->phone]['messages'][0]['text']);
        $this->assertStringContainsString('Parcelamento concluído.', $notices[$proposer->phone]['messages'][0]['text']);
        $this->assertSame(['🏡 Voltar ao menu', '↗️ Transferir'],
            array_column($notices[$proposer->phone]['messages'][0]['options'], 'label'));
        $this->assertSame(['paid' => 0, 'failed' => 0], app(InstallmentService::class)->processDue());
        $this->assertSame(4, Movement::count());
    }

    public function test_existing_installment_does_not_block_another_confirmation(): void
    {
        $sender = Customer::create(['phone' => '5551999998101', 'balance' => 30]);
        $recipient = Customer::create(['phone' => '5551999998102']);
        $service = app(InstallmentService::class);
        $first = $service->createAndConfirm($sender, $recipient, 20, 2);

        $sessionId = $this->postJson('/api/conversations/start', [
            'external_user_id' => $sender->phone,
        ])->assertCreated()->json('session_id');
        $this->postJson("/api/conversations/{$sessionId}/messages", ['message' => 'parcelado'])
            ->assertOk()->assertJsonPath('state', 'installment.waiting_status_ack');
        $this->postJson("/api/conversations/{$sessionId}/messages", ['message' => 'nova_proposta'])
            ->assertOk()->assertJsonPath('state', 'installment.waiting_installment_amount');
        foreach (['10', '2', $recipient->phone, 'confirmar'] as $message) {
            $this->postJson("/api/conversations/{$sessionId}/messages", ['message' => $message])->assertOk();
        }

        $this->assertSame(2, InstallmentProposal::count());
        $this->assertSame('accepted', $first->fresh()->status);
        $this->assertSame('accepted', InstallmentProposal::latest('id')->firstOrFail()->status);
        $this->assertSame(4, Movement::count());
    }

    public function test_insufficient_debit_balance_cancels_future_payments(): void
    {
        $sender = Customer::create(['phone' => '5551999998101', 'balance' => 10, 'rotative_balance' => 100]);
        $recipient = Customer::create(['phone' => '5551999998102']);
        $service = app(InstallmentService::class);
        $proposal = $service->propose($sender, $recipient, 20, 2);
        $service->decide($proposal, $recipient, true);

        Carbon::setTestNow($proposal->fresh()->accepted_at->copy()->addDays(30)->addMinute());
        $this->assertSame(['paid' => 0, 'failed' => 1], $service->processDue());
        $this->assertSame('failed', $proposal->fresh()->status);
        $this->assertSame(['paid', 'failed'], $proposal->payments()->orderBy('number')->pluck('status')->all());
        $this->assertSame(2, Movement::count());
        $this->assertSame('100.00', $sender->fresh()->rotative_balance);
    }

    public function test_only_new_proposals_use_daily_dates_during_the_test(): void
    {
        $sender = Customer::create(['phone' => '5551999998101', 'balance' => 30]);
        $recipient = Customer::create(['phone' => '5551999998102']);
        $service = app(InstallmentService::class);
        $existing = $service->propose($sender, $recipient, 20, 2);
        $service->decide($existing, $recipient, true);
        $this->assertSame(30, $existing->fresh()->interval_days);

        config(['mpmm.installments.interval_days' => 1]);
        $new = $service->propose($sender, $recipient, 5, 2);
        $this->assertSame(1, $new->interval_days);
        $service->decide($new, $recipient, true);
        config(['mpmm.installments.interval_days' => 30]);

        $this->assertSame(['2026-10-05', '2026-11-04'],
            $existing->payments()->orderBy('number')->get()->map(fn ($p) => $p->due_at->format('Y-m-d'))->all());
        $this->assertSame(['2026-10-05', '2026-10-06'],
            $new->payments()->orderBy('number')->get()->map(fn ($p) => $p->due_at->format('Y-m-d'))->all());

        Carbon::setTestNow($new->fresh()->accepted_at->copy()->addDay()->addMinute());
        $this->assertSame(['paid' => 1, 'failed' => 0], $service->processDue());
        $this->assertSame('paid', $existing->payments()->where('number', 1)->firstOrFail()->status);
    }

    public function test_minute_mode_schedules_and_processes_only_new_pilot_proposals_one_minute_apart(): void
    {
        config(['mpmm.installments.schedule_type' => 'minute']);
        $sender = Customer::create(['phone' => '5551999998101', 'balance' => 30]);
        $recipient = Customer::create(['phone' => '5551999998102']);
        $service = app(InstallmentService::class);
        $session = ConversationSession::create([
            'channel' => 'whatsapp', 'external_user_id' => $sender->phone,
            'customer_id' => $sender->id, 'current_flow' => 'installment',
            'current_step' => 'waiting_recipient', 'status' => 'active',
            'context' => ['installment_total_cents' => 900, 'installment_count' => 3],
            'last_interaction_at' => now(),
        ]);
        $preview = app(ConversationEngine::class)->receive($session, $recipient->phone);
        $this->assertStringContainsString('As demais a cada minuto', $preview['messages'][0]['text']);
        $this->assertMatchesRegularExpression('/05\/10\/2026 \d{2}:\d{2}/', $preview['messages'][0]['text']);

        $proposal = $service->propose($sender, $recipient, 9, 3);
        $this->assertSame('minute', $proposal->schedule_type);
        $proposal = $service->decide($proposal, $recipient, true);
        $this->assertSame(['2026-10-05 12:00:00', '2026-10-05 12:01:00', '2026-10-05 12:02:00'],
            $proposal->payments()->orderBy('number')->get()
                ->map(fn ($payment) => $payment->due_at->timezone('UTC')->format('Y-m-d H:i:s'))->all());
        $this->assertStringContainsString('05/10/2026 12:01',
            $service->scheduleText(9, 3, $proposal->accepted_at, 'UTC', 1, 'minute', 'UTC'));
        $this->assertSame(['paid' => 0, 'failed' => 0], $service->processDue());

        Carbon::setTestNow('2026-10-05 12:01:00 UTC');
        $this->assertSame(['paid' => 1, 'failed' => 0], $service->processDue());
        $this->assertSame(['paid' => 0, 'failed' => 0], $service->processDue());

        Carbon::setTestNow('2026-10-05 12:02:00 UTC');
        $this->assertSame(['paid' => 1, 'failed' => 0], $service->processDue());
        $this->assertSame('completed', $proposal->fresh()->status);
        $this->assertSame('21.00', $sender->fresh()->balance);
        $this->assertSame('9.00', $recipient->fresh()->balance);
    }

    public function test_existing_quarter_hour_proposals_keep_their_scheduled_payments(): void
    {
        config([
            'mpmm.installments.schedule_type' => 'quarter_hour',
            'mpmm.installments.interval_days' => 1,
        ]);
        $sender = Customer::create(['phone' => '5551999998101', 'balance' => 30]);
        $recipient = Customer::create(['phone' => '5551999998102']);
        $service = app(InstallmentService::class);
        $session = ConversationSession::create([
            'channel' => 'whatsapp', 'external_user_id' => $sender->phone,
            'customer_id' => $sender->id, 'current_flow' => 'installment',
            'current_step' => 'waiting_recipient', 'status' => 'active',
            'context' => ['installment_total_cents' => 900, 'installment_count' => 3],
            'last_interaction_at' => now(),
        ]);
        $preview = app(ConversationEngine::class)->receive($session, $recipient->phone);
        $this->assertStringContainsString('As demais a cada 7 dias', $preview['messages'][0]['text']);
        $this->assertStringNotContainsString('As demais a cada 15 minutos', $preview['messages'][0]['text']);

        $proposal = $service->propose($sender, $recipient, 9, 3);
        $this->assertSame('daily', $proposal->schedule_type);
        $proposal->forceFill(['schedule_type' => 'quarter_hour'])->save();
        $proposal = $service->decide($proposal, $recipient, true);
        $this->assertSame(['2026-10-05 12:00:00', '2026-10-05 12:15:00', '2026-10-05 12:30:00'],
            $proposal->payments()->orderBy('number')->get()
                ->map(fn ($payment) => $payment->due_at->timezone('UTC')->format('Y-m-d H:i:s'))->all());
        $this->assertSame(['paid' => 0, 'failed' => 0], $service->processDue());

        Carbon::setTestNow('2026-10-05 12:14:59 UTC');
        $this->assertSame(['paid' => 0, 'failed' => 0], $service->processDue());
        Carbon::setTestNow('2026-10-05 12:15:00 UTC');
        $this->assertSame(['paid' => 1, 'failed' => 0], $service->processDue());
        Carbon::setTestNow('2026-10-05 12:30:00 UTC');
        $this->assertSame(['paid' => 1, 'failed' => 0], $service->processDue());
        $this->assertSame('completed', $proposal->fresh()->status);
    }

    public function test_new_quarter_hour_proposals_are_rejected(): void
    {
        $sender = Customer::create(['phone' => '5551999998101', 'balance' => 30]);
        $recipient = Customer::create(['phone' => '5551999998102']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Prazo de parcelamento inválido.');
        app(InstallmentService::class)->propose($sender, $recipient, 9, 3, false, 'quarter_hour', 1);
    }

    public function test_pending_preview_with_retired_interval_requires_a_new_choice(): void
    {
        $sender = Customer::create(['phone' => '5551999998101', 'balance' => 30]);
        $recipient = Customer::create(['phone' => '5551999998102']);
        foreach (['quarter_hour', 'fifteen_days'] as $retiredInterval) {
            $session = ConversationSession::create([
                'channel' => 'whatsapp', 'external_user_id' => $sender->phone,
                'customer_id' => $sender->id, 'current_flow' => 'installment',
                'current_step' => 'waiting_preview', 'status' => 'active',
                'context' => ['installment_total_cents' => 900, 'installment_count' => 3,
                    'recipient_customer_id' => $recipient->id, 'installment_schedule' => $retiredInterval],
                'last_interaction_at' => now(),
            ]);

            $response = app(ConversationEngine::class)->receive($session, 'confirmar');

            $this->assertSame('installment.waiting_schedule', $response['state']);
            $this->assertSame(['seven_days', 'two_weeks', 'monthly'],
                array_column($response['messages'][0]['options'], 'value'));
        }
        $this->assertSame(0, InstallmentProposal::count());
    }

    public function test_member_can_choose_weekly_fortnightly_or_monthly_before_sending_proposal(): void
    {
        config(['mpmm.installments.schedule_type' => 'daily']);
        Carbon::setTestNow(Carbon::parse('2025-01-31 22:00:00', 'America/Sao_Paulo'));
        $sender = Customer::create(['phone' => '5551999998101', 'balance' => 90,
            'timezone' => 'America/Sao_Paulo']);
        $recipient = Customer::create(['phone' => '5551999998102']);

        foreach ([
            ['seven_days', 'daily', 7, ['2025-01-31', '2025-02-07', '2025-02-14']],
            ['two_weeks', 'daily', 14, ['2025-01-31', '2025-02-14', '2025-02-28']],
            ['monthly', 'monthly', 1, ['2025-01-31', '2025-02-28', '2025-03-31']],
        ] as [$choice, $type, $days, $dates]) {
            $session = ConversationSession::create([
                'channel' => 'whatsapp', 'external_user_id' => $sender->phone,
                'customer_id' => $sender->id, 'current_flow' => 'installment',
                'current_step' => 'waiting_recipient', 'status' => 'active',
                'context' => ['installment_total_cents' => 3000, 'installment_count' => 3],
                'last_interaction_at' => now(),
            ]);
            $engine = app(ConversationEngine::class);
            $engine->receive($session, $recipient->phone);
            $options = $engine->receive($session, 'escolher_prazo');
            $this->assertSame('installment.waiting_schedule', $options['state']);
            $this->assertSame('button', app(WhatsappResponseRenderer::class)
                ->render($options)[0]['interactive']['type']);
            $this->assertSame(['seven_days', 'two_weeks', 'monthly'],
                array_column($options['messages'][0]['options'], 'value'));

            $unavailable = $engine->receive($session, 'quarter_hour');
            $this->assertSame('installment.waiting_schedule', $unavailable['state']);
            $this->assertSame(['seven_days', 'two_weeks', 'monthly'],
                array_column($unavailable['messages'][0]['options'], 'value'));

            $preview = $engine->receive($session, $choice);
            $this->assertSame('installment.waiting_preview', $preview['state']);
            $this->assertStringContainsString(date('d/m/Y', strtotime($dates[1])), $preview['messages'][0]['text']);
            $engine->receive($session, 'confirmar');
            $proposal = InstallmentProposal::latest('id')->firstOrFail();
            $this->assertSame($type, $proposal->schedule_type);
            $this->assertSame($days, $proposal->interval_days);
            $this->assertSame('accepted', $proposal->status);
            $this->assertSame($dates, $proposal->payments()->orderBy('number')->get()
                ->map(fn ($payment) => $payment->due_at->timezone('America/Sao_Paulo')->format('Y-m-d'))->all());
        }
    }

    public function test_future_monthly_mode_anchors_the_day_and_handles_leap_february(): void
    {
        $sender = Customer::create(['phone' => '5551999998101', 'timezone' => 'America/Sao_Paulo', 'balance' => 30]);
        $recipient = Customer::create(['phone' => '5551999998102']);
        $service = app(InstallmentService::class);
        config(['mpmm.installments.schedule_type' => 'monthly']);
        Carbon::setTestNow(Carbon::parse('2024-01-31 22:00:00', 'America/Sao_Paulo'));

        $proposal = $service->propose($sender, $recipient, 30, 3);
        $this->assertSame('monthly', $proposal->schedule_type);
        $this->assertSame('America/Sao_Paulo', $proposal->schedule_timezone);
        config(['mpmm.installments.schedule_type' => 'daily']);
        $service->decide($proposal, $recipient, true);

        $dates = $proposal->payments()->orderBy('number')->get()
            ->map(fn ($payment) => $payment->due_at->timezone('America/Sao_Paulo')->format('Y-m-d H:i:s'))->all();
        $this->assertSame([
            '2024-01-31 22:00:00',
            '2024-02-29 22:00:00',
            '2024-03-31 22:00:00',
        ], $dates);

        $nonLeap = $service->schedule(20, 2,
            Carbon::parse('2025-01-31 22:00:00', 'America/Sao_Paulo'),
            1, 'monthly', 'America/Sao_Paulo');
        $this->assertSame(['2025-01-31', '2025-02-28'],
            array_map(fn ($entry) => $entry['due_at']->timezone('America/Sao_Paulo')->format('Y-m-d'), $nonLeap));

        $dayFive = $service->schedule(30, 3,
            Carbon::parse('2026-01-05 10:00:00', 'America/Sao_Paulo'),
            1, 'monthly', 'America/Sao_Paulo');
        $this->assertSame(['2026-01-05', '2026-02-05', '2026-03-05'],
            array_map(fn ($entry) => $entry['due_at']->timezone('America/Sao_Paulo')->format('Y-m-d'), $dayFive));
    }

    public function test_weekly_and_fortnightly_keep_the_local_weekday_across_dst(): void
    {
        $service = app(InstallmentService::class);
        $acceptedAt = Carbon::parse('2026-03-07 23:30:00', 'America/New_York');

        foreach ([7 => ['2026-03-07 23:30', '2026-03-14 23:30', '2026-03-21 23:30'],
            14 => ['2026-03-07 23:30', '2026-03-21 23:30', '2026-04-04 23:30']] as $days => $expected) {
            $entries = $service->schedule(30, 3, $acceptedAt, $days, 'daily', 'America/New_York');
            $this->assertSame($expected, array_map(fn ($entry) => $entry['due_at']
                ->timezone('America/New_York')->format('Y-m-d H:i'), $entries));
            $this->assertSame([Carbon::SATURDAY], array_values(array_unique(array_map(
                fn ($entry) => $entry['due_at']->timezone('America/New_York')->dayOfWeek, $entries))));
        }
    }

    public function test_amount_count_and_participants_are_limited_on_the_server(): void
    {
        $sender = Customer::create(['phone' => '5551999998101', 'balance' => 2000]);
        $recipient = Customer::create(['phone' => '5551999998102']);
        $outsider = Customer::create(['phone' => '5551999990098', 'balance' => 100]);
        $service = app(InstallmentService::class);

        foreach ([
            [$sender, $recipient, 2000.01, 12],
            [$sender, $recipient, 2000, 13],
            [$sender, $recipient, 0.02, 3],
        ] as [$from, $to, $amount, $count]) {
            try {
                $service->propose($from, $to, $amount, $count);
                $this->fail('An invalid installment proposal was accepted.');
            } catch (InvalidArgumentException) {
                // Monetary limits are enforced beyond the chat UI.
            }
        }

        $this->assertSame($outsider->id,
            $service->propose($sender, $outsider, 100, 2)->recipient_customer_id);

        $this->assertSame($outsider->id,
            $service->propose($outsider, $recipient, 100, 2)->sender_customer_id);

        $proposal = $service->propose($sender, $recipient, 2000, 12);
        try {
            $service->decide($proposal, $sender, true);
            $this->fail('The sender accepted their own proposal.');
        } catch (InvalidArgumentException) {
            $this->assertSame('pending', $proposal->fresh()->status);
        }
        $this->assertSame(3, InstallmentProposal::count());
    }

    public function test_total_is_rounded_up_for_regular_installments_and_adjusted_on_the_last(): void
    {
        $sender = Customer::create(['phone' => '5551999998101', 'balance' => 1000]);
        $recipient = Customer::create(['phone' => '5551999998102']);
        $service = app(InstallmentService::class);
        $proposal = $service->propose($sender, $recipient, 1000, 7);
        $service->decide($proposal, $recipient, true);

        $this->assertSame(['142.86', '142.86', '142.86', '142.86', '142.86', '142.86', '142.84'],
            $proposal->payments()->orderBy('number')->pluck('amount')->all());
        $this->assertSame('paid', $proposal->payments()->where('number', 1)->sole()->status);
        $this->assertSame('857.14', $sender->fresh()->balance);
        $this->assertSame('142.86', $recipient->fresh()->balance);
        $this->assertSame(['paid' => 0, 'failed' => 0], $service->processDue());
    }

    public function test_month_end_schedule_restores_the_original_day_and_clamps_february(): void
    {
        $service = app(InstallmentService::class);
        $entries = $service->schedule(1000, 7,
            Carbon::parse('2026-10-31 10:00:00', 'America/Sao_Paulo'),
            1, 'monthly', 'America/Sao_Paulo');

        $this->assertSame(['2026-10-31', '2026-11-30', '2026-12-31', '2027-01-31',
            '2027-02-28', '2027-03-31', '2027-04-30'],
            array_map(fn ($entry) => $entry['due_at']->timezone('America/Sao_Paulo')->format('Y-m-d'), $entries));
        $this->assertEqualsWithDelta(1000, array_sum(array_column($entries, 'amount')), 0.000001);
    }

    public function test_creation_uses_available_rotative_credit_above_legacy_cap(): void
    {
        $sender = Customer::create(['phone' => '5551999998101', 'balance' => 142.85, 'rotative_balance' => 3000]);
        $recipient = Customer::create(['phone' => '5551999998102']);

        $proposal = app(InstallmentService::class)->propose($sender, $recipient, 2500, 7);
        $this->assertSame('2500.00', $proposal->amount);
    }

    public function test_acceptance_rechecks_balance_and_keeps_proposal_pending_without_charging(): void
    {
        $sender = Customer::create(['phone' => '5551999998101', 'balance' => 20]);
        $recipient = Customer::create(['phone' => '5551999998102']);
        $service = app(InstallmentService::class);
        $proposal = $service->propose($sender, $recipient, 20, 2);
        $sender->forceFill(['balance' => 9])->save();

        try {
            $service->decide($proposal, $recipient, true);
            $this->fail('Acceptance should require the first installment balance.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Saldo disponível insuficiente para a primeira parcela', $exception->getMessage());
        }
        $this->assertSame('pending', $proposal->fresh()->status);
        $this->assertSame(0, InstallmentPayment::count());
        $this->assertSame(0, Movement::count());
    }
}
