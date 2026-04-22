<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Entity\Reclamation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use App\Controller\AdminTwoFactorController;

final class UserController extends AbstractController
{
    #[Route('/elfirma/utilisateurs', name: 'user_page', methods: ['GET'], priority: 10)]
public function page(Request $request, EntityManagerInterface $entityManager): Response
{
    $session = $request->getSession();
if ($session->get('user_role') !== 'admin' || !AdminTwoFactorController::hasValidAdminTwoFactor($request)) {
    $session->invalidate();
    return $this->redirectToRoute('app_login');
}
    $utilisateurRepo = $entityManager->getRepository(Utilisateur::class);
    $allUsers = $utilisateurRepo->findAll();

    $totalUsers = count($allUsers);
    $employeeCount = 0;
    $clientCount = 0;
    $adminCount = 0;

    foreach ($allUsers as $user) {

        $role = $user->getRoleU();

        if ($role === 'employee') {
            $employeeCount++;
        } elseif ($role === 'client') {
            $clientCount++;
        } elseif ($role === 'admin') {
            $adminCount++;
        }

        // =========================
        // QR CODE GENERATION
        // =========================
       $profileUrl = 'http://192.168.43.23:8000/elfirma/user/' . $user->getIdU() . '/profile';

        $user->qrCode = $this->generateQrCode($profileUrl);
    }

    // Get all complaints
    $reclamationRepo = $entityManager->getRepository(Reclamation::class);
    $allComplaints = $reclamationRepo->findAll();

    return $this->render('utilisateurs.html.twig', [
        'users' => $allUsers,
        'totalUsers' => $totalUsers,
        'employeeCount' => $employeeCount,
        'clientCount' => $clientCount,
        'adminCount' => $adminCount,
        'complaints' => $allComplaints,
        'module_meta' => [
            'folder' => 'utilisateurs',
            'title' => 'Users',
        ],
        'current_module' => 'utilisateurs',
    ]);
}



