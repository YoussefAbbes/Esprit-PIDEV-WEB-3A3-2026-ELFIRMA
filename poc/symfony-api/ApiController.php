<?php
// ─────────────────────────────────────────────────────────────────────
// Drop this file into:  src/Controller/ApiController.php
//
// It exposes read-only JSON endpoints under /api/* that the React POC
// frontend (poc/frontend) consumes. All shapes match poc/frontend/src/api.js
// fall-back mocks, so swapping mock → live data is transparent.
//
// IMPORTANT: enable CORS for the Vite dev server (http://localhost:5173).
// The cleanest way is to install nelmio/cors-bundle and add this to
// config/packages/nelmio_cors.yaml:
//
// nelmio_cors:
//     defaults:
//         allow_credentials: true
//         allow_origin: ['http://localhost:5173', 'http://127.0.0.1:5173']
//         allow_methods: ['GET', 'POST', 'OPTIONS']
//         allow_headers: ['Content-Type', 'Authorization']
//         max_age: 3600
//     paths:
//         '^/api/': null
//
// (Or skip CORS entirely — vite.config.js already proxies /api → Symfony.)
// ─────────────────────────────────────────────────────────────────────

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AnimalRepository;
use App\Repository\LivestockRepository;
use App\Repository\MaintenanceRepository;
use App\Repository\ParcelleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api', name: 'api_')]
final class ApiController extends AbstractController
{
    public function __construct(
        private readonly ParcelleRepository $parcelles,
        private readonly LivestockRepository $livestock,
        private readonly AnimalRepository $animals,
        private readonly MaintenanceRepository $maintenances,
    ) {
    }

    // ─── /api/parcelles ──────────────────────────────────────────────
    #[Route('/parcelles', name: 'parcelles', methods: ['GET'])]
    public function parcelles(): JsonResponse
    {
        $rows = $this->parcelles->findAllWithCultures();

        $payload = array_map(static function ($p) {
            return [
                'id'           => $p->getId(),
                'nom'          => $p->getNom(),
                'localisation' => $p->getLocalisation(),
                'superficie'   => $p->getSuperficie(),
                'typeSol'      => $p->getTypeSol(),
                'statut'       => $p->getStatut(),
                'latitude'     => $p->getLatitude(),
                'longitude'    => $p->getLongitude(),
                'dateCreation' => $p->getDateCreation()?->format('Y-m-d'),
            ];
        }, $rows);

        return $this->json($payload);
    }

    // ─── /api/parcelles/stats ────────────────────────────────────────
    #[Route('/parcelles/stats', name: 'parcelles_stats', methods: ['GET'])]
    public function parcellesStats(): JsonResponse
    {
        return $this->json($this->parcelles->getClientStats());
    }

    // ─── /api/livestock ──────────────────────────────────────────────
    #[Route('/livestock', name: 'livestock', methods: ['GET'])]
    public function livestockList(): JsonResponse
    {
        $rows = $this->livestock->findAllForManagement();

        // Already returns array<string, mixed> — pass through.
        return $this->json($rows);
    }

    // ─── /api/livestock/stats ────────────────────────────────────────
    #[Route('/livestock/stats', name: 'livestock_stats', methods: ['GET'])]
    public function livestockStats(): JsonResponse
    {
        $stats = $this->livestock->fetchStats();
        $animalStats = $this->animals->fetchStats();

        return $this->json(array_merge($stats, [
            'total_animals' => $animalStats['total'] ?? 0,
            'healthy'       => $animalStats['healthy'] ?? 0,
            'sick'          => $animalStats['sick'] ?? 0,
            'quarantined'   => $animalStats['quarantined'] ?? 0,
        ]));
    }

    // ─── /api/maintenances ───────────────────────────────────────────
    #[Route('/maintenances', name: 'maintenances', methods: ['GET'])]
    public function maintenanceList(): JsonResponse
    {
        $this->maintenances->sanitizeEnums();
        $rows = $this->maintenances->findAllOrderedByDate();

        $payload = array_map(static fn ($m) => [
            'id'          => $m->getId(),
            'type_m'      => $m->getTypeM(),
            'description' => $m->getDescription(),
            'date_m'      => $m->getDateM()?->format('Y-m-d'),
            'statut'      => $m->getStatut(),
            'priorite'    => $m->getPriorite(),
            'equipement'  => $m->getEquipement()?->getNomEq() ?? '—',
        ], $rows);

        return $this->json($payload);
    }

    // ─── /api/dashboard ──────────────────────────────────────────────
    // Aggregated KPI snapshot used by the admin dashboard home.
    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function dashboard(): JsonResponse
    {
        $parcelleStats = $this->parcelles->getClientStats();
        $livestockStats = $this->livestock->fetchStats();
        $animalStats = $this->animals->fetchStats();

        // Placeholder series — replace with a real revenue query if you have one.
        // (E.g. CommandeRepository::monthlyRevenue(6 months) → array of floats.)
        return $this->json([
            'parcelles' => $parcelleStats['total'] ?? 0,
            'livestock' => $animalStats['total'] ?? 0,
            'revenue_dt' => 42850,
            'alerts' => 4,
            'orders_in_progress' => 18,
            'revenue_series' => [9200, 11800, 10400, 14200, 16100, 18420],
            'order_series'   => [38, 52, 44, 61, 68, 75],
            'months' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
            'activity' => [
                ['kind' => 'irrigation', 'text' => 'Parcelle B-12 irrigation started automatically', 'ago' => '2 min'],
                ['kind' => 'order',      'text' => 'New order #CMD-2891 — 340 DT',                   'ago' => '15 min'],
                ['kind' => 'alert',      'text' => 'Tractor #3 predicted failure in 48h',            'ago' => '1 h'],
                ['kind' => 'vaccine',    'text' => 'Cow #47 vaccination scheduled — SMS sent',       'ago' => '3 h'],
                ['kind' => 'contract',   'text' => 'AgroTech Maroc contract expires in 7 days',      'ago' => '1 d'],
            ],
        ]);
    }
}
