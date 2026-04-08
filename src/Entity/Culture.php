<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\CultureRepository;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: CultureRepository::class)]
#[ORM\Table(name: 'culture')]
class Culture
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    #[ORM\ManyToOne(targetEntity: Parcelle::class, inversedBy: 'cultures')]
    #[ORM\JoinColumn(name: 'parcelleId', referencedColumnName: 'id', nullable: true)]
    private ?Parcelle $parcelle = null;

    public function getParcelle(): ?Parcelle
    {
        return $this->parcelle;
    }

    public function setParcelle(?Parcelle $parcelle): self
    {
        $this->parcelle = $parcelle;
        return $this;
    }

    #[ORM\Column(name: 'nomCulture', type: "string", length: 255)]
    #[Assert\NotBlank(message: 'Crop name is required.')]
    #[Assert\Length(
        min: 2,
        max: 255,
        minMessage: 'Crop name must be at least {{ limit }} characters.',
        maxMessage: 'Crop name cannot exceed {{ limit }} characters.'
    )]
    private ?string $nomCulture = null;

    public function getNomCulture(): ?string
    {
        return $this->nomCulture;
    }

    public function setNomCulture(string $nomCulture): self
    {
        $this->nomCulture = $nomCulture;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Assert\Length(
        max: 255,
        maxMessage: 'Variety cannot exceed {{ limit }} characters.'
    )]
    private ?string $variete = null;

    public function getVariete(): ?string
    {
        return $this->variete;
    }

    public function setVariete(?string $variete): self
    {
        $this->variete = $variete;
        return $this;
    }

    #[ORM\Column(name: 'datePlantation', type: "date", nullable: true)]
    private ?\DateTimeInterface $datePlantation = null;

    public function getDatePlantation(): ?\DateTimeInterface
    {
        return $this->datePlantation;
    }

    public function setDatePlantation(?\DateTimeInterface $datePlantation): self
    {
        $this->datePlantation = $datePlantation;
        return $this;
    }

    #[ORM\Column(name: 'dateRecoltePrevue', type: "date", nullable: true)]
    private ?\DateTimeInterface $dateRecoltePrevue = null;

    public function getDateRecoltePrevue(): ?\DateTimeInterface
    {
        return $this->dateRecoltePrevue;
    }

    public function setDateRecoltePrevue(?\DateTimeInterface $dateRecoltePrevue): self
    {
        $this->dateRecoltePrevue = $dateRecoltePrevue;
        return $this;
    }

    #[ORM\Column(name: 'dateRecolteReelle', type: "date", nullable: true)]
    private ?\DateTimeInterface $dateRecolteReelle = null;

    public function getDateRecolteReelle(): ?\DateTimeInterface
    {
        return $this->dateRecolteReelle;
    }

    public function setDateRecolteReelle(?\DateTimeInterface $dateRecolteReelle): self
    {
        $this->dateRecolteReelle = $dateRecolteReelle;
        return $this;
    }

    #[ORM\Column(name: 'quantitePlantee', type: "float")]
    #[Assert\NotNull(message: 'Quantity planted is required.')]
    #[Assert\GreaterThanOrEqual(value: 0, message: 'Quantity planted cannot be negative.')]
    private float $quantitePlantee;

    public function getQuantitePlantee(): float
    {
        return $this->quantitePlantee;
    }

    public function setQuantitePlantee(float $quantitePlantee): self
    {
        $this->quantitePlantee = $quantitePlantee;
        return $this;
    }

    #[ORM\Column(name: 'quantiteRecoltee', type: "float")]
    #[Assert\NotNull(message: 'Quantity harvested is required.')]
    #[Assert\GreaterThanOrEqual(value: 0, message: 'Quantity harvested cannot be negative.')]
    private float $quantiteRecoltee;

    public function getQuantiteRecoltee(): float
    {
        return $this->quantiteRecoltee;
    }

    public function setQuantiteRecoltee(float $quantiteRecoltee): self
    {
        $this->quantiteRecoltee = $quantiteRecoltee;
        return $this;
    }

    #[ORM\Column(name: 'coutProduction', type: "float")]
    #[Assert\NotNull(message: 'Production cost is required.')]
    #[Assert\GreaterThanOrEqual(value: 0, message: 'Production cost cannot be negative.')]
    private float $coutProduction;


    public function getCoutProduction(): float
    {
        return $this->coutProduction;
    }

    public function setCoutProduction(float $coutProduction): self
    {
        $this->coutProduction = $coutProduction;
        return $this;
    }

    #[ORM\Column(type: "string", length: 50, nullable: true)]
    #[Assert\Choice(
        choices: ['Harvested', 'In Progress', 'Planned'],
        message: 'Status must be one of: Harvested, In Progress, Planned.'
    )]
private ?string $statut = null;

public function getStatut(): ?string
{
    return $this->statut;
}

public function setStatut(?string $statut): self
{
    $this->statut = $statut;
    return $this;
}

    #[ORM\Column(type: "float", nullable: true)]
    #[Assert\GreaterThanOrEqual(value: 0, message: 'Yield cannot be negative.')]
    private ?float $rendement = null;

    public function getRendement(): ?float
    {
        return $this->rendement;
    }

    public function setRendement(?float $rendement): self
    {
        $this->rendement = $rendement;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 5000, maxMessage: 'Observations cannot exceed {{ limit }} characters.')]
    private ?string $observations = null;

    public function getObservations(): ?string
    {
        return $this->observations;
    }

    public function setObservations(?string $observations): self
    {
        $this->observations = $observations;
        return $this;
    }

    #[ORM\Column(type: 'blob', nullable: true)]
    private $image = null;

    public function getImage()
    {
        return $this->image;
    }

    public function setImage($image): self
    {
        $this->image = $image;
        return $this;
    }

    #[Assert\Callback]
    public function validateDates(ExecutionContextInterface $context): void
    {
        if ($this->datePlantation && $this->dateRecoltePrevue && $this->dateRecoltePrevue < $this->datePlantation) {
            $context->buildViolation('Expected harvest date must be after planting date.')
                ->atPath('dateRecoltePrevue')
                ->addViolation();
        }

        if ($this->datePlantation && $this->dateRecolteReelle && $this->dateRecolteReelle < $this->datePlantation) {
            $context->buildViolation('Actual harvest date must be after planting date.')
                ->atPath('dateRecolteReelle')
                ->addViolation();
        }
    }
}
