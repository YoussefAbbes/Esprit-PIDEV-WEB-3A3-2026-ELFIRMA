<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\CultureRepository;
use Symfony\Component\Validator\Constraints as Assert;

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

    #[ORM\Column(name: "nom_culture", type: "string", length: 255)]
    #[Assert\NotBlank(message: 'Le nom de la culture est obligatoire')]
    #[Assert\Length(
        min: 2,
        max: 255,
        minMessage: 'Le nom de la culture doit contenir au moins {{ limit }} caractères',
        maxMessage: 'Le nom de la culture ne peut pas dépasser {{ limit }} caractères'
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

    #[ORM\Column(name: "date_recolte_reelle", type: "date", nullable: true)]
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

    #[ORM\Column(name: "quantite_plantee", type: "float", columnDefinition: "DOUBLE NOT NULL")]
    #[Assert\NotNull(message: 'La quantité plantée est obligatoire')]
    #[Assert\GreaterThanOrEqual(
        value: 0,
        message: 'La quantité plantée doit être supérieure ou égale à 0'
    )]
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

    #[ORM\Column(name: "quantite_recoltee", type: "float", columnDefinition: "DOUBLE NOT NULL")]
    #[Assert\NotNull(message: 'La quantité récoltée est obligatoire')]
    #[Assert\GreaterThanOrEqual(
        value: 0,
        message: 'La quantité récoltée doit être supérieure ou égale à 0'
    )]
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

    #[ORM\Column(name: "cout_production", type: "float", columnDefinition: "DOUBLE NOT NULL")]
    #[Assert\NotNull(message: 'Le coût de production est obligatoire')]
    #[Assert\GreaterThanOrEqual(
        value: 0,
        message: 'Le coût de production doit être supérieur ou égal à 0'
    )]
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
    private ?float $rendement = null;

    public function getRendement(): float
    {
        return $this->rendement;
    }

    public function setRendement(float $rendement): self
    {
        $this->rendement = $rendement;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
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
}