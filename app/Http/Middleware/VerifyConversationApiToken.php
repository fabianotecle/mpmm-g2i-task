<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyConversationApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('mpmm.conversation_api.token');
        $provided = (string) $request->header('X-MPMM-Internal-Token');

        abort_if(strlen($expected) < 32, 503, 'Conversation API is not configured.');
        abort_unless($provided !== '' && hash_equals($expected, $provided), 403, 'Forbidden.');

        return $next($request);
    }
}
