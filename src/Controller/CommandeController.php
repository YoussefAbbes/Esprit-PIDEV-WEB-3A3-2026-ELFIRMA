<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Commande;
use App\Entity\Produit;
use App\Entity\Utilisateur;

final class CommandeController extends AbstractController
{
    private const PROMO_MIN_TOTAL = 50.0;
    private const PROMO_DISCOUNT_PERCENT = 10;
    private const PROMO_VALID_DAYS = 3;
    private const STRIPE_API_BASE = 'https://api.stripe.com/v1';

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

    #[Route('/commande/{id}/receipt', name: 'app_commande_receipt', methods: ['GET'])]
    public function receipt(int $id, EntityManagerInterface $em): Response
    {
        $commande = $em->getRepository(Commande::class)->find($id);

        if (!$commande) {
            throw $this->createNotFoundException('Commande introuvable');
        }

        if ($commande->getModePaiement() !== 'Carte bancaire' || $commande->getStatutPaiement() !== 'Payé') {
            $this->addFlash('error', 'Le recu est disponible uniquement pour les commandes payees par carte.');
            return $this->redirectToRoute('app_commande_show', ['id' => $id]);
        }

        return $this->render('commande_receipt.html.twig', [
            'commande' => $commande,
            'receipt_logo_data_uri' => $this->buildReceiptLogoDataUri(),
        ]);
    }

