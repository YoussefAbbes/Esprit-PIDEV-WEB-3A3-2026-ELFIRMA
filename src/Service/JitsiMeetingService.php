<?php

declare(strict_types=1);

namespace App\Service;

class JitsiMeetingService
{
    private const JITSI_BASE_URL = 'https://meet.jit.si';

    /**
     * Generate a Jitsi Meet room URL
     *
     * @param string $roomName The room name/ID (will be slugified)
     * @return string The full Jitsi meeting URL
     */
    public function generateMeetingUrl(string $roomName): string
    {
        // Slugify the room name (remove special chars, convert to lowercase)
        $slug = preg_replace('/[^a-zA-Z0-9-]/', '', str_replace(' ', '-', strtolower($roomName)));

        // Add random suffix to ensure uniqueness
        $randomSuffix = bin2hex(random_bytes(4)); // 8 character hex

        $roomId = $slug . '-' . $randomSuffix;

        return self::JITSI_BASE_URL . '/' . $roomId;
    }

    /**
     * Generate a meeting room ID from supplier and timestamp
     *
     * @param int $supplierId
     * @param \DateTime $meetingDateTime
     * @return string
     */
    public function generateMeetingRoomId(int $supplierId, \DateTime $meetingDateTime): string
    {
        $timestamp = $meetingDateTime->getTimestamp();
        $randomSuffix = bin2hex(random_bytes(4));

        return "meeting-s{$supplierId}-{$timestamp}-{$randomSuffix}";
    }

    /**
     * Get Jitsi meeting URL from room ID
     *
     * @param string $roomId
     * @return string
     */
    public function getMeetingUrl(string $roomId): string
    {
        return self::JITSI_BASE_URL . '/' . $roomId;
    }
}
