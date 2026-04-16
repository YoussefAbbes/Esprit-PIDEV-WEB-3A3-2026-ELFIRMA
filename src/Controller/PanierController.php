<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Entity\Produit;

final class PanierController extends AbstractController
{
    private const EXCHANGE_RATE_API_BASE = 'https://v6.exchangerate-api.com/v6';

    #[Route('/panier', name: 'app_panier_index', methods: ['GET'])]
    public function index(SessionInterface $session, EntityManagerInterface $em, HttpClientInterface $httpClient): Response
    {
        $panier = $session->get('panier', []);
        $panierWithData = [];
        $total = 0;

        foreach ($panier as $productId => $quantite) {
            $produit = $em->getRepository(Produit::class)->find($productId);
            if ($produit) {
                $subtotal = $produit->getPrixUnitaire() * $quantite;
                $panierWithData[] = [
                    'produit' => $produit,
                    'quantite' => $quantite,
                    'subtotal' => $subtotal
                ];
                $total += $subtotal;
            }
        }

        return $this->render('panier_index.html.twig', [
            'items' => $panierWithData,
            'total' => $total,
            'tnd_to_eur_rate' => $this->getTndToEurRate($httpClient),
        ]);
    }

    #[Route('/api/panier/add', name: 'app_api_panier_add', methods: ['POST'])]
    public function add(Request $request, SessionInterface $session, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['product_id'], $data['quantite'])) {
            return new JsonResponse(['error' => 'Données manquantes'], 400);
        }

        $productId = (int)$data['product_id'];
        $quantite = (int)$data['quantite'];

        // Vérifier que le produit existe
        $produit = $em->getRepository(Produit::class)->find($productId);
        if (!$produit) {
            return new JsonResponse(['error' => 'Produit introuvable'], 404);
        }

        // Vérifier le stock disponible
        if ($produit->getStatut() !== 'Disponible') {
            return new JsonResponse(['error' => 'Produit non disponible'], 400);
        }

        $panier = $session->get('panier', []);
        
        // Vérifier le stock total (quantité actuelle + nouvelle quantité)
        $quantiteActuelle = $panier[$productId] ?? 0;
        $nouvelleQuantiteTotale = $quantiteActuelle + $quantite;
        
        if ($nouvelleQuantiteTotale > $produit->getQuantiteStock()) {
            return new JsonResponse([
                'error' => 'Stock insuffisant',
                'stock_disponible' => $produit->getQuantiteStock(),
                'quantite_panier' => $quantiteActuelle
            ], 400);
        }

        // Ajouter au panier
        $panier[$productId] = $nouvelleQuantiteTotale;
        $session->set('panier', $panier);

        // Calculer le nombre total d'articles
        $totalItems = array_sum($panier);

        return new JsonResponse([
            'success' => true,
            'message' => 'Produit ajouté au panier',
            'total_items' => $totalItems
        ]);
    }

    #[Route('/api/panier/update', name: 'app_api_panier_update', methods: ['POST'])]
    public function update(Request $request, SessionInterface $session, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['product_id'], $data['quantite'])) {
            return new JsonResponse(['error' => 'Données manquantes'], 400);
        }

        $productId = (int)$data['product_id'];
        $quantite = (int)$data['quantite'];

        $produit = $em->getRepository(Produit::class)->find($productId);
        if (!$produit) {
            return new JsonResponse(['error' => 'Produit introuvable'], 404);
        }

        $panier = $session->get('panier', []);

        if ($quantite <= 0) {
            // Supprimer du panier si quantité = 0
            unset($panier[$productId]);
        } else {
            // Vérifier le stock
            if ($quantite > $produit->getQuantiteStock()) {
                return new JsonResponse(['error' => 'Stock insuffisant'], 400);
            }
            $panier[$productId] = $quantite;
        }

        $session->set('panier', $panier);

        return new JsonResponse([
            'success' => true,
            'message' => 'Panier mis à jour',
            'total_items' => array_sum($panier)
        ]);
    }

    #[Route('/api/panier/remove', name: 'app_api_panier_remove', methods: ['POST'])]
    public function remove(Request $request, SessionInterface $session): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['product_id'])) {
            return new JsonResponse(['error' => 'ID produit manquant'], 400);
        }

        $productId = (int)$data['product_id'];
        $panier = $session->get('panier', []);
        
        if (isset($panier[$productId])) {
            unset($panier[$productId]);
            $session->set('panier', $panier);
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'Produit retiré du panier',
            'total_items' => array_sum($panier)
        ]);
    }

    #[Route('/api/panier/clear', name: 'app_api_panier_clear', methods: ['POST'])]
    public function clear(SessionInterface $session): JsonResponse
    {
        $session->remove('panier');

        return new JsonResponse([
            'success' => true,
            'message' => 'Panier vidé'
        ]);
    }

    #[Route('/api/panier/count', name: 'app_api_panier_count', methods: ['GET'])]
    public function count(SessionInterface $session): JsonResponse
    {
        $panier = $session->get('panier', []);
        $totalItems = array_sum($panier);

        return new JsonResponse(['count' => $totalItems]);
    }

    private function getTndToEurRate(HttpClientInterface $httpClient): ?float
    {
        $apiKey = $this->getExchangeRateApiKey();
        if ($apiKey === '') {
            return null;
        }

        try {
            $url = self::EXCHANGE_RATE_API_BASE . '/' . rawurlencode($apiKey) . '/latest/TND';
            $response = $httpClient->request('GET', $url, [
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);

            $data = $response->toArray(false);
            $rate = (float) ($data['conversion_rates']['EUR'] ?? 0);

            return $rate > 0 ? $rate : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getExchangeRateApiKey(): string
    {
        return trim((string) ($_SERVER['EXCHANGE_RATE_API_KEY'] ?? $_ENV['EXCHANGE_RATE_API_KEY'] ?? ''));
    }
}