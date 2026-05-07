<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IrrigationEventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IrrigationEventRepository::class)]
#[ORM\Table(name: "irrigation_event")]
class IrrigationEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Parcelle::class)]
    #[ORM\JoinColumn(name: "parcelle_id", referencedColumnName: "id", nullable: false, onDelete: "CASCADE")]
    private ?Parcelle $parcelle = null;

    #[ORM\Column(type: "string", length: 30)]
    private string $source = "DEVICE";

    #[ORM\Column(name: "event_type", type: "string", length: 80)]
    private string $eventType = "UNKNOWN";

    #[ORM\Column(type: "text")]
    private string $message = "";

    #[ORM\Column(name: "soil_value", type: "integer", nullable: true)]
    private ?int $soilValue = null;

    #[ORM\Column(name: "needs_water", type: "boolean", nullable: true)]
    private ?bool $needsWater = null;

    #[ORM\Column(name: "created_at", type: "datetime_immutable")]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: "updated_at", type: "datetime_immutable", nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(name: "created_by", type: "integer", nullable: true)]
    private ?int $createdBy = null;

    #[ORM\Column(name: "updated_by", type: "integer", nullable: true)]
    private ?int $updatedBy = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = strtoupper(trim($source));

        return $this;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function setEventType(string $eventType): self
    {
        $this->eventType = strtoupper(trim($eventType));

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): self
    {
        $this->message = trim($message);

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCreatedBy(): ?int
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?int $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getUpdatedBy(): ?int
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?int $updatedBy): self
    {
        $this->updatedBy = $updatedBy;

        return $this;
    }
}
