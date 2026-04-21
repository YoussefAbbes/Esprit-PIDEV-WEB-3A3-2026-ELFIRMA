<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ClientScoringService
{
    private const OPENAI_RESPONSES_API = 'https://api.openai.com/v1/responses';

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /**
     * @param array{total_orders:int,total_spent:float,cancellations:int,client_reference?:string} $clientData
     *
     * @return array{ok:bool,score:int,category:string,explanation:string,raw_response?:array<string,mixed>,error?:string}
     */
    public function calculateClientScore(array $clientData): array
    {
        $apiKey = $this->getOpenAiApiKey();
        if ($apiKey === '') {
            return [
                'ok' => false,
                'score' => 0,
                'category' => 'normal',
                'explanation' => 'OPENAI_API_KEY manquante.',
                'error' => 'missing_api_key',
            ];
        }

        $payload = [
            'model' => 'gpt-4.1-mini',
            'input' => $this->buildPrompt($clientData),
            'temperature' => 0.2,
        ];

        try {
            $response = $this->httpClient->request('POST', self::OPENAI_RESPONSES_API, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray(false);
            if ($statusCode >= 400) {
                $apiMessage = $this->extractApiErrorMessage($data);

                if ($this->isQuotaOrBillingError($apiMessage)) {
                    return $this->buildFallbackScore($clientData, 'Mode fallback: quota OpenAI depasse. Score calcule localement.');
                }

                return [
                    'ok' => false,
                    'score' => 0,
                    'category' => 'normal',
                    'explanation' => $apiMessage !== '' ? $apiMessage : 'Erreur retournee par OpenAI.',
                    'error' => 'openai_api_error',
                    'raw_response' => is_array($data) ? $data : [],
                ];
            }

            $text = $this->extractTextResponse($data);
            $jsonText = $this->extractJsonObject($text);
            $decoded = json_decode($jsonText, true);

            if (!is_array($decoded)) {
                $fallback = $this->buildFallbackScore($clientData, 'Mode fallback: format de reponse IA invalide, score calcule localement.');
                $fallback['error'] = 'invalid_openai_payload';
                $fallback['raw_response'] = is_array($data) ? $data : [];

                return $fallback;
            }

            $score = max(0, min(100, (int) ($decoded['score'] ?? 0)));
            $category = $this->normalizeCategory((string) ($decoded['category'] ?? 'normal'), $score);
            $explanation = trim((string) ($decoded['explanation'] ?? 'Score genere par analyse des donnees client.'));

            return [
                'ok' => true,
                'score' => $score,
                'category' => $category,
                'explanation' => $explanation !== '' ? $explanation : 'Score genere par analyse des donnees client.',
                'raw_response' => is_array($data) ? $data : [],
            ];
        } catch (TransportExceptionInterface|\Throwable $e) {
            return [
                'ok' => false,
                'score' => 0,
                'category' => 'normal',
                'explanation' => 'Erreur lors de l\'appel OpenAI.',
                'error' => 'openai_request_failed',
            ];
        }
    }

    /**
     * @param array{total_orders:int,total_spent:float,cancellations:int,client_reference?:string} $clientData
     *
     * @return array{ok:bool,score:int,category:string,explanation:string,error?:string,raw_response?:array<string,mixed>}
     */
    public function calculateClientScoreLocal(array $clientData): array
    {
        return $this->buildFallbackScore($clientData, 'Score calcule localement.');
    }

    /**
     * @param array{total_orders:int,total_spent:float,cancellations:int,client_reference?:string} $clientData
     *
     * @return array{ok:bool,score:int,category:string,explanation:string,error?:string,raw_response?:array<string,mixed>}
     */
    private function buildFallbackScore(array $clientData, string $reason): array
    {
        $orders = max(0, (int) ($clientData['total_orders'] ?? 0));
        $spent = max(0.0, (float) ($clientData['total_spent'] ?? 0.0));
        $cancellations = max(0, (int) ($clientData['cancellations'] ?? 0));

        $ordersPoints = min(40, $orders * 5);
        $spentPoints = min(45, (int) floor($spent / 20));
        $cancellationPenalty = min(35, $cancellations * 12);
        $score = max(0, min(100, 15 + $ordersPoints + $spentPoints - $cancellationPenalty));

        return [
            'ok' => true,
            'score' => $score,
            'category' => $this->normalizeCategory('', $score),
            'explanation' => sprintf(
                '%s Base: commandes=%d, depense=%.2f DT, annulations=%d.',
                $reason,
                $orders,
                $spent,
                $cancellations
            ),
        ];
    }

    /**
     * @param array{total_orders:int,total_spent:float,cancellations:int,client_reference?:string} $clientData
     */
    private function buildPrompt(array $clientData): string
    {
        $totalOrders = (int) ($clientData['total_orders'] ?? 0);
        $totalSpent = (float) ($clientData['total_spent'] ?? 0);
        $cancellations = (int) ($clientData['cancellations'] ?? 0);
        $clientReference = trim((string) ($clientData['client_reference'] ?? 'client'));

        return <<<PROMPT
Tu es un assistant de scoring client e-commerce.
Calcule un score sur 100 et une categorie parmi: VIP, fidele, normal, a_risque.

Donnees client:
- reference: {$clientReference}
- nombre_commandes: {$totalOrders}
- montant_total_depense: {$totalSpent}
- nombre_annulations: {$cancellations}

Regles de bon sens:
- plus de commandes et de depenses => score plus eleve
- trop d'annulations => score plus faible

Reponds STRICTEMENT en JSON valide, sans markdown, format exact:
{"score": 0-100, "category": "VIP|fidèle|fidele|normal|à risque|a_risque", "explanation": "phrase courte"}
PROMPT;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function extractTextResponse(array $data): string
    {
        $direct = trim((string) ($data['output_text'] ?? ''));
        if ($direct !== '') {
            return $direct;
        }

        $output = $data['output'] ?? null;
        if (!is_array($output)) {
            return '';
        }

        foreach ($output as $block) {
            if (!is_array($block)) {
                continue;
            }

            $content = $block['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }

            foreach ($content as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $text = trim((string) ($item['text'] ?? ''));
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $data
     */
    private function extractApiErrorMessage(array $data): string
    {
        $error = $data['error'] ?? null;
        if (!is_array($error)) {
            return '';
        }

        return trim((string) ($error['message'] ?? ''));
    }

    private function isQuotaOrBillingError(string $message): bool
    {
        $haystack = strtolower($message);

        return str_contains($haystack, 'quota')
            || str_contains($haystack, 'billing')
            || str_contains($haystack, 'plan')
            || str_contains($haystack, 'insufficient');
    }

    private function extractJsonObject(string $text): string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return '';
        }

        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed) ?? $trimmed;
            $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;
            $trimmed = trim($trimmed);
        }

        $start = strpos($trimmed, '{');
        $end = strrpos($trimmed, '}');
        if ($start === false || $end === false || $end <= $start) {
            return $trimmed;
        }

        return substr($trimmed, $start, $end - $start + 1);
    }

    private function normalizeCategory(string $category, int $score): string
    {
        $normalized = strtolower(trim($category));
        $map = [
            'vip' => 'VIP',
            'fidele' => 'fidèle',
            'fidèle' => 'fidèle',
            'normal' => 'normal',
            'a_risque' => 'à risque',
            'à_risque' => 'à risque',
            'a risque' => 'à risque',
            'à risque' => 'à risque',
        ];

        if (isset($map[$normalized])) {
            return $map[$normalized];
        }

        if ($score >= 85) {
            return 'VIP';
        }
        if ($score >= 65) {
            return 'fidèle';
        }
        if ($score >= 40) {
            return 'normal';
        }

        return 'à risque';
    }

    private function getOpenAiApiKey(): string
    {
        return trim((string) ($_SERVER['OPENAI_API_KEY'] ?? $_ENV['OPENAI_API_KEY'] ?? ''));
    }
}
