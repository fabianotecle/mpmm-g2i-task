<?php

namespace App\Services\Conversation\Flows;

use App\Models\ConversationSession;
use App\Models\Customer;
use App\Services\Conversation\Contracts\ConversationFlow;
use App\Services\Conversation\ConversationResponse;
use App\Services\Conversation\Flows\Concerns\ParsesConversationInput;

class MainMenuFlow implements ConversationFlow
{
    use ParsesConversationInput;

    public function enter(ConversationSession $session): array
    {
        $session->loadMissing('customer');
        $customer = $session->customer;

        $session->forceFill([
            'current_step' => 'home',
            'context' => [],
        ])->save();

        return ConversationResponse::make([
            [
                'type' => 'options',
                'text' => $this->welcomeText($customer),
                'header_image_url' => config('services.whatsapp.welcome_image_url'),
                'options' => $this->primaryOptions(),
            ],
            ConversationResponse::options('E ainda…', $this->secondaryOptions()),
        ], null, $this->options());
    }

    public function handle(ConversationSession $session, string $message): array
    {
        $choice = $this->normalized($message);

        return match (true) {
            str_contains($choice, 'deposit') || str_contains($choice, 'carga') || $choice === '1' => ConversationResponse::make([], null, null, 'deposit'),
            str_contains($choice, 'transfer') || $choice === '2' => ConversationResponse::make([], null, null, 'transfer'),
            str_contains($choice, 'parcelad') && $session->customer => ConversationResponse::make([], null, null, 'installment'),
            str_contains($choice, 'resgate') || str_contains($choice, 'pix') || $choice === '3' => ConversationResponse::make([], null, null, 'redeem_pix'),
            str_contains($choice, 'extrato') || $choice === '4' => ConversationResponse::make([], null, null, 'statement'),
            str_contains($choice, 'convid') || $choice === '5' => ConversationResponse::make([], null, null, 'invite'),
            str_contains($choice, 'qr') || str_contains($choice, 'cobrar') || $choice === '6' => ConversationResponse::make([], null, null, 'payment_qr_code'),
            default => ConversationResponse::make([
                ConversationResponse::text('Não entendi essa opção. Escolha uma opção do menu.'),
                [
                    'type' => 'options',
                    'text' => $this->welcomeText($session->customer),
                    'header_image_url' => config('services.whatsapp.welcome_image_url'),
                    'options' => $this->primaryOptions(),
                ],
                ConversationResponse::options('E ainda…', $this->secondaryOptions()),
            ], null, $this->options()),
        };
    }

    private function welcomeText($customer): string
    {
        $name = $customer?->whatsappFirstName() ?: 'membro';
        $available = number_format(app(\App\Services\RotativeWallet\RotativeCreditService::class)
            ->availableToTransfer($customer), 2, ',', '.');

        return "Olá {$name}, seja muito bem-vindo(a) ao seu Cartão 🎁\n\n"
            ."*_MyPhone MyMoney®_*\n\n"
            ."*_Meu Celular Meu Dinheiro®_*\n\n"
            ."*💳 Disponível: R$ {$available}*\n\n"
            .'Como posso te ajudar hoje?';
    }

    private function options(): array
    {
        return [
            ...$this->primaryOptions(),
            ...$this->secondaryOptions(),
        ];
    }

    private function primaryOptions(): array
    {
        return [
            ['label' => '💰(re)Carga', 'value' => 'depositar'],
            ['label' => '↗️ Transferir', 'value' => 'transferir'],
            ['label' => '💸 Resgate Pix', 'value' => 'resgate_pix'],
        ];
    }

    private function secondaryOptions(): array
    {
        $options = [
            ['label' => '🧾 Ver Extrato', 'value' => 'extrato'],
            ['label' => '🙋 Convidar', 'value' => 'convidar'],
            ['label' => '🔳 Gerar QR Code', 'value' => 'gerar_qr_code'],
        ];

        return $options;
    }
}
