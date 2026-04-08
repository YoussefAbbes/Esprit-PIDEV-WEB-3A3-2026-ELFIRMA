<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Repository\AnimalRepository;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AnimalRepository::class)]
#[ORM\Table(name: 'animal')]
class Animal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_animal = null;

    public function getIdAnimal(): ?int
    {
        return $this->id_animal;
    }

    #[ORM\ManyToOne(targetEntity: Livestock::class, inversedBy: 'animals')]
    #[ORM\JoinColumn(name: 'id_elevage', referencedColumnName: 'id_elevage', nullable: true)]
    private ?Livestock $elevage = null;

    public function getElevage(): ?Livestock
    {
        return $this->elevage;
    }

    public function setElevage(?Livestock $elevage): self
    {
        $this->elevage = $elevage;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 50)]
    #[Assert\NotBlank(message: 'Le type est obligatoire.')]
    #[Assert\Regex(
        pattern: '/^[\p{L}\s]+$/u',
        message: 'Le type accepte uniquement des lettres et des espaces.'
    )]
    private ?string $type_animal = null;

    public function getTypeAnimal(): ?string
    {
        return $this->type_animal;
    }

    public function setTypeAnimal(string $type_animal): self
    {
        $this->type_animal = $type_animal;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 20)]
    private ?string $sexe = null;

    public function getSexe(): ?string
    {
        return $this->sexe;
    }

    public function setSexe(string $sexe): self
    {
        $this->sexe = $sexe;
        return $this;
    }

    #[ORM\Column(type: 'integer')]
    private ?int $age = null;

    public function getAge(): ?int
    {
        return $this->age;
    }

    public function setAge(int $age): self
    {
        $this->age = $age;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 50)]
    private ?string $etat_sante = null;

    public function getEtatSante(): ?string
    {
        return $this->etat_sante;
    }

    public function setEtatSante(string $etat_sante): self
    {
        $this->etat_sante = $etat_sante;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 50)]
    private ?string $statut = null;

    public function getStatut(): ?string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): self
    {
        $this->statut = $statut;
        return $this;
    }

    #[ORM\OneToMany(targetEntity: Vaccination::class, mappedBy: 'animal')]
    private Collection $vaccinations;

    public function __construct()
    {
        $this->vaccinations = new ArrayCollection();
    }

    public function getVaccinations(): Collection
    {
        return $this->vaccinations;
    }

    public function addVaccination(Vaccination $vaccination): self
    {
        if (!$this->vaccinations->contains($vaccination)) {
            $this->vaccinations[] = $vaccination;
        }
        return $this;
    }

    public function removeVaccination(Vaccination $vaccination): self
    {
        $this->vaccinations->removeElement($vaccination);
        return $this;
    }
}