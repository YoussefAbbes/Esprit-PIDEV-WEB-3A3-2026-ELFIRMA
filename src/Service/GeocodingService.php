<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeocodingService
{
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;

    public function __construct(HttpClientInterface $httpClient, LoggerInterface $logger)
    {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
    }

    /**
     * Geocode an address to coordinates using OpenStreetMap Nominatim API
     * @param string $address The address to geocode (e.g., "La Marsa, Tunisia")
     * @return array ['success' => bool, 'latitude' => float, 'longitude' => float, 'display_name' => string, 'error' => string|null]
     */
    public function geocodeAddress(string $address): array
    {
        $this->logger->info('=== GEOCODING START ===');
        $this->logger->info('Address to geocode: ' . $address);

        try {
            if (empty(trim($address))) {
                $this->logger->error('Empty address provided');
                return [
                    'success' => false,
                    'latitude' => null,
                    'longitude' => null,
                    'display_name' => '',
                    'error' => 'Address cannot be empty'
                ];
            }

            // Use OpenStreetMap Nominatim API (free, no API key required)
            $url = 'https://nominatim.openstreetmap.org/search';
            $this->logger->info('Calling Nominatim API at ' . $url);

            $response = $this->httpClient->request('GET', $url, [
                'query' => [
                    'q' => $address,
                    'format' => 'json',
                    'limit' => 1
                ],
                'headers' => [
                    'User-Agent' => 'SymfonyElfirmaApp'
                ],
                'timeout' => 10
            ]);

            $statusCode = $response->getStatusCode();
            $this->logger->info('API Response status: ' . $statusCode);

            if ($statusCode !== 200) {
                $this->logger->error('API returned error status: ' . $statusCode);
                return [
                    'success' => false,
                    'latitude' => null,
                    'longitude' => null,
                    'display_name' => '',
                    'error' => 'Failed to geocode address'
                ];
            }

            $data = $response->toArray();
            $this->logger->info('Response received with ' . count($data) . ' result(s)');

            if (empty($data)) {
                $this->logger->warning('No results found for address: ' . $address);
                return [
                    'success' => false,
                    'latitude' => null,
                    'longitude' => null,
                    'display_name' => '',
                    'error' => 'Address not found. Please try a different location.'
                ];
            }

            // Get first result
            $result = $data[0];
            $latitude = (float)$result['lat'];
            $longitude = (float)$result['lon'];
            $displayName = $result['display_name'] ?? $address;

            $this->logger->info('Geocoding successful');
            $this->logger->info('Latitude: ' . $latitude . ', Longitude: ' . $longitude);
            $this->logger->info('Display name: ' . $displayName);
            $this->logger->info('=== GEOCODING END ===');

            return [
                'success' => true,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'display_name' => $displayName,
                'error' => null
            ];

        } catch (\Exception $e) {
            $this->logger->error('EXCEPTION in geocodeAddress:');
            $this->logger->error('  Message: ' . $e->getMessage());
            $this->logger->error('  Code: ' . $e->getCode());
            $this->logger->error('  File: ' . $e->getFile() . ':' . $e->getLine());
            $this->logger->error('=== GEOCODING END (Exception) ===');

            return [
                'success' => false,
                'latitude' => null,
                'longitude' => null,
                'display_name' => '',
                'error' => 'Error geocoding address: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Reverse geocode coordinates to get address
     * @param float $latitude
     * @param float $longitude
     * @return array ['success' => bool, 'address' => string, 'error' => string|null]
     */
    public function reverseGeocode(float $latitude, float $longitude): array
    {
        $this->logger->info('=== REVERSE GEOCODING START ===');
        $this->logger->info('Coordinates - Latitude: ' . $latitude . ', Longitude: ' . $longitude);

        try {
            $url = 'https://nominatim.openstreetmap.org/reverse';

            $response = $this->httpClient->request('GET', $url, [
                'query' => [
                    'lat' => $latitude,
                    'lon' => $longitude,
                    'format' => 'json'
                ],
                'headers' => [
                    'User-Agent' => 'SymfonyElfirmaApp'
                ],
                'timeout' => 10
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                $this->logger->error('API returned error status: ' . $statusCode);
                return [
                    'success' => false,
                    'address' => '',
                    'error' => 'Failed to reverse geocode'
                ];
            }

            $data = $response->toArray();
            $address = $data['display_name'] ?? 'Unknown location';

            $this->logger->info('Reverse geocoding successful');
            $this->logger->info('Address: ' . $address);
            $this->logger->info('=== REVERSE GEOCODING END ===');

            return [
                'success' => true,
                'address' => $address,
                'error' => null
            ];

        } catch (\Exception $e) {
            $this->logger->error('EXCEPTION in reverseGeocode: ' . $e->getMessage());
            return [
                'success' => false,
                'address' => '',
                'error' => 'Error reverse geocoding: ' . $e->getMessage()
            ];
        }
    }
}
