<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ChatbotController extends AbstractController
{
    private const FASTAPI_URL = 'http://localhost:8001/chat';

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
}