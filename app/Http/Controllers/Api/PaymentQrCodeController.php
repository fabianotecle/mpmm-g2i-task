<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConversationSession;
use App\Services\Audit\CustomerAuditContext;
use App\Services\Conversation\ConversationEngine;
use App\Services\QrCodes\PaymentQrCodeService;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentQrCodeController extends Controller
{
    public function create(Request $request, ConversationSession $conversationSession, PaymentQrCodeService $codes): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:transfer_open,transfer_fixed,cash_in'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:150000'],
        ]);
        $conversationSession->loadMissing('customer');
        abort_unless($conversationSession->customer, 422, 'This conversation has no member.');

        if (in_array($data['type'], ['transfer_fixed', 'cash_in'], true) && ! array_key_exists('amount', $data)) {
            return response()->json(['message' => 'The amount field is required for this QR code.'], 422);
        }

        $qr = $codes->create($conversationSession->customer, $data['type'], $data['amount'] ?? null);
        $qr['image_url'] = route('qr-codes.svg', ['token' => $qr['token']]);

        return response()->json($qr, 201);
    }

    public function show(string $token, PaymentQrCodeService $codes): JsonResponse
    {
        try {
            $qr = $codes->resolve($token);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        return response()->json([
            'type' => $qr['type'],
            'amount' => $qr['amount'],
            'expires_at' => date(DATE_ATOM, $qr['expires_at']),
            'recipient_name' => $qr['recipient']->whatsappDisplayName(),
        ]);
    }

    public function start(Request $request, string $token, PaymentQrCodeService $codes, ConversationEngine $engine, CustomerAuditContext $audit): JsonResponse
    {
        $data = $request->validate(['external_user_id' => ['required', 'string', 'regex:/^[1-9]\d{7,14}$/'], 'channel' => ['nullable', 'string', 'max:40']]);
        try {
            $qr = $codes->resolve($token);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        $response = $engine->startFromPaymentQr($data['channel'] ?? 'api', $data['external_user_id'], $qr);
        $audit->attachSessionId($response['session_id'] ?? null);

        return response()->json($response, 201);
    }

    public function svg(string $token, PaymentQrCodeService $codes)
    {
        try {
            $codes->resolve($token);
        } catch (\InvalidArgumentException $exception) {
            abort(404, $exception->getMessage());
        }

        // The QR opens WhatsApp with an opaque command. The scanner must send
        // that command, then the webhook resolves the encrypted payment token.
        $result = (new Builder(
            writer: new SvgWriter(),
            data: $codes->whatsappUrl($token),
            size: 640,
            margin: 16,
        ))->build();

        return response($result->getString(), 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, max-age=3600']);
    }

    public function png(string $token, PaymentQrCodeService $codes)
    {
        try {
            $codes->resolve($token);
        } catch (\InvalidArgumentException $exception) {
            abort(404, $exception->getMessage());
        }

        $result = (new Builder(
            writer: new PngWriter(),
            data: $codes->whatsappUrl($token),
            size: 640,
            margin: 16,
        ))->build();

        return response($result->getString(), 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, max-age=3600']);
    }
}
