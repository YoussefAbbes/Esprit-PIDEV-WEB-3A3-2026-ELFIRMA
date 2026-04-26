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
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;




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
            ValidatorInterface $validator,
            ChartBuilderInterface $chartBuilder,
            MailerInterface $mailer
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

    // ✅ validation APRÈS ajout des erreurs
    if ($formMaintenance->isValid()) {

        $equipement = $maintenance->getEquipement();

        $em->persist($maintenance);

        $this->updateEquipementEtat($equipement, $em, $mailer);

        $em->flush();

        $this->addFlash('success', '✅ Maintenance ajoutée avec succès !');

        return $this->redirectToRoute('app_equipement_index');
    }

    // ❌ si erreur → rester sur page
    $chart = $chartBuilder->createChart(Chart::TYPE_DOUGHNUT);
    $chart->setData([
        'labels' => ['Faible', 'Moyen', 'Élevé'],
        'datasets' => [[
            'data' => [1, 1, 1],
            'backgroundColor' => ['#16a34a', '#f59e0b', '#dc2626']
        ]]
    ]);

    $chartMaint = $chartBuilder->createChart(Chart::TYPE_LINE);
    $chartMaint->setData([
        'labels' => ['Jan', 'Feb', 'Mar'],
        'datasets' => [[
            'data' => [1, 2, 3],
            'borderColor' => '#2563eb'
        ]]
    ]);

    return $this->render('elfirma/equipement/equipements.html.twig', [
        'maintenances' => $repo->findAll(),
        'equipements' => $equipementRepo->findAll(),
        'form' => $form->createView(),
        'formMaintenance' => $formMaintenance->createView(),
        'show_modal' => true,
        'modal_errors' => true,
        'chart' => $chart,
        'chartMaint' => $chartMaint
    ]);
}

            $chart = $chartBuilder->createChart(Chart::TYPE_DOUGHNUT);
                $chart->setData([
                    'labels' => ['Faible', 'Moyen', 'Élevé'],
                    'datasets' => [[
                        'data' => [1, 1, 1],
                        'backgroundColor' => ['#16a34a', '#f59e0b', '#dc2626']
                    ]]
                ]);

                $chartMaint = $chartBuilder->createChart(Chart::TYPE_LINE);
                $chartMaint->setData([
                    'labels' => ['Jan', 'Feb', 'Mar'],
                    'datasets' => [[
                        'data' => [1, 2, 3],
                        'borderColor' => '#2563eb'
                    ]]
                ]);

            // GET request
            return $this->render('elfirma/equipement/equipements.html.twig', [
                'maintenances' => $repo->findAll(),
                'equipements' => $equipementRepo->findAll(),
                'formMaintenance' => $formMaintenance->createView(),
                'form' => $form->createView(),
                'show_modal' => false,
                'modal_errors' => false,
                'chart' => $chart,
                'chartMaint' => $chartMaint
            ]);
        }

    #[Route('/maintenance/{id}/edit', name: 'maintenance_edit', methods: ['POST'])]
    public function edit(
        Request $request,
        Maintenance $maintenance,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
        MailerInterface $mailer
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
            $equipement = $maintenance->getEquipement();

            // 🔥 recalcul état
                $this->updateEquipementEtat($equipement, $em, $mailer);

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
    public function delete($id, MaintenanceRepository $repo, EntityManagerInterface $em, MailerInterface $mailer): Response
    {
        $maintenance = $repo->find($id);

        if (!$maintenance) {
            return new Response('Not found', 404);
        }

        $equipement = $maintenance->getEquipement();

        $em->remove($maintenance);
        $em->flush();

        // 🔥 recalcul après suppression
            $this->updateEquipementEtat($equipement, $em, $mailer);
        $em->flush();

        return new Response('Deleted');
    }

#[Route('/employee/panel', name: 'employee_panel')]
public function employeePanel(
    MaintenanceRepository $repo,
    EntityManagerInterface $em,
    Request $request
): Response {

    $userId = $request->getSession()->get('user_id');

    if (!$userId) {
        return $this->redirectToRoute('app_login');
    }

    $user = $em->getRepository(Utilisateur::class)->find($userId);

    // 🔒 sécurité
    if ($user->getRoleU() !== 'employee') {
        throw $this->createAccessDeniedException();
    }

    $maintenances = $repo->findBy([
        'technicien' => $user
    ]);

    return $this->render('elfirma/employee/maintenancesE.html.twig', [
        'maintenances' => $maintenances
    ]);
}

#[Route('/api/maintenances', name: 'api_maintenances')]
public function api(MaintenanceRepository $repo): JsonResponse
{
    $events = [];

    foreach ($repo->findAll() as $m) {
        $events[] = [
            'title' => $m->getTypeM(),
            'start' => $m->getDateM()->format('Y-m-d'),
        ];
    }

    return new JsonResponse($events);
}

#[Route('/maintenance/{id}/start', name: 'maintenance_start')]
public function startMaintenance(
    Maintenance $maintenance,
    EntityManagerInterface $em,
    Request $request
): Response {

    $userId = $request->getSession()->get('user_id');
    $user = $em->getRepository(Utilisateur::class)->find($userId);

    // 🔒 sécurité : vérifier que c'est SON intervention
    if ($maintenance->getTechnicien() !== $user) {
        throw $this->createAccessDeniedException();
    }

    // ❌ logique métier
    if ($maintenance->getStatut()->value !== 'planifie') {
        $this->addFlash('error', 'Impossible de démarrer cette maintenance');
        return $this->redirectToRoute('employee_panel');
    }

    // ✅ update
    $maintenance->setStatut(MaintenanceStatut::from('en_cours'));
    $em->flush();

    $this->addFlash('success', 'Maintenance démarrée');

    return $this->redirectToRoute('employee_panel');
}

#[Route('/maintenance/{id}/finish', name: 'maintenance_finish')]
public function finishMaintenance(
    Maintenance $maintenance,
    EntityManagerInterface $em,
    Request $request
): Response {

    $userId = $request->getSession()->get('user_id');
    $user = $em->getRepository(Utilisateur::class)->find($userId);

    // 🔒 sécurité
    if ($maintenance->getTechnicien() !== $user) {
        throw $this->createAccessDeniedException();
    }

    // ❌ logique métier
    if ($maintenance->getStatut()->value !== 'en_cours') {
        $this->addFlash('error', 'Seules les maintenances en cours peuvent être terminées');
        return $this->redirectToRoute('employee_panel');
    }

    // ✅ update
    $maintenance->setStatut(MaintenanceStatut::from('termine'));
    $em->flush();

    $this->addFlash('success', 'Maintenance terminée');

    return $this->redirectToRoute('employee_panel');
}

#[Route('/maintenance/pdf', name: 'maintenance_pdf')]
public function exportPdf(MaintenanceRepository $repo)
{
    $maintenances = $repo->findAll();

    $html = $this->renderView('pdf/maintenance.html.twig', [
        'maintenances' => $maintenances
    ]);

    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html);

    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    return new Response(
        $dompdf->stream("rapport_maintenances.pdf", [
            "Attachment" => true
        ]),
        200,
        ['Content-Type' => 'application/pdf']
    );
}

