<?php

namespace App\Http\Middleware;

use App\Models\ConversationSession;
use App\Services\Audit\CustomerAuditContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CustomerAuditMiddleware
{
    public function __construct(private readonly CustomerAuditContext $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->route('conversationSession');
        $session = $session instanceof ConversationSession ? $session : null;

        $context = match (true) {
            $request->is('api/admin/*') => [
                'source' => 'admin_panel',
                'actor_user_id' => $request->user()?->getAuthIdentifier(),
            ],
            $request->is('api/conversations/*') || $request->is('api/qr-codes/*/start') => [
                'source' => 'api_chat',
                'actor_phone' => $session?->external_user_id ?: $request->input('external_user_id'),
                'actor_customer_id' => $session?->customer_id,
                'conversation_session_id' => $session?->id,
            ],
            $request->is('api/webhooks/whatsapp*') => ['source' => 'whatsapp_webhook'],
            $request->is('api/webhooks/asaas') => ['source' => 'payment_webhook'],
            default => ['source' => 'api'],
        };

        return $this->audit->run($context, fn () => $next($request));
    }
}
