<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\CommandeRepository;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CommandeRepository::class)]
#[ORM\Table(name: 'commande')]
class Commande
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id_commande = null;

    public function getIdCommande(): ?int
    {
        return $this->id_commande;
    }

    #[ORM\Column(type: 'integer')]
    #[Assert\NotNull(message: 'Quantity is required.')]
    #[Assert\Positive(message: 'Quantity must be greater than 0.')]
    private ?int $quantite = null;

    public function getQuantite(): ?int
    {
        return $this->quantite;
    }

    public function setQuantite(int $quantite): self
    {
        $this->quantite = $quantite;
        return $this;
    }

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\NotBlank(message: 'Total price is required.')]
    #[Assert\Regex(pattern: '/^\d+(\.\d{1,2})?$/', message: 'Total price must be a valid decimal with up to 2 decimals.')]
    #[Assert\Positive(message: 'Total price must be greater than 0.')]
    private ?string $prix_total = null;

    public function getPrixTotal(): ?string
    {
        return $this->prix_total;
    }

    public function setPrixTotal(string $prix_total): self
    {
        $this->prix_total = $prix_total;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    #[Assert\NotBlank(message: 'Order status is required.')]
    #[Assert\Choice(
        choices: ['En attente', 'Confirmée', 'En cours', 'Livrée', 'Annulée'],
        message: 'Order status must be one of: En attente, Confirmée, En cours, Livrée, Annulée.'
    )]
    private ?string $statut_commande = null;

    public function getStatutCommande(): ?string
    {
        return $this->statut_commande;
    }

    public function setStatutCommande(?string $statut_commande): self
    {
        $this->statut_commande = $statut_commande;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    #[Assert\NotBlank(message: 'Payment mode is required.')]
    #[Assert\Choice(
        choices: ['Cash', 'Carte bancaire', 'Virement'],
        message: 'Payment mode must be one of: Cash, Carte bancaire, Virement.'
    )]
    private ?string $mode_paiement = null;

    public function getModePaiement(): ?string
    {
        return $this->mode_paiement;
    }

    public function setModePaiement(?string $mode_paiement): self
    {
        $this->mode_paiement = $mode_paiement;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    #[Assert\NotBlank(message: 'Payment status is required.')]
    #[Assert\Choice(
        choices: ['Non payé', 'Payé', 'Remboursé'],
        message: 'Payment status must be one of: Non payé, Payé, Remboursé.'
    )]
    private ?string $statut_paiement = null;

    public function getStatutPaiement(): ?string
    {
        return $this->statut_paiement;
    }

    public function setStatutPaiement(?string $statut_paiement): self
    {
        $this->statut_paiement = $statut_paiement;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $facture = null;

    public function getFacture(): ?string
    {
        return $this->facture;
    }

    public function setFacture(?string $facture): self
    {
        $this->facture = $facture;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: Produit::class, inversedBy: 'commandes')]
    #[ORM\JoinColumn(name: 'id_produit', referencedColumnName: 'id_produit', nullable: true)]
    #[Assert\NotNull(message: 'Product is required.')]
    private ?Produit $produit = null;

    public function getProduit(): ?Produit
    {
        return $this->produit;
    }

    public function setProduit(?Produit $produit): self
    {
        $this->produit = $produit;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    #[Assert\NotBlank(message: 'Full name is required.')]
    #[Assert\Length(
        min: 2,
        max: 100,
        minMessage: 'Full name must be at least {{ limit }} characters.',
        maxMessage: 'Full name cannot exceed {{ limit }} characters.'
    )]
    private ?string $nom_client = null;

    public function getNomClient(): ?string
    {
        return $this->nom_client;
    }

    public function setNomClient(?string $nom_client): self
    {
        $this->nom_client = $nom_client;
        return $this;
    }

    #[ORM\Column(type: 'date')]
    #[Assert\NotNull(message: 'Order date is required.')]
    #[Assert\Type(type: \DateTimeInterface::class, message: 'Order date is invalid.')]
    private ?\DateTimeInterface $date_commande = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'id_utilisateur', referencedColumnName: 'id_u', nullable: true, onDelete: 'SET NULL')]
    private ?Utilisateur $utilisateur = null;

    public function getDateCommande(): ?\DateTimeInterface
    {
        return $this->date_commande;
    }

    public function setDateCommande(\DateTimeInterface $date_commande): self
    {
        $this->date_commande = $date_commande;
        return $this;
    }

    public function getUtilisateur(): ?Utilisateur
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(?Utilisateur $utilisateur): self
    {
        $this->utilisateur = $utilisateur;
        return $this;
    }
}