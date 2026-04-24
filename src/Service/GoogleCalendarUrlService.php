<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Meeting;

class GoogleCalendarUrlService
{
    /**
     * Generate a Google Calendar event creation URL
     *
     * @param Meeting $meeting
     * @return string Google Calendar event URL
     */
    public function generateEventUrl(Meeting $meeting): string
    {
        $supplier = $meeting->getFournisseur();
        $meetingDateTime = $meeting->getMeetingDatetime();
        $endDateTime = (clone $meetingDateTime)->modify('+1 hour');

        // Format dates as ISO 8601: YYYYMMDDTHHmmssZ
        $startDate = $meetingDateTime->format('Ymd\THis\Z');
        $endDate = $endDateTime->format('Ymd\THis\Z');

        // Event title
        $title = ($supplier ? $supplier->getTypeF() : 'Meeting') . ' - Meeting with Elfirma';

        // Event description with meeting link
        $description = "Meeting with: " . ($supplier ? $supplier->getTypeF() : 'Supplier') . "\n";
        $description .= "Email: " . ($supplier ? $supplier->getEmailF() : 'N/A') . "\n\n";
        $description .= "Join the meeting here:\n";
        $description .= $meeting->getMeetingLink() . "\n\n";
        $description .= "Organized by: Elfirma Agriculture";

        // Build Google Calendar URL
        $baseUrl = 'https://calendar.google.com/calendar/render';
        $params = [
            'action' => 'TEMPLATE',
            'text' => $title,
            'dates' => $startDate . '/' . $endDate,
            'details' => $description,
            'location' => 'Jitsi Meeting',
        ];

        // Build query string with URL encoding
        $queryString = http_build_query($params);

        return $baseUrl . '?' . $queryString;
    }
}
