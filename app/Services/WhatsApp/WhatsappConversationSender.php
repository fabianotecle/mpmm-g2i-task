<?php

namespace App\Services\WhatsApp;

use App\Models\Customer;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\WhatsappNotificationDelivery;
use App\Models\WhatsappMessageStatus;
use App\Models\WhatsappOrderedMenu;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsappConversationSender
{
    public function __construct(
        private readonly WhatsappResponseRenderer $renderer,
        private readonly WhatsappClient $client,
        private readonly WhatsappTemplateHistory $templateHistory,
    ) {}

    public function send(string $to, array $response, ?Carbon $replyDeadline = null): array
    {
        $payloads = $this->renderer->render($response);
        $deliveries = [];
        $orderedMenus = [];

        foreach ($payloads as $index => $payload) {
            if ($replyDeadline?->lte(now())) {
                $deliveries[] = [
                    'channel' => 'whatsapp',
                    'status' => 'expired',
                    'reason' => 'reply_deadline_elapsed',
                    'to' => $to,
                ];
                break;
            }

            if ($index > 0) {
                if ($this->isSecondaryMenuPair($payloads[$index - 1], $payload)) {
                    $primary = $deliveries[$index - 1] ?? [];
                    $primaryId = $primary['response']['messages'][0]['id'] ?? null;
                    if (($primary['status'] ?? null) === 'sent' && is_string($primaryId) && $primaryId !== '') {
                        $deliveries[] = [
                            'channel' => 'whatsapp',
                            'status' => 'deferred',
                            'reason' => 'awaiting_primary_delivery',
                            'to' => $to,
                        ];
                        $orderedMenus[] = [
                            'primary_message_id' => $primaryId,
                            'recipient_phone' => $to,
                            'secondary_payload' => $payload,
                            'conversation_message_id' => $response['history_message_id'] ?? null,
                            'delivery_index' => $index,
                            'expires_at' => $replyDeadline ?? now()->addHours(24),
                        ];
                        continue;
                    }

                    $deliveries[] = [
                        'channel' => 'whatsapp',
                        'status' => 'skipped',
                        'reason' => ($primary['status'] ?? null) === 'failed'
                            ? 'primary_menu_failed' : 'primary_menu_unconfirmed',
                        'to' => $to,
                    ];
                    continue;
                }

                $this->waitBetweenMessages($payloads[$index - 1], $payload);
            }

            if ($replyDeadline?->lte(now())) {
                $deliveries[] = [
                    'channel' => 'whatsapp',
                    'status' => 'expired',
                    'reason' => 'reply_deadline_elapsed',
                    'to' => $to,
                ];
                break;
            }

            if (($payload['type'] ?? null) === 'document' && ! empty($payload['_local_path'])) {
                $localPath = $payload['_local_path'];
                unset($payload['_local_path']);
                $deliveries[] = $this->client->sendDocument(
                    $to,
                    $localPath,
                    $payload['document']['filename'] ?? 'Documento.pdf',
                    $payload['document']['caption'] ?? null,
                );

                continue;
            }

            if (($payload['type'] ?? null) === 'image' && ! empty($payload['_local_path'])) {
                $localPath = $payload['_local_path'];
                $caption = $payload['image']['caption'] ?? null;
                unset($payload['_local_path']);
                $deliveries[] = $this->client->sendImage($to, $localPath, $caption);

                continue;
            }

            unset($payload['_local_path']);
            $deliveries[] = $this->client->sendPayload($to, $payload);
        }

        $this->recordConversationDelivery($response, $deliveries);

        foreach ($orderedMenus as $orderedMenu) {
            WhatsappOrderedMenu::create($orderedMenu);
            // The receipt can arrive before the first request has finished saving this row.
            $this->releaseOrderedMenu($orderedMenu['primary_message_id']);
        }

        return $deliveries;
    }

    private function recordConversationDelivery(array $response, array $deliveries): void
    {
        $messageId = $response['history_message_id'] ?? null;
        if (! $messageId || ! ($message = ConversationMessage::find($messageId))) {
            return;
        }

        $payload = $message->payload ?? [];
        $payload['_deliveries'] = array_map(fn (array $delivery) => [
            'status' => $delivery['status'] ?? 'unknown',
            'accepted_at' => $delivery['accepted_at'] ?? null,
            'provider_message_id' => $delivery['response']['messages'][0]['id'] ?? null,
        ], $deliveries);
        $message->forceFill(['payload' => $payload])->save();
    }

    public function releaseOrderedMenu(string $primaryMessageId): void
    {
        $receipt = WhatsappMessageStatus::where('provider_message_id', $primaryMessageId)->first();
        if (! $receipt || ! in_array($receipt->status, ['delivered', 'read'], true)) {
            return;
        }

        $menu = WhatsappOrderedMenu::where('primary_message_id', $primaryMessageId)->first();
        if (! $menu || ($menu->expires_at && $menu->expires_at->isPast())) {
            return;
        }

        // Claim before the API call so duplicate status webhooks cannot send twice.
        if (WhatsappOrderedMenu::whereKey($menu->id)->where('status', 'pending')
            ->update(['status' => 'sending']) !== 1) {
            return;
        }

        $delivery = $this->client->sendPayload($menu->recipient_phone, $menu->secondary_payload);
        WhatsappOrderedMenu::whereKey($menu->id)->update([
            'status' => ($delivery['status'] ?? null) === 'sent' ? 'sent' : 'pending',
        ]);

        if ($menu->conversation_message_id) {
            $message = ConversationMessage::find($menu->conversation_message_id);
            if ($message) {
                $payload = $message->payload ?? [];
                $payload['_deliveries'][$menu->delivery_index] = [
                    'status' => $delivery['status'] ?? 'unknown',
                    'accepted_at' => $delivery['accepted_at'] ?? null,
                    'provider_message_id' => $delivery['response']['messages'][0]['id'] ?? null,
                ];
                $message->forceFill(['payload' => $payload])->save();
            }
        }
    }

    public function retryOrderedMenus(int $limit = 100): int
    {
        $menus = WhatsappOrderedMenu::where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereIn('primary_message_id', WhatsappMessageStatus::query()
                ->select('provider_message_id')->whereIn('status', ['delivered', 'read']))
            ->orderBy('id')->limit($limit)->get();

        foreach ($menus as $menu) {
            $this->releaseOrderedMenu($menu->primary_message_id);
        }

        return $menus->count();
    }

    public function sendNotifications(array $response): array
    {
        $deliveries = [];

        foreach ($response['notifications'] ?? [] as $notification) {
            if (($notification['channel'] ?? null) !== 'whatsapp' || empty($notification['to'])) {
                continue;
            }

            $notification = $this->normalizeLegacyFirstTransferNotification($notification);
            $tracking = $this->track($notification);
            if ($tracking?->status === 'sent') {
                continue;
            }
            if (($notification['requires_inbound_window'] ?? false)
                && ! $this->hasInboundWindow((string) $notification['to'])) {
                continue;
            }
            $notification = $this->selectTransferDelivery($notification, $tracking);
            $delivery = [];

            if ($notification['send_start_template'] ?? false) {
                $welcomeDelivery = $this->stepWasDelivered($tracking, 'welcome_template')
                    ? []
                    : $this->sendStartTemplate($notification['to']);
                $delivery = [...$delivery, ...$welcomeDelivery];

                if (($welcomeDelivery[0]['status'] ?? null) === 'sent') {
                    $this->markStepDelivered($tracking, 'welcome_template');
                    $this->markWelcomeDelivered((int) ($notification['welcome_customer_id'] ?? 0));
                    $this->templateHistory->record(
                        (string) $notification['to'],
                        config('services.whatsapp.start_template', 'boas_vindas'),
                        $this->startTemplateComponents(),
                        $tracking,
                        'welcome_template',
                        $welcomeDelivery[0]['response']['messages'][0]['id'] ?? null,
                    );
                }
            }

            if (! empty($notification['template_name'])
                && ! $this->stepWasDelivered($tracking, 'transfer_template')) {
                $templateDelivery = $this->client->sendTemplate(
                        $notification['to'],
                        $notification['template_name'],
                        $notification['template_language'] ?? 'pt_BR',
                        $notification['template_components'] ?? [],
                    );
                $delivery[] = $templateDelivery;
                if (($templateDelivery['status'] ?? null) === 'sent') {
                    $this->markStepDelivered($tracking, 'transfer_template');
                    $this->templateHistory->record(
                        (string) $notification['to'],
                        (string) $notification['template_name'],
                        $notification['template_components'] ?? [],
                        $tracking,
                        'transfer_template',
                        $templateDelivery['response']['messages'][0]['id'] ?? null,
                    );
                    if ($notification['mark_welcome_on_template'] ?? false) {
                        $this->markWelcomeDelivered((int) ($notification['welcome_customer_id'] ?? 0));
                    }
                }
            }

            $templateWasSent = collect($delivery)->contains(
                fn (array $item) => ($item['status'] ?? null) === 'sent',
            );

            if ($delivery !== [] && ! $templateWasSent) {
                Log::warning('WhatsApp notification template delivery failed.', [
                    'to' => $notification['to'],
                    'template' => $notification['template_name']
                        ?? config('services.whatsapp.start_template', 'boas_vindas'),
                    'status' => $delivery[0]['status'] ?? null,
                    'reason' => $delivery[0]['reason'] ?? null,
                    'message' => $delivery[0]['message'] ?? null,
                ]);
            }

            $pendingMessages = collect($notification['messages'] ?? [])
                ->reject(fn (array $message, int $index) => $this->stepWasDelivered($tracking, "message:{$index}"));
            $messageDelivery = $this->send($notification['to'], [
                'messages' => $pendingMessages->values()->all(),
            ]);
            foreach ($pendingMessages->keys()->values() as $position => $messageIndex) {
                if (($messageDelivery[$position]['status'] ?? null) === 'sent') {
                    $this->markStepDelivered($tracking, "message:{$messageIndex}");
                }
            }
            $this->recordNotificationMessages(
                (string) $notification['to'],
                $pendingMessages->values()->all(),
                $messageDelivery,
            );

            $deliveries[] = [
                'to' => $notification['to'],
                'delivery' => [
                    ...$delivery,
                    ...$messageDelivery,
                ],
            ];

            $trackedDelivery = $deliveries[array_key_last($deliveries)]['delivery'];
            if (($notification['send_start_template'] ?? false)
                && ! $this->stepWasDelivered($tracking, 'welcome_template')) {
                $trackedDelivery = array_slice($trackedDelivery, 1);
            }
            $this->finishTracking($tracking, $trackedDelivery, $notification);
        }

        return $deliveries;
    }

    private function selectTransferDelivery(array $notification, ?WhatsappNotificationDelivery $tracking): array
    {
        if (($notification['template_name'] ?? null) !== config('services.whatsapp.transfer_notification_template', 'aviso_transferencia_v1')) {
            return $notification;
        }

        foreach ($notification['messages'] ?? [] as $index => $message) {
            if (in_array($message['type'] ?? null, ['text', 'options'], true)
                && $this->stepWasDelivered($tracking, "message:{$index}")
                && $index === array_key_last($notification['messages'])) {
                unset($notification['template_name']);
                $notification['messages'] = [];
                $notification['send_start_template'] = false;

                return $notification;
            }
            foreach ($message['options'] ?? [] as $optionIndex => $option) {
                if (($option['value'] ?? null) === 'continuar') {
                    $notification['messages'][$index]['options'][$optionIndex] = [
                        'label' => '🏡 Voltar ao Menu', 'value' => 'menu',
                    ];
                }
            }
        }

        // A sent template already contains the transfer notice: never send its duplicate on retry.
        if ($this->stepWasDelivered($tracking, 'transfer_template')) {
            $notification['messages'] = [];
            $notification['send_start_template'] = false;

            return $notification;
        }

        $hasWindow = $this->hasInboundWindow((string) $notification['to']);

        if ($hasWindow && ! empty($notification['messages'])) {
            unset($notification['template_name']);
            $notification['send_start_template'] = false;
        } else {
            // Outbound messages and welcome templates do not open the customer service window.
            $notification['messages'] = [];
        }

        return $notification;
    }

    private function hasInboundWindow(string $phone): bool
    {
        return ConversationMessage::query()
            ->where('direction', 'inbound')
            ->where('external_channel', 'whatsapp')
            ->whereHas('conversationSession', fn ($query) => $query
                ->where('channel', 'whatsapp')->where('external_user_id', $phone))
            ->where(fn ($query) => $query
                ->where('external_timestamp', '>', now()->subHours(24))
                ->orWhere(fn ($fallback) => $fallback->whereNull('external_timestamp')->where('created_at', '>', now()->subHours(24))))
            ->exists();
    }

    public function retryPending(int $limit = 100): int
    {
        $count = 0;
        WhatsappNotificationDelivery::query()
            ->whereIn('status', ['pending', 'failed'])
            ->where(fn ($query) => $query->whereNull('notification_key')
                ->orWhere('notification_key', 'not like', 'installment-plan:%'))
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')->limit($limit)->get()
            ->each(function (WhatsappNotificationDelivery $tracking) use (&$count) {
                $notification = $tracking->notification;
                $notification['_tracking_id'] = $tracking->id;
                $this->sendNotifications(['notifications' => [$notification]]);
                $count++;
            });

        if ($count < $limit) {
            $plans = WhatsappNotificationDelivery::query()
                ->whereIn('status', ['pending', 'failed'])
                ->where('notification_key', 'like', 'installment-plan:%')
                ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->orderBy('id')->cursor();
            foreach ($plans as $tracking) {
                if ($count >= $limit) {
                    break;
                }
                if (! $this->hasInboundWindow($tracking->recipient_phone)) {
                    continue;
                }
                $notification = $tracking->notification;
                $notification['_tracking_id'] = $tracking->id;
                $this->sendNotifications(['notifications' => [$notification]]);
                $count++;
            }
        }

        return $count;
    }

    private function track(array $notification): ?WhatsappNotificationDelivery
    {
        try {
            if (! empty($notification['_tracking_id'])) {
                return WhatsappNotificationDelivery::find($notification['_tracking_id']);
            }

            if (empty($notification['transfer_id']) && empty($notification['notification_key'])) {
                return null;
            }

            $identity = ! empty($notification['notification_key'])
                ? ['notification_key' => $notification['notification_key'], 'recipient_phone' => $notification['to']]
                : ['transfer_id' => $notification['transfer_id'], 'recipient_phone' => $notification['to']];
            $tracking = WhatsappNotificationDelivery::firstOrCreate(
                $identity,
                ['notification' => $notification, 'status' => 'pending'],
            );

            // The transactional outbox is initially stored before the receipt
            // image exists. Enrich it with the final payload before delivery so
            // future retries can reproduce the complete notification.
            if ($tracking->notification !== $notification) {
                $tracking->forceFill(['notification' => $notification])->save();
            }

            return $tracking;
        } catch (Throwable $exception) {
            // Keep the financial flow and both WhatsApp responses operational
            // during a rolling deployment in which the migration is pending.
            Log::error('WhatsApp notification tracking is unavailable.', [
                'transfer_id' => $notification['transfer_id'] ?? null,
                'to' => $notification['to'] ?? null,
                'exception' => class_basename($exception),
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function normalizeLegacyFirstTransferNotification(array $notification): array
    {
        $legacyTemplate = config(
            'services.whatsapp.first_transfer_notification_template',
            'primeira_transferencia_v1',
        );

        if (($notification['template_name'] ?? null) !== $legacyTemplate) {
            return $this->addMissingTransferTemplateParameters($notification);
        }

        $notification['send_start_template'] = true;
        $notification['template_name'] = config(
            'services.whatsapp.transfer_notification_template',
            'aviso_transferencia_v1',
        );
        $notification['template_language'] = config(
            'services.whatsapp.transfer_notification_template_language',
            'pt_BR',
        );
        unset($notification['mark_welcome_on_template']);

        return $this->addMissingTransferTemplateParameters($notification);
    }

    private function addMissingTransferTemplateParameters(array $notification): array
    {
        $components = collect($notification['template_components'] ?? []);
        $header = $components->firstWhere('type', 'header');
        $body = $components->firstWhere('type', 'body');
        if (($header['parameters'][0]['type'] ?? null) === 'image'
            && count($body['parameters'] ?? []) >= 2) {
            return $notification;
        }

        $text = collect($notification['messages'] ?? [])
            ->pluck('text')
            ->filter()
            ->implode("\n");

        $amount = $body['parameters'][0]['text'] ?? null;
        if (! $amount && preg_match('/Valor:\s*\*?R\$\s*([\d.]+,\d{2})/u', $text, $amountMatches)) {
            $amount = $amountMatches[1];
        }
        if (! $amount || ! preg_match('/\*De:\*\s*(?:👤\s*)?([^\r\n]+)/u', $text, $originMatches)) {
            return $notification;
        }

        $notification['template_components'] = [[
            'type' => 'header',
            'parameters' => [[
                'type' => 'image',
                'image' => [
                    'link' => config('services.whatsapp.welcome_image_url'),
                ],
            ]],
        ], [
            'type' => 'body',
            'parameters' => [[
                'type' => 'text',
                'text' => $amount,
            ], [
                'type' => 'text',
                'text' => trim($originMatches[1]),
            ]],
        ]];

        return $notification;
    }

    private function finishTracking(
        ?WhatsappNotificationDelivery $tracking,
        array $delivery,
        array $notification,
    ): void
    {
        if (! $tracking) {
            return;
        }

        $failed = collect($delivery)->first(fn (array $item) => ($item['status'] ?? null) !== 'sent');
        $requiredSteps = array_keys($notification['messages'] ?? []);
        $requiredSteps = array_map(fn (int|string $index) => "message:{$index}", $requiredSteps);
        if (! empty($notification['template_name'])) {
            $requiredSteps[] = 'transfer_template';
        }
        $allRequiredStepsDelivered = collect($requiredSteps)
            ->every(fn (string $step) => $this->stepWasDelivered($tracking, $step));
        $failed ??= $allRequiredStepsDelivered ? null : [
            'reason' => 'incomplete_delivery',
            'message' => 'One or more notification steps remain pending.',
        ];
        $attempts = $tracking->attempts + 1;
        $tracking->forceFill([
            'status' => $failed ? 'failed' : 'sent',
            'attempts' => $attempts,
            'last_error' => $failed ? json_encode([
                'reason' => $failed['reason'] ?? null,
                'message' => $failed['message'] ?? null,
                'error' => $failed['error'] ?? null,
            ], JSON_UNESCAPED_UNICODE) : null,
            'last_attempted_at' => now(),
            'next_attempt_at' => $failed ? now()->addMinutes(min(60, 2 ** min($attempts, 6))) : null,
            'sent_at' => $failed ? null : now(),
        ])->save();

        Log::log($failed ? 'warning' : 'info', 'WhatsApp transfer notification delivery recorded.', [
            'delivery_id' => $tracking->id,
            'transfer_id' => $tracking->transfer_id,
            'to' => $tracking->recipient_phone,
            'status' => $tracking->status,
            'attempts' => $attempts,
        ]);
    }

    private function stepWasDelivered(?WhatsappNotificationDelivery $tracking, string $step): bool
    {
        return $tracking && in_array($step, $tracking->delivered_steps ?? [], true);
    }

    private function markStepDelivered(?WhatsappNotificationDelivery $tracking, string $step): void
    {
        if (! $tracking || $this->stepWasDelivered($tracking, $step)) {
            return;
        }

        $steps = [...($tracking->delivered_steps ?? []), $step];
        $tracking->forceFill(['delivered_steps' => array_values(array_unique($steps))])->save();
        $tracking->refresh();
    }

    private function markWelcomeDelivered(int $customerId): void
    {
        if ($customerId <= 0 || ! ($customer = Customer::find($customerId))) {
            return;
        }

        $metadata = $customer->metadata ?? [];
        $metadata['welcome_notified_at'] = now()->toISOString();
        $customer->forceFill(['metadata' => $metadata])->save();
    }

    private function recordNotificationMessages(string $phone, array $messages, array $deliveries): void
    {
        $customer = Customer::query()->where('phone', preg_replace('/\D+/', '', $phone))->first();
        if (! $customer) {
            return;
        }

        $session = ConversationSession::query()
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->first();
        $session ??= ConversationSession::create([
            'channel' => 'whatsapp_notification',
            'external_user_id' => $customer->phone,
            'customer_id' => $customer->id,
            'current_flow' => null,
            'current_step' => null,
            'status' => 'closed',
            'context' => ['source' => 'platform_notification'],
            'last_interaction_at' => now(),
        ]);

        foreach ($messages as $index => $message) {
            if (($deliveries[$index]['status'] ?? null) !== 'sent') {
                continue;
            }

            ConversationMessage::create([
                'conversation_session_id' => $session->id,
                'direction' => 'outbound',
                'type' => $message['type'] ?? 'json',
                'payload' => [...$message, 'delivery_status' => 'accepted'],
                'external_channel' => 'whatsapp',
                'external_message_id' => $deliveries[$index]['response']['messages'][0]['id'] ?? null,
                'external_timestamp' => now(),
            ]);
        }
    }

    private function waitBetweenMessages(array $previousPayload, array $payload): void
    {
        $delayMs = max(0, (int) config('services.whatsapp.inter_message_delay_ms', 1000));
        $delayMs = max($delayMs, $this->extraDelayForMediaAction($previousPayload, $payload));

        if ($delayMs === 0) {
            return;
        }

        usleep($delayMs * 1000);
    }

    private function extraDelayForMediaAction(array $previousPayload, array $payload): int
    {
        if (($previousPayload['type'] ?? null) === 'image'
            && ($payload['type'] ?? null) === 'interactive') {
            return max(0, (int) config('services.whatsapp.image_action_delay_ms', 4000));
        }

        return 0;
    }

    private function isSecondaryMenuPair(array $previousPayload, array $payload): bool
    {
        return ($previousPayload['interactive']['type'] ?? null) === 'button'
            && ($payload['interactive']['type'] ?? null) === 'button'
            && ($payload['interactive']['body']['text'] ?? null) === 'E ainda…';
    }

    public function sendStartTemplate(string $to): array
    {
        return [
            $this->client->sendTemplate(
                $to,
                config('services.whatsapp.start_template', 'boas_vindas'),
                config('services.whatsapp.start_template_language', 'pt_BR'),
                $this->startTemplateComponents(),
            ),
        ];
    }

    private function startTemplateComponents(): array
    {
        return array_values(array_filter([
            config('services.whatsapp.welcome_image_url') ? [
                'type' => 'header',
                'parameters' => [
                    [
                        'type' => 'image',
                        'image' => [
                            'link' => config('services.whatsapp.welcome_image_url'),
                        ],
                    ],
                ],
            ] : null,
            [
                'type' => 'button',
                'sub_type' => 'quick_reply',
                'index' => '0',
                'parameters' => [
                    [
                        'type' => 'payload',
                        'payload' => 'vamos_la',
                    ],
                ],
            ],
        ]));
    }
}
