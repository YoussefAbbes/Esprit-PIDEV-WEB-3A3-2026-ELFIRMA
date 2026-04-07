<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\ContratRepository;

#[ORM\Entity(repositoryClass: ContratRepository::class)]
#[ORM\Table(name: 'contrat')]
class Contrat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_contrat = null;

    public function getIdContrat(): ?int
    {
        return $this->id_contrat;
    }

    #[ORM\Column(type: 'date')]
    private ?\DateTimeInterface $date_debut_f = null;

    public function getDateDebutF(): ?\DateTimeInterface
    {
        return $this->date_debut_f;
    }

    public function setDateDebutF(\DateTimeInterface $date_debut_f): self
    {
        $this->date_debut_f = $date_debut_f;
        return $this;
    }

    #[ORM\Column(type: 'date')]
    private ?\DateTimeInterface $date_fin_f = null;

    public function getDateFinF(): ?\DateTimeInterface
    {
        return $this->date_fin_f;
    }

    public function setDateFinF(\DateTimeInterface $date_fin_f): self
    {
        $this->date_fin_f = $date_fin_f;
        return $this;
    }

    #[ORM\Column(name: "type_c_f", type: "string", length: 255)]
    private ?string $typeC_f = null;

    public function getTypeCF(): ?string
    {
        return $this->typeC_f;
    }

    public function setTypeCF(string $typeC_f): self
    {
        $this->typeC_f = $typeC_f;
        return $this;
    }

#[ORM\Column(name: "statut_c_f", type: "string", length: 255)]
    private ?string $statutC_f = null;

    public function getStatutCF(): ?string
    {
        return $this->statutC_f;
    }

    public function setStatutCF(string $statutC_f): self
    {
        $this->statutC_f = $statutC_f;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: Fournisseur::class, inversedBy: 'contrats')]
    #[ORM\JoinColumn(name: 'id_f', referencedColumnName: 'id_f')]
    private ?Fournisseur $fournisseur = null;

    public function getFournisseur(): ?Fournisseur
    {
        return $this->fournisseur;
    }

    public function setFournisseur(?Fournisseur $fournisseur): self
    {
        $this->fournisseur = $fournisseur;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $pdf_file = null;

    public function getPdfFile(): ?string
    {
        return $this->pdf_file;
    }

    public function setPdfFile(?string $pdf_file): self
    {
        $this->pdf_file = $pdf_file;
        return $this;
    }
}