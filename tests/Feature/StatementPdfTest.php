<?php

namespace Tests\Feature;

use App\Models\ConversationSession;
use App\Models\Customer;
use App\Models\InstallmentProposal;
use App\Models\Movement;
use App\Services\Documents\StatementPdfService;
use App\Services\Documents\DocumentAccessUrl;
use App\Services\WhatsApp\WhatsappClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\TestCase;

class StatementPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_statement_uses_the_offset_valid_on_each_historical_movement(): void
    {
        $customer = Customer::create([
            'phone' => '351912345678',
            'timezone' => 'Europe/Lisbon',
            'balance' => 20,
        ]);

        foreach (['2026-01-15 15:00:00', '2026-07-15 15:00:00'] as $index => $instant) {
            Movement::create([
                'customer_id' => $customer->id,
                'event' => 'deposit.confirmed',
                'credit' => 10,
                'debit' => 0,
                'balance' => 10 * ($index + 1),
            ])->forceFill(['created_at' => $instant])->saveQuietly();
        }

        $dates = app(StatementPdfService::class)->dataForCustomer($customer)['pages']
            ->flatten(1)->pluck('date')->all();

        $this->assertContains('15/01/2026 15:00:00 +0000', $dates);
        $this->assertContains('15/07/2026 16:00:00 +0100', $dates);
    }

    public function test_generates_and_serves_a_branded_statement_pdf(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 21:54:32', 'UTC'));
        Storage::fake('local');
        config(['services.documents.public_url' => 'https://example.test/mpmm/public']);

        $customer = Customer::create([
            'phone' => '5551999990100',
            'name' => 'Cliente do Extrato',
            'timezone' => 'America/Sao_Paulo',
            'balance' => 75,
        ]);
        $session = ConversationSession::create([
            'customer_id' => $customer->id,
            'external_user_id' => $customer->phone,
            'state' => 'statement.waiting_statement_action',
            'current_step' => 'waiting_statement_action',
            'context' => [],
        ]);
        Movement::create([
            'customer_id' => $customer->id,
            'conversation_session_id' => $session->id,
            'event' => 'deposit.confirmed',
            'debit' => 0,
            'credit' => 75,
            'balance' => 75,
        ]);

        $document = app(StatementPdfService::class)->generate($session);
        $storedPath = 'statements/'.$document['filename'];

        $this->assertSame('MpMM extrato 5551999990100 20260803 185432.pdf', $document['download_filename']);
        Storage::disk('local')->assertExists($storedPath);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($storedPath));
        $this->assertStringContainsString('/MediaBox [0.000 0.000 595.280 841.890]', Storage::disk('local')->get($storedPath));
        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page\b/', Storage::disk('local')->get($storedPath)));
        $this->assertStringStartsWith(
            'https://example.test/mpmm/public/api/documents/statements/'.$document['filename'].'?',
            $document['url'],
        );
        $signedPath = '/api/documents/statements/'.$document['filename'].'?'.parse_url($document['url'], PHP_URL_QUERY);

        $this->get('/api/documents/statements/'.$document['filename'])->assertForbidden();
        $this->get($signedPath.'&filename=other.pdf')->assertForbidden();
        $this->get($signedPath)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        Carbon::setTestNow(Carbon::now()->addDays(8));
        $this->get($signedPath)->assertForbidden();

        Carbon::setTestNow();
    }

    public function test_receipt_documents_and_images_require_signed_links(): void
    {
        Storage::fake('local');
        $id = (string) Str::uuid();
        $cases = [
            ['documents.transfer-receipts', "transfer-receipts/transferencia-mpmm-{$id}.pdf", 'application/pdf'],
            ['images.transfer-receipts', "transfer-receipt-images/transferencia-mpmm-{$id}.png", 'image/png'],
        ];

        foreach ($cases as [$route, $storagePath, $type]) {
            Storage::disk('local')->put($storagePath, 'fixture');
            $filename = basename($storagePath);
            $url = DocumentAccessUrl::for($route, $filename);
            $signedPath = '/api/'.($route === 'documents.transfer-receipts' ? 'documents/transfer-receipts/' : 'images/transfer-receipts/')
                .$filename.'?'.parse_url($url, PHP_URL_QUERY);

            $this->get(strtok($signedPath, '?'))->assertForbidden();
            $this->get($signedPath)->assertOk()->assertHeader('Content-Type', $type);
        }
    }

    public function test_statement_flow_returns_the_generated_pdf_as_a_document(): void
    {
        Storage::fake('local');
        config(['services.documents.public_url' => 'https://example.test/mpmm/public']);

        $customer = Customer::create(['phone' => '5551999990101', 'name' => 'Cliente PDF']);
        $sessionId = $this->postJson('/api/conversations/start', [
            'external_user_id' => $customer->phone,
        ])->assertCreated()->json('session_id');

        $this->postJson("/api/conversations/{$sessionId}/messages", ['message' => 'extrato'])
            ->assertOk()
            ->assertJsonPath('messages.1.type', 'document')
            ->assertJsonPath('messages.1.filename', fn (string $filename) => preg_match('/^MpMM extrato 5551999990101 \d{8} \d{6}\.pdf$/', $filename) === 1)
            ->assertJsonPath('messages.1.text', fn (string $text) => preg_match('/^🧾 Extrato dos últimos movimentos do seu cartão MpMM®\n\+55 \(51\) 99999-0101 \d{8} \d{6}$/u', $text) === 1)
            ->assertJsonPath('messages.2.text', 'Ainda não existem movimentos financeiros. O extrato mostra a abertura do seu Cartão, com a data e hora do primeiro acesso ao MpMM® e saldo R$ 0,00. Escolha o próximo passo:')
            ->assertJsonPath('messages.2.options.0.value', 'transferir')
            ->assertJsonPath('messages.2.options.1.value', 'gerar_qr_code')
            ->assertJsonPath('messages.2.options.2.value', 'voltar_menu');

        $session = ConversationSession::findOrFail($sessionId);
        $document = app(StatementPdfService::class)->generate($session);

        $this->assertFalse($document['has_financial_movements']);
        $this->assertSame($session->created_at->toISOString(), $document['opening_at']);
        $this->assertSame($session->created_at->toISOString(), $document['first_access_at']);
        $this->assertDatabaseMissing('movements', ['customer_id' => $customer->id]);
    }

    public function test_statement_actions_open_transfer_and_qr_code_flows(): void
    {
        Storage::fake('local');
        $customer = Customer::create(['phone' => '5551999990109', 'name' => 'Cliente Ações']);

        foreach (['transferir' => 'transfer.waiting_recipient', 'gerar_qr_code' => 'payment_qr_code.waiting_qr_type'] as $choice => $state) {
            $sessionId = $this->postJson('/api/conversations/start', [
                'external_user_id' => $customer->phone,
            ])->assertCreated()->json('session_id');
            $this->postJson("/api/conversations/{$sessionId}/messages", ['message' => 'extrato'])
                ->assertOk()->assertJsonPath('state', 'statement.waiting_statement_action');
            $this->postJson("/api/conversations/{$sessionId}/messages", ['message' => $choice])
                ->assertOk()->assertJsonPath('state', $state);
        }
    }

    public function test_statement_formats_financial_details_privacy_and_excludes_zero_movements(): void
    {
        $sender = Customer::create([
            'phone' => '5551999990103',
            'name' => 'Remetente cadastrado',
            'whatsapp_profile_name' => 'Nome atual posterior',
        ]);
        $customer = Customer::create([
            'phone' => '5551999990104',
            'name' => 'Nome do cadastro',
            'whatsapp_profile_name' => 'Nome do WhatsApp',
            'timezone' => 'America/Sao_Paulo',
            'balance' => 85,
            'limit' => 100,
        ]);

        Movement::create([
            'customer_id' => $customer->id,
            'event' => 'deposit.pending',
            'debit' => 0,
            'credit' => 0,
            'balance' => 0,
        ]);
        Movement::create([
            'customer_id' => $customer->id,
            'event' => 'deposit.cancelled',
            'debit' => 0,
            'credit' => 990,
            'balance' => 1090,
            'metadata' => ['cancelled_reason' => 'customer_changed_amount'],
        ]);
        Movement::create([
            'customer_id' => $customer->id,
            'event' => 'deposit.confirmed',
            'debit' => 0,
            'credit' => 100,
            'balance' => 100,
            'external_provider' => 'asaas',
            'external_operation_id' => 'pay_123',
            'metadata' => ['payment_method' => 'pix'],
        ])->forceFill(['created_at' => '2026-07-31 12:34:56'])->saveQuietly();
        Movement::create([
            'customer_id' => $customer->id,
            'origin_customer_id' => $sender->id,
            'event' => 'transfer.received',
            'debit' => 0,
            'credit' => 10,
            'balance' => 110,
            'metadata' => [
                'anonymous' => false,
                'sender_whatsapp_name' => 'Nome observado na transferência',
            ],
        ])->forceFill(['created_at' => '2026-08-01 13:01:02'])->saveQuietly();
        Movement::create([
            'customer_id' => $customer->id,
            'origin_customer_id' => $sender->id,
            'event' => 'transfer.received',
            'debit' => 0,
            'credit' => 5,
            'balance' => 115,
            'metadata' => ['anonymous' => true],
        ])->forceFill(['created_at' => '2026-08-01 14:02:03'])->saveQuietly();
        Movement::create([
            'customer_id' => $customer->id,
            'event' => 'redeem_pix.submitted',
            'debit' => 30,
            'credit' => 0,
            'balance' => 85,
            'external_operation_id' => 'transfer_123',
            'metadata' => ['pix_key' => '(51) 99999-0104'],
        ])->forceFill(['created_at' => '2026-08-02 15:03:04'])->saveQuietly();

        $data = app(StatementPdfService::class)->dataForCustomer($customer);
        $rows = $data['pages']->flatten(1);
        $descriptions = $rows->pluck('description');

        $this->assertSame('Nome do WhatsApp', $data['whatsappName']);
        $this->assertSame('+55 (51) 99999-0104', $data['formattedPhone']);
        $this->assertCount(4, $rows);
        $this->assertTrue($descriptions->contains('💰 (re)Carga via PIX · cadastro: pay_123 · R$ 100,00'));
        $this->assertTrue($descriptions->contains('↗️ Transferência recebida de +55 (51) 99999-0103 — Nome observado na transferência'));
        $this->assertTrue($descriptions->contains('↗️ Transferência privada recebida de +55 (51) *****-**03'));
        $this->assertTrue($descriptions->contains('💸 Resgate Pix para a 🔑 chave (51) 99999-0104 · R$ 30,00'));
        $this->assertFalse($descriptions->contains(fn (string $description) => str_contains($description, 'pendente')));
        $this->assertFalse($descriptions->contains(fn (string $description) => str_contains($description, 'cancel')));
        $this->assertSame('31/07/2026 09:34:56 -0300', $rows->last()['date']);
        $this->assertSame(0.0, $data['summary']['previous_balance']);
        $this->assertSame(115.0, $data['summary']['credits']);
        $this->assertSame(30.0, $data['summary']['debits']);
        $this->assertSame(4, $data['summary']['count']);

        $html = View::make('documents.statement', [...$data, 'logoData' => null])->render();
        $this->assertStringNotContainsString('Marca/Nome:', $html);
        $this->assertStringContainsString('<em>MyPhone MyMoney®</em>', $html);
        $this->assertStringContainsString('EXTRATO DOS ÚLTIMOS MOVIMENTOS DO CARTÃO 🎁', $html);
        $this->assertStringContainsString('Cartão 🎁 vinculado ao nº de WhatsApp <strong>+55 (51) 99999-0104</strong> e nome do membro no WhatsApp <strong>Nome do WhatsApp</strong>', $html);
        $this->assertStringContainsString('Crédito concedido', $html);
        $this->assertStringContainsString('💬0800 000 0000', $html);
        $this->assertStringContainsString('Página 1 de 1', $html);
        $this->assertStringContainsString('Total de entradas', $html);
        $this->assertStringContainsString('Total de saídas', $html);
        $this->assertStringContainsString('Movimentações', $html);
        $this->assertStringNotContainsString('Limite de crédito atualizado', $html);
    }

    public function test_statement_shows_auditable_reversals_and_nets_out_invalid_credits(): void
    {
        $customer = Customer::create(['phone' => '5551999990161', 'balance' => 0]);
        $deposit = Movement::create([
            'customer_id' => $customer->id,
            'event' => 'deposit.confirmed',
            'credit' => 1001,
            'debit' => 0,
            'balance' => 1001,
        ]);
        $transfer = Movement::create([
            'customer_id' => $customer->id,
            'event' => 'transfer.received',
            'credit' => 5000,
            'debit' => 0,
            'balance' => 6001,
        ]);
        Movement::create([
            'customer_id' => $customer->id,
            'event' => 'transfer.reversed',
            'credit' => 0,
            'debit' => 5000,
            'balance' => 1001,
            'metadata' => ['reverses_movement_id' => $transfer->id],
        ]);
        Movement::create([
            'customer_id' => $customer->id,
            'event' => 'deposit.reversed',
            'credit' => 0,
            'debit' => 1001,
            'balance' => 0,
            'metadata' => ['reverses_movement_id' => $deposit->id],
        ]);

        $service = app(StatementPdfService::class);
        $data = $service->dataForCustomer($customer);
        $descriptions = $data['pages']->flatten(1)->pluck('description');

        $this->assertTrue($descriptions->contains('↩️ Estorno de transferência recebida · lançamento #'.$transfer->id));
        $this->assertTrue($descriptions->contains('↩️ Estorno de (re)carga Pix não efetivada · lançamento #'.$deposit->id));
        $this->assertSame(0.0, $data['summary']['deposits']);
        $this->assertSame(0.0, $data['summary']['transfers_received']);
        $this->assertSame(4, $service->dataForAdminCustomer($customer)['movements']->count());
    }

    public function test_statement_masks_counterparty_numbers_for_sent_and_received_private_transfers(): void
    {
        $sender = Customer::create(['phone' => '5551999990123', 'name' => 'Nome secreto']);
        $customer = Customer::create(['phone' => '5551999990456', 'balance' => 5]);

        Movement::create([
            'customer_id' => $customer->id,
            'origin_customer_id' => $sender->id,
            'event' => 'transfer.received',
            'credit' => 10,
            'debit' => 0,
            'balance' => 10,
            'metadata' => ['anonymous' => true, 'sender_whatsapp_name' => 'Nome secreto'],
        ]);
        Movement::create([
            'customer_id' => $customer->id,
            'event' => 'transfer.sent',
            'credit' => 0,
            'debit' => 5,
            'balance' => 5,
            'metadata' => [
                'anonymous' => true,
                'recipient' => '5551900000008',
                'recipient_whatsapp_name' => 'Outro nome secreto',
            ],
        ]);

        $descriptions = app(StatementPdfService::class)
            ->dataForCustomer($customer)['pages']
            ->flatten(1)
            ->pluck('description');

        $this->assertTrue($descriptions->contains('↗️ Transferência privada recebida de +55 (51) *****-**23'));
        $this->assertTrue($descriptions->contains('↘️ Transferência privada enviada para +55 (51) *****-**99'));
        $this->assertFalse($descriptions->contains(fn (string $description) => str_contains($description, 'Nome secreto')));

        $adminRows = app(StatementPdfService::class)->dataForAdminCustomer($customer)['pages']->flatten(1);

        $this->assertTrue($adminRows->every(fn (array $row) => $row['is_private']));
        $this->assertFalse($adminRows->pluck('description')->contains(
            fn (string $description) => str_contains($description, 'Nome secreto')
                || str_contains($description, '5551999990123')
                || str_contains($description, '5551900000008'),
        ));
    }

    public function test_first_usage_is_the_earliest_session_or_movement_not_customer_creation(): void
    {
        $customer = Customer::create(['phone' => '5551999990789']);
        $customer->forceFill(['created_at' => '2026-08-03 12:00:00'])->saveQuietly();

        $session = ConversationSession::create([
            'customer_id' => $customer->id,
            'external_user_id' => $customer->phone,
            'context' => [],
        ]);
        $session->forceFill(['created_at' => '2026-08-02 12:00:00'])->saveQuietly();

        $movement = Movement::create([
            'customer_id' => $customer->id,
            'event' => 'deposit.pending',
            'credit' => 0,
            'debit' => 0,
            'balance' => 0,
        ]);
        $movement->forceFill(['created_at' => '2026-08-01 12:00:00'])->saveQuietly();

        $firstUsageAt = app(StatementPdfService::class)->firstUsageAt($customer);

        $this->assertSame('2026-08-01 12:00:00', $firstUsageAt->format('Y-m-d H:i:s'));
    }

    public function test_admin_statement_only_contains_front_office_movements_in_descending_order(): void
    {
        $customer = Customer::create(['phone' => '5551999990790', 'balance' => 30]);

        $oldTransfer = Movement::create([
            'customer_id' => $customer->id,
            'event' => 'transfer.received',
            'credit' => 10,
            'debit' => 0,
            'balance' => 10,
        ]);
        $oldTransfer->forceFill(['created_at' => '2026-08-01 10:00:00'])->saveQuietly();

        $backoffice = Movement::create([
            'customer_id' => $customer->id,
            'event' => 'credit_limit.updated',
            'credit' => 0,
            'debit' => 0,
            'balance' => 10,
        ]);
        $backoffice->forceFill(['created_at' => '2026-08-02 10:00:00'])->saveQuietly();

        $newDeposit = Movement::create([
            'customer_id' => $customer->id,
            'event' => 'deposit.confirmed',
            'credit' => 20,
            'debit' => 0,
            'balance' => 30,
        ]);
        $newDeposit->forceFill(['created_at' => '2026-08-03 10:00:00'])->saveQuietly();

        $data = app(StatementPdfService::class)->dataForAdminCustomer($customer);
        $rows = $data['pages']->flatten(1);

        $this->assertSame([$newDeposit->id, $oldTransfer->id], $rows->pluck('id')->all());
        $this->assertSame(2, $data['statistics']['movement_count']);
        $this->assertArrayNotHasKey('credit_limit.updated', $data['statistics']['events']);
        $this->assertFalse($data['movements']->contains('id', $backoffice->id));

        $html = View::make('documents.admin-statement', [
            ...$data,
            'logoData' => null,
            'generatedAt' => now(),
        ])->render();
        $this->assertStringNotContainsString('credit_limit.updated', $html);
        $this->assertStringNotContainsString('Provedor / ID externo', $html);
        $this->assertStringNotContainsString('<th class="event">Evento</th>', $html);
    }

    public function test_statement_is_limited_to_four_numbered_pages(): void
    {
        $customer = Customer::create([
            'phone' => '5551999990105',
            'name' => 'Cliente Longo',
            'balance' => 80,
        ]);

        foreach (range(1, 80) as $index) {
            Movement::create([
                'customer_id' => $customer->id,
                'event' => 'deposit.confirmed',
                'debit' => 0,
                'credit' => 1,
                'balance' => $index,
                'external_operation_id' => 'pay_'.$index,
                'metadata' => ['payment_method' => 'pix'],
                'created_at' => now()->subDays($index),
            ]);
        }
        Movement::create([
            'customer_id' => $customer->id,
            'event' => 'transfer.sent',
            'debit' => 7,
            'balance' => -7,
        ])->forceFill(['created_at' => now()->subDays(100)])->saveQuietly();

        $data = app(StatementPdfService::class)->dataForCustomer($customer);
        $html = View::make('documents.statement', [...$data, 'logoData' => null])->render();

        $this->assertCount(4, $data['pages']);
        $this->assertCount(74, $data['pages']->flatten(1));
        $this->assertSame(81, $data['summary']['count']);
        $this->assertSame(80.0, $data['summary']['credits']);
        $this->assertSame(7.0, $data['summary']['debits']);
        $this->assertSame(7.0, $data['summary']['transfers_sent']);
        $this->assertFalse($data['pages']->flatten(1)->pluck('amount')->contains(-7.0));
        $this->assertSame(4, substr_count($html, 'EXTRATO DOS ÚLTIMOS MOVIMENTOS DO CARTÃO 🎁'));
        $this->assertStringContainsString('Página 4 de 4', $html);

        Storage::fake('local');
        $document = app(StatementPdfService::class)->generateForCustomer($customer);
        $this->assertSame(4, preg_match_all('/\/Type\s*\/Page\b/', Storage::disk('local')->get('statements/'.$document['filename'])));
    }

    public function test_statement_labels_card_loads_and_installment_transfers(): void
    {
        $sender = Customer::create(['phone' => '5551999990181']);
        $recipient = Customer::create(['phone' => '5551999990182']);
        $proposal = InstallmentProposal::create([
            'sender_customer_id' => $sender->id,
            'recipient_customer_id' => $recipient->id,
            'amount' => 30,
            'installments' => 3,
        ]);

        foreach (['pix' => 'PIX', 'boleto' => 'boleto', 'debit_card' => 'cartão de débito', 'credit_card' => 'cartão de crédito'] as $method => $label) {
            Movement::create([
                'customer_id' => $recipient->id,
                'event' => 'deposit.confirmed',
                'credit' => 10,
                'balance' => 10,
                'metadata' => ['payment_method' => $method],
            ]);
        }
        Movement::create([
            'customer_id' => $recipient->id,
            'origin_customer_id' => $sender->id,
            'event' => 'transfer.received',
            'credit' => 10,
            'balance' => 20,
            'metadata' => ['installment_proposal_id' => $proposal->id, 'installment_number' => 2],
        ]);
        Movement::create([
            'customer_id' => $sender->id,
            'event' => 'transfer.sent',
            'debit' => 10,
            'balance' => -10,
            'metadata' => [
                'recipient' => $recipient->phone,
                'installment_proposal_id' => $proposal->id,
                'installment_number' => 2,
            ],
        ]);

        $descriptions = app(StatementPdfService::class)->dataForCustomer($recipient)['pages']
            ->flatten(1)->pluck('description');
        foreach (['PIX', 'boleto', 'cartão de débito', 'cartão de crédito'] as $label) {
            $this->assertTrue($descriptions->contains(fn (string $description) => str_starts_with($description, "💰 (re)Carga via {$label}")));
        }
        $this->assertTrue($descriptions->contains(fn (string $description) => str_starts_with($description, '↗️ Transferência recebida')
            && str_ends_with($description, 'Parcela 2/3')));
        $this->assertTrue(app(StatementPdfService::class)->dataForCustomer($sender)['pages']
            ->flatten(1)->pluck('description')->contains(fn (string $description) => str_starts_with($description, '↘️ Transferência enviada')
                && str_ends_with($description, 'Parcela 2/3')));
    }

    public function test_whatsapp_uploads_the_pdf_and_sends_it_by_media_id(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('statements/test.pdf', '%PDF-test');
        config([
            'services.whatsapp.phone_number_id' => 'phone-123',
            'services.whatsapp.access_token' => 'token-123',
            'services.whatsapp.api_version' => 'v23.0',
        ]);
        Http::fake([
            'https://graph.facebook.com/v23.0/phone-123/media' => Http::response(['id' => 'media-123']),
            'https://graph.facebook.com/v23.0/phone-123/messages' => Http::response(['messages' => [['id' => 'wamid.123']]]),
        ]);

        $delivery = app(WhatsappClient::class)->sendDocument(
            '5551999990102',
            Storage::disk('local')->path('statements/test.pdf'),
            'Extrato MpMM.pdf',
            'Extrato do seu Cartão MpMM',
        );

        $this->assertSame('sent', $delivery['status']);
        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v23.0/phone-123/media');
        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v23.0/phone-123/messages'
            && $request['document']['id'] === 'media-123');
    }
}
