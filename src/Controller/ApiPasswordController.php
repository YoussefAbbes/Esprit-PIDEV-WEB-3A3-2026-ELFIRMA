<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ApiPasswordController extends AbstractController
{
    #[Route('/api/password/suggest', name: 'app_api_password_suggest', methods: ['GET'])]
    public function suggest(HttpClientInterface $httpClient): JsonResponse
    {
        $fallbackPassword = $this->generateLocalPassword(7);

        try {
            $response = $httpClient->request('GET', 'https://passwordwolf.com/api/', [
                'query' => [
                    'length' => 7,
                    'upper' => 'on',
                    'lower' => 'on',
                    'numbers' => 'on',
                    'special' => 'on',
                    'repeat' => 1,
                ],
                'timeout' => 4,
            ]);

            $data = $response->toArray(false);

            if (is_array($data) && isset($data[0]['password']) && is_string($data[0]['password'])) {
                $candidate = trim($data[0]['password']);
                if ($this->isPasswordValidForCurrentRules($candidate)) {
                    return new JsonResponse([
                        'success' => true,
                        'password' => $candidate,
                        'source' => 'external_api',
                    ]);
                }
            }
        } catch (\Throwable) {
            // Keep silent and fall back to a local generator if external API is unavailable.
        }

        return new JsonResponse([
            'success' => true,
            'password' => $fallbackPassword,
            'source' => 'local_fallback',
        ]);
    }

    private function isPasswordValidForCurrentRules(string $password): bool
    {
        $length = strlen($password);

        if ($length < 6 || $length > 7) {
            return false;
        }

        if (!preg_match('/[A-Z]/', $password)) {
            return false;
        }

        if (!preg_match('/[a-z]/', $password)) {
            return false;
        }

        return (bool) preg_match('/\d/', $password);
    }

    private function generateLocalPassword(int $length): string
    {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $digits = '23456789';
        $special = '!@#$%';

        $required = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
        ];

        $all = $upper . $lower . $digits . $special;

        while (count($required) < $length) {
            $required[] = $all[random_int(0, strlen($all) - 1)];
        }

        shuffle($required);

        $candidate = implode('', $required);

        if (!$this->isPasswordValidForCurrentRules($candidate)) {
            return $this->generateLocalPassword($length);
        }

        return $candidate;
    }
}