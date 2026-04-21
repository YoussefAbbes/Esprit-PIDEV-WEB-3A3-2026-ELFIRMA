<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Meeting;
use App\Entity\Fournisseur;
use App\Service\MeetingColorService;
use App\Service\GoogleCalendarUrlService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class MeetingController extends AbstractController
{
    #[Route('/elfirma/meetings', name: 'meetings_page', methods: ['GET'])]
    public function page(
        EntityManagerInterface $entityManager,
        MeetingColorService $colorService
    ): Response {
        $meetingRepo = $entityManager->getRepository(Meeting::class);
        $fournisseurRepo = $entityManager->getRepository(Fournisseur::class);

        $now = new \DateTime('now');
        $currentMonth = (int)$now->format('m');
        $currentYear = (int)$now->format('Y');

        // Get all meetings for current month
        $meetings = $meetingRepo->findByMonth($currentYear, $currentMonth);
        $upcomingCount = count($meetingRepo->findUpcomingMeetings());
        $pastCount = count($meetingRepo->findPastMeetings());

        // Get all suppliers for filter
        $allSuppliers = $fournisseurRepo->findAll();

        // Calculate statistics
        $totalMeetings = count($meetingRepo->findAll());

        return $this->render('elfirma/meetings.html.twig', [
            'meetings' => $meetings,
            'suppliers' => $allSuppliers,
            'currentMonth' => $currentMonth,
            'currentYear' => $currentYear,
            'stats' => [
                'total' => $totalMeetings,
                'upcoming' => $upcomingCount,
                'past' => $pastCount,
            ],
            'module_meta' => [
                'folder' => 'meetings',
                'title' => 'Meeting Management',
            ],
            'current_module' => 'meetings',
        ]);
    }

    #[Route('/elfirma/meetings/data', name: 'meetings_data', methods: ['GET'])]
    public function getMeetingsData(
        Request $request,
        EntityManagerInterface $entityManager,
        MeetingColorService $colorService
    ): JsonResponse {
        $meetingRepo = $entityManager->getRepository(Meeting::class);
        $year = $request->query->getInt('year');
        $month = $request->query->getInt('month');
        $supplierId = $request->query->getInt('supplier_id');

        $meetings = $meetingRepo->findByMonth($year, $month);

        if ($supplierId) {
            $meetings = array_filter($meetings, function (Meeting $m) use ($supplierId) {
                return $m->getFournisseur()?->getIdF() == $supplierId;
            });
        }

        $data = array_map(function (Meeting $meeting) use ($colorService) {
            $supplier = $meeting->getFournisseur();
            $isUpcoming = $meeting->getMeetingDatetime() > new \DateTime('now');

            return [
                'id' => $meeting->getId(),
                'supplier_id' => $supplier?->getIdF(),
                'supplier_name' => $supplier?->getTypeF() ?? 'Unknown',
                'meeting_link' => $meeting->getMeetingLink(),
                'datetime' => $meeting->getMeetingDatetime()->format('Y-m-d H:i'),
                'time' => $meeting->getMeetingDatetime()->format('H:i'),
                'day' => (int)$meeting->getMeetingDatetime()->format('d'),
                'status' => $isUpcoming ? 'upcoming' : 'past',
                'color' => $colorService->getColorForSupplier($supplier?->getIdF() ?? 0),
            ];
        }, $meetings);

        return new JsonResponse(['success' => true, 'data' => $data]);
    }

    #[Route('/elfirma/meeting/reschedule', name: 'meeting_reschedule', methods: ['POST'])]
    public function rescheduleMeeting(
        Request $request,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        \Twig\Environment $twig
    ): JsonResponse {
        $errors = [];
        $meetingId = $request->request->get('meeting_id');
        $newDateTime = $request->request->get('new_datetime');

        if (!$meetingId) {
            $errors['general'] = 'Meeting ID is required';
        }

        if (!$newDateTime) {
            $errors['datetime'] = 'New date and time are required';
        } else {
            try {
                $dateTime = new \DateTime($newDateTime);
                $now = new \DateTime('now');
                if ($dateTime <= $now) {
                    $errors['datetime'] = 'New date and time must be in the future';
                }
            } catch (\Exception $e) {
                $errors['datetime'] = 'Invalid date and time format';
            }
        }

        if (!empty($errors)) {
            return new JsonResponse(['success' => false, 'errors' => $errors]);
        }

        try {
            $meetingRepo = $entityManager->getRepository(Meeting::class);
            $meeting = $meetingRepo->find($meetingId);

            if (!$meeting) {
                return new JsonResponse(['success' => false, 'message' => 'Meeting not found']);
            }

            $oldDateTime = $meeting->getMeetingDatetime();
            $meeting->setMeetingDatetime(new \DateTime($newDateTime));
            $entityManager->flush();

            // Send updated invitation email
            $supplier = $meeting->getFournisseur();
            try {
                $emailContent = $twig->render('emails/meeting_invitation.html.twig', [
                    'supplierName' => $supplier->getTypeF(),
                    'meetingDateTime' => $meeting->getMeetingDatetime(),
                    'meetingLink' => $meeting->getMeetingLink(),
                ]);

                $email = new Email();
                $email->from($_ENV['MAILER_FROM'] ?? 'noreply@elfirma.tn')
                    ->to($supplier->getEmailF())
                    ->subject('Meeting Rescheduled - Elfirma')
                    ->html($emailContent);

                $mailer->send($email);
            } catch (\Exception $e) {
                error_log('Failed to send rescheduled meeting email: ' . $e->getMessage());
            }

            return new JsonResponse([
                'success' => true,
                'message' => 'Meeting rescheduled successfully'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error rescheduling meeting: ' . $e->getMessage()
            ]);
        }
    }

    #[Route('/elfirma/meeting/delete-notify', name: 'meeting_delete_notify', methods: ['POST'])]
    public function deleteMeetingWithNotification(
        Request $request,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        \Twig\Environment $twig
    ): JsonResponse {
        $meetingId = $request->request->get('meeting_id');

        if (!$meetingId) {
            return new JsonResponse(['success' => false, 'message' => 'Meeting ID is required']);
        }

        try {
            $meetingRepo = $entityManager->getRepository(Meeting::class);
            $meeting = $meetingRepo->find($meetingId);

            if (!$meeting) {
                return new JsonResponse(['success' => false, 'message' => 'Meeting not found']);
            }

            $supplier = $meeting->getFournisseur();
            $meetingDateTime = $meeting->getMeetingDatetime();

            // Send cancellation email
            try {
                $emailContent = $twig->render('emails/meeting_cancellation.html.twig', [
                    'supplierName' => $supplier->getTypeF(),
                    'meetingDateTime' => $meetingDateTime,
                ]);

                $email = new Email();
                $email->from($_ENV['MAILER_FROM'] ?? 'noreply@elfirma.tn')
                    ->to($supplier->getEmailF())
                    ->subject('Meeting Cancelled - Elfirma')
                    ->html($emailContent);

                $mailer->send($email);
            } catch (\Exception $e) {
                error_log('Failed to send meeting cancellation email: ' . $e->getMessage());
            }

            // Delete meeting
            $entityManager->remove($meeting);
            $entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Meeting cancelled and supplier has been notified'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error deleting meeting: ' . $e->getMessage()
            ]);
        }
    }

    #[Route('/elfirma/meeting/resend-invitation', name: 'meeting_resend_invitation', methods: ['POST'])]
    public function resendInvitation(
        Request $request,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        \Twig\Environment $twig
    ): JsonResponse {
        $meetingId = $request->request->get('meeting_id');

        if (!$meetingId) {
            return new JsonResponse(['success' => false, 'message' => 'Meeting ID is required']);
        }

        try {
            $meetingRepo = $entityManager->getRepository(Meeting::class);
            $meeting = $meetingRepo->find($meetingId);

            if (!$meeting) {
                return new JsonResponse(['success' => false, 'message' => 'Meeting not found']);
            }

            $supplier = $meeting->getFournisseur();

            // Send invitation email
            $emailContent = $twig->render('emails/meeting_invitation.html.twig', [
                'supplierName' => $supplier->getTypeF(),
                'meetingDateTime' => $meeting->getMeetingDatetime(),
                'meetingLink' => $meeting->getMeetingLink(),
            ]);

            $email = new Email();
            $email->from($_ENV['MAILER_FROM'] ?? 'noreply@elfirma.tn')
                ->to($supplier->getEmailF())
                ->subject('Meeting Invitation - Elfirma')
                ->html($emailContent);

            $mailer->send($email);

            return new JsonResponse([
                'success' => true,
                'message' => 'Invitation email sent successfully'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error sending invitation: ' . $e->getMessage()
            ]);
        }
    }

    #[Route('/elfirma/meeting/export-google/{id}', name: 'meeting_export_google', methods: ['GET'])]
    public function exportToGoogleCalendar(
        int $id,
        EntityManagerInterface $entityManager,
        GoogleCalendarUrlService $calendarService
    ): JsonResponse {
        try {
            $meetingRepo = $entityManager->getRepository(Meeting::class);
            $meeting = $meetingRepo->find($id);

            if (!$meeting) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Meeting not found'
                ]);
            }

            $calendarUrl = $calendarService->generateEventUrl($meeting);

            return new JsonResponse([
                'success' => true,
                'calendar_url' => $calendarUrl
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error generating calendar URL: ' . $e->getMessage()
            ]);
        }
    }
}
