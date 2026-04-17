<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Commande;
use App\Entity\Produit;

final class CommandeController extends AbstractController
{
    #[Route('/commandes', name: 'app_commandes_index', methods: ['GET'])]
    public function index(EntityManagerInterface $em): Response
    {
        // Pour l'exemple, on récupère toutes les commandes
        // En pratique, il faudrait filtrer par utilisateur connecté
        $commandes = $em->getRepository(Commande::class)->findBy(
            [],
            ['date_commande' => 'DESC']
        );

        return $this->render('commande_index.html.twig', [
            'commandes' => $commandes
        ]);
    }

    #[Route('/commande/{id}', name: 'app_commande_show', methods: ['GET'])]
    public function show(int $id, EntityManagerInterface $em): Response
    {
        $commande = $em->getRepository(Commande::class)->find($id);

        if (!$commande) {
            throw $this->createNotFoundException('Commande introuvable');
        }

        return $this->render('commande_show.html.twig', [
            'commande' => $commande
        ]);
    }

    #[Route('/commander', name: 'app_commande_create', methods: ['GET', 'POST'])]
    public function create(Request $request, SessionInterface $session, EntityManagerInterface $em): Response
    {
        $panier = $session->get('panier', []);

        if (empty($panier)) {
            $this->addFlash('error', 'Votre panier est vide');
            return $this->redirectToRoute('app_panier_index');
        }

        // Récupérer les produits du panier avec leurs données
        $panierWithData = [];
        $total = 0;

        foreach ($panier as $productId => $quantite) {
            $produit = $em->getRepository(Produit::class)->find($productId);
            if ($produit && $produit->getStatut() === 'Disponible') {
                if ($quantite <= $produit->getQuantiteStock()) {
                    $subtotal = $produit->getPrixUnitaire() * $quantite;
                    $panierWithData[] = [
                        'produit' => $produit,
                        'quantite' => $quantite,
                        'subtotal' => $subtotal
                    ];
                    $total += $subtotal;
                } else {
                    $this->addFlash('error', "Stock insuffisant pour {$produit->getNom()}");
                }
            }
        }

        if (empty($panierWithData)) {
            $this->addFlash('error', 'Aucun produit valide dans le panier');
            return $this->redirectToRoute('app_panier_index');
        }

        if ($request->isMethod('POST')) {
            return $this->processOrder($request, $panierWithData, $total, $session, $em);
        }

        return $this->render('commande_create.html.twig', [
            'items' => $panierWithData,
            'total' => $total
        ]);
    }

    private function processOrder(Request $request, array $panierWithData, float $total, SessionInterface $session, EntityManagerInterface $em): Response
    {
        $nomClient = $request->request->get('nom_client');
        $emailClient = $request->request->get('email_client');
        $telephoneClient = $request->request->get('telephone_client');
        $adresseClient = $request->request->get('adresse_client');
        $modePaiement = $request->request->get('mode_paiement');
        $commentaires = $request->request->get('commentaires');

        // Validation
        if (empty($nomClient) || empty($emailClient) || empty($telephoneClient) || empty($adresseClient)) {
            $this->addFlash('error', 'Tous les champs obligatoires doivent être remplis');
            return $this->render('commande_create.html.twig', [
                'items' => $panierWithData,
                'total' => $total
            ]);
        }

        try {
            $em->beginTransaction();

            // Créer une commande pour chaque produit du panier
            $commandeIds = [];

            foreach ($panierWithData as $item) {
                $produit = $item['produit'];
                $quantite = $item['quantite'];

                // Vérifier à nouveau le stock (en cas de modification entre temps)
                if ($quantite > $produit->getQuantiteStock()) {
                    throw new \Exception("Stock insuffisant pour {$produit->getNom()}");
                }

                // Créer la commande
                $commande = new Commande();
                $commande->setProduit($produit);
                $commande->setQuantite($quantite);
                $commande->setPrixTotal($item['subtotal']);
                $commande->setNomClient($nomClient);
                $commande->setStatutCommande('En attente');
                $commande->setStatutPaiement('Non payé');
                $commande->setModePaiement($modePaiement);
                $commande->setDateCommande(new \DateTime());

                // Mettre à jour le stock
                $nouveauStock = $produit->getQuantiteStock() - $quantite;
                $produit->setQuantiteStock($nouveauStock);

                // Si le stock est épuisé, changer le statut
                if ($nouveauStock <= 0) {
                    $produit->setStatut('Rupture');
                }

                $em->persist($commande);
                $em->persist($produit);

                $commandeIds[] = $commande->getIdCommande();
            }

            $em->flush();
            $em->commit();

            // Vider le panier
            $session->remove('panier');

            $this->addFlash('success', 'Commande créée avec succès!');
            
            // Rediriger vers la première commande créée
            if (!empty($commandeIds)) {
                return $this->redirectToRoute('app_commande_show', ['id' => $commandeIds[0]]);
            }
            
            return $this->redirectToRoute('app_commandes_index');

        } catch (\Exception $e) {
            $em->rollback();
            $this->addFlash('error', 'Erreur lors de la création de la commande: ' . $e->getMessage());
            
            return $this->render('commande_create.html.twig', [
                'items' => $panierWithData,
                'total' => $total
            ]);
        }
    }

