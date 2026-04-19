<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ParcelleRepository;
use App\Service\IrrigationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route("/irrigation")]
final class IrrigationController extends AbstractController
{
    #[Route("", name: "irrigation_index", methods: ["GET"])]
    public function index(
        Request $request,
        ParcelleRepository $parcelleRepository,
        IrrigationService $irrigationService,
    ): Response {
        $parcelles = $parcelleRepository->findBy([], ["id" => "ASC"]);
        $selectedParcelleId = $request->query->getInt("parcelle", 0);

        $selectedParcelle = null;
        if ($selectedParcelleId > 0) {
            $selectedParcelle = $parcelleRepository->find($selectedParcelleId);
        }

        if ($selectedParcelle === null && $parcelles !== []) {
            $selectedParcelle = $parcelles[0];
        }

        $initialState = $selectedParcelle !== null
            ? $irrigationService->getLatestStatePayload($selectedParcelle)
            : $irrigationService->emptyStatePayload();

        $initialEvents = $selectedParcelle !== null
            ? $irrigationService->getRecentEventsPayload($selectedParcelle)
            : [];

        return $this->render("elfirma/parcelles/irrigation.html.twig", [
            "parcelles" => $parcelles,
            "selectedParcelle" => $selectedParcelle,
            "initialState" => $initialState,
            "initialEvents" => $initialEvents,
        ]);
    }

    #[Route("/{parcelleId}/command", name: "irrigation_command", methods: ["POST"], requirements: ["parcelleId" => "\\d+"])]
    public function command(
        int $parcelleId,
        Request $request,
        ParcelleRepository $parcelleRepository,
        IrrigationService $irrigationService,
    ): JsonResponse {
        $parcelle = $parcelleRepository->find($parcelleId);
        if ($parcelle === null) {
            return $this->json([
                "success" => false,
                "error" => "Plot not found.",
            ], Response::HTTP_NOT_FOUND);
        }

        $payload = $this->extractPayload($request);
        $token = (string) ($payload["_token"] ?? $request->headers->get("X-CSRF-TOKEN", ""));

        if (!$this->isCsrfTokenValid("irrigation_command", $token)) {
            return $this->json([
                "success" => false,
                "error" => "Invalid CSRF token.",
            ], Response::HTTP_FORBIDDEN);
        }

        $command = $irrigationService->normalizeCommand((string) ($payload["command"] ?? ""));

        if (!$irrigationService->isValidCommand($command)) {
            return $this->json([
                "success" => false,
                "error" => "Invalid command. Allowed values: AUTO, MANUAL_ON, MANUAL_OFF.",
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $queued = $irrigationService->queueCommand($parcelle, $command);

            return $this->json([
                "success" => true,
                "message" => "Command sent.",
                "command" => $queued->getCommand(),
                "commandId" => $queued->getId(),
            ], Response::HTTP_CREATED);
        } catch (\Throwable $exception) {
            return $this->json([
                "success" => false,
                "error" => "Unable to save command.",
                "details" => $exception->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route("/{parcelleId}/state", name: "irrigation_state", methods: ["GET"], requirements: ["parcelleId" => "\\d+"])]
    public function state(
        int $parcelleId,
        ParcelleRepository $parcelleRepository,
        IrrigationService $irrigationService,
    ): JsonResponse {
        $parcelle = $parcelleRepository->find($parcelleId);
        if ($parcelle === null) {
            return $this->json([
                "success" => false,
                "error" => "Plot not found.",
            ], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            "success" => true,
            "state" => $irrigationService->getLatestStatePayload($parcelle),
        ]);
    }

    #[Route("/{parcelleId}/events", name: "irrigation_events", methods: ["GET"], requirements: ["parcelleId" => "\\d+"])]
    public function events(
        int $parcelleId,
        ParcelleRepository $parcelleRepository,
        IrrigationService $irrigationService,
    ): JsonResponse {
        $parcelle = $parcelleRepository->find($parcelleId);
        if ($parcelle === null) {
            return $this->json([
                "success" => false,
                "error" => "Plot not found.",
            ], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            "success" => true,
            "events" => $irrigationService->getRecentEventsPayload($parcelle, 20),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function extractPayload(Request $request): array
    {
        $content = trim((string) $request->getContent());

        if ($content !== "") {
            try {
                /** @var mixed $decoded */
                $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (\JsonException) {
                // Fall back to request form payload.
            }
        }

        /** @var array<string,mixed> $payload */
        $payload = $request->request->all();

        return $payload;
    }
}
