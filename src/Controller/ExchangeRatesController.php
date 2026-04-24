<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ExchangeRatesController extends AbstractController
{
    #[Route('/api/exchange-rates', name: 'api_exchange_rates')]
    public function getRates(HttpClientInterface $client): JsonResponse
    {
        $apiKey = "2ba612b20b121f0e4c5709bd";
        $url = "https://v6.exchangerate-api.com/v6/$apiKey/latest/TND";

        try {
            $response = $client->request('GET', $url);
            $data = $response->toArray();

            return $this->json($data['conversion_rates']);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Impossible de récupérer les taux'
            ], 500);
        }
    }
}