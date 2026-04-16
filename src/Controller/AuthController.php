<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Entity\Produit;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class AuthController extends AbstractController
{
    #[Route('/', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(Request $request, EntityManagerInterface $em): Response
    {
        if ($request->isMethod('POST')) {
            $email = strtolower(trim($request->request->get('email', '')));
            $password = trim($request->request->get('password', ''));

            if (empty($email) || empty($password)) {
                return $this->render('auth/login.html.twig', ['error' => 'Email and password are required']);
            }

            // Find user by email (case-insensitive)
            $user = $em->createQuery('SELECT u FROM App\Entity\Utilisateur u WHERE LOWER(u.email_u) = LOWER(:email)')
                ->setParameter('email', $email)
                ->getOneOrNullResult();

            if (!$user || !password_verify($password, $user->getMotDePasseU())) {
                return $this->render('auth/login.html.twig', ['error' => 'Invalid email or password']);
            }

            // Set session
            $session = $request->getSession();
            $session->set('user_id', $user->getIdU());
            $session->set('user_email', $user->getEmailU());
            $session->set('user_role', $user->getRoleU());
            $session->set('user_name', $user->getPrenomU() . ' ' . $user->getNomU());

            return $this->redirectToRoute('app_verify_captcha');
        }
        return $this->render('auth/login.html.twig');
    }




    #[Route('/verify-captcha', name: 'app_verify_captcha', methods: ['GET', 'POST'])]
public function verifyCaptcha(Request $request): Response
{
    if ($request->isMethod('POST')) {

        $recaptchaResponse = $request->request->get('g-recaptcha-response');

        if (!$recaptchaResponse) {
            return $this->render('auth/recaptcha.html.twig', [
                'error' => 'Please confirm you are not a robot',
                'recaptcha_site_key' => $_ENV['RECAPTCHA_SITE_KEY']
            ]);
        }

        $secret = $_ENV['RECAPTCHA_SECRET_KEY'];

        $verify = file_get_contents(
            "https://www.google.com/recaptcha/api/siteverify?secret=$secret&response=$recaptchaResponse"
        );

        $captchaSuccess = json_decode($verify);

        if (!$captchaSuccess->success) {
            return $this->render('auth/recaptcha.html.twig', [
                'error' => 'reCAPTCHA failed',
                'recaptcha_site_key' => $_ENV['RECAPTCHA_SITE_KEY']
            ]);
        }

        // CAPTCHA OK → accès final
        return $this->redirectToRoute('app_pages_home');
    }

    return $this->render('auth/recaptcha.html.twig', [
    'recaptcha_site_key' => $_ENV['RECAPTCHA_SITE_KEY'],
    'error' => null
]);
}
#[Route('/connect/google', name: 'connect_google')]
public function connectGoogle(ClientRegistry $clientRegistry)
{
    return $clientRegistry->getClient('google')
        ->redirect(['email', 'profile']);
}

#[Route('/connect/google/check', name: 'connect_google_check')]
public function connectGoogleCheck(ClientRegistry $clientRegistry, EntityManagerInterface $em, Request $request)
{
    $client = $clientRegistry->getClient('google');
    $googleUser = $client->fetchUser();

    $email = $googleUser->getEmail();

    $user = $em->getRepository(Utilisateur::class)
        ->findOneBy(['email_u' => $email]);

    if (!$user) {
        $user = new Utilisateur();
        $user->setEmailU($email);
        $user->setPrenomU($googleUser->getFirstName() ?? 'Google');
        $user->setNomU($googleUser->getLastName() ?? 'User');
        $user->setMotDePasseU('GOOGLE');
        $user->setRoleU('client');
        $user->setDateCreationU(new \DateTime());

        $em->persist($user);
        $em->flush();
    }

    // SESSION (comme ton login classique)
    $session = $request->getSession();
    $session->set('user_id', $user->getIdU());
    $session->set('user_email', $user->getEmailU());
    $session->set('user_role', $user->getRoleU());
    $session->set('user_name', $user->getPrenomU().' '.$user->getNomU());

    return $this->redirectToRoute('app_pages_home');
}

#[Route('/connect/github', name: 'connect_github')]
public function connectGithub(ClientRegistry $clientRegistry)
{
    return $clientRegistry->getClient('github')->redirect();
}

#[Route('/connect/github/check', name: 'connect_github_check')]
public function connectGithubCheck(ClientRegistry $clientRegistry, EntityManagerInterface $em, Request $request)
{
    $client = $clientRegistry->getClient('github');
    $githubUser = $client->fetchUser();

    $email = $githubUser->getEmail();

    // GitHub peut retourner null email
    if (!$email) {
        $email = $githubUser->getNickname().'@github.com';
    }

    $user = $em->getRepository(Utilisateur::class)
        ->findOneBy(['email_u' => $email]);

    if (!$user) {
        $user = new Utilisateur();
        $user->setEmailU($email);
        $user->setPrenomU($githubUser->getNickname());
        $user->setNomU('GitHub');
        $user->setMotDePasseU('GITHUB');
        $user->setRoleU('client');
        $user->setDateCreationU(new \DateTime());

        $em->persist($user);
        $em->flush();
    }

    // SESSION (même système que login normal)
    $session = $request->getSession();
    $session->set('user_id', $user->getIdU());
    $session->set('user_email', $user->getEmailU());
    $session->set('user_role', $user->getRoleU());
    $session->set('user_name', $user->getPrenomU().' '.$user->getNomU());

    return $this->redirectToRoute('app_pages_home');
}

    #[Route('/signup', name: 'app_signup', methods: ['GET', 'POST'])]
    public function signup(Request $request, EntityManagerInterface $em): Response
    {
        if ($request->isMethod('POST')) {
            $email = trim($request->request->get('email', ''));
            $password = trim($request->request->get('password', ''));
            $confirm_password = trim($request->request->get('confirm_password', ''));
            $prenom = trim($request->request->get('prenom', ''));
            $nom = trim($request->request->get('nom', ''));
            $role = trim($request->request->get('role', ''));

            $errors = [];

            // Validation
            if (empty($email)) {
                $errors['email'] = 'Email is required';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Invalid email format';
            } else {
                // Check if email exists
                $existingUser = $em->getRepository(Utilisateur::class)->findOneBy(['email_u' => $email]);
                if ($existingUser) {
                    $errors['email'] = 'Email already registered';
                }
            }

            if (empty($password)) {
                $errors['password'] = 'Password is required';
            } elseif (strlen($password) < 6) {
                $errors['password'] = 'Password must be at least 6 characters';
            }

            if ($password !== $confirm_password) {
                $errors['confirm_password'] = 'Passwords do not match';
            }

            if (empty($prenom)) {
                $errors['prenom'] = 'First name is required';
            }

            if (empty($nom)) {
                $errors['nom'] = 'Last name is required';
            }

            if (empty($role)) {
                $errors['role'] = 'Please select a role';
            } elseif (!in_array($role, ['employee', 'client'])) {
                $errors['role'] = 'Invalid role selected';
            }

            // If there are errors, show them
            if (!empty($errors)) {
                return $this->render('auth/signup.html.twig', ['errors' => $errors]);
            }

            // Create new user
            $user = new Utilisateur();
            $user->setEmailU($email);
            $user->setMotDePasseU(password_hash($password, PASSWORD_BCRYPT));
            $user->setPrenomU($prenom);
            $user->setNomU($nom);
            $user->setRoleU($role);
            $user->setDateCreationU(new \DateTime());

            try {
                $em->persist($user);
                $em->flush();

                // Set session after successful registration
                $session = $request->getSession();
                $session->set('user_id', $user->getIdU());
                $session->set('user_email', $user->getEmailU());
                $session->set('user_role', $user->getRoleU());
                $session->set('user_name', $user->getPrenomU() . ' ' . $user->getNomU());

                return $this->redirectToRoute('app_pages_home');
            } catch (\Exception $e) {
                return $this->render('auth/signup.html.twig', ['error' => 'Error creating account: ' . $e->getMessage()]);
            }
        }
        return $this->render('auth/signup.html.twig');
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(Request $request): Response
    {
        $request->getSession()->invalidate();
        return $this->redirectToRoute('app_login');
    }

    #[Route('/home', name: 'app_pages_home')]
    public function home(EntityManagerInterface $em): Response
    {
        $produits = $em->getRepository(Produit::class)->findBy(
            ['statut' => 'Disponible'],
            ['nom' => 'ASC']
        );

        $categories = $em->getRepository(Categorie::class)->findBy([], ['nom' => 'ASC']);

        return $this->render('pages/index.html.twig', [
            'produits' => $produits,
            'categories' => $categories,
        ]);
    }

#[Route('/complaint/submit', name: 'app_submit_complaint', methods: ['POST'])]
public function submitComplaint(Request $request, EntityManagerInterface $entityManager): JsonResponse
{
    $errors = [];

    // Get form data
    $type_reclamation = trim($request->request->get('type_reclamation', ''));
    $titre = trim($request->request->get('titre', ''));
    $description = trim($request->request->get('description', ''));

    // PHP Validations
    if (empty($type_reclamation)) {
        $errors['type_reclamation'] = 'Complaint type is required';
    }

    if (empty($titre)) {
        $errors['titre'] = 'Complaint title is required';
    } elseif (strlen($titre) > 20) {
        $errors['titre'] = 'Complaint title must not exceed 20 characters';
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

    // Get current user from session
    $userId = $request->getSession()->get('user_id');
    if (!$userId) {
        return new JsonResponse([
            'success' => false,
            'errors' => ['general' => 'You must be logged in to submit a complaint']
        ]);
    }

    // Get user from database
    $utilisateurRepo = $entityManager->getRepository(Utilisateur::class);
    $user = $utilisateurRepo->find($userId);
    if (!$user) {
        return new JsonResponse([
            'success' => false,
            'errors' => ['general' => 'User not found']
        ]);
    }

    // Create new complaint
    $reclamation = new \App\Entity\Reclamation();
    $reclamation->setTitreU($titre);
    $reclamation->setTypeReclamationU($type_reclamation);
    $reclamation->setDescriptionU($description);
    $reclamation->setDateReclamationU(new \DateTime());
    $reclamation->setStatutU('pending');
    $reclamation->setUtilisateur($user);

    // Save to database
    try {
        $entityManager->persist($reclamation);
        $entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Complaint submitted successfully'
        ]);
    } catch (\Exception $e) {
        return new JsonResponse([
            'success' => false,
            'errors' => ['general' => 'Error submitting complaint: ' . $e->getMessage()]
        ]);
    }
}
}