    #[Route('/elfirma/user/add', name: 'elfirma_add_user', methods: ['POST'])]
    public function addUser(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $errors = [];
        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/users/';

        // Get form data
        $nom = trim($request->request->get('nom', ''));
        $prenom = trim($request->request->get('prenom', ''));
        $email = trim($request->request->get('email', ''));
        $password = $request->request->get('password', '');
        $role = $request->request->get('role', '');
        $photoFile = $request->files->get('photo');
        $photoPath = 'default.JPG';

        // PHP Validations
        if (empty($nom)) {
            $errors['nom'] = 'Last name is required';
        } elseif (strlen($nom) > 10) {
            $errors['nom'] = 'Last name must not exceed 10 characters';
        }

        if (empty($prenom)) {
            $errors['prenom'] = 'First name is required';
        } elseif (strlen($prenom) > 10) {
            $errors['prenom'] = 'First name must not exceed 10 characters';
        }

        if (empty($email)) {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Email is not valid';
        } else {
            // Check email uniqueness
            $utilisateurRepo = $entityManager->getRepository(Utilisateur::class);
            $existingUser = $utilisateurRepo->findOneBy(['email_u' => $email]);
            if ($existingUser) {
                $errors['email'] = 'Email is already in use';
            }
        }

        if (empty($password)) {
            $errors['password'] = 'Password is required';
        } elseif (strlen($password) > 7) {
            $errors['password'] = 'Password must not exceed 7 characters';
        } elseif (strlen($password) < 3) {
            $errors['password'] = 'Password must be at least 3 characters';
        }

        if (empty($role) || !in_array($role, ['employee', 'client'])) {
            $errors['role'] = 'Please select a valid role';
        }

        // Upload photo if present
        if ($photoFile) {
            $fileName = md5(uniqid()) . '.' . $photoFile->guessExtension();
            try {
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $photoFile->move($uploadDir, $fileName);
                $photoPath = 'uploads/users/' . $fileName;
            } catch (\Exception $e) {
                $errors['photo'] = 'Error uploading the photo';
            }
        }

        // If there are errors, return them
        if (!empty($errors)) {
            return new JsonResponse([
                'success' => false,
                'errors' => $errors
            ]);
        }

        // Create new user
        $utilisateur = new Utilisateur();
        $utilisateur->setNomU($nom);
        $utilisateur->setPrenomU($prenom);
        $utilisateur->setEmailU($email);
        // Hash password
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        $utilisateur->setMotDePasseU($hashedPassword);
        $utilisateur->setRoleU($role);
        $utilisateur->setImageU($photoPath);
        $utilisateur->setDateCreationU(new \DateTime());

        try {
            $entityManager->persist($utilisateur);
            $entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'User created successfully',
                'user' => [
                    'id' => $utilisateur->getIdU(),
                    'nom' => $utilisateur->getNomU(),
                    'prenom' => $utilisateur->getPrenomU(),
                    'email' => $utilisateur->getEmailU(),
                ]
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['general' => 'Error creating user: ' . $e->getMessage()]
            ]);
        }
    }

    #[Route('/elfirma/user/update', name: 'elfirma_update_user', methods: ['POST'])]
    public function updateUser(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $errors = [];
        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/users/';

        // Get form data
        $userId = $request->request->get('user_id', '');
        $nom = trim($request->request->get('nom', ''));
        $prenom = trim($request->request->get('prenom', ''));
        $email = trim($request->request->get('email', ''));
        $password = $request->request->get('password', '');
        $role = $request->request->get('role', '');
        $photoFile = $request->files->get('photo');

        // Find user
        $utilisateurRepo = $entityManager->getRepository(Utilisateur::class);
        $utilisateur = $utilisateurRepo->find($userId);

        if (!$utilisateur) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['general' => 'User not found']
            ]);
        }

        // PHP Validations
        if (empty($nom)) {
            $errors['nom'] = 'Last name is required';
        } elseif (strlen($nom) > 10) {
            $errors['nom'] = 'Last name must not exceed 10 characters';
        }

        if (empty($prenom)) {
            $errors['prenom'] = 'First name is required';
        } elseif (strlen($prenom) > 10) {
            $errors['prenom'] = 'First name must not exceed 10 characters';
        }

        if (empty($email)) {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Email is not valid';
        } else {
            // Check email uniqueness (but allow the same email for this user)
            $existingUsers = $utilisateurRepo->findOneBy(['email_u' => $email]);
            if ($existingUsers && $existingUsers->getIdU() != $userId) {
                $errors['email'] = 'Email is already in use';
            }
        }

        // Only validate password if filled in (optional during update)
        if (!empty($password)) {
            if (strlen($password) > 7) {
                $errors['password'] = 'Password must not exceed 7 characters';
            } elseif (strlen($password) < 3) {
                $errors['password'] = 'Password must be at least 3 characters';
            }
        }

        if (empty($role) || !in_array($role, ['employee', 'client'])) {
            $errors['role'] = 'Please select a valid role';
        }

        // If there are errors, return them
        if (!empty($errors)) {
            return new JsonResponse([
                'success' => false,
                'errors' => $errors
            ]);
        }

        // Update user data
        $utilisateur->setNomU($nom);
        $utilisateur->setPrenomU($prenom);
        $utilisateur->setEmailU($email);
        $utilisateur->setRoleU($role);

        // Update password only if provided
        if (!empty($password)) {
            $utilisateur->setMotDePasseU(password_hash($password, PASSWORD_BCRYPT));
        }

        // Upload new photo if present
        if ($photoFile) {
            $fileName = md5(uniqid()) . '.' . $photoFile->guessExtension();
            try {
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $photoFile->move($uploadDir, $fileName);
                $photoPath = 'uploads/users/' . $fileName;
                $utilisateur->setImageU($photoPath);
            } catch (\Exception $e) {
                $errors['photo'] = 'Error uploading the photo';
                return new JsonResponse([
                    'success' => false,
                    'errors' => $errors
                ]);
            }
        }

        // Save updates
        $entityManager->persist($utilisateur);
        $entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'User updated successfully',
            'user' => [
                'id' => $utilisateur->getIdU(),
                'nom' => $utilisateur->getNomU(),
                'prenom' => $utilisateur->getPrenomU(),
                'email' => $utilisateur->getEmailU(),
            ]
        ]);
    }

    #[Route('/elfirma/user/delete', name: 'elfirma_delete_user', methods: ['POST'])]
    public function deleteUser(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $userId = $request->request->get('user_id', '');

        if (!$userId) {
            return new JsonResponse([
                'success' => false,
                'message' => 'User ID is required'
            ]);
        }

        try {
            $utilisateurRepo = $entityManager->getRepository(Utilisateur::class);
            $utilisateur = $utilisateurRepo->find($userId);

            if (!$utilisateur) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'User not found'
                ]);
            }

            // Delete the user
            $entityManager->remove($utilisateur);
            $entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'User deleted successfully'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error deleting user: ' . $e->getMessage()
            ]);
        }
    }


    #[Route('/elfirma/user/{id}/profile', name: 'elfirma_user_profile', methods: ['GET'])]
    public function userProfile(int $id, EntityManagerInterface $em): Response
    {
        $user = $em->getRepository(Utilisateur::class)->find($id);

        if (!$user) {
            throw $this->createNotFoundException('User not found');
        }

        return $this->render('user/profile_public.html.twig', [
            'user' => $user
        ]);
    }

   
  private function generateQrCode(string $url): string
{
    $qrCode = new QrCode(
        data: $url,
        encoding: new Encoding('UTF-8'),
        size: 250,
        margin: 10
    );

    $writer = new PngWriter();
    $result = $writer->write($qrCode);

    return 'data:image/png;base64,' . base64_encode($result->getString());
}


}
