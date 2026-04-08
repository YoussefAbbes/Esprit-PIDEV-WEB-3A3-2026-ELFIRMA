<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Repository\ParcelleRepository;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ParcelleRepository::class)]
#[ORM\Table(name: 'parcelle')]
class Parcelle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'Parcel name is required.')]
    #[Assert\Length(
        min: 2,
        max: 255,
        minMessage: 'Parcel name must be at least {{ limit }} characters.',
        maxMessage: 'Parcel name cannot exceed {{ limit }} characters.'
    )]
    private ?string $nom = null;

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'Location is required.')]
    #[Assert\Length(
        min: 2,
        max: 255,
        minMessage: 'Location must be at least {{ limit }} characters.',
        maxMessage: 'Location cannot exceed {{ limit }} characters.'
    )]
    private ?string $localisation = null;

    public function getLocalisation(): ?string
    {
        return $this->localisation;
    }

    public function setLocalisation(string $localisation): self
    {
        $this->localisation = $localisation;
        return $this;
    }

    #[ORM\Column(type: 'float')]
    #[Assert\NotNull(message: 'Area is required.')]
    #[Assert\GreaterThan(value: 0, message: 'Area must be greater than 0.')]
    #[Assert\LessThanOrEqual(value: 1000000, message: 'Area value is too large.')]
    private ?float $superficie = null;

    public function getSuperficie(): ?float
    {
        return $this->superficie;
    }

    public function setSuperficie(float $superficie): self
    {
        $this->superficie = $superficie;
        return $this;
    }

    #[ORM\Column(name: 'typeSol', type: "string", length: 100, nullable: true)]
    #[Assert\Choice(
        choices: ['Sandy', 'Loamy', 'Clay', 'Humus'],
        message: 'Soil type must be one of: Sandy, Loamy, Clay, Humus.'
    )]
    private ?string $typeSol = null;

    public function getTypeSol(): ?string
    {
        return $this->typeSol;
    }

    public function setTypeSol(?string $typeSol): self
    {
        $this->typeSol = $typeSol;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    #[Assert\Choice(
        choices: ['Available', 'Occupied', 'Resting'],
        message: 'Status must be one of: Available, Occupied, Resting.'
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

    #[ORM\Column(name: 'dateCreation', type: "date", nullable: true)]
    private ?\DateTimeInterface $dateCreation = null;

    public function getDateCreation(): ?\DateTimeInterface
    {
        return $this->dateCreation;
    }

    public function setDateCreation(?\DateTimeInterface $dateCreation): self
    {
        $this->dateCreation = $dateCreation;
        return $this;
    }

    #[ORM\Column(type: 'float', nullable: true)]
    #[Assert\Range(
        min: -90,
        max: 90,
        notInRangeMessage: 'Latitude must be between {{ min }} and {{ max }}.'
    )]
    private ?float $latitude = null;

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function setLatitude(?float $latitude): self
    {
        $this->latitude = $latitude;
        return $this;
    }

    #[ORM\Column(type: 'float', nullable: true)]
    #[Assert\Range(
        min: -180,
        max: 180,
        notInRangeMessage: 'Longitude must be between {{ min }} and {{ max }}.'
    )]
    private ?float $longitude = null;

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setLongitude(?float $longitude): self
    {
        $this->longitude = $longitude;
        return $this;
    }

    #[ORM\OneToMany(targetEntity: Culture::class, mappedBy: 'parcelle')]
    private Collection $cultures;

    public function __construct()
    {
        $this->cultures = new ArrayCollection();
    }

    public function getCultures(): Collection
    {
        return $this->cultures;
    }

    public function addCulture(Culture $culture): self
    {
        if (!$this->cultures->contains($culture)) {
            $this->cultures[] = $culture;
        }
        return $this;
    }

    public function removeCulture(Culture $culture): self
    {
        $this->cultures->removeElement($culture);
        return $this;
    }
}
