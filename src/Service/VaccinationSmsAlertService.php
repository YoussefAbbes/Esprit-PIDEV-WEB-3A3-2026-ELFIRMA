<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\VaccinationRepository;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class VaccinationSmsAlertService
{
    public function __construct(
        private readonly VaccinationRepository $vaccinationRepository,
        private readonly TwilioSmsService $twilioSmsService,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'cache.app')] private readonly CacheItemPoolInterface $cache
    ) {
    }

    public function checkAndSendAlerts(int $days = 2): int
    {
        if (!$this->twilioSmsService->isConfigured()) {
            $this->logger->warning('Twilio SMS alerts skipped: API key configuration is incomplete.');
            return 0;
        }

        if (!$this->twilioSmsService->verifyApiKey()) {
            $this->logger->error('Twilio SMS alerts skipped: API key validation failed.');
            return 0;
        }

        $upcomingVaccinations = $this->vaccinationRepository->findUpcomingForSmsAlerts($days);
        if ($upcomingVaccinations === []) {
            return 0;
        }

        $today = new \DateTimeImmutable('today');
        $sentCount = 0;

        foreach ($upcomingVaccinations as $vaccination) {
            $dateNextRaw = (string) ($vaccination['date_next'] ?? '');
            $dateNext = \DateTimeImmutable::createFromFormat('Y-m-d', $dateNextRaw);
            if ($dateNext === false) {
                continue;
            }

            $daysLeft = (int) $today->diff($dateNext)->format('%r%a');
            if ($daysLeft < 0 || $daysLeft > $days) {
                continue;
            }

            $cacheKey = sprintf(
                'vaccination_sms_%d_%s',
                (int) ($vaccination['id_vaccination'] ?? 0),
                $dateNext->format('Ymd')
            );

            $cacheItem = $this->cache->getItem($cacheKey);
            if ($cacheItem->isHit()) {
                continue;
            }

            $message = $this->buildSmsMessage($vaccination, $dateNext, $daysLeft);
            $sent = $this->twilioSmsService->sendToDefaultPhone($message);

            if ($sent) {
                $cacheItem->set(true);
                $cacheItem->expiresAfter(60 * 60 * 24 * 3);
                $this->cache->save($cacheItem);
                $sentCount++;
            }
        }

        if ($sentCount > 0) {
            $this->logger->info('Vaccination SMS alerts sent.', ['count' => $sentCount]);
        }

        return $sentCount;
    }

    private function buildSmsMessage(array $vaccination, \DateTimeImmutable $dateNext, int $daysLeft): string
    {
        $animalType = (string) ($vaccination['animal_type'] ?? 'Animal');
        $vaccineName = (string) ($vaccination['vaccine_name'] ?? 'Vaccin');
        $animalId = (int) ($vaccination['id_animal'] ?? 0);

        $urgency = $daysLeft === 0
            ? "aujourd'hui"
            : sprintf('dans %d jour(s)', $daysLeft);

        return sprintf(
            'Alerte: la prochaine vaccination approche (%s). Animal: %s (ID:%d), vaccin: %s, date prevue: %s.',
            $urgency,
            $animalType,
            $animalId,
            $vaccineName,
            $dateNext->format('d/m/Y')
        );
    }
}
