<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class TransferReceiptImageController extends Controller
{
    public function __invoke(string $filename): Response
    {
        abort_unless(preg_match('/^transferencia-mpmm-[0-9a-f-]{36}\.png$/i', $filename), 404);
        $path = 'transfer-receipt-images/'.$filename;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response(Storage::disk('local')->get($path), 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
