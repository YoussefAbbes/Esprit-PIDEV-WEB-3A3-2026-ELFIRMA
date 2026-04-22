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
}
