<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\IrrigationCommand;
use App\Entity\IrrigationState;
use App\Entity\Parcelle;
use App\Repository\IrrigationEventRepository;
use App\Repository\IrrigationStateRepository;
use Doctrine\ORM\EntityManagerInterface;

final class IrrigationService
{
    public const COMMAND_AUTO = "AUTO";
    public const COMMAND_MANUAL_ON = "MANUAL_ON";
    public const COMMAND_MANUAL_OFF = "MANUAL_OFF";

    private const MAX_EVENTS = 20;

    /**
     * @var list<string>
     */
    public const ALLOWED_COMMANDS = [
        self::COMMAND_AUTO,
        self::COMMAND_MANUAL_ON,
        self::COMMAND_MANUAL_OFF,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IrrigationStateRepository $irrigationStateRepository,
        private readonly IrrigationEventRepository $irrigationEventRepository,
    ) {
    }

    public function isValidCommand(string $command): bool
    {
        return in_array($this->normalizeCommand($command), self::ALLOWED_COMMANDS, true);
    }

    public function normalizeCommand(string $command): string
    {
        return strtoupper(trim($command));
    }

    public function queueCommand(Parcelle $parcelle, string $command): IrrigationCommand
    {
        $normalizedCommand = $this->normalizeCommand($command);

        if (!$this->isValidCommand($normalizedCommand)) {
            throw new \InvalidArgumentException("Invalid irrigation command.");
        }

        $irrigationCommand = (new IrrigationCommand())
            ->setParcelle($parcelle)
            ->setCommand($normalizedCommand)
            ->setStatus(IrrigationCommand::STATUS_PENDING)
            ->setRequestedBy("WEB")
            ->setRequestedAt(new \DateTimeImmutable());

        $this->entityManager->persist($irrigationCommand);
        $this->entityManager->flush();

        return $irrigationCommand;
    }

    /**
     * @return array<string,mixed>
     */
    public function getLatestStatePayload(Parcelle $parcelle): array
    {
        $state = $this->irrigationStateRepository->findLatestByParcelle($parcelle);

        if (!$state instanceof IrrigationState) {
            return $this->emptyStatePayload();
        }

        return [
            "exists" => true,
            "mode" => $state->getMode(),
            "pumpRunning" => $state->isPumpRunning(),
            "soilValue" => $state->getSoilValue(),
            "needsWater" => $state->getNeedsWater(),
            "updatedAt" => $state->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function emptyStatePayload(): array
    {
        return [
            "exists" => false,
            "mode" => null,
            "pumpRunning" => false,
            "soilValue" => null,
            "needsWater" => null,
            "updatedAt" => null,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getRecentEventsPayload(Parcelle $parcelle, int $limit = self::MAX_EVENTS): array
    {
        $events = $this->irrigationEventRepository->findLatestByParcelle(
            $parcelle,
            min(max(1, $limit), self::MAX_EVENTS),
        );

        return array_map(
            static fn ($event): array => [
                "createdAt" => $event->getCreatedAt()->format(DATE_ATOM),
                "source" => $event->getSource(),
                "eventType" => $event->getEventType(),
                "message" => $event->getMessage(),
                "soilValue" => $event->getSoilValue(),
                "needsWater" => $event->getNeedsWater(),
            ],
            $events,
        );
    }
}
