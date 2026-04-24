<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\VaccinationRepository;
use App\Enum\VaccinationStatus;
use App\Bundle\VaccinationCalendarBundle;
use Symfony\Component\Validator\Constraints as Assert;


#[ORM\Entity(repositoryClass: VaccinationRepository::class)]
#[ORM\Table(name: 'vaccination')]
class Vaccination
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_vaccination = null;

    private ?VaccinationCalendarBundle $calendrier_vaccination = null;

    public function getIdVaccination(): ?int
    {
        return $this->id_vaccination;
    }

    #[ORM\ManyToOne(targetEntity: Animal::class, inversedBy: 'vaccinations')]
    #[ORM\JoinColumn(name: 'id_animal', referencedColumnName: 'id_animal', nullable: true)]
    #[Assert\NotNull(message: 'Please select a valid animal.')]
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
    #[Assert\NotBlank(message: 'Vaccine name is required.')]
    #[Assert\Regex(
        pattern: '/^[\p{L}\s]+$/u',
        message: 'Vaccination name can contain letters and spaces only'
    )]
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
    #[Assert\NotNull(message: 'Vaccination Date is required')]
    private ?\DateTimeInterface $date_done = null;

    public function getDateDone(): ?\DateTimeInterface
    {
        return $this->date_done;
    }

    public function setDateDone(?\DateTimeInterface $date_done): self
    {
        $this->date_done = $date_done;
        $this->calendrier_vaccination = null;
        return $this;
    }

    #[ORM\Column(type: 'date')]
    #[Assert\NotNull(message: 'Next vaccination date is required.')]
    #[Assert\GreaterThanOrEqual(propertyPath: 'date_done', message: 'Next date must be after vaccination date.')]
    private ?\DateTimeInterface $date_next = null;

    public function getDateNext(): ?\DateTimeInterface
    {
        return $this->date_next;
    }

    public function setDateNext(\DateTimeInterface $date_next): self
    {
        $this->date_next = $date_next;
        $this->calendrier_vaccination = null;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Assert\NotBlank(message: 'Notes is required')]
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
    #[Assert\NotNull(message: 'Status is required.')]
    private ?VaccinationStatus $status = null;

    public function getStatus(): ?VaccinationStatus
    {
        return $this->status;
    }   

    public function setStatus(?VaccinationStatus $status): self
    {
        $this->status = $status;
        $this->calendrier_vaccination = null;
        return $this;
    }

    public function getCalendrierVaccination(): VaccinationCalendarBundle
    {
        if ($this->calendrier_vaccination === null) {
            $this->calendrier_vaccination = VaccinationCalendarBundle::fromVaccinationFields(
                $this->date_done,
                $this->date_next,
                $this->status
            );
        }

        return $this->calendrier_vaccination;
    }

    public function setCalendrierVaccination(VaccinationCalendarBundle $calendrier_vaccination): self
    {
        $this->calendrier_vaccination = $calendrier_vaccination;
        $this->date_done = $calendrier_vaccination->getDateDone();

        if ($calendrier_vaccination->getDateNext() !== null) {
            $this->date_next = $calendrier_vaccination->getDateNext();
        }

        $this->status = $calendrier_vaccination->getStatus();

        return $this;
    }
}