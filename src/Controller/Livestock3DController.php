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
        LivestockRepository $livestockRepository,
        Tripo3DGenerationService $tripo3DGenerationService
    ): Response
    {
        return $this->render('elfirma/Livestock&Animal Management/conception_3d.html.twig', [
            'livestock_list' => $livestockRepository->findAllForManagement(),
            'is_tripo_configured' => $tripo3DGenerationService->isConfigured(),
        ]);
    }

    #[Route('/api/livestock/3d/generate', name: 'api_livestock_3d_generate', methods: ['POST'])]
    public function generate(
        Request $request,
        LivestockRepository $livestockRepository,
        Tripo3DGenerationService $tripo3DGenerationService
    ): JsonResponse
    {
        // ✅ FIX : augmente le timeout PHP pour la génération 3D (peut prendre 2-3 minutes)
        set_time_limit(300);
        ini_set('max_execution_time', '300');

        if (!$this->isCsrfTokenValid('livestock_3d_generate', (string) $request->request->get('_token', ''))) {
            return $this->json([
                'ok' => false,
                'message' => 'Token CSRF invalide.',
            ], Response::HTTP_FORBIDDEN);
        }

        if (!$tripo3DGenerationService->isConfigured()) {
            return $this->json([
                'ok' => false,
                'message' => 'Cle API Tripo3D manquante dans le serveur.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $livestockId = $request->request->getInt('livestock_id', 0);
        if ($livestockId <= 0) {
            return $this->json([
                'ok' => false,
                'message' => 'Livestock invalide.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $livestock = $livestockRepository->findForEdit($livestockId);
        if ($livestock === null) {
            return $this->json([
                'ok' => false,
                'message' => 'Livestock introuvable.',
            ], Response::HTTP_NOT_FOUND);
        }

        $renderMode = strtolower(trim((string) $request->request->get('render_mode', 'signature')));
        if (!in_array($renderMode, ['eco', 'balanced', 'ultra', 'cinematic', 'signature'], true)) {
            $renderMode = 'signature';
        }

        $result = $tripo3DGenerationService->generateFromLivestock($livestock, $renderMode);
        if ($result === null) {
            return $this->json([
                'ok' => false,
                'message' => $tripo3DGenerationService->getLastError() ?? 'Generation Tripo3D echouee.',
            ], Response::HTTP_BAD_GATEWAY);
        }

        return $this->json([
            'ok' => true,
            'message' => 'Habitat 3D genere avec succes.',
            'livestock_id' => (int) ($livestock['id_elevage'] ?? 0),
            'livestock_type' => (string) ($livestock['type_elevage'] ?? ''),
            'model_url' => (string) ($result['model_url'] ?? ''),
            'preview_url' => (string) ($result['preview_url'] ?? ''),
            'file_name' => (string) ($result['file_name'] ?? 'livestock.glb'),
            'file_size_bytes' => (int) ($result['file_size_bytes'] ?? 0),
            'prompt' => (string) ($result['prompt'] ?? ''),
            'render_mode' => (string) ($result['render_mode'] ?? $renderMode),
        ]);
    }
}
