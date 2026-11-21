<?php

namespace App\Services\Conversation\Flows;

use App\Models\ConversationSession;
use App\Models\Customer;
use App\Models\GeneralContent;
use App\Services\Conversation\Contracts\ConversationFlow;
use App\Services\Conversation\ConversationResponse;
use App\Services\Conversation\Flows\Concerns\ParsesConversationInput;
use App\Services\Invites\InviteTracker;
use App\Services\Time\PhoneTimezoneResolver;
use Illuminate\Support\Carbon;

class RegisterCustomerFlow implements ConversationFlow
{
    use ParsesConversationInput;

    public function __construct(
        private readonly InviteTracker $invites,
        private readonly PhoneTimezoneResolver $timezones,
    ) {
    }

    public function enter(ConversationSession $session): array
    {
        return $this->showWelcome($session);
    }

    public function handle(ConversationSession $session, string $message): array
    {
        if ($this->isCancel($message)) {
            $session->forceFill(['status' => 'cancelled'])->save();

            return ConversationResponse::make([
                ConversationResponse::text('Poxa, que pena. Quando quiser, envie uma nova mensagem para recomeçar.'),
            ]);
        }

        return match ($session->current_step) {
            'waiting_welcome' => $this->handleWelcome($session, $message),
            'waiting_terms' => $this->askCpf($session),
            'waiting_cpf' => $this->captureCpf($session, $message),
            'waiting_name' => $this->captureName($session, $message),
            'waiting_birth_date' => $this->captureBirthDate($session, $message),
            'waiting_confirmation' => $this->confirm($session, $message),
            default => $this->enter($session),
        };
    }

    private function showWelcome(ConversationSession $session): array
    {
        $context = $session->context ?? [];
        $welcome = GeneralContent::active('registration', 'welcome');
        $content = $welcome?->content ?? [
            'header_image_url' => config('services.whatsapp.welcome_image_url'),
            'body' => "Olá, 🙏🏼🙏🏼 seja bem-vindo ao\n*_MyPhone MyMoney®_*\n*_Meu Celular Meu Dinheiro®_*\n\nExperimente e aproveite todas as vantagens de ter uma carteira no\n*_MyPhone MyMoney®_*\n\nO serviço é 100% automático.\n\nA equipe MPMM está à disposição pelo canal oficial de suporte.\n\nVamos começar?",
        ];
        $actions = $welcome?->actions ?? [
            ['label' => '👍 Vamos lá!', 'value' => 'vamos_la', 'action' => 'start_registration'],
        ];

        $session->forceFill([
            'current_step' => 'waiting_welcome',
            'context' => array_intersect_key($context, ['metadata' => true]),
        ])->save();

        return ConversationResponse::make([
            [
                'type' => 'options',
                'text' => $content['body'],
                'header_image_url' => $content['header_image_url'] ?? null,
                'options' => $actions,
            ],
        ], [
            'type' => 'option',
            'field' => 'registration_welcome',
            'area' => $welcome?->area ?? 'registration',
            'content_key' => $welcome?->key ?? 'welcome',
            'content_id' => $welcome?->id,
        ], $actions);
    }

    private function handleWelcome(ConversationSession $session, string $message): array
    {
        if (! $this->isWelcomeConfirmation($message)) {
            return $this->showWelcome($session);
        }

        $customer = Customer::updateOrCreate(
            ['phone' => $session->external_user_id],
            [
                ...$this->whatsappIdentityAttributes($session),
                'opt_in' => true,
                'status' => 'active',
                'timezone' => $this->timezones->resolve($session->external_user_id),
            ],
        );

        $session->forceFill([
            'customer_id' => $customer->id,
        ])->save();

        if ($inviteCode = $this->inviteCodeFromMetadata($session)) {
            $this->invites->recordLinkReceived($inviteCode, $customer->phone, [
                'conversation_session_id' => $session->id,
                ...($session->context['metadata']['whatsapp'] ?? []),
            ]);
        }

        return $this->showTerms($session);
    }

