<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Repository\CategorieRepository;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: CategorieRepository::class)]
#[ORM\Table(name: 'categorie')]
#[UniqueEntity(
    fields: ['nom'],
    message: 'Une catégorie avec ce nom existe déjà dans le système'
)]
class Categorie
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank(
        message: 'Le nom de la catégorie est obligatoire'
    )]
    #[Assert\Length(
        min: 3,
        max: 100,
        minMessage: 'Le nom doit contenir au moins {{ limit }} caractères',
        maxMessage: 'Le nom ne peut pas dépasser {{ limit }} caractères'
    )]
    #[Assert\Regex(
        pattern: '/^[a-zA-ZÀ-ÿ0-9\s\-\_\']+$/u',
        message: 'Le nom ne peut contenir que des lettres, chiffres, espaces, tirets, underscores et apostrophes'
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

    #[ORM\OneToMany(targetEntity: Produit::class, mappedBy: 'categorie')]
    private Collection $produits;

    public function __construct()
    {
        $this->produits = new ArrayCollection();
    }

    public function getProduits(): Collection
    {
        return $this->produits;
    }

    public function addProduit(Produit $produit): self
    {
        if (!$this->produits->contains($produit)) {
            $this->produits[] = $produit;
        }
        return $this;
    }

    public function removeProduit(Produit $produit): self
    {
        $this->produits->removeElement($produit);
        return $this;
    }

    /**
     * Validation personnalisée complète pour l'ajout et la modification de catégorie
     */
    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        // ========== VALIDATION 1: Minimum 3 lettres alphabétiques dans le nom ==========
        if ($this->nom !== null) {
            preg_match_all('/[a-zA-ZÀ-ÿ]/u', $this->nom, $matches);
            $alphabetCount = count($matches[0]);

            if ($alphabetCount < 3) {
                $context->buildViolation('Le nom de la catégorie doit contenir au moins 3 lettres (actuellement {{ count }} lettre(s))')
                    ->setParameter('{{ count }}', (string) $alphabetCount)
                    ->atPath('nom')
                    ->addViolation();
            }
        }

        // ========== VALIDATION 2: Vérifier que le nom ne contient pas de chiffres seuls ==========
        if ($this->nom !== null) {
            $trimmedNom = trim($this->nom);
            // Vérifier si le nom est uniquement composé de chiffres
            if (preg_match('/^\d+$/', $trimmedNom)) {
                $context->buildViolation('Le nom de la catégorie ne peut pas être uniquement composé de chiffres')
                    ->atPath('nom')
                    ->addViolation();
            }
        }

        // ========== VALIDATION 3: Vérifier que le nom ne commence pas par un espace ou caractère spécial ==========
        if ($this->nom !== null && strlen($this->nom) > 0) {
            $firstChar = substr($this->nom, 0, 1);
            if (!preg_match('/[a-zA-ZÀ-ÿ0-9]/', $firstChar)) {
                $context->buildViolation('Le nom de la catégorie doit commencer par une lettre ou un chiffre')
                    ->atPath('nom')
                    ->addViolation();
            }
        }

        // ========== VALIDATION 4: Vérifier que le nom n'est pas trop court après suppression des espaces ==========
        if ($this->nom !== null) {
            $nomWithoutSpaces = str_replace(' ', '', $this->nom);
            if (strlen($nomWithoutSpaces) < 3) {
                $context->buildViolation('Le nom de la catégorie doit contenir au moins 3 caractères significatifs (hors espaces)')
                    ->atPath('nom')
                    ->addViolation();
            }
        }
    }
}