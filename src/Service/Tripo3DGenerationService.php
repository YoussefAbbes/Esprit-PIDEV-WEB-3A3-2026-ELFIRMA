<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Tripo3DGenerationService
{
    private ?string $lastError = null;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $projectDir,
        private string $apiKey,
        private string $apiBaseUrl,
        private int $timeoutSeconds
    ) {}

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '' && trim($this->apiBaseUrl) !== '';
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function generateFromLivestock(array $livestock): ?array
    {
        $this->lastError = null;

        if (!$this->isConfigured()) {
            $this->lastError = 'Configuration Tripo3D manquante.';
            return null;
        }

        $prompt = $this->buildPrompt($livestock);
        $apiKey = trim($this->apiKey);
        $apiBaseUrl = rtrim(trim($this->apiBaseUrl), '/');

        // Tripo task processing can take longer than default PHP 30s.
        $requestedExecutionTime = max(90, $this->timeoutSeconds + 30);
        if (function_exists('set_time_limit')) {
            @set_time_limit($requestedExecutionTime);
        }
        @ini_set('max_execution_time', (string) $requestedExecutionTime);

        if ($apiBaseUrl === '') {
            $this->lastError = 'URL API Tripo3D invalide.';
            return null;
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                $apiBaseUrl.'/v2/openapi/task',
                [
                    'headers' => [
                        'authorization' => 'Bearer '.$apiKey,
                        'content-type' => 'application/json',
                        'accept' => 'application/json',
                    ],
                    'json' => [
                        'type' => 'text_to_model',
                        'prompt' => $prompt,
                    ],
                    'timeout' => 35,
                    'max_duration' => 35,
                ]
            );

            $createStatusCode = $response->getStatusCode();
            $createPayload = $response->getContent(false);

            if ($createStatusCode < 200 || $createStatusCode >= 300) {
                $this->lastError = $this->buildHttpErrorMessage('creation de la tache', $createStatusCode, $createPayload);

                $this->logger->error('Tripo task creation rejected.', [
                    'status_code' => $createStatusCode,
                    'response' => $createPayload,
                ]);

                return null;
            }

            $data = $this->decodeJson($createPayload);
            $taskId = $this->extractTaskId($data);

            if (!$taskId) {
                $this->lastError = $this->buildHttpErrorMessage(
                    'creation de la tache',
                    $createStatusCode,
                    $createPayload,
                    'Identifiant de tache introuvable dans la reponse Tripo3D.'
                );

                $this->logger->error('Tripo task id missing after successful creation response.', [
                    'response' => $createPayload,
                ]);

                return null;
            }

            $start = time();

            while (time() - $start < $this->timeoutSeconds) {

                sleep(2);

                $res = $this->httpClient->request(
                    'GET',
                    $apiBaseUrl.'/v2/openapi/task/'.$taskId,
                    [
                        'headers' => [
                            'authorization' => 'Bearer '.$apiKey,
                            'accept' => 'application/json',
                        ],
                        'timeout' => 20,
                        'max_duration' => 20,
                    ]
                );

                $pollStatusCode = $res->getStatusCode();
                $pollPayload = $res->getContent(false);

                if ($pollStatusCode < 200 || $pollStatusCode >= 300) {
                    $this->lastError = $this->buildHttpErrorMessage('verification de la tache', $pollStatusCode, $pollPayload);

                    $this->logger->error('Tripo task polling rejected.', [
                        'task_id' => $taskId,
                        'status_code' => $pollStatusCode,
                        'response' => $pollPayload,
                    ]);

                    return null;
                }

                $data = $this->decodeJson($pollPayload);
                $status = strtolower((string) ($data['data']['status'] ?? $data['status'] ?? ''));

                if (in_array($status, ['created', 'queued', 'pending', 'running', 'processing', 'in_progress'], true)) {
                    continue;
                }

                if (in_array($status, ['success', 'completed', 'succeeded'], true)) {

                    $modelUrl = $this->extractUrlFromCandidates([
                        $data['data']['result']['pbr_model'] ?? null,
                        $data['data']['result']['model'] ?? null,
                        $data['data']['output']['pbr_model'] ?? null,
                        $data['data']['output']['model'] ?? null,
                        $data['data']['output']['glb'] ?? null,
                        $data['data']['model_url'] ?? null,
                        $data['model_url'] ?? null,
                    ]);

                    $previewUrl = $this->extractUrlFromCandidates([
                        $data['data']['result']['rendered_image'] ?? null,
                        $data['data']['output']['rendered_image'] ?? null,
                        $data['data']['output']['generated_image'] ?? null,
                        $data['data']['thumbnail'] ?? null,
                        $data['thumbnail'] ?? null,
                    ]);

                    if (!is_string($modelUrl) || trim($modelUrl) === '') {
                        $this->lastError = 'Generation Tripo3D terminee, mais aucune URL modele n a ete retournee.';

                        $this->logger->error('Tripo task succeeded without model URL.', [
                            'task_id' => $taskId,
                            'response' => $pollPayload,
                        ]);

                        return null;
                    }

                    return $this->download($modelUrl, $livestock, $prompt, $previewUrl);
                }

                if (in_array($status, ['failed', 'error', 'cancelled', 'canceled'], true)) {
                    $apiMessage = $this->extractApiMessage($data, $pollPayload);
                    $this->lastError = $apiMessage !== ''
                        ? 'Generation Tripo3D echouee: '.$apiMessage
                        : 'Generation Tripo3D echouee.';

                    $this->logger->error('Tripo task finished with failure status.', [
                        'task_id' => $taskId,
                        'status' => $status,
                        'response' => $pollPayload,
                    ]);

                    return null;
                }

                $this->logger->warning('Tripo task returned unknown status during polling.', [
                    'task_id' => $taskId,
                    'status' => $status,
                    'response' => $pollPayload,
                ]);
            }

            $this->lastError = 'Delai depasse pendant la generation Tripo3D. Reessaie dans quelques instants.';
            return null;

        } catch (\Throwable $e) {
            $this->lastError = 'Erreur Tripo3D: '.$e->getMessage();

            $this->logger->error('Tripo generation exception.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function download(string $url, array $livestock, string $prompt, ?string $previewUrl = null): array
    {
        $dir = $this->projectDir.'/public/uploads/tripo-3d';

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $livestockId = (int) ($livestock['id_elevage'] ?? 0);
        $fileName = sprintf('livestock-%d-%d.glb', max(0, $livestockId), time());
        $path = $dir.'/'.$fileName;

        $downloadResponse = $this->httpClient->request('GET', $url, [
            'timeout' => 60,
            'max_duration' => 60,
        ]);

        $downloadStatusCode = $downloadResponse->getStatusCode();
        $content = $downloadResponse->getContent(false);

        if ($downloadStatusCode < 200 || $downloadStatusCode >= 300) {
            throw new \RuntimeException(
                $this->buildHttpErrorMessage('telechargement du modele', $downloadStatusCode, $content)
            );
        }

        if ($content === '') {
            throw new \RuntimeException('Le modele Tripo3D telecharge est vide.');
        }

        $writtenBytes = @file_put_contents($path, $content);

        if ($writtenBytes === false) {
            throw new \RuntimeException('Unable to store generated Tripo3D model.');
        }

        return [
            'model_url' => '/uploads/tripo-3d/'.$fileName,
            'preview_url' => $previewUrl,
            'file_name' => $fileName,
            'file_size_bytes' => (int) $writtenBytes,
            'prompt' => $prompt,
        ];
    }

    private function extractUrlFromCandidates(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }

            if (is_array($candidate)) {
                if (isset($candidate['url']) && is_string($candidate['url']) && trim($candidate['url']) !== '') {
                    return trim($candidate['url']);
                }

                if (isset($candidate[0]) && is_string($candidate[0]) && trim($candidate[0]) !== '') {
                    return trim($candidate[0]);
                }
            }
        }

        return null;
    }

    private function decodeJson(string $payload): array
    {
        $trimmed = trim($payload);
        if ($trimmed === '') {
            return [];
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }

    private function extractTaskId(array $data): ?string
    {
        $taskId = $data['data']['task_id'] ?? $data['data']['id'] ?? $data['task_id'] ?? $data['id'] ?? null;

        if (!is_string($taskId)) {
            return null;
        }

        $taskId = trim($taskId);
        return $taskId !== '' ? $taskId : null;
    }

    private function extractApiMessage(array $data, string $rawPayload = ''): string
    {
        $candidates = [
            $data['message'] ?? null,
            $data['msg'] ?? null,
            $data['error_message'] ?? null,
            $data['error']['message'] ?? null,
            $data['error'] ?? null,
            $data['data']['message'] ?? null,
            $data['data']['error_message'] ?? null,
            $data['data']['error']['message'] ?? null,
            $data['data']['error'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }

            if (is_array($candidate) && isset($candidate[0]) && is_string($candidate[0]) && trim($candidate[0]) !== '') {
                return trim($candidate[0]);
            }
        }

        $rawPayload = trim($rawPayload);
        if ($rawPayload !== '') {
            return substr($rawPayload, 0, 260);
        }

        return '';
    }

    private function buildHttpErrorMessage(string $step, int $statusCode, string $rawPayload, string $fallback = ''): string
    {
        $apiMessage = $this->extractApiMessage($this->decodeJson($rawPayload), $rawPayload);

        if ($statusCode === 401 || $statusCode === 403) {
            $base = 'Acces Tripo3D refuse (HTTP '.$statusCode.'). Verifie la cle API et les permissions OpenAPI du compte.';
            if ($apiMessage !== '') {
                return $base.' Detail: '.$apiMessage;
            }

            return $base;
        }

        if ($apiMessage !== '') {
            return 'Erreur Tripo3D pendant '.$step.' (HTTP '.$statusCode.'): '.$apiMessage;
        }

        if ($fallback !== '') {
            return 'Erreur Tripo3D pendant '.$step.' (HTTP '.$statusCode.'): '.$fallback;
        }

        return 'Erreur Tripo3D pendant '.$step.' (HTTP '.$statusCode.').';
    }

    private function buildPrompt(array $livestock): string
    {
        $type = trim((string) ($livestock['type_elevage'] ?? 'livestock'));
        $state = trim((string) ($livestock['etat_elevage'] ?? 'normal'));
        $production = trim((string) ($livestock['production'] ?? 'farm production'));
        $capacity = (int) ($livestock['capacite'] ?? 0);
        $animals = (int) ($livestock['nombre_animaux'] ?? 0);

        if ($type === '') {
            $type = 'livestock';
        }

        return sprintf(
            'Ultra realistic 3D livestock habitat for %s. State %s, production %s, capacity %d, animals %d. One coherent barn structure on flat terrain, realistic materials, no floating fragments, no text, no watermark.',
            strtolower($type),
            $state !== '' ? $state : 'normal',
            $production !== '' ? $production : 'farm production',
            max(0, $capacity),
            max(0, $animals)
        );
    }
}