<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWhatsappSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.whatsapp.app_secret');
        abort_if($secret === '', 503, 'WhatsApp webhook is not configured.');

        $provided = (string) $request->header('X-Hub-Signature-256');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        abort_unless(
            preg_match('/^sha256=[a-f0-9]{64}$/', $provided) === 1 && hash_equals($expected, $provided),
            403,
            'Forbidden.',
        );

        $phoneNumberId = (string) config('services.whatsapp.phone_number_id');
        if ($phoneNumberId !== '') {
            foreach ((array) $request->input('entry', []) as $entry) {
                foreach ((array) data_get($entry, 'changes', []) as $change) {
                    if (data_get($change, 'value.messages')) {
                        abort_unless(
                            hash_equals($phoneNumberId, (string) data_get($change, 'value.metadata.phone_number_id')),
                            403,
                            'Forbidden.',
                        );
                    }
                }
            }
        }

        return $next($request);
    }
}
