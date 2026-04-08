<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\ReclamationRepository;

#[ORM\Entity(repositoryClass: ReclamationRepository::class)]
#[ORM\Table(name: 'reclamation')]
class Reclamation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $idr_u = null;

    public function getIdrU(): ?int
    {
        return $this->idr_u;
    }

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $titre_u = null;

    public function getTitreU(): ?string
    {
        return $this->titre_u;
    }

    public function setTitreU(?string $titre_u): self
    {
        $this->titre_u = $titre_u;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $type_reclamation_u = null;

    public function getTypeReclamationU(): ?string
    {
        return $this->type_reclamation_u;
    }

    public function setTypeReclamationU(?string $type_reclamation_u): self
    {
        $this->type_reclamation_u = $type_reclamation_u;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description_u = null;

    public function getDescriptionU(): ?string
    {
        return $this->description_u;
    }

    public function setDescriptionU(?string $description_u): self
    {
        $this->description_u = $description_u;
        return $this;
    }

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $date_reclamation_u = null;

    public function getDateReclamationU(): ?\DateTimeInterface
    {
        return $this->date_reclamation_u;
    }

    public function setDateReclamationU(?\DateTimeInterface $date_reclamation_u): self
    {
        $this->date_reclamation_u = $date_reclamation_u;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $statut_u = null;

    public function getStatutU(): ?string
    {
        return $this->statut_u;
    }

    public function setStatutU(?string $statut_u): self
    {
        $this->statut_u = $statut_u;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: Utilisateur::class, inversedBy: 'reclamations')]
    #[ORM\JoinColumn(name: 'utilisateur_id_u', referencedColumnName: 'id_u')]
    private ?Utilisateur $utilisateur = null;

    public function getUtilisateur(): ?Utilisateur
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(?Utilisateur $utilisateur): self
    {
        $this->utilisateur = $utilisateur;
        return $this;
    }
}