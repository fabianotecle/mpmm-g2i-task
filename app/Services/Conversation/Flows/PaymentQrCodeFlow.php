<?php

namespace App\Services\Conversation\Flows;

use App\Models\ConversationSession;
use App\Models\Customer;
use App\Services\Conversation\Contracts\ConversationFlow;
use App\Services\Conversation\ConversationResponse;
use App\Services\Conversation\Flows\Concerns\ParsesConversationInput;
use App\Services\Phone\PhoneNumberFormatter;
use App\Services\QrCodes\PaymentQrCodeService;

class PaymentQrCodeFlow implements ConversationFlow
{
    use ParsesConversationInput;

    public function __construct(
        private readonly PaymentQrCodeService $codes,
        private readonly PhoneNumberFormatter $phoneFormatter,
    ) {}

    public function enter(ConversationSession $session): array
    {
        $session->forceFill(['current_step' => 'waiting_qr_type', 'context' => []])->save();

        return ConversationResponse::make([
            ConversationResponse::options('Qual QR Code deseja gerar?', $this->typeOptions(), '🔳 Gerar QRcode'),
        ], ['type' => 'option', 'field' => 'qr_code_type']);
    }

    public function handle(ConversationSession $session, string $message): array
    {
        if ($this->isCancel($message) || str_contains($this->normalized($message), 'menu')) {
            return ConversationResponse::make([], null, null, 'main_menu');
        }

        return match ($session->current_step) {
            'waiting_qr_type' => $this->captureType($session, $message),
            'waiting_qr_amount' => $this->captureAmount($session, $message),
            'waiting_qr_result_action' => $this->handleResultAction($session, $message),
            default => $this->enter($session),
        };
    }

    private function captureType(ConversationSession $session, string $message): array
    {
        $choice = $this->normalized($message);

        if ($choice === 'transferencia_valor_livre'
            || str_contains($choice, 'livre')
            || str_contains($choice, 'sem_valor')
            || (str_contains($choice, 'saldo mpmm') && ! str_contains($choice, 'informando'))) {
            return $this->generate($session, 'transfer_open');
        }

        $type = $choice === 'recarga_pix' || str_contains($choice, 'recarga') || str_contains($choice, 'pix')
            ? 'cash_in'
            : ($choice === 'transferencia_com_valor'
                || str_contains($choice, 'informando o valor')
                || str_contains($choice, 'transfer')
                || str_contains($choice, 'cobran') ? 'transfer_fixed' : null);

        if ($type === null) {
            return ConversationResponse::make([
                ConversationResponse::options('Escolha uma opção para gerar o QR Code.', $this->typeOptions(), '🔳 Gerar QRcode'),
            ], ['type' => 'option', 'field' => 'qr_code_type']);
        }

        $session->forceFill(['current_step' => 'waiting_qr_amount', 'context' => ['qr_code_type' => $type]])->save();

        return ConversationResponse::make([
            ConversationResponse::text('Informe o valor do QR Code.'),
        ], ['type' => 'money', 'field' => 'qr_code_amount', 'min' => 0.01, 'max' => 150000]);
    }

    private function captureAmount(ConversationSession $session, string $message): array
    {
        $amount = $this->moneyFromMessage($message);

        if ($amount === null || $amount <= 0 || $amount > 150000) {
            return ConversationResponse::make([
                ConversationResponse::text('Informe um valor entre R$ 0,01 e R$ 150.000,00.'),
            ], ['type' => 'money', 'field' => 'qr_code_amount', 'min' => 0.01, 'max' => 150000]);
        }

        return $this->generate($session, (string) (($session->context ?? [])['qr_code_type'] ?? 'transfer_fixed'), $amount);
    }

    private function generate(ConversationSession $session, string $type, ?float $amount = null): array
    {
        $session->loadMissing('customer');
        $qr = $this->codes->create($session->customer, $type, $amount);
        $formattedAmount = $amount === null ? 'valor livre' : 'R$ '.number_format($amount, 2, ',', '.');
        $message = "QR Code gerado para {$formattedAmount}. Compartilhe a imagem para receber o pagamento.";

        if ($type === 'cash_in') {
            $recipient = $this->recipientDetails($session->customer);
            $formattedPixAmount = number_format($amount, 2, ',', '.');
            $message = "💠 QR Code gerado para (re)carga Pix de *R$* {$formattedPixAmount}\n\n"
                ."Compartilhe a imagem para\n{$recipient}\n"
                ."*receber a (re)carga Pix aqui*. 💰\n\n"
                ."Saiba mais em:\n*MyPhone MyMoney®*\n💬 0800 000 0000";
        } elseif ($type === 'transfer_fixed') {
            $recipient = $this->recipientDetails($session->customer);
            $formattedTransferAmount = number_format($amount, 2, ',', '.');
            $message = "💰QR Code gerado para *R$* {$formattedTransferAmount}.\n\n"
                ."Compartilhe esta imagem para\n{$recipient}\n"
                ."*receber a transferência*.\n\n"
                ."*Saiba mais em:*\n*MyPhone MyMoney®*\n💬 0800 000 0000";
        } elseif ($type === 'transfer_open') {
            $recipient = $this->recipientDetails($session->customer);
            $message = "💳 QR Code gerado para *valor livre*.\n\n"
                ."Compartilhe esta imagem para\n{$recipient}\n"
                ."*receber a transferência*.\n\n"
                ."Saiba mais em:\n*MyPhone MyMoney®*\n💬 0800 000 0000";
        }

        $session->forceFill([
            'current_step' => 'waiting_qr_result_action',
            'context' => ['qr_code_type' => $type],
        ])->save();

        return ConversationResponse::make([
            [
                'type' => 'image',
                'text' => $message,
                'url' => route('qr-codes.png', ['token' => $qr['token']]),
            ],
            [
                ...ConversationResponse::options('O que deseja fazer agora?', $this->resultOptions(), 'Escolher opção'),
                'force_list' => true,
            ],
        ], ['type' => 'option', 'field' => 'qr_code_result_action']);
    }

    private function handleResultAction(ConversationSession $session, string $message): array
    {
        if (str_contains($this->normalized($message), 'outro')) {
            return $this->enter($session);
        }

        $session->forceFill(['current_step' => 'waiting_qr_type', 'context' => []])->save();

        return $this->captureType($session, $message);
    }

    private function recipientDetails(Customer $customer): string
    {
        $whatsappName = trim((string) $customer->whatsapp_profile_name);

        if ($whatsappName === '') {
            $whatsappName = trim((string) $customer->whatsapp_username);
        }

        return $this->phoneFormatter->international($customer->phone)
            .($whatsappName !== '' ? "\n{$whatsappName}" : '');
    }

    private function typeOptions(): array
    {
        return [
            ['label' => '🏡 Voltar ao Menu', 'value' => 'voltar_menu'],
            [
                'label' => '💳 Transfere-me (Livre)',
                'description' => 'Cobrar com MpMM® (SEM valor definido)',
                'value' => 'transferencia_valor_livre',
            ],
            [
                'label' => '💰Transfere-me o Valor',
                'description' => 'Cobrar com MpMM® (COM valor R$)',
                'value' => 'transferencia_com_valor',
            ],
            [
                'label' => '💠 (re)Carga Pix',
                'description' => 'Carrega direto meu MpMM®',
                'value' => 'recarga_pix',
            ],
        ];
    }

    private function resultOptions(): array
    {
        $options = $this->typeOptions();

        return [
            $options[0],
            ['label' => '🔳 Gerar outro QR Code', 'value' => 'gerar_outro_qr_code'],
            ...array_slice($options, 1),
        ];
    }
}
