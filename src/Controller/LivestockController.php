<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Livestock;
use App\Repository\LivestockRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class LivestockController extends AbstractController
{
    #[Route('/elfirma/animaux-elevages/livestock/create', name: 'livestock_create', methods: ['POST'])]
    public function create(Request $request, LivestockRepository $livestockRepository, ValidatorInterface $validator): Response
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

        $violations = $validator->validate(
            (new Livestock())
                ->setTypeElevage($payload['type_elevage'])
                ->setEtatElevage($payload['etat_elevage'])
                ->setCapacite($payload['capacite'])
                ->setNombreAnimaux(0)
                ->setProduction($payload['production'])
        );

        if (count($violations) > 0) {
            return $this->redirectToLivestockFormWithFieldErrors(
                $formRedirect,
                $this->collectViolationFieldErrors($violations),
                $input
            );
        }

        $livestockRepository->createLivestock($payload);

        return $this->redirectToLivestockList();
    }

    #[Route('/elfirma/animaux-elevages/livestock/update', name: 'livestock_update', methods: ['POST'])]
    public function update(Request $request, LivestockRepository $livestockRepository, ValidatorInterface $validator): Response
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

        $violations = $validator->validate(
            (new Livestock())
                ->setTypeElevage($payload['type_elevage'])
                ->setEtatElevage($payload['etat_elevage'])
                ->setCapacite($payload['capacite'])
                ->setNombreAnimaux($nombreAnimaux)
                ->setProduction($payload['production'])
        );

        if (count($violations) > 0) {
            return $this->redirectToLivestockFormWithFieldErrors(
                $formRedirect,
                $this->collectViolationFieldErrors($violations),
                $input
            );
        }

        $livestockRepository->updateLivestock($idElevage, $payload, $nombreAnimaux);

        return $this->redirectToLivestockList();
    }

    #[Route('/elfirma/animaux-elevages/livestock/delete', name: 'livestock_delete', methods: ['POST'])]
    public function delete(Request $request, LivestockRepository $livestockRepository): Response
    {
        if (!$this->isCsrfTokenValid('livestock_delete', (string) $request->request->get('_token', ''))) {
            return $this->redirectToLivestockList();
        }

        $idElevage = (int) $request->request->get('id_elevage', 0);
        if ($idElevage <= 0) {
            return $this->redirectToLivestockList();
        }

        $livestockRepository->deleteLivestock($idElevage);

        return $this->redirectToLivestockList();
    }

    /**
     * @return array{type_elevage:string,etat_elevage:string,capacite:string,production:string}
     */
    private function collectLivestockInput(Request $request): array
    {
        return [
            'type_elevage' => trim((string) $request->request->get('type_elevage', '')),
            'etat_elevage' => trim((string) $request->request->get('etat_elevage', '')),
            'capacite' => trim((string) $request->request->get('capacite', '')),
            'production' => trim((string) $request->request->get('production', '')),
        ];
    }

    /**
     * @param array{type_elevage:string,etat_elevage:string,capacite:string,production:string} $input
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

        return $errors;
    }

    /**
     * @param array{type_elevage:string,etat_elevage:string,capacite:string,production:string} $input
     *
     * @return array{type_elevage:string,etat_elevage:string,capacite:int,production:string}
     */
    private function toLivestockPayload(array $input): array
    {
        return [
            'type_elevage' => $input['type_elevage'],
            'etat_elevage' => $input['etat_elevage'],
            'capacite' => (int) $input['capacite'],
            'production' => $input['production'],
        ];
    }

    private function redirectToLivestockList(): Response
    {
        return $this->redirectToRoute('elfirma_page', [
            'module' => 'animaux-elevages',
            'view' => 'livestock',
        ]);
    }

    /**
     * @return array<string,string>
     */
    private function collectViolationFieldErrors(ConstraintViolationListInterface $violations): array
    {
        $errors = [];
        foreach ($violations as $violation) {
            $field = $this->normalizeFieldName((string) $violation->getPropertyPath());
            if ($field === '') {
                $field = '_form';
            }

            if (!isset($errors[$field])) {
                $errors[$field] = $violation->getMessage();
            }
        }

        return $errors;
    }

    private function normalizeFieldName(string $field): string
    {
        $field = trim($field);
        if ($field === '') {
            return '';
        }

        $field = preg_replace('/([a-z])([A-Z])/', '$1_$2', $field);

        return strtolower((string) $field);
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
