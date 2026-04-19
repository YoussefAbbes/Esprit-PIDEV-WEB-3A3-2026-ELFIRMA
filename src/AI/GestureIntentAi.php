<?php

namespace App\AI;

final class GestureIntentAi
{
    private const MODEL_FILE = '/var/ai/gesture_intent_model.json';
    private const MODEL_VERSION = 2;

    /** @var array<string,IntentModel> */
    private array $models = [];

    public function __construct(private readonly string $projectDir)
    {
    }

    /**
     * @return array{intent:string,confidence:float,scores:array<string,float>}
     */
    public function predict(string $context, string $gestureText): array
    {
        $ctx = $this->normalize($context);
        $model = $this->loadModel($ctx);

        return $model->predict($gestureText);
    }

    /**
     * @return array{trained_at:string,examples:int,classes:int,model_path:string}
     */
    public function trainAndSave(): array
    {
        $all = $this->trainingDataset();
        $payload = [
            'version' => self::MODEL_VERSION,
            'models' => [],
        ];
        $examples = 0;
        $classes = 0;

        foreach ($all as $context => $dataset) {
            $model = IntentModel::train($dataset);
            $this->models[$context] = $model;
            $payload['models'][$context] = $model->toArray();
            $classes += count($dataset);

            foreach ($dataset as $rows) {
                $examples += count($rows);
            }
        }

        $path = $this->modelPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return [
            'trained_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'examples' => $examples,
            'classes' => $classes,
            'model_path' => $path,
        ];
    }

    /**
     * @return list<array{gesture:string,title:string,description:string}>
     */
    public function gestureGuide(string $context): array
    {
        $ctx = $this->normalize($context);

        return match ($ctx) {
            'catalog' => [
                ['gesture' => 'open_palm', 'title' => 'Main ouverte', 'description' => 'Aller au panier'],
                ['gesture' => 'one_finger', 'title' => '1 doigt', 'description' => 'Détails du premier produit'],
                ['gesture' => 'two_fingers', 'title' => '2 doigts', 'description' => 'Détails du deuxième produit'],
                ['gesture' => 'three_fingers', 'title' => '3 doigts', 'description' => 'Détails du troisième produit'],
                ['gesture' => 'four_fingers', 'title' => '4 doigts', 'description' => 'Détails du quatrième produit'],
                ['gesture' => 'five_fingers', 'title' => '5 doigts', 'description' => 'Détails du cinquième produit'],
                ['gesture' => 'thumb_up', 'title' => 'Pouce haut', 'description' => 'Ajouter le produit au panier'],
            ],
            'cart' => [
                ['gesture' => 'open_palm', 'title' => 'Main ouverte', 'description' => 'Passer a la commande'],
                ['gesture' => 'thumb_up', 'title' => 'Pouce haut', 'description' => 'Augmenter la quantite'],
                ['gesture' => 'thumb_down', 'title' => 'Pouce bas', 'description' => 'Diminuer la quantite'],
                ['gesture' => 'fist', 'title' => 'Poing', 'description' => 'Vider le panier'],
                ['gesture' => 'victory', 'title' => 'V signe', 'description' => 'Lire le recapitulatif panier'],
            ],
            'checkout' => [
                ['gesture' => 'wave', 'title' => 'Vague', 'description' => 'Passer au champ suivant'],
                ['gesture' => 'open_palm', 'title' => 'Main ouverte', 'description' => 'Generer code promo'],
                ['gesture' => 'fist', 'title' => 'Poing', 'description' => 'Appliquer code promo'],
                ['gesture' => 'thumb_up', 'title' => 'Pouce haut', 'description' => 'Confirmer la commande'],
                ['gesture' => 'victory', 'title' => 'V signe', 'description' => 'Lire le recapitulatif commande'],
            ],
            default => [],
        };
    }

    private function loadModel(string $context): IntentModel
    {
        if (isset($this->models[$context])) {
            return $this->models[$context];
        }

        $path = $this->modelPath();
        if (is_file($path)) {
            $raw = file_get_contents($path);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($data)
                && isset($data['version'], $data['models'])
                && is_int($data['version'])
                && is_array($data['models'])
                && $data['version'] === self::MODEL_VERSION
                && isset($data['models'][$context])
                && is_array($data['models'][$context])
            ) {
                $ctxData = $data['models'][$context];
                if (isset($ctxData['priors'], $ctxData['logLikelihoods'], $ctxData['classTotals'], $ctxData['vocabulary'], $ctxData['classes'])) {
                    $this->models[$context] = IntentModel::fromArray($ctxData);
                    return $this->models[$context];
                }
            }
        }

        $this->trainAndSave();

        return $this->models[$context] ?? IntentModel::train($this->trainingDatasetForContext($context));
    }

    private function modelPath(): string
    {
        return $this->projectDir . self::MODEL_FILE;
    }

    /**
     * @return array<string,array<string,list<string>>>
     */
    private function trainingDataset(): array
    {
        return [
            'catalog' => [
                'catalog_open_cart' => ['open_palm', 'main ouverte', 'ouvrir panier', 'aller panier'],
                'catalog_details_1' => ['one_finger', '1 doigt', 'details premier produit', 'premier'],
                'catalog_details_2' => ['two_fingers', '2 doigts', 'details deuxieme produit', 'deuxieme'],
                'catalog_details_3' => ['three_fingers', '3 doigts', 'details troisieme produit', 'troisieme'],
                'catalog_details_4' => ['four_fingers', '4 doigts', 'details quatrieme produit', 'quatrieme'],
                'catalog_details_5' => ['five_fingers', '5 doigts', 'details cinquieme produit', 'cinquieme'],
                'catalog_add' => ['thumb_up', 'pouce haut', 'ajouter panier', 'ajouter produit'],
            ],
            'cart' => [
                'cart_clear' => ['fist', 'poing', 'vider panier'],
                'cart_checkout' => ['open_palm', 'main ouverte', 'passer commande', 'aller commande'],
                'cart_read' => ['victory', 'v signe', 'lire panier', 'recap panier'],
                'cart_increase' => ['thumb_up', 'pouce haut', 'augmenter quantite', 'plus un'],
                'cart_decrease' => ['thumb_down', 'pouce bas', 'diminuer quantite', 'moins un'],
            ],
            'checkout' => [
                'checkout_focus_next' => ['wave', 'vague', 'champ suivant', 'suivant'],
                'checkout_promo_generate' => ['open_palm', 'main ouverte', 'generer promo'],
                'checkout_promo_apply' => ['fist', 'poing', 'appliquer promo'],
                'checkout_confirm' => ['thumb_up', 'pouce haut', 'confirmer commande'],
                'checkout_read_summary' => ['victory', 'v signe', 'lire recap', 'total commande'],
            ],
        ];
    }

    /**
     * @return array<string,list<string>>
     */
    private function trainingDatasetForContext(string $context): array
    {
        $all = $this->trainingDataset();

        return $all[$context] ?? ['unknown' => ['unknown']];
    }

    private function normalize(string $value): string
    {
        return strtolower(trim($value));
    }
}
