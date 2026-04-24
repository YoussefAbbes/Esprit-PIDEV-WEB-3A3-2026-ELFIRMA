<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ChatbotController extends AbstractController
{
    private const FASTAPI_URL = 'http://localhost:8002/chat';

    public function __construct(private HttpClientInterface $http) {}

    #[Route('/chatbot/message', name: 'chatbot_message', methods: ['POST'])]
    public function message(Request $request): JsonResponse
    {
        $body    = json_decode($request->getContent(), true);
        $message = trim($body['message'] ?? '');

        if (empty($message)) {
            return $this->json(['error' => 'Empty message'], 400);
        }

        try {
            // Forward message to Python FastAPI
            $response = $this->http->request('POST', self::FASTAPI_URL, [
                'json'    => ['message' => $message],
                'timeout' => 10,
            ]);

            $data = $response->toArray();

            return $this->json([
                'intent'     => $data['intent'],
                'response'   => $data['response'],
                'confidence' => $data['confidence'],
            ]);

        } catch (\Throwable $e) {
            return $this->json([
                'intent'   => 'error',
                'response' => 'Sorry, the AI service is unavailable. Please try again.',
                'confidence' => 0,
            ], 500);
        }
    }

    #[Route('/chatbot/users-insights', name: 'chatbot_user_insights', methods: ['POST'])]
    public function userInsights(Request $request, EntityManagerInterface $em): JsonResponse
    {
        if (!$request->getSession()->get('user_id')) {
            return $this->json([
                'matched' => true,
                'response' => 'Please sign in first to access user insights.',
                'confidence' => 1.0,
            ], 401);
        }

        $body = json_decode($request->getContent(), true);
        $message = trim((string) ($body['message'] ?? ''));

        if ($message === '') {
            return $this->json([
                'matched' => false,
                'response' => '',
                'confidence' => 0,
            ]);
        }

        $insight = $this->buildUserInsightReply($message, $em);

        return $this->json($insight);
    }

    private function buildUserInsightReply(string $message, EntityManagerInterface $em): array
    {
        $text = mb_strtolower(trim($message));
        $repo = $em->getRepository(Utilisateur::class);

        $mentionsUsers = (bool) preg_match('/\b(users?|utilisateurs?)\b/u', $text);
        $mentionsEmployees = (bool) preg_match('/\b(employee|employees|employe|employes|employés)\b/u', $text);
        $mentionsClients = (bool) preg_match('/\b(client|clients)\b/u', $text);
        $mentionsAdmins = (bool) preg_match('/\b(admin|admins|administrateur|administrateurs)\b/u', $text);

        $asksCount = (bool) preg_match('/\b(nombre|combien|total|count|how many)\b/u', $text);
        $asksNames = (bool) preg_match('/\b(nom|noms|name|names|liste|list|qui)\b/u', $text);
        $asksEmails = (bool) preg_match('/\b(email|emails|mail|mails)\b/u', $text);

        if ($mentionsUsers && $mentionsEmployees && $mentionsClients && $asksCount) {
            $employeeCount = (int) $repo->count(['role_u' => 'employee']);
            $clientCount = (int) $repo->count(['role_u' => 'client']);
            $adminCount = (int) $repo->count(['role_u' => 'admin']);
            $totalUsers = (int) $repo->count([]);

            return [
                'matched' => true,
                'response' => sprintf(
                    'User stats: total %d, employees %d, clients %d, admins %d.',
                    $totalUsers,
                    $employeeCount,
                    $clientCount,
                    $adminCount
                ),
                'confidence' => 0.98,
            ];
        }

        if ($asksCount && ($mentionsUsers || str_contains($text, 'utilisateur'))) {
            return [
                'matched' => true,
                'response' => sprintf('Total users: %d.', (int) $repo->count([])),
                'confidence' => 0.96,
            ];
        }

        if ($asksCount && $mentionsEmployees) {
            return [
                'matched' => true,
                'response' => sprintf('Total employees: %d.', (int) $repo->count(['role_u' => 'employee'])),
                'confidence' => 0.96,
            ];
        }

        if ($asksCount && $mentionsClients) {
            return [
                'matched' => true,
                'response' => sprintf('Total clients: %d.', (int) $repo->count(['role_u' => 'client'])),
                'confidence' => 0.96,
            ];
        }

        if ($asksCount && $mentionsAdmins) {
            return [
                'matched' => true,
                'response' => sprintf('Total admins: %d.', (int) $repo->count(['role_u' => 'admin'])),
                'confidence' => 0.96,
            ];
        }

        if ($asksNames) {
            $roleFilter = null;
            if ($mentionsEmployees) {
                $roleFilter = 'employee';
            } elseif ($mentionsClients) {
                $roleFilter = 'client';
            } elseif ($mentionsAdmins) {
                $roleFilter = 'admin';
            }

            $users = $roleFilter
                ? $repo->findBy(['role_u' => $roleFilter], ['nom_u' => 'ASC'], 15)
                : $repo->findBy([], ['nom_u' => 'ASC'], 15);

            if (count($users) === 0) {
                return [
                    'matched' => true,
                    'response' => 'No users found for that request.',
                    'confidence' => 0.95,
                ];
            }

            $names = array_map(
                static fn (Utilisateur $u): string => trim(((string) $u->getNomU()) . ' ' . ((string) $u->getPrenomU())),
                $users
            );

            $label = $roleFilter ? ($roleFilter . ' names') : 'User names';

            return [
                'matched' => true,
                'response' => $label . ': ' . implode(', ', $names) . '.',
                'confidence' => 0.93,
            ];
        }

        if ($asksEmails) {
            $roleFilter = null;
            if ($mentionsEmployees) {
                $roleFilter = 'employee';
            } elseif ($mentionsClients) {
                $roleFilter = 'client';
            } elseif ($mentionsAdmins) {
                $roleFilter = 'admin';
            }

            $users = $roleFilter
                ? $repo->findBy(['role_u' => $roleFilter], ['email_u' => 'ASC'], 15)
                : $repo->findBy([], ['email_u' => 'ASC'], 15);

            if (count($users) === 0) {
                return [
                    'matched' => true,
                    'response' => 'No emails found for that request.',
                    'confidence' => 0.95,
                ];
            }

            $emails = array_values(array_filter(array_map(
                static fn (Utilisateur $u): string => (string) $u->getEmailU(),
                $users
            )));

            if (count($emails) === 0) {
                return [
                    'matched' => true,
                    'response' => 'Users exist, but no valid emails were found.',
                    'confidence' => 0.92,
                ];
            }

            $label = $roleFilter ? ($roleFilter . ' emails') : 'User emails';

            return [
                'matched' => true,
                'response' => $label . ': ' . implode(', ', $emails) . '.',
                'confidence' => 0.93,
            ];
        }

        return [
            'matched' => false,
            'response' => '',
            'confidence' => 0,
        ];
    }
}
