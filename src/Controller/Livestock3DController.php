<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\LivestockRepository;
use App\Service\Tripo3DGenerationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class Livestock3DController extends AbstractController
{
    #[Route('/elfirma/3d-conception', name: 'elfirma_3d_conception', methods: ['GET'])]
    public function page(
        Tripo3DGenerationService $generationService,
        LivestockRepository $livestockRepository
    ): Response
    {
        return $this->render('elfirma/Livestock&Animal Management/conception_3d.html.twig', [
            'is_3d_configured' => $generationService->isConfigured(),
            'livestock_list' => $livestockRepository->findAllForManagement(),
        ]);
    }

    #[Route('/api/livestock/3d/generate', name: 'api_livestock_3d_generate', methods: ['POST'])]
    public function generate(
        Request $request,
        Tripo3DGenerationService $generationService,
        LivestockRepository $livestockRepository
    ): JsonResponse
    {
        if (!$this->isCsrfTokenValid('livestock_3d_generate', (string) $request->request->get('_token', ''))) {
            return $this->json([
                'ok' => false,
                'message' => 'Invalid CSRF token.',
            ], Response::HTTP_FORBIDDEN);
        }

        if (!$generationService->isConfigured()) {
            return $this->json([
                'ok' => false,
                'message' => '3D API configuration missing on server.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $livestockId = $request->request->getInt('livestock_id', 0);
        if ($livestockId <= 0) {
            return $this->json([
                'ok' => false,
                'message' => 'Invalid livestock ID.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $livestock = $livestockRepository->findForEdit($livestockId);
        if ($livestock === null) {
            return $this->json([
                'ok' => false,
                'message' => 'Livestock not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $textureResolution = trim((string) $request->request->get('texture_resolution', '2048'));
        $remesh = trim((string) $request->request->get('remesh', 'none'));
        $renderMode = strtolower(trim((string) $request->request->get('render_mode', 'signature')));

        if (!\in_array($textureResolution, ['512', '1024', '2048'], true)) {
            $textureResolution = '2048';
        }

        if (!\in_array($remesh, ['none', 'triangle', 'quad'], true)) {
            $remesh = 'none';
        }

        if (!\in_array($renderMode, ['eco', 'balanced', 'ultra', 'cinematic', 'signature'], true)) {
            $renderMode = 'signature';
        }

        $result = $generationService->generateFromLivestock($livestock);

        if ($result === null) {
            $errorMessage = $generationService->getLastError() ?? '3D generation failed.';
            $isCreditError = str_contains($errorMessage, 'HTTP 403')
                || str_contains($errorMessage, 'HTTP 401')
                || str_contains(strtolower($errorMessage), 'credit')
                || str_contains(strtolower($errorMessage), 'permission');
            return $this->json([
                'ok' => false,
                'message' => $errorMessage,
            ], $isCreditError ? Response::HTTP_PAYMENT_REQUIRED : Response::HTTP_BAD_GATEWAY);
        }

        return $this->json([
            'ok' => true,
            'message' => '3D habitat generated successfully.',
            'livestock_id' => (int) ($livestock['id_elevage'] ?? 0),
            'livestock_type' => (string) ($livestock['type_elevage'] ?? ''),
            'model_url' => $result['model_url'] ?? null,
            'preview_url' => $result['preview_url'] ?? null,
            'file_name' => $result['file_name'] ?? 'livestock-model.glb',
            'file_size_bytes' => (int) ($result['file_size_bytes'] ?? 0),
            'prompt' => $result['prompt'] ?? '',
            'render_mode' => $renderMode,
            'generation_options' => null,
        ]);
    }

    #[Route('/api/livestock/3d/balance', name: 'api_livestock_3d_balance', methods: ['POST'])]
    public function balance(
        Request $request,
        Tripo3DGenerationService $generationService
    ): JsonResponse
    {
        if (!$this->isCsrfTokenValid('livestock_3d_balance', (string) $request->request->get('_token', ''))) {
            return $this->json([
                'ok' => false,
                'message' => 'Invalid CSRF token.',
            ], Response::HTTP_FORBIDDEN);
        }

        if (!$generationService->isConfigured()) {
            return $this->json([
                'ok' => false,
                'message' => '3D API configuration missing on server.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'ok' => true,
            'credits' => null,
            'has_credits' => true,
            'message' => 'Tripo3D configuration present. The actual balance is verified at generation time.',
        ]);
    }
}
