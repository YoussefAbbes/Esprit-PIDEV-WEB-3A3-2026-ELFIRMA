<?php

namespace App\Controller;

use App\Entity\Categorie;
use App\Entity\Produit;
use App\Entity\Utilisateur;
use App\Service\AdminTwoFactorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use App\Service\FaceIdClient;
use App\Service\FaceEncodingStore;

final class AuthController extends AbstractController
{
    private const FORGOT_CONTEXT_SESSION_KEY = 'forgot_password_context';
    private const FORGOT_EMAIL_CODE_TTL_SECONDS = 600;
    private const FORGOT_EMAIL_CODE_MAX_ATTEMPTS = 5;
    private const FORGOT_2FA_MAX_ATTEMPTS = 5;
    private const FORGOT_EMAIL_RESEND_COOLDOWN_SECONDS = 60;

    #[Route('/', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(Request $request, EntityManagerInterface $em): Response
    {
        if ($request->isMethod('POST')) {
            $email = strtolower(trim($request->request->get('email', '')));
            $password = trim($request->request->get('password', ''));

            if (empty($email) || empty($password)) {
                return $this->render('auth/login.html.twig', ['error' => 'Email and password required']);
            }

            $user = $em->createQuery('SELECT u FROM App\Entity\Utilisateur u WHERE LOWER(u.email_u) = LOWER(:email)')
                ->setParameter('email', $email)
                ->getOneOrNullResult();

            if (!$user || !password_verify($password, $user->getMotDePasseU())) {
                return $this->render('auth/login.html.twig', ['error' => 'Invalid credentials']);
            }

            $session = $request->getSession();
            $session->set('user_id', $user->getIdU());
            $session->set('user_email', $user->getEmailU());
            $session->set('user_role', $user->getRoleU());
            $session->set('user_name', $user->getPrenomU() . ' ' . $user->getNomU());

            return $this->redirectToRoute('app_verify_captcha');
        }

        return $this->render('auth/login.html.twig');
    }

