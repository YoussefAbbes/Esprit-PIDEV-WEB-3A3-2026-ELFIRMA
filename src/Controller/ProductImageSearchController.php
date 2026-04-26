<?php

namespace App\Controller;

use App\Entity\Produit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Process;
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

        $clipMatches = $this->searchByClipPython((string) $imageFile->getPathname(), $produits, $projectDir);
        if ($clipMatches !== null) {
            return new JsonResponse([
                'ok' => true,
                'count' => count($clipMatches),
                'product_ids' => array_values(array_map(static fn (array $row): int => (int) $row['id'], $clipMatches)),
                'results' => $clipMatches,
                'mode' => 'clip',
                'message' => count($clipMatches) > 0
                    ? 'CLIP image similarity search completed.'
                    : 'No visually similar product found.',
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
            'mode' => 'histogram',
        ]);
    }

    /**
     * @param list<Produit> $produits
     *
     * @return list<array{id:int,name:string,similarity:float}>|null
     */
    private function searchByClipPython(string $queryImagePath, array $produits, string $projectDir): ?array
    {
        $scriptPath = $projectDir . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'recommender' . DIRECTORY_SEPARATOR . 'image_clip_search.py';
        if (!is_file($scriptPath)) {
            return null;
        }

        $productsPayload = [];
        foreach ($produits as $produit) {
            if (!$produit instanceof Produit) {
                continue;
            }

            $imageName = trim((string) ($produit->getImage() ?? ''));
            if ($imageName === '') {
                continue;
            }

            $imagePath = $projectDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'produits' . DIRECTORY_SEPARATOR . $imageName;
            if (!is_file($imagePath) || !is_readable($imagePath)) {
                continue;
            }

            $productsPayload[] = [
                'id' => (int) ($produit->getIdProduit() ?? 0),
                'name' => (string) ($produit->getNom() ?? ''),
                'image_path' => $imagePath,
            ];
        }

        if ($productsPayload === []) {
            return [];
        }

        $payloadFile = tempnam(sys_get_temp_dir(), 'clip_search_payload_');
        if (!is_string($payloadFile) || $payloadFile === '') {
            return null;
        }

        $payloadJson = json_encode([
            'query_image' => $queryImagePath,
            'products' => $productsPayload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($payloadJson) || $payloadJson === '' || @file_put_contents($payloadFile, $payloadJson) === false) {
            @unlink($payloadFile);

            return null;
        }

        try {
            $python = $this->resolvePythonBinary($projectDir);
            $process = new Process([$python, $scriptPath, '--payload-file', $payloadFile], $projectDir);
            // First CLIP run can take several minutes while Hugging Face model files are downloaded.
            $process->setTimeout(900);
            $process->run();

            if (!$process->isSuccessful()) {
                return null;
            }

            $output = trim($process->getOutput());
            if ($output === '') {
                return null;
            }

            $decoded = json_decode($output, true);
            if (!is_array($decoded) || !($decoded['ok'] ?? false)) {
                return null;
            }

            $results = $decoded['results'] ?? [];
            if (!is_array($results)) {
                return [];
            }

            $matches = [];
            foreach ($results as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }

                $matches[] = [
                    'id' => $id,
                    'name' => (string) ($row['name'] ?? ''),
                    'similarity' => round((float) ($row['similarity'] ?? 0.0), 4),
                ];
            }

            return array_slice($matches, 0, 24);
        } catch (\Throwable) {
            return null;
        } finally {
            @unlink($payloadFile);
        }
    }

    private function resolvePythonBinary(string $projectDir): string
    {
        $venvPython = $projectDir . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
        if (is_file($venvPython)) {
            return $venvPython;
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
