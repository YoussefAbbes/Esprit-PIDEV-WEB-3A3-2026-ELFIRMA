<?php

namespace App\Controller;

use App\AI\GestureIntentAi;
use App\AI\VoiceIntentAi;
use App\Entity\Produit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

final class AiAccessibilityController extends AbstractController
{
    public function __construct(
        private readonly VoiceIntentAi $voiceIntentAi,
        private readonly GestureIntentAi $gestureIntentAi,
    )
    {
    }

    #[Route('/api/ai/voice-command', name: 'app_api_ai_voice_command', methods: ['POST'])]
    public function voiceCommand(Request $request, EntityManagerInterface $em, SessionInterface $session): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['ok' => false, 'speak' => 'Invalid voice payload.'], 400);
        }

        $context = $this->normalize((string) ($payload['context'] ?? ''));
        $transcript = trim((string) ($payload['transcript'] ?? ''));

        if ($context === '' || $transcript === '') {
            return new JsonResponse(['ok' => false, 'speak' => 'Missing voice context or transcript.'], 400);
        }

        return match ($context) {
            'catalog' => $this->handleCatalogCommand($transcript, $em),
            'cart' => $this->handleCartCommand($transcript, $em, $session),
            'checkout' => $this->handleCheckoutCommand($transcript, $em, $session),
            default => new JsonResponse(['ok' => false, 'speak' => 'Unsupported voice context.'], 400),
        };
    }

    #[Route('/api/ai/gesture-command', name: 'app_api_ai_gesture_command', methods: ['POST'])]
    public function gestureCommand(Request $request, EntityManagerInterface $em, SessionInterface $session): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['ok' => false, 'speak' => 'Invalid gesture payload.'], 400);
        }

        $context = $this->normalize((string) ($payload['context'] ?? ''));
        $gesture = $this->normalize((string) ($payload['gesture'] ?? ''));
        $productId = (int) ($payload['product_id'] ?? 0);

        if ($context === '' || $gesture === '') {
            return new JsonResponse(['ok' => false, 'speak' => 'Missing gesture context or command.'], 400);
        }

        $prediction = $this->gestureIntentAi->predict($context, $gesture);
        $intent = (string) ($prediction['intent'] ?? '');

        return match ($context) {
            'catalog' => $this->handleCatalogGesture($intent, $gesture, $productId, $em),
            'cart' => $this->handleCartGesture($intent, $gesture, $productId, $session),
            'checkout' => $this->handleCheckoutGesture($intent, $gesture),
            default => new JsonResponse(['ok' => false, 'speak' => 'Unsupported gesture context.'], 400),
        };
    }

    #[Route('/api/ai/gesture-help', name: 'app_api_ai_gesture_help', methods: ['GET'])]
    public function gestureHelp(Request $request): JsonResponse
    {
        $context = $this->normalize((string) $request->query->get('context', ''));
        if ($context === '') {
            return new JsonResponse(['ok' => false, 'speak' => 'Missing gesture context.'], 400);
        }

        return new JsonResponse([
            'ok' => true,
            'context' => $context,
            'items' => $this->gestureIntentAi->gestureGuide($context),
        ]);
    }

    private function handleCatalogGesture(string $intent, string $gesture, int $productId, EntityManagerInterface $em): JsonResponse
    {
        if ($intent === 'catalog_read_products') {
            return $this->ok('Lecture des produits visibles.', [
                ['type' => 'read_visible_products'],
            ]);
        }

        if ($intent === 'catalog_open_cart' || $gesture === 'open_palm') {
            return $this->ok('Ouverture du panier.', [
                ['type' => 'navigate', 'url' => $this->generateUrl('app_panier_index')],
            ]);
        }

        if ($productId <= 0) {
            return $this->ok('Aucun produit cible detecte.');
        }

        $product = $em->getRepository(Produit::class)->find($productId);
        if (!$product instanceof Produit) {
            return $this->ok('Produit introuvable.');
        }

        if (str_starts_with($intent, 'catalog_details_') || in_array($gesture, ['point', 'index', 'one_finger', 'two_fingers', 'three_fingers', 'four_fingers', 'five_fingers', 'victory'], true)) {
            return $this->ok(sprintf('Details ouverts pour %s.', (string) $product->getNom()), [
                ['type' => 'open_product_details', 'product_id' => (int) $product->getIdProduit()],
            ]);
        }

        if ($intent === 'catalog_add' || $gesture === 'thumb_up') {
            return $this->ok(sprintf('%s ajoute au panier.', (string) $product->getNom()), [
                ['type' => 'add_to_cart', 'product_id' => (int) $product->getIdProduit()],
            ]);
        }

        return $this->ok('Geste catalogue non reconnu.');
    }

    private function handleCartGesture(string $intent, string $gesture, int $productId, SessionInterface $session): JsonResponse
    {
        if ($intent === 'cart_clear' || $gesture === 'fist') {
            return $this->ok('Demande de vidage du panier envoyee.', [
                ['type' => 'clear_cart'],
            ]);
        }

        if ($intent === 'cart_checkout' || $gesture === 'open_palm') {
            return $this->ok('Ouverture de la commande.', [
                ['type' => 'navigate', 'url' => $this->generateUrl('app_commande_create')],
            ]);
        }

        if ($intent === 'cart_read' || $gesture === 'victory') {
            return $this->ok('Lecture du recapitulatif panier.', [
                ['type' => 'read_cart_summary'],
            ]);
        }

        if ($productId <= 0) {
            return $this->ok('Aucun produit cible detecte dans le panier.');
        }

        if ($intent === 'cart_increase' || $gesture === 'thumb_up') {
            return $this->ok('Quantite augmentee.', [
                ['type' => 'update_cart_quantity_delta', 'product_id' => $productId, 'delta' => 1],
            ]);
        }

        if ($intent === 'cart_decrease' || $gesture === 'thumb_down') {
            return $this->ok('Quantite diminuee.', [
                ['type' => 'update_cart_quantity_delta', 'product_id' => $productId, 'delta' => -1],
            ]);
        }

        return $this->ok('Geste panier non reconnu.');
    }

    private function handleCheckoutGesture(string $intent, string $gesture): JsonResponse
    {
        if ($intent === 'checkout_focus_next' || $gesture === 'point') {
            return $this->ok('Selection du champ suivant.', [
                ['type' => 'focus_next_checkout_field'],
            ]);
        }

        if ($intent === 'checkout_promo_generate' || $gesture === 'open_palm') {
            return $this->ok('Generation du code promo en cours.', [
                ['type' => 'submit_checkout_action', 'value' => 'generate_promo'],
            ]);
        }

        if ($intent === 'checkout_promo_apply' || $gesture === 'fist') {
            return $this->ok('Application du code promo en cours.', [
                ['type' => 'submit_checkout_action', 'value' => 'apply_promo'],
            ]);
        }

        if ($intent === 'checkout_confirm' || $gesture === 'thumb_up') {
            return $this->ok('Confirmation de commande en cours.', [
                ['type' => 'click_confirm_order'],
            ]);
        }

        if ($intent === 'checkout_read_summary' || $gesture === 'victory') {
            return $this->ok('Lecture du recapitulatif commande.', [
                ['type' => 'read_checkout_summary'],
            ]);
        }

        return $this->ok('Geste checkout non reconnu.');
    }

    private function handleCatalogCommand(string $transcript, EntityManagerInterface $em): JsonResponse
    {
        $text = $this->normalize($transcript);
        $prediction = $this->voiceIntentAi->predict($transcript);
        $intent = (string) ($prediction['intent'] ?? '');

        if ($intent === 'help' || $this->containsAny($text, ['aide', 'help'])) {
            return $this->ok('Commandes disponibles. Chercher nom du produit. Lire produits. Details nom du produit. Ajouter nom du produit. Commander nom du produit. Ouvrir panier.');
        }

        if ($intent === 'catalog_read_products' || $this->containsAny($text, ['lire produits', 'liste produits'])) {
            return $this->ok('Je lis les produits visibles.', [
                ['type' => 'read_visible_products'],
            ]);
        }

        if ($intent === 'catalog_search' || str_starts_with($text, 'chercher ')) {
            $query = trim($this->extractAfterKeyword($text, ['chercher', 'cherche', 'rechercher', 'recherche']));
            if ($query === '') {
                $query = trim($this->extractProductNeedle($text));
            }
            if ($query === '') {
                return $this->ok('Precisez le produit a chercher.');
            }

            return $this->ok(sprintf('Recherche en cours pour %s.', $query), [
                ['type' => 'filter_products', 'query' => $query],
            ]);
        }

        if ($intent === 'catalog_details' || str_starts_with($text, 'details ')) {
            $needle = trim($this->extractAfterKeyword($text, ['details', 'detail', 'ouvrir details', 'afficher details']));
            $product = $this->findAvailableProductByName($needle, $em);
            if (!$product instanceof Produit) {
                return $this->ok('Produit introuvable pour afficher les details.');
            }

            return $this->ok(sprintf('Details ouverts pour %s.', (string) $product->getNom()), [
                ['type' => 'open_product_details', 'product_id' => (int) $product->getIdProduit()],
            ]);
        }

        if ($intent === 'catalog_add' || str_starts_with($text, 'ajouter ')) {
            $needle = trim($this->extractAfterKeyword($text, ['ajouter', 'ajoute', 'mettre']));
            $product = $this->findAvailableProductByName($needle, $em);
            if (!$product instanceof Produit) {
                return $this->ok('Produit introuvable pour ajout au panier.');
            }

            return $this->ok(sprintf('%s ajoute au panier.', (string) $product->getNom()), [
                ['type' => 'add_to_cart', 'product_id' => (int) $product->getIdProduit()],
            ]);
        }

        if (
            $intent === 'catalog_order'
            || $this->containsAny($text, ['commander', 'acheter', 'commande rapide', 'passe commande'])
        ) {
            $needle = trim($this->extractProductNeedle($text));
            $product = $this->findAvailableProductByName($needle, $em);
            if (!$product instanceof Produit) {
                return $this->ok('Produit non trouve dans la base de donnees.');
            }

            return $this->ok(sprintf('%s trouve. Ajout au panier puis ouverture de la commande.', (string) $product->getNom()), [
                ['type' => 'add_to_cart_then_checkout', 'product_id' => (int) $product->getIdProduit()],
            ]);
        }

        if ($intent === 'catalog_open_cart' || $this->containsAny($text, ['ouvrir panier', 'aller panier']) || $text === 'panier') {
            return $this->ok('Ouverture du panier.', [
                ['type' => 'navigate', 'url' => $this->generateUrl('app_panier_index')],
            ]);
        }

        return $this->ok('Commande non reconnue. Dites aide pour entendre les commandes.');
    }

    private function handleCartCommand(string $transcript, EntityManagerInterface $em, SessionInterface $session): JsonResponse
    {
        $text = $this->normalize($transcript);
        $prediction = $this->voiceIntentAi->predict($transcript);
        $intent = (string) ($prediction['intent'] ?? '');

        if ($intent === 'help' || $this->containsAny($text, ['aide', 'help'])) {
            return $this->ok('Commandes disponibles. Lire panier. Quantite suivi du nombre, ou quantite nom produit nombre. Augmenter ou diminuer quantite. Supprimer nom du produit. Vider panier. Passer commande.');
        }

        if ($intent === 'cart_read' || $this->containsAny($text, ['lire panier'])) {
            return $this->ok('Lecture du recapitulatif panier.', [
                ['type' => 'read_cart_summary'],
            ]);
        }

        $spokenQuantity = $this->extractSpokenInteger($text);
        if ($this->containsAny($text, ['quantite']) && $spokenQuantity !== null) {
            $quantity = max(0, $spokenQuantity);

            $needleRaw = trim($this->extractAfterKeyword($text, ['quantite de', 'quantite du', 'quantite de la', 'quantite de l', 'quantite', 'mettre quantite', 'fixer quantite']));
            $needle = $this->cleanCartNeedle($needleRaw);

            $product = $needle !== ''
                ? $this->findProductInCartByName($needle, $em, $session)
                : $this->findSingleProductInCart($em, $session);

            if (!$product instanceof Produit) {
                return $this->ok('Precisez le nom du produit pour modifier la quantite. Exemple: quantite pomme 2.');
            }

            return $this->ok(sprintf('Quantite de %s reglee a %d.', (string) $product->getNom(), $quantity), [
                ['type' => 'update_cart_quantity_set', 'product_id' => (int) $product->getIdProduit(), 'quantity' => $quantity],
            ]);
        }

        if ($intent === 'cart_increase' || $this->containsAny($text, ['augmenter', 'augmente', 'plus', 'incremente'])) {
            $needle = $this->cleanCartNeedle($this->extractAfterKeyword($text, ['augmenter', 'augmente', 'plus', 'ajouter quantite', 'incremente']));
            $product = $needle !== ''
                ? $this->findProductInCartByName($needle, $em, $session)
                : $this->findSingleProductInCart($em, $session);
            if (!$product instanceof Produit) {
                return $this->ok('Produit introuvable dans le panier.');
            }

            return $this->ok('Quantite augmentee.', [
                ['type' => 'update_cart_quantity_delta', 'product_id' => (int) $product->getIdProduit(), 'delta' => 1],
            ]);
        }

        if ($intent === 'cart_decrease' || $this->containsAny($text, ['diminuer', 'diminue', 'reduire', 'moins', 'decrementer'])) {
            $needle = $this->cleanCartNeedle($this->extractAfterKeyword($text, ['diminuer', 'diminue', 'reduire', 'moins', 'decrementer']));
            $product = $needle !== ''
                ? $this->findProductInCartByName($needle, $em, $session)
                : $this->findSingleProductInCart($em, $session);
            if (!$product instanceof Produit) {
                return $this->ok('Produit introuvable dans le panier.');
            }

            return $this->ok('Quantite reduite.', [
                ['type' => 'update_cart_quantity_delta', 'product_id' => (int) $product->getIdProduit(), 'delta' => -1],
            ]);
        }

        if ($intent === 'cart_remove' || str_starts_with($text, 'supprimer ')) {
            $needle = $this->cleanCartNeedle($this->extractAfterKeyword($text, ['supprimer', 'retirer', 'enlever']));
            $product = $needle !== ''
                ? $this->findProductInCartByName($needle, $em, $session)
                : $this->findSingleProductInCart($em, $session);
            if (!$product instanceof Produit) {
                return $this->ok('Produit introuvable dans le panier.');
            }

            return $this->ok('Produit supprime du panier.', [
                ['type' => 'remove_from_cart', 'product_id' => (int) $product->getIdProduit()],
            ]);
        }

        if ($intent === 'cart_clear' || $this->containsAny($text, ['vider panier', 'vider le panier', 'vider mon panier', 'panier vide'])) {
            return $this->ok('Demande de vidage du panier envoyee.', [
                ['type' => 'clear_cart'],
            ]);
        }

        if ($intent === 'cart_checkout' || $this->containsAny($text, ['passer commande', 'passer la commande', 'je passe commande', 'commander'])) {
            return $this->ok('Ouverture de la page commande.', [
                ['type' => 'navigate', 'url' => $this->generateUrl('app_commande_create')],
            ]);
        }

        return $this->ok('Commande non reconnue. Dites aide pour la liste des commandes vocales.');
    }

    private function handleCheckoutCommand(string $transcript, EntityManagerInterface $em, SessionInterface $session): JsonResponse
    {
        $text = $this->normalize($transcript);
        $prediction = $this->voiceIntentAi->predict($transcript);
        $intent = (string) ($prediction['intent'] ?? '');

        if ($intent === 'help' || $this->containsAny($text, ['aide', 'help'])) {
            return $this->ok('Commandes disponibles. Nom suivi de votre nom. Adresse suivi de votre adresse. Paiement cash, paiement carte, paiement virement. Generer promo. Appliquer promo suivi du code. Augmenter ou diminuer nom du produit. Lire recap. Confirmer commande.');
        }

        if ($this->containsAny($text, ['guide checkout', 'guide commande', 'etapes commande', 'que dois je remplir', 'quoi remplir'])) {
            return $this->ok('Etapes checkout. Dites nom suivi du nom client. Puis adresse suivi de votre adresse. Puis paiement cash, carte ou virement. Ensuite dites generer promo. Puis dites utiliser ce code promo. Enfin dites confirmer commande.');
        }

        if ($intent === 'cart_read' || $this->containsAny($text, ['lire panier'])) {
            $panier = $session->get('panier', []);
            $count = is_array($panier) ? array_sum($panier) : 0;

            return $this->ok(sprintf('Votre panier contient %d article(s).', (int) $count), [
                ['type' => 'reload_page'],
            ]);
        }

        if ($intent === 'cart_increase' || str_starts_with($text, 'augmenter ')) {
            $needle = trim($this->extractAfterKeyword($text, ['augmenter', 'plus', 'ajouter quantite', 'incremente']));
            if ($needle === '') {
                $needle = trim($this->extractProductNeedle($text));
            }

            $product = $this->findProductInCartByName($needle, $em, $session);
            if (!$product instanceof Produit) {
                return $this->ok('Produit introuvable dans le panier.');
            }

            $result = $this->updateCartProductQuantity((int) $product->getIdProduit(), 1, $session, $product);

            return $this->ok($result['message'], [['type' => 'reload_page']]);
        }

        if ($intent === 'cart_decrease' || str_starts_with($text, 'diminuer ')) {
            $needle = trim($this->extractAfterKeyword($text, ['diminuer', 'reduire', 'moins', 'decrementer']));
            if ($needle === '') {
                $needle = trim($this->extractProductNeedle($text));
            }

            $product = $this->findProductInCartByName($needle, $em, $session);
            if (!$product instanceof Produit) {
                return $this->ok('Produit introuvable dans le panier.');
            }

            $result = $this->updateCartProductQuantity((int) $product->getIdProduit(), -1, $session, $product);

            return $this->ok($result['message'], [['type' => 'reload_page']]);
        }

        if ($intent === 'checkout_name' || preg_match('/^nom\s+(.+)$/iu', $transcript, $matches) === 1) {
            $value = isset($matches[1]) ? trim((string) $matches[1]) : trim($this->extractAfterKeyword($transcript, ['nom', 'mon nom est', 'saisir nom']));
            return $this->ok('Nom client mis a jour.', [
                ['type' => 'set_field', 'field_id' => 'nom_client', 'value' => $value],
            ]);
        }

        if ($intent === 'checkout_address' || preg_match('/^adresse\s+(.+)$/iu', $transcript, $matches) === 1) {
            $value = isset($matches[1]) ? trim((string) $matches[1]) : trim($this->extractAfterKeyword($transcript, ['adresse', 'mon adresse est', 'saisir adresse']));
            return $this->ok('Adresse de livraison mise a jour.', [
                ['type' => 'set_field', 'field_id' => 'adresse_livraison', 'value' => $value],
            ]);
        }

        if ($intent === 'checkout_payment_cash' || $this->containsAny($text, ['paiement cash', 'paiement espece'])) {
            return $this->ok('Mode de paiement Cash selectionne.', [
                ['type' => 'set_payment_mode', 'value' => 'Cash'],
            ]);
        }

        if ($intent === 'checkout_payment_card' || $this->containsAny($text, ['paiement carte'])) {
            return $this->ok('Mode de paiement carte bancaire selectionne.', [
                ['type' => 'set_payment_mode', 'value' => 'Carte bancaire'],
            ]);
        }

        if ($intent === 'checkout_payment_transfer' || $this->containsAny($text, ['paiement virement'])) {
            return $this->ok('Mode de paiement virement selectionne.', [
                ['type' => 'set_payment_mode', 'value' => 'Virement'],
            ]);
        }

        if ($intent === 'checkout_promo_generate' || $this->containsAny($text, ['generer promo'])) {
            return $this->ok('Generation du code promo en cours.', [
                ['type' => 'submit_checkout_action', 'value' => 'generate_promo'],
            ]);
        }

        if ($this->containsAny($text, ['utiliser ce code promo', 'appliquer ce code promo', 'utilise ce code promo', 'appliquer ce code', 'utilise ce code'])) {
            return $this->ok('Application du code promo genere en cours.', [
                ['type' => 'apply_generated_promo'],
            ]);
        }

        if ($intent === 'checkout_promo_apply' || preg_match('/^appliquer\s+promo\s+(.+)$/iu', $transcript, $matches) === 1) {
            $value = isset($matches[1]) ? trim((string) $matches[1]) : trim($this->extractAfterKeyword($transcript, ['appliquer promo', 'utiliser code promo', 'mettre promo']));
            return $this->ok('Application du code promo en cours.', [
                ['type' => 'set_field', 'field_id' => 'promo_code', 'value' => $value],
                ['type' => 'submit_checkout_action', 'value' => 'apply_promo'],
            ]);
        }

        if ($intent === 'checkout_read_summary' || $this->containsAny($text, ['lire recap', 'lire total'])) {
            return $this->ok('Lecture du recapitulatif commande.', [
                ['type' => 'read_checkout_summary'],
            ]);
        }

        if ($intent === 'checkout_confirm' || $this->containsAny($text, ['confirmer commande'])) {
            return $this->ok('Confirmation de commande en cours.', [
                ['type' => 'click_confirm_order'],
            ]);
        }

        if ($intent === 'checkout_back_cart' || $this->containsAny($text, ['retour panier'])) {
            return $this->ok('Retour au panier.', [
                ['type' => 'navigate', 'url' => $this->generateUrl('app_panier_index')],
            ]);
        }

        return $this->ok('Commande non reconnue. Dites aide pour les commandes vocales.');
    }

    /**
     * @return array{ok:bool,message:string}
     */
    private function updateCartProductQuantity(int $productId, int $delta, SessionInterface $session, Produit $product): array
    {
        $panier = $session->get('panier', []);
        if (!is_array($panier) || !isset($panier[$productId])) {
            return ['ok' => false, 'message' => 'Produit non present dans le panier.'];
        }

        $currentQty = max(0, (int) $panier[$productId]);
        $newQty = $currentQty + $delta;

        if ($newQty <= 0) {
            unset($panier[$productId]);
            $session->set('panier', $panier);

            return ['ok' => true, 'message' => sprintf('%s retire du panier.', (string) $product->getNom())];
        }

        $available = (int) ($product->getQuantiteStock() ?? 0);
        if ($newQty > $available) {
            return [
                'ok' => false,
                'message' => sprintf('Stock insuffisant pour %s. Stock disponible %d.', (string) $product->getNom(), $available),
            ];
        }

        $panier[$productId] = $newQty;
        $session->set('panier', $panier);

        return ['ok' => true, 'message' => sprintf('Quantite de %s mise a %d.', (string) $product->getNom(), $newQty)];
    }

    private function findAvailableProductByName(string $needle, EntityManagerInterface $em): ?Produit
    {
        $target = $this->normalize($needle);
        if ($target === '') {
            return null;
        }

        $repository = $em->getRepository(Produit::class);
        $products = $repository->findBy(['statut' => 'Disponible']);
        if ($products === []) {
            // Fallback for datasets where status values differ by case or vocabulary.
            $products = $repository->findAll();
        }

        if ($products === []) {
            return null;
        }

        $best = null;
        $bestScore = 0.0;

        foreach ($products as $product) {
            $name = $this->normalize((string) $product->getNom());
            if ($name === '') {
                continue;
            }

            $score = $this->scoreProductMatch($target, $name);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $product;
            }
        }

        // Guardrail to avoid false positives on unrelated speech.
        return $bestScore >= 0.38 ? $best : null;
    }

    private function findProductInCartByName(string $needle, EntityManagerInterface $em, SessionInterface $session): ?Produit
    {
        $target = $this->normalize($needle);
        if ($target === '') {
            return null;
        }

        $panier = $session->get('panier', []);
        if (!is_array($panier) || $panier === []) {
            return null;
        }

        $ids = array_map(static fn ($id): int => (int) $id, array_keys($panier));
        $products = $em->getRepository(Produit::class)->findBy(['id_produit' => $ids]);

        foreach ($products as $product) {
            $name = $this->normalize((string) $product->getNom());
            if ($name !== '' && str_contains($name, $target)) {
                return $product;
            }
        }

        return null;
    }

    private function findSingleProductInCart(EntityManagerInterface $em, SessionInterface $session): ?Produit
    {
        $panier = $session->get('panier', []);
        if (!is_array($panier) || count($panier) !== 1) {
            return null;
        }

        $id = (int) array_key_first($panier);
        if ($id <= 0) {
            return null;
        }

        return $em->getRepository(Produit::class)->find($id);
    }

    private function cleanCartNeedle(string $needle): string
    {
        $value = $this->normalize($needle);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\b(la|le|les|du|de|des|d|mon|ma|mes|produit|article|quantite|panier|a|au|aux)\b/u', ' ', $value) ?? $value;
        $value = preg_replace('/\b\d{1,4}\b/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function extractSpokenInteger(string $text): ?int
    {
        $normalized = $this->normalize($text);

        if (preg_match('/\b(\d{1,4})\b/u', $normalized, $matches) === 1) {
            return (int) ($matches[1] ?? 0);
        }

        $map = [
            'zero' => 0,
            'un' => 1,
            'une' => 1,
            'deux' => 2,
            'trois' => 3,
            'quatre' => 4,
            'cinq' => 5,
            'six' => 6,
            'sept' => 7,
            'huit' => 8,
            'neuf' => 9,
            'dix' => 10,
        ];

        foreach ($map as $word => $value) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/u', $normalized) === 1) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param list<string> $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $this->normalize($needle))) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $text): string
    {
        $value = mb_strtolower(trim($text));
        if ($value === '') {
            return '';
        }

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_D);
            if (is_string($normalized) && $normalized !== '') {
                $value = $normalized;
            }
        }

        $value = preg_replace('/\p{Mn}+/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @param list<string> $keywords
     */
    private function extractAfterKeyword(string $text, array $keywords): string
    {
        $normalizedText = $this->normalize($text);
        foreach ($keywords as $keyword) {
            $k = $this->normalize($keyword);
            if ($k === '') {
                continue;
            }

            $pos = mb_strpos($normalizedText, $k);
            if ($pos !== false) {
                $start = $pos + mb_strlen($k);

                return trim(mb_substr($normalizedText, $start));
            }
        }

        return '';
    }

    private function extractProductNeedle(string $text): string
    {
        $normalized = $this->normalize($text);
        $candidate = $this->extractAfterKeyword($normalized, [
            'je veux commander',
            'je veux acheter',
            'je veux prendre',
            'je voudrais acheter',
            'je souhaite acheter',
            'je voudrais commander',
            'passe commande pour',
            'commander',
            'commande',
            'acheter',
            'prendre',
            'ajouter',
        ]);

        if ($candidate === '') {
            $candidate = $normalized;
        }

        // Ignore trailing justifications that often appear in natural speech.
        $candidate = preg_split('/\b(car|parce que|puisque|mais|car\s+c\'?est)\b/u', $candidate, 2)[0] ?? $candidate;

        $candidate = preg_replace('/\b(un|une|des|du|de|la|le|les|au|aux|a|s’il|te|plait|svp|stp|produit|veux|voudrais|souhaite|prendre|elle|il|est|dans|ma|mon|mes|bd|base|donnees)\b/u', ' ', $candidate) ?? $candidate;
        $candidate = preg_replace('/\s+/u', ' ', $candidate) ?? $candidate;

        return trim($candidate);
    }

    private function scoreProductMatch(string $target, string $productName): float
    {
        if ($target === '' || $productName === '') {
            return 0.0;
        }

        if ($target === $productName) {
            return 1.0;
        }

        $score = 0.0;

        if (str_contains($productName, $target)) {
            $score += 0.68;
        }

        if (str_contains($target, $productName)) {
            $score += 0.52;
        }

        $targetTokens = array_values(array_filter(explode(' ', $target), static fn (string $t): bool => $t !== ''));
        $nameTokens = array_values(array_filter(explode(' ', $productName), static fn (string $t): bool => $t !== ''));

        if ($targetTokens !== [] && $nameTokens !== []) {
            $common = array_intersect($targetTokens, $nameTokens);
            $tokenCoverage = count($common) / max(1, count($nameTokens));
            $score += min(0.35, $tokenCoverage * 0.35);
        }

        $distance = levenshtein($target, $productName);
        $maxLen = max(1, mb_strlen($target), mb_strlen($productName));
        $levSimilarity = max(0.0, 1 - ($distance / $maxLen));
        $score += $levSimilarity * 0.25;

        return min(1.0, $score);
    }

    /**
     * @param array<int,array<string,mixed>> $actions
     */
    private function ok(string $speak, array $actions = []): JsonResponse
    {
        return new JsonResponse([
            'ok' => true,
            'speak' => $speak,
            'actions' => $actions,
        ]);
    }
}
