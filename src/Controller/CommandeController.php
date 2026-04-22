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
use App\Entity\Utilisateur;

final class CommandeController extends AbstractController
{
    #[Route('/commandes', name: 'app_commandes_index', methods: ['GET'])]
    public function index(EntityManagerInterface $em, SessionInterface $session): Response
    {
        $userName = trim((string) $session->get('user_name', ''));
        $criteria = [];

        if ($userName !== '') {
            $criteria['nom_client'] = $userName;
        }

        $commandes = $em->getRepository(Commande::class)->findBy($criteria, ['date_commande' => 'DESC']);

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
    public function create(Request $request, SessionInterface $session, EntityManagerInterface $em, ValidatorInterface $validator): Response
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
                    $subtotal = (float) $produit->getPrixUnitaire() * $quantite;
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
            return $this->processOrder($request, $panierWithData, $total, $session, $em, $validator);
        }

        // Check if Stripe is enabled (has both public and secret keys)
        $stripeEnabled = !empty($_ENV['STRIPE_PUBLIC_KEY'] ?? '') && !empty($_ENV['STRIPE_SECRET_KEY'] ?? '');

        return $this->render('commande_create.html.twig', [
            'items' => $panierWithData,
            'total' => $total,
            'stripe_enabled' => $stripeEnabled,
            'promo_min_total' => 50.0
        ]);
    }

    private function processOrder(
        Request $request,
        array $panierWithData,
        float $total,
        SessionInterface $session,
        EntityManagerInterface $em,
        ValidatorInterface $validator
    ): Response
    {
        $nomClient = trim((string) $request->request->get('nom_client', ''));
        $emailClient = trim((string) $request->request->get('email_client', ''));
        $telephoneClient = trim((string) $request->request->get('telephone_client', ''));
        $adresseClient = trim((string) $request->request->get('adresse_client', ''));
        $modePaiement = trim((string) $request->request->get('mode_paiement', 'Cash'));

        if ($nomClient === '' || $emailClient === '' || $telephoneClient === '' || $adresseClient === '') {
            $this->addFlash('error', 'Tous les champs obligatoires doivent être remplis');
            return $this->render('commande_create.html.twig', [
                'items' => $panierWithData,
                'total' => $total
            ]);
        }

        $sessionUserId = $session->get('user_id');
        $utilisateur = is_numeric($sessionUserId)
            ? $em->getRepository(Utilisateur::class)->find((int) $sessionUserId)
            : null;

        try {
            $connection = $em->getConnection();
            $connection->beginTransaction();

            $createdOrders = [];

            foreach ($panierWithData as $item) {
                $produit = $item['produit'];
                $quantite = $item['quantite'];

                if ($quantite > $produit->getQuantiteStock()) {
                    throw new \Exception("Stock insuffisant pour {$produit->getNom()}");
                }

                $commande = new Commande();
                $commande->setProduit($produit);
                $commande->setQuantite($quantite);
                $commande->setPrixTotal(number_format((float) $item['subtotal'], 2, '.', ''));
                $commande->setNomClient($nomClient);
                $commande->setStatutCommande('En attente');
                $commande->setStatutPaiement('Non payé');
                $commande->setModePaiement($modePaiement !== '' ? $modePaiement : 'Cash');
                $commande->setDateCommande(new \DateTime());
                $commande->setUtilisateur($utilisateur);

                $violations = $validator->validate($commande);
                if (count($violations) > 0) {
                    throw new \RuntimeException((string) $violations[0]->getMessage());
                }

                $nouveauStock = $produit->getQuantiteStock() - $quantite;
                $produit->setQuantiteStock($nouveauStock);

                if ($nouveauStock <= 0) {
                    $produit->setStatut('Rupture');
                }

                $em->persist($commande);
                $em->persist($produit);
                $createdOrders[] = $commande;
            }

            $em->flush();
            $connection->commit();

            $session->remove('panier');

            $this->addFlash('success', 'Commande créée avec succès!');

            if (!empty($createdOrders)) {
                return $this->redirectToRoute('app_commande_show', ['id' => $createdOrders[0]->getIdCommande()]);
            }

            return $this->redirectToRoute('app_commandes_index');

        } catch (\Exception $e) {
            if ($em->getConnection()->isTransactionActive()) {
                $em->getConnection()->rollBack();
            }
            $this->addFlash('error', 'Erreur lors de la création de la commande: ' . $e->getMessage());

            return $this->render('commande_create.html.twig', [
                'items' => $panierWithData,
                'total' => $total
            ]);
        }
    }

    #[Route('/api/commande/quick', name: 'app_api_commande_quick', methods: ['POST'])]
    public function quickOrder(Request $request, SessionInterface $session, EntityManagerInterface $em, ValidatorInterface $validator): JsonResponse
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

        $sessionUserId = $session->get('user_id');
        $utilisateur = is_numeric($sessionUserId)
            ? $em->getRepository(Utilisateur::class)->find((int) $sessionUserId)
            : null;

        try {
            $connection = $em->getConnection();
            $connection->beginTransaction();

            $commande = new Commande();
            $commande->setProduit($produit);
            $commande->setQuantite($quantite);
            $commande->setPrixTotal(number_format($quantite * (float) $produit->getPrixUnitaire(), 2, '.', ''));
            $commande->setNomClient($nomClient);
            $commande->setStatutCommande('En attente');
            $commande->setStatutPaiement('Non payé');
            $commande->setModePaiement($data['mode_paiement'] ?? 'Cash');
            $commande->setDateCommande(new \DateTime());
            $commande->setUtilisateur($utilisateur);

            $violations = $validator->validate($commande);
            if (count($violations) > 0) {
                $connection->rollBack();
                return new JsonResponse(['error' => $violations[0]->getMessage()], 400);
            }

            $nouveauStock = $produit->getQuantiteStock() - $quantite;
            $produit->setQuantiteStock($nouveauStock);

            if ($nouveauStock <= 0) {
                $produit->setStatut('Rupture');
            }

            $em->persist($commande);
            $em->persist($produit);
            $em->flush();
            $connection->commit();

            return new JsonResponse([
                'success' => true,
                'message' => 'Commande créée avec succès',
                'order_id' => $commande->getIdCommande(),
                'total' => $commande->getPrixTotal()
            ]);

        } catch (\Exception $e) {
            if ($em->getConnection()->isTransactionActive()) {
                $em->getConnection()->rollBack();
            }
            return new JsonResponse(['error' => 'Erreur lors de la création de la commande: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/admin/commandes', name: 'app_admin_commandes', methods: ['GET'])]
    public function adminIndex(Request $request, EntityManagerInterface $em): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $statusFilter = trim((string) $request->query->get('status', ''));
        $paymentFilter = trim((string) $request->query->get('payment', ''));
        $sort = (string) $request->query->get('sort', 'date-desc');

        $commandes = $em->getRepository(Commande::class)->findAll();
        $commandes = $this->filterAndSortCommandes($commandes, $q, $statusFilter, $paymentFilter, $sort);

        $stats = [
            'total' => count($commandes),
            'pending' => count(array_filter($commandes, static fn (Commande $c): bool => $c->getStatutCommande() === 'En attente')),
            'paid' => count(array_filter($commandes, static fn (Commande $c): bool => $c->getStatutPaiement() === 'Payé')),
            'amount' => array_reduce($commandes, static fn (float $carry, Commande $c): float => $carry + (float) ($c->getPrixTotal() ?? 0), 0.0),
        ];

        return $this->render('elfirma/commandes.html.twig', [
            'commandes' => $commandes,
            'produits' => $em->getRepository(Produit::class)->findBy([], ['nom' => 'ASC']),
            'order_stats' => $stats,
            'filters' => [
                'q' => $q,
                'status' => $statusFilter,
                'payment' => $paymentFilter,
                'sort' => $sort,
            ],
        ]);
    }

    #[Route('/admin/commande/create', name: 'app_admin_commande_create', methods: ['POST'])]
    public function adminCreate(Request $request, EntityManagerInterface $em, ValidatorInterface $validator): Response
    {
        $commande = new Commande();
        $errors = $this->hydrateCommandeFromRequest($commande, $request, $em);
        $this->appendValidationErrors($errors, $validator->validate($commande));

        if ($errors !== []) {
            $this->addFlash('form_errors_commande_create', $errors);
            $this->addFlash('form_old_commande_create', $request->request->all());

            return $this->redirectToRoute('app_admin_commandes');
        }

        try {
            $em->persist($commande);
            $em->flush();
            $this->addFlash('success', 'Commande créée avec succès.');
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Impossible de créer la commande pour le moment.');
            $this->addFlash('form_old_commande_create', $request->request->all());
        }

        return $this->redirectToRoute('app_admin_commandes');
    }

    #[Route('/admin/commande/{id}/edit', name: 'app_admin_commande_edit', methods: ['POST'])]
    public function adminEdit(int $id, Request $request, EntityManagerInterface $em, ValidatorInterface $validator): Response
    {
        $commande = $em->getRepository(Commande::class)->find($id);

        if (!$commande) {
            $this->addFlash('error', 'Commande introuvable.');
            return $this->redirectToRoute('app_admin_commandes');
        }

        $errors = $this->hydrateCommandeFromRequest($commande, $request, $em);
        $this->appendValidationErrors($errors, $validator->validate($commande));

        if ($errors !== []) {
            $this->addFlash('form_errors_commande_edit', $errors);
            $old = $request->request->all();
            $old['id'] = $id;
            $this->addFlash('form_old_commande_edit', $old);

            return $this->redirectToRoute('app_admin_commandes');
        }

        try {
            $em->flush();
            $this->addFlash('success', 'Commande mise à jour.');
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Impossible de mettre à jour la commande pour le moment.');
        }

        return $this->redirectToRoute('app_admin_commandes');
    }

    #[Route('/admin/commande/{id}/delete', name: 'app_admin_commande_delete', methods: ['POST'])]
    public function adminDelete(int $id, EntityManagerInterface $em): Response
    {
        $commande = $em->getRepository(Commande::class)->find($id);
        if (!$commande) {
            $this->addFlash('error', 'Commande introuvable.');
            return $this->redirectToRoute('app_admin_commandes');
        }

        try {
            $em->remove($commande);
            $em->flush();
            $this->addFlash('success', 'Commande supprimée avec succès.');
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Impossible de supprimer la commande pour le moment.');
        }

        return $this->redirectToRoute('app_admin_commandes');
    }

    /**
     * @return array<string, list<string>>
     */
    private function hydrateCommandeFromRequest(Commande $commande, Request $request, EntityManagerInterface $em): array
    {
        $errors = [];

        $produitId = filter_var($request->request->get('produit_id'), FILTER_VALIDATE_INT);
        $produit = $produitId !== false ? $em->getRepository(Produit::class)->find((int) $produitId) : null;
        if (!$produit) {
            $errors['produit'][] = 'Produit invalide.';
        }

        $quantiteValue = (string) $request->request->get('quantite', '');
        $quantite = filter_var($quantiteValue, FILTER_VALIDATE_INT);
        if ($quantite === false || $quantite <= 0) {
            $errors['quantite'][] = 'La quantité doit être un entier strictement positif.';
            $quantite = 1;
        }

        $prixTotalRaw = trim((string) $request->request->get('prix_total', ''));
        if ($prixTotalRaw === '') {
            $prixTotalRaw = number_format(((int) $quantite) * (float) ($produit?->getPrixUnitaire() ?? 0), 2, '.', '');
        }

        $dateRaw = trim((string) $request->request->get('date_commande', ''));
        $dateCommande = \DateTime::createFromFormat('Y-m-d', $dateRaw);
        if ($dateRaw === '' || $dateCommande === false) {
            $dateCommande = new \DateTime();
        }

        $nomClient = trim((string) $request->request->get('nom_client', ''));
        $modePaiement = trim((string) $request->request->get('mode_paiement', 'Cash'));
        $statutCommande = trim((string) $request->request->get('statut_commande', 'En attente'));
        $statutPaiement = trim((string) $request->request->get('statut_paiement', 'Non payé'));
        $facture = trim((string) $request->request->get('facture', ''));

        $commande->setProduit($produit);
        $commande->setQuantite((int) $quantite);
        $commande->setPrixTotal($prixTotalRaw);
        $commande->setNomClient($nomClient);
        $commande->setModePaiement($modePaiement);
        $commande->setStatutCommande($statutCommande);
        $commande->setStatutPaiement($statutPaiement);
        $commande->setDateCommande($dateCommande);
        $commande->setFacture($facture !== '' ? $facture : null);

        return $errors;
    }

    /**
     * @param iterable<mixed> $violations
     * @param array<string, list<string>> $errors
     */
    private function appendValidationErrors(array &$errors, iterable $violations): void
    {
        foreach ($violations as $violation) {
            $field = (string) $violation->getPropertyPath();
            if ($field === '') {
                $field = '_global';
            }

            $message = (string) $violation->getMessage();
            if (!isset($errors[$field]) || !in_array($message, $errors[$field], true)) {
                $errors[$field][] = $message;
            }
        }
    }

    /**
     * @param list<Commande> $commandes
     *
     * @return list<Commande>
     */
    private function filterAndSortCommandes(array $commandes, string $q, string $statusFilter, string $paymentFilter, string $sort): array
    {
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $commandes = array_values(array_filter($commandes, static function (Commande $commande) use ($needle): bool {
                $productName = $commande->getProduit()?->getNom() ?? '';
                $id = (string) ($commande->getIdCommande() ?? '');
                $nomClient = $commande->getNomClient() ?? '';

                return str_contains(mb_strtolower($nomClient), $needle)
                    || str_contains(mb_strtolower($productName), $needle)
                    || str_contains($id, $needle);
            }));
        }

        if ($statusFilter !== '') {
            $commandes = array_values(array_filter($commandes, static fn (Commande $commande): bool => $commande->getStatutCommande() === $statusFilter));
        }

        if ($paymentFilter !== '') {
            $commandes = array_values(array_filter($commandes, static fn (Commande $commande): bool => $commande->getStatutPaiement() === $paymentFilter));
        }

        usort($commandes, static function (Commande $a, Commande $b) use ($sort): int {
            $dateA = $a->getDateCommande();
            $dateB = $b->getDateCommande();

            $idA = (int) ($a->getIdCommande() ?? 0);
            $idB = (int) ($b->getIdCommande() ?? 0);

            $totalA = (float) ($a->getPrixTotal() ?? 0);
            $totalB = (float) ($b->getPrixTotal() ?? 0);

            return match ($sort) {
                'date-asc' => ($dateA?->getTimestamp() ?? 0) <=> ($dateB?->getTimestamp() ?? 0),
                'total-desc' => $totalB <=> $totalA,
                'total-asc' => $totalA <=> $totalB,
                'id-asc' => $idA <=> $idB,
                'id-desc' => $idB <=> $idA,
                default => ($dateB?->getTimestamp() ?? 0) <=> ($dateA?->getTimestamp() ?? 0),
            };
        });

        return $commandes;
    }
}