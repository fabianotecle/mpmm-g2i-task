<?php

namespace App\Services\Conversation\Contracts;

use App\Models\ConversationSession;

interface ConversationFlow
{
    public function enter(ConversationSession $session): array;

    public function handle(ConversationSession $session, string $message): array;
}
