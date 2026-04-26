<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ExchangeRatesController extends AbstractController
{
    /**
     * @var array<string, float>
     */
    private const FALLBACK_RATES = [
        'TND' => 1.0,
        'USD' => 0.347,
        'EUR' => 0.296,
        'AUD' => 0.485,
    ];

    #[Route('/api/exchange-rates', name: 'api_exchange_rates')]
    public function getRates(HttpClientInterface $client): JsonResponse
    {
        $apiKey = $_ENV['EXCHANGE_RATE_API_KEY'] ?? '2ba612b20b121f0e4c5709bd';
        $url = sprintf('https://v6.exchangerate-api.com/v6/%s/latest/TND', $apiKey);

        try {
            $response = $client->request('GET', $url, [
                'timeout' => 8,
                'max_duration' => 10,
            ]);
            $data = $response->toArray();

            if (!isset($data['conversion_rates']) || !is_array($data['conversion_rates'])) {
                return $this->json(self::FALLBACK_RATES);
            }

            return $this->json($data['conversion_rates']);
        } catch (\Throwable) {
            return $this->json(self::FALLBACK_RATES);
        }
    }
}