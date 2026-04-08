<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Repository\ProduitRepository;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: ProduitRepository::class)]
#[ORM\Table(name: 'produit')]
#[UniqueEntity(
    fields: ['nom'],
    message: 'Un produit avec ce nom existe déjà dans le système'
)]
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
    #[Assert\NotBlank(
        message: 'Le nom du produit est obligatoire'
    )]
    #[Assert\Length(
        min: 3,
        max: 100,
        minMessage: 'Le nom doit contenir au moins {{ limit }} caractères',
        maxMessage: 'Le nom ne peut pas dépasser {{ limit }} caractères'
    )]
    #[Assert\Regex(
        pattern: '/^[a-zA-ZÀ-ÿ0-9\s\-\_\.\']+$/u',
        message: 'Le nom ne peut contenir que des lettres, chiffres, espaces, tirets, underscores, points et apostrophes'
    )]
    #[Assert\Type(
        type: 'string',
        message: 'Le nom doit être une chaîne de caractères'
    )]
    private ?string $nom = null;

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = trim($nom);
        return $this;
    }

    #[ORM\Column(type: 'string', length: 30)]
    #[Assert\NotBlank(
        message: 'Le type du produit est obligatoire'
    )]
    #[Assert\Length(
        min: 3,
        max: 30,
        minMessage: 'Le type doit contenir au moins {{ limit }} caractères',
        maxMessage: 'Le type ne peut pas dépasser {{ limit }} caractères'
    )]
    #[Assert\Regex(
        pattern: '/^[a-zA-ZÀ-ÿ\s\-\']+$/u',
        message: 'Le type ne peut contenir que des lettres, espaces, tirets et apostrophes'
    )]
    #[Assert\Type(
        type: 'string',
        message: 'Le type doit être une chaîne de caractères'
    )]
    private ?string $type = null;

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = trim($type);
        return $this;
    }

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\NotBlank(
        message: 'Le prix unitaire est obligatoire'
    )]
    #[Assert\Positive(
        message: 'Le prix unitaire doit être un nombre strictement positif (supérieur à 0)'
    )]
    #[Assert\Range(
        min: 0.01,
        max: 99999999.99,
        notInRangeMessage: 'Le prix doit être compris entre {{ min }} DT et {{ max }} DT'
    )]
    #[Assert\Regex(
        pattern: '/^\d+(\.\d{1,2})?$/',
        message: 'Le prix doit être un nombre avec au maximum 2 décimales (ex: 25.50)'
    )]
    #[Assert\Type(
        type: 'numeric',
        message: 'Le prix doit être une valeur numérique'
    )]
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
    #[Assert\NotBlank(
        message: 'La quantité en stock est obligatoire'
    )]
    #[Assert\PositiveOrZero(
        message: 'La quantité en stock doit être supérieure ou égale à 0'
    )]
    #[Assert\Range(
        min: 0,
        max: 1000000,
        notInRangeMessage: 'La quantité doit être comprise entre {{ min }} et {{ max }} unités'
    )]
    #[Assert\Type(
        type: 'integer',
        message: 'La quantité doit être un nombre entier (sans décimales)'
    )]
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
    #[Assert\Length(
        max: 20,
        maxMessage: 'La qualité ne peut pas dépasser {{ limit }} caractères'
    )]
    #[Assert\Choice(
        choices: ['Premium', 'Standard', 'Économique', 'Bio', 'Certifié'],
        message: 'La qualité doit être l\'une des valeurs suivantes : Premium, Standard, Économique, Bio ou Certifié'
    )]
    #[Assert\Type(
        type: 'string',
        message: 'La qualité doit être une chaîne de caractères'
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
    #[Assert\Type(
        type: '\DateTimeInterface',
        message: 'La date de production doit être une date valide'
    )]
    #[Assert\NotBlank(
        message: 'La date de production est obligatoire'
    )]
    #[Assert\LessThanOrEqual(
        value: 'today',
        message: 'La date de production ne peut pas être dans le futur'
    )]
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
    #[Assert\Type(
        type: '\DateTimeInterface',
        message: 'La date d\'expiration doit être une date valide'
    )]
    #[Assert\NotBlank(
        message: 'La date d\'expiration est obligatoire'
    )]
    #[Assert\GreaterThan(
        propertyPath: 'date_production',
        message: 'La date d\'expiration doit être postérieure à la date de production'
    )]
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
    #[Assert\Length(
        max: 255,
        maxMessage: 'Le chemin de l\'image ne peut pas dépasser {{ limit }} caractères'
    )]
    #[Assert\NotBlank(
        message: 'L\'image du produit est obligatoire'
    )]
    #[Assert\Regex(
        pattern: '/^[\w\-\.\/]+\.(jpg|jpeg|png|gif|webp)$/i',
        message: 'L\'image doit être au format JPG, PNG, GIF ou WebP'
    )]
    private ?string $image = null;

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): self
    {
        $this->image = $image;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 20, nullable: true, options: ['default' => 'Disponible'])]
    #[Assert\NotBlank(
        message: 'Le statut du produit est obligatoire'
    )]
    #[Assert\Choice(
        choices: ['Disponible', 'Rupture', 'Expiré'],
        message: 'Le statut doit être l\'une des valeurs suivantes : Disponible, Rupture ou Expiré'
    )]
    #[Assert\Type(
        type: 'string',
        message: 'Le statut doit être une chaîne de caractères'
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
    #[ORM\JoinColumn(name: 'categorie_id', referencedColumnName: 'id', nullable: false)]
    #[Assert\NotNull(message: 'La catégorie est obligatoire')]
    #[Assert\Valid]
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

    /**
     * Validation personnalisée complète pour l'ajout et la modification de produit
     */
    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        // ========== VALIDATION 1: Minimum 3 lettres alphabétiques dans le nom ==========
        if ($this->nom !== null) {
            preg_match_all('/[a-zA-ZÀ-ÿ]/u', $this->nom, $matchesNom);
            $alphabetCountNom = count($matchesNom[0]);

            if ($alphabetCountNom < 3) {
                $context->buildViolation('Le nom du produit doit contenir au moins 3 lettres (actuellement {{ count }} lettre(s))')
                    ->setParameter('{{ count }}', (string) $alphabetCountNom)
                    ->atPath('nom')
                    ->addViolation();
            }
        }

        // ========== VALIDATION 2: Minimum 3 lettres alphabétiques dans le type ==========
        if ($this->type !== null) {
            preg_match_all('/[a-zA-ZÀ-ÿ]/u', $this->type, $matchesType);
            $alphabetCountType = count($matchesType[0]);

            if ($alphabetCountType < 3) {
                $context->buildViolation('Le type du produit doit contenir au moins 3 lettres (actuellement {{ count }} lettre(s))')
                    ->setParameter('{{ count }}', (string) $alphabetCountType)
                    ->atPath('type')
                    ->addViolation();
            }
        }

        // ========== VALIDATION 3: Cohérence entre statut "Rupture" et quantité en stock ==========
        if ($this->statut === 'Rupture' && $this->quantite_stock !== null && $this->quantite_stock > 0) {
            $context->buildViolation('Le statut "Rupture" ne peut être utilisé que si la quantité en stock est égale à 0 (actuellement {{ quantity }} unité(s))')
                ->setParameter('{{ quantity }}', $this->quantite_stock)
                ->atPath('statut')
                ->addViolation();
        }

        // ========== VALIDATION 4: Cohérence entre statut "Disponible" et quantité en stock ==========
        if ($this->statut === 'Disponible' && $this->quantite_stock !== null && $this->quantite_stock === 0) {
            $context->buildViolation('Le statut ne peut être "Disponible" si la quantité en stock est égale à 0. Veuillez choisir le statut "Rupture"')
                ->atPath('statut')
                ->addViolation();
        }

        // ========== VALIDATION 5: Cohérence entre statut "Expiré" et date d'expiration ==========
        if ($this->statut === 'Expiré') {
            if ($this->date_expiration === null) {
                $context->buildViolation('Le statut "Expiré" nécessite une date d\'expiration définie')
                    ->atPath('statut')
                    ->addViolation();
            } else {
                $now = new \DateTime();
                if ($this->date_expiration > $now) {
                    $context->buildViolation('Le statut "Expiré" ne peut être utilisé que si la date d\'expiration est déjà dépassée (date d\'expiration : {{ date }})')
                        ->setParameter('{{ date }}', $this->date_expiration->format('d/m/Y'))
                        ->atPath('statut')
                        ->addViolation();
                }
            }
        }

        // ========== VALIDATION 6: Vérification prix > 0 ==========
        if ($this->prix_unitaire !== null && (float)$this->prix_unitaire <= 0) {
            $context->buildViolation('Le prix unitaire doit être strictement supérieur à 0 (valeur actuelle : {{ price }} DT)')
                ->setParameter('{{ price }}', $this->prix_unitaire)
                ->atPath('prix_unitaire')
                ->addViolation();
        }

        // ========== VALIDATION 7: Cohérence des dates (production < expiration) ==========
        if ($this->date_production !== null && $this->date_expiration !== null) {
            if ($this->date_expiration <= $this->date_production) {
                $context->buildViolation('La date d\'expiration ({{ exp_date }}) doit être postérieure à la date de production ({{ prod_date }})')
                    ->setParameter('{{ exp_date }}', $this->date_expiration->format('d/m/Y'))
                    ->setParameter('{{ prod_date }}', $this->date_production->format('d/m/Y'))
                    ->atPath('date_expiration')
                    ->addViolation();
            }
        }

        // ========== VALIDATION 8: Vérification format image si présente ==========
        if ($this->image !== null && !empty(trim($this->image))) {
            $validExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $extension = strtolower(pathinfo($this->image, PATHINFO_EXTENSION));
            
            if (!in_array($extension, $validExtensions)) {
                $context->buildViolation('L\'image doit avoir l\'une des extensions suivantes : {{ extensions }}')
                    ->setParameter('{{ extensions }}', implode(', ', $validExtensions))
                    ->atPath('image')
                    ->addViolation();
            }
        }
    }
}