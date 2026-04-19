<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\VaccinationRepository;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class VaccinationSmsAlertService
{
    private const MAX_ALERT_WINDOW_DAYS = 2;

    public function __construct(
        private readonly VaccinationRepository $vaccinationRepository,
        private readonly TwilioSmsService $twilioSmsService,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'cache.app')] private readonly CacheItemPoolInterface $cache
    ) {
    }

    public function checkAndSendAlerts(int $days = 2): int
    {
        $windowDays = min(self::MAX_ALERT_WINDOW_DAYS, max(0, $days));

        if (!$this->twilioSmsService->isConfigured()) {
            $this->logger->warning('Twilio SMS alerts skipped: API key configuration is incomplete.');
            return 0;
        }

        if (!$this->twilioSmsService->verifyApiKey()) {
            $this->logger->error('Twilio SMS alerts skipped: API key validation failed.');
            return 0;
        }

        $eligibleVaccinations = $this->vaccinationRepository->findEligibleForIntervalSmsAlerts($windowDays);
        if ($eligibleVaccinations === []) {
            return 0;
        }

        $sentCount = 0;

        foreach ($eligibleVaccinations as $vaccination) {
            $dateDoneRaw = (string) ($vaccination['date_done'] ?? '');
            $dateNextRaw = (string) ($vaccination['date_next'] ?? '');
            $dateDone = \DateTimeImmutable::createFromFormat('Y-m-d', $dateDoneRaw);
            $dateNext = \DateTimeImmutable::createFromFormat('Y-m-d', $dateNextRaw);
            if ($dateDone === false || $dateNext === false) {
                continue;
            }

            $intervalDays = isset($vaccination['interval_days'])
                ? (int) $vaccination['interval_days']
                : (int) $dateDone->diff($dateNext)->format('%r%a');

            if ($intervalDays < 0 || $intervalDays > $windowDays) {
                continue;
            }

            $cacheKey = sprintf(
                'vaccination_sms_interval_%d_%s_%s',
                (int) ($vaccination['id_vaccination'] ?? 0),
                $dateDone->format('Ymd'),
                $dateNext->format('Ymd')
            );

            $cacheItem = $this->cache->getItem($cacheKey);
            if ($cacheItem->isHit()) {
                continue;
            }

            $message = $this->buildSmsMessage($vaccination, $dateDone, $dateNext, $intervalDays);
            $sent = $this->twilioSmsService->sendToDefaultPhone($message);

            if ($sent) {
                $cacheItem->set(true);
                $cacheItem->expiresAfter(60 * 60 * 24 * 30);
                $this->cache->save($cacheItem);
                $sentCount++;
            }
        }

        if ($sentCount > 0) {
            $this->logger->info('Vaccination SMS alerts sent.', ['count' => $sentCount]);
        }

        return $sentCount;
    }

    private function buildSmsMessage(
        array $vaccination,
        \DateTimeImmutable $dateDone,
        \DateTimeImmutable $dateNext,
        int $intervalDays
    ): string
    {
        $animalType = (string) ($vaccination['animal_type'] ?? 'Animal');
        $vaccineName = (string) ($vaccination['vaccine_name'] ?? 'Vaccin');
        $animalId = (int) ($vaccination['id_animal'] ?? 0);

        return sprintf(
            'Alerte: intervalle vaccination court (%d jour(s)). Animal: %s (ID:%d), vaccin: %s, date faite: %s, prochaine date: %s.',
            $intervalDays,
            $animalType,
            $animalId,
            $vaccineName,
            $dateDone->format('d/m/Y'),
            $dateNext->format('d/m/Y')
        );
    }
}