    #[Route('/commande/{id}/receipt/download', name: 'app_commande_receipt_download', methods: ['GET'])]
    public function downloadReceipt(int $id, EntityManagerInterface $em): Response
    {
        $commande = $em->getRepository(Commande::class)->find($id);

        if (!$commande) {
            throw $this->createNotFoundException('Commande introuvable');
        }

        if ($commande->getModePaiement() !== 'Carte bancaire' || $commande->getStatutPaiement() !== 'Payé') {
            $this->addFlash('error', 'Le recu est disponible uniquement pour les commandes payees par carte.');
            return $this->redirectToRoute('app_commande_show', ['id' => $id]);
        }

        $content = $this->renderView('commande_receipt.html.twig', [
            'commande' => $commande,
            'is_download' => true,
            'receipt_logo_data_uri' => $this->buildReceiptLogoDataUri(),
        ]);

        $filename = sprintf('recu-paiement-commande-%d.html', (int) ($commande->getIdCommande() ?? 0));

        return new Response($content, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function buildReceiptLogoDataUri(): ?string
    {
        $projectDir = (string) $this->getParameter('kernel.project_dir');
        $logoPath = $projectDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'logo.png';

        if (!is_file($logoPath) || !is_readable($logoPath)) {
            return null;
        }

        $binary = file_get_contents($logoPath);
        if ($binary === false || $binary === '') {
            return null;
        }

        $extension = strtolower((string) pathinfo($logoPath, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'application/octet-stream',
        };

        return 'data:' . $mime . ';base64,' . base64_encode($binary);
    }

    #[Route('/commander', name: 'app_commande_create', methods: ['GET', 'POST'])]
    public function create(Request $request, SessionInterface $session, EntityManagerInterface $em, ValidatorInterface $validator, HttpClientInterface $httpClient): Response
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
            $action = (string) $request->request->get('checkout_action', '');

            if ($action === 'generate_promo') {
                return $this->handlePromoGeneration($request, $panierWithData, $total, $session);
            }

            if ($action === 'apply_promo') {
                return $this->handlePromoApplication($request, $panierWithData, $total, $session);
            }

            if ($action !== 'confirm_order') {
                return $this->renderCheckout($panierWithData, $total, $session, [
                    '_global' => ['Veuillez confirmer la commande pour finaliser l\'achat.'],
                ], [
                    'nom_client' => trim((string) $request->request->get('nom_client', (string) $session->get('user_name', ''))),
                    'adresse_livraison' => trim((string) $request->request->get('adresse_livraison', '')),
                    'mode_paiement' => trim((string) $request->request->get('mode_paiement', 'Cash')),
                    'promo_code' => strtoupper(trim((string) $request->request->get('promo_code', ''))),
                    'stripe_payment_intent_id' => trim((string) $request->request->get('stripe_payment_intent_id', '')),
                ]);
            }

            return $this->processOrder($request, $panierWithData, $total, $session, $em, $validator, $httpClient);
        }

        return $this->renderCheckout($panierWithData, $total, $session, [], [
            'nom_client' => (string) $session->get('user_name', ''),
            'adresse_livraison' => '',
            'mode_paiement' => 'Cash',
            'promo_code' => '',
        ]);
    }

    private function processOrder(
        Request $request,
        array $panierWithData,
        float $total,
        SessionInterface $session,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
        HttpClientInterface $httpClient
    ): Response
    {
        $formErrors = [];
        $nomClient = trim((string) $request->request->get('nom_client', ''));
        $adresseLivraison = trim((string) $request->request->get('adresse_livraison', ''));
        $modePaiement = trim((string) $request->request->get('mode_paiement', 'Cash'));
        $promoCode = strtoupper(trim((string) $request->request->get('promo_code', '')));
        $stripePaymentIntentId = trim((string) $request->request->get('stripe_payment_intent_id', ''));

        $old = [
            'nom_client' => $nomClient,
            'adresse_livraison' => $adresseLivraison,
            'mode_paiement' => $modePaiement,
            'promo_code' => $promoCode,
            'stripe_payment_intent_id' => $stripePaymentIntentId,
        ];

        if ($nomClient === '') {
            $formErrors['nom_client'][] = 'Le nom complet est obligatoire.';
        }

        if ($adresseLivraison === '') {
            $formErrors['adresse_livraison'][] = 'L\'adresse de livraison est obligatoire.';
        }

        if ($modePaiement === '') {
            $formErrors['mode_paiement'][] = 'Le mode de paiement est obligatoire.';
        }

        if ($formErrors !== []) {
            return $this->renderCheckout($panierWithData, $total, $session, $formErrors, $old);
        }

        $promoSummary = $this->resolvePromoSummary($session, $total);
        if ($promoCode !== '' && (!$promoSummary['is_applied'] || $promoSummary['applied_code'] !== $promoCode)) {
            $formErrors['promo_code'][] = 'Code promo invalide, expire ou non applique.';
            return $this->renderCheckout($panierWithData, $total, $session, $formErrors, $old);
        }

        if ($modePaiement === 'Carte bancaire') {
            if ($stripePaymentIntentId === '') {
                $formErrors['mode_paiement'][] = 'Paiement carte non confirme. Veuillez verifier votre carte.';
                return $this->renderCheckout($panierWithData, $total, $session, $formErrors, $old);
            }

            $expectedAmount = (int) round(((float) $promoSummary['final_total']) * 100);
            $verification = $this->verifyStripePaymentIntent($stripePaymentIntentId, $expectedAmount, $httpClient);
            if (!$verification['ok']) {
                $formErrors['mode_paiement'][] = $verification['message'];
                return $this->renderCheckout($panierWithData, $total, $session, $formErrors, $old);
            }
        }

        $sessionUserId = $session->get('user_id');
        $utilisateur = is_numeric($sessionUserId)
            ? $em->getRepository(Utilisateur::class)->find((int) $sessionUserId)
            : null;

        try {
            $connection = $em->getConnection();
            $connection->beginTransaction();

            $createdOrders = [];
            $pendingPersist = [];
            $promoDiscount = (float) $promoSummary['discount_amount'];
            $lineTotals = $this->buildDiscountedLineTotals($panierWithData, $promoDiscount);

            foreach ($panierWithData as $index => $item) {
                $produit = $item['produit'];
                $quantite = $item['quantite'];

                if ($quantite > $produit->getQuantiteStock()) {
                    $formErrors['_global'][] = "Stock insuffisant pour {$produit->getNom()}.";
                    continue;
                }

                $commande = new Commande();
                $commande->setProduit($produit);
                $commande->setQuantite($quantite);
                $commande->setPrixTotal(number_format((float) $lineTotals[$index], 2, '.', ''));
                $commande->setNomClient($nomClient);
                $commande->setAdresseLivraison($adresseLivraison);
                $commande->setStatutCommande('En attente');
                $commande->setStatutPaiement($modePaiement === 'Carte bancaire' ? 'Payé' : 'Non payé');
                $commande->setModePaiement($modePaiement !== '' ? $modePaiement : 'Cash');
                $commande->setDateCommande(new \DateTime());
                $commande->setUtilisateur($utilisateur);

                $violations = $validator->validate($commande);
                if (count($violations) > 0) {
                    $this->appendValidationErrors($formErrors, $violations);
                    continue;
                }

                $pendingPersist[] = [
                    'commande' => $commande,
                    'produit' => $produit,
                    'quantite' => $quantite,
                ];
            }

            if ($formErrors !== []) {
                $connection->rollBack();
                return $this->renderCheckout($panierWithData, $total, $session, $formErrors, $old);
            }

            foreach ($pendingPersist as $entry) {
                /** @var Produit $produit */
                $produit = $entry['produit'];
                $quantite = (int) $entry['quantite'];
                /** @var Commande $commande */
                $commande = $entry['commande'];

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
            $this->clearPromoState($session);

            $this->addFlash('success', 'Commande créée avec succès!');

            if ($modePaiement === 'Carte bancaire' && !empty($createdOrders)) {
                $this->addFlash('card_receipt_ready', 'Paiement confirme. Vous pouvez telecharger votre recu.');
            }

            if (!empty($createdOrders)) {
                return $this->redirectToRoute('app_commande_show', ['id' => $createdOrders[0]->getIdCommande()]);
            }

            return $this->redirectToRoute('app_commandes_index');

        } catch (\Exception $e) {
            if ($em->getConnection()->isTransactionActive()) {
                $em->getConnection()->rollBack();
            }
            $this->addFlash('error', 'Erreur lors de la création de la commande: ' . $e->getMessage());

            return $this->renderCheckout(
                $panierWithData,
                $total,
                $session,
                ['_global' => ['Erreur lors de la creation de la commande: ' . $e->getMessage()]],
                $old
            );
        }
    }

    private function handlePromoGeneration(Request $request, array $panierWithData, float $total, SessionInterface $session): Response
    {
        $old = [
            'nom_client' => trim((string) $request->request->get('nom_client', (string) $session->get('user_name', ''))),
            'adresse_livraison' => trim((string) $request->request->get('adresse_livraison', '')),
            'mode_paiement' => trim((string) $request->request->get('mode_paiement', 'Cash')),
            'promo_code' => strtoupper(trim((string) $request->request->get('promo_code', ''))),
            'stripe_payment_intent_id' => trim((string) $request->request->get('stripe_payment_intent_id', '')),
        ];

        if ($total < self::PROMO_MIN_TOTAL) {
            return $this->renderCheckout($panierWithData, $total, $session, [
                'promo_code' => [sprintf('Le code promo est disponible a partir de %.0f DT.', self::PROMO_MIN_TOTAL)],
            ], $old);
        }

        $code = 'ELFIRMA-' . strtoupper(bin2hex(random_bytes(3)));
        $expiresAt = (new \DateTimeImmutable())->modify('+' . self::PROMO_VALID_DAYS . ' days');

        $session->set('commande_promo_generated', [
            'code' => $code,
            'discount_percent' => self::PROMO_DISCOUNT_PERCENT,
            'min_total' => self::PROMO_MIN_TOTAL,
            'expires_at' => $expiresAt->format(\DateTimeInterface::ATOM),
        ]);
        $session->remove('commande_promo_applied');

        $this->addFlash('success', sprintf('Code genere: %s (valable %d jours).', $code, self::PROMO_VALID_DAYS));

        $old['promo_code'] = $code;
        return $this->renderCheckout($panierWithData, $total, $session, [], $old);
    }

    private function handlePromoApplication(Request $request, array $panierWithData, float $total, SessionInterface $session): Response
    {
        $nomClient = trim((string) $request->request->get('nom_client', (string) $session->get('user_name', '')));
        $adresseLivraison = trim((string) $request->request->get('adresse_livraison', ''));
        $modePaiement = trim((string) $request->request->get('mode_paiement', 'Cash'));
        $promoCode = strtoupper(trim((string) $request->request->get('promo_code', '')));

        $old = [
            'nom_client' => $nomClient,
            'adresse_livraison' => $adresseLivraison,
            'mode_paiement' => $modePaiement,
            'promo_code' => $promoCode,
            'stripe_payment_intent_id' => trim((string) $request->request->get('stripe_payment_intent_id', '')),
        ];

        if ($promoCode === '') {
            return $this->renderCheckout($panierWithData, $total, $session, [
                'promo_code' => ['Veuillez saisir un code promo.'],
            ], $old);
        }

        if ($total < self::PROMO_MIN_TOTAL) {
            return $this->renderCheckout($panierWithData, $total, $session, [
                'promo_code' => [sprintf('Le montant minimum pour appliquer un code est %.0f DT.', self::PROMO_MIN_TOTAL)],
            ], $old);
        }

        $generated = $this->getGeneratedPromo($session);
        if ($generated === null) {
            return $this->renderCheckout($panierWithData, $total, $session, [
                'promo_code' => ['Aucun code promo genere.'],
            ], $old);
        }

        if ($generated['code'] !== $promoCode) {
            return $this->renderCheckout($panierWithData, $total, $session, [
                'promo_code' => ['Code promo invalide.'],
            ], $old);
        }

        if ($this->isPromoExpired($generated['expires_at'])) {
            $this->clearPromoState($session);
            return $this->renderCheckout($panierWithData, $total, $session, [
                'promo_code' => ['Ce code promo a expire.'],
            ], $old);
        }

        $session->set('commande_promo_applied', [
            'code' => $promoCode,
            'applied_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);

        $this->addFlash('success', 'Code promo applique avec succes.');
        return $this->renderCheckout($panierWithData, $total, $session, [], $old);
    }

    private function renderCheckout(array $panierWithData, float $total, SessionInterface $session, array $formErrors, array $old): Response
    {
        $promoSummary = $this->resolvePromoSummary($session, $total);
        $stripePublicKey = $this->getStripePublicKey();
        $stripeSecretKey = $this->getStripeSecretKey();
        $stripeEnabled = $stripePublicKey !== '' && $stripeSecretKey !== '';

        return $this->render('commande_create.html.twig', [
            'items' => $panierWithData,
            'total' => $total,
            'final_total' => $promoSummary['final_total'],
            'promo_discount' => $promoSummary['discount_amount'],
            'promo_percent' => $promoSummary['discount_percent'],
            'promo_generated_code' => $promoSummary['generated_code'],
            'promo_expires_at' => $promoSummary['expires_at'],
            'promo_is_applied' => $promoSummary['is_applied'],
            'promo_min_total' => self::PROMO_MIN_TOTAL,
            'stripe_enabled' => $stripeEnabled,
            'stripe_public_key' => $stripePublicKey,
            'form_errors' => $formErrors,
            'old' => $old,
        ]);
    }

    #[Route('/commande/stripe/create-intent', name: 'app_commande_stripe_create_intent', methods: ['POST'])]
    public function createStripePaymentIntent(SessionInterface $session, EntityManagerInterface $em, HttpClientInterface $httpClient): JsonResponse
    {
        $panier = $session->get('panier', []);
        if (!is_array($panier) || $panier === []) {
            return new JsonResponse(['error' => 'Panier vide.'], 400);
        }

        $total = 0.0;
        foreach ($panier as $productId => $quantite) {
            $produit = $em->getRepository(Produit::class)->find((int) $productId);
            if (!$produit || $produit->getStatut() !== 'Disponible') {
                continue;
            }

            $qty = (int) $quantite;
            if ($qty <= 0 || $qty > $produit->getQuantiteStock()) {
                continue;
            }

            $total += ((float) $produit->getPrixUnitaire()) * $qty;
        }

        if ($total <= 0) {
            return new JsonResponse(['error' => 'Aucun produit valide dans le panier.'], 400);
        }

        $promoSummary = $this->resolvePromoSummary($session, $total);
        $amountCents = (int) round(((float) $promoSummary['final_total']) * 100);
        if ($amountCents <= 0) {
            return new JsonResponse(['error' => 'Montant invalide.'], 400);
        }

        $secretKey = $this->getStripeSecretKey();
        if ($secretKey === '') {
            return new JsonResponse(['error' => 'Stripe non configure.'], 500);
        }

        try {
            $payload = [
                'amount' => $amountCents,
                'currency' => $this->getStripeCurrency(),
                'automatic_payment_methods[enabled]' => 'true',
                'description' => 'Paiement commande EL FIRMA',
                'metadata[source]' => 'checkout_commande',
            ];

            $response = $httpClient->request('POST', self::STRIPE_API_BASE . '/payment_intents', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $secretKey,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => http_build_query($payload),
            ]);

            $data = $response->toArray(false);
            if (!isset($data['client_secret'], $data['id'])) {
                return new JsonResponse(['error' => 'Reponse Stripe invalide.'], 502);
            }

            return new JsonResponse([
                'clientSecret' => $data['client_secret'],
                'paymentIntentId' => $data['id'],
                'amount' => $amountCents,
                'currency' => $this->getStripeCurrency(),
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Impossible de creer le paiement Stripe.'], 502);
        }
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function verifyStripePaymentIntent(string $paymentIntentId, int $expectedAmount, HttpClientInterface $httpClient): array
    {
        $secretKey = $this->getStripeSecretKey();
        if ($secretKey === '') {
            return ['ok' => false, 'message' => 'Stripe non configure.'];
        }

        try {
            $response = $httpClient->request('GET', self::STRIPE_API_BASE . '/payment_intents/' . rawurlencode($paymentIntentId), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $secretKey,
                ],
            ]);

            $data = $response->toArray(false);
            $status = (string) ($data['status'] ?? '');
            $amount = (int) ($data['amount'] ?? 0);

            if ($status !== 'succeeded') {
                return ['ok' => false, 'message' => 'Le paiement carte n\'est pas confirme.'];
            }

            if ($amount !== $expectedAmount) {
                return ['ok' => false, 'message' => 'Le montant paye ne correspond pas a la commande.'];
            }

            return ['ok' => true, 'message' => 'OK'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Verification Stripe impossible.'];
        }
    }

    private function getStripePublicKey(): string
    {
        return trim((string) ($_SERVER['STRIPE_PUBLIC_KEY'] ?? $_ENV['STRIPE_PUBLIC_KEY'] ?? ''));
    }

    private function getStripeSecretKey(): string
    {
        return trim((string) ($_SERVER['STRIPE_SECRET_KEY'] ?? $_ENV['STRIPE_SECRET_KEY'] ?? ''));
    }

    private function getStripeCurrency(): string
    {
        $currency = strtolower(trim((string) ($_SERVER['STRIPE_CURRENCY'] ?? $_ENV['STRIPE_CURRENCY'] ?? 'eur')));
        return $currency !== '' ? $currency : 'eur';
    }

    /**
     * @return array{final_total: float, discount_amount: float, discount_percent: int, generated_code: ?string, expires_at: ?\DateTimeImmutable, is_applied: bool, applied_code: ?string}
     */
    private function resolvePromoSummary(SessionInterface $session, float $total): array
    {
        $generated = $this->getGeneratedPromo($session);
        $applied = $session->get('commande_promo_applied');

        if ($generated === null || $this->isPromoExpired($generated['expires_at'])) {
            $this->clearPromoState($session);
            return [
                'final_total' => $total,
                'discount_amount' => 0.0,
                'discount_percent' => 0,
                'generated_code' => null,
                'expires_at' => null,
                'is_applied' => false,
                'applied_code' => null,
            ];
        }

        $isApplied = is_array($applied) && ($applied['code'] ?? '') === $generated['code'] && $total >= (float) $generated['min_total'];
        $discountPercent = (int) ($generated['discount_percent'] ?? 0);
        $discountAmount = $isApplied ? round($total * $discountPercent / 100, 2) : 0.0;

        return [
            'final_total' => max(0.0, round($total - $discountAmount, 2)),
            'discount_amount' => $discountAmount,
            'discount_percent' => $discountPercent,
            'generated_code' => (string) $generated['code'],
            'expires_at' => \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, (string) $generated['expires_at']) ?: null,
            'is_applied' => $isApplied,
            'applied_code' => $isApplied ? (string) $generated['code'] : null,
        ];
    }

    /**
     * @return array{code: string, discount_percent: int, min_total: float, expires_at: string}|null
     */
    private function getGeneratedPromo(SessionInterface $session): ?array
    {
        $generated = $session->get('commande_promo_generated');
        if (!is_array($generated)) {
            return null;
        }

        if (!isset($generated['code'], $generated['discount_percent'], $generated['min_total'], $generated['expires_at'])) {
            return null;
        }

        return [
            'code' => (string) $generated['code'],
            'discount_percent' => (int) $generated['discount_percent'],
            'min_total' => (float) $generated['min_total'],
            'expires_at' => (string) $generated['expires_at'],
        ];
    }

    private function isPromoExpired(string $expiresAt): bool
    {
        $expires = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $expiresAt);
        if (!$expires) {
            return true;
        }

        return $expires < new \DateTimeImmutable();
    }

    private function clearPromoState(SessionInterface $session): void
    {
        $session->remove('commande_promo_generated');
        $session->remove('commande_promo_applied');
    }

    /**
     * @return array<int, float>
     */
    private function buildDiscountedLineTotals(array $panierWithData, float $promoDiscount): array
    {
        $lineTotals = [];
        $rawTotals = array_map(static fn (array $item): float => (float) ($item['subtotal'] ?? 0), $panierWithData);
        $sum = array_sum($rawTotals);

        if ($promoDiscount <= 0 || $sum <= 0) {
            foreach ($rawTotals as $index => $rawTotal) {
                $lineTotals[$index] = round($rawTotal, 2);
            }
            return $lineTotals;
        }

        $allocated = 0.0;
        $lastIndex = array_key_last($rawTotals);

        foreach ($rawTotals as $index => $rawTotal) {
            if ($index === $lastIndex) {
                $lineTotals[$index] = max(0.0, round($rawTotal - ($promoDiscount - $allocated), 2));
                continue;
            }

            $lineDiscount = round(($rawTotal / $sum) * $promoDiscount, 2);
            $allocated += $lineDiscount;
            $lineTotals[$index] = max(0.0, round($rawTotal - $lineDiscount, 2));
        }

        return $lineTotals;
    }

    #[Route('/api/commande/chatbot', name: 'app_api_commande_chatbot', methods: ['POST'])]
    public function chatbot(Request $request, SessionInterface $session, EntityManagerInterface $em): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Format invalide.'], 400);
        }

        $message = trim((string) ($payload['message'] ?? ''));
        if ($message === '') {
            return new JsonResponse(['error' => 'Message vide.'], 400);
        }

        $panier = $session->get('panier', []);
        $items = 0;
        $total = 0.0;

        if (is_array($panier)) {
            foreach ($panier as $productId => $quantite) {
                $qty = max(0, (int) $quantite);
                if ($qty === 0) {
                    continue;
                }

                $produit = $em->getRepository(Produit::class)->find((int) $productId);
                if (!$produit || $produit->getStatut() !== 'Disponible') {
                    continue;
                }

                $items += $qty;
                $total += ((float) $produit->getPrixUnitaire()) * $qty;
            }
        }

        $promo = $this->resolvePromoSummary($session, $total);
        $result = $this->buildCheckoutChatbotReply($message, $items, $total, $promo);

        return new JsonResponse([
            'reply' => $result['reply'],
            'quick_replies' => $result['quick_replies'],
            'context' => [
                'items' => $items,
                'total' => round($total, 2),
                'final_total' => round((float) $promo['final_total'], 2),
                'promo_applied' => (bool) $promo['is_applied'],
            ],
        ]);
    }

    /**
     * @param array{final_total: float, discount_amount: float, discount_percent: int, generated_code: ?string, expires_at: ?\DateTimeImmutable, is_applied: bool, applied_code: ?string} $promo
     *
     * @return array{reply: string, quick_replies: list<string>}
     */
    private function buildCheckoutChatbotReply(string $message, int $items, float $total, array $promo): array
    {
        $text = mb_strtolower($message);
        $hasPromo = (bool) $promo['is_applied'];
        $finalTotal = (float) $promo['final_total'];
        $discount = (float) $promo['discount_amount'];
        $promoMin = self::PROMO_MIN_TOTAL;

        if (str_contains($text, 'bonjour') || str_contains($text, 'salut') || str_contains($text, 'hello')) {
            return [
                'reply' => 'Bonjour! Je vous aide a finaliser votre commande. Vous avez ' . $items . ' article(s), total actuel: ' . number_format($total, 2, '.', ' ') . ' DT.',
                'quick_replies' => ['Comment utiliser le code promo ?', 'Quels moyens de paiement ?', 'Que se passe-t-il apres paiement ?'],
            ];
        }

        if (str_contains($text, 'promo') || str_contains($text, 'reduction') || str_contains($text, 'code')) {
            if ($total < $promoMin) {
                return [
                    'reply' => 'Le code promo est disponible a partir de ' . number_format($promoMin, 0, '.', ' ') . ' DT. Votre total est ' . number_format($total, 2, '.', ' ') . ' DT.',
                    'quick_replies' => ['Comment augmenter mon panier ?', 'Quels moyens de paiement ?'],
                ];
            }

            if ($hasPromo) {
                return [
                    'reply' => 'Votre promo est deja appliquee: -' . number_format($discount, 2, '.', ' ') . ' DT. Total a payer: ' . number_format($finalTotal, 2, '.', ' ') . ' DT.',
                    'quick_replies' => ['Comment payer par carte ?', 'Je confirme ma commande'],
                ];
            }

            return [
                'reply' => 'Vous pouvez generer puis appliquer un code promo (valable 3 jours) directement dans le bloc promo avant la confirmation.',
                'quick_replies' => ['Generer code promo', 'Appliquer code promo', 'Total actuel'],
            ];
        }

        if (str_contains($text, 'carte') || str_contains($text, 'stripe') || str_contains($text, 'paiement')) {
            return [
                'reply' => 'Pour payer par carte: choisissez "Carte bancaire", remplissez les informations Stripe, puis confirmez. La commande est enregistree seulement si le paiement est valide.',
                'quick_replies' => ['Carte test ?', 'Paiement cash', 'Paiement virement'],
            ];
        }

        if (str_contains($text, 'test') || str_contains($text, '4242')) {
            return [
                'reply' => 'En mode test Stripe, vous pouvez utiliser 4242 4242 4242 4242, une date future, et un CVC de 3 chiffres.',
                'quick_replies' => ['Comment confirmer la commande ?', 'Quels sont les statuts ?'],
            ];
        }

        if (str_contains($text, 'adresse') || str_contains($text, 'livraison')) {
            return [
                'reply' => 'Le champ adresse de livraison est obligatoire. Vous trouverez le message d\'erreur juste sous le champ si la saisie est invalide.',
                'quick_replies' => ['Delai de livraison ?', 'Je veux modifier mon adresse'],
            ];
        }

        if (str_contains($text, 'total') || str_contains($text, 'montant')) {
            $reply = 'Total panier: ' . number_format($total, 2, '.', ' ') . ' DT.';
            if ($hasPromo) {
                $reply .= ' Reduction appliquee: -' . number_format($discount, 2, '.', ' ') . ' DT. Total final: ' . number_format($finalTotal, 2, '.', ' ') . ' DT.';
            }

            return [
                'reply' => $reply,
                'quick_replies' => ['Comment utiliser le code promo ?', 'Je confirme ma commande'],
            ];
        }

        return [
            'reply' => 'Je peux vous aider sur: promo, paiement carte Stripe, adresse de livraison et total de commande. Dites-moi ce que vous souhaitez verifier.',
            'quick_replies' => ['Code promo', 'Paiement carte Stripe', 'Adresse de livraison', 'Total actuel'],
        ];
    }

    #[Route('/api/commande/quick', name: 'app_api_commande_quick', methods: ['POST'])]
    public function quickOrder(Request $request, SessionInterface $session, EntityManagerInterface $em, ValidatorInterface $validator): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid request payload'], 400);
        }

