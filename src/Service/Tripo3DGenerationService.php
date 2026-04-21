<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Tripo3DGenerationService
{
    private ?string $lastError = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%env(string:TRIPO3D_API_KEY)%')] private readonly string $apiKey,
        #[Autowire('%env(string:TRIPO3D_API_BASE_URL)%')] private readonly string $apiBaseUrl,
        #[Autowire('%env(int:TRIPO3D_TIMEOUT)%')] private readonly int $timeoutSeconds
    ) {}

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '';
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * 🚀 ENTRY POINT
     */
    public function generateFromLivestock(array $livestock): ?array
    {
        $this->lastError = null;

        if (!$this->isConfigured()) {
            $this->lastError = 'Clé API Tripo3D manquante.';
            return null;
        }

        // 1er essai
        $taskId = $this->createTextToModelTask($livestock, false);

        // fallback si échec
        if ($taskId === null) {
            $this->logger->warning('Fallback prompt activated');
            $taskId = $this->createTextToModelTask($livestock, true);
        }

        if ($taskId === null) {
            return null;
        }

        $taskPayload = $this->waitForTaskCompletion($taskId);

        if ($taskPayload === null) {
            return null;
        }

        // 🔥 DEBUG (optionnel)
        $this->logger->info('TRIPO RESPONSE', $taskPayload);

        // 🚨 FIX PRINCIPAL ICI
        $modelUrl = $this->extract($taskPayload, [
            ['data', 'result', 'pbr_model', 'url'],
            ['data', 'result', 'model', 'url'],
            ['data', 'output', 'pbr_model', 'url'],
            ['data', 'output', 'model', 'url'],
            ['data', 'output', 'model_url'],
            ['data', 'result', 'model_url'],
            ['data', 'mesh_url'],
            ['data', 'model_url'],
            ['data', 'files', 0, 'url'],
        ]);

        $previewUrl = $this->extract($taskPayload, [
            ['data', 'thumbnail'],
            ['data', 'preview_url'],
            ['preview_url'],
        ]);

        // 🚨 FIX ERROR ORIGINAL
        if (!$modelUrl) {

            $this->logger->warning('Model missing → retry simplified prompt');

            // fallback ultime
            $retryTask = $this->createTextToModelTask($livestock, true);

            if ($retryTask) {
                $taskPayload = $this->waitForTaskCompletion($retryTask);

                $modelUrl = $this->extract($taskPayload, [
                    ['data', 'model_url'],
                    ['data', 'output', 'model_url'],
                    ['data', 'result', 'model_url'],
                ]);
            }

            if (!$modelUrl) {
                $this->lastError = 'Aucun modèle GLB retourné par Tripo3D.';
                return null;
            }
        }

        return $this->storeGeneratedAssets($modelUrl, $previewUrl);
    }

    /**
     * 🧠 PROMPT ENGINE
     */
    private function createTextToModelTask(array $livestock, bool $fallback = false): ?string
    {
        $type = strtolower($livestock['type_elevage'] ?? '');

        $prompt = $fallback
            ? "simple 3D farm {$type} with animals and barn"
            : $this->buildPrompt($type);

        try {
            $response = $this->httpClient->request('POST', $this->buildApiUrl('/v2/openapi/task'), [
                'headers' => $this->buildHeaders(),
                'json' => [
                    'type' => 'text_to_model',
                    'prompt' => $prompt
                ],
                'timeout' => 60
            ]);

            $data = $response->toArray(false);

            return $data['data']['task_id'] ?? null;

        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
    }

    /**
     * 🧠 PROMPTS SIMPLIFIÉS
     */
    private function buildPrompt(string $type): string
    {
        return match (true) {

            str_contains($type, 'mouton') =>
                "3D sheep farm, barn, sheep inside and outside",

            str_contains($type, 'bovin') =>
                "3D cattle farm, cows, barn",

            str_contains($type, 'poule') =>
                "3D poultry farm, chickens, coop",

            default =>
                "3D farm animals barn"
        };
    }

    /**
     * ⏳ POLLING OPTIMISÉ
     */
    private function waitForTaskCompletion(string $taskId): ?array
    {
        $end = time() + 180;

        while (time() < $end) {

            try {
                $response = $this->httpClient->request(
                    'GET',
                    $this->buildApiUrl('/v2/openapi/task/' . $taskId),
                    [
                        'headers' => $this->buildHeaders(),
                        'timeout' => 30
                    ]
                );

                $data = $response->toArray(false);
                $status = strtolower($data['data']['status'] ?? '');

                if (in_array($status, ['success','completed','finished','succeeded'])) {
                    return $data;
                }

                if (in_array($status, ['failed','error','cancelled'])) {
                    return null;
                }

                usleep(2000000); // 2 sec

            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * 💾 SAVE FILE
     */
    private function storeGeneratedAssets(string $modelUrl, ?string $previewUrl): ?array
    {
        $dir = $this->projectDir . '/public/uploads/tripo-3d';

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $fileName = 'farm-' . time() . '.glb';
        $filePath = $dir . '/' . $fileName;

        try {
            $content = $this->httpClient->request('GET', $modelUrl)->getContent(false);

            if (!$content) {
                $this->lastError = 'Empty GLB file';
                return null;
            }

            file_put_contents($filePath, $content);

        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return null;
        }

        return [
            'model_url' => '/uploads/tripo-3d/' . $fileName,
            'preview_url' => $previewUrl
        ];
    }

    private function buildHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . trim($this->apiKey),
            'Content-Type' => 'application/json'
        ];
    }

    private function buildApiUrl(string $path): string
    {
        return rtrim($this->apiBaseUrl, '/') . '/' . ltrim($path, '/');
    }

    private function extract(array $payload, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = $payload;

            foreach ($path as $key) {
                if (!isset($value[$key])) {
                    continue 2;
                }
                $value = $value[$key];
            }

            if (is_string($value)) {
                return $value;
            }
        }

        return null;
    }
}