    private function showTerms(ConversationSession $session): array
    {
        $session->forceFill([
            'current_step' => 'waiting_terms',
        ])->save();

        $terms = GeneralContent::active('registration', 'terms_acceptance');
        $content = $terms?->content ?? [
            'title' => 'Boas-vindas ao *_MpMM®_* Trial',
            'body' => 'Para começar, aceite os termos de uso.',
            'terms_version' => 'fallback',
            'terms_url' => null,
            'privacy_policy_url' => null,
        ];
        $actions = $terms?->actions ?? [
            ['label' => 'Aceito', 'value' => 'aceitar', 'action' => 'accept_terms'],
            ['label' => '❌Cancelar', 'value' => 'cancelar', 'action' => 'cancel'],
        ];

        return ConversationResponse::make([
            ConversationResponse::text($content['body']),
            ConversationResponse::options($content['title'], $actions),
        ], [
            'type' => 'option',
            'field' => 'terms_acceptance',
            'area' => $terms?->area ?? 'registration',
            'content_key' => $terms?->key ?? 'terms_acceptance',
            'content_id' => $terms?->id,
            'terms_version' => $content['terms_version'] ?? null,
            'terms_url' => $content['terms_url'] ?? null,
            'privacy_policy_url' => $content['privacy_policy_url'] ?? null,
        ]);
    }

    private function isWelcomeConfirmation(string $message): bool
    {
        return in_array($this->normalized($message), [
            'vamos_la',
            'vamos la',
            'vamos lá',
            'vamos la?',
            'vamos lá?',
            '👍 vamos la!',
            '👍 vamos lá!',
            '↩️ 👍 vamos la!',
            '↩️ 👍 vamos lá!',
        ], true);
    }

    private function askCpf(ConversationSession $session): array
    {
        $session->forceFill(['current_step' => 'waiting_cpf'])->save();

        return ConversationResponse::make([
            ConversationResponse::text('Informe seu CPF.'),
        ], [
            'type' => 'cpf',
            'field' => 'customer_cpf',
        ]);
    }

    private function captureCpf(ConversationSession $session, string $message): array
    {
        $cpf = $this->onlyDigits($message);

        if (strlen($cpf) !== 11) {
            return ConversationResponse::make([
                ConversationResponse::text('CPF inválido. Envie os 11 dígitos.'),
            ], [
                'type' => 'cpf',
                'field' => 'customer_cpf',
            ]);
        }

        $context = $session->context ?? [];
        $context['customer_cpf'] = $cpf;

        $session->forceFill([
            'current_step' => 'waiting_name',
            'context' => $context,
        ])->save();

        return ConversationResponse::make([
            ConversationResponse::text('Agora informe seu nome completo.'),
        ], [
            'type' => 'text',
            'field' => 'customer_name',
            'min_length' => 3,
        ]);
    }

    private function captureName(ConversationSession $session, string $message): array
    {
        if (mb_strlen(trim($message)) < 3) {
            return ConversationResponse::make([
                ConversationResponse::text('Nome muito curto. Informe seu nome completo.'),
            ], [
                'type' => 'text',
                'field' => 'customer_name',
                'min_length' => 3,
            ]);
        }

        $context = $session->context ?? [];
        $context['customer_name'] = trim($message);

        $session->forceFill([
            'current_step' => 'waiting_birth_date',
            'context' => $context,
        ])->save();

        return ConversationResponse::make([
            ConversationResponse::text('Informe sua data de nascimento no formato DDMMAAAA.'),
        ], [
            'type' => 'date',
            'field' => 'customer_birth_date',
            'min_age' => 18,
            'max_age' => 80,
        ]);
    }

