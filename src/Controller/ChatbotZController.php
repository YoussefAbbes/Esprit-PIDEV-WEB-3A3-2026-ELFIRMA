<?php
declare(strict_types=1);

namespace App\Controller;

use App\Exception\ChatbotEngineException;
use App\Service\ChatbotEngineService;
use App\Service\ChatbotResponseEnhancer;
use App\Service\ChatbotRequestValidator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ChatbotZController extends AbstractController
{
    #[Route('/api/chat', name: 'api_chat', methods: ['POST'])]
    public function chat(
        Request $request,
        ChatbotRequestValidator $requestValidator,
        ChatbotEngineService $chatbotEngineService,
        ChatbotResponseEnhancer $chatbotResponseEnhancer,
        LoggerInterface $logger
    ): JsonResponse {
        $requestId = $this->buildRequestId();

        try {
            $rawBody = trim((string) $request->getContent());
            if ($rawBody === '') {
                throw new ChatbotEngineException(
                    'validation_error',
                    'Request body must not be empty.',
                    Response::HTTP_BAD_REQUEST
                );
            }

            try {
                $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new ChatbotEngineException(
                    'validation_error',
                    'Request body must be valid JSON.',
                    Response::HTTP_BAD_REQUEST,
                    ['json_error' => $exception->getMessage()],
                    $exception
                );
            }

            $chatRequest = $requestValidator->validateAndBuild($payload);
            $chatResponse = $chatbotEngineService->ask($chatRequest);
            $chatResponse = $chatbotResponseEnhancer->enhance($chatRequest, $chatResponse);

            return $this->buildJsonResponse($chatResponse, Response::HTTP_OK);
        } catch (ChatbotEngineException $exception) {
            $statusCode = $exception->getStatusCode();

            if ($statusCode >= 500) {
                $logger->error('Chatbot API request failed.', [
                    'request_id' => $requestId,
                    'error_code' => $exception->getErrorCode(),
                    'details' => $exception->getDetails(),
                ]);
            } else {
                $logger->warning('Chatbot API request rejected.', [
                    'request_id' => $requestId,
                    'error_code' => $exception->getErrorCode(),
                    'details' => $exception->getDetails(),
                ]);
            }

            return $this->buildErrorResponse(
                errorCode: $exception->getErrorCode(),
                message: $exception->getMessage(),
                statusCode: $statusCode,
                details: $exception->getDetails(),
                requestId: $requestId
            );
        } catch (\Throwable $exception) {
            $logger->error('Unexpected chatbot API error.', [
                'request_id' => $requestId,
                'error' => $exception->getMessage(),
            ]);

            return $this->buildErrorResponse(
                errorCode: 'internal_error',
                message: 'An unexpected internal error occurred.',
                statusCode: Response::HTTP_INTERNAL_SERVER_ERROR,
                details: [],
                requestId: $requestId
            );
        }
    }

    /**
     * @param array<string, mixed> $details
     */
    private function buildErrorResponse(
        string $errorCode,
        string $message,
        int $statusCode,
        array $details,
        string $requestId
    ): JsonResponse {
        return $this->buildJsonResponse([
            'error_code' => $errorCode,
            'message' => $message,
            'details' => $details,
            'request_id' => $requestId,
        ], $statusCode);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildJsonResponse(array $payload, int $statusCode): JsonResponse
    {
        $response = new JsonResponse($payload, $statusCode);
        $response->setEncodingOptions($response->getEncodingOptions() | JSON_INVALID_UTF8_SUBSTITUTE);

        return $response;
    }

    private function buildRequestId(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable) {
            return uniqid('chat_', true);
        }
    }
}