    #[Route('/api/commande/quick', name: 'app_api_commande_quick', methods: ['POST'])]
    public function quickOrder(Request $request, EntityManagerInterface $em, ValidatorInterface $validator): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid request payload'], 400);
        }

        // Validation des données
        if (!array_key_exists('produit_id', $data) || !array_key_exists('quantite', $data) || !array_key_exists('nom_client', $data)) {
            return new JsonResponse(['error' => 'Données manquantes'], 400);
        }

        $productId = (int) $data['produit_id'];
        $quantite = (int) ($data['quantite'] ?? 0);
        $nomClient = trim((string) ($data['nom_client'] ?? ''));

        $produit = $em->getRepository(Produit::class)->find($productId);
        
        if (!$produit) {
            return new JsonResponse(['error' => 'Produit introuvable'], 404);
        }

        if ($produit->getStatut() !== 'Disponible') {
            return new JsonResponse(['error' => 'Produit non disponible'], 400);
        }

        if ($quantite > $produit->getQuantiteStock()) {
            return new JsonResponse(['error' => 'Stock insuffisant'], 400);
        }

        try {
            $em->beginTransaction();

            // Créer la commande
            $commande = new Commande();
            $commande->setProduit($produit);
            $commande->setQuantite($quantite);
            $commande->setPrixTotal($quantite * $produit->getPrixUnitaire());
            $commande->setNomClient($nomClient);
            $commande->setStatutCommande('En attente');
            $commande->setStatutPaiement('Non payé');
            $commande->setModePaiement($data['mode_paiement'] ?? 'Cash');
            $commande->setDateCommande(new \DateTime());

            $violations = $validator->validate($commande);
            if (count($violations) > 0) {
                $em->rollback();
                return new JsonResponse(['error' => $violations[0]->getMessage()], 400);
            }

            // Mettre à jour le stock
            $nouveauStock = $produit->getQuantiteStock() - $quantite;
            $produit->setQuantiteStock($nouveauStock);

            // Si le stock est épuisé, changer le statut
            if ($nouveauStock <= 0) {
                $produit->setStatut('Rupture');
            }

            $em->persist($commande);
            $em->persist($produit);
            $em->flush();
            $em->commit();

            return new JsonResponse([
                'success' => true,
                'message' => 'Commande créée avec succès',
                'order_id' => $commande->getIdCommande(),
                'total' => $commande->getPrixTotal()
            ]);

        } catch (\Exception $e) {
            $em->rollback();
            return new JsonResponse(['error' => 'Erreur lors de la création de la commande: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/admin/commandes', name: 'app_admin_commandes', methods: ['GET'])]
    public function adminIndex(EntityManagerInterface $em): Response
    {
        $commandes = $em->getRepository(Commande::class)->findBy(
            [],
            ['date_commande' => 'DESC']
        );

        return $this->render('commande/admin/index.html.twig', [
            'commandes' => $commandes
        ]);
    }

    #[Route('/admin/commande/{id}/edit', name: 'app_admin_commande_edit', methods: ['GET', 'POST'])]
    public function adminEdit(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $commande = $em->getRepository(Commande::class)->find($id);

        if (!$commande) {
            throw $this->createNotFoundException('Commande introuvable');
        }

        if ($request->isMethod('POST')) {
            $statutCommande = $request->request->get('statut_commande');
            $statutPaiement = $request->request->get('statut_paiement');

            if ($statutCommande) {
                $commande->setStatutCommande($statutCommande);
            }
            if ($statutPaiement) {
                $commande->setStatutPaiement($statutPaiement);
            }

            $em->flush();
            $this->addFlash('success', 'Commande mise à jour');

            return $this->redirectToRoute('app_admin_commandes');
        }

        return $this->render('commande/admin/edit.html.twig', [
            'commande' => $commande
        ]);
    }
}