<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Repository\UtilisateurRepository;

#[ORM\Entity(repositoryClass: UtilisateurRepository::class)]
#[ORM\Table(name: 'utilisateur')]
class Utilisateur
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_u = null;

    public function getIdU(): ?int
    {
        return $this->id_u;
    }

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $nom_u = null;

    public function getNomU(): ?string
    {
        return $this->nom_u;
    }

    public function setNomU(?string $nom_u): self
    {
        $this->nom_u = $nom_u;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $prenom_u = null;

    public function getPrenomU(): ?string
    {
        return $this->prenom_u;
    }

    public function setPrenomU(?string $prenom_u): self
    {
        $this->prenom_u = $prenom_u;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $email_u = null;

    public function getEmailU(): ?string
    {
        return $this->email_u;
    }

    public function setEmailU(?string $email_u): self
    {
        $this->email_u = $email_u;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $mot_de_passe_u = null;

    public function getMotDePasseU(): ?string
    {
        return $this->mot_de_passe_u;
    }

    public function setMotDePasseU(?string $mot_de_passe_u): self
    {
        $this->mot_de_passe_u = $mot_de_passe_u;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $role_u = null;

    public function getRoleU(): ?string
    {
        return $this->role_u;
    }

    public function setRoleU(?string $role_u): self
    {
        $this->role_u = $role_u;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 255, nullable: true, options: ['default' => 'default.png'])]
    private ?string $image_u = 'default.png';

    public function getImageU(): ?string
    {
        return $this->image_u;
    }

    public function setImageU(?string $image_u): self
    {
        $this->image_u = $image_u;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 255, nullable: true, options: ['default' => 'default_face.png'])]
    private ?string $photo_face = 'default_face.png';

    public function getPhotoFace(): ?string
    {
        return $this->photo_face;
    }

    public function setPhotoFace(?string $photo_face): self
    {
        $this->photo_face = $photo_face;
        return $this;
    }

    #[ORM\Column(type: 'datetime', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private ?\DateTimeInterface $date_creation_u = null;

    public function getDateCreationU(): ?\DateTimeInterface
    {
        return $this->date_creation_u;
    }

    public function setDateCreationU(\DateTimeInterface $date_creation_u): self
    {
        $this->date_creation_u = $date_creation_u;
        return $this;
    }

    #[ORM\OneToMany(targetEntity: Reclamation::class, mappedBy: 'utilisateur')]
    private Collection $reclamations;

    public function __construct()
    {
        $this->reclamations = new ArrayCollection();
    }

    public function getReclamations(): Collection
    {
        return $this->reclamations;
    }

    public function addReclamation(Reclamation $reclamation): self
    {
        if (!$this->reclamations->contains($reclamation)) {
            $this->reclamations[] = $reclamation;
        }
        return $this;
    }

    public function removeReclamation(Reclamation $reclamation): self
    {
        $this->reclamations->removeElement($reclamation);
        return $this;
    }
}