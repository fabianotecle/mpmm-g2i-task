<?php

namespace App\Services\Documents;

use Illuminate\Support\Facades\URL;

class DocumentAccessUrl
{
    public static function for(string $route, string $filename): string
    {
        $baseUrl = rtrim((string) config('services.documents.public_url', config('app.url')), '/');
        $path = URL::temporarySignedRoute(
            $route,
            now()->addMinutes((int) config('services.documents.link_ttl_minutes', 10080)),
            ['filename' => $filename],
            absolute: false,
        );

        return $baseUrl.$path;
    }
}
