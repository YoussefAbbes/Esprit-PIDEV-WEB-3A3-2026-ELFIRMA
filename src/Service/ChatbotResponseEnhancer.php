<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ChatbotRequest;
use App\Repository\VaccinationRepository;

final class ChatbotResponseEnhancer
{
    public function __construct(
        private readonly VaccinationRepository $vaccinationRepository
    ) {
    }

    /**
     * @param array<string, mixed> $chatResponse
     *
     * @return array<string, mixed>
     */
    public function enhance(ChatbotRequest $request, array $chatResponse): array
    {
        if (!$this->isVaccinationDueThisWeekQuery($request->getQuery())) {
            return $chatResponse;
        }

        $upcomingVaccinations = $this->vaccinationRepository->findUpcomingForSmsAlerts(7);
        $chatResponse['answer_text'] = $this->buildDueThisWeekAnswer($upcomingVaccinations);
        $chatResponse['route'] = 'observed_value_lookup';

        $sources = $this->buildDatabaseSources($upcomingVaccinations);
        $chatResponse['sources'] = $sources;

        $sourceCount = count($sources);
        $chatResponse['confidence_summary'] = [
            'top_confidence' => 'confirmed',
            'counts' => ['confirmed' => $sourceCount],
        ];

        $chatResponse['evidence_summary'] = [
            'chunk_count' => $sourceCount,
            'source_count' => 1,
            'document_type_counts' => ['observed_value' => $sourceCount],
            'top_source' => $sources[0],
        ];

        $chatResponse['context_metadata'] = [
            'retrieved_count' => $sourceCount,
            'candidate_count' => $sourceCount,
            'top_k' => $sourceCount,
            'retrieval_pool_top_k' => $sourceCount,
            'active_filters' => [
                'entity' => 'vaccination',
                'time_window' => 'next_7_days',
            ],
            'has_context_block' => false,
        ];

        $chatResponse['retrieval_debug'] = [
            'detected_route' => 'observed_value_lookup',
            'preferred_document_types' => ['observed_value'],
            'deprioritized_document_types' => [],
            'reranking_applied' => false,
            'order_changed' => false,
            'adjustments_applied' => 0,
            'top_candidates' => [],
        ];

        if (isset($chatResponse['llm']) && is_array($chatResponse['llm'])) {
            $chatResponse['llm']['mode'] = 'symfony_rule_override';
            $chatResponse['llm']['enabled'] = false;
            $chatResponse['llm']['implemented'] = false;
        }

        return $chatResponse;
    }

    private function isVaccinationDueThisWeekQuery(string $query): bool
    {
        $text = strtolower(trim($query));
        if ($text === '') {
            return false;
        }

        $mentionsVaccination = str_contains($text, 'vaccin');
        $mentionsDue = str_contains($text, 'due')
            || str_contains($text, 'upcoming')
            || str_contains($text, 'next')
            || str_contains($text, 'prochain')
            || str_contains($text, 'a venir');
        $mentionsWeekWindow = str_contains($text, 'this week')
            || str_contains($text, 'week')
            || str_contains($text, '7 day')
            || str_contains($text, '7-day')
            || str_contains($text, 'semaine');

        return $mentionsVaccination && ($mentionsDue || $mentionsWeekWindow);
    }

    /**
     * @param list<array{id_vaccination:int,id_animal:int,animal_type:string,vaccine_name:string,date_next:string,status:?string}> $upcomingVaccinations
     */
    private function buildDueThisWeekAnswer(array $upcomingVaccinations): string
    {
        $count = count($upcomingVaccinations);
        if ($count === 0) {
            return 'No vaccinations are due in the next 7 days.';
        }

        $lines = [sprintf('%d vaccination(s) are due in the next 7 days:', $count)];
        $maxItems = 8;

        foreach (array_slice($upcomingVaccinations, 0, $maxItems) as $vaccination) {
            $animalType = trim((string) ($vaccination['animal_type'] ?? 'Animal'));
            $vaccineName = trim((string) ($vaccination['vaccine_name'] ?? 'Vaccine'));
            $dateNext = $this->formatDate((string) ($vaccination['date_next'] ?? ''));
            $status = trim((string) ($vaccination['status'] ?? ''));
            $statusSuffix = $status !== '' ? sprintf(' [%s]', $status) : '';

            $lines[] = sprintf('- %s: %s, due on %s%s', $animalType, $vaccineName, $dateNext, $statusSuffix);
        }

        if ($count > $maxItems) {
            $lines[] = sprintf('- ...and %d more.', $count - $maxItems);
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array{id_vaccination:int,id_animal:int,animal_type:string,vaccine_name:string,date_next:string,status:?string}> $upcomingVaccinations
     *
     * @return list<array{source_file:string,section:string,document_type:string,confidence:string,score:float}>
     */
    private function buildDatabaseSources(array $upcomingVaccinations): array
    {
        if ($upcomingVaccinations === []) {
            return [[
                'source_file' => 'database:vaccination',
                'section' => 'No rows due in next 7 days.',
                'document_type' => 'observed_value',
                'confidence' => 'confirmed',
                'score' => 1.0,
            ]];
        }

        $sources = [];
        foreach (array_slice($upcomingVaccinations, 0, 10) as $vaccination) {
            $animalType = trim((string) ($vaccination['animal_type'] ?? 'Animal'));
            $vaccineName = trim((string) ($vaccination['vaccine_name'] ?? 'Vaccine'));
            $dateNext = $this->formatDate((string) ($vaccination['date_next'] ?? ''));

            $sources[] = [
                'source_file' => 'database:vaccination',
                'section' => sprintf('%s | %s | due %s', $animalType, $vaccineName, $dateNext),
                'document_type' => 'observed_value',
                'confidence' => 'confirmed',
                'score' => 1.0,
            ];
        }

        return $sources;
    }

    private function formatDate(string $rawDate): string
    {
        $rawDate = trim($rawDate);
        if ($rawDate === '') {
            return 'unknown date';
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $rawDate);
        if ($date === false) {
            return $rawDate;
        }

        return $date->format('Y-m-d');
    }
}
