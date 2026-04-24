<?php

declare(strict_types=1);

namespace App\Dto;

final class ChatbotRequest
{
    /**
     * @param array<string, list<string>> $filters
     */
    public function __construct(
        private readonly string $query,
        private readonly ?int $topK,
        private readonly ?float $minScore,
        private readonly ?string $routeOverride,
        private readonly bool $disableRouting,
        private readonly ?int $rerankPoolSize,
        private readonly bool $debug,
        private readonly array $filters = []
    ) {
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getTopK(): ?int
    {
        return $this->topK;
    }

    public function getMinScore(): ?float
    {
        return $this->minScore;
    }

    public function getRouteOverride(): ?string
    {
        return $this->routeOverride;
    }

    public function isDisableRouting(): bool
    {
        return $this->disableRouting;
    }

    public function getRerankPoolSize(): ?int
    {
        return $this->rerankPoolSize;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * @return array<string, list<string>>
     */
    public function getFilters(): array
    {
        return $this->filters;
    }
}