private function updateEquipementEtat(Equipement $equipement, EntityManagerInterface $em, MailerInterface $mailer) 
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
    $ratio = $purchaseCost > 0 ? ($totalCost / $purchaseCost) * 100 : 0;

    $currentEtat = $equipement->getEtat()->value;

    error_log("🔍 DEBUG: count6Months=$count6Months, ratio=$ratio, totalCost=$totalCost");

    // 🔥 CONDITION CRITIQUE
    if (($count6Months > 3 || $ratio > 50) && $totalCost > 0) {
        error_log("✅ CONDITION MET - Creating automatic maintenance");
        
        $equipement->setEtat(\App\Enum\EquipementEtat::from('panne'));

        $alreadyExists = false;
        foreach ($maintenances as $m) {
            if (
                $m->getTypeM() === 'Maintenance automatique' &&
                $m->getDateM() >= new \DateTime('-2 days')
            ) {
                $alreadyExists = true;
                break;
            }
        }

        // ✅ Skip if already exists
        if ($alreadyExists) {
            error_log("⚠️ Maintenance automatique already exists in last 2 days");
            return;
        }

        // 🆕 Create maintenance
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
        $maintenance->setStatut(\App\Enum\MaintenanceStatut::from('planifie'));
        $maintenance->setPriorite(\App\Enum\MaintenancePriorite::from('urgente'));

        // 👨‍🔧 Get technician
        $technicien = $em->getRepository(\App\Entity\Utilisateur::class)
            ->findOneBy(['role_u' => 'employee']);

        if (!$technicien) {
            error_log('❌ No technician found');
            return;
        }

        if (!$technicien->getEmailU()) {
            error_log('❌ Technician has no email');
            return;
        }

        // ✅ PERSIST MAINTENANCE FIRST
        $maintenance->setTechnicien($technicien);
        $em->persist($maintenance);
        $em->flush(); // 🔥 CRUCIAL - Save to DB first

        error_log('✅ Maintenance saved to database');

        // 📬 SEND EMAIL AFTER DB SAVE
        try {
            error_log('📧 Sending email to: ' . $technicien->getEmailU());

            $email = (new TemplatedEmail())
                ->from('fethizouabi190@gmail.com')
                ->to($technicien->getEmailU())
                ->subject('⚠️ Maintenance critique - ' . $equipement->getNomEq())
                ->htmlTemplate('emails/maintenance_alert.html.twig')
                ->context([
                    'user' => $technicien->getNomU(),
                    'equipement' => $equipement->getNomEq(),
                    'description' => $maintenance->getDescription(),
                    'date' => $maintenance->getDateM()?->format('d/m/Y')
                ]);

            $mailer->send($email);
            error_log('✅ EMAIL SENT SUCCESSFULLY');

        } catch (\Exception $e) {
            error_log('❌ Email error: ' . $e->getMessage());
        }

        return;
    }

    error_log("❌ CONDITION NOT MET - No alert needed");

    // 🟢 Reset to available if no issues
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