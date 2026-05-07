<?php

namespace App\Service;

/**
 * FingerprintClient
 * =================
 * HTTP client for the Java ZKFinger fingerprint bridge server.
 * Mirrors the pattern of FaceIdClient but targets the Java bridge
 * running on http://localhost:8085 (or configured host/port).
 *
 * Endpoints (all POST, JSON in/out):
 *   POST /status         → device info & readiness
 *   POST /enroll/start   → begin 3-scan enrollment session
 *   POST /enroll/capture → capture one scan (call 3 times to complete)
 *   POST /verify         → 1:1 live capture vs. a stored template
 *   POST /identify       → 1:N live capture vs. a list of user templates
 */
class FingerprintClient
{
    private string $host;
    private int    $port;

    public function __construct(
        string $host = 'localhost',
        int    $port = 8085
    ) {
        $this->host = $host;
        $this->port = $port;
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Check device status / service health.
     * POST /status — no body required.
     */
    public function status(): array
    {
        return $this->request('status');
    }

    /**
     * Start a new enrollment session (resets the 3-scan counter on the bridge).
     * POST /enroll/start — no body required.
     */
    public function enrollStart(): array
    {
        return $this->request('enroll/start');
    }

    /**
     * Capture one fingerprint scan during enrollment.
     * Must be called 3 times (steps 1, 2, 3).
     * On the final call the bridge returns the merged template.
     * POST /enroll/capture — no body required.
     */
    public function enrollCapture(): array
    {
        return $this->request('enroll/capture');
    }

    /**
     * 1:1 verification — live capture from sensor vs. the supplied template.
     *
     * @param string $templateBase64  Base-64 encoded binary template blob.
     * @param int    $templateLength  Original byte-length of the template.
     */
    public function verify(string $templateBase64, int $templateLength): array
    {
        return $this->request('verify', [
            'template'        => $templateBase64,
            'template_length' => $templateLength,
        ]);
    }

    /**
     * 1:N identification — live capture from sensor, matched against every
     * template in the supplied list.
     *
     * @param array $users  Array of associative arrays, each with:
     *                      [
     *                        'user_id'         => int,
     *                        'template'        => string (base-64),
     *                        'template_length' => int,
     *                      ]
     */
    public function identify(array $users): array
    {
        return $this->request('identify', [
            'templates' => $users,
        ]);
    }

    // -----------------------------------------------------------------------
    // Internal cURL helper
    // -----------------------------------------------------------------------

    /**
     * Perform a POST request to the Java bridge.
     *
     * Timeout is intentionally set to 30 s because fingerprint capture
     * requires the user to place their finger on the sensor, which can
     * take several seconds.
     *
     * @param string $endpoint  Path segment (no leading slash), e.g. "enroll/capture".
     * @param array  $data      Payload to JSON-encode; empty array sends an empty object {}.
     *
     * @return array  Decoded JSON response, or ['ok' => false, 'error' => '...'] on failure.
     */
    private function request(string $endpoint, array $data = []): array
    {
        try {
            $url = "http://{$this->host}:{$this->port}/{$endpoint}";

            $ch = curl_init($url);

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST,           true);
            curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json']);
            $jsonPayload = json_encode($data, JSON_UNESCAPED_SLASHES);
            if ($jsonPayload === false) {
                return [
                    'ok'    => false,
                    'error' => 'Failed to encode fingerprint request payload to JSON.',
                ];
            }

            curl_setopt($ch, CURLOPT_POSTFIELDS,     $jsonPayload);
            // Fingerprint capture can legitimately take several seconds.
            curl_setopt($ch, CURLOPT_TIMEOUT,        30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

            $response  = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                return [
                    'ok'    => false,
                    'error' => "Connection failed: {$curlError}. "
                             . "Make sure the fingerprint bridge service is running on "
                             . "{$this->host}:{$this->port}.",
                ];
            }

            if ($httpCode !== 200) {
                $decodedError = json_decode((string) $response, true);
                $bridgeError = is_array($decodedError)
                    ? ($decodedError['error'] ?? $decodedError['message'] ?? null)
                    : null;

                return [
                    'ok'    => false,
                    'error' => $bridgeError
                        ? "Fingerprint bridge returned HTTP {$httpCode}: {$bridgeError}"
                        : "Fingerprint bridge returned HTTP {$httpCode}. "
                          . "Please ensure the Java bridge is properly configured.",
                ];
            }

            $decoded = json_decode((string) $response, true);

            if ($decoded === null) {
                return [
                    'ok'    => false,
                    'error' => 'Invalid JSON response received from fingerprint bridge.',
                ];
            }

            return $decoded;

        } catch (\Throwable $e) {
            return [
                'ok'    => false,
                'error' => 'FingerprintClient exception: ' . $e->getMessage(),
            ];
        }
    }
}
