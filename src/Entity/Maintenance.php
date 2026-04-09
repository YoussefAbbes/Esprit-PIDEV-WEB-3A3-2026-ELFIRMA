<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\MaintenanceRepository;
use App\Enum\MaintenancePriorite;
use App\Enum\MaintenanceStatut;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: MaintenanceRepository::class)]
#[ORM\Table(name: 'maintenance')]
class Maintenance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_m = null;
    public function getIdM(): ?int
    {
        return $this->id_m;
    }

    // ✅ TYPE
    #[ORM\Column(type: 'string', length: 50)]
    #[Assert\NotBlank(message: "Le type est obligatoire")]
    #[Assert\Length(min: 3, max: 50)]
    private ?string $type_m = null;

    public function getTypeM(): ?string
    {
        return $this->type_m;
    }

    public function setTypeM(string $type_m): self
    {
        $this->type_m = $type_m;
        return $this;
    }

    // ✅ DATE
    #[ORM\Column(type: 'date')]
    #[Assert\NotNull(message: "La date est obligatoire")]
    #[Assert\LessThanOrEqual("today", message: "Date invalide")]
    private ?\DateTimeInterface $date_m = null;
    public function getDateM(): ?\DateTimeInterface
    {
        return $this->date_m;
    }

    public function setDateM(?\DateTimeInterface $date_m): self
    {
        $this->date_m = $date_m;
        return $this;
    }

    // ✅ DESCRIPTION
    #[ORM\Column(type: 'string', length: 200)]
    #[Assert\NotBlank(message: "La description est obligatoire")]
    #[Assert\Length(min: 5, max: 200)]
    private ?string $description = null;
    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    // ✅ COUT
    #[ORM\Column(type: 'float')]
    #[Assert\NotNull(message: "Le coût est obligatoire")]
    #[Assert\Positive(message: "Le coût doit être positif")]
    private ?float $cout = null;
    public function getCout(): ?float
    {
        return $this->cout;
    }

    public function setCout(float $cout): self
    {
        $this->cout = $cout;
        return $this;
    }

    // ✅ STATUT
    #[ORM\Column(enumType: MaintenanceStatut::class, nullable: true)]
    #[Assert\NotNull(message: "Le statut est obligatoire")]
    private ?MaintenanceStatut $statut = null;
    public function getStatut(): ?MaintenanceStatut
    {
        return $this->statut;
    }

    public function setStatut(?MaintenanceStatut $statut): self
    {
        $this->statut = $statut;
        return $this;
    }

    // ✅ PRIORITE
    #[ORM\Column(enumType: MaintenancePriorite::class, nullable: true)]
    #[Assert\NotNull(message: "La priorité est obligatoire")]
    private ?MaintenancePriorite $priorite = null;
    public function getPriorite(): ?MaintenancePriorite
    {
        return $this->priorite;
    }

    public function setPriorite(?MaintenancePriorite $priorite): self
    {
        $this->priorite = $priorite;
        return $this;
    }

    // ✅ EQUIPEMENT
    #[ORM\ManyToOne(targetEntity: Equipement::class, inversedBy: 'maintenances')]
    #[ORM\JoinColumn(name: 'id_equipement', referencedColumnName: 'id_eq')]
    #[Assert\NotNull(message: "L'équipement est obligatoire")]
    private ?Equipement $equipement = null;
    public function getEquipement(): ?Equipement
    {
        return $this->equipement;
    }

    public function setEquipement(?Equipement $equipement): self
    {
        $this->equipement = $equipement;
        return $this;
    }

    // ✅ TECHNICIEN
    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank(message: "Le technicien est obligatoire")]
    #[Assert\Length(min: 3, max: 100)]
    private ?string $technicien = null;
    public function getTechnicien(): ?string
    {
        return $this->technicien;
    }

    public function setTechnicien(string $technicien): self
    {
        $this->technicien = $technicien;
        return $this;
    }
}