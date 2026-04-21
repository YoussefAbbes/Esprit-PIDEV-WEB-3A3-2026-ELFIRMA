<?php

namespace App\Service;


use Symfony\Contracts\HttpClient\HttpClientInterface;

class AIPredictionService
{
    private $client;

    public function __construct(HttpClientInterface $client)
    {
        $this->client = $client;
    }

    public function predict($data)
    {
        $response = $this->client->request('POST', 'http://127.0.0.1:8001/full-analysis', [
            'json' => $data
        ]);

        return $response->toArray();
    }
}