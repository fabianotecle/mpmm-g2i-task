<?php

namespace Tests\Unit;

use App\Services\Conversation\Flows\Concerns\ParsesConversationInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ParsesConversationInputTest extends TestCase
{
    #[DataProvider('moneyFormats')]
    public function test_it_parses_brazilian_and_english_money_formats(string $input, float $expected): void
    {
        $parser = new class
        {
            use ParsesConversationInput;

            public function money(string $value): ?float
            {
                return $this->moneyFromMessage($value);
            }
        };

        $this->assertSame($expected, $parser->money($input));
    }

    public static function moneyFormats(): array
    {
        return [
            'english decimal' => ['56.00', 56.0],
            'brazilian decimal' => ['56,00', 56.0],
            'english thousands' => ['1,500.00', 1500.0],
            'brazilian thousands' => ['1.500,00', 1500.0],
            'english thousands without cents' => ['1,500', 1500.0],
            'brazilian thousands without cents' => ['1.500', 1500.0],
            'space thousands' => ['1 500', 1500.0],
            'space thousands with english cents' => ['1 500.00', 1500.0],
            'space thousands with brazilian cents' => ['1 500,00', 1500.0],
            'currency prefix' => ['R$ 1.500,00', 1500.0],
            'currency inside forwarded text' => ['Esse é o preço da tua alforria, Pessoa Teste: R$ 369,50. 🙂', 369.5],
            'currency ignores unrelated numbers' => ['Ligue 0800 000 0000 e transfira R$ 369,50.', 369.5],
            'single amount inside text' => ['Pode transferir 369,50 para ela', 369.5],
            'whole amount' => ['1500', 1500.0],
            'zero' => ['0', 0.0],
        ];
    }

    public function test_it_rejects_text_with_multiple_unidentified_numbers(): void
    {
        $parser = new class
        {
            use ParsesConversationInput;

            public function money(string $value): ?float
            {
                return $this->moneyFromMessage($value);
            }
        };

        $this->assertNull($parser->money('Tenho 2 contas e quero transferir 100'));
    }
}
