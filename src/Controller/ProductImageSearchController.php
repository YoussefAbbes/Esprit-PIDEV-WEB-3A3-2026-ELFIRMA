<?php

namespace App\Controller;

use App\Entity\Produit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ProductImageSearchController extends AbstractController
{
    #[Route('/api/products/search-image', name: 'app_api_product_image_search', methods: ['POST'])]
    public function searchByImage(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $imageFile = $request->files->get('image');
        if ($imageFile === null) {
            return new JsonResponse([
                'ok' => false,
                'message' => 'Image file is required.',
            ], 400);
        }

        $mimeType = (string) ($imageFile->getMimeType() ?? '');
        if (!str_starts_with($mimeType, 'image/')) {
            return new JsonResponse([
                'ok' => false,
                'message' => 'Invalid file type. Please upload an image.',
            ], 415);
        }

        $maxBytes = 5 * 1024 * 1024;
        if ((int) $imageFile->getSize() > $maxBytes) {
            return new JsonResponse([
                'ok' => false,
                'message' => 'Image is too large. Maximum size is 5MB.',
            ], 413);
        }

        $projectDir = (string) $this->getParameter('kernel.project_dir');
        $produits = $em->getRepository(Produit::class)->findBy(['statut' => 'Disponible']);

        $clipResult = $this->searchWithClip((string) $imageFile->getPathname(), $projectDir);
        if ((bool) ($clipResult['ok'] ?? false) && is_array($clipResult['results'] ?? null) && $clipResult['results'] !== []) {
            $clipMatches = $this->mapClipMatchesToProducts($produits, $clipResult['results']);

            return new JsonResponse([
                'ok' => true,
                'count' => count($clipMatches),
                'product_ids' => array_values(array_map(static fn (array $row): int => (int) $row['id'], $clipMatches)),
                'results' => $clipMatches,
                'mode' => 'clip',
                'message' => 'Deep visual search powered by CLIP.',
            ]);
        }

        $querySignature = $this->extractImageSignature((string) $imageFile->getPathname());
        if ($querySignature === []) {
            $fallbackMatches = $this->searchByFilenameHeuristic((string) $imageFile->getClientOriginalName(), $produits);

            return new JsonResponse([
                'ok' => true,
                'count' => count($fallbackMatches),
                'product_ids' => array_values(array_map(static fn (array $row): int => (int) $row['id'], $fallbackMatches)),
                'results' => $fallbackMatches,
                'mode' => 'filename_fallback',
                'message' => 'Image engine unavailable, fallback search applied.',
                'clip_error' => (string) ($clipResult['message'] ?? ''),
            ]);
        }

        $matches = [];
        foreach ($produits as $produit) {
            if (!$produit instanceof Produit) {
                continue;
            }

            $imageName = trim((string) ($produit->getImage() ?? ''));
            if ($imageName === '') {
                continue;
            }

            $productImagePath = $projectDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'produits' . DIRECTORY_SEPARATOR . $imageName;
            if (!is_file($productImagePath) || !is_readable($productImagePath)) {
                continue;
            }

            $productSignature = $this->extractImageSignature($productImagePath);
            if ($productSignature === []) {
                continue;
            }

            $similarity = $this->cosineSimilarity($querySignature, $productSignature);
            if ($similarity <= 0.0) {
                continue;
            }

            $matches[] = [
                'id' => (int) ($produit->getIdProduit() ?? 0),
                'name' => (string) ($produit->getNom() ?? ''),
                'similarity' => round($similarity, 4),
            ];
        }

        usort($matches, static fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);
        $matches = array_slice($matches, 0, 24);

        return new JsonResponse([
            'ok' => true,
            'count' => count($matches),
            'product_ids' => array_values(array_map(static fn (array $row): int => (int) $row['id'], $matches)),
            'results' => $matches,
            'mode' => 'color_signature',
            'message' => 'Visual similarity via color signature.',
            'clip_error' => (string) ($clipResult['message'] ?? ''),
        ]);
    }

    /**
     * @param list<Produit> $produits
     * @param list<array{image:string,similarity:float}> $clipResults
     *
     * @return list<array{id:int,name:string,similarity:float}>
     */
    private function mapClipMatchesToProducts(array $produits, array $clipResults): array
    {
        $productsByImage = [];
        foreach ($produits as $produit) {
            if (!$produit instanceof Produit) {
                continue;
            }

            $imageName = trim((string) ($produit->getImage() ?? ''));
            if ($imageName === '') {
                continue;
            }

            $productsByImage[mb_strtolower(basename($imageName))] = $produit;
        }

        $matches = [];
        foreach ($clipResults as $row) {
            $imageName = mb_strtolower(basename((string) ($row['image'] ?? '')));
            if ($imageName === '' || !isset($productsByImage[$imageName])) {
                continue;
            }

            $produit = $productsByImage[$imageName];
            $matches[] = [
                'id' => (int) ($produit->getIdProduit() ?? 0),
                'name' => (string) ($produit->getNom() ?? ''),
                'similarity' => max(0.0, min(1.0, (float) ($row['similarity'] ?? 0.0))),
            ];
        }

        usort($matches, static fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);

        return array_slice($matches, 0, 24);
    }

    /**
     * @return array{ok:bool,message:string,results:list<array{image:string,similarity:float}>}
     */
    private function searchWithClip(string $queryImagePath, string $projectDir): array
    {
        $scriptPath = $projectDir . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'recommender' . DIRECTORY_SEPARATOR . 'image_clip_search.py';
        if (!is_file($scriptPath)) {
            return [
                'ok' => false,
                'message' => 'CLIP script not found.',
                'results' => [],
            ];
        }

        $pythonBinary = $this->resolvePythonBinary();
        $command = sprintf(
            '%s %s --query-image %s --project-dir %s --top-k 24 2>&1',
            escapeshellarg($pythonBinary),
            escapeshellarg($scriptPath),
            escapeshellarg($queryImagePath),
            escapeshellarg($projectDir)
        );

        $lines = [];
        $exitCode = 1;
        exec($command, $lines, $exitCode);

        $parsed = null;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim((string) $lines[$i]);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }

            $candidate = json_decode($line, true);
            if (is_array($candidate)) {
                $parsed = $candidate;
                break;
            }
        }

        if (!is_array($parsed)) {
            return [
                'ok' => false,
                'message' => 'CLIP output invalid.',
                'results' => [],
            ];
        }

        if ($exitCode !== 0 || !((bool) ($parsed['ok'] ?? false))) {
            return [
                'ok' => false,
                'message' => (string) ($parsed['message'] ?? 'CLIP search failed.'),
                'results' => [],
            ];
        }

        $results = is_array($parsed['results'] ?? null) ? $parsed['results'] : [];
        $normalized = [];
        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }

            $image = trim((string) ($row['image'] ?? ''));
            if ($image === '') {
                continue;
            }

            $normalized[] = [
                'image' => basename($image),
                'similarity' => (float) ($row['similarity'] ?? 0.0),
            ];
        }

        return [
            'ok' => true,
            'message' => (string) ($parsed['message'] ?? 'ok'),
            'results' => $normalized,
        ];
    }

    private function resolvePythonBinary(): string
    {
        $localAppData = getenv('LOCALAPPDATA');
        if (is_string($localAppData) && $localAppData !== '') {
            $candidate = $localAppData . DIRECTORY_SEPARATOR . 'Programs' . DIRECTORY_SEPARATOR . 'Python' . DIRECTORY_SEPARATOR . 'Python312' . DIRECTORY_SEPARATOR . 'python.exe';
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return 'python';
    }

    /**
     * @return list<float>
     */
    private function extractImageSignature(string $imagePath): array
    {
        if (class_exists('\Imagick')) {
            $imagickSignature = $this->extractImageSignatureWithImagick($imagePath);
            if ($imagickSignature !== []) {
                return $imagickSignature;
            }
        }

        if (function_exists('imagecreatefromstring')) {
            return $this->extractImageSignatureWithGd($imagePath);
        }

        return [];
    }

    /**
     * @return list<float>
     */
    private function extractImageSignatureWithGd(string $imagePath): array
    {
        $binary = @file_get_contents($imagePath);
        if ($binary === false || $binary === '') {
            return [];
        }

        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            return [];
        }

        $size = 16;
        $resized = imagecreatetruecolor($size, $size);
        if ($resized === false) {
            imagedestroy($source);

            return [];
        }

        imagecopyresampled($resized, $source, 0, 0, 0, 0, $size, $size, imagesx($source), imagesy($source));

        $bins = array_fill(0, 48, 0.0);
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $rgb = imagecolorat($resized, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                $rBin = min(15, intdiv($r, 16));
                $gBin = min(15, intdiv($g, 16));
                $bBin = min(15, intdiv($b, 16));

                $bins[$rBin] += 1.0;
                $bins[16 + $gBin] += 1.0;
                $bins[32 + $bBin] += 1.0;
            }
        }

        imagedestroy($resized);
        imagedestroy($source);

        $pixelCount = (float) ($size * $size);
        foreach ($bins as $idx => $value) {
            $bins[$idx] = $value / $pixelCount;
        }

        return $bins;
    }

    /**
     * @return list<float>
     */
    private function extractImageSignatureWithImagick(string $imagePath): array
    {
        if (!class_exists('Imagick')) {
            return [];
        }

        try {
            $size = 16;
            $imagickClass = 'Imagick';
            $imagick = new $imagickClass($imagePath);
            $imagick->setImageColorspace((int) constant('Imagick::COLORSPACE_RGB'));
            $imagick->resizeImage($size, $size, (int) constant('Imagick::FILTER_LANCZOS'), 1.0, true);
            $imagick->setImageType((int) constant('Imagick::IMGTYPE_TRUECOLOR'));

            $pixels = $imagick->exportImagePixels(0, 0, $size, $size, 'RGB', (int) constant('Imagick::PIXEL_CHAR'));
            if (!is_array($pixels) || $pixels === []) {
                $imagick->clear();
                $imagick->destroy();

                return [];
            }

            $bins = array_fill(0, 48, 0.0);
            $count = count($pixels);
            for ($i = 0; $i + 2 < $count; $i += 3) {
                $r = (int) $pixels[$i];
                $g = (int) $pixels[$i + 1];
                $b = (int) $pixels[$i + 2];

                $rBin = min(15, intdiv($r, 16));
                $gBin = min(15, intdiv($g, 16));
                $bBin = min(15, intdiv($b, 16));

                $bins[$rBin] += 1.0;
                $bins[16 + $gBin] += 1.0;
                $bins[32 + $bBin] += 1.0;
            }

            $imagick->clear();
            $imagick->destroy();

            $pixelCount = (float) ($size * $size);
            foreach ($bins as $idx => $value) {
                $bins[$idx] = $value / $pixelCount;
            }

            return $bins;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param list<Produit> $produits
     *
     * @return list<array{id:int,name:string,similarity:float}>
     */
    private function searchByFilenameHeuristic(string $filename, array $produits): array
    {
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $tokens = preg_split('/[^a-z0-9]+/i', mb_strtolower(trim($basename))) ?: [];
        $tokens = array_values(array_filter($tokens, static fn (string $token): bool => mb_strlen($token) >= 2));

        $matches = [];
        foreach ($produits as $produit) {
            if (!$produit instanceof Produit) {
                continue;
            }

            $name = mb_strtolower((string) ($produit->getNom() ?? ''));
            if ($name === '') {
                continue;
            }

            $score = 0.0;
            foreach ($tokens as $token) {
                if (str_contains($name, $token)) {
                    $score += 0.35;
                }
            }

            if ($score <= 0.0) {
                continue;
            }

            $matches[] = [
                'id' => (int) ($produit->getIdProduit() ?? 0),
                'name' => (string) ($produit->getNom() ?? ''),
                'similarity' => min(0.99, $score),
            ];
        }

        usort($matches, static fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);

        return array_slice($matches, 0, 24);
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $length = min(count($a), count($b));
        if ($length === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $length; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