    private function captureBirthDate(ConversationSession $session, string $message): array
    {
        $birthDate = $this->birthDateFromMessage($message);

        if (! $birthDate) {
            return ConversationResponse::make([
                ConversationResponse::text('Data inválida. Use o formato DDMMAAAA.'),
            ], [
                'type' => 'date',
                'field' => 'customer_birth_date',
            ]);
        }

        $age = $birthDate->age;

        if ($age < 18 || $age > 80) {
            return ConversationResponse::make([
                ConversationResponse::text('O cadastro aceita membros entre 18 e 80 anos.'),
            ], [
                'type' => 'date',
                'field' => 'customer_birth_date',
                'min_age' => 18,
                'max_age' => 80,
            ]);
        }

        $context = $session->context ?? [];
        $context['customer_birth_date'] = $birthDate->toDateString();

        $session->forceFill([
            'current_step' => 'waiting_confirmation',
            'context' => $context,
        ])->save();

        return ConversationResponse::make([
            ConversationResponse::options(
                "Confirmar cadastro de {$context['customer_name']}?",
                [
                    ['label' => 'Aceitar termos', 'value' => 'aceitar'],
                    ['label' => '❌Cancelar', 'value' => 'cancelar'],
                ],
            ),
        ], [
            'type' => 'option',
            'field' => 'registration_confirmation',
        ]);
    }

    private function confirm(ConversationSession $session, string $message): array
    {
        if (! $this->isConfirm($message)) {
            return ConversationResponse::make([
                ConversationResponse::text('Para concluir, escolha Aceitar termos ou Cancelar.'),
            ]);
        }

        $context = $session->context ?? [];

        $customer = Customer::firstOrNew(['phone' => $session->external_user_id]);

        if (! $customer->exists) {
            $customer->forceFill([
                'balance' => 0,
                'redeemable_balance' => 0,
                // O cadastro não concede crédito automaticamente.
                'limit' => 0,
                'timezone' => $this->timezones->resolve($session->external_user_id),
            ]);
        }

        $customer->forceFill([
            ...$this->whatsappIdentityAttributes($session),
            'cpf' => $context['customer_cpf'],
            'name' => $context['customer_name'],
            'birth_date' => $context['customer_birth_date'],
            'status' => 'active',
            'opt_in' => true,
            'timezone' => $customer->timezone ?: $this->timezones->resolve($session->external_user_id),
        ])->save();

        $session->forceFill([
            'customer_id' => $customer->id,
        ])->save();

        if ($inviteCode = $this->inviteCodeFromMetadata($session)) {
            $invite = $this->invites->markAccepted($customer, $inviteCode, [
                'conversation_session_id' => $session->id,
            ]);

            if ($invite) {
                $metadata = $customer->metadata ?? [];
                $metadata['invite_code'] = $inviteCode;
                $metadata['invited_by_customer_id'] = $invite->inviter_customer_id;
                $metadata['accepted_customer_invite_id'] = $invite->id;
                $customer->forceFill(['metadata' => $metadata])->save();
            }
        }

        return ConversationResponse::make([
            ConversationResponse::text('Cadastro criado com sucesso.'),
        ], null, null, 'main_menu');
    }

    private function whatsappIdentityAttributes(ConversationSession $session): array
    {
        $identity = $session->context['metadata']['whatsapp'] ?? [];

        return array_filter([
            'whatsapp_id' => $identity['id'] ?? null,
            'whatsapp_username' => $identity['username'] ?? null,
            'whatsapp_profile_name' => $identity['profile_name'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function inviteCodeFromMetadata(ConversationSession $session): ?string
    {
        $metadata = $session->context['metadata'] ?? [];
        $code = $metadata['invite_code']
            ?? $metadata['convite']
            ?? $metadata['referral_code']
            ?? $metadata['invite']['code']
            ?? null;

        return is_string($code) && trim($code) !== '' ? trim($code) : null;
    }

    private function birthDateFromMessage(string $message): ?Carbon
    {
        $value = trim($message);
        $digits = $this->onlyDigits($value);
        $formats = strlen($digits) === 8
            ? ['dmY', 'd/m/Y', 'Y-m-d']
            : ['d/m/Y', 'Y-m-d'];

        foreach ($formats as $format) {
            try {
                $date = $format === 'dmY'
                    ? Carbon::createFromFormat($format, $digits)
                    : Carbon::createFromFormat($format, $value);
            } catch (\Throwable) {
                continue;
            }

            if ($date && $date->format($format) === ($format === 'dmY' ? $digits : $value)) {
                return $date->startOfDay();
            }
        }

        return null;
    }
}
