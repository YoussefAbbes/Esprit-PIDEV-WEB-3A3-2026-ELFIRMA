<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Repository\ProduitRepository;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[Vich\Uploadable]

#[ORM\Entity(repositoryClass: ProduitRepository::class)]
#[ORM\Table(name: 'produit')]
#[Assert\Expression(
    'this.getDateProduction() === null || this.getDateExpiration() === null || this.getDateExpiration() >= this.getDateProduction()',
    message: 'Expiration date must be on or after production date.'
)]
#[Assert\Callback('validateStatusDateConsistency')]
class Produit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_produit = null;

    public function getIdProduit(): ?int
    {
        return $this->id_produit;
    }

    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank(message: 'Product name is required.')]
    #[Assert\Length(max: 100, maxMessage: 'Product name cannot exceed {{ limit }} characters.')]
    private ?string $nom = null;

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 30)]
    #[Assert\NotBlank(message: 'Type is required.')]
    #[Assert\Choice(
        choices: ['Frais', 'Transformé', 'Biologique', 'Séché', 'Conditionné'],
        message: 'Type must be one of: Frais, Transformé, Biologique, Séché, Conditionné.'
    )]
    private ?string $type = null;

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\NotBlank(message: 'Price is required.')]
    #[Assert\Regex(pattern: '/^\d+(\.\d{1,2})?$/', message: 'Price must be a valid decimal with up to 2 decimals.')]
    #[Assert\Positive(message: 'Price must be greater than 0.')]
    private ?string $prix_unitaire = null;

    public function getPrixUnitaire(): ?string
    {
        return $this->prix_unitaire;
    }

    public function setPrixUnitaire(string $prix_unitaire): self
    {
        $this->prix_unitaire = $prix_unitaire;
        return $this;
    }

    #[ORM\Column(type: 'integer')]
    #[Assert\NotNull(message: 'Stock quantity is required.')]
    #[Assert\PositiveOrZero(message: 'Stock quantity must be greater than or equal to 0.')]
    private ?int $quantite_stock = null;

    public function getQuantiteStock(): ?int
    {
        return $this->quantite_stock;
    }

    public function setQuantiteStock(?int $quantite_stock): self
    {
        $this->quantite_stock = $quantite_stock;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    #[Assert\NotBlank(message: 'Quality is required.')]
    #[Assert\Choice(
        choices: ['Premium', 'Standard', 'Économique', 'Bio', 'Certifié'],
        message: 'Quality must be one of: Premium, Standard, Économique, Bio, Certifié.'
    )]
    private ?string $qualite = null;

    public function getQualite(): ?string
    {
        return $this->qualite;
    }

    public function setQualite(?string $qualite): self
    {
        $this->qualite = $qualite;
        return $this;
    }

    #[ORM\Column(type: 'date', nullable: true)]
    #[Assert\NotNull(message: 'Production date is required.')]
    #[Assert\Type(type: \DateTimeInterface::class, message: 'Production date is invalid.')]
    #[Assert\LessThanOrEqual('today', message: 'Production date cannot be in the future.')]
    private ?\DateTimeInterface $date_production = null;

    public function getDateProduction(): ?\DateTimeInterface
    {
        return $this->date_production;
    }

    public function setDateProduction(?\DateTimeInterface $date_production): self
    {
        $this->date_production = $date_production;
        return $this;
    }

    #[ORM\Column(type: 'date', nullable: true)]
    #[Assert\NotNull(message: 'Expiration date is required.')]
    #[Assert\Type(type: \DateTimeInterface::class, message: 'Expiration date is invalid.')]
    #[Assert\GreaterThanOrEqual(propertyPath: 'date_production', message: 'Expiration date must be on or after production date.')]
    private ?\DateTimeInterface $date_expiration = null;

    public function getDateExpiration(): ?\DateTimeInterface
    {
        return $this->date_expiration;
    }

    public function setDateExpiration(?\DateTimeInterface $date_expiration): self
    {
        $this->date_expiration = $date_expiration;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Assert\Length(max: 255, maxMessage: 'Image name cannot exceed {{ limit }} characters.')]
    private ?string $image = null;

    #[Vich\UploadableField(mapping: 'produit_images', fileNameProperty: 'image')]
    #[Assert\File(maxSize: '5M', mimeTypes: ['image/png','image/jpeg','image/jpg','image/webp'], mimeTypesMessage: 'Please upload a valid image (png, jpg, jpeg, webp).')]
    private ?File $imageFile = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): self
    {
        $this->image = $image;
        return $this;
    }

    public function setImageFile(?File $file = null): self
    {
        $this->imageFile = $file;
        if (null !== $file) {
            $this->updatedAt = new \DateTimeImmutable();
        }
        return $this;
    }

    public function getImageFile(): ?File
    {
        return $this->imageFile;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\Column(type: 'string', length: 20, nullable: true, options: ['default' => 'Disponible'])]
    #[Assert\NotBlank(message: 'Status is required.')]
    #[Assert\Choice(
        choices: ['Disponible', 'Rupture', 'Expiré'],
        message: 'Status must be one of: Disponible, Rupture, Expiré.'
    )]
    private ?string $statut = 'Disponible';

    public function getStatut(): ?string
    {
        return $this->statut;
    }

    public function setStatut(?string $statut): self
    {
        $this->statut = $statut;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: Categorie::class, inversedBy: 'produits')]
    #[ORM\JoinColumn(name: 'categorie_id', referencedColumnName: 'id')]
    #[Assert\NotNull(message: 'Category is required.')]
    private ?Categorie $categorie = null;

    public function getCategorie(): ?Categorie
    {
        return $this->categorie;
    }

    public function setCategorie(?Categorie $categorie): self
    {
        $this->categorie = $categorie;
        return $this;
    }

    #[ORM\OneToMany(targetEntity: Commande::class, mappedBy: 'produit')]
    private Collection $commandes;

    public function __construct()
    {
        $this->commandes = new ArrayCollection();
    }

    public function getCommandes(): Collection
    {
        return $this->commandes;
    }

    public function addCommande(Commande $commande): self
    {
        if (!$this->commandes->contains($commande)) {
            $this->commandes[] = $commande;
        }
        return $this;
    }

    public function removeCommande(Commande $commande): self
    {
        $this->commandes->removeElement($commande);
        return $this;
    }

    public function validateStatusDateConsistency(ExecutionContextInterface $context): void
    {
        $status = $this->getStatut();
        $expiration = $this->getDateExpiration();

        if ($status === null || $expiration === null) {
            return;
        }

        $today = new \DateTimeImmutable('today');
        $expirationDay = \DateTimeImmutable::createFromInterface($expiration)->setTime(0, 0);

        if ($status === 'Disponible' && $expirationDay < $today) {
            $context
                ->buildViolation('Available product must have an expiration date that is today or later.')
                ->atPath('date_expiration')
                ->addViolation();
        }

        if ($status === 'Expiré' && $expirationDay > $today) {
            $context
                ->buildViolation('Expired product must have an expiration date in the past or today.')
                ->atPath('date_expiration')
                ->addViolation();
        }
    }
}