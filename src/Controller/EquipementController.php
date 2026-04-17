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
    public function index(EquipementRepository $repo, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(EquipementType::class, new Equipement());
        $formMaintenance = $this->createForm(MaintenanceType::class, new Maintenance());

        $equipements = $repo->findAll();

        // 🔥 IMPORTANT : recalcul automatique
        foreach ($equipements as $eq) {
            $this->updateEquipementEtat($eq, $em);
        }

        $em->flush(); // 🔥 TRÈS IMPORTANT

        return $this->render('elfirma/equipement/equipements.html.twig', [
            'equipements' => $equipements,
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

    private function updateEquipementEtat(Equipement $equipement, EntityManagerInterface $em)
    {
        $maintenances = $equipement->getMaintenances();

        $sixMonthsAgo = new \DateTime('-6 months');

        $count6Months = 0;
        $totalCost = 0;

        foreach ($maintenances as $m) {
            $totalCost += $m->getCout();

            if ($m->getDateM() >= $sixMonthsAgo) {
                $count6Months++;
            }
        }

        $purchaseCost = $equipement->getCoutAchat();
        if ($purchaseCost <= 0) {
            $ratio = 0;
        } else {
            $ratio = ($totalCost / $purchaseCost) * 100;
        }
        $currentEtat = $equipement->getEtat()->value;
        if (($count6Months > 3 || $ratio > 50) && $totalCost > 0) {

            $equipement->setEtat(\App\Enum\EquipementEtat::from('panne'));
            foreach ($maintenances as $m) {
                if ($m->getTypeM() === 'Maintenance automatique' 
                    && $m->getDateM() >= new \DateTime('-2 days')) {
                    return; // déjà créée
                }
            }
            $maintenance = new \App\Entity\Maintenance();

            $maintenance->setEquipement($equipement);
            $maintenance->setTypeM('Maintenance automatique');
            $maintenance->setDescription('Maintenance générée automatiquement (équipement critique)');
            
            // 📅 date proche (demain)
            $maintenance->setDateM(new \DateTime('+1 day'));

            $maintenance->setCout(200);

            // ⚙️ statut
            $maintenance->setStatut(\App\Enum\MaintenanceStatut::from('planifie'));

            // 🔥 priorité élevée
            $maintenance->setPriorite(\App\Enum\MaintenancePriorite::from('urgente'));

            $technicien = $em->getRepository(\App\Entity\Utilisateur::class)
            ->findOneBy(['role_u' => 'employee']);

        if ($technicien) {
            $maintenance->setTechnicien($technicien);
        }

            $em->persist($maintenance);

            return;
        }
        if ($currentEtat === 'maintenance') {
            return;
        }

        $equipement->setEtat(\App\Enum\EquipementEtat::from('disponible'));
        }

        #[Route('/equipement/{id}/generate-image', name: 'generate_image', methods: ['POST'])]
public function generateImage(Equipement $equipement): JsonResponse
{
    $description = $equipement->getDescriptionEq();

    if (!$description) {
        return new JsonResponse([
            'success' => false,
            'message' => 'Description vide'
        ], 400);
    }

    $accountId = '1c9e8098b3c6cb26f06ef73dcc8d8846';
    $apiToken = '81nlNeNoH1aS4SZ0K06jKtt8X9H2DnluC75DR_Jn';

    $url = "https://api.cloudflare.com/client/v4/accounts/$accountId/ai/run/@cf/stabilityai/stable-diffusion-xl-base-1.0";

    $payload = json_encode([
        'prompt' => $description
    ]);

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $apiToken",
            "Content-Type: application/json"
        ],
        CURLOPT_TIMEOUT => 60
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        curl_close($ch);
        return new JsonResponse([
            'success' => false,
            'message' => 'Erreur API'
        ], 500);
    }

    curl_close($ch);

    // 🔥 encoder image en base64 pour frontend
    $base64 = base64_encode($response);

    return new JsonResponse([
        'success' => true,
        'image' => 'data:image/png;base64,' . $base64
    ]);
}
    
}