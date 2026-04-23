<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TwilioSmsService
{
    private ?string $lastError = null;
    private ?string $resolvedAccountSid = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(string:TWILIO_ACCOUNT_SID)%')] private readonly string $accountSid,
        #[Autowire('%env(string:TWILIO_API_KEY_SID)%')] private readonly string $apiKeySid,
        #[Autowire('%env(string:TWILIO_API_KEY_SECRET)%')] private readonly string $apiKeySecret,
        #[Autowire('%env(string:TWILIO_FROM_PHONE)%')] private readonly string $fromPhone,
        #[Autowire('%env(string:TWILIO_TO_PHONE)%')] private readonly string $defaultToPhone,
        #[Autowire('%env(bool:TWILIO_SSL_VERIFY)%')] private readonly bool $sslVerify
    ) {
    }

    public function isConfigured(): bool
    {
        $accountSid = trim($this->accountSid);
        $apiKeySid = trim($this->apiKeySid);
        $apiKeySecret = trim($this->apiKeySecret);

        $hasAccountSid = (bool) preg_match('/^AC[a-zA-Z0-9]{32}$/', $accountSid);
        $hasApiKeyPair = (bool) preg_match('/^SK[a-zA-Z0-9]{32}$/', $apiKeySid) && $apiKeySecret !== '';

        return $hasAccountSid
            && $hasApiKeyPair
            && trim($this->fromPhone) !== ''
            && trim($this->defaultToPhone) !== '';
    }

    public function verifyApiKey(): bool
    {
        $this->lastError = null;

        if (!$this->isConfigured()) {
            $this->lastError = 'Configuration Twilio incomplete (SID/secret manquant).';
            return false;
        }

        $credentials = $this->resolveCredentials();
        if ($credentials === null) {
            $this->lastError = 'Credentials Twilio invalides.';
            return false;
        }

        [$authUser, $authPassword] = $credentials;
        $accountSidCandidates = $this->resolveAccountSidCandidates($authUser, $authPassword);

        foreach ($accountSidCandidates as $accountSid) {
            $endpoint = sprintf('https://api.twilio.com/2010-04-01/Accounts/%s.json', rawurlencode($accountSid));

            [$ok, $error] = $this->requestTwilio($endpoint, $authUser, $authPassword, null);
            if ($ok) {
                return true;
            }

            $this->lastError = $error;
        }

        if ($this->lastError === null) {
            $this->lastError = 'Verification Twilio echouee.';
        }

        return false;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function sendToDefaultPhone(string $message): bool
    {
        return $this->sendSms($this->defaultToPhone, $message);
    }

    public function sendSms(string $toPhone, string $message): bool
    {
        $this->lastError = null;

        if (!$this->isConfigured()) {
            $this->lastError = 'Configuration Twilio invalide ou incomplete.';
            $this->logger->warning('Twilio SMS skipped: missing configuration variables.');
            return false;
        }

        $credentials = $this->resolveCredentials();
        if ($credentials === null) {
            $this->lastError = 'Credentials Twilio invalides.';
            return false;
        }

        [$authUser, $authPassword] = $credentials;

        $from = $this->normalizePhone($this->fromPhone);
        $to = $this->normalizePhone($toPhone);
        if ($from === '' || $to === '') {
            $this->lastError = 'Format numero invalide pour Twilio.';
            return false;
        }

        $accountSidCandidates = $this->resolveAccountSidCandidates($authUser, $authPassword);

        foreach ($accountSidCandidates as $accountSid) {
            $endpoint = sprintf('https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json', rawurlencode($accountSid));

            $body = [
                'To' => $to,
                'From' => $from,
                'Body' => $message,
            ];

            [$ok, $error] = $this->requestTwilio($endpoint, $authUser, $authPassword, $body);
            if ($ok) {
                return true;
            }

            $this->lastError = $error;
        }

        if ($this->lastError === null) {
            $this->lastError = 'Envoi SMS Twilio echoue.';
        }

        return false;
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\s+/', '', trim($phone)) ?? '';
    }

    /**
     * @return array{0:string,1:string}|null
     */
    private function resolveCredentials(): ?array
    {
        $apiKeySid = trim($this->apiKeySid);
        $apiKeySecret = trim($this->apiKeySecret);
        if ($apiKeySid !== '' && $apiKeySecret !== '') {
            return [$apiKeySid, $apiKeySecret];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function resolveAccountSidCandidates(string $authUser, string $authPassword): array
    {
        $configuredSid = trim($this->accountSid);
        if ((bool) preg_match('/^AC[a-zA-Z0-9]{32}$/', $configuredSid)) {
            return [$configuredSid];
        }

        $resolved = $this->resolveAccountSid($authUser, $authPassword);
        if ($resolved !== null) {
            return [$resolved];
        }

        return [];
    }

    private function resolveAccountSid(string $authUser, string $authPassword): ?string
    {
        if ($this->resolvedAccountSid !== null) {
            return $this->resolvedAccountSid;
        }

        $configuredSid = trim($this->accountSid);
        if ((bool) preg_match('/^AC[a-zA-Z0-9]{32}$/', $configuredSid)) {
            $this->resolvedAccountSid = $configuredSid;
            return $configuredSid;
        }

        try {
            $response = $this->httpClient->request('GET', 'https://api.twilio.com/2010-04-01/Accounts.json?PageSize=1', [
                'auth_basic' => [$authUser, $authPassword],
                'verify_peer' => $this->sslVerify,
                'verify_host' => $this->sslVerify,
                'timeout' => 15,
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getContent(false);
            $payload = json_decode($body, true);

            if ($statusCode >= 200 && $statusCode < 300) {
                $sid = (string) ($payload['accounts'][0]['sid'] ?? '');
                if ((bool) preg_match('/^AC[a-zA-Z0-9]{32}$/', $sid)) {
                    $this->resolvedAccountSid = $sid;
                    return $sid;
                }
            }

            $this->lastError = sprintf(
                'Twilio account resolution failed (%s): %s',
                (string) ($payload['code'] ?? $statusCode),
                (string) ($payload['message'] ?? 'unable to resolve account')
            );
        } catch (\Throwable $exception) {
            $this->lastError = 'Twilio account resolution exception: ' . $exception->getMessage();
        }

        return null;
    }

    /**
     * @param array<string,string>|null $body
     *
     * @return array{0:bool,1:?string}
     */
    private function requestTwilio(string $endpoint, string $authUser, string $authPassword, ?array $body): array
    {
        try {
            $options = [
                'auth_basic' => [$authUser, $authPassword],
                'verify_peer' => $this->sslVerify,
                'verify_host' => $this->sslVerify,
                'timeout' => 15,
            ];

            if ($body !== null) {
                $options['body'] = $body;
            }

            $method = $body === null ? 'GET' : 'POST';
            $response = $this->httpClient->request($method, $endpoint, $options);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                return [true, null];
            }

            $rawBody = $response->getContent(false);
            $decoded = json_decode($rawBody, true);
            $twilioCode = $decoded['code'] ?? null;
            $twilioMessage = $decoded['message'] ?? null;

            $error = $twilioCode !== null || $twilioMessage !== null
                ? sprintf('Twilio error %s: %s', (string) ($twilioCode ?? 'unknown'), (string) ($twilioMessage ?? 'request rejected'))
                : sprintf('Twilio HTTP %d error.', $statusCode);

            $this->logger->error('Twilio request failed.', [
                'endpoint' => $endpoint,
                'status_code' => $statusCode,
                'response' => $rawBody,
            ]);

            return [false, $error];
        } catch (\Throwable $exception) {
            $error = 'Twilio exception: ' . $exception->getMessage();
            $this->logger->error('Twilio request exception.', [
                'endpoint' => $endpoint,
                'error' => $exception->getMessage(),
            ]);

            return [false, $error];
        }
    }
}
