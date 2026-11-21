<?php

namespace App\Services\Conversation\Flows;

use App\Models\ConversationSession;
use App\Models\Movement;
use App\Services\Conversation\Contracts\ConversationFlow;
use App\Services\Conversation\ConversationResponse;
use App\Services\Conversation\Flows\Concerns\ParsesConversationInput;
use App\Services\Documents\StatementPdfService;
use App\Services\Phone\PhoneNumberFormatter;

class StatementFlow implements ConversationFlow
{
    use ParsesConversationInput;

    public function __construct(
        private readonly StatementPdfService $statementPdf,
        private readonly PhoneNumberFormatter $phoneFormatter,
    ) {}

    public function enter(ConversationSession $session): array
    {
        $session->forceFill(['current_step' => 'waiting_statement_action'])->save();
        $hasMovements = Movement::where('customer_id', $session->customer_id)->exists();
        $message = $hasMovements
            ? 'Escolha o próximo passo:'
            : 'Ainda não existem movimentos financeiros. O extrato mostra a abertura do seu Cartão, com a data e hora do primeiro acesso ao MpMM® e saldo R$ 0,00. Escolha o próximo passo:';

        return ConversationResponse::make([
            ConversationResponse::text('⌚ Um momento, por favor... estamos processando seu pedido.'),
            $this->statementMediaMessage($session),
            ConversationResponse::options($message, [
                ['label' => '↗️ Transferir', 'value' => 'transferir'],
                ['label' => '🔳 Gerar QR Code', 'value' => 'gerar_qr_code'],
                ['label' => '🏡 Voltar ao Menu', 'value' => 'voltar_menu'],
            ]),
        ], [
            'type' => 'option',
            'field' => 'statement_action',
        ]);
    }

    public function handle(ConversationSession $session, string $message): array
    {
        $choice = $this->normalized($message);
        if ($choice === 'transferir' || str_contains($choice, 'transferir')) {
            return ConversationResponse::make([], null, null, 'transfer');
        }
        if ($choice === 'gerar_qr_code' || str_contains($choice, 'qr code')) {
            return ConversationResponse::make([], null, null, 'payment_qr_code');
        }
        if (str_contains($choice, 'voltar') || str_contains($choice, 'menu')) {
            return ConversationResponse::make([], null, null, 'main_menu');
        }

        return $this->enter($session);
    }

    private function statementMediaMessage(ConversationSession $session): array
    {
        $document = $this->statementPdf->generate($session);
        $session->loadMissing('customer');
        preg_match('/(\d{8} \d{6})\.pdf$/', $document['download_filename'], $matches);
        $phone = $this->phoneFormatter->international($session->customer->phone);

        return [
            'type' => 'document',
            'text' => "🧾 Extrato dos últimos movimentos do seu cartão MpMM®\n{$phone} ".($matches[1] ?? ''),
            'filename' => $document['download_filename'],
            'url' => $document['url'],
            'path' => $document['path'],
        ];
    }

    private function statementText(ConversationSession $session): string
    {
        $session->loadMissing('customer');
        $timezone = $session->customer?->timezone ?: 'UTC';

        $movements = Movement::where('customer_id', $session->customer_id)
            ->latest()
            ->limit(10)
            ->get();

        if ($movements->isEmpty()) {
            return 'Nenhuma movimentação encontrada.';
        }

        return $movements
            ->map(fn (Movement $movement) => $movement->created_at?->copy()->timezone($timezone)->format('d/m/Y H:i O').' | '.($movement->fund_type === 'credit' ? '💳 Crédito' : '💰 Débito').' | '.$movement->event.' | R$ '.$this->formatMoney((float) $movement->credit + (float) $movement->rotative_credit - (float) $movement->debit - (float) $movement->rotative_debit).' | Saldo R$ '.$this->formatMoney((float) $movement->balance + (float) $movement->rotative_balance))
            ->implode("\n");
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 2, ',', '.');
    }
}
