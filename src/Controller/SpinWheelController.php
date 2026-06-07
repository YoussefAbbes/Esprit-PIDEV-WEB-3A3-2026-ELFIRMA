<?php

namespace App\Controller;

use App\Entity\SpinReward;
use App\Entity\UserSpin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class SpinWheelController extends AbstractController
{
    private const DEFAULT_REWARDS = [
        ['label' => '10% OFF Order',  'type' => 'promo_code', 'codePrefix' => 'FARM10',   'discountType' => 'percent', 'discountValue' => 10.0, 'color' => '#116530', 'weight' => 15, 'description' => '10% discount on your next order'],
        ['label' => 'Free Delivery',  'type' => 'coupon',     'codePrefix' => 'FREEDEL',  'discountType' => 'fixed',   'discountValue' => 0.0,  'color' => '#e67e22', 'weight' => 10, 'description' => 'Free delivery on your next order'],
        ['label' => '15% Bio OFF',    'type' => 'promo_code', 'codePrefix' => 'BIO15',    'discountType' => 'percent', 'discountValue' => 15.0, 'color' => '#27ae60', 'weight' => 12, 'description' => '15% off Bio & Organic products'],
        ['label' => 'Better Luck!',   'type' => 'no_prize',   'codePrefix' => null,       'discountType' => 'percent', 'discountValue' => 0.0,  'color' => '#95a5a6', 'weight' => 25, 'description' => 'No prize this time'],
        ['label' => '20% Seeds',      'type' => 'promo_code', 'codePrefix' => 'SEEDS20',  'discountType' => 'percent', 'discountValue' => 20.0, 'color' => '#934b19', 'weight' => 8,  'description' => '20% off seeds & fertilizers'],
        ['label' => '5 TND OFF',      'type' => 'coupon',     'codePrefix' => 'SAVE5TND', 'discountType' => 'fixed',   'discountValue' => 5.0,  'color' => '#f39c12', 'weight' => 15, 'description' => '5 TND off your next purchase'],
        ['label' => '25% Equipment',  'type' => 'promo_code', 'codePrefix' => 'EQUIP25',  'discountType' => 'percent', 'discountValue' => 25.0, 'color' => '#2980b9', 'weight' => 5,  'description' => '25% off farm equipment'],
        ['label' => 'Try Again!',     'type' => 'no_prize',   'codePrefix' => null,       'discountType' => 'percent', 'discountValue' => 0.0,  'color' => '#bdc3c7', 'weight' => 10, 'description' => 'No prize this time'],
    ];

    #[Route('/api/spin/check', name: 'api_spin_check', methods: ['GET'])]
    public function check(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $session = $request->getSession();
        $userId = $session->get('user_id');
        $role   = $session->get('user_role');

        if (!$userId || $role !== 'client') {
            return new JsonResponse(['show' => false]);
        }

        if (!$session->get('spin_wheel_eligible')) {
            return new JsonResponse(['show' => false]);
        }

        // Clear the flag — one chance per login
        $session->remove('spin_wheel_eligible');

        $rewards = $this->getOrSeedRewards($em);

        $segments = array_map(fn(SpinReward $r) => [
            'id'          => $r->getId(),
            'label'       => $r->getLabel(),
            'color'       => $r->getColor(),
            'type'        => $r->getType(),
        ], $rewards);

        return new JsonResponse(['show' => true, 'segments' => $segments]);
    }

    #[Route('/api/spin/do', name: 'api_spin_do', methods: ['POST'])]
    public function spin(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $session = $request->getSession();
        $userId = $session->get('user_id');
        $role   = $session->get('user_role');

        if (!$userId || $role !== 'client') {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $rewards = $this->getOrSeedRewards($em);
        $reward  = $this->pickWeightedReward($rewards);

        $code = null;
        if ($reward->getType() !== 'no_prize' && $reward->getCodePrefix()) {
            $code = strtoupper($reward->getCodePrefix()) . '-' . strtoupper(bin2hex(random_bytes(4)));
        }

        $userSpin = new UserSpin();
        $userSpin->setUtilisateurId((int) $userId);
        $userSpin->setSpinReward($reward);
        $userSpin->setGeneratedCode($code);
        $em->persist($userSpin);
        $em->flush();

        return new JsonResponse([
            'rewardId'      => $reward->getId(),
            'label'         => $reward->getLabel(),
            'type'          => $reward->getType(),
            'code'          => $code,
            'discountType'  => $reward->getDiscountType(),
            'discountValue' => $reward->getDiscountValue(),
            'description'   => $reward->getDescription(),
            'color'         => $reward->getColor(),
        ]);
    }

    #[Route('/api/spin/my-rewards', name: 'api_spin_my_rewards', methods: ['GET'])]
    public function myRewards(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $userId = $request->getSession()->get('user_id');
        if (!$userId) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        $spins = $em->getRepository(UserSpin::class)->findBy(
            ['utilisateurId' => (int) $userId],
            ['spunAt' => 'DESC'],
            10
        );

        $data = array_map(function (UserSpin $s) {
            $r = $s->getSpinReward();
            return [
                'code'        => $s->getGeneratedCode(),
                'label'       => $r ? $r->getLabel() : 'No prize',
                'type'        => $r ? $r->getType() : 'no_prize',
                'isUsed'      => $s->isUsed(),
                'spunAt'      => $s->getSpunAt()->format('Y-m-d H:i'),
            ];
        }, $spins);

        return new JsonResponse($data);
    }

    /** @return SpinReward[] */
    private function getOrSeedRewards(EntityManagerInterface $em): array
    {
        $repo    = $em->getRepository(SpinReward::class);
        $rewards = $repo->findBy(['isActive' => true], ['id' => 'ASC']);

        if (count($rewards) === 0) {
            foreach (self::DEFAULT_REWARDS as $def) {
                $r = new SpinReward();
                $r->setLabel($def['label']);
                $r->setType($def['type']);
                $r->setCodePrefix($def['codePrefix']);
                $r->setDiscountType($def['discountType']);
                $r->setDiscountValue($def['discountValue']);
                $r->setColor($def['color']);
                $r->setProbabilityWeight($def['weight']);
                $r->setDescription($def['description']);
                $em->persist($r);
            }
            $em->flush();
            $rewards = $repo->findBy(['isActive' => true], ['id' => 'ASC']);
        }

        return $rewards;
    }

    /** @param SpinReward[] $rewards */
    private function pickWeightedReward(array $rewards): SpinReward
    {
        $totalWeight = array_sum(array_map(fn(SpinReward $r) => $r->getProbabilityWeight(), $rewards));
        $rand = mt_rand(1, $totalWeight);
        $cumulative = 0;
        foreach ($rewards as $reward) {
            $cumulative += $reward->getProbabilityWeight();
            if ($rand <= $cumulative) {
                return $reward;
            }
        }
        return $rewards[0];
    }
}
