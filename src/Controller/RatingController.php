<?php

namespace App\Controller;

use App\Entity\Fournisseur;
use App\Entity\Rating;
use App\Service\ProfanityViolationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class RatingController extends AbstractController
{
    private const CUSTOM_BAD_WORDS = ['bonjour', 'bonsoir', 'bad'];

    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;
    private ProfanityViolationService $violationService;

    public function __construct(HttpClientInterface $httpClient, LoggerInterface $logger, ProfanityViolationService $violationService)
    {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        $this->violationService = $violationService;
    }

    #[Route('/suppliers', name: 'suppliers_list', methods: ['GET'])]
    public function suppliersList(EntityManagerInterface $entityManager): Response
    {
        try {
            $fournisseurRepo = $entityManager->getRepository(Fournisseur::class);
            $suppliers = $fournisseurRepo->findAll();

            $request = $this->container->get('request_stack')->getCurrentRequest();
            $session = $request->getSession();

            return $this->render('suppliers/list.html.twig', [
                'suppliers' => $suppliers,
                'userId' => $session->get('user_id'),
            ]);
        } catch (\Exception $e) {
            return new Response('Error: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/supplier/{id}/ratings', name: 'api_get_supplier_ratings', methods: ['GET'])]
    public function getSupplierRatings(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        try {
            $fournisseurRepo = $entityManager->getRepository(Fournisseur::class);
            $supplier = $fournisseurRepo->find($id);

            if (!$supplier) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Supplier not found'
                ], Response::HTTP_NOT_FOUND);
            }

            $ratingRepo = $entityManager->getRepository(Rating::class);
            $ratings = $ratingRepo->findBy(['fournisseur' => $supplier], ['created_at' => 'DESC']);

            $totalStars = 0;
            $ratingCount = count($ratings);
            foreach ($ratings as $rating) {
                $totalStars += $rating->getNumberOfStars();
            }
            $averageRating = $ratingCount > 0 ? round($totalStars / $ratingCount, 1) : 0;

            $formattedRatings = [];
            foreach ($ratings as $rating) {
                $formattedRatings[] = [
                    'id' => $rating->getIdRating(),
                    'stars' => $rating->getNumberOfStars(),
                    'comment' => $rating->getComment(),
                    'createdAt' => $rating->getCreatedAt() ? $rating->getCreatedAt()->format('Y-m-d H:i') : '',
                ];
            }

            return new JsonResponse([
                'success' => true,
                'supplier' => [
                    'id' => $supplier->getIdF(),
                    'type' => $supplier->getTypeF(),
                    'description' => $supplier->getDescriptionF(),
                    'email' => $supplier->getEmailF(),
                    'tel' => $supplier->getTelF(),
                    'address' => $supplier->getAdresseF(),
                    'status' => $supplier->getStatutF(),
                ],
                'ratings' => $formattedRatings,
                'averageRating' => $averageRating,
                'ratingCount' => $ratingCount,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/rating/add', name: 'api_add_rating', methods: ['POST'])]
    public function addRating(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            $errors = [];
            $supplierId = $data['supplier_id'] ?? null;
            $userId = $data['user_id'] ?? null;
            $stars = $data['stars'] ?? null;
            $comment = $data['comment'] ?? null;

            if (!$supplierId) {
                $errors['supplier_id'] = 'Supplier ID is required';
            }

            if (!$userId) {
                $errors['user_id'] = 'User ID is required';
            }

            if ($stars === null || !is_numeric($stars) || $stars < 1 || $stars > 5) {
                $errors['stars'] = 'Rating must be between 1 and 5 stars';
            }

            if (empty($comment) || strlen(trim($comment)) === 0) {
                $errors['comment'] = 'Review is required';
            } elseif (strlen($comment) > 1000) {
                $errors['comment'] = 'Review must not exceed 1000 characters';
            }

            if (!empty($errors)) {
                $this->logger->debug('[addRating] Validation errors found - rejecting request', $errors);
                return new JsonResponse([
                    'success' => false,
                    'errors' => $errors
                ]);
            }

            // ===== CHECK IF USER IS BLOCKED FROM COMMENTING =====
            $blockStatus = $this->violationService->isUserBlocked((int)$userId);
            if ($blockStatus['is_blocked']) {
                $this->logger->warning('[addRating] User ' . $userId . ' is blocked from commenting until: ' . $blockStatus['blocked_until']);
                return new JsonResponse([
                    'success' => false,
                    'error' => 'You are currently blocked from commenting',
                    'blocked' => true,
                    'blocked_until' => $blockStatus['blocked_until'],
                    'remaining_hours' => $blockStatus['remaining_hours'],
                    'message' => 'Your commenting privileges have been suspended due to repeated profanity violations. You will be able to comment again on: ' . (new \DateTime($blockStatus['blocked_until']))->format('d/m/Y H:i')
                ]);
            }

            // Check for profanity
            $this->logger->info('[addRating] ===== PROFANITY CHECK INITIATED =====');
            $this->logger->info('[addRating] Supplier ID: ' . $supplierId);
            $this->logger->info('[addRating] User ID: ' . $userId);
            $this->logger->info('[addRating] Stars: ' . $stars);
            $this->logger->info('[addRating] Comment length: ' . strlen($comment) . ' characters');

            $profanityCheck = $this->checkProfanity(trim($comment));
            $this->logger->info('[addRating] Profanity check result - Safe: ' . ($profanityCheck['safe'] ? 'YES' : 'NO') . ', Flagged: ' . ($profanityCheck['flagged'] ? 'YES' : 'NO'));

            // Use the censored version for saving
            $finalComment = $profanityCheck['censored'];
            $hasProfanity = $profanityCheck['flagged'] ?? false;

            if ($hasProfanity) {
                $this->logger->warning('[addRating] Profanity detected in comment');
                $this->logger->info('[addRating] Original: "' . trim($comment) . '"');
                $this->logger->info('[addRating] Censored: "' . $finalComment . '"');

                // ===== TRACK PROFANITY VIOLATION =====
                $violationResult = $this->violationService->recordViolation((int)$userId);
                $this->logger->warning('[addRating] Violation recorded - Count: ' . $violationResult['violations'] . ', Blocked: ' . ($violationResult['blocked'] ? 'YES' : 'NO'));

                if ($violationResult['blocked']) {
                    // User just got blocked
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Your comment contains inappropriate language',
                        'blocked' => true,
                        'blocked_reason' => 'You have reached the maximum number of profanity violations (3). You are now blocked from commenting for 7 days.',
                        'unblock_date' => $violationResult['unblock_date'],
                        'message' => 'You have been blocked from commenting for 7 days due to repeated profanity violations. You will be able to comment again on: ' . (new \DateTime($violationResult['unblock_date']))->format('d/m/Y H:i')
                    ]);
                }
            } else {
                $this->logger->info('[addRating] No profanity detected - Allowing submission');
            }

            $fournisseurRepo = $entityManager->getRepository(Fournisseur::class);
            $supplier = $fournisseurRepo->find($supplierId);

            if (!$supplier) {
                return new JsonResponse([
                    'success' => false,
                    'errors' => ['supplier_id' => 'Supplier not found']
                ], Response::HTTP_NOT_FOUND);
            }

            $rating = new Rating();
            $rating->setFournisseur($supplier);
            $rating->setUserId((int)$userId);
            $rating->setNumberOfStars((int)$stars);
            $rating->setComment($finalComment);  // Use censored comment
            $rating->setCreatedAt(new \DateTime());
            $rating->setUpdatedAt(new \DateTime());

            $entityManager->persist($rating);
            $entityManager->flush();

            $this->logger->info('[addRating] Review saved successfully');

            $responseMessage = 'Review submitted successfully! Thank you for your feedback.';
            if ($hasProfanity) {
                $responseMessage = 'Your review was submitted! Note: It contained inappropriate language and has been automatically censored for display.';
                $this->logger->info('[addRating] Response includes censoring notice');
            }

            return new JsonResponse([
                'success' => true,
                'message' => $responseMessage,
                'hasProfanity' => $hasProfanity,
                'rating' => [
                    'id' => $rating->getIdRating(),
                    'stars' => $rating->getNumberOfStars(),
                    'comment' => $rating->getComment(),
                    'createdAt' => $rating->getCreatedAt()->format('Y-m-d H:i'),
                ]
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['general' => 'Error adding rating: ' . $e->getMessage()]
            ]);
        }
    }

    /**
     * Check if comment contains profanity using API Ninjas Profanity Filter
     * @param string $text
     * @return array ['safe' => bool, 'flagged' => bool, 'censored' => string]
     */
    private function checkProfanity(string $text): array
    {
        $this->logger->debug('=== PROFANITY CHECK START ===');

        $localFlagged = $this->containsCustomBadWords($text);
        $locallyCensoredText = $this->censorCustomBadWords($text);

        if ($localFlagged) {
            $this->logger->warning('Step 0: Custom bad words detected locally');
        }

        try {
            $apiKey = trim($_ENV['PROFANITY_FILTER_API_KEY'] ?? '');
            $this->logger->debug('Step 1: API Key retrieved - ' . (empty($apiKey) ? 'EMPTY/NOT SET' : 'SET (length: ' . strlen($apiKey) . ')'));

            if (empty($apiKey)) {
                $this->logger->warning('Step 2: API Key validation - FAILED - Using local profanity list only');
                $this->logger->debug('=== PROFANITY CHECK END (No API Key) ===');
                return [
                    'safe' => !$localFlagged,
                    'flagged' => $localFlagged,
                    'censored' => $locallyCensoredText
                ];
            }

            $this->logger->debug('Step 2: API Key validation - PASSED');
            $this->logger->debug('Step 3: Text to check - "' . $text . '" (Length: ' . strlen($text) . ')');

            $url = 'https://api.api-ninjas.com/v1/profanityfilter';
            $this->logger->debug('Step 4: Calling API at ' . $url);

            $response = $this->httpClient->request('GET', $url, [
                'query' => ['text' => $text],
                'headers' => [
                    'X-Api-Key' => $apiKey,
                    'User-Agent' => 'SymfonyApp'
                ],
                'timeout' => 8
            ]);

            $statusCode = $response->getStatusCode();
            $this->logger->debug('Step 5: API Response received - Status Code: ' . $statusCode);

            if ($statusCode !== 200) {
                $this->logger->error('Step 6: Status check - FAILED (Expected 200, got ' . $statusCode . ') - Using local profanity list only');
                $this->logger->debug('=== PROFANITY CHECK END (API Error) ===');
                return [
                    'safe' => !$localFlagged,
                    'flagged' => $localFlagged,
                    'censored' => $locallyCensoredText
                ];
            }

            $this->logger->debug('Step 6: Status check - PASSED (200 OK)');

            $responseBody = $response->getContent();
            $this->logger->debug('Step 7: Raw response body - ' . $responseBody);

            $data = $response->toArray();
            $this->logger->debug('Step 8: Parsed JSON response - ' . json_encode($data));

            // API Ninjas returns 'has_profanity' not 'is_profane'
            $isProfane = $data['has_profanity'] ?? false;
            $censoredText = $data['censored'] ?? $text;
            $isFlagged = $isProfane || $localFlagged;
            $finalCensoredText = $this->censorCustomBadWords($censoredText);

            $this->logger->debug('Step 9: has_profanity field extracted - ' . ($isProfane ? 'TRUE' : 'FALSE'));
            $this->logger->debug('Step 9b: Censored text - "' . $censoredText . '"');
            $this->logger->debug('Step 10: Available fields in response - ' . implode(', ', array_keys($data)));

            if ($isFlagged) {
                $this->logger->warning('Step 11: PROFANITY DETECTED (API and/or custom list) - Will censor and allow submission');
            } else {
                $this->logger->info('Step 11: No profanity detected - Allowing submission');
            }

            $this->logger->debug('=== PROFANITY CHECK END (Check Complete) ===');
            return ['safe' => !$isFlagged, 'flagged' => $isFlagged, 'censored' => $finalCensoredText];
        } catch (\Exception $e) {
            $this->logger->error('EXCEPTION in checkProfanity:');
            $this->logger->error('  Message: ' . $e->getMessage());
            $this->logger->error('  Code: ' . $e->getCode());
            $this->logger->error('  File: ' . $e->getFile() . ':' . $e->getLine());
            $this->logger->error('  Trace: ' . $e->getTraceAsString());
            $this->logger->error('Using local profanity list due to exception');
            $this->logger->debug('=== PROFANITY CHECK END (Exception) ===');
            return [
                'safe' => !$localFlagged,
                'flagged' => $localFlagged,
                'censored' => $locallyCensoredText
            ];
        }
    }

    /**
     * Check if text contains any custom blacklisted word.
     */
    private function containsCustomBadWords(string $text): bool
    {
        foreach (self::CUSTOM_BAD_WORDS as $word) {
            $pattern = '/(?<![\p{L}\p{N}_])' . preg_quote($word, '/') . '(?![\p{L}\p{N}_])/iu';
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Censor custom blacklisted words by replacing each matched word with asterisks.
     * sgvdcjgvsjd
     */
    private function censorCustomBadWords(string $text): string
    {
        $censored = $text;

        foreach (self::CUSTOM_BAD_WORDS as $word) {
            $pattern = '/(?<![\p{L}\p{N}_])(' . preg_quote($word, '/') . ')(?![\p{L}\p{N}_])/iu';
            $censored = preg_replace_callback(
                $pattern,
                static fn(array $matches): string => str_repeat('*', mb_strlen($matches[1])),
                $censored
            ) ?? $censored;
        }

        return $censored;
    }

    #[Route('/api/supplier/{id}', name: 'api_get_supplier', methods: ['GET'])]
    public function getSupplier(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        try {
            $fournisseurRepo = $entityManager->getRepository(Fournisseur::class);
            $supplier = $fournisseurRepo->find($id);

            if (!$supplier) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Supplier not found'
                ], Response::HTTP_NOT_FOUND);
            }

            return new JsonResponse([
                'success' => true,
                'supplier' => [
                    'id' => $supplier->getIdF(),
                    'type' => $supplier->getTypeF(),
                    'description' => $supplier->getDescriptionF(),
                    'email' => $supplier->getEmailF(),
                    'tel' => $supplier->getTelF(),
                    'address' => $supplier->getAdresseF(),
                    'status' => $supplier->getStatutF(),
                ]
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/test-profanity', name: 'api_test_profanity', methods: ['POST'])]
    public function testProfanity(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $text = $data['text'] ?? 'test';

            $this->logger->info('========== TEST PROFANITY API CALLED ==========');
            $this->logger->info('Input text: "' . $text . '"');

            $result = $this->checkProfanity($text);

            $this->logger->info('Result received: ' . json_encode($result));
            $this->logger->info('API Key configured: ' . (!empty($_ENV['PROFANITY_FILTER_API_KEY'] ?? '') ? 'YES' : 'NO'));
            $this->logger->info('========== TEST PROFANITY COMPLETE ==========');

            return new JsonResponse([
                'text' => $text,
                'result' => $result,
                'api_key_set' => !empty($_ENV['PROFANITY_FILTER_API_KEY'] ?? ''),
                'api_key_preview' => substr($_ENV['PROFANITY_FILTER_API_KEY'] ?? '', 0, 5) . '...',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            $this->logger->error('EXCEPTION in testProfanity: ' . $e->getMessage());
            return new JsonResponse([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    #[Route('/api/user/{userId}/notifications', name: 'api_get_user_notifications', methods: ['GET'])]
    public function getUserNotifications(int $userId): JsonResponse
    {
        try {
            $this->logger->info('[Notifications] Fetching unread notifications for user: ' . $userId);
            
            $notifications = $this->violationService->getUserNotifications($userId);
            
            return new JsonResponse([
                'success' => true,
                'notifications' => $notifications,
                'count' => count($notifications)
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error fetching notifications: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/notification/{notificationId}/read', name: 'api_mark_notification_read', methods: ['POST'])]
    public function markNotificationAsRead(int $notificationId): JsonResponse
    {
        try {
            $this->logger->info('[Notifications] Marking notification as read: ' . $notificationId);
            
            $this->violationService->markNotificationAsRead($notificationId);
            
            return new JsonResponse([
                'success' => true,
                'message' => 'Notification marked as read'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error marking notification as read: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}