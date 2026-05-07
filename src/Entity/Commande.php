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

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\NotNull(message: 'La quantite est obligatoire.')]
    #[Assert\Positive(message: 'La quantite doit etre superieure a 0.')]
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

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Assert\NotBlank(message: 'Le prix total est obligatoire.')]
    #[Assert\Regex(pattern: '/^\d+(\.\d{1,2})?$/', message: 'Le prix total doit etre un decimal valide avec 2 chiffres maximum apres la virgule.')]
    #[Assert\Positive(message: 'Le prix total doit etre superieur a 0.')]
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
    #[Assert\NotBlank(message: 'Le statut de la commande est obligatoire.')]
    #[Assert\Choice(
        choices: ['En attente', 'Confirmée', 'En cours', 'Livrée', 'Annulée'],
        message: 'Le statut de commande est invalide.'
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
    #[Assert\NotBlank(message: 'Le mode de paiement est obligatoire.')]
    #[Assert\Choice(
        choices: ['Cash', 'Carte bancaire', 'Virement'],
        message: 'Le mode de paiement est invalide.'
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
    #[Assert\NotBlank(message: 'Le statut du paiement est obligatoire.')]
    #[Assert\Choice(
        choices: ['Non payé', 'Payé', 'Remboursé'],
        message: 'Le statut du paiement est invalide.'
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
    #[Assert\Length(
        max: 255,
        maxMessage: 'La facture ne doit pas depasser {{ limit }} caracteres.'
    )]
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
    #[Assert\NotNull(message: 'Le produit est obligatoire.')]
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
    #[Assert\NotBlank(message: 'Le nom du client est obligatoire.')]
    #[Assert\Length(
        min: 2,
        max: 100,
        minMessage: 'Le nom du client doit contenir au moins {{ limit }} caracteres.',
        maxMessage: 'Le nom du client ne doit pas depasser {{ limit }} caracteres.'
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

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Assert\NotBlank(message: 'L\'adresse de livraison est obligatoire.')]
    #[Assert\Length(
        min: 5,
        max: 255,
        minMessage: 'L\'adresse de livraison doit contenir au moins {{ limit }} caracteres.',
        maxMessage: 'L\'adresse de livraison ne doit pas depasser {{ limit }} caracteres.'
    )]
    private ?string $adresse_livraison = null;

    public function getAdresseLivraison(): ?string
    {
        return $this->adresse_livraison;
    }

    public function setAdresseLivraison(?string $adresse_livraison): self
    {
        $this->adresse_livraison = $adresse_livraison;
        return $this;
    }

    #[ORM\Column(type: 'date', nullable: true)]
    #[Assert\NotNull(message: 'La date de commande est obligatoire.')]
    #[Assert\Type(type: \DateTimeInterface::class, message: 'La date de commande est invalide.')]
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