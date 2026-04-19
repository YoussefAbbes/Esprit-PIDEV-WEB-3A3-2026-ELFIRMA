<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IrrigationStateRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IrrigationStateRepository::class)]
#[ORM\Table(name: "irrigation_state")]
class IrrigationState
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Parcelle::class)]
    #[ORM\JoinColumn(name: "parcelle_id", referencedColumnName: "id", nullable: false, onDelete: "CASCADE")]
    private ?Parcelle $parcelle = null;

    #[ORM\Column(type: "string", length: 20, nullable: true)]
    private ?string $mode = null;

    #[ORM\Column(name: "pump_running", type: "boolean")]
    private bool $pumpRunning = false;

    #[ORM\Column(name: "soil_value", type: "integer", nullable: true)]
    private ?int $soilValue = null;

    #[ORM\Column(name: "needs_water", type: "boolean", nullable: true)]
    private ?bool $needsWater = null;

    #[ORM\Column(name: "updated_at", type: "datetime_immutable")]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getParcelle(): ?Parcelle
    {
        return $this->parcelle;
    }

    public function setParcelle(?Parcelle $parcelle): self
    {
        $this->parcelle = $parcelle;

        return $this;
    }

    public function getMode(): ?string
    {
        return $this->mode;
    }

    public function setMode(?string $mode): self
    {
        $this->mode = $mode !== null ? strtoupper(trim($mode)) : null;

        return $this;
    }

    public function isPumpRunning(): bool
    {
        return $this->pumpRunning;
    }

    public function setPumpRunning(bool $pumpRunning): self
    {
        $this->pumpRunning = $pumpRunning;

        return $this;
    }

    public function getSoilValue(): ?int
    {
        return $this->soilValue;
    }

    public function setSoilValue(?int $soilValue): self
    {
        $this->soilValue = $soilValue;

        return $this;
    }

    public function getNeedsWater(): ?bool
    {
        return $this->needsWater;
    }

    public function setNeedsWater(?bool $needsWater): self
    {
        $this->needsWater = $needsWater;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
