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

            // Build email HTML content
            $emailContent = sprintf(
                '<div style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto;">
                    <div style="background-color: #f5f5dc; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                        <h2 style="color: #116530; margin: 0; font-size: 24px;">Response to Your Complaint</h2>
                        <p style="color: #666; margin: 8px 0 0 0;">Complaint ID: <strong>#%s</strong></p>
                    </div>

                    <div style="background-color: #f9f9f9; padding: 20px; border-left: 4px solid #116530; border-radius: 4px; margin-bottom: 20px;">
                        <h3 style="color: #0a2200; margin-top: 0;">Your Complaint: %s</h3>
                        <p style="color: #555; margin: 10px 0;">%s</p>
                    </div>

                    <div style="background-color: #ffffff; padding: 20px; border: 1px solid #e0e0e0; border-radius: 4px; margin-bottom: 20px;">
                        <h3 style="color: #0a2200; margin-top: 0;">Our Response:</h3>
                        <p style="color: #333; white-space: pre-wrap; line-height: 1.8;">%s</p>
                    </div>

                    <div style="background-color: #f5f5dc; padding: 15px; border-radius: 4px; text-align: center;">
                        <p style="color: #666; font-size: 14px; margin: 0;">
                            Thank you for bringing this to our attention.<br>
                            If you have any further questions, please reply to this email.
                        </p>
                    </div>

                    <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e0e0e0; font-size: 12px; color: #999;">
                        <p style="margin: 5px 0;">
                            <strong>El Firma Agriculture Team</strong><br>
                            rue cheikh taieb sayala, La marsa, 2070 TUN<br>
                            Email: <a href="mailto:elfirma@gmail.com" style="color: #116530; text-decoration: none;">elfirma@gmail.com</a>
                        </p>
                    </div>
                </div>',
                $complaintId,
                htmlspecialchars($complaint->getTitreU()),
                htmlspecialchars($complaint->getDescriptionU()),
                htmlspecialchars($replyMessage)
            );

            // Send email
            $email = (new Email())
                ->from($_ENV['MAILER_FROM'] ?? 'noreply@elfirma.tn')
                ->to($user->getEmailU())
                ->subject('Re: ' . $complaint->getTitreU() . ' - Complaint Response')
                ->html($emailContent);

            $mailer->send($email);

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
