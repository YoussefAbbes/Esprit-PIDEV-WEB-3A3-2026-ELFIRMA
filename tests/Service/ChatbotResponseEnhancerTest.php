<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\ChatbotRequest;
use App\Repository\VaccinationRepository;
use App\Service\ChatbotResponseEnhancer;
use PHPUnit\Framework\TestCase;

final class ChatbotResponseEnhancerTest extends TestCase
{
    public function testEnhanceReplacesStubForDueThisWeekVaccinationQuery(): void
    {
        $repository = $this->createMock(VaccinationRepository::class);
        $repository
            ->expects(self::once())
            ->method('findUpcomingForSmsAlerts')
            ->with(7)
            ->willReturn([
                [
                    'id_vaccination' => 11,
                    'id_animal' => 5,
                    'animal_type' => 'Cow',
                    'vaccine_name' => 'Brucellosis',
                    'date_next' => '2026-04-18',
                    'status' => 'Pending',
                ],
            ]);

        $enhancer = new ChatbotResponseEnhancer($repository);

        $request = new ChatbotRequest(
            query: 'which vaccinations are due this week',
            topK: null,
            minScore: null,
            routeOverride: null,
            disableRouting: false,
            rerankPoolSize: null,
            debug: false,
            filters: []
        );

        $response = $this->baseStubResponse();
        $enhanced = $enhancer->enhance($request, $response);

        self::assertNotSame($response['answer_text'], $enhanced['answer_text']);
        self::assertStringContainsString('due in the next 7 days', (string) $enhanced['answer_text']);
        self::assertSame('observed_value_lookup', $enhanced['route']);
        self::assertIsArray($enhanced['sources']);
        self::assertSame('database:vaccination', $enhanced['sources'][0]['source_file']);
        self::assertSame('confirmed', $enhanced['confidence_summary']['top_confidence']);
        self::assertSame('symfony_rule_override', $enhanced['llm']['mode']);
    }

    public function testEnhanceSkipsNonVaccinationQuery(): void
    {
        $repository = $this->createMock(VaccinationRepository::class);
        $repository
            ->expects(self::never())
            ->method('findUpcomingForSmsAlerts');

        $enhancer = new ChatbotResponseEnhancer($repository);

        $request = new ChatbotRequest(
            query: 'what is livestock capacity',
            topK: null,
            minScore: null,
            routeOverride: null,
            disableRouting: false,
            rerankPoolSize: null,
            debug: false,
            filters: []
        );

        $response = $this->baseStubResponse();
        $enhanced = $enhancer->enhance($request, $response);

        self::assertSame($response, $enhanced);
    }

    public function testEnhanceReplacesNonStubForDueThisWeekVaccinationQuery(): void
    {
        $repository = $this->createMock(VaccinationRepository::class);
        $repository
            ->expects(self::once())
            ->method('findUpcomingForSmsAlerts')
            ->with(7)
            ->willReturn([
                [
                    'id_vaccination' => 42,
                    'id_animal' => 9,
                    'animal_type' => 'Goat',
                    'vaccine_name' => 'Rabies',
                    'date_next' => '2026-04-20',
                    'status' => 'Scheduled',
                ],
            ]);

        $enhancer = new ChatbotResponseEnhancer($repository);

        $request = new ChatbotRequest(
            query: 'which vaccinations are due this week',
            topK: null,
            minScore: null,
            routeOverride: null,
            disableRouting: false,
            rerankPoolSize: null,
            debug: false,
            filters: []
        );

        $response = $this->baseStubResponse();
        $response['answer_text'] = 'Based on documentation, vaccination status is a workflow field.';

        $enhanced = $enhancer->enhance($request, $response);

        self::assertStringContainsString('due in the next 7 days', (string) $enhanced['answer_text']);
        self::assertSame('database:vaccination', $enhanced['sources'][0]['source_file']);
        self::assertSame('symfony_rule_override', $enhanced['llm']['mode']);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseStubResponse(): array
    {
        return [
            'contract_version' => '1.0.0',
            'query' => 'which vaccinations are due this week',
            'answer_text' => '[STUB] default fallback answer',
            'route' => 'field_definition',
            'sources' => [
                [
                    'source_file' => 'rag/corpus/vaccination_domain.md',
                    'section' => 'Vaccination Domain',
                    'document_type' => 'domain_guide',
                    'confidence' => 'inferred',
                    'score' => 0.7,
                ],
            ],
            'confidence_summary' => [
                'top_confidence' => 'inferred',
                'counts' => ['inferred' => 1],
            ],
            'evidence_summary' => [
                'chunk_count' => 1,
                'source_count' => 1,
                'document_type_counts' => ['domain_guide' => 1],
                'top_source' => [
                    'source_file' => 'rag/corpus/vaccination_domain.md',
                    'section' => 'Vaccination Domain',
                    'document_type' => 'domain_guide',
                    'confidence' => 'inferred',
                    'score' => 0.7,
                ],
            ],
            'context_metadata' => [
                'retrieved_count' => 1,
                'candidate_count' => 1,
                'top_k' => 1,
                'retrieval_pool_top_k' => 1,
                'active_filters' => [],
                'has_context_block' => true,
            ],
            'retrieval_debug' => [
                'detected_route' => 'field_definition',
                'preferred_document_types' => ['domain_guide'],
                'deprioritized_document_types' => [],
                'reranking_applied' => true,
                'order_changed' => false,
                'adjustments_applied' => 0,
                'top_candidates' => [],
            ],
            'llm' => [
                'mode' => 'local_stub',
                'enabled' => false,
                'implemented' => false,
            ],
        ];
    }
}
