<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class StatementDocumentController extends Controller
{
    public function __invoke(string $filename): Response
    {
        abort_unless(preg_match('/^extrato-(?:admin-)?mpmm-[0-9a-f-]{36}\.pdf$/i', $filename), 404);

        $path = 'statements/'.$filename;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response(Storage::disk('local')->get($path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