        // Validation des données
        if (!array_key_exists('produit_id', $data) || !array_key_exists('quantite', $data) || !array_key_exists('nom_client', $data) || !array_key_exists('adresse_livraison', $data)) {
            return new JsonResponse(['error' => 'Données manquantes'], 400);
        }

        $productId = (int) $data['produit_id'];
        $quantite = (int) ($data['quantite'] ?? 0);
        $nomClient = trim((string) ($data['nom_client'] ?? ''));
        $adresseLivraison = trim((string) ($data['adresse_livraison'] ?? ''));

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
            $commande->setAdresseLivraison($adresseLivraison);
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
        $adresseLivraison = trim((string) $request->request->get('adresse_livraison', ''));
        $modePaiement = trim((string) $request->request->get('mode_paiement', 'Cash'));
        $statutCommande = trim((string) $request->request->get('statut_commande', 'En attente'));
        $statutPaiement = trim((string) $request->request->get('statut_paiement', 'Non payé'));
        $facture = trim((string) $request->request->get('facture', ''));

        $commande->setProduit($produit);
        $commande->setQuantite((int) $quantite);
        $commande->setPrixTotal($prixTotalRaw);
        $commande->setNomClient($nomClient);
        $commande->setAdresseLivraison($adresseLivraison);
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
            $field = $this->mapValidationFieldToFormField((string) $violation->getPropertyPath());
            if ($field === '') {
                $field = '_global';
            }

            $message = (string) $violation->getMessage();
            if (!isset($errors[$field]) || !in_array($message, $errors[$field], true)) {
                $errors[$field][] = $message;
            }
        }
    }

    private function mapValidationFieldToFormField(string $field): string
    {
        return match ($field) {
            'produit' => 'produit_id',
            default => $field,
        };
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