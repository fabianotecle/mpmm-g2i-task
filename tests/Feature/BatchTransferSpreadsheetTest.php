<?php

namespace Tests\Feature;

use App\Models\ConversationSession;
use App\Models\Customer;
use App\Models\Movement;
use App\Services\Conversation\ConversationEngine;
use App\Services\Transfers\BatchTransferSpreadsheet;
use App\Services\WhatsApp\WhatsappWebhookParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class BatchTransferSpreadsheetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_the_iraja_batch_spreadsheet_layout(): void
    {
        $rows = app(BatchTransferSpreadsheet::class)->parse($this->spreadsheet());

        $this->assertSame([
            ['ddi' => '55', 'phone' => '51900000006', 'amount' => 1.0],
            ['ddi' => '55', 'phone' => '51900000010', 'amount' => 1.0],
        ], $rows);
    }

    public function test_imports_confirms_and_executes_a_batch_received_as_whatsapp_document(): void
    {
        config([
            'services.whatsapp.access_token' => 'test-token',
            'services.whatsapp.api_version' => 'v23.0',
        ]);
        Http::fake([
            'https://graph.facebook.com/v23.0/media-batch-1' => Http::response([
                'url' => 'https://lookaside.fbsbx.com/batch.xlsx',
            ]),
            'https://lookaside.fbsbx.com/batch.xlsx' => Http::response($this->spreadsheet(), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]),
        ]);

        Customer::create([
            'phone' => '5551999990000',
            'balance' => 2,
            'limit' => 0,
            'status' => 'active',
            'opt_in' => true,
        ]);

        $engine = app(ConversationEngine::class);
        $started = $engine->start('api', '5551999990000');
        $session = ConversationSession::findOrFail($started['session_id']);
        $engine->receive($session, 'transferir');

        $preview = $engine->receive($session->refresh(), 'MpMM - Pagamentos em Lote.xlsx', [
            'raw' => [
                'type' => 'document',
                'document' => [
                    'id' => 'media-batch-1',
                    'filename' => 'MpMM - Pagamentos em Lote.xlsx',
                    'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ],
            ],
        ]);

        $this->assertSame('transfer.waiting_batch_confirmation', $preview['state']);
        $this->assertStringContainsString('Destinatários: 2', $preview['messages'][0]['text']);
        $this->assertStringContainsString('Valor total: R$ 2,00', $preview['messages'][0]['text']);
        $this->assertDatabaseCount('movements', 0);

        $result = $engine->receive($session->refresh(), 'confirmar');

        $this->assertSame('main_menu.home', $result['state']);
        $this->assertStringContainsString('Transferência em lote concluída', $result['messages'][0]['text']);
        $this->assertCount(2, $result['notifications']);
        $this->assertTrue($result['notifications'][0]['send_start_template']);
        $this->assertTrue($result['notifications'][1]['send_start_template']);
        $this->assertSame(0.0, (float) Customer::where('phone', '5551999990000')->value('balance'));
        $this->assertSame(1.0, (float) Customer::where('phone', '5551900000006')->value('balance'));
        $this->assertSame(1.0, (float) Customer::where('phone', '5551900000010')->value('balance'));
        $this->assertSame(2, Movement::where('event', 'transfer.sent')->count());
        $this->assertSame(2, Movement::where('event', 'transfer.received')->count());
        $this->assertSame(1, Movement::whereNotNull('metadata->batch_transfer_id')->count() > 0 ? 1 : 0);
    }

    public function test_whatsapp_parser_keeps_an_incoming_xlsx_document(): void
    {
        $messages = app(WhatsappWebhookParser::class)->messages([
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'contacts' => [['wa_id' => '5551999990000']],
                        'messages' => [[
                            'id' => 'wamid.batch-document',
                            'from' => '5551999990000',
                            'type' => 'document',
                            'document' => [
                                'id' => 'media-batch-1',
                                'filename' => 'MpMM - Pagamentos em Lote.xlsx',
                                'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            ],
                        ]],
                    ],
                ]],
            ]],
        ]);

        $this->assertCount(1, $messages);
        $this->assertSame('MpMM - Pagamentos em Lote.xlsx', $messages[0]->text);
        $this->assertSame('media-batch-1', $messages[0]->raw['document']['id']);
    }

    private function spreadsheet(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mpmm-test-xlsx-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>');
        $zip->addFromString('xl/tables/table1.xml', '<?xml version="1.0"?><table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" name="destinatarios" displayName="destinatarios" ref="A1:C3"><tableColumns count="3"><tableColumn id="1" name="DDI"/><tableColumn id="2" name="Telefone"/><tableColumn id="3" name="Valor"/></tableColumns></table>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="1"><c r="A1" t="inlineStr"><is><t>DDI</t></is></c><c r="B1" t="inlineStr"><is><t>Telefone</t></is></c><c r="C1" t="inlineStr"><is><t>Valor</t></is></c></row>'
            .'<row r="2"><c r="A2"><v>55</v></c><c r="B2"><v>51900000006</v></c><c r="C2"><v>1</v></c></row>'
            .'<row r="3"><c r="A3"><v>55</v></c><c r="B3"><v>51900000010</v></c><c r="C3"><v>1</v></c></row>'
            .'</sheetData></worksheet>');
        $zip->close();
        $contents = file_get_contents($path);
        @unlink($path);

        return $contents;
    }
}
