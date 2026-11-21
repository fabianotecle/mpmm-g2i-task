<?php

namespace App\Services\Conversation;

class ConversationResponse
{
    public static function make(
        array $messages,
        ?array $expectedInput = null,
        ?array $options = null,
        ?string $transitionTo = null,
        array $notifications = [],
    ): array {
        return array_filter([
            'messages' => $messages,
            'expected_input' => $expectedInput,
            'options' => $options,
            'transition_to' => $transitionTo,
            'notifications' => $notifications ?: null,
        ], fn ($value) => $value !== null);
    }

    public static function text(string $text): array
    {
        return [
            'type' => 'text',
            'text' => $text,
        ];
    }

    public static function options(string $text, array $options, ?string $listButton = null): array
    {
        return array_filter([
            'type' => 'options',
            'text' => $text,
            'options' => $options,
            'list_button' => $listButton,
        ], fn ($value) => $value !== null);
    }
}
