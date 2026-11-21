<?php

namespace App\Services\Conversation\Flows;

use App\Models\Customer;
use App\Models\ConversationSession;
use App\Services\Conversation\Contracts\ConversationFlow;
use App\Services\Conversation\ConversationResponse;
use App\Services\Conversation\Flows\Concerns\ParsesConversationInput;
use App\Services\Invites\InviteTracker;
use Illuminate\Support\Str;

class InviteFlow implements ConversationFlow
{
    use ParsesConversationInput;

    public function __construct(private readonly InviteTracker $invites)
    {
    }

    public function enter(ConversationSession $session): array
    {
        $session->forceFill(['current_step' => 'waiting_invite_action'])->save();
        $session->loadMissing('customer');

        $inviteCode = $this->inviteCode($session);

        if ($session->customer) {
            $this->invites->recordLinkGenerated($session->customer, $inviteCode, [
                'conversation_session_id' => $session->id,
            ]);
        }

        return ConversationResponse::make([
            ConversationResponse::options(
                "Obrigado por convidar *amigos, colegas e familiares* para a comunidade do cartão 🎁\n"
                ."*_MyPhone MyMoney®_*\n"
                ."*_Meu Celular Meu Dinheiro®_*\n\n"
                ."Este convite é seu, exclusivo e permanente. Cada aceite fica ligado a você.\n\n"
                ."Seu crédito aumenta. Acumule:\n\n"
                ."- R$1 com cada convite aceito.\n"
                ."- R$10 com cada convite aceite com carga de R$50.\n\n"
                ."Você será informado quando alguém aceitar e quando fizer a primeira carga.\n\n"
                ."*Quanto mais convidar mais crédito para você.*\n\n"
                ."👇Compartilhe a mensagem abaixo com amigos, colegas, família e grupos!",
                [
                    ['label' => 'Convidar Agora', 'value' => 'convidar_agora'],
                    ['label' => '🏡 Voltar ao Menu', 'value' => 'voltar_menu'],
                ],
            ),
        ], [
            'type' => 'option',
            'field' => 'invite_action',
        ]);
    }

    public function handle(ConversationSession $session, string $message): array
    {
        $choice = $this->normalized($message);

        if (str_contains($choice, 'voltar') || str_contains($choice, 'menu')) {
            return ConversationResponse::make([], null, null, 'main_menu');
        }

        if (str_contains($choice, 'convidar')) {
            return $this->sharePermanentInvitation($session);
        }

        return $this->enter($session);
    }

    private function sharePermanentInvitation(ConversationSession $session): array
    {
        $session->loadMissing('customer');
        if (! $session->customer) {
            return $this->enter($session);
        }

        $invitation = $this->invitationText($this->inviteCode($session));
        $session->forceFill(['current_step' => 'waiting_invite_action'])->save();

        return ConversationResponse::make([
            ConversationResponse::text($invitation),
            [
                'type' => 'cta_url',
                'text' => 'Este convite é seu, único e permanente. Compartilhe com membros ou grupos; cada aceitação ficará ligada a você.',
                'label' => 'Reencaminhar',
                'url' => 'https://wa.me/?text='.rawurlencode($invitation),
            ],
            ConversationResponse::options(
                'Você será avisado quando alguém aceitar e quando fizer a primeira carga.',
                [['label' => '🏡 Voltar ao Menu', 'value' => 'voltar_menu']],
            ),
        ], [
            'type' => 'option',
            'field' => 'invite_action',
        ]);
    }

    private function invitationText(string $inviteCode): string
    {
        $number = preg_replace('/\D+/', '', (string) config('services.whatsapp.public_number', ''));
        $initialMessage = "Olá! Quero conhecer o Cartão MyPhone MyMoney® 🎁 Convite: {$inviteCode}";
        $chatLink = 'https://wa.me/'.$number.'?text='.rawurlencode($initialMessage);

        return "🙋 Já conhece o *MyPhone MyMoney® Meu Celular Meu Dinheiro®?*\n\n"
            ."💰 É um *Cartão grátis* e tem bônus.\n\n"
            ."💚 Estou usando e adorando.\n\n"
            ."Acesse o link abaixo e aproveite!\n\n"
            .$chatLink;
    }

    private function inviteCode(ConversationSession $session): string
    {
        $customer = $session->customer
            ?? Customer::where('phone', $session->external_user_id)->first();

        if (! $customer) {
            return $session->external_user_id;
        }

        if ($customer->invite_code) {
            return $customer->invite_code;
        }

        do {
            $code = Str::lower(Str::random(12));
        } while (Customer::where('invite_code', $code)->exists());

        $customer->forceFill(['invite_code' => $code])->save();

        return $code;
    }
}
