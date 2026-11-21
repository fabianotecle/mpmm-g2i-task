<?php

namespace App\Services\WhatsApp;

use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\Customer;
use App\Models\WhatsappNotificationDelivery;
use Illuminate\Support\Carbon;

class WhatsappTemplateHistory
{
    public function record(
        string $phone,
        string $templateName,
        array $components,
        ?WhatsappNotificationDelivery $tracking,
        string $step,
        ?string $providerMessageId = null,
        ?Carbon $acceptedAt = null,
        bool $recovered = false,
        bool $timeEstimated = false,
    ): ?ConversationMessage {
        $customer = Customer::query()->where('phone', preg_replace('/\D+/', '', $phone))->first();
        if (! $customer) {
            return null;
        }

        if ($tracking) {
            $existing = ConversationMessage::query()
                ->where('external_channel', 'whatsapp')
                ->where('payload->notification_delivery_id', $tracking->id)
                ->where('payload->notification_step', $step)
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        if ($providerMessageId) {
            $existing = ConversationMessage::query()
                ->where('external_channel', 'whatsapp')
                ->where('external_message_id', $providerMessageId)
                ->first();
            if ($existing) {
                return $existing;
            }
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
            'last_interaction_at' => $acceptedAt ?? now(),
        ]);

        $acceptedAt ??= now();
        $message = new ConversationMessage();
        $message->forceFill([
            'conversation_session_id' => $session->id,
            'direction' => 'outbound',
            'type' => 'template',
            'payload' => [
                'type' => 'template',
                'text' => $this->summary($templateName, $components),
                'template_name' => $templateName,
                'template_components' => $components,
                'notification_delivery_id' => $tracking?->id,
                'notification_step' => $step,
                'provider_message_id' => $providerMessageId,
                'history_source' => $recovered ? 'recovered_notification' : 'api_acceptance',
                'delivery_status' => 'accepted',
                'time_estimated' => $timeEstimated,
            ],
            'external_channel' => 'whatsapp',
            'external_message_id' => $providerMessageId,
            'external_timestamp' => $acceptedAt,
            'created_at' => $acceptedAt,
            'updated_at' => $acceptedAt,
        ])->save();

        return $message;
    }

    private function summary(string $templateName, array $components): string
    {
        $parameters = collect($components)->firstWhere('type', 'body')['parameters'] ?? [];

        if ($templateName === config('services.whatsapp.transfer_notification_template', 'aviso_transferencia_v1')) {
            $amount = $parameters[0]['text'] ?? null;
            $sender = $parameters[1]['text'] ?? null;

            return implode("\n", array_filter([
                '📨 Aviso de transferência recebida (modelo WhatsApp)',
                $amount ? 'Valor: R$ '.$amount : null,
                $sender ? 'De: '.$sender : null,
                'Resumo do envio; o texto exato do modelo não foi arquivado.',
            ]));
        }

        if ($templateName === config('services.whatsapp.start_template', 'boas_vindas')) {
            return "📨 Boas-vindas (modelo WhatsApp)\nResumo do envio; o texto exato do modelo não foi arquivado.";
        }

        return "📨 Modelo WhatsApp: {$templateName}\nResumo do envio; o texto exato do modelo não foi arquivado.";
    }
}
