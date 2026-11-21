<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\Invites\InviteTracker;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class InviteLinkController extends Controller
{
    public function __construct(private readonly InviteTracker $invites)
    {
    }

    public function __invoke(Request $request, string $code): Response
    {
        $phone = $request->query('phone');

        if (is_string($phone) && $phone !== '') {
            $this->invites->recordLinkReceived($code, $phone, [
                'name' => $request->query('name'),
                'source' => 'invite_link',
            ]);
        }

        abort_unless($this->invites->codeExists($code), 404);

        return response(
            "Convite *_MyPhone MyMoney®_* registrado.\n\nEnvie um oi no WhatsApp para começar.",
            200,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }
}
