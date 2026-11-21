<?php

namespace App\Services\Conversation;

use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\Customer;
use App\Models\CustomerInvite;
use App\Services\Bonus\BonusAwardService;
use App\Services\Bonus\BonusEvents;
use App\Services\Invites\InviteTracker;
use App\Services\Conversation\Flows\TransferFlow;
use App\Services\Conversation\Flows\InstallmentFlow;
use App\Services\Conversation\Flows\AdminFlow;
use App\Services\Time\PhoneTimezoneResolver;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ConversationEngine
{
    public function __construct(
        private readonly FlowRegistry $flows,
        private readonly PhoneTimezoneResolver $timezones,
        private readonly InviteTracker $invites,
        private readonly BonusAwardService $bonuses,
    ) {
    }

    public function start(string $channel, string $externalUserId, array $metadata = [], ?array $initialInbound = null): array
    {
        return DB::transaction(function () use ($channel, $externalUserId, $metadata, $initialInbound) {
            $initialMessage = (string) ($metadata['initial_message'] ?? '');
            $adminCashOut = $channel === 'whatsapp'
                ? $this->adminCashOutShortcut($metadata, $externalUserId) : null;
            $flow = match (true) {
                $initialMessage === 'continuar_mpmm' => 'main_menu',
                $adminCashOut !== null => 'admin',
                $channel === 'whatsapp' && $this->isTransferShortcut($metadata) => 'transfer',
                $channel === 'whatsapp' && $this->isQrShortcut($metadata) => 'payment_qr_code',
                $this->isInstallmentShortcut($initialMessage) => 'installment',
                default => $this->initialFlow($externalUserId),
            };
            $customerAlreadyExisted = Customer::where('phone', $externalUserId)->exists();
            $customer = $flow === 'admin'
                ? Customer::where('phone', $externalUserId)->first()
                : $this->ensureCustomer($externalUserId, $metadata);

            $session = ConversationSession::create([
                'channel' => $channel,
                'external_user_id' => $externalUserId,
                'customer_id' => $customer?->id,
                'current_flow' => $flow,
                'current_step' => null,
                'status' => 'active',
                'context' => [
                    'metadata' => $metadata,
                ],
                'last_interaction_at' => Carbon::now(),
            ]);

            if ($initialInbound !== null) {
                $this->recordInbound(
                    $session,
                    $initialInbound['text'],
                    $initialInbound['metadata'],
                    $initialInbound['channel'],
                    $initialInbound['id'],
                    $initialInbound['timestamp'],
                );
            }

            $acceptedInvite = $customer ? $this->recordInviteAccess($customer, $metadata) : null;

            $decision = $this->installmentDecisionShortcut((string) ($metadata['initial_message'] ?? ''));
            $response = $decision
                ? app(InstallmentFlow::class)->decideFromShortcut($session, $decision['id'], $decision['accept'])
                : ($adminCashOut
                    ? app(AdminFlow::class)->fromCashOutNotice($session, $adminCashOut['customer_id'], $adminCashOut['action'])
                    : $this->flows->get($flow)->enter($session));
            if ($decision) {
                $response = $this->followTransition($session->refresh(), $response);
            } elseif (! $adminCashOut && $this->shouldHandleInitialChoice($flow, $initialInbound)) {
                $response = $this->flows->get($flow)->handle($session->refresh(), $initialInbound['text']);
                $response = $this->followTransition($session->refresh(), $response);
            }
            $response = $this->withInviteAcknowledgement($response, $customer, $metadata, $customerAlreadyExisted);
            $response = $this->withInviterAcceptanceNotification($response, $acceptedInvite, $customer);

            return $this->recordOutbound($session->refresh(), $response);
        });
    }

    /** Start a payer conversation from a previously resolved merchant QR code. */
    public function startFromPaymentQr(string $channel, string $externalUserId, array $qr, ?array $initialInbound = null): array
    {
        return DB::transaction(function () use ($channel, $externalUserId, $qr, $initialInbound) {
            /** @var Customer $recipient */
            $recipient = $qr['recipient'];
            $payer = $this->ensureCustomer($externalUserId, []);
            $flow = $qr['type'] === 'cash_in' ? 'deposit' : 'transfer';
            $sessionCustomer = $flow === 'deposit' ? $recipient : $payer;

            $session = ConversationSession::create([
                'channel' => $channel,
                'external_user_id' => $externalUserId,
                'customer_id' => $sessionCustomer->id,
                'current_flow' => $flow,
                'status' => 'active',
                'context' => ['payment_qr' => ['type' => $qr['type'], 'nonce' => $qr['nonce']]],
                'last_interaction_at' => Carbon::now(),
            ]);

            if ($initialInbound !== null) {
                $this->recordInbound(
                    $session,
                    $initialInbound['text'],
                    $initialInbound['metadata'],
                    $initialInbound['channel'],
                    $initialInbound['id'],
                    $initialInbound['timestamp'],
                );
            }

            if ($flow === 'transfer') {
                $response = app(TransferFlow::class)->enterFromQr($session, $recipient, $qr['amount']);
            } else {
                // A cash-in QR is deliberately credited to its issuer, while the
                // scanner is merely the payer. DepositFlow creates the provider
                // charge and the webhook later credits this session customer.
                $context = $session->context ?? [];
                $context['valor_recarga'] = $qr['amount'];
                $session->forceFill(['current_step' => 'waiting_method', 'context' => $context])->save();
                $response = $this->flows->get('deposit')->handle($session, 'pix');
            }

            return $this->recordOutbound($session->refresh(), $response);
        });
    }

    public function receive(
        ConversationSession $session,
        string $message,
        array $metadata = [],
        ?string $externalChannel = null,
        ?string $externalMessageId = null,
        ?Carbon $externalTimestamp = null,
    ): array
    {
        return DB::transaction(function () use ($session, $message, $metadata, $externalChannel, $externalMessageId, $externalTimestamp) {
            $session->refresh();
            $this->recordInbound($session, $message, $metadata, $externalChannel, $externalMessageId, $externalTimestamp);
            $this->mergeInboundMetadata($session, $metadata);

            if ($externalChannel === 'whatsapp'
                && ($adminCashOut = $this->adminCashOutShortcut($metadata, $session->external_user_id))) {
                $response = app(AdminFlow::class)->fromCashOutNotice(
                    $session, $adminCashOut['customer_id'], $adminCashOut['action']);
            } elseif ($externalChannel === 'whatsapp' && $this->isTransferShortcut($metadata)) {
                if (! $session->customer_id) {
                    $session->forceFill([
                        'customer_id' => $this->ensureCustomer($session->external_user_id, $metadata)->id,
                    ])->save();
                }
                $response = ['transition_to' => 'transfer'];
            } elseif ($externalChannel === 'whatsapp' && $this->isQrShortcut($metadata)) {
                if (! $session->customer_id) {
                    $session->forceFill([
                        'customer_id' => $this->ensureCustomer($session->external_user_id, $metadata)->id,
                    ])->save();
                }
                $response = ['transition_to' => 'payment_qr_code'];
            } elseif ($decision = $this->installmentDecisionShortcut($message)) {
                $response = app(InstallmentFlow::class)->decideFromShortcut($session, $decision['id'], $decision['accept']);
            } elseif ($this->isInstallmentShortcut($message)) {
                $response = ['transition_to' => 'installment'];
            } elseif ($this->inviteCodeFromMetadata($metadata) && $session->customer) {
                $acceptedInvite = $this->recordInviteAccess($session->customer, $metadata);
                $session->forceFill([
                    'current_flow' => 'main_menu',
                    'current_step' => null,
                ])->save();
                $response = $this->flows->get('main_menu')->enter($session->refresh());
                $response = $this->withInviteAcknowledgement($response, $session->customer, $metadata, true);
                $response = $this->withInviterAcceptanceNotification($response, $acceptedInvite, $session->customer);
            } else {
                $response = $message === 'continuar_mpmm'
                    ? $this->continueConversation($session)
                    : ($this->shouldRestartInitialFlow($session, $message)
                        ? $this->restartInitialFlow($session)
                        : $this->flows->get($session->current_flow)->handle($session, $message));
            }

            if ($this->isUnhandledInputResponse($session->refresh(), $response)) {
                $response = $this->handleUnhandledInputAttempt($session->refresh(), $response);
            } else {
                $this->clearUnhandledInputAttempts($session->refresh());
            }

            $response = $this->followTransition($session->refresh(), $response);

            $session->forceFill([
                'last_interaction_at' => Carbon::now(),
            ])->save();

            return $this->recordOutbound($session->refresh(), $response);
        });
    }

    private function isTransferShortcut(array $metadata): bool
    {
        return Arr::get($metadata, 'raw.interactive.button_reply.id') === 'transferir'
            || Arr::get($metadata, 'raw.button.payload') === 'transferir';
    }

    private function isQrShortcut(array $metadata): bool
    {
        return Arr::get($metadata, 'raw.interactive.button_reply.id') === 'gerar_qr_code'
            || Arr::get($metadata, 'raw.button.payload') === 'gerar_qr_code';
    }

    private function adminCashOutShortcut(array $metadata, string $phone): ?array
    {
        if (! in_array($phone, $this->adminIds(), true)) {
            return null;
        }
        $id = Arr::get($metadata, 'raw.interactive.list_reply.id')
            ?? Arr::get($metadata, 'raw.interactive.button_reply.id')
            ?? Arr::get($metadata, 'raw.button.payload');
        if (! is_string($id)
            || ! preg_match('/^admin_cashout_(menu|adjust|statement|search)_(\d+)$/', $id, $matches)) {
            return null;
        }

        return ['action' => $matches[1], 'customer_id' => (int) $matches[2]];
    }

    private function shouldHandleInitialChoice(string $flow, ?array $initialInbound): bool
    {
        if ($initialInbound === null || ! in_array($flow, ['admin', 'main_menu'], true)) {
            return false;
        }

        $raw = $initialInbound['metadata']['raw'] ?? [];
        $choice = Arr::get($raw, 'interactive.button_reply.id')
            ?? Arr::get($raw, 'interactive.list_reply.id')
            ?? Arr::get($raw, 'button.payload');

        return is_string($choice) && in_array($choice, $flow === 'admin'
            ? ['consultar', 'minha_conta']
            : ['depositar', 'transferir', 'resgate_pix', 'extrato', 'convidar', 'gerar_qr_code'], true);
    }

    private function isInstallmentShortcut(string $message): bool
    {
        $message = mb_strtolower(trim($message));
        if (preg_match('/^parcelado_(aceitar|recusar)_\d+$/', $message)) {
            // The proposal recipient is verified under a row lock in InstallmentService::decide().
            return true;
        }
        return in_array($message, ['parcelado', '🗓️ parcelado'], true);
    }

    private function installmentDecisionShortcut(string $message): ?array
    {
        if (! preg_match('/^parcelado_(aceitar|recusar)_(\d+)$/', mb_strtolower(trim($message)), $matches)
            || ! $this->isInstallmentShortcut($message)) return null;

        return ['id' => (int) $matches[2], 'accept' => $matches[1] === 'aceitar'];
    }

    private function restartInitialFlow(ConversationSession $session): array
    {
        $flow = $this->initialFlow($session->external_user_id);
        $customer = $flow === 'admin'
            ? Customer::where('phone', $session->external_user_id)->first()
            : $this->ensureCustomer($session->external_user_id, $session->context['metadata'] ?? []);

        $session->forceFill([
            'customer_id' => $customer?->id,
            'current_step' => null,
            'context' => [],
        ])->save();

        return ['transition_to' => $flow];
    }

    public function reset(ConversationSession $session): array
    {
        return DB::transaction(function () use ($session) {
            $session->refresh();
            $flow = $this->initialFlow($session->external_user_id);
            $customer = $flow === 'admin'
                ? Customer::where('phone', $session->external_user_id)->first()
                : $this->ensureCustomer($session->external_user_id, $session->context['metadata'] ?? []);

            $session->forceFill([
                'customer_id' => $customer?->id,
                'current_flow' => $flow,
                'current_step' => null,
                'status' => 'active',
                'context' => [],
                'last_interaction_at' => Carbon::now(),
            ])->save();

            $response = $this->flows->get($flow)->enter($session);

            return $this->recordOutbound($session->refresh(), $response);
        });
    }

    private function followTransition(ConversationSession $session, array $response): array
    {
        $messages = $response['messages'] ?? [];
        $transitionTo = Arr::pull($response, 'transition_to');

        if (! $transitionTo) {
            return $response;
        }

        $session->forceFill([
            'current_flow' => $transitionTo,
            'current_step' => null,
        ])->save();

        $next = $this->flows->get($transitionTo)->enter($session->refresh());
        $next = $this->followTransition($session->refresh(), $next);

        return array_filter([
            'messages' => [
                ...$messages,
                ...($next['messages'] ?? []),
            ],
            'expected_input' => $next['expected_input'] ?? $response['expected_input'] ?? null,
            'options' => $next['options'] ?? $response['options'] ?? null,
            'notifications' => [
                ...($response['notifications'] ?? []),
                ...($next['notifications'] ?? []),
            ],
        ], fn ($value) => $value !== null && $value !== []);
    }

    private function recordInbound(
        ConversationSession $session,
        string $message,
        array $metadata,
        ?string $externalChannel = null,
        ?string $externalMessageId = null,
        ?Carbon $externalTimestamp = null,
    ): void
    {
        ConversationMessage::create([
            'conversation_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'text',
            'payload' => [
                'text' => $message,
                'metadata' => $metadata,
            ],
            'external_channel' => $externalChannel,
            'external_message_id' => $externalMessageId,
            'external_timestamp' => $externalTimestamp,
        ]);
    }

    private function recordOutbound(ConversationSession $session, array $response): array
    {
        $message = ConversationMessage::create([
            'conversation_session_id' => $session->id,
            'direction' => 'outbound',
            'type' => 'json',
            'payload' => $response,
        ]);

        return [
            'history_message_id' => $message->id,
            'session_id' => $session->id,
            'state' => "{$session->current_flow}.{$session->current_step}",
            'status' => $session->status,
            'context' => $session->context ?? [],
            ...$response,
        ];
    }

    private function continueConversation(ConversationSession $session): array
    {
        $session->forceFill([
            'current_flow' => 'main_menu',
            'current_step' => null,
        ])->save();

        return $this->flows->get('main_menu')->enter($session->refresh());
    }

    private function mergeInboundMetadata(ConversationSession $session, array $metadata): void
    {
        if ($metadata === []) {
            return;
        }

        $context = $session->context ?? [];
        $context['metadata'] = array_replace_recursive($context['metadata'] ?? [], $metadata);

        $session->forceFill(['context' => $context])->save();
    }

    private function initialFlow(string $externalUserId): string
    {
        if (in_array($externalUserId, $this->adminIds(), true)) {
            return 'admin';
        }

        return 'main_menu';
    }

    private function ensureCustomer(string $externalUserId, array $metadata = []): Customer
    {
        $customer = Customer::firstOrCreate(
            ['phone' => $externalUserId],
            [
                ...$this->whatsappIdentityAttributes($metadata),
                'status' => 'active',
                'balance' => 0,
                'redeemable_balance' => 0,
                // Crédito somente pode ser concedido pelo fluxo administrativo confirmado.
                'limit' => 0,
                'opt_in' => true,
                'timezone' => $this->timezones->resolve($externalUserId),
            ],
        );
        $wasRecentlyCreated = $customer->wasRecentlyCreated;

        $attributes = array_filter([
            ...$this->whatsappIdentityAttributes($metadata),
            'status' => 'active',
            'opt_in' => true,
            'timezone' => $customer->timezone ?: $this->timezones->resolve($externalUserId),
        ], fn ($value) => $value !== null && $value !== '');

        if ($attributes !== []) {
            $customer->forceFill($attributes)->save();
        }

        if ($wasRecentlyCreated && ! $this->inviteCodeFromMetadata($metadata)) {
            $this->bonuses->award(BonusEvents::CUSTOMER_AUTONOMOUS_ENTRY, $customer, [
                'source_type' => 'customer',
                'source_id' => $customer->id,
            ]);
        }

        if ($wasRecentlyCreated) {
            $this->bonuses->award(BonusEvents::CUSTOMER_DDD, $customer, [
                'source_type' => 'customer',
                'source_id' => $customer->id,
                ...$this->phoneBonusMetadata($externalUserId),
            ]);
        }

        return $customer;
    }

    private function whatsappIdentityAttributes(array $metadata): array
    {
        $identity = $metadata['whatsapp'] ?? [];

        return array_filter([
            'whatsapp_id' => $identity['id'] ?? null,
            'whatsapp_username' => $identity['username'] ?? null,
            'whatsapp_profile_name' => $identity['profile_name'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function recordInviteAccess(Customer $customer, array $metadata): ?CustomerInvite
    {
        $code = $this->inviteCodeFromMetadata($metadata);

        if (! $code) {
            return null;
        }

        $this->invites->recordLinkReceived($code, $customer->phone, [
            ...($metadata['whatsapp'] ?? []),
            'conversation_entry' => 'main_menu',
        ]);

        $invite = $this->invites->markAccepted($customer, $code, [
            'conversation_entry' => 'main_menu',
        ]);

        if (! $invite) {
            return null;
        }

        $customerMetadata = $customer->metadata ?? [];
        $customer->forceFill([
            'metadata' => [
                ...$customerMetadata,
                'invite_code' => $code,
                'invited_by_customer_id' => $invite->inviter_customer_id,
                'accepted_customer_invite_id' => $invite->id,
            ],
        ])->save();

        return $invite;
    }

    private function withInviterAcceptanceNotification(
        array $response,
        ?CustomerInvite $invite,
        ?Customer $recipient,
    ): array {
        if (! $invite || ! $recipient || ! $invite->acceptance_was_new) {
            return $response;
        }

        $inviter = $invite->inviter()->first();
        if (! $inviter) {
            return $response;
        }

        $name = $recipient->whatsappDisplayName() ?: 'Um novo membro';
        $response['notifications'][] = [
            'channel' => 'whatsapp',
            'to' => $inviter->phone,
            'messages' => [ConversationResponse::text(
                "🎉 *Seu convite foi aceito!*\n\n{$name} entrou no MyPhone MyMoney® pelo seu convite."
                ."\n\nOs bônus de ambos foram aplicados conforme a política vigente. Avisaremos também quando ocorrer a primeira carga.",
            )],
        ];

        return $response;
    }

    private function withInviteAcknowledgement(
        array $response,
        ?Customer $customer,
        array $metadata,
        bool $customerAlreadyExisted,
    ): array {
        $code = $this->inviteCodeFromMetadata($metadata);

        if (! $customer || ! $code || ($customer->fresh()->metadata['invite_code'] ?? null) !== $code) {
            return $response;
        }

        $text = $customerAlreadyExisted
            ? "😊 Obrigado por aceitar o convite!\n\nVocê já é membro do *Cartão MyPhone MyMoney®* ou já tinha aceitado um convite. Seu cadastro continua ativo e não será duplicado."
            : "🎉 Convite aceito! Seja muito bem-vindo(a) ao *Cartão MyPhone MyMoney®*.\n\n💰 Você já pode receber, transferir e carregar. É grátis, privado e seguro.";

        $response['messages'] = [
            ConversationResponse::text($text),
            ...($response['messages'] ?? []),
        ];

        return $response;
    }

    private function inviteCodeFromMetadata(array $metadata): ?string
    {
        $code = $metadata['invite_code']
            ?? $metadata['convite']
            ?? $metadata['referral_code']
            ?? $metadata['invite']['code']
            ?? null;

        return is_string($code) && trim($code) !== '' ? trim($code) : null;
    }

    private function phoneBonusMetadata(string $phone): array
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';

        if ($digits === '') {
            return [];
        }

        $metadata = [
            'phone_prefix' => substr($digits, 0, 4),
        ];

        if (str_starts_with($digits, '55') && strlen($digits) >= 4) {
            return [
                ...$metadata,
                'ddi' => '55',
                'ddd' => substr($digits, 2, 2),
            ];
        }

        foreach (['351', '44', '1'] as $ddi) {
            if (str_starts_with($digits, $ddi)) {
                return [
                    ...$metadata,
                    'ddi' => $ddi,
                ];
            }
        }

        return $metadata;
    }

    private function adminIds(): array
    {
        $configured = array_filter(array_map(
            'trim',
            explode(',', (string) config('mpmm.admin_ids', '')),
        ));

        return array_values(array_unique($configured));
    }

    private function isRestartCommand(string $message): bool
    {
        $command = $this->normalizedRestartCommand($message);
        $restartCommands = [
            'oi',
            'ola',
            'ol',
            'bom dia',
            'boa tarde',
            'boa noite',
            'hello',
            'hi',
            'buenas',
            'hola',
            'atendente',
            'sos',
            'ciao',
            'menu',
            'continuar',
            'inicio',
            'comecar',
        ];

        if (in_array($command, $restartCommands, true)) {
            return true;
        }

        foreach ($restartCommands as $restartCommand) {
            if (str_starts_with($command, $restartCommand.' ')) {
                return true;
            }
        }

        return false;
    }

    private function shouldRestartInitialFlow(ConversationSession $session, string $message): bool
    {
        if ($session->current_flow === 'register_customer') {
            return false;
        }

        if ($session->current_flow === 'redeem_pix' && $this->normalizedRestartCommand($message) === 'continuar') {
            return false;
        }

        if ($session->current_flow === 'deposit'
            && $session->current_step === 'waiting_payment_confirmation'
            && $this->normalizedRestartCommand($message) === 'continuar') {
            return false;
        }

        if ($session->current_flow === 'admin'
            && $session->current_step === 'waiting_customer_action'
            && in_array($this->normalizedRestartCommand($message), ['continuar', 'continuar consulta', 'continuar_consulta'], true)) {
            return false;
        }

        return $this->isRestartCommand($message);
    }

    private function handleUnhandledInputAttempt(ConversationSession $session, array $response): array
    {
        $context = $session->context ?? [];
        $attempts = (int) ($context['_unhandled_input_attempts'] ?? 0) + 1;

        if ($attempts < 3) {
            $context['_unhandled_input_attempts'] = $attempts;
            $session->forceFill(['context' => $context])->save();

            return $response;
        }

        unset($context['_unhandled_input_attempts']);
        $session->forceFill(['context' => $context])->save();

        return [
            'messages' => [
                ['type' => 'text', 'text' => '🤔 Opa… Algo não parece bem. Vamos começar de novo.'],
            ],
            ...$this->restartInitialFlow($session),
        ];
    }

    private function clearUnhandledInputAttempts(ConversationSession $session): void
    {
        $context = $session->context ?? [];

        if (! array_key_exists('_unhandled_input_attempts', $context)) {
            return;
        }

        unset($context['_unhandled_input_attempts']);
        $session->forceFill(['context' => $context])->save();
    }

    private function isUnhandledInputResponse(ConversationSession $session, array $response): bool
    {
        if (isset($response['transition_to'])) {
            return false;
        }

        foreach ($response['messages'] ?? [] as $message) {
            $text = $message['text'] ?? null;

            if (! is_string($text)) {
                continue;
            }

            $normalized = $this->normalizedRestartCommand($text);

            foreach ($this->unhandledInputPrefixes() as $prefix) {
                if (str_starts_with($normalized, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function unhandledInputPrefixes(): array
    {
        return [
            'nao entendi',
            'nao consegui identificar',
            'escolha uma opcao',
            'escolha uma das opcoes',
            'escolha pix',
            'escolha confirmar',
            'escolha ajustar',
            'informe um valor entre',
            'informe um valor valido',
            'ops informe um valor entre',
            'digite o nº de 2 a',
            'cpf invalido',
            'data invalida',
        ];
    }

    private function normalizedRestartCommand(string $message): string
    {
        $message = mb_strtolower(trim($message));
        $message = strtr($message, [
            'á' => 'a',
            'à' => 'a',
            'â' => 'a',
            'ã' => 'a',
            'ä' => 'a',
            'é' => 'e',
            'è' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'í' => 'i',
            'ì' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'ó' => 'o',
            'ò' => 'o',
            'ô' => 'o',
            'õ' => 'o',
            'ö' => 'o',
            'ú' => 'u',
            'ù' => 'u',
            'û' => 'u',
            'ü' => 'u',
            'ç' => 'c',
            'ñ' => 'n',
        ]);

        return trim((string) preg_replace('/[^\pL\pN]+/u', ' ', $message));
    }
}
