<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\VaccinationRepository;
use App\Service\VaccinationSmsAlertService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class VaccinationController extends AbstractController
{
    #[Route('/elfirma/animaux-elevages/vaccination/create', name: 'vaccination_create', methods: ['POST'])]
    public function create(
        Request $request,
        VaccinationRepository $vaccinationRepository,
        VaccinationSmsAlertService $vaccinationSmsAlertService
    ): Response
    {
        $formRedirect = [
            'module' => 'animaux-elevages',
            'view' => 'vaccination',
            'add' => '1',
        ];
        $input = $this->collectVaccinationInput($request);

        if (!$this->isCsrfTokenValid('vaccination_create', (string) $request->request->get('_token', ''))) {
            return $this->redirectToVaccinationFormWithFieldErrors($formRedirect, [
                '_form' => 'Session expired. Please try again.',
            ], $input);
        }

        $errors = $this->validateVaccinationInput($input, $vaccinationRepository);
        if ($errors !== []) {
            return $this->redirectToVaccinationFormWithFieldErrors($formRedirect, $errors, $input);
        }

        $payload = $this->toVaccinationPayload($input);

        $vaccinationRepository->createVaccination($payload);

        $sent = $vaccinationSmsAlertService->checkAndSendAlerts(2);
        if ($sent > 0) {
            $this->addFlash('success', sprintf('%d SMS alert(s) sent successfully.', $sent));
        }

        return $this->redirectToVaccinationList();
    }

    #[Route('/elfirma/animaux-elevages/vaccination/update', name: 'vaccination_update', methods: ['POST'])]
    public function update(
        Request $request,
        VaccinationRepository $vaccinationRepository,
        VaccinationSmsAlertService $vaccinationSmsAlertService
    ): Response
    {
        $idVaccination = (int) $request->request->get('id_vaccination', 0);
        $formRedirect = [
            'module' => 'animaux-elevages',
            'view' => 'vaccination',
        ];
        $input = $this->collectVaccinationInput($request);

        if ($idVaccination > 0) {
            $formRedirect['edit'] = (string) $idVaccination;
        }

        if (!$this->isCsrfTokenValid('vaccination_update', (string) $request->request->get('_token', ''))) {
            return $this->redirectToVaccinationFormWithFieldErrors($formRedirect, [
                '_form' => 'Session expired. Please try again.',
            ], $input);
        }

        if ($idVaccination <= 0 || !$vaccinationRepository->existsById($idVaccination)) {
            return $this->redirectToVaccinationFormWithFieldErrors($formRedirect, [
                '_form' => 'Invalid vaccination.',
            ], $input);
        }

        $errors = $this->validateVaccinationInput($input, $vaccinationRepository);
        if ($errors !== []) {
            return $this->redirectToVaccinationFormWithFieldErrors($formRedirect, $errors, $input);
        }

        $payload = $this->toVaccinationPayload($input);

        $vaccinationRepository->updateVaccination($idVaccination, $payload);

        $sent = $vaccinationSmsAlertService->checkAndSendAlerts(2);
        if ($sent > 0) {
            $this->addFlash('success', sprintf('%d SMS alert(s) sent successfully.', $sent));
        }

        return $this->redirectToVaccinationList();
    }

    #[Route('/elfirma/animaux-elevages/vaccination/delete', name: 'vaccination_delete', methods: ['POST'])]
    public function delete(Request $request, VaccinationRepository $vaccinationRepository): Response
    {
        if (!$this->isCsrfTokenValid('vaccination_delete', (string) $request->request->get('_token', ''))) {
            return $this->redirectToVaccinationList();
        }

        $idVaccination = (int) $request->request->get('id_vaccination', 0);
        if ($idVaccination <= 0) {
            return $this->redirectToVaccinationList();
        }

        $vaccinationRepository->deleteVaccination($idVaccination);

        return $this->redirectToVaccinationList();
    }

    /**
     * @return array{id_animal:string,vaccine_name:string,date_done:string,date_next:string,notes:string,status:string}
     */
    private function collectVaccinationInput(Request $request): array
    {
        return [
            'id_animal' => trim((string) $request->request->get('id_animal', '')),
            'vaccine_name' => trim((string) $request->request->get('vaccine_name', '')),
            'date_done' => trim((string) $request->request->get('date_done', '')),
            'date_next' => trim((string) $request->request->get('date_next', '')),
            'notes' => trim((string) $request->request->get('notes', '')),
            'status' => trim((string) $request->request->get('status', 'Scheduled')),
        ];
    }

    /**
     * @param array{id_animal:string,vaccine_name:string,date_done:string,date_next:string,notes:string,status:string} $input
     *
     * @return array<string,string>
     */
    private function validateVaccinationInput(array $input, VaccinationRepository $vaccinationRepository): array
    {
        $errors = [];

        $idAnimal = filter_var($input['id_animal'], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($idAnimal === false) {
            $errors['id_animal'] = 'Please select a valid animal.';
        } elseif (!$vaccinationRepository->animalExistsById((int) $idAnimal)) {
            $errors['id_animal'] = 'Selected animal was not found.';
        }

        if ($input['vaccine_name'] === '') {
            $errors['vaccine_name'] = 'Vaccine name is required.';
        } elseif (!preg_match('/^[\p{L}\s]+$/u', $input['vaccine_name'])) {
            $errors['vaccine_name'] = 'Vaccination name can contain letters and spaces only';
        }

        if ($input['date_done'] === '') {
            $errors['date_done'] = 'Vaccination Date is required';
        } elseif (!$this->isValidDate($input['date_done'])) {
            $errors['date_done'] = 'Vaccination date is invalid.';
        }

        if ($input['date_next'] === '') {
            $errors['date_next'] = 'Next vaccination date is required.';
        } elseif (!$this->isValidDate($input['date_next'])) {
            $errors['date_next'] = 'Next vaccination date is invalid.';
        }

        if ($input['date_done'] !== '' && $this->isValidDate($input['date_done']) && $this->isValidDate($input['date_next'])) {
            if ($input['date_done'] > $input['date_next']) {
                $errors['date_next'] = 'Next date must be after vaccination date.';
            }
        }

        if ($input['notes'] === '') {
            $errors['notes'] = 'Notes is required';
        }

        if (!\in_array($input['status'], ['Scheduled', 'Done', 'Overdue'], true)) {
            $errors['status'] = 'Status is invalid.';
        }

        return $errors;
    }

    /**
     * @param array{id_animal:string,vaccine_name:string,date_done:string,date_next:string,notes:string,status:string} $input
     *
     * @return array{id_animal:int,vaccine_name:string,date_done:?string,date_next:string,notes:?string,status:?string}
     */
    private function toVaccinationPayload(array $input): array
    {
        return [
            'id_animal' => (int) $input['id_animal'],
            'vaccine_name' => $input['vaccine_name'],
            'date_done' => $input['date_done'] !== '' ? $input['date_done'] : null,
            'date_next' => $input['date_next'],
            'notes' => $input['notes'] !== '' ? $input['notes'] : null,
            'status' => $input['status'] !== '' ? $input['status'] : null,
        ];
    }

    private function isValidDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function redirectToVaccinationList(): Response
    {
        return $this->redirectToRoute('elfirma_page', [
            'module' => 'animaux-elevages',
            'view' => 'vaccination',
        ]);
    }

    /**
     * @param array{module:string,view:string,add?:string,edit?:string} $routeParams
     * @param array<string,string> $fieldErrors
     * @param array<string,string> $input
     */
    private function redirectToVaccinationFormWithFieldErrors(array $routeParams, array $fieldErrors, array $input): Response
    {
        if ($fieldErrors !== []) {
            $this->addFlash('form_errors', $fieldErrors);
        }
        $this->addFlash('form_input', $input);

        return $this->redirectToRoute('elfirma_page', $routeParams);
    }
}
