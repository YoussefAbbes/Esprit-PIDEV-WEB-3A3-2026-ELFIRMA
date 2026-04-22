<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AdminTwoFactorService;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;

final class AdminTwoFactorController extends AbstractController
{
    private const ADMIN_2FA_TTL_SECONDS = 1800;
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    #[Route('/admin-panel/secure', name: 'app_admin_panel_entry', methods: ['GET'])]
    public function entry(Request $request): Response
    {
        $session = $request->getSession();
        
        $userRole = $session->get('user_role');
        $userId = $session->get('user_id');
        
        $this->logger->info('📍 AdminTwoFactorController::entry() called', [
            'user_role' => $userRole,
            'user_id' => $userId,
            'session_id' => $session->getId(),
        ]);

        if ($userRole !== 'admin' || !$userId) {
            $this->logger->warning('❌ User is not admin or no user_id', [
                'user_role' => $userRole,
                'user_id' => $userId,
            ]);
            return $this->forceLogout($request);
        }

        $isValid = $this->isAdminTwoFactorValid($request);
        $this->logger->info('🔐 2FA validation check', [
            'is_valid' => $isValid,
            'admin_2fa_verified' => $session->get('admin_2fa_verified'),
            'admin_2fa_verified_at' => $session->get('admin_2fa_verified_at'),
        ]);

        if ($isValid) {
            $this->logger->info('✅ 2FA is valid, redirecting to user_page');
            return $this->redirectToRoute('user_page');
        }

        $this->logger->info('⚠️ 2FA not valid, redirecting to 2FA form');
        return $this->redirectToRoute('app_admin_panel_2fa');
    }

    #[Route('/admin-panel/2fa', name: 'app_admin_panel_2fa', methods: ['GET', 'POST'])]
    public function challenge(Request $request, AdminTwoFactorService $twoFactorService): Response
    {
        $session = $request->getSession();
        $userId = (int) $session->get('user_id', 0);
        $userRole = (string) $session->get('user_role', '');
        $userEmail = (string) $session->get('user_email', '');

        $this->logger->info('📍 AdminTwoFactorController::challenge() called', [
            'method' => $request->getMethod(),
            'user_id' => $userId,
            'user_role' => $userRole,
            'user_email' => $userEmail,
        ]);

        if ($userId <= 0 || $userRole !== 'admin') {
            $this->logger->warning('❌ Invalid user for 2FA challenge', [
                'user_id' => $userId,
                'user_role' => $userRole,
            ]);
            return $this->forceLogout($request);
        }

        if ($request->isMethod('POST')) {
            $code = (string) $request->request->get('code', '');
            
            $this->logger->info('🔑 2FA code received', [
                'code_length' => strlen($code),
                'user_id' => $userId,
            ]);

            $verified = $twoFactorService->verifyCode($userId, $code);
            $this->logger->info('✓ verifyCode result', ['verified' => $verified]);

            // Vérification avec secret temporaire si existe
            if (!$verified) {
                $pendingSecret = (string) $session->get('admin_2fa_pending_secret', '');
                $this->logger->info('🔍 Trying pending secret', [
                    'has_pending_secret' => !empty($pendingSecret),
                ]);
                
                if ($pendingSecret !== '') {
                    $verified = $twoFactorService->verifyCodeWithSecret($pendingSecret, $code);
                    $this->logger->info('✓ verifyCodeWithSecret result', ['verified' => $verified]);
                }
            }

            if (!$verified) {
                $this->logger->warning('❌ Invalid 2FA code', [
                    'user_id' => $userId,
                    'code_length' => strlen($code),
                ]);
                return $this->forceLogout($request, 'invalid_code');
            }

            $this->logger->info('✅ 2FA code verified successfully', [
                'user_id' => $userId,
            ]);

            $session->set('admin_2fa_verified', true);
            $session->set('admin_2fa_verified_at', time());
            $session->set('admin_2fa_user_id', $userId);
            $session->remove('admin_2fa_pending_secret');

            $this->logger->info('✅ Session updated, redirecting to user_page', [
                'admin_2fa_verified' => $session->get('admin_2fa_verified'),
                'admin_2fa_verified_at' => $session->get('admin_2fa_verified_at'),
                'admin_2fa_user_id' => $session->get('admin_2fa_user_id'),
            ]);

            return $this->redirectToRoute('user_page');
        }

        $this->logger->info('📋 Displaying 2FA form for user', [
            'user_id' => $userId,
            'user_email' => $userEmail,
        ]);

        // Génération du secret si nécessaire
        $secret = $twoFactorService->getOrCreateSecretForUser($userId);
        $session->set('admin_2fa_pending_secret', $secret);

        $provisioningUri = $twoFactorService->getProvisioningUri($userId, $userEmail);

        $qrCode = new QrCode(
            data: $provisioningUri,
            encoding: new Encoding('UTF-8'),
            size: 260,
            margin: 12
        );
        $writer = new PngWriter();
        $qrDataUri = $writer->write($qrCode)->getDataUri();

        return $this->render('auth/admin_2fa.html.twig', [
            'qr_data_uri' => $qrDataUri,
            'authenticator_label' => $userEmail !== '' ? $userEmail : ('admin-' . $userId),
        ]);
    }

    public static function hasValidAdminTwoFactor(Request $request): bool
    {
        $session = $request->getSession();

        if ($session->get('user_role') !== 'admin') {
            return false;
        }

        $admin2faVerified = $session->get('admin_2fa_verified');
        if (!in_array($admin2faVerified, [true, 1, '1'], true)) {
            return false;
        }

        $verifiedAt = (int) $session->get('admin_2fa_verified_at', 0);
        $verifiedForUser = (int) $session->get('admin_2fa_user_id', 0);
        $currentUser = (int) $session->get('user_id', 0);

        if ($verifiedAt <= 0 || $verifiedForUser <= 0 || $currentUser <= 0) {
            return false;
        }

        if ($verifiedForUser !== $currentUser) {
            return false;
        }

        return (time() - $verifiedAt) <= self::ADMIN_2FA_TTL_SECONDS;
    }

    private function isAdminTwoFactorValid(Request $request): bool
    {
        $result = self::hasValidAdminTwoFactor($request);
        
        $session = $request->getSession();
        $this->logger->debug('🔍 isAdminTwoFactorValid() result', [
            'result' => $result,
            'user_role' => $session->get('user_role'),
            'admin_2fa_verified' => $session->get('admin_2fa_verified'),
            'time_now' => time(),
            'admin_2fa_verified_at' => $session->get('admin_2fa_verified_at'),
            'ttl_seconds' => self::ADMIN_2FA_TTL_SECONDS,
        ]);
        
        return $result;
    }

    private function forceLogout(Request $request, ?string $twoFaError = null): Response
    {
        $session = $request->getSession();

        $session->clear();
        $session->invalidate();

        $params = [];
        if ($twoFaError !== null && $twoFaError !== '') {
            $params['twofa_error'] = $twoFaError;
        }

        return $this->redirectToRoute('app_login', $params);
    }
}