<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Conversation\ConversationResponse;
use App\Services\Payments\AsaasPaymentProvider;
use App\Services\WhatsApp\WhatsappConversationSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AsaasWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        AsaasPaymentProvider $asaas,
        WhatsappConversationSender $whatsapp,
    ): JsonResponse {
        $result = $asaas->handleWebhook($request->getContent(), $request->headers->all());
        $notification = $result['notification'] ?? null;
        $inviterNotification = $result['inviter_notification'] ?? null;

        if ($notification && ! empty($notification['to']) && ! empty($result['movement_id'])) {
            $amount = number_format((float) ($notification['amount'] ?? 0), 2, ',', '.');
            $adminPhone = (string) config('services.whatsapp.asaas_activity_admin_phone', '');
            $whatsappName = Customer::where('phone', $notification['to'])->first()?->whatsappAccountName() ?? 'Não informado';
            $notifications = [
                [
                    'channel' => 'whatsapp',
                    'to' => $notification['to'],
                    'messages' => [
                        ConversationResponse::options(
                            "😃 Carga recebida\n\n*Valor:* R$*{$amount}*\n\n👋 Sempre que precisar, é só dar um oi aqui.",
                            [['label' => 'Continuar', 'value' => 'continuar']],
                        ),
                    ],
                ],
                [
                    'channel' => 'whatsapp',
                    'to' => $adminPhone,
                    'messages' => [
                        ConversationResponse::options(
                            "🏦 *Movimentação Asaas — Depósito recebido*\n\n*Valor:* R$ {$amount}\n*WhatsApp do membro:* +{$notification['to']}\n*Nome de WhatsApp do membro:* {$whatsappName}",
                            [['label' => 'Continuar', 'value' => 'continuar']],
                        ),
                    ],
                ],
            ];

            if ($inviterNotification && ! empty($inviterNotification['to'])) {
                $inviteAmount = number_format((float) $inviterNotification['amount'], 2, ',', '.');
                $name = $inviterNotification['name'];
                $notifications[] = [
                    'channel' => 'whatsapp',
                    'to' => $inviterNotification['to'],
                    'messages' => [ConversationResponse::text(
                        "💰 *Primeira carga de um convidado!*\n\n{$name} realizou a primeira carga de R$ {$inviteAmount}."
                    )],
                ];
            }

            $deliveries = $whatsapp->sendNotifications(['notifications' => $notifications]);
            $sent = collect($deliveries)
                ->flatMap(fn (array $item) => $item['delivery'] ?? [])
                ->every(fn (array $delivery) => ($delivery['status'] ?? null) === 'sent');

            $asaas->recordDepositNotification((int) $result['movement_id'], $sent, $deliveries);
            $result['notification_delivery'] = $sent ? 'sent' : 'pending_retry';
            unset($result['notification']);
            unset($result['inviter_notification']);
        }

        return response()->json($result);
    }
}
