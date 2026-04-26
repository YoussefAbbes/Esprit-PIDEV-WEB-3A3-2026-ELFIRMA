<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Repository\EquipementRepository;
use App\Enum\EquipementEtat;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EquipementRepository::class)]
#[ORM\Table(name: 'equipement')]
class Equipement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_eq = null;

    public function getIdEq(): ?int
    {
        return $this->id_eq;
    }

    // ✅ NOM
    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank(message: "Le nom est obligatoire")]
    #[Assert\Length(min: 3, max: 100)]
    private ?string $nom_eq = null;

    public function getNomEq(): ?string { return $this->nom_eq; }
    public function setNomEq(string $nom_eq): self { $this->nom_eq = $nom_eq; return $this; }

    // ✅ TYPE
    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank(message: "Le type est obligatoire")]
    #[Assert\Length(min: 3, max: 100)]
    private ?string $type_eq = null;

    public function getTypeEq(): ?string { return $this->type_eq; }
    public function setTypeEq(string $type_eq): self { $this->type_eq = $type_eq; return $this; }

    #[ORM\Column(type: 'date')]
    #[Assert\NotNull(message: "La date est obligatoire")]
    #[Assert\LessThanOrEqual("today", message: "Date invalide")]
    private \DateTimeInterface $date_achat;

    public function getDateAchat(): ?\DateTimeInterface {
        return $this->date_achat;
    }

    public function setDateAchat(?\DateTimeInterface $date_achat): self
    {
        $this->date_achat = $date_achat;
        return $this;
    }

    #[ORM\Column(enumType: EquipementEtat::class)]
    #[Assert\NotNull(message: "L'état est obligatoire")]
    private EquipementEtat $etat;

    public function getEtat(): ?EquipementEtat {
        return $this->etat;
    }

    public function setEtat(EquipementEtat $etat): self {
        $this->etat = $etat;
        return $this;
    }

    // ✅ COUT
    #[ORM\Column(type: 'float')]
    #[Assert\NotNull(message: "Le coût est obligatoire")]
    #[Assert\Positive(message: "Le coût doit être positif")]
    private ?float $cout_achat = null;

    public function getCoutAchat(): ?float { return $this->cout_achat; }
    public function setCoutAchat(float $cout_achat): self { $this->cout_achat = $cout_achat; return $this; }

    // ✅ DESCRIPTION
    #[ORM\Column(type: 'string', length: 200)]
    #[Assert\NotBlank(message: "La description est obligatoire")]
    #[Assert\Length(min: 5, max: 200)]
    private ?string $description_eq = null;

    public function getDescriptionEq(): ?string { return $this->description_eq; }
    public function setDescriptionEq(string $description_eq): self { $this->description_eq = $description_eq; return $this; }

    // IMAGE (pas obligatoire)
    #[ORM\Column(type: 'string', length: 255, options: ['default' => 'default.png'])]
    private ?string $image_eq = 'default.png';

    public function getImageEq(): ?string { return $this->image_eq; }
    public function setImageEq(string $image_eq): self { $this->image_eq = $image_eq; return $this; }

    #[ORM\OneToMany(targetEntity: Maintenance::class, mappedBy: 'equipement')]
    private Collection $maintenances;

    public function __construct()
    {
        $this->maintenances = new ArrayCollection();
    }

    public function getMaintenances(): Collection
    {
        return $this->maintenances;
    }

    public function addMaintenance(Maintenance $maintenance): self
    {
        if (!$this->maintenances->contains($maintenance)) {
            $this->maintenances[] = $maintenance;
        }
        return $this;
    }

    public function removeMaintenance(Maintenance $maintenance): self
    {
        $this->maintenances->removeElement($maintenance);
        return $this;
    }
}