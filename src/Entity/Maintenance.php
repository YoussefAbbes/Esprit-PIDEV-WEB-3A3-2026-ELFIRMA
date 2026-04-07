<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\MaintenanceRepository;
use App\Enum\MaintenancePriorite;

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

    #[ORM\Column(type: 'string', length: 50)]
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

    #[ORM\Column(type: 'date')]
    private ?\DateTimeInterface $date_m = null;

    public function getDateM(): ?\DateTimeInterface
    {
        return $this->date_m;
    }

    public function setDateM(\DateTimeInterface $date_m): self
    {
        $this->date_m = $date_m;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 200)]
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

    #[ORM\Column(type: 'float')]
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

    #[ORM\Column(type: 'string', length: 30)]
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

    #[ORM\Column(enumType: MaintenancePriorite::class, length: 20, nullable: true)]
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

    #[ORM\ManyToOne(targetEntity: Equipement::class, inversedBy: 'maintenances')]
    #[ORM\JoinColumn(name: 'id_equipement', referencedColumnName: 'id_eq')]
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

    #[ORM\Column(type: 'string', length: 100)]
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