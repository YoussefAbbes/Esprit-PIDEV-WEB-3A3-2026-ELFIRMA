<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IrrigationCommandRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IrrigationCommandRepository::class)]
#[ORM\Table(name: "irrigation_command")]
class IrrigationCommand
{
    public const STATUS_PENDING = "PENDING";
    public const STATUS_ACK = "ACK";
    public const STATUS_FAILED = "FAILED";

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Parcelle::class)]
    #[ORM\JoinColumn(name: "parcelle_id", referencedColumnName: "id", nullable: false, onDelete: "CASCADE")]
    private ?Parcelle $parcelle = null;

    #[ORM\Column(type: "string", length: 20)]
    private string $command = "AUTO";

    #[ORM\Column(type: "string", length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: "requested_by", type: "string", length: 100)]
    private string $requestedBy = "WEB";

    #[ORM\Column(name: "requested_at", type: "datetime_immutable")]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(name: "processed_at", type: "datetime_immutable", nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    public function __construct()
    {
        $this->requestedAt = new \DateTimeImmutable();
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

    public function getCommand(): string
    {
        return $this->command;
    }

    public function setCommand(string $command): self
    {
        $this->command = strtoupper(trim($command));

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = strtoupper(trim($status));

        return $this;
    }

    public function getRequestedBy(): string
    {
        return $this->requestedBy;
    }

    public function setRequestedBy(string $requestedBy): self
    {
        $this->requestedBy = trim($requestedBy) !== "" ? trim($requestedBy) : "WEB";

        return $this;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function setRequestedAt(\DateTimeImmutable $requestedAt): self
    {
        $this->requestedAt = $requestedAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->requestedAt = $createdAt;

        return $this;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function setProcessedAt(?\DateTimeImmutable $processedAt): self
    {
        $this->processedAt = $processedAt;

        return $this;
    }
}
