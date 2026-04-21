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

final class AdminTwoFactorController extends AbstractController
{
    private const ADMIN_2FA_TTL_SECONDS = 1800;

    #[Route('/admin-panel/secure', name: 'app_admin_panel_entry', methods: ['GET'])]
    public function entry(Request $request): Response
    {
        $session = $request->getSession();

        if ($session->get('user_role') !== 'admin' || !$session->get('user_id')) {
            return $this->forceLogout($request);
        }

        if ($this->isAdminTwoFactorValid($request)) {
            return $this->redirectToRoute('elfirma_page', ['module' => 'utilisateurs']);
        }

        return $this->redirectToRoute('app_admin_panel_2fa');
    }

    #[Route('/admin-panel/2fa', name: 'app_admin_panel_2fa', methods: ['GET', 'POST'])]
    public function challenge(Request $request, AdminTwoFactorService $twoFactorService): Response
    {
        $session = $request->getSession();
        $userId = (int) $session->get('user_id', 0);
        $userRole = (string) $session->get('user_role', '');
        $userEmail = (string) $session->get('user_email', '');

        if ($userId <= 0 || $userRole !== 'admin') {
            return $this->forceLogout($request);
        }

        if ($request->isMethod('POST')) {
            $code = (string) $request->request->get('code', '');
            $verified = $twoFactorService->verifyCode($userId, $code);

            // Vérification avec secret temporaire si existe
            if (!$verified) {
                $pendingSecret = (string) $session->get('admin_2fa_pending_secret', '');
                if ($pendingSecret !== '') {
                    $verified = $twoFactorService->verifyCodeWithSecret($pendingSecret, $code);
                }
            }

            if (!$verified) {
                return $this->forceLogout($request, 'invalid_code');
            }

            $session->set('admin_2fa_verified', true);
            $session->set('admin_2fa_verified_at', time());
            $session->set('admin_2fa_user_id', $userId);
            $session->remove('admin_2fa_pending_secret');

            return $this->redirectToRoute('elfirma_page', ['module' => 'utilisateurs']);
        }

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
        return self::hasValidAdminTwoFactor($request);
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