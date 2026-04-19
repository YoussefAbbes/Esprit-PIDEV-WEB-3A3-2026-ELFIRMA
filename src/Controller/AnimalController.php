<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AnimalRepository;
use App\Repository\LivestockRepository;
use App\Service\LivestockCapacityEmailAlertService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AnimalController extends AbstractController
{
    #[Route('/elfirma/animaux-elevages/animal/create', name: 'animal_create', methods: ['POST'])]
    public function create(
        Request $request,
        AnimalRepository $animalRepository,
        LivestockRepository $livestockRepository,
        LivestockCapacityEmailAlertService $capacityEmailAlertService
    ): Response
    {
        $formRedirect = [
            'module' => 'animaux-elevages',
            'view' => 'animal',
            'add' => '1',
        ];
        $input = $this->collectAnimalInput($request);

        if (!$this->isCsrfTokenValid('animal_create', (string) $request->request->get('_token', ''))) {
            return $this->redirectToAnimalFormWithFieldErrors($formRedirect, [
                '_form' => 'Session expired. Please try again.',
            ], $input);
        }

        $errors = $this->validateAnimalInput($input, $livestockRepository);
        if ($errors !== []) {
            return $this->redirectToAnimalFormWithFieldErrors($formRedirect, $errors, $input);
        }

        $payload = $this->toAnimalPayload($input);

        $animalRepository->createAnimal($payload);
        $livestockRepository->syncAnimalCount($payload['id_elevage']);
        $capacityEmailAlertService->checkAndSendForLivestock($payload['id_elevage']);

        return $this->redirectToAnimalList();
    }

    #[Route('/elfirma/animaux-elevages/animal/update', name: 'animal_update', methods: ['POST'])]
    public function update(
        Request $request,
        AnimalRepository $animalRepository,
        LivestockRepository $livestockRepository,
        LivestockCapacityEmailAlertService $capacityEmailAlertService
    ): Response
    {
        $idAnimal = (int) $request->request->get('id_animal', 0);
        $formRedirect = [
            'module' => 'animaux-elevages',
            'view' => 'animal',
        ];
        $input = $this->collectAnimalInput($request);

        if ($idAnimal > 0) {
            $formRedirect['edit'] = (string) $idAnimal;
        }

        if (!$this->isCsrfTokenValid('animal_update', (string) $request->request->get('_token', ''))) {
            return $this->redirectToAnimalFormWithFieldErrors($formRedirect, [
                '_form' => 'Session expired. Please try again.',
            ], $input);
        }

        if ($idAnimal <= 0) {
            return $this->redirectToAnimalFormWithFieldErrors($formRedirect, [
                '_form' => 'Invalid animal.',
            ], $input);
        }

        $previousElevageId = $animalRepository->findElevageIdByAnimalId($idAnimal);
        if ($previousElevageId === null || $previousElevageId <= 0) {
            return $this->redirectToAnimalFormWithFieldErrors($formRedirect, [
                '_form' => 'Animal not found.',
            ], $input);
        }

        $errors = $this->validateAnimalInput($input, $livestockRepository);
        if ($errors !== []) {
            return $this->redirectToAnimalFormWithFieldErrors($formRedirect, $errors, $input);
        }

        $payload = $this->toAnimalPayload($input);

        $animalRepository->updateAnimal($idAnimal, $payload);
        $livestockRepository->syncAnimalCount($payload['id_elevage']);
        $capacityEmailAlertService->checkAndSendForLivestock($payload['id_elevage']);

        if ($previousElevageId !== $payload['id_elevage']) {
            $livestockRepository->syncAnimalCount($previousElevageId);
            $capacityEmailAlertService->checkAndSendForLivestock($previousElevageId);
        }

        return $this->redirectToAnimalList();
    }

    #[Route('/elfirma/animaux-elevages/animal/delete', name: 'animal_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        AnimalRepository $animalRepository,
        LivestockRepository $livestockRepository,
        LivestockCapacityEmailAlertService $capacityEmailAlertService
    ): Response
    {
        if (!$this->isCsrfTokenValid('animal_delete', (string) $request->request->get('_token', ''))) {
            return $this->redirectToAnimalList();
        }

        $idAnimal = (int) $request->request->get('id_animal', 0);
        if ($idAnimal <= 0) {
            return $this->redirectToAnimalList();
        }

        $animalElevageId = $animalRepository->findElevageIdByAnimalId($idAnimal);
        $animalRepository->deleteAnimal($idAnimal);

        if ($animalElevageId !== null && $animalElevageId > 0) {
            $livestockRepository->syncAnimalCount($animalElevageId);
            $capacityEmailAlertService->checkAndSendForLivestock($animalElevageId);
        }

        return $this->redirectToAnimalList();
    }

    /**
     * @return array{id_elevage:string,type_animal:string,sexe:string,age:string,etat_sante:string,statut:string}
     */
    private function collectAnimalInput(Request $request): array
    {
        return [
            'id_elevage' => trim((string) $request->request->get('id_elevage', '')),
            'type_animal' => trim((string) $request->request->get('type_animal', '')),
            'sexe' => trim((string) $request->request->get('sexe', '')),
            'age' => trim((string) $request->request->get('age', '')),
            'etat_sante' => trim((string) $request->request->get('etat_sante', '')),
            'statut' => trim((string) $request->request->get('statut', '')),
        ];
    }

    /**
     * @param array{id_elevage:string,type_animal:string,sexe:string,age:string,etat_sante:string,statut:string} $input
     *
     * @return array<string,string>
     */
    private function validateAnimalInput(array $input, LivestockRepository $livestockRepository): array
    {
        $errors = [];

        $idElevage = filter_var($input['id_elevage'], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($idElevage === false) {
            $errors['id_elevage'] = 'Please select a valid farm.';
        } elseif (!$livestockRepository->existsById((int) $idElevage)) {
            $errors['id_elevage'] = 'Selected farm was not found.';
        }

        if ($input['type_animal'] === '') {
            $errors['type_animal'] = 'Type is required.';
        } elseif (!preg_match('/^[\p{L}\s]+$/u', $input['type_animal'])) {
            $errors['type_animal'] = 'Type can contain letters and spaces only.';
        }

        if ($input['sexe'] === '') {
            $errors['sexe'] = 'Gender is required.';
        }

        if ($input['age'] === '') {
            $errors['age'] = 'Age is required.';
        } else {
            $age = filter_var($input['age'], FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0],
            ]);
            if ($age === false) {
                $errors['age'] = 'Age must be a whole number greater than or equal to 0.';
            }
        }

        if ($input['etat_sante'] === '') {
            $errors['etat_sante'] = 'Health status is required.';
        }

        if ($input['statut'] === '') {
            $errors['statut'] = 'Status is required.';
        }

        return $errors;
    }

    /**
     * @param array{id_elevage:string,type_animal:string,sexe:string,age:string,etat_sante:string,statut:string} $input
     *
     * @return array{id_elevage:int,type_animal:string,sexe:string,age:int,etat_sante:string,statut:string}
     */
    private function toAnimalPayload(array $input): array
    {
        return [
            'id_elevage' => (int) $input['id_elevage'],
            'type_animal' => $input['type_animal'],
            'sexe' => $input['sexe'],
            'age' => (int) $input['age'],
            'etat_sante' => $input['etat_sante'],
            'statut' => $input['statut'],
        ];
    }

    private function redirectToAnimalList(): Response
    {
        return $this->redirectToRoute('elfirma_page', [
            'module' => 'animaux-elevages',
            'view' => 'animal',
        ]);
    }

    /**
     * @param array{module:string,view:string,add?:string,edit?:string} $routeParams
     * @param array<string,string> $fieldErrors
     * @param array<string,string> $input
     */
    private function redirectToAnimalFormWithFieldErrors(array $routeParams, array $fieldErrors, array $input): Response
    {
        if ($fieldErrors !== []) {
            $this->addFlash('form_errors', $fieldErrors);
        }
        $this->addFlash('form_input', $input);

        return $this->redirectToRoute('elfirma_page', $routeParams);
    }
}
