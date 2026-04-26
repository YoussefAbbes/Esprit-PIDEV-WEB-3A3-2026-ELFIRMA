<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Reclamation;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

final class ReclamationController extends AbstractController
{
    #[Route('/elfirma/reclamations', name: 'reclamation_page', methods: ['GET'], priority: 10)]
    public function page(EntityManagerInterface $entityManager): Response
    {
        $reclamationRepo = $entityManager->getRepository(Reclamation::class);
        $allComplaints = $reclamationRepo->findAll();

        return $this->render('elfirma/r_clamations.html.twig', [
            'complaints' => $allComplaints,
            'module_meta' => [
                'folder' => 'r_clamations',
                'title' => 'Claims',
            ],
            'current_module' => 'reclamations',
        ]);
    }

    #[Route('/elfirma/complaint/create', name: 'elfirma_create_complaint', methods: ['POST'])]
    public function createComplaint(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $errors = [];

        // Get form data
        $type = trim($request->request->get('type', ''));
        $title = trim($request->request->get('title', ''));
        $description = trim($request->request->get('description', ''));
        $userId = $request->getSession()->get('user_id');

        // PHP Validations
        if (empty($type) || !in_array($type, ['Product Issue', 'Service Issue', 'Delivery Problem', 'Quality Concern', 'Other'])) {
            $errors['type'] = 'Please select a valid complaint type';
        }

        if (empty($title)) {
            $errors['title'] = 'Title is required';
        } elseif (strlen($title) > 100) {
            $errors['title'] = 'Title must not exceed 100 characters';
        }

        if (empty($description)) {
            $errors['description'] = 'Description is required';
        } elseif (strlen($description) > 500) {
            $errors['description'] = 'Description must not exceed 500 characters';
        }

        // If there are errors, return them
        if (!empty($errors)) {
            return new JsonResponse([
                'success' => false,
                'errors' => $errors
            ], 400);
        }

        try {
            // Get user if logged in
            $user = null;
            if ($userId) {
                $utilisateurRepo = $entityManager->getRepository(Utilisateur::class);
                $user = $utilisateurRepo->find($userId);
            }

            // Create new complaint
            $complaint = new Reclamation();
            $complaint->setTypeReclamationU($type);
            $complaint->setTitreU($title);
            $complaint->setDescriptionU($description);
            $complaint->setDateReclamationU(new \DateTime());
            $complaint->setStatutU('new');
            if ($user) {
                $complaint->setUtilisateur($user);
            }

            $entityManager->persist($complaint);
            $entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Complaint submitted successfully! Thank you for your feedback.',
                'complaint_id' => $complaint->getIdrU()
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['general' => 'Error creating complaint: ' . $e->getMessage()]
            ], 500);
        }
    }

    #[Route('/elfirma/complaint/validate', name: 'elfirma_validate_complaint', methods: ['POST'])]
    public function validateComplaint(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $complaintId = $request->request->get('complaint_id');

        if (!$complaintId) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Complaint ID is required'
            ]);
        }

        try {
            $reclamationRepo = $entityManager->getRepository(Reclamation::class);
            $complaint = $reclamationRepo->find($complaintId);

            if (!$complaint) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Complaint not found'
                ]);
            }

            // Update status to "reviewed"
            $complaint->setStatutU('reviewed');
            $entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Complaint status updated to reviewed'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error updating complaint: ' . $e->getMessage()
            ]);
        }
    }

    #[Route('/elfirma/complaint/update', name: 'elfirma_update_complaint', methods: ['POST'])]
    public function updateComplaint(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $errors = [];

        // Get form data
        $complaintId = $request->request->get('complaint_id', '');
        $type = trim($request->request->get('type', ''));
        $titre = trim($request->request->get('titre', ''));
        $description = trim($request->request->get('description', ''));

        // Find complaint
        $reclamationRepo = $entityManager->getRepository(Reclamation::class);
        $complaint = $reclamationRepo->find($complaintId);

        if (!$complaint) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['general' => 'Complaint not found']
            ]);
        }

        // PHP Validations
        if (empty($type) || !in_array($type, ['Product Issue', 'Service Issue', 'Delivery Problem', 'Quality Concern', 'Other'])) {
            $errors['type'] = 'Please select a valid complaint type';
        }

        if (empty($titre)) {
            $errors['titre'] = 'Title is required';
        } elseif (strlen($titre) > 20) {
            $errors['titre'] = 'Title must not exceed 20 characters';
        }

        if (empty($description)) {
            $errors['description'] = 'Description is required';
        } elseif (strlen($description) > 30) {
            $errors['description'] = 'Description must not exceed 30 characters';
        }

        // If there are errors, return them
        if (!empty($errors)) {
            return new JsonResponse([
                'success' => false,
                'errors' => $errors
            ]);
        }

        // Update complaint
        $complaint->setTypeReclamationU($type);
        $complaint->setTitreU($titre);
        $complaint->setDescriptionU($description);

        try {
            $entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Complaint updated successfully'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['general' => 'Error updating complaint: ' . $e->getMessage()]
            ]);
        }
    }

    #[Route('/elfirma/complaint/delete', name: 'elfirma_delete_complaint', methods: ['POST'])]
    public function deleteComplaint(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $complaintId = $request->request->get('complaint_id', '');

        if (!$complaintId) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Complaint ID is required'
            ]);
        }

        try {
            $reclamationRepo = $entityManager->getRepository(Reclamation::class);
            $complaint = $reclamationRepo->find($complaintId);

            if (!$complaint) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Complaint not found'
                ]);
            }

            // Delete the complaint
            $entityManager->remove($complaint);
            $entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Complaint deleted successfully'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error deleting complaint: ' . $e->getMessage()
            ]);
        }
    }

    #[Route('/elfirma/complaint/reply', name: 'elfirma_reply_complaint', methods: ['POST'])]
    public function replyComplaint(Request $request, EntityManagerInterface $entityManager, MailerInterface $mailer): JsonResponse
    {
        $complaintId = $request->request->get('complaint_id', '');
        $replyMessage = trim($request->request->get('reply_message', ''));

        $errors = [];

        if (!$complaintId) {
            $errors['complaint_id'] = 'Complaint ID is required';
        }

        if (empty($replyMessage)) {
            $errors['reply_message'] = 'Reply message is required';
        } elseif (strlen($replyMessage) < 10) {
            $errors['reply_message'] = 'Reply message must be at least 10 characters long';
        } elseif (strlen($replyMessage) > 5000) {
            $errors['reply_message'] = 'Reply message must not exceed 5000 characters';
        }

        if (!empty($errors)) {
            return new JsonResponse([
                'success' => false,
                'errors' => $errors
            ], 400);
        }

        try {
            // Find complaint
            $reclamationRepo = $entityManager->getRepository(Reclamation::class);
            $complaint = $reclamationRepo->find($complaintId);

            if (!$complaint) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Complaint not found'
                ]);
            }

            // Get user from complaint
            $user = $complaint->getUtilisateur();

            if (!$user || !$user->getEmailU()) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'User email not found'
                ]);
            }

            $fromEmail = (string) ($_ENV['MAILER_FROM'] ?? $_SERVER['MAILER_FROM'] ?? 'islem.souid@esprit.tn');
            $alternateMailerDsn = (string) ($_ENV['MAILER_DSN_OTHER'] ?? $_SERVER['MAILER_DSN_OTHER'] ?? '');

            // When an alternate SMTP transport is configured, prefer it for complaint replies.
            if ($alternateMailerDsn !== '' && str_contains($alternateMailerDsn, 'smtp.gmail.com')) {
                $fromEmail = 'fethizouabi190@gmail.com';
            }

            $emailHtml = $this->renderView('emails/complaint_reply.html.twig', [
                'complaintId' => $complaintId,
                'complaintTitle' => (string) $complaint->getTitreU(),
                'complaintDescription' => (string) $complaint->getDescriptionU(),
                'replyMessage' => $replyMessage,
                'recipientName' => trim((string) ($user->getNomU() . ' ' . $user->getPrenomU())),
                'recipientEmail' => (string) $user->getEmailU(),
            ]);

            $email = (new Email())
                ->from($fromEmail)
                ->to($user->getEmailU())
                ->subject('Re: ' . $complaint->getTitreU() . ' - Complaint Response')
                ->html($emailHtml);

            if ($alternateMailerDsn !== '') {
                $alternateTransport = Transport::fromDsn($alternateMailerDsn);
                $alternateMailer = new Mailer($alternateTransport);
                $alternateMailer->send($email);
            } else {
                $mailer->send($email);
            }

            // Update complaint status to "responded"
            $complaint->setStatutU('responded');
            $entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Reply sent successfully to ' . $user->getEmailU()
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error sending reply: ' . $e->getMessage()
            ], 500);
        }
    }
}