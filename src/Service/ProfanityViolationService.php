<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Notification\Notification;

class ProfanityViolationService
{
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;
    private NotifierInterface $notifier;

    private const VIOLATION_THRESHOLD = 3; // Block after 3 profanity violations
    private const BLOCK_DURATION_DAYS = 7; // Block for 7 days

    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
        NotifierInterface $notifier
    ) {
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->notifier = $notifier;
    }

    /**
     * Track a profanity violation for a user
     * @param int $userId
     * @return array ['blocked' => bool, 'violations' => int, 'unblock_date' => string|null]
     */
    public function recordViolation(int $userId): array
    {
        $this->logger->info('[ProfanityViolation] Recording violation for user: ' . $userId);

        try {
            // Get the connection and create a table if not exists
            $connection = $this->entityManager->getConnection();
            
            // Check if table exists and create if needed
            $schemaManager = $connection->createSchemaManager();
            if (!$schemaManager->tablesExist(['profanity_violations'])) {
                $this->createViolationsTable($connection);
            }

            // Get or create violation record for user
            $sql = 'SELECT violation_count, is_blocked, blocked_until FROM profanity_violations WHERE user_id = ?';
            $result = $connection->executeQuery($sql, [$userId])->fetchAssociative();

            if ($result) {
                $violationCount = (int)$result['violation_count'];
                $isBlocked = (bool)$result['is_blocked'];
                $blockedUntil = $result['blocked_until'];
            } else {
                $violationCount = 0;
                $isBlocked = false;
                $blockedUntil = null;
            }

            $violationCount++;
            $this->logger->info('[ProfanityViolation] Violation count is now: ' . $violationCount);

            $blocked = false;
            $unblockDate = null;

            // Check if user should be blocked
            if ($violationCount >= self::VIOLATION_THRESHOLD && !$isBlocked) {
                $blocked = true;
                $unblockDateTime = new \DateTime('+' . self::BLOCK_DURATION_DAYS . ' days');
                $unblockDate = $unblockDateTime->format('Y-m-d H:i:s');
                $blockedUntil = $unblockDate;

                $this->logger->warning('[ProfanityViolation] USER BLOCKED! User ' . $userId . ' will be unblocked on: ' . $unblockDate);

                // Send notification to user
                $this->sendBlockNotification($userId, $unblockDate);
            }

            // Upsert violation record
            if ($result) {
                $sql = 'UPDATE profanity_violations SET violation_count = ?, is_blocked = ?, blocked_until = ? WHERE user_id = ?';
                $connection->executeStatement($sql, [$violationCount, $blocked ? 1 : 0, $blockedUntil, $userId]);
                $this->logger->info('[ProfanityViolation] Updated violation record for user: ' . $userId);
            } else {
                $sql = 'INSERT INTO profanity_violations (user_id, violation_count, is_blocked, blocked_until) VALUES (?, ?, ?, ?)';
                $connection->executeStatement($sql, [$userId, $violationCount, $blocked ? 1 : 0, $blockedUntil]);
                $this->logger->info('[ProfanityViolation] Created violation record for user: ' . $userId);
            }

            return [
                'blocked' => $blocked,
                'violations' => $violationCount,
                'unblock_date' => $unblockDate,
                'threshold_reached' => $violationCount >= self::VIOLATION_THRESHOLD
            ];

        } catch (\Exception $e) {
            $this->logger->error('[ProfanityViolation] Error recording violation: ' . $e->getMessage());
            return [
                'blocked' => false,
                'violations' => 0,
                'unblock_date' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check if user is currently blocked from commenting
     * @param int $userId
     * @return array ['is_blocked' => bool, 'blocked_until' => string|null, 'remaining_hours' => float|null]
     */
    public function isUserBlocked(int $userId): array
    {
        try {
            $connection = $this->entityManager->getConnection();
            
            $sql = 'SELECT is_blocked, blocked_until FROM profanity_violations WHERE user_id = ?';
            $result = $connection->executeQuery($sql, [$userId])->fetchAssociative();

            if (!$result) {
                return ['is_blocked' => false, 'blocked_until' => null, 'remaining_hours' => null];
            }

            $isBlocked = (bool)$result['is_blocked'];
            $blockedUntil = $result['blocked_until'];

            if (!$isBlocked || !$blockedUntil) {
                return ['is_blocked' => false, 'blocked_until' => null, 'remaining_hours' => null];
            }

            $unblockTime = new \DateTime($blockedUntil);
            $now = new \DateTime();

            // Check if block period has expired
            if ($now >= $unblockTime) {
                $this->logger->info('[ProfanityViolation] Block period expired for user: ' . $userId);
                // Unblock the user
                $this->unblockUser($userId);
                return ['is_blocked' => false, 'blocked_until' => null, 'remaining_hours' => null];
            }

            $interval = $now->diff($unblockTime);
            $remainingHours = $interval->h + ($interval->days * 24);

            return [
                'is_blocked' => true,
                'blocked_until' => $blockedUntil,
                'remaining_hours' => $remainingHours
            ];

        } catch (\Exception $e) {
            $this->logger->error('[ProfanityViolation] Error checking block status: ' . $e->getMessage());
            return ['is_blocked' => false, 'blocked_until' => null, 'remaining_hours' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Unblock user (called when block period expires)
     * @param int $userId
     */
    public function unblockUser(int $userId): void
    {
        try {
            $connection = $this->entityManager->getConnection();
            $sql = 'UPDATE profanity_violations SET is_blocked = 0, blocked_until = NULL, violation_count = 0 WHERE user_id = ?';
            $connection->executeStatement($sql, [$userId]);
            $this->logger->info('[ProfanityViolation] User ' . $userId . ' has been unblocked');
        } catch (\Exception $e) {
            $this->logger->error('[ProfanityViolation] Error unblocking user: ' . $e->getMessage());
        }
    }

    /**
     * Send real-time notification to user about block
     * @param int $userId
     * @param string $unblockDate
     */
    private function sendBlockNotification(int $userId, string $unblockDate): void
    {
        try {
            $this->logger->info('[ProfanityViolation] Sending block notification to user: ' . $userId);

            $unblockDateTime = new \DateTime($unblockDate);
            $formattedDate = $unblockDateTime->format('d/m/Y à H:i');

            $notification = (new Notification())
                ->subject('Comment Blocked')
                ->content('You have been blocked from commenting for 7 days due to repeated profanity violations. You will be able to comment again on: ' . $formattedDate)
                ->importance(Notification::IMPORTANCE_HIGH);

            // Send to the user (this requires a recipient channel setup)
            // For browser notifications, we'll handle this via WebSocket or Server-Sent Events
            $this->logger->info('[ProfanityViolation] Notification prepared for user: ' . $userId . ', unblock date: ' . $formattedDate);

            // Store notification in database for frontend to fetch
            $this->storeNotification($userId, $formattedDate);

        } catch (\Exception $e) {
            $this->logger->error('[ProfanityViolation] Error sending notification: ' . $e->getMessage());
        }
    }

    /**
     * Store notification in database for frontend to retrieve
     * @param int $userId
     * @param string $unblockDate
     */
    private function storeNotification(int $userId, string $unblockDate): void
    {
        try {
            $connection = $this->entityManager->getConnection();
            
            // Check if notifications table exists
            $schemaManager = $connection->createSchemaManager();
            if (!$schemaManager->tablesExist(['user_notifications'])) {
                $this->createNotificationsTable($connection);
            }

            $sql = 'INSERT INTO user_notifications (user_id, title, message, type, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?)';
            $connection->executeStatement($sql, [
                $userId,
                'Comment Blocked',
                'You have been blocked from commenting for 7 days due to repeated profanity violations. You will be able to comment again on: ' . $unblockDate,
                'warning',
                0,
                (new \DateTime())->format('Y-m-d H:i:s')
            ]);

            $this->logger->info('[ProfanityViolation] Notification stored in database for user: ' . $userId);
        } catch (\Exception $e) {
            $this->logger->error('[ProfanityViolation] Error storing notification: ' . $e->getMessage());
        }
    }

    /**
     * Create profanity violations table if it doesn't exist
     */
    private function createViolationsTable($connection): void
    {
        $sql = <<<SQL
            CREATE TABLE IF NOT EXISTS profanity_violations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL UNIQUE,
                violation_count INT NOT NULL DEFAULT 0,
                is_blocked BOOLEAN NOT NULL DEFAULT FALSE,
                blocked_until DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_is_blocked (is_blocked)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL;

        $connection->executeStatement($sql);
        $this->logger->info('[ProfanityViolation] Created profanity_violations table');
    }

    /**
     * Create notifications table if it doesn't exist
     */
    private function createNotificationsTable($connection): void
    {
        $sql = <<<SQL
            CREATE TABLE IF NOT EXISTS user_notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                message TEXT,
                type VARCHAR(50),
                is_read BOOLEAN DEFAULT FALSE,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_is_read (is_read)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL;

        $connection->executeStatement($sql);
        $this->logger->info('[ProfanityViolation] Created user_notifications table');
    }

    /**
     * Get unread notifications for user
     * @param int $userId
     * @return array
     */
    public function getUserNotifications(int $userId): array
    {
        try {
            $connection = $this->entityManager->getConnection();
            $sql = 'SELECT * FROM user_notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC';
            return $connection->executeQuery($sql, [$userId])->fetchAllAssociative();
        } catch (\Exception $e) {
            $this->logger->error('[ProfanityViolation] Error fetching notifications: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Mark notification as read
     * @param int $notificationId
     */
    public function markNotificationAsRead(int $notificationId): void
    {
        try {
            $connection = $this->entityManager->getConnection();
            $sql = 'UPDATE user_notifications SET is_read = 1 WHERE id = ?';
            $connection->executeStatement($sql, [$notificationId]);
        } catch (\Exception $e) {
            $this->logger->error('[ProfanityViolation] Error marking notification as read: ' . $e->getMessage());
        }
    }
}
