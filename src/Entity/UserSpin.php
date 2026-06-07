<?php

namespace App\Entity;

use App\Repository\UserSpinRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserSpinRepository::class)]
#[ORM\Table(name: 'user_spin')]
class UserSpin
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $utilisateurId = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SpinReward $spinReward = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $generatedCode = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $spunAt;

    #[ORM\Column]
    private bool $isUsed = false;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $usedAt = null;

    public function __construct()
    {
        $this->spunAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getUtilisateurId(): int { return $this->utilisateurId; }
    public function setUtilisateurId(int $utilisateurId): static { $this->utilisateurId = $utilisateurId; return $this; }

    public function getSpinReward(): ?SpinReward { return $this->spinReward; }
    public function setSpinReward(?SpinReward $spinReward): static { $this->spinReward = $spinReward; return $this; }

    public function getGeneratedCode(): ?string { return $this->generatedCode; }
    public function setGeneratedCode(?string $generatedCode): static { $this->generatedCode = $generatedCode; return $this; }

    public function getSpunAt(): \DateTime { return $this->spunAt; }
    public function setSpunAt(\DateTime $spunAt): static { $this->spunAt = $spunAt; return $this; }

    public function isUsed(): bool { return $this->isUsed; }
    public function setIsUsed(bool $isUsed): static { $this->isUsed = $isUsed; return $this; }

    public function getUsedAt(): ?\DateTime { return $this->usedAt; }
    public function setUsedAt(?\DateTime $usedAt): static { $this->usedAt = $usedAt; return $this; }
}
