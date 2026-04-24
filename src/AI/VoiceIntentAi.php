<?php

namespace App\AI;

final class VoiceIntentAi
{
    private const MODEL_FILE = '/var/ai/voice_intent_model.json';

    private ?IntentModel $model = null;

    public function __construct(private readonly string $projectDir)
    {
    }

    /**
     * @return array{intent:string,confidence:float,scores:array<string,float>}
     */
    public function predict(string $text): array
    {
        $model = $this->loadModel();

        return $model->predict($text);
    }

    /**
     * @return array{trained_at:string,examples:int,classes:int,model_path:string}
     */
    public function trainAndSave(): array
    {
        $dataset = $this->trainingDataset();
        $model = IntentModel::train($dataset);

        $totalExamples = 0;
        foreach ($dataset as $examples) {
            $totalExamples += count($examples);
        }

        $path = $this->modelPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        file_put_contents($path, json_encode($model->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->model = $model;

        return [
            'trained_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'examples' => $totalExamples,
            'classes' => count($dataset),
            'model_path' => $path,
        ];
    }

    private function loadModel(): IntentModel
    {
        if ($this->model instanceof IntentModel) {
            return $this->model;
        }

        $path = $this->modelPath();
        if (is_file($path)) {
            $raw = file_get_contents($path);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($data) && isset($data['priors'], $data['logLikelihoods'], $data['classTotals'], $data['vocabulary'], $data['classes'])) {
                $this->model = IntentModel::fromArray($data);

                return $this->model;
            }
        }

        // Auto-train if model file is missing.
        $this->trainAndSave();

        return $this->model ?? IntentModel::train($this->trainingDataset());
    }

    private function modelPath(): string
    {
        return $this->projectDir . self::MODEL_FILE;
    }

    /**
     * @return array<string,list<string>>
     */
    private function trainingDataset(): array
    {
        return [
            'help' => [
                'aide',
                'help',
                'montre moi les commandes',
                'quelles sont les commandes',
                'je veux de l aide',
            ],
            'catalog_read_products' => [
                'lire produits',
                'liste produits',
                'lis les produits visibles',
                'dis moi les produits',
                'affiche les produits visibles',
            ],
            'catalog_search' => [
                'chercher huile',
                'cherche tomate',
                'trouver produit',
                'rechercher olive',
                'je veux chercher un produit',
            ],
            'catalog_details' => [
                'details huile',
                'ouvrir details tomate',
                'afficher details produit',
                'details produit olive',
                'voir details',
            ],
            'catalog_add' => [
                'ajouter huile',
                'ajoute tomate au panier',
                'mettre produit au panier',
                'ajouter produit',
                'je veux ajouter un article',
            ],
            'catalog_order' => [
                'commander pomme',
                'je veux commander une pomme',
                'acheter tomate',
                'je veux acheter huile',
                'commande rapide olive',
                'passe commande pour pomme',
            ],
            'catalog_open_cart' => [
                'ouvrir panier',
                'aller panier',
                'va au panier',
                'afficher panier',
                'panier',
            ],
            'cart_read' => [
                'lire panier',
                'resume panier',
                'dis moi le panier',
                'afficher recap panier',
                'quel est le total panier',
            ],
            'cart_increase' => [
                'augmenter huile',
                'ajouter quantite huile',
                'plus un sur tomate',
                'incremente le produit',
                'je veux augmenter la quantite',
            ],
            'cart_decrease' => [
                'diminuer huile',
                'reduire quantite tomate',
                'moins un produit',
                'decrementer article',
                'je veux diminuer la quantite',
            ],
            'cart_remove' => [
                'supprimer huile',
                'retirer tomate du panier',
                'enlever produit',
                'supprime article',
                'je veux supprimer cet article',
            ],
            'cart_clear' => [
                'vider panier',
                'supprimer tous les produits',
                'effacer panier',
                'je veux vider mon panier',
                'enlever tout du panier',
            ],
            'cart_checkout' => [
                'passer commande',
                'commander maintenant',
                'valider panier',
                'aller paiement',
                'je veux finaliser la commande',
            ],
            'checkout_name' => [
                'nom ali',
                'mon nom est ahmed',
                'met nom client',
                'saisir nom',
                'nom complet',
            ],
            'checkout_address' => [
                'adresse tunis',
                'mon adresse est sfax',
                'saisir adresse livraison',
                'mettre adresse',
                'adresse de livraison',
            ],
            'checkout_payment_cash' => [
                'paiement cash',
                'payer en espece',
                'mode cash',
                'je paie a la livraison',
                'choisir cash',
            ],
            'checkout_payment_card' => [
                'paiement carte',
                'payer par carte bancaire',
                'mode carte',
                'choisir carte bancaire',
                'paiement stripe carte',
            ],
            'checkout_payment_transfer' => [
                'paiement virement',
                'payer par virement',
                'mode virement',
                'choisir virement bancaire',
                'je veux virement',
            ],
            'checkout_promo_generate' => [
                'generer promo',
                'creer code promo',
                'genere un code promo',
                'je veux un code promo',
                'obtenir promo',
            ],
            'checkout_promo_apply' => [
                'appliquer promo abc',
                'utiliser code promo',
                'active le code promo',
                'applique mon code',
                'mettre promo',
            ],
            'checkout_read_summary' => [
                'lire recap',
                'lire total',
                'resume commande',
                'dis moi le total commande',
                'afficher recapitulatif commande',
            ],
            'checkout_confirm' => [
                'confirmer commande',
                'valider la commande',
                'finaliser commande',
                'confirme achat',
                'terminer commande',
            ],
            'checkout_back_cart' => [
                'retour panier',
                'revenir au panier',
                'retourner panier',
                'aller vers panier',
                'annuler et revenir panier',
            ],
        ];
    }
}
