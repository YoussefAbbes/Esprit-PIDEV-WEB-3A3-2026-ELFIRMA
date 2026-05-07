<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Repository\ElevageRepository;

#[ORM\Entity(repositoryClass: ElevageRepository::class)]
#[ORM\Table(name: 'elevage')]
class Elevage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_elevage = null;

    public function getIdElevage(): ?int
    {
        return $this->id_elevage;
    }

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $type_elevage = null;

    public function getTypeElevage(): ?string
    {
        return $this->type_elevage;
    }

    public function setTypeElevage(string $type_elevage): self
    {
        $this->type_elevage = $type_elevage;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $etat_elevage = null;

    public function getEtatElevage(): ?string
    {
        return $this->etat_elevage;
    }

    public function setEtatElevage(string $etat_elevage): self
    {
        $this->etat_elevage = $etat_elevage;
        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $capacite = null;

    public function getCapacite(): ?int
    {
        return $this->capacite;
    }

    public function setCapacite(int $capacite): self
    {
        $this->capacite = $capacite;
        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $nombre_animaux = null;

    public function getNombreAnimaux(): ?int
    {
        return $this->nombre_animaux;
    }

    public function setNombreAnimaux(int $nombre_animaux): self
    {
        $this->nombre_animaux = $nombre_animaux;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 200, nullable: true)]
    private ?string $production = null;

    public function getProduction(): ?string
    {
        return $this->production;
    }

    public function setProduction(string $production): self
    {
        $this->production = $production;
        return $this;
    }

    #[ORM\Column(type: 'float', nullable: true)]
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

    #[ORM\OneToMany(targetEntity: Animal::class, mappedBy: 'elevage')]
    private Collection $animals;

    public function __construct()
    {
        $this->animals = new ArrayCollection();
    }

    public function getAnimals(): Collection
    {
        return $this->animals;
    }

    public function addAnimal(Animal $animal): self
    {
        if (!$this->animals->contains($animal)) {
            $this->animals[] = $animal;
        }
        return $this;
    }

    public function removeAnimal(Animal $animal): self
    {
        $this->animals->removeElement($animal);
        return $this;
    }
}