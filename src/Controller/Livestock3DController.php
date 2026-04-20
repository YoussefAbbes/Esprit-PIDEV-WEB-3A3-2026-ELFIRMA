<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\LivestockRepository;
use App\Service\Livestock3DGenerationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class Livestock3DController extends AbstractController
{
    #[Route('/elfirma/3d-conception', name: 'elfirma_3d_conception', methods: ['GET'])]
    public function page(
        Livestock3DGenerationService $generationService,
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
        Livestock3DGenerationService $generationService,
        LivestockRepository $livestockRepository
    ): JsonResponse
    {
        if (!$this->isCsrfTokenValid('livestock_3d_generate', (string) $request->request->get('_token', ''))) {
            return $this->json([
                'ok' => false,
                'message' => 'Token CSRF invalide.',
            ], Response::HTTP_FORBIDDEN);
        }

        if (!$generationService->isConfigured()) {
            return $this->json([
                'ok' => false,
                'message' => 'Configuration API 3D manquante sur le serveur.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $livestockId = $request->request->getInt('livestock_id', 0);
        if ($livestockId <= 0) {
            return $this->json([
                'ok' => false,
                'message' => 'ID livestock invalide.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $livestock = $livestockRepository->findForEdit($livestockId);
        if ($livestock === null) {
            return $this->json([
                'ok' => false,
                'message' => 'Livestock introuvable.',
            ], Response::HTTP_NOT_FOUND);
        }

        $textureResolution = trim((string) $request->request->get('texture_resolution', '2048'));
        $remesh = trim((string) $request->request->get('remesh', 'none'));
        $renderMode = strtolower(trim((string) $request->request->get('render_mode', 'signature')));

        if (!in_array($textureResolution, ['512', '1024', '2048'], true)) {
            $textureResolution = '2048';
        }

        if (!in_array($remesh, ['none', 'triangle', 'quad'], true)) {
            $remesh = 'none';
        }

        if (!in_array($renderMode, ['eco', 'balanced', 'ultra', 'cinematic', 'signature'], true)) {
            $renderMode = 'signature';
        }

        $result = $generationService->generateFromLivestock(
            $livestock,
            $textureResolution !== '' ? $textureResolution : null,
            $remesh !== '' ? $remesh : null,
            $renderMode !== '' ? $renderMode : null
        );

        if ($result === null) {
            return $this->json([
                'ok' => false,
                'message' => $generationService->getLastError() ?? 'Echec de la generation 3D.',
            ], Response::HTTP_BAD_GATEWAY);
        }

        return $this->json([
            'ok' => true,
            'message' => 'Habitat 3D genere avec succes.',
            'livestock_id' => (int) ($livestock['id_elevage'] ?? 0),
            'livestock_type' => (string) ($livestock['type_elevage'] ?? ''),
            'model_url' => $result['model_url'],
            'preview_url' => $result['preview_url'],
            'file_name' => $result['file_name'],
            'file_size_bytes' => $result['file_size_bytes'],
            'prompt' => $result['prompt'],
            'render_mode' => $result['render_mode'] ?? $renderMode,
            'generation_options' => $result['three_d_options'] ?? null,
        ]);
    }

    #[Route('/api/livestock/3d/balance', name: 'api_livestock_3d_balance', methods: ['POST'])]
    public function balance(
        Request $request,
        Livestock3DGenerationService $generationService
    ): JsonResponse
    {
        if (!$this->isCsrfTokenValid('livestock_3d_balance', (string) $request->request->get('_token', ''))) {
            return $this->json([
                'ok' => false,
                'message' => 'Token CSRF invalide.',
            ], Response::HTTP_FORBIDDEN);
        }

        if (!$generationService->isConfigured()) {
            return $this->json([
                'ok' => false,
                'message' => 'Configuration API 3D manquante sur le serveur.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $balance = $generationService->fetchBalance();
        if ($balance === null) {
            return $this->json([
                'ok' => false,
                'message' => $generationService->getLastError() ?? 'Echec de recuperation du solde API.',
            ], Response::HTTP_BAD_GATEWAY);
        }

        $credits = (float) ($balance['credits'] ?? 0.0);

        return $this->json([
            'ok' => true,
            'credits' => $credits,
            'has_credits' => $credits > 0,
            'message' => $credits > 0
                ? 'Credits disponibles pour la generation 3D.'
                : 'Credits insuffisants pour la generation 3D.',
        ]);
    }
}
