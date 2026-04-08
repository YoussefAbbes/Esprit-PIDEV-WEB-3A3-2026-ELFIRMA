<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Livestock;
use App\Repository\LivestockRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
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

        if (!$this->isCsrfTokenValid('livestock_create', (string) $request->request->get('_token', ''))) {
            return $this->redirectToLivestockList();
        }

        $payload = $this->extractLivestockPayload($request);
        if ($payload === null) {
            return $this->redirectToRoute('elfirma_page', $formRedirect);
        }

        $violations = $validator->validate(
            (new Livestock())
                ->setTypeElevage($payload['type_elevage'])
                ->setEtatElevage($payload['etat_elevage'])
                ->setCapacite($payload['capacite'])
                ->setNombreAnimaux(0)
                ->setProduction($payload['production'])
        );

        if (count($violations) > 0) {
            return $this->redirectToRoute('elfirma_page', $formRedirect);
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

        if ($idElevage > 0) {
            $formRedirect['edit'] = (string) $idElevage;
        }

        if (!$this->isCsrfTokenValid('livestock_update', (string) $request->request->get('_token', ''))) {
            return $this->redirectToLivestockList();
        }

        if ($idElevage <= 0) {
            return $this->redirectToRoute('elfirma_page', $formRedirect);
        }

        $payload = $this->extractLivestockPayload($request);
        if ($payload === null) {
            return $this->redirectToRoute('elfirma_page', $formRedirect);
        }

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
            return $this->redirectToRoute('elfirma_page', $formRedirect);
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
     * @return array{type_elevage:string,etat_elevage:string,capacite:?int,production:string}|null
     */
    private function extractLivestockPayload(Request $request): ?array
    {
        $typeElevage = trim((string) $request->request->get('type_elevage', ''));
        $etatElevage = trim((string) $request->request->get('etat_elevage', ''));
        $production = trim((string) $request->request->get('production', ''));

        $capaciteRaw = trim((string) $request->request->get('capacite', ''));
        $capacite = filter_var($capaciteRaw, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);

        if ($typeElevage === '' || $etatElevage === '' || $production === '' || $capacite === false) {
            return null;
        }

        return [
            'type_elevage' => $typeElevage,
            'etat_elevage' => $etatElevage,
            'capacite' => (int) $capacite,
            'production' => $production,
        ];
    }

    private function redirectToLivestockList(): Response
    {
        return $this->redirectToRoute('elfirma_page', [
            'module' => 'animaux-elevages',
            'view' => 'livestock',
        ]);
    }
}
