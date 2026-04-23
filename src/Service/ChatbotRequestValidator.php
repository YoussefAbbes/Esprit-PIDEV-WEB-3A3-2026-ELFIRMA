<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ChatbotRequest;
use App\Exception\ChatbotEngineException;
use Symfony\Component\HttpFoundation\Response;

final class ChatbotRequestValidator
{
    private const MAX_QUERY_LENGTH = 2000;
    private const MAX_TOP_K = 20;
    private const MIN_SCORE_MIN = -1.0;
    private const MIN_SCORE_MAX = 1.0;
    private const MAX_RERANK_POOL_SIZE = 200;

    private const ALLOWED_PAYLOAD_KEYS = [
        'query',
        'top_k',
        'min_score',
        'route_override',
        'disable_routing',
        'rerank_pool_size',
        'filters',
        'debug',
    ];

    private const ALLOWED_ROUTE_OVERRIDES = [
        'field_definition',
        'observed_value_lookup',
        'structural_relationship',
        'policy_unknown',
        'example_request',
    ];

    private const ALLOWED_FILTER_KEYS = [
        'domain',
        'document_type',
        'confidence',
        'language',
        'evidence_scope',
    ];

    /**
     * @param mixed $payload
     */
    public function validateAndBuild(mixed $payload): ChatbotRequest
    {
        if (!\is_array($payload)) {
            throw new ChatbotEngineException(
                'validation_error',
                'Request body must be a valid JSON object.',
                Response::HTTP_BAD_REQUEST
            );
        }

        $errors = [];

        foreach (array_keys($payload) as $key) {
            if (!\is_string($key) || !\in_array($key, self::ALLOWED_PAYLOAD_KEYS, true)) {
                $errors['request'][] = sprintf('Unsupported field: %s', (string) $key);
            }
        }

        $query = $payload['query'] ?? null;
        if (!\is_string($query)) {
            $errors['query'][] = 'query must be a string.';
        } else {
            $query = trim($query);
            if ($query == '') {
                $errors['query'][] = 'query must not be empty.';
            }
            if ($this->safeLength($query) > self::MAX_QUERY_LENGTH) {
                $errors['query'][] = sprintf('query must be at most %d characters.', self::MAX_QUERY_LENGTH);
            }
        }

        $topK = $payload['top_k'] ?? null;
        if ($topK !== null) {
            if (!\is_int($topK)) {
                $errors['top_k'][] = 'top_k must be an integer.';
            } elseif ($topK <= 0 || $topK > self::MAX_TOP_K) {
                $errors['top_k'][] = sprintf('top_k must be between 1 and %d.', self::MAX_TOP_K);
            }
        }

        $minScore = $payload['min_score'] ?? null;
        if ($minScore !== null) {
            if (!\is_int($minScore) && !\is_float($minScore)) {
                $errors['min_score'][] = 'min_score must be a number.';
            } else {
                $normalizedMinScore = (float) $minScore;
                if ($normalizedMinScore < self::MIN_SCORE_MIN || $normalizedMinScore > self::MIN_SCORE_MAX) {
                    $errors['min_score'][] = sprintf(
                        'min_score must be between %s and %s.',
                        (string) self::MIN_SCORE_MIN,
                        (string) self::MIN_SCORE_MAX
                    );
                }
            }
        }

        $routeOverride = $payload['route_override'] ?? null;
        $normalizedRouteOverride = null;
        if ($routeOverride !== null) {
            if (!\is_string($routeOverride) || trim($routeOverride) == '') {
                $errors['route_override'][] = 'route_override must be a non-empty string when provided.';
            } else {
                $routeOverride = trim($routeOverride);
            }

            if (\is_string($routeOverride) && !\in_array($routeOverride, self::ALLOWED_ROUTE_OVERRIDES, true)) {
                $errors['route_override'][] = 'route_override is not a supported route.';
            } elseif (\is_string($routeOverride)) {
                $normalizedRouteOverride = $routeOverride;
            }
        }

        $disableRouting = $payload['disable_routing'] ?? false;
        if (!\is_bool($disableRouting)) {
            $errors['disable_routing'][] = 'disable_routing must be a boolean.';
        }

        $rerankPoolSize = $payload['rerank_pool_size'] ?? null;
        if ($rerankPoolSize !== null) {
            if (!\is_int($rerankPoolSize)) {
                $errors['rerank_pool_size'][] = 'rerank_pool_size must be an integer.';
            } elseif ($rerankPoolSize < 0 || $rerankPoolSize > self::MAX_RERANK_POOL_SIZE) {
                $errors['rerank_pool_size'][] = sprintf(
                    'rerank_pool_size must be between 0 and %d.',
                    self::MAX_RERANK_POOL_SIZE
                );
            }
        }

        $debug = $payload['debug'] ?? false;
        if (!\is_bool($debug)) {
            $errors['debug'][] = 'debug must be a boolean.';
        }

        $normalizedFilters = [];
        $filters = $payload['filters'] ?? null;
        if ($filters !== null) {
            if (!\is_array($filters)) {
                $errors['filters'][] = 'filters must be an object.';
            } else {
                foreach ($filters as $key => $rawValue) {
                    if (!\is_string($key) || !\in_array($key, self::ALLOWED_FILTER_KEYS, true)) {
                        $errors['filters'][] = sprintf('Unsupported filter key: %s', (string) $key);
                        continue;
                    }

                    $normalizedValue = $this->normalizeFilterValue($rawValue);
                    if ($normalizedValue === null) {
                        $errors[sprintf('filters.%s', $key)][] = 'Filter value must be a string or array of strings.';
                        continue;
                    }

                    if ($normalizedValue !== []) {
                        $normalizedFilters[$key] = $normalizedValue;
                    }
                }
            }
        }

        if ($errors !== []) {
            throw new ChatbotEngineException(
                'validation_error',
                'Invalid chat request payload.',
                Response::HTTP_BAD_REQUEST,
                ['violations' => $errors]
            );
        }

        return new ChatbotRequest(
            query: (string) $query,
            topK: \is_int($topK) ? $topK : null,
            minScore: (\is_int($minScore) || \is_float($minScore)) ? (float) $minScore : null,
            routeOverride: $normalizedRouteOverride,
            disableRouting: (bool) $disableRouting,
            rerankPoolSize: \is_int($rerankPoolSize) ? $rerankPoolSize : null,
            debug: (bool) $debug,
            filters: $normalizedFilters
        );
    }

    private function safeLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value);
        }

        return strlen($value);
    }

    /**
     * @param mixed $value
     *
     * @return list<string>|null
     */
    private function normalizeFilterValue(mixed $value): ?array
    {
        if (\is_string($value)) {
            $trimmed = trim($value);
            return $trimmed === '' ? [] : [$trimmed];
        }

        if (!\is_array($value)) {
            return null;
        }

        $result = [];
        foreach ($value as $item) {
            if (!\is_string($item)) {
                return null;
            }
            $trimmed = trim($item);
            if ($trimmed !== '') {
                $result[] = $trimmed;
            }
        }

        return $result;
    }
}