    #[Route('/forgot-password', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function forgotPassword(
        Request $request,
        EntityManagerInterface $em,
        AdminTwoFactorService $twoFactorService,
        MailerInterface $mailer
    ): Response {
        $context = $this->getForgotPasswordContext($request);
        $error = null;
        $success = null;

        if ($request->isMethod('POST')) {
            $action = (string) $request->request->get('action', '');

            if ($action === 'start') {
                $token = (string) $request->request->get('_token', '');
                if (!$this->isCsrfTokenValid('forgot_email', $token)) {
                    $error = 'Invalid request token. Please try again.';
                } else {
                    $email = strtolower(trim((string) $request->request->get('email', '')));

                    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $error = 'Please enter a valid email address.';
                    } else {
                        $user = $em->createQuery('SELECT u FROM App\\Entity\\Utilisateur u WHERE LOWER(u.email_u) = LOWER(:email)')
                            ->setParameter('email', $email)
                            ->getOneOrNullResult();

                        if (!$user instanceof Utilisateur) {
                            $error = 'This email does not exist.';
                        } else {
                            $context = [
                                'step' => 'two_factor',
                                'user_id' => $user->getIdU(),
                                'email' => (string) $user->getEmailU(),
                                'two_factor_attempts' => 0,
                                'created_at' => time(),
                            ];
                            $this->setForgotPasswordContext($request, $context);
                            $success = 'Email verified. Enter your 2FA code.';
                        }
                    }
                }
            } elseif ($action === 'verify_2fa') {
                $token = (string) $request->request->get('_token', '');
                if (!$this->isCsrfTokenValid('forgot_2fa', $token)) {
                    $error = 'Invalid request token. Please try again.';
                } elseif (($context['step'] ?? '') !== 'two_factor' || empty($context['user_id']) || empty($context['email'])) {
                    $this->clearForgotPasswordContext($request);
                    $context = [];
                    $error = 'Reset session expired. Please start again.';
                } else {
                    $code = preg_replace('/\s+/', '', trim((string) $request->request->get('code', '')));
                    $verified = is_string($code) && $twoFactorService->verifyCode((int) $context['user_id'], $code);

                    if (!$verified) {
                        $attempts = (int) ($context['two_factor_attempts'] ?? 0) + 1;
                        $context['two_factor_attempts'] = $attempts;

                        if ($attempts >= self::FORGOT_2FA_MAX_ATTEMPTS) {
                            $this->clearForgotPasswordContext($request);
                            $context = [];
                            $error = 'Too many invalid 2FA attempts. Please restart the process.';
                        } else {
                            $this->setForgotPasswordContext($request, $context);
                            $error = 'Invalid 2FA code.';
                        }
                    } else {
                        $emailCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                        $emailCodeHash = password_hash($emailCode, PASSWORD_BCRYPT);

                        $context['step'] = 'email_code';
                        $context['two_factor_verified_at'] = time();
                        $context['two_factor_attempts'] = 0;
                        $context['email_code_hash'] = $emailCodeHash;
                        $context['email_code_expires_at'] = time() + self::FORGOT_EMAIL_CODE_TTL_SECONDS;
                        $context['email_code_attempts'] = 0;
                        $context['email_code_last_sent_at'] = time();
                        $this->setForgotPasswordContext($request, $context);

                        try {
                            $this->sendForgotPasswordCodeEmail($mailer, (string) $context['email'], $emailCode);
                            $success = 'Verification code sent by email.';
                        } catch (\Throwable) {
                            $error = '2FA is valid but email sending failed. Please retry sending the code.';
                        }
                    }
                }
            } elseif ($action === 'resend_email_code') {
                $token = (string) $request->request->get('_token', '');
                if (!$this->isCsrfTokenValid('forgot_email_code', $token)) {
                    $error = 'Invalid request token. Please try again.';
                } elseif (($context['step'] ?? '') !== 'email_code' || empty($context['email'])) {
                    $this->clearForgotPasswordContext($request);
                    $context = [];
                    $error = 'Reset session expired. Please start again.';
                } else {
                    $lastSentAt = (int) ($context['email_code_last_sent_at'] ?? 0);
                    if ($lastSentAt > 0 && (time() - $lastSentAt) < self::FORGOT_EMAIL_RESEND_COOLDOWN_SECONDS) {
                        $remaining = self::FORGOT_EMAIL_RESEND_COOLDOWN_SECONDS - (time() - $lastSentAt);
                        $error = 'Please wait ' . $remaining . ' seconds before requesting a new code.';
                    } else {
                        $emailCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                        $context['email_code_hash'] = password_hash($emailCode, PASSWORD_BCRYPT);
                        $context['email_code_expires_at'] = time() + self::FORGOT_EMAIL_CODE_TTL_SECONDS;
                        $context['email_code_attempts'] = 0;
                        $context['email_code_last_sent_at'] = time();
                        $this->setForgotPasswordContext($request, $context);

                        try {
                            $this->sendForgotPasswordCodeEmail($mailer, (string) $context['email'], $emailCode);
                            $success = 'A new verification code has been sent.';
                        } catch (\Throwable) {
                            $error = 'Unable to send email now. Please try again in a moment.';
                        }
                    }
                }
            } elseif ($action === 'verify_email_code') {
                $token = (string) $request->request->get('_token', '');
                if (!$this->isCsrfTokenValid('forgot_email_code', $token)) {
                    $error = 'Invalid request token. Please try again.';
                } elseif (($context['step'] ?? '') !== 'email_code') {
                    $this->clearForgotPasswordContext($request);
                    $context = [];
                    $error = 'Reset session expired. Please start again.';
                } else {
                    $expiresAt = (int) ($context['email_code_expires_at'] ?? 0);
                    if ($expiresAt <= 0 || time() > $expiresAt) {
                        $error = 'Verification code expired. Please request a new code.';
                    } else {
                        $attempts = (int) ($context['email_code_attempts'] ?? 0);
                        if ($attempts >= self::FORGOT_EMAIL_CODE_MAX_ATTEMPTS) {
                            $this->clearForgotPasswordContext($request);
                            $context = [];
                            $error = 'Too many invalid email code attempts. Please restart the process.';
                        } else {
                            $inputCode = preg_replace('/\s+/', '', trim((string) $request->request->get('email_code', '')));
                            $hash = (string) ($context['email_code_hash'] ?? '');
                            $isValidCode = is_string($inputCode)
                                && preg_match('/^\d{6}$/', $inputCode)
                                && $hash !== ''
                                && password_verify($inputCode, $hash);

                            if (!$isValidCode) {
                                $context['email_code_attempts'] = $attempts + 1;
                                $this->setForgotPasswordContext($request, $context);
                                $error = 'Invalid verification code.';
                            } else {
                                $context['step'] = 'reset_password';
                                $context['email_verified_at'] = time();
                                unset($context['email_code_hash'], $context['email_code_expires_at']);
                                $this->setForgotPasswordContext($request, $context);
                                $success = 'Email code verified. You can now set a new password.';
                            }
                        }
                    }
                }
            } elseif ($action === 'reset_password') {
                $token = (string) $request->request->get('_token', '');
                if (!$this->isCsrfTokenValid('forgot_reset_password', $token)) {
                    $error = 'Invalid request token. Please try again.';
                } elseif (($context['step'] ?? '') !== 'reset_password' || empty($context['user_id'])) {
                    $this->clearForgotPasswordContext($request);
                    $context = [];
                    $error = 'Reset session expired. Please start again.';
                } else {
                    $newPassword = (string) $request->request->get('new_password', '');
                    $confirmPassword = (string) $request->request->get('confirm_password', '');

                    if ($newPassword === '' || strlen($newPassword) < 6) {
                        $error = 'Password must be at least 6 characters.';
                    } elseif (!preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/\d/', $newPassword)) {
                        $error = 'Password must include uppercase, lowercase, and a number.';
                    } elseif ($newPassword !== $confirmPassword) {
                        $error = 'Password confirmation does not match.';
                    } else {
                        $user = $em->getRepository(Utilisateur::class)->find((int) $context['user_id']);

                        if (!$user instanceof Utilisateur) {
                            $this->clearForgotPasswordContext($request);
                            $context = [];
                            $error = 'User no longer exists. Please restart.';
                        } else {
                            $user->setMotDePasseU(password_hash($newPassword, PASSWORD_BCRYPT));
                            $em->flush();

                            $session = $request->getSession();
                            $session->set('user_id', $user->getIdU());
                            $session->set('user_email', $user->getEmailU());
                            $session->set('user_role', $user->getRoleU());
                            $session->set('user_name', $user->getPrenomU() . ' ' . $user->getNomU());

                            $this->clearForgotPasswordContext($request);

                            return $this->redirectToRoute('app_pages_home');
                        }
                    }
                }
            }
        }

        $context = $this->getForgotPasswordContext($request);
        $step = (string) ($context['step'] ?? 'email');
        if (!in_array($step, ['email', 'two_factor', 'email_code', 'reset_password'], true)) {
            $step = 'email';
        }

        return $this->render('auth/forgot_password.html.twig', [
            'step' => $step,
            'error' => $error,
            'success' => $success,
            'masked_email' => $this->maskEmail((string) ($context['email'] ?? '')),
        ]);
    }

    #[Route('/face-id/detect', name: 'app_face_id_detect', methods: ['POST'])]
    public function faceIdDetect(Request $request, FaceIdClient $faceIdClient): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true);
        $image = $payload['image'] ?? null;

        if (!is_string($image) || $image === '') {
            return new JsonResponse(['ok' => false, 'error' => 'Missing frame data'], 400);
        }

        try {
            $result = $faceIdClient->detect($image);
            
            // If the service returned an error, return it to the client
            if (isset($result['ok']) && !$result['ok']) {
                return new JsonResponse($result, 503);
            }
            
            return new JsonResponse($result);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'ok' => false, 
                'error' => 'Face ID service error: ' . $e->getMessage()
            ], 503);
        }
    }

    #[Route('/face-id/recognize', name: 'app_face_id_recognize', methods: ['POST'])]
    public function faceIdRecognize(Request $request, FaceIdClient $faceIdClient): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true);
        $image = $payload['image'] ?? null;

        if (!is_string($image) || $image === '') {
            return new JsonResponse(['ok' => false, 'error' => 'Missing frame data'], 400);
        }

        try {
            $result = $faceIdClient->recognize($image);
            
            // If the service returned an error, return it to the client
            if (isset($result['ok']) && !$result['ok']) {
                return new JsonResponse($result, 503);
            }
            
            return new JsonResponse($result);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'ok' => false, 
                'error' => 'Face ID service error: ' . $e->getMessage()
            ], 503);
        }
    }

    #[Route('/face-id/login', name: 'app_face_id_login', methods: ['POST'])]
    public function faceIdLogin(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true);
        $userId = isset($payload['userId']) ? (int) $payload['userId'] : 0;

        if ($userId <= 0) {
            return new JsonResponse(['ok' => false, 'error' => 'Invalid user id'], 400);
        }

        $user = $em->getRepository(Utilisateur::class)->find($userId);
        if (!$user) {
            return new JsonResponse(['ok' => false, 'error' => 'User not found'], 404);
        }

        $session = $request->getSession();
        $session->set('user_id', $user->getIdU());
        $session->set('user_email', $user->getEmailU());
        $session->set('user_role', $user->getRoleU());
        $session->set('user_name', $user->getPrenomU() . ' ' . $user->getNomU());

        return new JsonResponse([
            'ok' => true,
            'redirect' => $this->generateUrl('app_pages_home'),
        ]);
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
            return $clientRegistry->getClient('github')
                ->redirect(['user:email']);
        }

        #[Route('/connect/github/check', name: 'connect_github_check')]
        public function connectGithubCheck(ClientRegistry $clientRegistry, EntityManagerInterface $em, Request $request)
        {
            $client = $clientRegistry->getClient('github');
            $githubUser = $client->fetchUser();

            $email = $githubUser->getEmail();

            // GitHub peut retourner null email
            if (!$email) {
                $email = $githubUser->getLogin().'@github.com';
            }

            $user = $em->getRepository(Utilisateur::class)
                ->findOneBy(['email_u' => $email]);

            if (!$user) {
                $user = new Utilisateur();
                $user->setEmailU($email);
                $user->setPrenomU($githubUser->getLogin());
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
    public function signup(Request $request, EntityManagerInterface $em, FaceEncodingStore $faceEncodingStore): Response
    {
        if ($request->isMethod('POST')) {
            $email = trim($request->request->get('email', ''));
            $password = trim($request->request->get('password', ''));
            $prenom = trim($request->request->get('prenom', ''));
            $nom = trim($request->request->get('nom', ''));
            $role = trim($request->request->get('role', ''));
            $faceEmbeddingsRaw = $request->request->get('face_embeddings_json', '');

            $errors = [];

            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Valid email required';
            }

            if (empty($password) || strlen($password) < 6) {
                $errors['password'] = 'Password must be 6+ characters';
            }

            if (empty($prenom)) {
                $errors['prenom'] = 'First name required';
            }

            if (empty($nom)) {
                $errors['nom'] = 'Last name required';
            }

            if (!empty($errors)) {
                return $this->render('auth/signup.html.twig', ['errors' => $errors]);
            }

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

                if (is_string($faceEmbeddingsRaw) && $faceEmbeddingsRaw !== '') {
                    $decodedEmbeddings = json_decode($faceEmbeddingsRaw, true);
                    if (is_array($decodedEmbeddings)) {
                        $faceFile = $faceEncodingStore->saveUserEmbeddings($user, $decodedEmbeddings);
                        if ($faceFile !== null) {
                            $user->setPhotoFace($faceFile);
                            $em->flush();
                        }
                    }
                }

                $session = $request->getSession();
                $session->set('user_id', $user->getIdU());
                $session->set('user_email', $user->getEmailU());
                $session->set('user_role', $user->getRoleU());
                $session->set('user_name', $user->getPrenomU() . ' ' . $user->getNomU());

                return $this->redirectToRoute('app_pages_home');
            } catch (\Exception $e) {
                return $this->render('auth/signup.html.twig', ['error' => 'Error creating account']);
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

private function getForgotPasswordContext(Request $request): array
{
    $context = $request->getSession()->get(self::FORGOT_CONTEXT_SESSION_KEY, []);
    return is_array($context) ? $context : [];
}

private function setForgotPasswordContext(Request $request, array $context): void
{
    $request->getSession()->set(self::FORGOT_CONTEXT_SESSION_KEY, $context);
}

private function clearForgotPasswordContext(Request $request): void
{
    $request->getSession()->remove(self::FORGOT_CONTEXT_SESSION_KEY);
}

private function maskEmail(string $email): string
{
    if ($email === '' || !str_contains($email, '@')) {
        return '';
    }

    [$local, $domain] = explode('@', $email, 2);
    if ($local === '') {
        return '***@' . $domain;
    }

    $visiblePrefix = substr($local, 0, 1);
    $visibleSuffix = strlen($local) > 2 ? substr($local, -1) : '';
    return $visiblePrefix . '***' . $visibleSuffix . '@' . $domain;
}

private function sendForgotPasswordCodeEmail(MailerInterface $mailer, string $recipientEmail, string $code): void
{
    $email = (new Email())
        ->from($_ENV['MAILER_FROM'] ?? 'noreply@elfirma.tn')
        ->to($recipientEmail)
        ->subject('EL FIRMA - Password Reset Verification Code')
        ->html(
            '<div style="font-family: Arial, sans-serif; color: #1b1d0e; line-height: 1.6;">'
            . '<h2 style="color: #116530; margin: 0 0 12px;">Password Reset Verification</h2>'
            . '<p>Your verification code is:</p>'
            . '<p style="font-size: 28px; font-weight: 700; letter-spacing: 4px; margin: 12px 0; color: #116530;">' . htmlspecialchars($code, ENT_QUOTES) . '</p>'
            . '<p>This code expires in 10 minutes.</p>'
            . '<p>If you did not request this reset, please ignore this email.</p>'
            . '</div>'
        );

    $mailer->send($email);
}
}