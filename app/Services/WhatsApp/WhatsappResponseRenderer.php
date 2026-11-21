<?php

namespace App\Services\WhatsApp;

class WhatsappResponseRenderer
{
    /**
     * @return array<int, array>
     */
    public function render(array $response): array
    {
        return collect($response['messages'] ?? [])
            ->map(fn (array $message) => $this->renderMessage($message))
            ->filter()
            ->values()
            ->all();
    }

    private function renderMessage(array $message): ?array
    {
        if (($message['type'] ?? null) === 'copyable_code') {
            if (empty($message['template_name'])) {
                return [
                    'type' => 'text',
                    'text' => ['body' => $message['text'] ?? ''],
                ];
            }

            $code = $message['text'] ?? '';

            return [
                'type' => 'template',
                'template' => [
                    'name' => $message['template_name'],
                    'language' => ['code' => $message['language'] ?? 'pt_BR'],
                    'components' => [
                        [
                            'type' => 'body',
                            'parameters' => [['type' => 'text', 'text' => $code]],
                        ],
                        [
                            'type' => 'button',
                            'sub_type' => 'copy_code',
                            'index' => '0',
                            'parameters' => [['type' => 'coupon_code', 'coupon_code' => $code]],
                        ],
                    ],
                ],
            ];
        }

        if (($message['type'] ?? null) === 'cta_url') {
            return [
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'cta_url',
                    'body' => ['text' => $message['text'] ?? ''],
                    'action' => [
                        'name' => 'cta_url',
                        'parameters' => [
                            'display_text' => $message['label'] ?? 'Abrir',
                            'url' => $message['url'] ?? '',
                        ],
                    ],
                ],
            ];
        }

        if (($message['type'] ?? null) === 'text') {
            return [
                'type' => 'text',
                'text' => [
                    'body' => $message['text'] ?? '',
                ],
            ];
        }

        if (($message['type'] ?? null) === 'document') {
            if (empty($message['url'])) {
                return [
                    'type' => 'text',
                    'text' => [
                        'body' => $message['text'] ?? $message['filename'] ?? 'Documento',
                    ],
                ];
            }

            return [
                'type' => 'document',
                'document' => [
                    'link' => $message['url'],
                    'filename' => $message['filename'] ?? 'Documento.pdf',
                    'caption' => $message['text'] ?? $message['filename'] ?? null,
                ],
                '_local_path' => $message['path'] ?? null,
            ];
        }

        if (($message['type'] ?? null) === 'image') {
            if (empty($message['url'])) {
                return [
                    'type' => 'text',
                    'text' => [
                        'body' => $message['text'] ?? 'Imagem',
                    ],
                ];
            }

            return [
                'type' => 'image',
                'image' => [
                    'link' => $message['url'],
                    'caption' => $message['text'] ?? null,
                ],
                '_local_path' => $message['path'] ?? null,
            ];
        }

        if (($message['type'] ?? null) === 'options') {
            if (count($message['options'] ?? []) <= 3 && empty($message['force_list'])) {
                $interactive = [
                    'type' => 'interactive',
                    'interactive' => [
                        'type' => 'button',
                        'body' => [
                            'text' => $message['text'] ?? '',
                        ],
                        'action' => [
                            'buttons' => collect($message['options'] ?? [])
                                ->map(fn (array $option) => [
                                    'type' => 'reply',
                                    'reply' => [
                                        'id' => $option['value'] ?? $option['action'] ?? $option['label'],
                                        'title' => $this->optionTitle($option, 20),
                                    ],
                                ])
                                ->values()
                                ->all(),
                        ],
                    ],
                ];

                if (! empty($message['header_image_url'])) {
                    $interactive['interactive']['header'] = [
                        'type' => 'image',
                        'image' => [
                            'link' => $message['header_image_url'],
                        ],
                    ];
                }

                return $interactive;
            }

            return [
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'list',
                    'body' => [
                        'text' => $message['text'] ?? '',
                    ],
                    'action' => [
                        'button' => mb_substr((string) ($message['list_button'] ?? 'Continuar'), 0, 20),
                        'sections' => [
                            [
                                'title' => 'Opções',
                                'rows' => collect($message['options'] ?? [])
                                    ->map(function (array $option): array {
                                        $row = [
                                            'id' => $option['value'] ?? $option['action'] ?? $option['label'],
                                            'title' => $this->optionTitle($option, 24),
                                        ];

                                        if (! empty($option['description'])) {
                                            $row['description'] = mb_substr((string) $option['description'], 0, 72);
                                        }

                                        return $row;
                                    })
                                    ->values()
                                    ->all(),
                            ],
                        ],
                    ],
                ],
            ];
        }

        return null;
    }

    private function optionTitle(array $option, int $limit): string
    {
        return mb_substr((string) ($option['label'] ?? $option['value'] ?? 'Opcao'), 0, $limit);
    }
}
