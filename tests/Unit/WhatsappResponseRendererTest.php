<?php

namespace Tests\Unit;

use App\Services\WhatsApp\WhatsappResponseRenderer;
use PHPUnit\Framework\TestCase;

class WhatsappResponseRendererTest extends TestCase
{
    public function test_truncates_reply_button_titles_to_whatsapp_limit(): void
    {
        $payloads = (new WhatsappResponseRenderer)->render([
            'messages' => [[
                'type' => 'options',
                'text' => 'Escolha uma opção',
                'options' => [
                    ['label' => 'Voltar ao menu inicial agora', 'value' => 'voltar_menu'],
                ],
            ]],
        ]);

        $title = $payloads[0]['interactive']['action']['buttons'][0]['reply']['title'];

        $this->assertSame('Voltar ao menu inici', $title);
        $this->assertSame('voltar_menu', $payloads[0]['interactive']['action']['buttons'][0]['reply']['id']);
    }

    public function test_uses_the_custom_list_button_label(): void
    {
        $payloads = (new WhatsappResponseRenderer)->render(['messages' => [[
            'type' => 'options', 'text' => 'Confirma?', 'list_button' => 'Escolher opção',
            'options' => array_map(fn ($number) => ['label' => "Opção {$number}", 'value' => $number], range(1, 4)),
        ]]]);

        $this->assertSame('Escolher opção', $payloads[0]['interactive']['action']['button']);
    }

    public function test_includes_an_option_description_in_a_list_row(): void
    {
        $payloads = (new WhatsappResponseRenderer)->render(['messages' => [[
            'type' => 'options',
            'text' => 'Escolha uma opção',
            'options' => [
                ['label' => 'Opção 1', 'value' => 'one'],
                ['label' => 'Opção 2', 'value' => 'two'],
                ['label' => 'Opção 3', 'value' => 'three'],
                ['label' => 'Saldo MPMM (livre)', 'description' => 'Cobrar com QR de Saldo MPMM.', 'value' => 'open'],
            ],
        ]]]);

        $row = $payloads[0]['interactive']['action']['sections'][0]['rows'][3];

        $this->assertSame('Saldo MPMM (livre)', $row['title']);
        $this->assertSame('Cobrar com QR de Saldo MPMM.', $row['description']);
    }
}
