<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Animal;
use App\Repository\AnimalRepository;
use App\Repository\LivestockRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AnimalController extends AbstractController
{
    #[Route('/elfirma/animaux-elevages/animal/create', name: 'animal_create', methods: ['POST'])]
    public function create(
        Request $request,
        AnimalRepository $animalRepository,
        LivestockRepository $livestockRepository,
        ValidatorInterface $validator
    ): Response
    {
        $formRedirect = [
            'module' => 'animaux-elevages',
            'view' => 'animal',
            'add' => '1',
        ];

        if (!$this->isCsrfTokenValid('animal_create', (string) $request->request->get('_token', ''))) {
            return $this->redirectToAnimalList();
        }

        $payload = $this->extractAnimalPayload($request);
        if ($payload === null) {
            return $this->redirectToRoute('elfirma_page', $formRedirect);
        }

        if (!$livestockRepository->existsById($payload['id_elevage'])) {
            return $this->redirectToRoute('elfirma_page', $formRedirect);
        }

        $violations = $validator->validate(
            (new Animal())
                ->setTypeAnimal($payload['type_animal'])
                ->setSexe($payload['sexe'])
                ->setAge($payload['age'])
                ->setEtatSante($payload['etat_sante'])
                ->setStatut($payload['statut'])
        );

        if (count($violations) > 0) {
            return $this->redirectToRoute('elfirma_page', $formRedirect);
        }

        $animalRepository->createAnimal($payload);
        $livestockRepository->syncAnimalCount($payload['id_elevage']);

        return $this->redirectToAnimalList();
    }

    #[Route('/elfirma/animaux-elevages/animal/update', name: 'animal_update', methods: ['POST'])]
    public function update(
        Request $request,
        AnimalRepository $animalRepository,
        LivestockRepository $livestockRepository,
        ValidatorInterface $validator
    ): Response
    {
        $idAnimal = (int) $request->request->get('id_animal', 0);
        $formRedirect = [
            'module' => 'animaux-elevages',
            'view' => 'animal',
        ];

        if ($idAnimal > 0) {
            $formRedirect['edit'] = (string) $idAnimal;
        }

        if (!$this->isCsrfTokenValid('animal_update', (string) $request->request->get('_token', ''))) {
            return $this->redirectToAnimalList();
        }

        if ($idAnimal <= 0) {
            return $this->redirectToRoute('elfirma_page', $formRedirect);
        }

        $payload = $this->extractAnimalPayload($request);
        if ($payload === null) {
            return $this->redirectToRoute('elfirma_page', $formRedirect);
        }

        if (!$livestockRepository->existsById($payload['id_elevage'])) {
            return $this->redirectToRoute('elfirma_page', $formRedirect);
        }

        $previousElevageId = $animalRepository->findElevageIdByAnimalId($idAnimal);
        if ($previousElevageId === null || $previousElevageId <= 0) {
            return $this->redirectToRoute('elfirma_page', $formRedirect);
        }

        $violations = $validator->validate(
            (new Animal())
                ->setTypeAnimal($payload['type_animal'])
                ->setSexe($payload['sexe'])
                ->setAge($payload['age'])
                ->setEtatSante($payload['etat_sante'])
                ->setStatut($payload['statut'])
        );

        if (count($violations) > 0) {
            return $this->redirectToRoute('elfirma_page', $formRedirect);
        }

        $animalRepository->updateAnimal($idAnimal, $payload);
        $livestockRepository->syncAnimalCount($payload['id_elevage']);
        if ($previousElevageId !== $payload['id_elevage']) {
            $livestockRepository->syncAnimalCount($previousElevageId);
        }

        return $this->redirectToAnimalList();
    }

    #[Route('/elfirma/animaux-elevages/animal/delete', name: 'animal_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        AnimalRepository $animalRepository,
        LivestockRepository $livestockRepository
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
        }

        return $this->redirectToAnimalList();
    }

    /**
     * @return array{id_elevage:int,type_animal:string,sexe:string,age:int,etat_sante:string,statut:string}|null
     */
    private function extractAnimalPayload(Request $request): ?array
    {
        $idElevage = filter_var((string) $request->request->get('id_elevage', ''), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $age = filter_var((string) $request->request->get('age', ''), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);

        $typeAnimal = trim((string) $request->request->get('type_animal', ''));
        $sexe = trim((string) $request->request->get('sexe', ''));
        $etatSante = trim((string) $request->request->get('etat_sante', ''));
        $statut = trim((string) $request->request->get('statut', ''));

        if ($idElevage === false || $age === false || $typeAnimal === '' || $sexe === '' || $etatSante === '' || $statut === '') {
            return null;
        }

        return [
            'id_elevage' => (int) $idElevage,
            'type_animal' => $typeAnimal,
            'sexe' => $sexe,
            'age' => (int) $age,
            'etat_sante' => $etatSante,
            'statut' => $statut,
        ];
    }

    private function redirectToAnimalList(): Response
    {
        return $this->redirectToRoute('elfirma_page', [
            'module' => 'animaux-elevages',
            'view' => 'animal',
        ]);
    }
}
