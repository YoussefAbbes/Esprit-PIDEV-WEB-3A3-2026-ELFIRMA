<?php

namespace App\Controller;

use App\Entity\Maintenance;
use App\Entity\Equipement;

use App\Repository\MaintenanceRepository;
use App\Repository\EquipementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Enum\MaintenancePriorite;
use App\Enum\MaintenanceStatut;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use App\Form\MaintenanceType;
use App\Form\EquipementType;
use Symfony\Component\HttpFoundation\JsonResponse;




class MaintenanceController extends AbstractController
{
    #[Route('/maintenances', name: 'maintenance_list')]
    public function index(
        MaintenanceRepository $repo,
        EquipementRepository $equipementRepo,
        Request $request
    ): Response {

        $equipementId = $request->query->get('equipement');

        if ($equipementId) {
            $maintenances = $repo->findBy([
                'equipement' => $equipementId
            ]);
        } else {
            $maintenances = $repo->findAll();
        }

        return $this->render('elfirma/equipement/maintenances.html.twig', [
            'maintenances' => $maintenances,
        ]);
    }

        #[Route('/maintenance/new', name: 'maintenance_new', methods: ['POST'])]
        public function new(
            Request $request,
            EntityManagerInterface $em,
            MaintenanceRepository $repo,
            EquipementRepository $equipementRepo,
            ValidatorInterface $validator
        ): Response {
            $maintenance = new Maintenance();
            $form = $this->createForm(EquipementType::class, new Equipement());
            $formMaintenance = $this->createForm(MaintenanceType::class, $maintenance);
            $formMaintenance->handleRequest($request);

            if ($formMaintenance->isSubmitted()) {

                if ($formMaintenance->isValid()) {

                    // ✅ validation supplémentaire (comme Equipement)
                    $errors = $validator->validate($maintenance);

                    if (count($errors) > 0) {
                        return $this->render('elfirma/equipement/equipements.html.twig', [
                            'maintenances' => $repo->findAll(),
                            'equipements' => $equipementRepo->findAll(),
                            'form' => $form->createView(),
                            'formMaintenance' => $formMaintenance->createView(),
                            'show_modal' => true,
                            'modal_errors' => true
                        ]);
                    }

                    // ✅ OK → persist
                    $em->persist($maintenance);
                    $em->flush();

                    $this->addFlash('success', '✅ Maintenance ajoutée avec succès !');

                    return $this->redirectToRoute('app_equipement_index');
                } else {
                    // ❌ erreurs formulaire
                    return $this->render('elfirma/equipement/equipements.html.twig', [
                        'maintenances' => $repo->findAll(),
                        'equipements' => $equipementRepo->findAll(),
                        'form' => $form->createView(),
                        'formMaintenance' => $formMaintenance->createView(),
                        'show_modal' => true,
                        'modal_errors' => true
                    ]);
                }
            }

            // GET request
            return $this->render('elfirma/equipement/equipements.html.twig', [
                'maintenances' => $repo->findAll(),
                'equipements' => $equipementRepo->findAll(),
                'formMaintenance' => $formMaintenance->createView(),
                'form' => $form->createView(),
                'show_modal' => false,
                'modal_errors' => false
            ]);
        }

    #[Route('/maintenance/{id}/edit', name: 'maintenance_edit', methods: ['POST'])]
    public function edit(
        Request $request,
        Maintenance $maintenance,
        EntityManagerInterface $em,
        ValidatorInterface $validator
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Données invalides'
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            // ✅ TYPE
            if (isset($data['typeM'])) {
                $maintenance->setTypeM($data['typeM']);
            }

            // ✅ DATE
            if (isset($data['dateM']) && !empty($data['dateM'])) {
                $maintenance->setDateM(new \DateTime($data['dateM']));
            }

            // ✅ DESCRIPTION
            if (isset($data['description'])) {
                $maintenance->setDescription($data['description']);
            }

            // ✅ COUT
            if (isset($data['cout'])) {
                $maintenance->setCout((float) $data['cout']);
            }

            // ✅ TECHNICIEN
            if (isset($data['technicien'])) {
                $maintenance->setTechnicien($data['technicien']);
            }

            // ✅ STATUT (ENUM sécurisé)
            if (!empty($data['statut'])) {
                $statut = \App\Enum\MaintenanceStatut::tryFrom($data['statut']);
                if ($statut) {
                    $maintenance->setStatut($statut);
                }
            }

            // ✅ PRIORITE (ENUM sécurisé)
            if (!empty($data['priorite'])) {
                $priorite = \App\Enum\MaintenancePriorite::tryFrom($data['priorite']);
                if ($priorite) {
                    $maintenance->setPriorite($priorite);
                }
            }

            // ✅ VALIDATION
            $errors = $validator->validate($maintenance);

            if (count($errors) > 0) {
                $errorMessages = [];

                foreach ($errors as $error) {
                    $errorMessages[] = $error->getMessage();
                }

                return new JsonResponse([
                    'success' => false,
                    'message' => 'Erreurs de validation',
                    'errors' => $errorMessages
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // ✅ SAVE
            $em->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Maintenance modifiée avec succès',
                'data' => [
                    'id' => $maintenance->getIdM(),
                    'typeM' => $maintenance->getTypeM(),
                    'dateM' => $maintenance->getDateM()?->format('Y-m-d'),
                    'description' => $maintenance->getDescription(),
                    'cout' => $maintenance->getCout(),
                    'technicien' => $maintenance->getTechnicien(),
                    'statut' => $maintenance->getStatut()?->value,
                    'priorite' => $maintenance->getPriorite()?->value,
                    'equipement' => $maintenance->getEquipement()?->getNomEq()
                ]
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erreur lors de la modification: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/maintenance/{id}/delete', name: 'maintenance_delete', methods: ['POST'])]
    public function delete($id, MaintenanceRepository $repo, EntityManagerInterface $em): Response
    {
        $maintenance = $repo->find($id);

        if (!$maintenance) {
            return new Response('Not found', 404);
        }

        $em->remove($maintenance);
        $em->flush();

        return new Response('Deleted');
    }
}