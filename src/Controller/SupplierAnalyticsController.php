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
    private const FASTAPI_URL = 'http://localhost:8002/analyze';

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

            $aiData = $response->toArray();
            
            // Transform AI response from chatbot_ai to expected template format
            $results = [];
            if (isset($aiData['results']) && is_array($aiData['results'])) {
                foreach ($aiData['results'] as $supplier) {
                    // Convert sentiment_breakdown from {Positive, Neutral, Negative} to {positive%, neutral%, negative%}
                    $sentiments = $supplier['sentiment_breakdown'] ?? [];
                    $total_senti = array_sum($sentiments);
                    $normalizedComplaints = $this->normalizeComplaints($supplier['top_complaints'] ?? null);

                    if (empty($normalizedComplaints)) {
                        $normalizedComplaints = $this->buildDerivedComplaints(
                            (float) ($supplier['avg_stars'] ?? 0),
                            (int) ($supplier['total_reviews'] ?? 0),
                            $sentiments,
                            $supplier['top_keywords'] ?? []
                        );
                    }
                    
                    $results[] = [
                        'supplier_id'    => $supplier['supplier_id'] ?? 0,
                        'supplier_name'  => $supplier['supplier_name'] ?? 'Unknown',
                        'avg_stars'      => $supplier['avg_stars'] ?? 0,
                        'total_reviews'  => $supplier['total_reviews'] ?? 0,
                        'sentiment_breakdown' => [
                            'positive' => $total_senti > 0 ? round(($sentiments['Positive'] ?? 0) / $total_senti * 100) : 0,
                            'neutral'  => $total_senti > 0 ? round(($sentiments['Neutral'] ?? 0) / $total_senti * 100) : 0,
                            'negative' => $total_senti > 0 ? round(($sentiments['Negative'] ?? 0) / $total_senti * 100) : 0,
                        ],
                        'star_distribution' => $supplier['star_distribution'] ?? [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0],
                        'top_complaints' => $normalizedComplaints,
                        'top_keywords' => $supplier['top_keywords'] ?? [],
                        'health' => [
                            'label' => $supplier['health']['label'] ?? 'Unknown',
                            'confidence' => ($supplier['health']['confidence'] ?? 0),
                        ],
                        'recommendation' => $supplier['recommendation'] ?? 'Review supplier performance',
                    ];
                }
            }

            return $this->json(['results' => $results]);

        } catch (\Throwable $e) {
            return $this->json([
                'error' => 'AI service unavailable: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Accepts possible shapes from AI response and returns {label => count}.
     */
    private function normalizeComplaints(mixed $rawComplaints): array
    {
        if (!is_array($rawComplaints)) {
            return [];
        }

        $normalized = [];

        foreach ($rawComplaints as $key => $value) {
            if (is_string($key)) {
                $count = is_numeric($value) ? (int) $value : 1;
                if ($count > 0) {
                    $normalized[$key] = $count;
                }
                continue;
            }

            if (is_string($value) && trim($value) !== '') {
                $label = trim($value);
                $normalized[$label] = ($normalized[$label] ?? 0) + 1;
            }
        }

        arsort($normalized);

        return $normalized;
    }

    /**
     * Fallback issue extraction when top_complaints is empty.
     */
    private function buildDerivedComplaints(float $avgStars, int $totalReviews, array $sentiments, mixed $keywords): array
    {
        if ($totalReviews <= 0) {
            return [];
        }

        $derived = [];

        $negativeCount = (int) ($sentiments['Negative'] ?? 0);
        $negativePct = $totalReviews > 0 ? ($negativeCount / $totalReviews) * 100 : 0;

        if ($avgStars < 3.0) {
            $derived['Low overall satisfaction'] = max(1, (int) round((3.0 - $avgStars) * 2));
        }

        if ($negativePct >= 30) {
            $derived['Frequent negative feedback'] = max(1, (int) round($negativeCount));
        }

        $keywordList = is_array($keywords) ? $keywords : [];
        $joinedKeywords = strtolower(implode(' ', array_map('strval', $keywordList)));

        if ($joinedKeywords !== '') {
            if (preg_match('/delay|late|slow|retard|lent/', $joinedKeywords)) {
                $derived['Delivery delays'] = ($derived['Delivery delays'] ?? 0) + 1;
            }
            if (preg_match('/quality|damaged|broken|qualite|defect/', $joinedKeywords)) {
                $derived['Product quality concerns'] = ($derived['Product quality concerns'] ?? 0) + 1;
            }
            if (preg_match('/price|expensive|cost|cher/', $joinedKeywords)) {
                $derived['Pricing complaints'] = ($derived['Pricing complaints'] ?? 0) + 1;
            }
            if (preg_match('/support|response|service|communication/', $joinedKeywords)) {
                $derived['Customer service issues'] = ($derived['Customer service issues'] ?? 0) + 1;
            }
        }

        arsort($derived);

        return array_slice($derived, 0, 3, true);
    }
}