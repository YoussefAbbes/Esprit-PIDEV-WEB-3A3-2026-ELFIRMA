<?php

namespace App\Controller;

use App\Entity\Maintenance;
use App\Entity\Equipement;
use App\Entity\Utilisateur;

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
        Request $request,
        EntityManagerInterface $em
    ): Response {

        $equipementId = $request->query->get('equipement');

        if ($equipementId) {
            $maintenances = $repo->findBy([
                'equipement' => $equipementId
            ]);
        } else {
            $maintenances = $repo->findAll();
        }

        $employees = $em->getRepository(Utilisateur::class)
        ->findBy(['role_u' => 'employee']);

        return $this->render('elfirma/equipement/maintenances.html.twig', [
            'maintenances' => $maintenances,
            'employees' => $employees   
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

                $date = $maintenance->getDateM();
                $isHoliday = $this->isHoliday($date);

                if ($isHoliday) {
                    $formMaintenance->get('dateM')->addError(
                        new \Symfony\Component\Form\FormError('❌ Cette date est un jour férié')
                    );
                }

                // 🔥 BLOQUAGE CLAIR
                if ($formMaintenance->isValid() && !$isHoliday) {

                    $equipement = $maintenance->getEquipement();

                    $em->persist($maintenance);

                    $this->updateEquipementEtat($equipement, $em);

                    $em->flush();

                    $this->addFlash('success', '✅ Maintenance ajoutée avec succès !');

                    return $this->redirectToRoute('app_equipement_index');
                }

                // ❌ sinon on reste sur le formulaire
                return $this->render('elfirma/equipement/equipements.html.twig', [
                    'maintenances' => $repo->findAll(),
                    'equipements' => $equipementRepo->findAll(),
                    'form' => $form->createView(),
                    'formMaintenance' => $formMaintenance->createView(),
                    'show_modal' => true,
                    'modal_errors' => true
                ]);
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

            $equipement = $maintenance->getEquipement();

            // 🔥 recalcul état
            $this->updateEquipementEtat($equipement, $em);

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

        $equipement = $maintenance->getEquipement();

        $em->remove($maintenance);
        $em->flush();

        // 🔥 recalcul après suppression
        $this->updateEquipementEtat($equipement, $em);
        $em->flush();

        return new Response('Deleted');
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
            
            $date = new \DateTime('+1 day');
            while ($this->isHoliday($date)) {
                $date->modify('+1 day');
            }
            $maintenance->setDateM($date);

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
       private function isHoliday(\DateTime $date): bool
        {
            $apiKey = 'uUEzUqYq5jExcWlBWULmo5eSzFm6SBFyeSeHG9pt';

            $query = http_build_query([
                'country' => 'TN',
                'date' => $date->format('Y-m-d') // 🔥 IMPORTANT
            ]);

            $url = "https://api.api-ninjas.com/v1/ispublicholiday?$query";

            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "X-Api-Key: $apiKey",
                    "Accept: application/json"
                ],
                CURLOPT_TIMEOUT => 5
            ]);

            $response = curl_exec($ch);

            if ($response === false) {
                curl_close($ch);
                return false;
            }

            curl_close($ch);

            $data = json_decode($response, true);

            // 🔥 EXACTEMENT comme Java
            if (isset($data['is_public_holiday']) && $data['is_public_holiday'] === true) {
                return true;
            }

            return false;
        }
}