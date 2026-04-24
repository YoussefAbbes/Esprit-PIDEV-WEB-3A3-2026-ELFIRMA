<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Livestock3DGenerationService
{
    private ?string $lastError = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%env(string:STABILITY_3D_API_KEY)%')] private readonly string $apiKey,
        #[Autowire('%env(string:STABILITY_3D_API_URL)%')] private readonly string $apiUrl,
        #[Autowire('%env(string:STABILITY_IMAGE_API_URL)%')] private readonly string $imageApiUrl,
        #[Autowire('%env(bool:STABILITY_3D_SSL_VERIFY)%')] private readonly bool $sslVerify,
        #[Autowire('%env(int:STABILITY_3D_TIMEOUT)%')] private readonly int $timeoutSeconds
    ) {
    }

    public function isConfigured(): bool
    {
        $key = trim($this->apiKey);
        $modelApiUrl = trim($this->apiUrl);
        $imageApiUrl = trim($this->imageApiUrl);

        return $key !== ''
            && str_starts_with($key, 'sk-')
            && $modelApiUrl !== ''
            && $imageApiUrl !== '';
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @return array{credits:float}|null
     */
    public function fetchBalance(): ?array
    {
        $this->lastError = null;

        if (!$this->isConfigured()) {
            $this->lastError = 'Configuration API 3D incomplete.';
            return null;
        }

        $balanceUrl = $this->buildAccountApiUrl('/v1/user/balance');

        try {
            $response = $this->httpClient->request('GET', $balanceUrl, [
                'headers' => [
                    'authorization' => 'Bearer ' . trim($this->apiKey),
                    'accept' => 'application/json',
                ],
                'verify_peer' => $this->sslVerify,
                'verify_host' => $this->sslVerify,
                'timeout' => max(15, min(45, $this->timeoutSeconds)),
                'max_duration' => max(15, min(45, $this->timeoutSeconds)),
            ]);

            $statusCode = $response->getStatusCode();
            $rawResponse = $response->getContent(false);

            if ($statusCode < 200 || $statusCode >= 300) {
                $this->lastError = $this->extractApiError($rawResponse);

                $this->logger->error('Balance check failed with provider response.', [
                    'status_code' => $statusCode,
                    'response' => $rawResponse,
                ]);

                return null;
            }

            if ($rawResponse === '') {
                $this->lastError = 'Provider returned an empty balance payload.';
                return null;
            }

            $decoded = json_decode($rawResponse, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || !isset($decoded['credits']) || !is_numeric($decoded['credits'])) {
                $this->lastError = 'Balance payload is invalid.';
                return null;
            }

            return [
                'credits' => (float) $decoded['credits'],
            ];
        } catch (\Throwable $exception) {
            $this->lastError = 'Balance request failed: ' . $exception->getMessage();
            $this->logger->error('Balance request exception.', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $livestock
     * @return array<string, mixed>|null
     */
    public function generateFromLivestock(
        array $livestock,
        ?string $textureResolution = null,
        ?string $remesh = null,
        ?string $renderMode = null
    ): ?array {
        $this->lastError = null;

        if (!$this->isConfigured()) {
            $this->lastError = 'Configuration API 3D incomplete.';
            return null;
        }

        $effectiveRenderMode = $this->normalizeRenderMode($renderMode);
        $threeDOptions = $this->buildThreeDGenerationOptions($effectiveRenderMode, $textureResolution, $remesh);

        $prompt = $this->buildImagePrompt($livestock, $effectiveRenderMode);
        $generatedImage = $this->generateImageFromPrompt($prompt, $effectiveRenderMode);
        if ($generatedImage === null) {
            return null;
        }

        $modelBinary = $this->generate3DModelFromImage(
            (string) $generatedImage['binary'],
            (string) $generatedImage['mime_type'],
            (string) $generatedImage['extension'],
            $threeDOptions
        );
        if ($modelBinary === null) {
            return null;
        }

        $storedAssets = $this->storeGeneratedAssets(
            $livestock,
            $prompt,
            (string) $generatedImage['binary'],
            (string) $generatedImage['extension'],
            $modelBinary
        );

        if ($storedAssets === null) {
            return null;
        }

        $storedAssets['render_mode'] = $effectiveRenderMode;
        $storedAssets['three_d_options'] = $threeDOptions;

        return $storedAssets;
    }

    private function generateImageFromPrompt(string $prompt, string $renderMode): ?array
    {
        $apiUrl = rtrim(trim($this->imageApiUrl), '/');
        if ($apiUrl === '') {
            $this->lastError = 'Image API URL is empty.';
            return null;
        }

        $imageOptions = $this->buildImageGenerationOptions($renderMode);

        $formData = new FormDataPart([
            'prompt' => $prompt,
            'output_format' => 'webp',
            'negative_prompt' => $imageOptions['negative_prompt'],
            'style_preset' => $imageOptions['style_preset'],
            'aspect_ratio' => $imageOptions['aspect_ratio'],
        ]);

        try {
            $response = $this->httpClient->request('POST', $apiUrl, [
                'headers' => array_merge(
                    $formData->getPreparedHeaders()->toArray(),
                    [
                        'authorization' => 'Bearer ' . trim($this->apiKey),
                        'accept' => 'image/*',
                    ]
                ),
                'body' => $formData->bodyToIterable(),
                'verify_peer' => $this->sslVerify,
                'verify_host' => $this->sslVerify,
                'timeout' => max(30, $this->timeoutSeconds),
                'max_duration' => max(30, $this->timeoutSeconds),
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                $rawResponse = $response->getContent(false);
                $this->lastError = $this->extractApiError($rawResponse);

                $this->logger->error('Image generation failed with provider response.', [
                    'status_code' => $statusCode,
                    'response' => $rawResponse,
                ]);

                return null;
            }

            $headers = $response->getHeaders(false);
            $contentType = $headers['content-type'][0] ?? '';
            $mimeType = strtolower(trim(explode(';', (string) $contentType)[0]));

            $rawPayload = $response->getContent(false);
            if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                $this->lastError = $this->extractApiError($rawPayload);

                $this->logger->error('Image generation returned a non-image payload.', [
                    'content_type' => $contentType,
                    'response' => substr($rawPayload, 0, 500),
                ]);

                return null;
            }

            if ($rawPayload === '') {
                $this->lastError = 'Provider returned an empty image.';
                return null;
            }

            return [
                'binary' => $rawPayload,
                'mime_type' => $mimeType,
                'extension' => $this->mimeToExtension($mimeType),
            ];
        } catch (\Throwable $exception) {
            $this->lastError = 'Image generation request failed: ' . $exception->getMessage();
            $this->logger->error('Image generation request exception.', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function generate3DModelFromImage(
        string $imageBinary,
        string $mimeType,
        string $imageExtension,
        array $threeDOptions
    ): ?string
    {
        $apiUrl = rtrim(trim($this->apiUrl), '/');
        if ($apiUrl === '') {
            $this->lastError = '3D API URL is empty.';
            return null;
        }

        $formFields = [
            'image' => new DataPart(
                $imageBinary,
                'livestock-auto.' . $imageExtension,
                $mimeType
            ),
        ];

        if (isset($threeDOptions['texture_resolution']) && in_array((string) $threeDOptions['texture_resolution'], ['512', '1024', '2048'], true)) {
            $formFields['texture_resolution'] = (string) $threeDOptions['texture_resolution'];
        }

        if (isset($threeDOptions['remesh']) && in_array((string) $threeDOptions['remesh'], ['none', 'triangle', 'quad'], true)) {
            $formFields['remesh'] = (string) $threeDOptions['remesh'];
        }

        if (isset($threeDOptions['foreground_ratio'])) {
            $foregroundRatio = max(1.0, min(2.0, (float) $threeDOptions['foreground_ratio']));
            $formFields['foreground_ratio'] = number_format($foregroundRatio, 2, '.', '');
        }

        $isPointAwareEndpoint = str_contains(strtolower($apiUrl), 'stable-point-aware-3d');

        if ($isPointAwareEndpoint && isset($threeDOptions['guidance_scale'])) {
            $guidanceScale = max(1.0, min(10.0, (float) $threeDOptions['guidance_scale']));
            $formFields['guidance_scale'] = number_format($guidanceScale, 2, '.', '');
        }

        $targetType = strtolower((string) ($threeDOptions['target_type'] ?? 'none'));
        $targetCount = max(100, min(20000, (int) ($threeDOptions['target_count'] ?? 0)));

        if ($targetType !== 'none') {
            if ($isPointAwareEndpoint && in_array($targetType, ['face', 'vertex'], true)) {
                $formFields['target_type'] = $targetType;
                $formFields['target_count'] = (string) $targetCount;
            }

            if (!$isPointAwareEndpoint) {
                $formFields['vertex_count'] = (string) $targetCount;
            }
        }

        $formData = new FormDataPart($formFields);

        try {
            $response = $this->httpClient->request('POST', $apiUrl, [
                'headers' => array_merge(
                    $formData->getPreparedHeaders()->toArray(),
                    ['authorization' => 'Bearer ' . trim($this->apiKey)]
                ),
                'body' => $formData->bodyToIterable(),
                'verify_peer' => $this->sslVerify,
                'verify_host' => $this->sslVerify,
                'timeout' => max(30, $this->timeoutSeconds),
                'max_duration' => max(30, $this->timeoutSeconds),
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                $rawResponse = $response->getContent(false);
                $this->lastError = $this->extractApiError($rawResponse);

                $this->logger->error('3D generation failed with provider response.', [
                    'status_code' => $statusCode,
                    'response' => $rawResponse,
                ]);

                return null;
            }

            $headers = $response->getHeaders(false);
            $contentType = strtolower(trim(explode(';', (string) ($headers['content-type'][0] ?? ''))[0]));

            $modelBinary = $response->getContent(false);
            if ($modelBinary === '') {
                $this->lastError = 'Provider returned an empty 3D model.';
                return null;
            }

            if ($contentType !== '' && (str_starts_with($contentType, 'application/json') || str_starts_with($contentType, 'text/'))) {
                $this->lastError = $this->extractApiError($modelBinary);

                $this->logger->error('3D generation returned a non-binary payload.', [
                    'content_type' => $contentType,
                    'response' => substr($modelBinary, 0, 500),
                ]);

                return null;
            }

            return $modelBinary;
        } catch (\Throwable $exception) {
            $this->lastError = '3D generation request failed: ' . $exception->getMessage();
            $this->logger->error('3D generation request exception.', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $livestock
     * @return array<string, mixed>|null
     */
    private function storeGeneratedAssets(
        array $livestock,
        string $prompt,
        string $imageBinary,
        string $imageExtension,
        string $modelBinary
    ): ?array {
        $targetDir = $this->projectDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'livestock-3d';
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            $this->lastError = 'Failed to create output folder for 3D models.';
            return null;
        }

        $label = $this->buildFileLabel($livestock);
        $timestamp = (new \DateTimeImmutable())->format('Ymd-His');

        $modelFileName = sprintf('%s-%s.glb', $label, $timestamp);
        $previewFileName = sprintf('%s-source-%s.%s', $label, $timestamp, $imageExtension);

        $modelPath = $targetDir . DIRECTORY_SEPARATOR . $modelFileName;
        $previewPath = $targetDir . DIRECTORY_SEPARATOR . $previewFileName;

        $modelBytes = @file_put_contents($modelPath, $modelBinary);
        if ($modelBytes === false) {
            $this->lastError = 'Failed to store generated 3D model.';
            return null;
        }

        $previewBytes = @file_put_contents($previewPath, $imageBinary);
        if ($previewBytes === false) {
            @unlink($modelPath);
            $this->lastError = 'Failed to store generated preview image.';
            return null;
        }

        return [
            'file_name' => $modelFileName,
            'file_size_bytes' => $modelBytes,
            'model_url' => '/uploads/livestock-3d/' . $modelFileName,
            'preview_url' => '/uploads/livestock-3d/' . $previewFileName,
            'preview_size_bytes' => $previewBytes,
            'prompt' => $prompt,
        ];
    }

    /**
     * @param array<string, mixed> $livestock
     */
    private function buildImagePrompt(array $livestock, string $renderMode = 'ultra'): string
    {
        $type = trim((string) ($livestock['type_elevage'] ?? 'livestock'));
        $state = trim((string) ($livestock['etat_elevage'] ?? 'normal'));
        $production = trim((string) ($livestock['production'] ?? 'farm production'));
        $capacity = (int) ($livestock['capacite'] ?? 0);
        $animals = (int) ($livestock['nombre_animaux'] ?? 0);

        if ($type === '') {
            $type = 'livestock';
        }

        if ($state === '') {
            $state = 'normal';
        }

        if ($production === '') {
            $production = 'farm production';
        }

        $habitatType = $this->inferHabitatType($type);
        $productionContext = $this->normalizeProductionContext($production);
        $visualMood = match ($renderMode) {
            'eco' => 'clean daylight render with low visual clutter and simplified materials',
            'balanced' => 'realistic daylight render with balanced detail and natural contrast',
            'cinematic' => 'cinematic golden-hour lighting with dramatic but realistic shadows and premium architectural atmosphere',
            'signature' => 'award-level architectural visualization with controlled global illumination, premium textures, and highly coherent structural composition',
            default => 'ultra-detailed realistic render with physically plausible materials and precise geometry definition',
        };

        return sprintf(
            'Ultra-realistic architectural 3D concept of a %s for %s livestock on a Tunisian farm. The habitat must be one coherent connected structure anchored on a flat ground plane, with clean topology suitable for a compact GLB mesh and no disconnected pieces. Include only relevant farm elements placed logically around the building: shelter frame, pens, feeding corridor, water points, ventilation openings, fenced perimeter, bedding area, manure management corner, roof trusses, drainage channels, gate hardware, feeding barriers, and realistic material joins. Keep a balanced centered composition, realistic scale, and high material detail (wood, concrete, galvanized steel). Visual direction: %s. If livestock are visible, they must be anatomically clear (legs, head, ears or horns, wool or fur texture) and never look like stones or amorphous blobs. Functional context: state %s, production %s, capacity %d, current animals %d. Strictly avoid floating objects, scattered debris, exploded parts, isolated fragments, abstract shapes, text, logos, watermark, humans, unrelated machines.',
            $habitatType,
            strtolower($type),
            $visualMood,
            $state,
            $productionContext,
            max(0, $capacity),
            max(0, $animals)
        );
    }

    /**
     * @return array{negative_prompt:string, style_preset:string, aspect_ratio:string}
     */
    private function buildImageGenerationOptions(string $renderMode): array
    {
        $baseNegativePrompt = 'close-up animal portrait, humans, fruit blender, kitchen appliance, unrelated machinery, tractor in foreground, abstract object, text, logo, watermark, floating parts, isolated fragments, broken mesh, exploded view, scattered debris, duplicated buildings, chaotic layout, ruined building, rubble piles, cluttered background, stone-like sheep, blob sheep, amorphous livestock, fused limbs, melted animals, low-detail fur, plastic-like animals';

        return match ($renderMode) {
            'eco' => [
                'negative_prompt' => $baseNegativePrompt,
                'style_preset' => '3d-model',
                'aspect_ratio' => '16:9',
            ],
            'balanced' => [
                'negative_prompt' => $baseNegativePrompt,
                'style_preset' => '3d-model',
                'aspect_ratio' => '16:9',
            ],
            'cinematic' => [
                'negative_prompt' => $baseNegativePrompt . ', overexposed sky, extreme haze, heavy lens flare, artificial bokeh',
                'style_preset' => '3d-model',
                'aspect_ratio' => '16:9',
            ],
            'signature' => [
                'negative_prompt' => $baseNegativePrompt . ', deformed geometry, stretched walls, missing roof, disconnected fences, noisy textures, low-poly artifacts, warped perspective',
                'style_preset' => '3d-model',
                'aspect_ratio' => '16:9',
            ],
            default => [
                'negative_prompt' => $baseNegativePrompt,
                'style_preset' => '3d-model',
                'aspect_ratio' => '16:9',
            ],
        };
    }

    /**
     * @return array{texture_resolution:string, remesh:string, foreground_ratio:float, guidance_scale:float, target_type:string, target_count:int}
     */
    private function buildThreeDGenerationOptions(
        string $renderMode,
        ?string $textureResolution,
        ?string $remesh
    ): array {
        $safeTextureResolution = in_array((string) $textureResolution, ['512', '1024', '2048'], true)
            ? (string) $textureResolution
            : '2048';

        $safeRemesh = in_array((string) $remesh, ['none', 'triangle', 'quad'], true)
            ? (string) $remesh
            : 'none';

        $balancedTextureResolution = $safeTextureResolution === '512' ? '1024' : $safeTextureResolution;

        return match ($renderMode) {
            'eco' => [
                'texture_resolution' => '1024',
                'remesh' => 'triangle',
                'foreground_ratio' => 1.22,
                'guidance_scale' => 2.60,
                'target_type' => 'face',
                'target_count' => 4500,
            ],
            'balanced' => [
                'texture_resolution' => $balancedTextureResolution,
                'remesh' => $safeRemesh === 'triangle' ? 'triangle' : 'none',
                'foreground_ratio' => 1.30,
                'guidance_scale' => 3.00,
                'target_type' => 'none',
                'target_count' => 0,
            ],
            'ultra' => [
                'texture_resolution' => '2048',
                'remesh' => 'none',
                'foreground_ratio' => 1.40,
                'guidance_scale' => 3.05,
                'target_type' => 'none',
                'target_count' => 0,
            ],
            'cinematic' => [
                'texture_resolution' => '2048',
                'remesh' => 'none',
                'foreground_ratio' => 1.46,
                'guidance_scale' => 3.10,
                'target_type' => 'none',
                'target_count' => 0,
            ],
            default => [
                'texture_resolution' => '2048',
                'remesh' => 'none',
                'foreground_ratio' => 1.55,
                'guidance_scale' => 3.20,
                'target_type' => 'none',
                'target_count' => 0,
            ],
        };
    }

    private function normalizeRenderMode(?string $rawRenderMode): string
    {
        $mode = strtolower(trim((string) $rawRenderMode));

        return in_array($mode, ['eco', 'balanced', 'ultra', 'cinematic', 'signature'], true)
            ? $mode
            : 'signature';
    }

    private function inferHabitatType(string $rawType): string
    {
        $type = trim($rawType);
        if ($type === '') {
            return 'livestock housing building';
        }

        if (preg_match('/bovin|vache|taureau|cattle|cow/i', $type) === 1) {
            return 'cattle barn (etable)';
        }

        if (preg_match('/ovin|mouton|sheep/i', $type) === 1) {
            return 'sheepfold (bergerie)';
        }

        if (preg_match('/caprin|chevre|chèvre|goat/i', $type) === 1) {
            return 'goat stable (chevrerie)';
        }

        if (preg_match('/equin|cheval|horse/i', $type) === 1) {
            return 'horse stable (ecurie)';
        }

        if (preg_match('/porc|porcin|pig|swine/i', $type) === 1) {
            return 'pigsty (porcherie)';
        }

        if (preg_match('/volaille|poule|poulet|chicken|hen|poultry/i', $type) === 1) {
            return 'poultry house (poulailler)';
        }

        if (preg_match('/lapin|rabbit/i', $type) === 1) {
            return 'rabbit hutch building (clapier)';
        }

        if (preg_match('/apic|abeille|bee|honey/i', $type) === 1) {
            return 'apiary shelter with beehives';
        }

        return 'livestock housing building';
    }

    private function normalizeProductionContext(string $rawProduction): string
    {
        $production = trim($rawProduction);
        if ($production === '') {
            return 'animal production';
        }

        if (preg_match('/lait|milk/i', $production) === 1) {
            return 'milk';
        }

        if (preg_match('/viande|meat/i', $production) === 1) {
            return 'meat';
        }

        if (preg_match('/oeuf|oeufs|egg/i', $production) === 1) {
            return 'eggs';
        }

        if (preg_match('/laine|wool/i', $production) === 1) {
            return 'wool';
        }

        if (preg_match('/miel|honey/i', $production) === 1) {
            return 'honey';
        }

        if (preg_match('/repro|breed|breeding|naissance/i', $production) === 1) {
            return 'breeding';
        }

        if (preg_match('/fumier|manure|compost/i', $production) === 1) {
            return 'manure';
        }

        return 'animal production';
    }

    /**
     * @param array<string, mixed> $livestock
     */
    private function buildFileLabel(array $livestock): string
    {
        $id = (int) ($livestock['id_elevage'] ?? 0);
        $type = trim((string) ($livestock['type_elevage'] ?? 'livestock'));
        $state = trim((string) ($livestock['etat_elevage'] ?? 'normal'));

        $raw = sprintf('%s-%s-%d', $type, $state, max(0, $id));
        $safe = trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower($raw)), '-');

        return $safe !== '' ? $safe : 'livestock-model';
    }

    private function mimeToExtension(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => 'webp',
        };
    }

    private function buildAccountApiUrl(string $path): string
    {
        $trimmedPath = '/' . ltrim($path, '/');

        $parsed = parse_url(trim($this->imageApiUrl));
        if (is_array($parsed) && isset($parsed['scheme'], $parsed['host'])) {
            $base = $parsed['scheme'] . '://' . $parsed['host'];
            return $base . $trimmedPath;
        }

        return 'https://api.stability.ai' . $trimmedPath;
    }

    private function extractApiError(string $rawResponse): string
    {
        $trimmed = trim($rawResponse);
        if ($trimmed === '') {
            return '3D provider returned an unknown error.';
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $errorName = isset($decoded['name']) && is_string($decoded['name'])
                    ? strtolower(trim($decoded['name']))
                    : '';

                if ($errorName === 'payment_required') {
                    return 'Credits API Stability insuffisants. Rechargez votre compte Stability puis relancez la generation 3D.';
                }

                if (isset($decoded['errors']) && is_array($decoded['errors']) && $decoded['errors'] !== []) {
                    $flattened = array_map(static fn ($value): string => (string) $value, $decoded['errors']);

                    $joinedLower = strtolower(implode(' ', $flattened));
                    if (str_contains($joinedLower, 'sufficient credits') || str_contains($joinedLower, 'payment_required')) {
                        return 'Credits API Stability insuffisants. Rechargez votre compte Stability puis relancez la generation 3D.';
                    }

                    return implode(' | ', $flattened);
                }

                if (isset($decoded['message']) && is_string($decoded['message']) && $decoded['message'] !== '') {
                    return $decoded['message'];
                }

                if (isset($decoded['name']) && is_string($decoded['name']) && $decoded['name'] !== '') {
                    return $decoded['name'];
                }
            }
        } catch (\JsonException) {
            // Keep fallback message below.
        }

        return substr($trimmed, 0, 250);
    }
}
