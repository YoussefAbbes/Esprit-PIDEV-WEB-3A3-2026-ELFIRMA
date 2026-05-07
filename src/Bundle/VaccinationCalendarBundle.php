<?php
//correction de bundle vaccination bundle erreurs de syntaxe et d'importation
declare(strict_types=1);

namespace App\Bundle;

use App\Enum\VaccinationStatus;

final class VaccinationCalendarBundle
{
    private ?\DateTimeInterface $dateDone;

    private ?\DateTimeInterface $dateNext;

    private ?VaccinationStatus $status;

    public function __construct(
        ?\DateTimeInterface $dateDone,
        ?\DateTimeInterface $dateNext,
        ?VaccinationStatus $status,
    ) {
        $this->dateDone = $dateDone;
        $this->dateNext = $dateNext;
        $this->status = $status;
    }

    public static function fromVaccinationFields(
        ?\DateTimeInterface $dateDone,
        ?\DateTimeInterface $dateNext,
        ?VaccinationStatus $status,
    ): self {
        return new self($dateDone, $dateNext, $status);
    }

    public function getDateDone(): ?\DateTimeInterface
    {
        return $this->dateDone;
    }

    public function getDateNext(): ?\DateTimeInterface
    {
        return $this->dateNext;
    }

    public function getStatus(): ?VaccinationStatus
    {
        return $this->status;
    }
}
