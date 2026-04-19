<?php

namespace App\Service;

use App\Entity\Produit;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

final class RecommendationService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @param array<int, int|string> $cartProducts Product IDs currently in cart.
     *
     * @return array<int, Produit>
     */
    public function getRecommendationsFromCart(array $cartProducts): array
    {
        $cartIds = $this->normalizeProductIds($cartProducts);
        if ($cartIds === []) {
            return [];
        }

        $scoredProductIds = $this->findCoPurchasedProductIds($cartIds, 5);
        if ($scoredProductIds === []) {
            return [];
        }

        /** @var array<int, Produit> $products */
        $products = $this->em->getRepository(Produit::class)
            ->createQueryBuilder('p')
            ->andWhere('p.id_produit IN (:ids)')
            ->andWhere('p.statut = :status')
            ->andWhere('p.quantite_stock > 0')
            ->setParameter('ids', $scoredProductIds)
            ->setParameter('status', 'Disponible')
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($products as $product) {
            $id = $product->getIdProduit();
            if ($id !== null) {
                $byId[$id] = $product;
            }
        }

        $ordered = [];
        foreach ($scoredProductIds as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return array_slice($ordered, 0, 5);
    }

    /**
     * @param array<int, int|string> $cartProducts
     *
     * @return int[]
     */
    private function normalizeProductIds(array $cartProducts): array
    {
        $ids = [];

        foreach ($cartProducts as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * Collaborative filtering based on products found in the same basket.
     *
     * Because each product line is stored as one row in `commande`, a synthetic
     * basket key is created from customer and order metadata to emulate baskets.
     *
     * @param int[] $cartIds
     *
     * @return int[]
     */
    private function findCoPurchasedProductIds(array $cartIds, int $limit): array
    {
        $safeLimit = max(1, $limit);

        $sql = <<<'SQL'
SELECT c2.id_produit AS product_id,
       COUNT(*) AS co_purchase_count
FROM commande c1
JOIN commande c2
    ON c1.id_commande <> c2.id_commande
   AND c1.id_utilisateur <=> c2.id_utilisateur
   AND NULLIF(TRIM(c1.nom_client), '') <=> NULLIF(TRIM(c2.nom_client), '')
   AND DATE(c1.date_commande) = DATE(c2.date_commande)
   AND NULLIF(TRIM(c1.adresse_livraison), '') <=> NULLIF(TRIM(c2.adresse_livraison), '')
   AND NULLIF(TRIM(c1.mode_paiement), '') <=> NULLIF(TRIM(c2.mode_paiement), '')
WHERE c1.id_produit IN (:cartIds)
  AND c2.id_produit NOT IN (:cartIds)
GROUP BY c2.id_produit
ORDER BY co_purchase_count DESC, c2.id_produit DESC
LIMIT __LIMIT__
SQL;

        $sql = str_replace('__LIMIT__', (string) $safeLimit, $sql);

        $connection = $this->em->getConnection();
        $rows = $connection->executeQuery(
            $sql,
            [
                'cartIds' => $cartIds,
            ],
            [
                'cartIds' => ArrayParameterType::INTEGER,
            ]
        )->fetchAllAssociative();

        $productIds = [];
        foreach ($rows as $row) {
            $id = (int) ($row['product_id'] ?? 0);
            if ($id > 0) {
                $productIds[] = $id;
            }
        }

        return $productIds;
    }
}
