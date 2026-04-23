<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\ChatbotEngineException;
use App\Service\ChatbotRequestValidator;
use PHPUnit\Framework\TestCase;

final class ChatbotRequestValidatorTest extends TestCase
{
    public function testValidateAndBuildWithMinimalValidPayload(): void
    {
        $validator = new ChatbotRequestValidator();

        $request = $validator->validateAndBuild([
            'query' => 'What does vaccination status mean?',
            'top_k' => 3,
        ]);

        self::assertSame('What does vaccination status mean?', $request->getQuery());
        self::assertSame(3, $request->getTopK());
        self::assertNull($request->getRouteOverride());
        self::assertSame([], $request->getFilters());
    }

    public function testValidateAndBuildSupportsExtendedContractFields(): void
    {
        $validator = new ChatbotRequestValidator();

        $request = $validator->validateAndBuild([
            'query' => 'Explain vaccination confidence labels',
            'top_k' => 7,
            'min_score' => -0.2,
            'route_override' => 'field_definition',
            'disable_routing' => true,
            'rerank_pool_size' => 50,
            'debug' => true,
            'filters' => [
                'domain' => 'vaccination',
            ],
        ]);

        self::assertSame('Explain vaccination confidence labels', $request->getQuery());
        self::assertSame(7, $request->getTopK());
        self::assertSame(-0.2, $request->getMinScore());
        self::assertSame('field_definition', $request->getRouteOverride());
        self::assertTrue($request->isDisableRouting());
        self::assertSame(50, $request->getRerankPoolSize());
        self::assertTrue($request->isDebug());
        self::assertSame(['domain' => ['vaccination']], $request->getFilters());
    }

    public function testValidateAndBuildNormalizesStringAndArrayFilters(): void
    {
        $validator = new ChatbotRequestValidator();

        $request = $validator->validateAndBuild([
            'query' => 'Explain etat_sante',
            'filters' => [
                'domain' => 'animals',
                'language' => ['fr-en', 'en'],
            ],
        ]);

        self::assertSame([
            'domain' => ['animals'],
            'language' => ['fr-en', 'en'],
        ], $request->getFilters());
    }

    public function testValidateAndBuildRejectsUnsupportedTopLevelField(): void
    {
        $validator = new ChatbotRequestValidator();

        $this->expectException(ChatbotEngineException::class);

        try {
            $validator->validateAndBuild([
                'query' => 'Valid query',
                'unexpected' => 'value',
            ]);
        } catch (ChatbotEngineException $exception) {
            self::assertSame('validation_error', $exception->getErrorCode());
            self::assertArrayHasKey('violations', $exception->getDetails());
            self::assertArrayHasKey('request', $exception->getDetails()['violations']);
            throw $exception;
        }
    }

    public function testValidateAndBuildThrowsOnEmptyQuery(): void
    {
        $validator = new ChatbotRequestValidator();

        $this->expectException(ChatbotEngineException::class);

        try {
            $validator->validateAndBuild([
                'query' => '   ',
            ]);
        } catch (ChatbotEngineException $exception) {
            self::assertSame('validation_error', $exception->getErrorCode());
            self::assertArrayHasKey('violations', $exception->getDetails());
            throw $exception;
        }
    }
}
