<?php

namespace App\Service;

final class ProductVideoGeneratorService
{
    public function __construct(
        private readonly string $projectDir,
        private readonly string $pythonBinary = 'python'
    ) {
    }

    /**
        * @param array{id:int,name:string,description:string,price:string,image:string,quality?:string,production_date?:string,expiration_date?:string,tts_text?:string} $product
     *
     * @return array{ok:bool,video_url:?string,message:string,stdout:string}
     */
    public function generate(array $product): array
    {
        $scriptPath = $this->projectDir . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'generate_product_video.py';
        if (!is_file($scriptPath)) {
            return [
                'ok' => false,
                'video_url' => null,
                'message' => 'Python script not found.',
                'stdout' => '',
            ];
        }

        $imagePath = '';
        if (($product['image'] ?? '') !== '') {
            $imagePath = $this->projectDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'produits' . DIRECTORY_SEPARATOR . basename((string) $product['image']);
        }

        $payload = [
            'id' => (int) ($product['id'] ?? 0),
            'name' => (string) ($product['name'] ?? 'Produit'),
            'description' => (string) ($product['description'] ?? ''),
            'price' => (string) ($product['price'] ?? '0.00'),
            'quality' => (string) ($product['quality'] ?? ''),
            'production_date' => (string) ($product['production_date'] ?? ''),
            'expiration_date' => (string) ($product['expiration_date'] ?? ''),
            'tts_text' => (string) ($product['tts_text'] ?? ''),
            'image_path' => $imagePath,
            'project_dir' => $this->projectDir,
        ];

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($jsonPayload) || $jsonPayload === '') {
            return [
                'ok' => false,
                'video_url' => null,
                'message' => 'Unable to encode payload for Python generator.',
                'stdout' => '',
            ];
        }

        $pythonBinary = $this->resolvePythonBinary();
        $payloadFile = tempnam(sys_get_temp_dir(), 'product_video_payload_');
        if (!is_string($payloadFile) || $payloadFile === '') {
            return [
                'ok' => false,
                'video_url' => null,
                'message' => 'Unable to create temporary payload file.',
                'stdout' => '',
            ];
        }

        if (@file_put_contents($payloadFile, $jsonPayload) === false) {
            return [
                'ok' => false,
                'video_url' => null,
                'message' => 'Unable to write temporary payload file.',
                'stdout' => '',
            ];
        }

        $command = sprintf(
            '%s %s --payload-file %s 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($scriptPath),
            escapeshellarg($payloadFile)
        );

        $outputLines = [];
        $exitCode = 1;
        $previousMaxExecutionTime = ini_get('max_execution_time');
        try {
            if (function_exists('set_time_limit')) {
                @set_time_limit(600);
            }
            @ini_set('max_execution_time', '600');
            exec($command, $outputLines, $exitCode);
        } finally {
            if ($previousMaxExecutionTime !== false && $previousMaxExecutionTime !== null) {
                @ini_set('max_execution_time', (string) $previousMaxExecutionTime);
            }
            @unlink($payloadFile);
        }

        $stdout = implode(PHP_EOL, $outputLines);

        if ($stdout === '') {
            return [
                'ok' => false,
                'video_url' => null,
                'message' => 'No output from Python generator. Verify Python, MoviePy and gTTS installation.',
                'stdout' => '',
            ];
        }

        $parsed = null;
        for ($i = count($outputLines) - 1; $i >= 0; $i--) {
            $line = trim((string) $outputLines[$i]);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $candidate = json_decode($line, true);
            if (is_array($candidate)) {
                $parsed = $candidate;
                break;
            }
        }

        if (!is_array($parsed)) {
            return [
                'ok' => false,
                'video_url' => null,
                'message' => 'Invalid Python output format. Expected JSON line.',
                'stdout' => $stdout,
            ];
        }

        $ok = (bool) ($parsed['ok'] ?? false);
        if ($exitCode !== 0 && !$ok) {
            return [
                'ok' => false,
                'video_url' => null,
                'message' => (string) ($parsed['message'] ?? 'Video generation failed.'),
                'stdout' => $stdout,
            ];
        }

        return [
            'ok' => $ok,
            'video_url' => isset($parsed['video_web_path']) ? (string) $parsed['video_web_path'] : null,
            'message' => (string) ($parsed['message'] ?? ''),
            'stdout' => $stdout,
        ];
    }

    private function resolvePythonBinary(): string
    {
        $configured = trim($this->pythonBinary);
        if ($configured !== '' && strcasecmp($configured, 'python') !== 0) {
            return $configured;
        }

        $localAppData = getenv('LOCALAPPDATA');
        if (is_string($localAppData) && $localAppData !== '') {
            $candidate = $localAppData . DIRECTORY_SEPARATOR . 'Programs' . DIRECTORY_SEPARATOR . 'Python' . DIRECTORY_SEPARATOR . 'Python312' . DIRECTORY_SEPARATOR . 'python.exe';
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return 'python';
    }
}
