<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Carbon;

class WhatsappInboundMessage
{
    public function __construct(
        public readonly string $from,
        public readonly string $id,
        public readonly string $text,
        public readonly ?Carbon $timestamp = null,
        public readonly array $raw = [],
        public readonly ?string $whatsappId = null,
        public readonly ?string $phone = null,
        public readonly ?string $username = null,
        public readonly ?string $profileName = null,
        public readonly ?string $replyTo = null,
    ) {
    }
}
