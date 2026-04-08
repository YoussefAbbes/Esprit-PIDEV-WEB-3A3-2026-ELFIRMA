<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\MeetingRepository;

#[ORM\Entity(repositoryClass: MeetingRepository::class)]
#[ORM\Table(name: 'meetings')]
class Meeting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    #[ORM\ManyToOne(targetEntity: Fournisseur::class, inversedBy: 'meetings')]
    #[ORM\JoinColumn(name: 'supplier_id', referencedColumnName: 'id_f', nullable: true)]
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

    #[ORM\Column(type: 'string', length: 500)]
    private ?string $meeting_link = null;

    public function getMeetingLink(): ?string
    {
        return $this->meeting_link;
    }

    public function setMeetingLink(string $meeting_link): self
    {
        $this->meeting_link = $meeting_link;
        return $this;
    }

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $meeting_datetime = null;

    public function getMeetingDatetime(): ?\DateTimeInterface
    {
        return $this->meeting_datetime;
    }

    public function setMeetingDatetime(\DateTimeInterface $meeting_datetime): self
    {
        $this->meeting_datetime = $meeting_datetime;
        return $this;
    }

    #[ORM\Column(type: 'datetime', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $created_at = null;

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->created_at;
    }

    public function setCreatedAt(\DateTimeInterface $created_at): self
    {
        $this->created_at = $created_at;
        return $this;
    }
}