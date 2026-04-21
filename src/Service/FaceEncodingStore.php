<?php

namespace App\Service;

use App\Entity\Utilisateur;

class FaceEncodingStore
{
    private string $encodingsDir;

    public function __construct(string $encodingsDir)
    {
        $this->encodingsDir = $encodingsDir;
        @mkdir($this->encodingsDir, 0755, true);
    }

    public function saveUserEmbeddings(Utilisateur $user, array $embeddings): ?string
    {
        try {
            $filename = "user_{$user->getIdU()}.json";
            $filepath = "{$this->encodingsDir}/{$filename}";

            $data = [
                'user_id' => $user->getIdU(),
                'email' => $user->getEmailU(),
                'first_name' => $user->getPrenomU(),
                'last_name' => $user->getNomU(),
                'full_name' => "{$user->getPrenomU()} {$user->getNomU()}",
                'updated_at' => (new \DateTime())->format('c'),
                'embeddings' => $embeddings,
            ];

            file_put_contents($filepath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $filename;
        } catch (\Throwable $e) {
            return null;
        }
    }
}