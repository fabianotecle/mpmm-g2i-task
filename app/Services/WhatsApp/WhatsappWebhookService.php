<?php

namespace App\Services\WhatsApp;

use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\Customer;
use App\Models\CustomerWhatsappNameObservation;
use App\Services\Audit\CustomerAuditContext;
use App\Services\Conversation\ConversationEngine;
use App\Services\Installments\InstallmentService;
use App\Services\QrCodes\PaymentQrCodeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WhatsappWebhookService
{
    public function __construct(
        private readonly ConversationEngine $engine,
        private readonly WhatsappWebhookParser $parser,
        private readonly WhatsappStatusRecorder $statusRecorder,
        private readonly WhatsappConversationSender $sender,
        private readonly WhatsappClient $client,
        private readonly InstallmentService $installments,
        private readonly PaymentQrCodeService $qrCodes,
        private readonly CustomerAuditContext $audit,
    ) {}

    public function handle(array $payload): array
    {
        $processed = [];
        $this->statusRecorder->record($payload);
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['statuses'] ?? [] as $status) {
                    if (in_array($status['status'] ?? null, ['delivered', 'read'], true)
                        && ! empty($status['id'])) {
                        $this->sender->releaseOrderedMenu((string) $status['id']);
                    }
                }
            }
        }

        foreach ($this->parser->messages($payload) as $message) {
            $processed[] = $this->handleMessage($message);
        }

        return [
            'status' => 'ok',
            'processed' => $processed,
        ];
    }

    private function handleMessage(WhatsappInboundMessage $message): array
    {
        if ($this->wasAlreadyProcessed($message)) {
            return [
                'external_message_id' => $message->id,
                'status' => 'duplicate',
            ];
        }

        $replyDeadline = $message->timestamp?->copy()->addHour();
        if ($replyDeadline?->lte(Carbon::now())) {
            return [
                'external_message_id' => $message->id,
                'status' => 'expired',
            ];
        }

        // Acknowledge receipt before external providers are called so WhatsApp does
        // not leave the customer's selection looking unread during processing.
        $readReceipt = $this->client->markMessageAsRead($message->id);

        return $this->audit->run([
            'source' => 'whatsapp_chat',
            'actor_phone' => $message->from,
            'external_message_id' => $message->id,
        ], function () use ($message, $replyDeadline, $readReceipt) {
            $response = DB::transaction(function () use ($message) {
                $paymentQr = $this->paymentQrFromMessage($message->text);
                if ($paymentQr !== null) {
                    $previousProfileName = Customer::where('phone', $message->from)->value('whatsapp_profile_name');
                    $previousSession = $this->activeSession($message->from);
                    if ($previousSession) {
                        $previousSession->forceFill(['status' => 'expired'])->save();
                    }

                    $response = $this->engine->startFromPaymentQr(
                        'whatsapp', $message->from, $paymentQr, $this->initialInbound($message),
                    );

                    $this->recordProfileNameObservation(
                        ConversationSession::findOrFail($response['session_id']), $message, $previousProfileName,
                    );

                    return $response;
                }

                $session = $this->activeSession($message->from);

                if (! $session) {
                    $previousProfileName = Customer::where('phone', $message->from)->value('whatsapp_profile_name');
                    $inviteCode = $this->inviteCodeFromMessage($message->text);
                    $response = $this->engine->start('whatsapp', $message->from, [
                        'initial_message' => $message->text,
                        'raw' => $message->raw,
                        'external_message_id' => $message->id,
                        'invite_code' => $inviteCode,
                        'whatsapp' => $this->identityMetadata($message),
                    ], $this->initialInbound($message));

                    $session = ConversationSession::findOrFail($response['session_id']);

                    $this->recordProfileNameObservation($session, $message, $previousProfileName);

                    return $response;
                }

                $this->recordProfileNameObservation($session, $message);
                $this->mergeSessionIdentity($session, $message);

                return $this->engine->receive(
                    $session,
                    $message->text,
                    [
                        'raw' => $message->raw,
                        'invite_code' => $this->inviteCodeFromMessage($message->text),
                        'whatsapp' => $this->identityMetadata($message),
                    ],
                    'whatsapp',
                    $message->id,
                    $message->timestamp,
                );
            });
            $this->audit->attachSessionId($response['session_id'] ?? null);

            $recipient = Customer::query()->where('phone', $message->from)->first();
            if ($recipient) {
                $response['notifications'] = [
                    ...($response['notifications'] ?? []),
                    ...$this->installments->pendingNotificationsAfterInbound($recipient),
                ];
            }

            // A transfer recipient may require a template, media upload and an
            // interactive message. Reply to the sender first so that a slow or
            // unavailable recipient delivery cannot leave the confirmation screen
            // apparently stuck.
            $delivery = $this->sender->send($message->replyTo ?: $message->from, $response, $replyDeadline);
            $notificationDelivery = $this->sender->sendNotifications($response);

            return [
                'external_message_id' => $message->id,
                'session_id' => $response['session_id'] ?? null,
                'state' => $response['state'] ?? null,
                'read_receipt' => $readReceipt,
                'delivery' => $delivery,
                'notification_delivery' => $notificationDelivery,
                'status' => 'processed',
            ];
        });
    }

    private function initialInbound(WhatsappInboundMessage $message): array
    {
        return [
            'text' => $message->text,
            'metadata' => [
                'raw' => $message->raw,
                'whatsapp' => $this->identityMetadata($message),
            ],
            'channel' => 'whatsapp',
            'id' => $message->id,
            'timestamp' => $message->timestamp,
        ];
    }

    private function inviteCodeFromMessage(string $message): ?string
    {
        if (! preg_match('/(?:convite|invite)\s*[:=]\s*([a-z0-9_-]{6,32})/iu', $message, $matches)) {
            return null;
        }

        return Str::lower($matches[1]);
    }

    private function paymentQrFromMessage(string $message): ?array
    {
        if (preg_match('/\bref(?:\.|ª)?\s+(?:mpmm\s+)?([a-z0-9]{10})\s*$/iu', $message, $matches)) {
            try {
                return $this->qrCodes->resolveReference($matches[1]);
            } catch (\InvalidArgumentException) {
                return null;
            }
        }

        if (preg_match('/^\s*mpmm:qr:([A-Za-z0-9_-]+)\s*$/i', $message, $matches)) {
            try {
                return $this->qrCodes->resolve($matches[1]);
            } catch (\InvalidArgumentException) {
                return null;
            }
        }

        return null;
    }

    private function activeSession(string $from): ?ConversationSession
    {
        $session = ConversationSession::where('channel', 'whatsapp')
            ->where('external_user_id', $from)
            ->where('status', 'active')
            ->latest()
            ->first();

        if (! $session || ! $this->isExpired($session)) {
            return $session;
        }

        $session->forceFill(['status' => 'expired'])->save();

        return null;
    }

    private function isExpired(ConversationSession $session): bool
    {
        $idleMinutes = max(1, (int) config('mpmm.conversation_session_idle_minutes', 120));
        $lastInteraction = $session->last_interaction_at ?? $session->updated_at ?? $session->created_at;

        return $lastInteraction !== null
            && $lastInteraction->lt(Carbon::now()->subMinutes($idleMinutes));
    }

    private function wasAlreadyProcessed(WhatsappInboundMessage $message): bool
    {
        return ConversationMessage::where('external_channel', 'whatsapp')
            ->where('external_message_id', $message->id)
            ->exists();
    }

    private function mergeSessionIdentity(ConversationSession $session, WhatsappInboundMessage $message): void
    {
        $identity = $this->identityMetadata($message);

        if ($identity === []) {
            return;
        }

        $context = $session->context ?? [];
        $metadata = $context['metadata'] ?? [];
        $metadata['whatsapp'] = array_filter([
            ...($metadata['whatsapp'] ?? []),
            ...$identity,
        ], fn ($value) => $value !== null && $value !== '');
        $context['metadata'] = $metadata;

        $session->forceFill(['context' => $context])->save();
        $this->mergeCustomerIdentity($session, $identity);
    }

    private function mergeCustomerIdentity(ConversationSession $session, array $identity): void
    {
        if ($session->customer_id === null) {
            return;
        }

        $attributes = array_filter([
            'whatsapp_id' => $identity['id'] ?? null,
            'whatsapp_username' => $identity['username'] ?? null,
            'whatsapp_profile_name' => $identity['profile_name'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        if ($attributes === []) {
            return;
        }

        Customer::findOrFail($session->customer_id)->forceFill($attributes)->save();
    }

    private function identityMetadata(WhatsappInboundMessage $message): array
    {
        return array_filter([
            'id' => $message->whatsappId,
            'phone' => $message->phone,
            'username' => $message->username,
            'profile_name' => $message->profileName,
            'from' => $message->from,
            'reply_to' => $message->replyTo,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function recordProfileNameObservation(
        ConversationSession $session,
        WhatsappInboundMessage $message,
        ?string $previousProfileName = null,
    ): void {
        $profileName = trim((string) $message->profileName);

        if ($session->customer_id === null || $profileName === '') {
            return;
        }

        $previousName = CustomerWhatsappNameObservation::where('customer_id', $session->customer_id)
            ->latest('observed_at')
            ->latest('id')
            ->value('profile_name')
            ?? $previousProfileName
            ?? Customer::whereKey($session->customer_id)->value('whatsapp_profile_name');
        $previousName = trim((string) $previousName) ?: null;

        CustomerWhatsappNameObservation::create([
            'customer_id' => $session->customer_id,
            'conversation_session_id' => $session->id,
            'external_message_id' => $message->id,
            'profile_name' => $profileName,
            'previous_profile_name' => $previousName !== $profileName ? $previousName : null,
            'name_changed' => $previousName !== null && $previousName !== $profileName,
            'observed_at' => $message->timestamp ?? Carbon::now(),
        ]);
    }
}
