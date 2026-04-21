<?php

declare(strict_types=1);

namespace App\Service;

use OTPHP\TOTP;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final class AdminTwoFactorService
{
    private const ISSUER = 'EL FIRMA';

    public function __construct(private readonly ParameterBagInterface $params)
    {
    }

    public function getProvisioningUri(int $userId, string $email): string
    {
        $secret = $this->getOrCreateSecret($userId);

        $totp = TOTP::create($secret);
        $totp->setLabel($email !== '' ? $email : ('admin-' . $userId));
        $totp->setIssuer(self::ISSUER);

        return $totp->getProvisioningUri();
    }

    public function verifyCode(int $userId, string $code): bool
    {
        $secret = $this->getStoredSecret($userId);
        if ($secret === null) {
            return false;
        }

<<<<<<< HEAD
        return $this->verifyCodeWithSecret($secret, $code);
    }

    public function verifyCodeWithSecret(string $secret, string $code): bool
    {
        if ($secret === '') {
            return false;
        }

=======
>>>>>>> ec33c26 (add genrationg strong password api in signup)
        $cleanCode = preg_replace('/\s+/', '', trim($code));
        if ($cleanCode === '' || !preg_match('/^\d{6}$/', $cleanCode)) {
            return false;
        }

        $totp = TOTP::create($secret);
        return $totp->verify($cleanCode, null, 1);
    }

<<<<<<< HEAD
    public function getOrCreateSecretForUser(int $userId): string
    {
        return $this->getOrCreateSecret($userId);
    }

=======
>>>>>>> ec33c26 (add genrationg strong password api in signup)
    private function getOrCreateSecret(int $userId): string
    {
        $existing = $this->getStoredSecret($userId);
        if ($existing !== null) {
            return $existing;
        }

        $totp = TOTP::create();
        $secret = $totp->getSecret();

        $this->persistSecret($userId, $secret);

        return $secret;
    }

    private function getStoredSecret(int $userId): ?string
    {
        $path = $this->getUserSecretPath($userId);
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $secret = $decoded['secret'] ?? null;
        if (!is_string($secret) || $secret === '') {
            return null;
        }

        return $secret;
    }

    private function persistSecret(int $userId, string $secret): void
    {
        $dir = $this->getStorageDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $payload = [
            'user_id' => $userId,
            'secret' => $secret,
            'created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        file_put_contents($this->getUserSecretPath($userId), json_encode($payload, JSON_PRETTY_PRINT));
    }

    private function getStorageDir(): string
    {
        return (string) $this->params->get('kernel.project_dir') . '/var/2fa';
    }

    private function getUserSecretPath(int $userId): string
    {
        return $this->getStorageDir() . '/user_' . $userId . '.json';
    }
}