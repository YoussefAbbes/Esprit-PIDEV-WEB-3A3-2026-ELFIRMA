<?php

namespace App\Controller;

use App\Entity\Equipement;
use App\Repository\EquipementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use App\Form\EquipementType;
use App\Enum\EquipementEtat;
use App\Entity\Maintenance;
use App\Form\MaintenanceType;


class EquipementController extends AbstractController
{
    #[Route('/equipements', name: 'app_equipement_index')]
    public function index(EquipementRepository $repo): Response
    {
        $form = $this->createForm(EquipementType::class, new Equipement());
        $formMaintenance = $this->createForm(MaintenanceType::class, new Maintenance());

        return $this->render('elfirma/equipement/equipements.html.twig', [
            'equipements' => $repo->findAll(),
            'form' => $form->createView(),
            'formMaintenance' => $formMaintenance->createView(),

        ]);
    }

    #[Route('/equipements/new', name: 'app_equipement_new', methods: ['POST'])]
    public function new(
        Request $request, 
        EntityManagerInterface $em,
        EquipementRepository $repo,
        ValidatorInterface $validator
    ): Response {
        $equipement = new Equipement();
        $form = $this->createForm(EquipementType::class, $equipement);
        $formMaintenance = $this->createForm(MaintenanceType::class, new Maintenance());
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                // Validation supplémentaire par l'entity
                $errors = $validator->validate($equipement);

                if (count($errors) > 0) {
                    // Des erreurs de validation existent
                    return $this->render('elfirma/equipement/equipements.html.twig', [
                        'equipements' => $repo->findAll(),
                        'form' => $form->createView(),
                        'formMaintenance' => $formMaintenance->createView(),
                        'show_modal' => true,
                        'modal_errors' => true
                    ]);
                }

                // Aucune erreur, on persiste
                $em->persist($equipement);
                $em->flush();

                $this->addFlash('success', '✅ Équipement ajouté avec succès !');
                return $this->redirectToRoute('app_equipement_index');
            } else {
                // Le formulaire n'est pas valide
                return $this->render('elfirma/equipement/equipements.html.twig', [
                    'equipements' => $repo->findAll(),
                    'form' => $form->createView(),
                    'formMaintenance' => $formMaintenance->createView(),
                    'show_modal' => true,
                    'modal_errors' => true
                ]);
            }
        }

        // GET request
        return $this->render('elfirma/equipement/equipements.html.twig', [
            'equipements' => $repo->findAll(),
            'form' => $form->createView(),
            'formMaintenance' => $formMaintenance->createView(),
            'show_modal' => false,
            'modal_errors' => false
        ]);
    }

    #[Route('/equipements/{id}/edit', name: 'app_equipement_edit', methods: ['POST'])]
    public function edit(
        Request $request,
        Equipement $equipement,
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
            // Mise à jour des données
            if (isset($data['nomEq'])) {
                $equipement->setNomEq($data['nomEq']);
            }
            if (isset($data['typeEq'])) {
                $equipement->setTypeEq($data['typeEq']);
            }
            if (isset($data['dateAchat'])) {
                $equipement->setDateAchat(new \DateTime($data['dateAchat']));
            }
            if (isset($data['etat'])) {
                $equipement->setEtat(EquipementEtat::from($data['etat']));
            }
            if (isset($data['coutAchat'])) {
                $equipement->setCoutAchat((float) $data['coutAchat']);
            }
            if (isset($data['descriptionEq'])) {
                $equipement->setDescriptionEq($data['descriptionEq']);
            }

            // Validation de l'entity
            $errors = $validator->validate($equipement);

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

            // Pas d'erreurs, on persiste
            $em->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Équipement modifié avec succès',
                'data' => [
                    'id' => $equipement->getIdEq(),
                    'nomEq' => $equipement->getNomEq(),
                    'typeEq' => $equipement->getTypeEq(),
                    'dateAchat' => $equipement->getDateAchat()?->format('Y-m-d'),
                    'etat' => $equipement->getEtat()->value,
                    'coutAchat' => $equipement->getCoutAchat(),
                    'descriptionEq' => $equipement->getDescriptionEq()
                ]
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erreur lors de la modification: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/equipements/{id}/delete', name: 'app_equipement_delete', methods: ['POST'])]
    public function delete(
        Equipement $equipement,
        EntityManagerInterface $em
    ): JsonResponse {
        try {
            $em->remove($equipement);
            $em->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Équipement supprimé avec succès'
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erreur lors de la suppression: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}