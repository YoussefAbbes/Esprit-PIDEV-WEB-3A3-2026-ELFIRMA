<?php

namespace App\Entity;

use App\Repository\SpinRewardRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SpinRewardRepository::class)]
#[ORM\Table(name: 'spin_reward')]
class SpinReward
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $label = '';

    /** promo_code | coupon | no_prize */
    #[ORM\Column(length: 30)]
    private string $type = 'no_prize';

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $codePrefix = null;

    /** percent | fixed */
    #[ORM\Column(length: 20)]
    private string $discountType = 'percent';

    #[ORM\Column(type: 'float')]
    private float $discountValue = 0.0;

    #[ORM\Column(length: 7)]
    private string $color = '#116530';

    #[ORM\Column]
    private int $probabilityWeight = 10;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    public function getId(): ?int { return $this->id; }

    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): static { $this->label = $label; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }

    public function getCodePrefix(): ?string { return $this->codePrefix; }
    public function setCodePrefix(?string $codePrefix): static { $this->codePrefix = $codePrefix; return $this; }

    public function getDiscountType(): string { return $this->discountType; }
    public function setDiscountType(string $discountType): static { $this->discountType = $discountType; return $this; }

    public function getDiscountValue(): float { return $this->discountValue; }
    public function setDiscountValue(float $discountValue): static { $this->discountValue = $discountValue; return $this; }

    public function getColor(): string { return $this->color; }
    public function setColor(string $color): static { $this->color = $color; return $this; }

    public function getProbabilityWeight(): int { return $this->probabilityWeight; }
    public function setProbabilityWeight(int $probabilityWeight): static { $this->probabilityWeight = $probabilityWeight; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): static { $this->isActive = $isActive; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
}
