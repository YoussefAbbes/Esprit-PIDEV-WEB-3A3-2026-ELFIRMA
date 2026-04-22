<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Repository\FournisseurRepository;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: FournisseurRepository::class)]
#[ORM\Table(name: 'fournisseurs')]
class Fournisseur
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_f = null;

    public function getIdF(): ?int
    {
        return $this->id_f;
    }

    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank(message: 'Le type du fournisseur est obligatoire.')]
    #[Assert\Length(
        min: 2,
        max: 100,
        minMessage: 'Le type doit contenir au moins {{ limit }} caracteres.',
        maxMessage: 'Le type ne doit pas depasser {{ limit }} caracteres.'
    )]
    private ?string $type_f = null;

    public function getTypeF(): ?string
    {
        return $this->type_f;
    }

    public function setTypeF(string $type_f): self
    {
        $this->type_f = $type_f;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(
        max: 1000,
        maxMessage: 'La description ne doit pas depasser {{ limit }} caracteres.'
    )]
    private ?string $description_f = null;

    public function getDescriptionF(): ?string
    {
        return $this->description_f;
    }

    public function setDescriptionF(?string $description_f): self
    {
        $this->description_f = $description_f;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Assert\NotBlank(message: 'Address is required')]
    #[Assert\Regex(
        pattern: '/[a-zA-Z]/',
        message: 'Address must contain at least one letter (e.g., 123 marsa or marsa)'
    )]
    private ?string $adresse_f = null;

    public function getAdresseF(): ?string
    {
        return $this->adresse_f;
    }

    public function setAdresseF(?string $adresse_f): self
    {
        $this->adresse_f = $adresse_f;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    #[Assert\NotBlank(message: 'Telephone is required')]
    #[Assert\Regex(
        pattern: '/^\d{8}$/',
        message: 'Must be exactly 8 digits (only numbers)'
    )]
    private ?string $tel_f = null;

    public function getTelF(): ?string
    {
        return $this->tel_f;
    }

    public function setTelF(?string $tel_f): self
    {
        $this->tel_f = $tel_f;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    #[Assert\NotBlank(message: 'Email is required')]
    #[Assert\Regex(
        pattern: '/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/',
        message: 'Email must be in this format: name@domain.com'
    )]
    private ?string $email_f = null;

    public function getEmailF(): ?string
    {
        return $this->email_f;
    }

    public function setEmailF(?string $email_f): self
    {
        $this->email_f = $email_f;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 50, options: ['default' => 'Actif'])]
    #[Assert\NotBlank(message: 'Le statut est obligatoire.')]
    #[Assert\Length(
        max: 50,
        maxMessage: 'Le statut ne doit pas depasser {{ limit }} caracteres.'
    )]
    private ?string $statut_f = 'Actif';

    public function getStatutF(): ?string
    {
        return $this->statut_f;
    }

    public function setStatutF(string $statut_f): self
    {
        $this->statut_f = $statut_f;
        return $this;
    }

    #[ORM\OneToMany(mappedBy: 'fournisseur', targetEntity: Contrat::class)]
    private Collection $contrats;

    #[ORM\OneToMany(mappedBy: 'fournisseur', targetEntity: Meeting::class)]
    private Collection $meetings;

    public function __construct()
    {
        $this->contrats = new ArrayCollection();
        $this->meetings = new ArrayCollection();
    }

    public function getContrats(): Collection
    {
        return $this->contrats;
    }

    public function addContrat(Contrat $contrat): self
    {
        if (!$this->contrats->contains($contrat)) {
            $this->contrats[] = $contrat;
            $contrat->setFournisseur($this);
        }
        return $this;
    }

    public function removeContrat(Contrat $contrat): self
    {
        if ($this->contrats->removeElement($contrat)) {
            if ($contrat->getFournisseur() === $this) {
                $contrat->setFournisseur(null);
            }
        }
        return $this;
    }

    public function getMeetings(): Collection
    {
        return $this->meetings;
    }
}