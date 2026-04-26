<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\LivestockRepository;
use App\Service\LivestockCapacityEmailAlertService;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LivestockController extends AbstractController
{
    // ─────────────────────────────────────────
    // CREATE
    // ─────────────────────────────────────────
    #[Route('/elfirma/animaux-elevages/livestock/create', name: 'livestock_create', methods: ['POST'])]
    public function create(Request $request, LivestockRepository $livestockRepository): Response
    {
        $input = $this->collectLivestockInput($request);
        $formRedirect = [
            'module' => 'animaux-elevages',
            'view' => 'livestock',
            'add' => '1',
        ];

        if (!$this->isCsrfTokenValid('livestock_create', (string)$request->request->get('_token'))) {
            return $this->redirectToLivestockFormWithFieldErrors($formRedirect, [
                '_form' => 'Session expired. Please try again.',
            ], $input);
        }

        $errors = $this->validateLivestockInput($input);
        if ($errors !== []) {
            return $this->redirectToLivestockFormWithFieldErrors($formRedirect, $errors, $input);
        }

        $livestockRepository->createLivestock($this->toLivestockPayload($input));

        return $this->redirectToLivestockList();
    }

    // ─────────────────────────────────────────
    // UPDATE
    // ─────────────────────────────────────────
    #[Route('/elfirma/animaux-elevages/livestock/update', name: 'livestock_update', methods: ['POST'])]
    public function update(
        Request $request,
        LivestockRepository $livestockRepository,
        LivestockCapacityEmailAlertService $capacityEmailAlertService
    ): Response {
        $id = (int)$request->request->get('id_elevage');
        $input = $this->collectLivestockInput($request);
        $formRedirect = [
            'module' => 'animaux-elevages',
            'view' => 'livestock',
        ];

        if ($id > 0) {
            $formRedirect['edit'] = (string) $id;
        }

        if ($id <= 0) {
            return $this->redirectToLivestockFormWithFieldErrors($formRedirect, [
                '_form' => 'Invalid livestock.',
            ], $input);
        }

        if (!$this->isCsrfTokenValid('livestock_update', (string)$request->request->get('_token'))) {
            return $this->redirectToLivestockFormWithFieldErrors($formRedirect, [
                '_form' => 'Session expired. Please try again.',
            ], $input);
        }

        $errors = $this->validateLivestockInput($input);
        if ($errors !== []) {
            return $this->redirectToLivestockFormWithFieldErrors($formRedirect, $errors, $input);
        }

        $livestockRepository->updateLivestock(
            $id,
            $this->toLivestockPayload($input),
            $livestockRepository->countAnimalsForLivestock($id)
        );

        $capacityEmailAlertService->checkAndSendForLivestock($id);

        return $this->redirectToLivestockList();
    }

    // ─────────────────────────────────────────
    // DELETE
    // ─────────────────────────────────────────
    #[Route('/elfirma/animaux-elevages/livestock/delete', name: 'livestock_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        LivestockRepository $livestockRepository,
        LivestockCapacityEmailAlertService $capacityEmailAlertService
    ): Response {
        $id = (int)$request->request->get('id_elevage');

        if ($id <= 0) return $this->redirectToLivestockList();

        if (!$this->isCsrfTokenValid('livestock_delete', (string)$request->request->get('_token'))) {
            return $this->redirectToLivestockList();
        }

        try {
            $livestockRepository->deleteLivestock($id);
            $capacityEmailAlertService->clearAlertState($id);
        } catch (ForeignKeyConstraintViolationException) {
            return $this->redirectToLivestockList();
        }

        return $this->redirectToLivestockList();
    }

    // ─────────────────────────────────────────
    // 🧠 NUTRITION + ICÔNES LIVESTOCK
    // ─────────────────────────────────────────
    #[Route('/elfirma/animaux-elevages/livestock/nutrition', name: 'livestock_nutrition', methods: ['GET'])]
    public function nutrition(Request $request): JsonResponse
    {
        $production = trim((string)$request->query->get('production'));
        $type       = strtolower(trim((string)$request->query->get('type')));

        if ($production === '') {
            return $this->json(['error' => 'invalid'], 400);
        }

        $apiKey = $_ENV['USDA_API_KEY'] ?? '';

        $url = "https://api.nal.usda.gov/fdc/v1/foods/search?query=" . urlencode($production)
            . "&pageSize=1&api_key=" . urlencode($apiKey);

        $raw = @file_get_contents($url);
        $data = json_decode($raw ?: '[]', true);

        $p = $c = $f = 0;

        foreach ($data['foods'][0]['foodNutrients'] ?? [] as $n) {
            $id  = $n['nutrientId'] ?? 0;
            $val = (float)($n['value'] ?? 0);

            if (in_array($id, [203, 1003])) $p = $val;
            if (in_array($id, [205, 1005])) $c = $val;
            if (in_array($id, [204, 1004])) $f = $val;
        }

        // 🐄🐔🐑 ICONES + LABEL
        $animalData = match ($type) {
            'mouton', 'sheep' => [
                'icon' => '🐑',
                'label' => 'Sheep Farm'
            ],
            'poule', 'poultry', 'poulet' => [
                'icon' => '🐔',
                'label' => 'Poultry Farm'
            ],
            'vache', 'cow', 'bovin' => [
                'icon' => '🐄',
                'label' => 'Bovin Farm'
            ],
            default => [
                'icon' => '🏠',
                'label' => 'Farm'
            ],
        };

        return $this->json([
            'animal'   => $animalData['icon'],
            'label'    => $animalData['label'],

            'proteins' => round($p, 1),
            'carbs'    => round($c, 1),
            'fats'     => round($f, 1),

            'icons' => [
                'proteins' => '💪',
                'carbs'    => '🍞',
                'fats'     => '🛢️'
            ]
        ]);
    }

    // ─────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────
    private function collectLivestockInput(Request $request): array
    {
        return [
            'type_elevage' => trim((string)$request->request->get('type_elevage')),
            'etat_elevage' => trim((string)$request->request->get('etat_elevage')),
            'capacite'     => trim((string)$request->request->get('capacite')),
            'production'   => trim((string)$request->request->get('production')),
            'latitude'     => trim((string)$request->request->get('latitude')),
            'longitude'    => trim((string)$request->request->get('longitude')),
        ];
    }

    private function validateLivestockInput(array $input): array
    {
        $errors = [];

        if ($input['type_elevage'] === '') {
            $errors['type_elevage'] = 'Type is required.';
        } elseif (!preg_match('/^[\p{L}\s]+$/u', $input['type_elevage'])) {
            $errors['type_elevage'] = 'Type can contain letters and spaces only.';
        }

        if ($input['etat_elevage'] === '') {
            $errors['etat_elevage'] = 'State is required.';
        }

        if ($input['capacite'] === '') {
            $errors['capacite'] = 'Capacity is required.';
        } else {
            $capacity = filter_var($input['capacite'], FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0],
            ]);

            if ($capacity === false) {
                $errors['capacite'] = 'Capacity must be a whole number greater than or equal to 0.';
            }
        }

        if ($input['production'] === '') {
            $errors['production'] = 'Production is required.';
        } elseif (!preg_match('/^[\p{L}\s]+$/u', $input['production'])) {
            $errors['production'] = 'Production can contain letters and spaces only.';
        }

        if ($input['latitude'] === '' || $input['longitude'] === '') {
            $errors['location'] = 'Please select a location on the map.';
            return $errors;
        }

        $latitude = filter_var($input['latitude'], FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($input['longitude'], FILTER_VALIDATE_FLOAT);

        if ($latitude === false || $longitude === false) {
            $errors['location'] = 'Location coordinates are invalid.';
            return $errors;
        }

        if ($latitude < -90 || $latitude > 90) {
            $errors['location'] = 'Latitude must be between -90 and 90.';
        } elseif ($longitude < -180 || $longitude > 180) {
            $errors['location'] = 'Longitude must be between -180 and 180.';
        }

        return $errors;
    }

    private function toLivestockPayload(array $input): array
    {
        return [
            'type_elevage' => $input['type_elevage'],
            'etat_elevage' => $input['etat_elevage'],
            'capacite'     => (int)$input['capacite'],
            'production'   => $input['production'],
            'latitude'     => (float)$input['latitude'],
            'longitude'    => (float)$input['longitude'],
        ];
    }

    private function redirectToLivestockList(): Response
    {
        return $this->redirectToRoute('elfirma_page', [
            'module' => 'animaux-elevages',
            'view'   => 'livestock'
        ]);
    }

    /**
     * @param array{module:string,view:string,add?:string,edit?:string} $routeParams
     * @param array<string,string> $fieldErrors
     * @param array<string,string> $input
     */
    private function redirectToLivestockFormWithFieldErrors(array $routeParams, array $fieldErrors, array $input): Response
    {
        if ($fieldErrors !== []) {
            $this->addFlash('form_errors', $fieldErrors);
        }
        $this->addFlash('form_input', $input);

        return $this->redirectToRoute('elfirma_page', $routeParams);
    }
}