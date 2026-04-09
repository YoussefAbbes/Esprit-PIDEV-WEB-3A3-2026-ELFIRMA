<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Produit;
use App\Entity\Commande;

final class ProductController extends AbstractController
{
    #[Route('/produit/{id}', name: 'app_product_details', methods: ['GET'])]
    public function details(int $id, EntityManagerInterface $em): Response
    {
        $produit = $em->getRepository(Produit::class)->find($id);

        if (!$produit) {
            throw $this->createNotFoundException('Product not found');
        }

        return $this->render('product/details.html.twig', [
            'produit' => $produit
        ]);
    }

    #[Route('/api/produit/{id}', name: 'app_api_product_details', methods: ['GET'])]
    public function apiDetails(int $id, EntityManagerInterface $em): JsonResponse
    {
        $produit = $em->getRepository(Produit::class)->find($id);

        if (!$produit) {
            return new JsonResponse(['error' => 'Product not found'], 404);
        }

        $data = [
            'id' => $produit->getIdProduit(),
            'nom' => $produit->getNom(),
            'type' => $produit->getType(),
            'prix' => $produit->getPrixUnitaire(),
            'stock' => $produit->getQuantiteStock(),
            'qualite' => $produit->getQualite(),
            'statut' => $produit->getStatut(),
            'categorie' => $produit->getCategorie() ? $produit->getCategorie()->getNom() : null,
            'image' => $produit->getImage(),
            'dateProduction' => $produit->getDateProduction() ? $produit->getDateProduction()->format('d/m/Y') : null,
            'dateExpiration' => $produit->getDateExpiration() ? $produit->getDateExpiration()->format('d/m/Y') : null
        ];

        return new JsonResponse($data);
    }

    #[Route('/commande/create', name: 'app_order_create', methods: ['POST'])]
    public function createOrder(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        // Validation des données
        if (!isset($data['produit_id'], $data['quantite'], $data['nom_client'])) {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        $produit = $em->getRepository(Produit::class)->find($data['produit_id']);
        
        if (!$produit) {
            return new JsonResponse(['error' => 'Product not found'], 404);
        }

        if ($produit->getStatut() !== 'Disponible') {
            return new JsonResponse(['error' => 'Product not available'], 400);
        }

        if ($data['quantite'] > $produit->getQuantiteStock()) {
            return new JsonResponse(['error' => 'Not enough stock'], 400);
        }

        // Créer la commande
        $commande = new Commande();
        $commande->setProduit($produit);
        $commande->setQuantite($data['quantite']);
        $commande->setPrixTotal($data['quantite'] * $produit->getPrixUnitaire());
        $commande->setNomClient($data['nom_client']);
        $commande->setStatutCommande('En attente');
        $commande->setStatutPaiement('Non payé');
        $commande->setModePaiement($data['mode_paiement'] ?? 'Cash');
        $commande->setDateCommande(new \DateTime());

        // Mettre à jour le stock
        $nouveauStock = $produit->getQuantiteStock() - $data['quantite'];
        $produit->setQuantiteStock($nouveauStock);

        // Si le stock est épuisé, changer le statut
        if ($nouveauStock <= 0) {
            $produit->setStatut('Rupture');
        }

        $em->persist($commande);
        $em->persist($produit);
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Order created successfully',
            'order_id' => $commande->getIdCommande(),
            'total' => $commande->getPrixTotal()
        ]);
    }

    #[Route('/catalogue', name: 'app_product_catalog', methods: ['GET'])]
    public function catalog(EntityManagerInterface $em): Response
    {
        $produits = $em->getRepository(Produit::class)->findBy(
            ['statut' => 'Disponible'],
            ['nom' => 'ASC']
        );

        $categories = $em->getRepository(\App\Entity\Categorie::class)->findAll();

        return $this->render('product/catalog.html.twig', [
            'produits' => $produits,
            'categories' => $categories
        ]);
    }

    #[Route('/api/catalogue', name: 'app_api_product_catalog', methods: ['GET'])]
    public function apiCatalog(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $categoryId = $request->query->get('category');
        
        $criteria = ['statut' => 'Disponible'];
        if ($categoryId) {
            $criteria['categorie'] = $categoryId;
        }

        $produits = $em->getRepository(Produit::class)->findBy(
            $criteria,
            ['nom' => 'ASC']
        );

        $data = [];
        foreach ($produits as $produit) {
            $data[] = [
                'id' => $produit->getIdProduit(),
                'nom' => $produit->getNom(),
                'type' => $produit->getType(),
                'prix' => $produit->getPrixUnitaire(),
                'stock' => $produit->getQuantiteStock(),
                'qualite' => $produit->getQualite(),
                'categorie' => $produit->getCategorie() ? $produit->getCategorie()->getNom() : null,
                'image' => $produit->getImage()
            ];
        }

        return new JsonResponse($data);
    }
}