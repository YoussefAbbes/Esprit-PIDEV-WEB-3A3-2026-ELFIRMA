<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\VaccinationRepository;
use App\Enum\VaccinationStatus;


#[ORM\Entity(repositoryClass: VaccinationRepository::class)]
#[ORM\Table(name: 'vaccination')]
class Vaccination
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_vaccination = null;

    public function getIdVaccination(): ?int
    {
        return $this->id_vaccination;
    }

    #[ORM\ManyToOne(targetEntity: Animal::class)]
    #[ORM\JoinColumn(name: 'id_animal', referencedColumnName: 'id_animal', nullable: true)]
    private ?Animal $animal = null;

    public function getAnimal(): ?Animal
    {
        return $this->animal;
    }

    public function setAnimal(?Animal $animal): self
    {
        $this->animal = $animal;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 100)]
    private ?string $vaccine_name = null;

    public function getVaccineName(): ?string
    {
        return $this->vaccine_name;
    }

    public function setVaccineName(string $vaccine_name): self
    {
        $this->vaccine_name = $vaccine_name;
        return $this;
    }

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $date_done = null;

    public function getDateDone(): ?\DateTimeInterface
    {
        return $this->date_done;
    }

    public function setDateDone(?\DateTimeInterface $date_done): self
    {
        $this->date_done = $date_done;
        return $this;
    }

    #[ORM\Column(type: 'date')]
    private ?\DateTimeInterface $date_next = null;

    public function getDateNext(): ?\DateTimeInterface
    {
        return $this->date_next;
    }

    public function setDateNext(\DateTimeInterface $date_next): self
    {
        $this->date_next = $date_next;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $notes = null;

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }

    #[ORM\Column(enumType: VaccinationStatus::class, length: 20, nullable: true)]
    private ?VaccinationStatus $status = null;

    public function getStatus(): ?VaccinationStatus
    {
        return $this->status;
    }   

    public function setStatus(?VaccinationStatus $status): self
    {
        $this->status = $status;
        return $this;
    }
}