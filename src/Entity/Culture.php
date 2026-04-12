<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\CultureRepository;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CultureRepository::class)]
#[ORM\Table(name: "culture")]
class Culture
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    #[ORM\ManyToOne(targetEntity: Parcelle::class, inversedBy: "cultures")]
    #[
        ORM\JoinColumn(
            name: "parcelleId",
            referencedColumnName: "id",
            nullable: true,
        ),
    ]
    #[Assert\NotNull(message: "Parcel is required.")]
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

    #[ORM\Column(name: "nom_culture", type: "string", length: 255)]
    #[Assert\NotBlank(message: "Crop name is required.")]
    #[
        Assert\Length(
            min: 2,
            max: 255,
            minMessage: "Crop name must be at least {{ limit }} characters long.",
            maxMessage: "Crop name cannot exceed {{ limit }} characters.",
        ),
    ]
    private ?string $nomCulture = null;

    public function getNomCulture(): ?string
    {
        return $this->nomCulture;
    }

    public function setNomCulture(string $nomCulture): self
    {
        $this->nomCulture = trim($nomCulture);
        return $this;
    }

    #[ORM\Column(type: "string", length: 255, nullable: true)]
    #[Assert\NotBlank(message: "Variety is required.")]
    #[
        Assert\Length(
            min: 2,
            max: 255,
            minMessage: "Variety must be at least {{ limit }} characters long.",
            maxMessage: "Variety cannot exceed {{ limit }} characters.",
        ),
    ]
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

    #[ORM\Column(name: "date_plantation", type: "date", nullable: true)]
    #[Assert\NotNull(message: "Planting date is required.")]
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

    #[ORM\Column(name: "date_recolte_prevue", type: "date", nullable: true)]
    #[Assert\NotNull(message: "Expected harvest date is required.")]
    #[
        Assert\GreaterThanOrEqual(
            propertyPath: "datePlantation",
            message: "Expected harvest date must be on or after planting date.",
        ),
    ]
    private ?\DateTimeInterface $dateRecoltePrevue = null;

    public function getDateRecoltePrevue(): ?\DateTimeInterface
    {
        return $this->dateRecoltePrevue;
    }

    public function setDateRecoltePrevue(
        ?\DateTimeInterface $dateRecoltePrevue,
    ): self {
        $this->dateRecoltePrevue = $dateRecoltePrevue;
        return $this;
    }

    #[ORM\Column(name: "date_recolte_reelle", type: "date", nullable: true)]
    private ?\DateTimeInterface $dateRecolteReelle = null;

    public function getDateRecolteReelle(): ?\DateTimeInterface
    {
        return $this->dateRecolteReelle;
    }

    public function setDateRecolteReelle(
        ?\DateTimeInterface $dateRecolteReelle,
    ): self {
        $this->dateRecolteReelle = $dateRecolteReelle;
        return $this;
    }

    #[
        ORM\Column(
            name: "quantite_plantee",
            type: "float",
            columnDefinition: "DOUBLE NOT NULL",
        ),
    ]
    #[Assert\NotNull(message: "Quantity planted is required.")]
    #[Assert\Positive(message: "Quantity planted must be greater than 0.")]
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

    #[
        ORM\Column(
            name: "quantite_recoltee",
            type: "float",
            columnDefinition: "DOUBLE NOT NULL",
        ),
    ]
    #[
        Assert\PositiveOrZero(
            message: "Quantity harvested must be greater than or equal to 0.",
        ),
    ]
    private float $quantiteRecoltee = 0.0;

    public function getQuantiteRecoltee(): float
    {
        return $this->quantiteRecoltee;
    }

    public function setQuantiteRecoltee(float $quantiteRecoltee): self
    {
        $this->quantiteRecoltee = $quantiteRecoltee;
        return $this;
    }

    #[
        ORM\Column(
            name: "cout_production",
            type: "float",
            columnDefinition: "DOUBLE NOT NULL",
        ),
    ]
    #[Assert\PositiveOrZero(message: "Production cost must be 0 or greater.")]
    private float $coutProduction = 0.0;

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
    #[Assert\NotBlank(message: "Status is required.")]
    #[
        Assert\Choice(
            choices: ["Harvested", "In Progress", "Planned"],
            message: "Status must be one of: Harvested, In Progress, Planned.",
        ),
    ]
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
    #[
        Assert\PositiveOrZero(
            message: "Yield must be greater than or equal to 0.",
        ),
    ]
    private ?float $rendement = 0.0;

    public function getRendement(): float
    {
        return $this->rendement;
    }

    public function setRendement(float $rendement): self
    {
        $this->rendement = $rendement;
        return $this;
    }

    #[ORM\Column(type: "text", nullable: true)]
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

    #[ORM\Column(type: "blob", nullable: true)]
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
}
