<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\LivestockRepository;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LivestockCapacityEmailAlertService
{
    public function __construct(
        private readonly LivestockRepository $livestockRepository,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'cache.app')] private readonly CacheItemPoolInterface $cache,
        #[Autowire('%env(string:CAPACITY_ALERT_TO_EMAIL)%')] private readonly string $toEmail,
        #[Autowire('%env(string:CAPACITY_ALERT_FROM_EMAIL)%')] private readonly string $fromEmail,
        #[Autowire('%env(float:CAPACITY_ALERT_THRESHOLD_PERCENT)%')] private readonly float $thresholdPercent,
        #[Autowire('%env(string:RESEND_API_KEY)%')] private readonly string $resendApiKey,
        #[Autowire('%env(string:RESEND_API_BASE_URL)%')] private readonly string $resendApiBaseUrl,
        #[Autowire('%env(bool:CAPACITY_ALERT_SSL_VERIFY)%')] private readonly bool $sslVerify
    ) {
    }

    public function checkAndSendForLivestock(int $idElevage): bool
    {
        if ($idElevage <= 0) {
            return false;
        }

        $livestock = $this->livestockRepository->findForEdit($idElevage);
        if ($livestock === null) {
            return false;
        }

        $capacity = max(0, (int) ($livestock['capacite'] ?? 0));
        $animals = max(0, (int) ($livestock['nombre_animaux'] ?? 0));

        if ($capacity <= 0) {
            $this->clearAlertState($idElevage);
            return false;
        }

        $usagePercent = ($animals / $capacity) * 100;
        $isAboveThreshold = $usagePercent >= $this->thresholdPercent;

        $cacheItem = $this->cache->getItem($this->cacheKey($idElevage));
        $wasAboveThreshold = $cacheItem->isHit() && (bool) $cacheItem->get();

        if (!$isAboveThreshold) {
            if ($wasAboveThreshold) {
                $this->clearAlertState($idElevage);
            }

            return false;
        }

        if ($wasAboveThreshold) {
            return false;
        }

        $typeElevage = trim((string) ($livestock['type_elevage'] ?? 'Unknown'));
        $etatElevage = trim((string) ($livestock['etat_elevage'] ?? ''));
        if ($etatElevage === '') {
            $etatElevage = 'Non specifie';
        }

        $usageLabel = (string) (int) round($usagePercent);

        $subject = sprintf(
            'ALERTE: Elevage %s a %s%% de capacite',
            $typeElevage,
            $usageLabel
        );

        $text = $this->buildEmailBody(
            typeElevage: $typeElevage,
            etatElevage: $etatElevage,
            nombreAnimaux: $animals,
            capacite: $capacity,
            usageLabel: $usageLabel
        );

        try {
            if (!$this->sendEmailByApi($subject, $text)) {
                return false;
            }

            $cacheItem->set(true);
            $cacheItem->expiresAfter(60 * 60 * 24 * 30);
            $this->cache->save($cacheItem);

            $this->logger->info('Capacity alert email sent.', [
                'id_elevage' => $idElevage,
                'usage_percent' => $usagePercent,
                'to' => $this->toEmail,
            ]);

            return true;
        } catch (\Throwable $exception) {
            $this->logger->error('Capacity alert email failed.', [
                'id_elevage' => $idElevage,
                'usage_percent' => $usagePercent,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public function clearAlertState(int $idElevage): void
    {
        if ($idElevage <= 0) {
            return;
        }

        $cacheItem = $this->cache->getItem($this->cacheKey($idElevage));
        $cacheItem->set(false);
        $cacheItem->expiresAfter(60 * 60 * 24 * 30);
        $this->cache->save($cacheItem);
    }

    private function cacheKey(int $idElevage): string
    {
        return sprintf('livestock_capacity_email_alert_%d', $idElevage);
    }

    private function buildEmailBody(
        string $typeElevage,
        string $etatElevage,
        int $nombreAnimaux,
        int $capacite,
        string $usageLabel
    ): string {
        $thresholdLabel = rtrim(rtrim(number_format($this->thresholdPercent, 2, '.', ''), '0'), '.');

        return implode("\n", [
            'ALERTE DE CAPACITE D\'ELEVAGE',
            '',
            'Type d\'elevage: ' . $typeElevage,
            'Etat: ' . $etatElevage,
            'Nombre d\'animaux actuels: ' . $nombreAnimaux,
            'Capacite maximale: ' . $capacite,
            'Taux d\'utilisation: ' . $usageLabel . '%',
            'Statut: Taux d\'utilisation presque insuffisant.',
            '',
            'ATTENTION: L\'elevage a atteint ou depasse ' . $thresholdLabel . '% de sa capacite.',
            'Veuillez considerer l\'expansion ou la reduction du nombre d\'animaux.',
            '',
            'Date/Heure: ' . (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            '---',
            'Farm Management System',
        ]);
    }

    private function sendEmailByApi(string $subject, string $text): bool
    {
        $apiKey = trim($this->resendApiKey);
        if ($apiKey === '') {
            $this->logger->error('Resend API key is missing for capacity alert emails.');
            return false;
        }

        $baseUrl = rtrim(trim($this->resendApiBaseUrl), '/');
        if ($baseUrl === '') {
            $this->logger->error('Resend API base URL is empty for capacity alert emails.');
            return false;
        }

        $fromEmail = trim($this->fromEmail);
        if ($fromEmail === '') {
            $fromEmail = 'onboarding@resend.dev';
        }

        $endpoint = $baseUrl . '/emails';

        $response = $this->httpClient->request('POST', $endpoint, [
            'headers' => [
                'accept' => 'application/json',
                'content-type' => 'application/json',
                'authorization' => 'Bearer ' . $apiKey,
            ],
            'json' => [
                'from' => $fromEmail,
                'to' => [$this->toEmail],
                'subject' => $subject,
                'text' => $text,
            ],
            'verify_peer' => $this->sslVerify,
            'verify_host' => $this->sslVerify,
            'timeout' => 20,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode >= 200 && $statusCode < 300) {
            return true;
        }

        $raw = $response->getContent(false);
        $this->logger->error('Resend API email request failed.', [
            'status_code' => $statusCode,
            'response' => $raw,
        ]);

        return false;
    }
}
