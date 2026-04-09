<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Repository\FournisseurRepository;

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