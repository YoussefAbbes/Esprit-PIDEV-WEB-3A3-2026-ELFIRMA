<?php

namespace App\Controller;

use App\Entity\Fournisseur;
use App\Entity\Rating;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SupplierAnalyticsController extends AbstractController
{
    private const FASTAPI_URL = 'http://localhost:8001/analyze';

    public function __construct(private HttpClientInterface $http) {}

    #[Route('/elfirma/supplier-analytics', name: 'supplier_analytics', methods: ['GET'])]
    public function index(EntityManagerInterface $em): Response
    {
        return $this->render('elfirma/supplier_analytics.html.twig');
    }

    #[Route('/api/supplier-analytics', name: 'api_supplier_analytics', methods: ['GET'])]
    public function analyze(EntityManagerInterface $em): JsonResponse
    {
        // Fetch all suppliers with their ratings
        $suppliers = $em->getRepository(Fournisseur::class)->findAll();

        if (empty($suppliers)) {
            return $this->json(['error' => 'No suppliers found'], 404);
        }

        // Build payload for Python FastAPI
        $payload = ['suppliers' => []];

        foreach ($suppliers as $supplier) {
            $ratings = $em->getRepository(Rating::class)->findBy(
                ['fournisseur' => $supplier],
                ['created_at' => 'DESC']
            );

            $ratingsData = [];
            foreach ($ratings as $rating) {
                $ratingsData[] = [
                    'stars'      => $rating->getNumberOfStars(),
                    'comment'    => $rating->getComment() ?? '',
                    'created_at' => $rating->getCreatedAt()
                        ? $rating->getCreatedAt()->format('Y-m-d H:i:s')
                        : '',
                ];
            }

            $payload['suppliers'][] = [
                'supplier_id'   => $supplier->getIdF(),
                'supplier_name' => $supplier->getTypeF(),
                'ratings'       => $ratingsData,
            ];
        }

        try {
            $response = $this->http->request('POST', self::FASTAPI_URL, [
                'json'    => $payload,
                'timeout' => 15,
            ]);

            $data = $response->toArray();
            return $this->json($data);

        } catch (\Throwable $e) {
            return $this->json([
                'error' => 'AI service unavailable: ' . $e->getMessage()
            ], 500);
        }
    }
}