<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConversationSession;
use App\Services\Audit\CustomerAuditContext;
use App\Services\Conversation\ConversationEngine;
use App\Services\WhatsApp\WhatsappConversationSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function __construct(
        private readonly ConversationEngine $engine,
        private readonly WhatsappConversationSender $whatsappSender,
        private readonly CustomerAuditContext $audit,
    ) {
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['nullable', 'string', 'max:40'],
            'external_user_id' => ['required', 'string', 'regex:/^[1-9]\d{7,14}$/'],
            'metadata' => ['nullable', 'array'],
        ]);

        $channel = $data['channel'] ?? 'api';
        $response = $this->engine->start(
            $channel,
            $data['external_user_id'],
            $data['metadata'] ?? [],
        );
        $this->audit->attachSessionId($response['session_id'] ?? null);

        if ($channel === 'whatsapp') {
            $response['delivery'] = $this->whatsappSender->send($data['external_user_id'], $response);
        }

        return response()->json($response, 201);
    }

    public function show(ConversationSession $conversationSession): JsonResponse
    {
        $conversationSession->load(['customer', 'messages' => fn ($query) => $query->latest()->limit(20)]);

        return response()->json([
            'session_id' => $conversationSession->id,
            'state' => "{$conversationSession->current_flow}.{$conversationSession->current_step}",
            'status' => $conversationSession->status,
            'channel' => $conversationSession->channel,
            'external_user_id' => $conversationSession->external_user_id,
            'customer' => $conversationSession->customer,
            'context' => $conversationSession->context ?? [],
            'messages' => $conversationSession->messages,
        ]);
    }

    public function message(Request $request, ConversationSession $conversationSession): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string'],
            'metadata' => ['nullable', 'array'],
        ]);

        $response = $this->engine->receive(
            $conversationSession,
            $data['message'],
            $data['metadata'] ?? [],
        );

        if ($conversationSession->channel === 'whatsapp') {
            $response['delivery'] = $this->whatsappSender->send($conversationSession->external_user_id, $response);
            $response['notification_delivery'] = $this->whatsappSender->sendNotifications($response);
        }

        return response()->json($response);
    }

    public function reset(ConversationSession $conversationSession): JsonResponse
    {
        return response()->json($this->engine->reset($conversationSession));
    }
}
