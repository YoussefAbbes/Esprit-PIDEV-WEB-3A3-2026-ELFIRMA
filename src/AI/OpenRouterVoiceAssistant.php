<?php

namespace App\AI;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenRouterVoiceAssistant
{
    private const API_URL = 'https://openrouter.ai/api/v1/chat/completions';
    private const MODEL = 'openai/gpt-4o-mini';
    private const TIMEOUT = 10;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
    ) {
    }

    public function parse(string $transcript, array $productNames): array
    {
        if (empty($transcript) || empty($productNames)) {
            return [
                'intent' => 'unknown',
                'product' => null,
                'query' => null,
            ];
        }

        $intents = [
            'help',
            'catalog_search',
            'catalog_read_products',
            'catalog_details',
            'catalog_add',
            'catalog_order',
            'catalog_open_cart',
        ];

        $systemPrompt = sprintf(
            'You are a voice assistant for an agricultural product catalog. '
            . 'Available intents: %s. '
            . 'Available products: %s. '
            . 'User said: "%s". '
            . 'Reply with ONLY a JSON object like {"intent":"...","product":null or "product name","query":null or "search query"} with no extra text.',
            implode(', ', $intents),
            json_encode($productNames),
            addslashes($transcript),
        );

        try {
            $response = $this->httpClient->request('POST', self::API_URL, [
                'timeout' => self::TIMEOUT,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'HTTP-Referer' => 'http://localhost:8000',
                    'X-Title' => 'EL FIRMA',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => self::MODEL,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $systemPrompt,
                        ],
                    ],
                    'temperature' => 0.3,
                    'max_tokens' => 150,
                ],
            ]);

            $data = $response->toArray();

            if (!isset($data['choices'][0]['message']['content'])) {
                return [
                    'intent' => 'unknown',
                    'product' => null,
                    'query' => null,
                ];
            }

            $content = trim($data['choices'][0]['message']['content']);

            $parsed = json_decode($content, true);
            if (!is_array($parsed)) {
                return [
                    'intent' => 'unknown',
                    'product' => null,
                    'query' => null,
                ];
            }

            return [
                'intent' => (string) ($parsed['intent'] ?? 'unknown'),
                'product' => isset($parsed['product']) && is_string($parsed['product']) && $parsed['product'] !== '' ? $parsed['product'] : null,
                'query' => isset($parsed['query']) && is_string($parsed['query']) && $parsed['query'] !== '' ? $parsed['query'] : null,
            ];
        } catch (\Throwable $e) {
            error_log('OpenRouter voice assistant error: ' . $e->getMessage());

            return [
                'intent' => 'unknown',
                'product' => null,
                'query' => null,
            ];
        }
    }
}
