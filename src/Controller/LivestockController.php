<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\LivestockRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LivestockController extends AbstractController
{
    #[Route('/elfirma/animaux-elevages/livestock/create', name: 'livestock_create', methods: ['POST'])]
    public function create(Request $request, LivestockRepository $livestockRepository): Response
    {
        $formRedirect = [
            'module' => 'animaux-elevages',
            'view' => 'livestock',
            'add' => '1',
        ];
        $input = $this->collectLivestockInput($request);

        if (!$this->isCsrfTokenValid('livestock_create', (string) $request->request->get('_token', ''))) {
            return $this->redirectToLivestockFormWithFieldErrors($formRedirect, [
                '_form' => 'Session expired. Please try again.',
            ], $input);
        }

        $errors = $this->validateLivestockInput($input);
        if ($errors !== []) {
            return $this->redirectToLivestockFormWithFieldErrors($formRedirect, $errors, $input);
        }

        $payload = $this->toLivestockPayload($input);

        $livestockRepository->createLivestock($payload);

        return $this->redirectToLivestockList();
    }

    #[Route('/elfirma/animaux-elevages/livestock/update', name: 'livestock_update', methods: ['POST'])]
    public function update(Request $request, LivestockRepository $livestockRepository): Response
    {
        $idElevage = (int) $request->request->get('id_elevage', 0);
        $formRedirect = [
            'module' => 'animaux-elevages',
            'view' => 'livestock',
        ];
        $input = $this->collectLivestockInput($request);

        if ($idElevage > 0) {
            $formRedirect['edit'] = (string) $idElevage;
        }

        if (!$this->isCsrfTokenValid('livestock_update', (string) $request->request->get('_token', ''))) {
            return $this->redirectToLivestockFormWithFieldErrors($formRedirect, [
                '_form' => 'Session expired. Please try again.',
            ], $input);
        }

        if ($idElevage <= 0) {
            return $this->redirectToLivestockFormWithFieldErrors($formRedirect, [
                '_form' => 'Invalid livestock.',
            ], $input);
        }

        $errors = $this->validateLivestockInput($input);
        if ($errors !== []) {
            return $this->redirectToLivestockFormWithFieldErrors($formRedirect, $errors, $input);
        }

        $payload = $this->toLivestockPayload($input);

        $nombreAnimaux = $livestockRepository->countAnimalsForLivestock($idElevage);

        $livestockRepository->updateLivestock($idElevage, $payload, $nombreAnimaux);

        return $this->redirectToLivestockList();
    }

    #[Route('/elfirma/animaux-elevages/livestock/delete', name: 'livestock_delete', methods: ['POST'])]
    public function delete(Request $request, LivestockRepository $livestockRepository): Response
    {
        if (!$this->isCsrfTokenValid('livestock_delete', (string) $request->request->get('_token', ''))) {
            $this->addFlash('error', 'Action refusée : session expirée, veuillez réessayer.');
            return $this->redirectToLivestockList();
        }

        $idElevage = (int) $request->request->get('id_elevage', 0);
        if ($idElevage <= 0) {
            $this->addFlash('error', 'Suppression impossible : identifiant d\'élevage invalide.');
            return $this->redirectToLivestockList();
        }

        $linkedAnimals = $livestockRepository->countAnimalsForLivestock($idElevage);
        if ($linkedAnimals > 0) {
            $this->addFlash('error', sprintf(
                'Suppression impossible : %d animal(aux) sont liés à cet élevage. Déplacez ou supprimez d\'abord ces animaux.',
                $linkedAnimals
            ));

            return $this->redirectToLivestockList();
        }

        try {
            $livestockRepository->deleteLivestock($idElevage);
            $this->addFlash('success', 'Élevage supprimé avec succès.');
        } catch (ForeignKeyConstraintViolationException) {
            $this->addFlash('error', 'Suppression impossible : des animaux liés existent encore pour cet élevage.');

            return $this->redirectToLivestockList();
        }

        return $this->redirectToLivestockList();
    }

    /**
     * @return array{type_elevage:string,etat_elevage:string,capacite:string,production:string,latitude:string,longitude:string}
     */
    private function collectLivestockInput(Request $request): array
    {
        return [
            'type_elevage' => trim((string) $request->request->get('type_elevage', '')),
            'etat_elevage' => trim((string) $request->request->get('etat_elevage', '')),
            'capacite' => trim((string) $request->request->get('capacite', '')),
            'production' => trim((string) $request->request->get('production', '')),
            'latitude' => trim((string) $request->request->get('latitude', '')),
            'longitude' => trim((string) $request->request->get('longitude', '')),
        ];
    }

    /**
     * @param array{type_elevage:string,etat_elevage:string,capacite:string,production:string,latitude:string,longitude:string} $input
     *
     * @return array<string,string>
     */
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
            $capacite = filter_var($input['capacite'], FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0],
            ]);
            if ($capacite === false) {
                $errors['capacite'] = 'Capacity must be a whole number greater than or equal to 0.';
            }
        }

        if ($input['production'] === '') {
            $errors['production'] = 'Production is required.';
        } elseif (!preg_match('/^[\p{L}\s]+$/u', $input['production'])) {
            $errors['production'] = 'Production can contain letters and spaces only.';
        }

        $coordinatesError = $this->validateCoordinatesPair($input['latitude'], $input['longitude']);
        if ($coordinatesError !== null) {
            $errors['location'] = $coordinatesError;
        }

        return $errors;
    }

    /**
     * @param array{type_elevage:string,etat_elevage:string,capacite:string,production:string,latitude:string,longitude:string} $input
     *
     * @return array{type_elevage:string,etat_elevage:string,capacite:int,production:string,latitude:?float,longitude:?float}
     */
    private function toLivestockPayload(array $input): array
    {
        return [
            'type_elevage' => $input['type_elevage'],
            'etat_elevage' => $input['etat_elevage'],
            'capacite' => (int) $input['capacite'],
            'production' => $input['production'],
            'latitude' => $this->normalizeCoordinate($input['latitude']),
            'longitude' => $this->normalizeCoordinate($input['longitude']),
        ];
    }

    private function normalizeCoordinate(string $value): ?float
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        return (float) $trimmed;
    }

    private function validateCoordinatesPair(string $latitude, string $longitude): ?string
    {
        $lat = trim($latitude);
        $lon = trim($longitude);

        if ($lat === '' && $lon === '') {
            return null;
        }

        if ($lat === '' || $lon === '') {
            return 'Latitude and longitude must be provided together.';
        }

        if (!is_numeric($lat) || !is_numeric($lon)) {
            return 'Latitude and longitude must be valid numbers.';
        }

        $latValue = (float) $lat;
        $lonValue = (float) $lon;

        if ($latValue < -90 || $latValue > 90) {
            return 'Latitude must be between -90 and 90.';
        }

        if ($lonValue < -180 || $lonValue > 180) {
            return 'Longitude must be between -180 and 180.';
        }

        return null;
    }

    private function redirectToLivestockList(): Response
    {
        return $this->redirectToRoute('elfirma_page', [
            'module' => 'animaux-elevages',
            'view' => 'livestock',
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
