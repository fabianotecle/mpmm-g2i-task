<?php

namespace App\Services\Documents;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TransferReceiptPdfService
{
    public function generate(
        float $amount,
        string $senderPhone,
        string $recipientPhone,
        string $controlCode,
        Carbon $transferredAt,
        bool $private,
        ?string $senderName = null,
        ?string $recipientName = null,
    ): array {
        $token = (string) Str::uuid();
        $storedFilename = "transferencia-mpmm-{$token}.pdf";
        $mode = $private ? 'privada' : 'publica';
        $downloadFilename = "MpMM transferencia {$mode} ".$transferredAt->format('Ymd His').'.pdf';
        $path = "transfer-receipts/{$storedFilename}";
        $logoPath = public_path('assets/whatsapp/logo.jpg');
        $pdf = Pdf::loadView('documents.transfer-receipt', [
            'amount' => $amount,
            'senderPhone' => $senderPhone,
            'recipientPhone' => $recipientPhone,
            'senderName' => $private ? null : $senderName,
            'recipientName' => $private ? null : $recipientName,
            'controlCode' => $controlCode,
            'transferredAt' => $transferredAt,
            'private' => $private,
            'logoData' => is_file($logoPath)
                ? 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($logoPath))
                : null,
        ])->setPaper('a4');

        Storage::disk('local')->put($path, $pdf->output());
        return [
            'filename' => $downloadFilename,
            'stored_filename' => $storedFilename,
            'path' => Storage::disk('local')->path($path),
            'url' => DocumentAccessUrl::for('documents.transfer-receipts', $storedFilename),
        ];
    }
}
