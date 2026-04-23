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
use App\Service\AIPredictionService;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use Twig\Environment;
use Symfony\Component\DependencyInjection\Attribute\Autowire;


class EquipementController extends AbstractController
{
#[Route('/equipements', name: 'app_equipement_index')]
public function index(
    EquipementRepository $repo,
    EntityManagerInterface $em,
    AIPredictionService $ai,
    ChartBuilderInterface $chartBuilder,
    MailerInterface $mailer,
    Environment $twig,
    #[Autowire('%env(MAILER_FROM)%')]
    string $mailerFrom
): Response {

    $form = $this->createForm(EquipementType::class, new Equipement());
    $formMaintenance = $this->createForm(MaintenanceType::class, new Maintenance());

    $equipements = $repo->findAll();

    // 🔄 Mise à jour état
    foreach ($equipements as $eq) {
        $this->updateEquipementEtat($eq, $em, $mailer, $twig, $mailerFrom);
    }
    $em->flush();

    // Générer les charts
    $charts = $this->generateCharts($equipements, $chartBuilder, $ai);

    // =========================
    // 📤 RETURN
    // =========================
    return $this->render('elfirma/equipement/equipements.html.twig', [
        'equipements' => $equipements,
        'form' => $form->createView(),
        'formMaintenance' => $formMaintenance->createView(),
        'chart' => $charts['chart'],
        'chartMaint' => $charts['chartMaint']
    ]);
}

    private function generateCharts($equipements, ChartBuilderInterface $chartBuilder, AIPredictionService $ai)
    {
        // =========================
        // 🤖 1. IA GLOBAL (RISQUES)
        // =========================
        $risques = [0 => 0, 1 => 0, 2 => 0];

        foreach ($equipements as $eq) {

            $nbMaintenances = count($eq->getMaintenances());

            $totalCout = 0;
            foreach ($eq->getMaintenances() as $m) {
                $totalCout += $m->getCout();
            }

            $age = (new \DateTime())->diff($eq->getDateAchat())->y;

            $etatMap = [
                'bon' => 'bon',
                'moyen' => 'moyen',
                'critique' => 'critique',
                'disponible' => 'bon',
                'maintenance' => 'moyen',
                'panne' => 'critique'
            ];

            $etatString = strtolower($eq->getEtat()->value);
            $etat = $etatMap[$etatString] ?? 'bon';

            $payload = [
                'etat' => $etat,
                'age' => $age,
                'cout' => $eq->getCoutAchat(),
                'nb_maintenances' => $nbMaintenances,
                'total_cout' => $totalCout
            ];

            try {
                $result = $ai->predict($payload);
                $risques[$result['risk']]++;
            } catch (\Exception $e) {
                // fallback si API down
                $risques[0]++;
            }
        }

        // =========================
        // 📊 2. GRAPH RISQUES
        // =========================
        $chartRisk = $chartBuilder->createChart(Chart::TYPE_DOUGHNUT);

        $chartRisk->setData([
            'labels' => [
                'Faible (Bon état)',
                'Moyen (Surveillance)',
                'Élevé (Critique)'
            ],
            'datasets' => [
                [
                    'data' => [
                        $risques[0],
                        $risques[1],
                        $risques[2]
                    ],
                    'backgroundColor' => [
                        'rgba(34,197,94,0.7)',
                        'rgba(251,191,36,0.7)',
                        'rgba(239,68,68,0.7)'
                    ],
                    'borderWidth' => 1
                ],
            ],
        ]);

        $chartRisk->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false
        ]);

        // =========================
        // 📈 3. GRAPH MAINTENANCES
        // =========================
        $months = array_fill(1, 12, 0);

        foreach ($equipements as $eq) {
            foreach ($eq->getMaintenances() as $m) {
                $month = (int)$m->getDateM()->format('m');
                $months[$month]++;
            }
        }

        $labels = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin',
                   'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];

        $dataMaint = array_values($months);

        $chartMaint = $chartBuilder->createChart(Chart::TYPE_LINE);

        $chartMaint->setData([
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Maintenances par mois',
                    'data' => $dataMaint,
                    'borderColor' => 'rgb(59,130,246)',
                    'backgroundColor' => 'rgba(59,130,246,0.2)',
                    'fill' => true,
                    'tension' => 0.4
                ],
            ],
        ]);

        $chartMaint->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false
        ]);

        return [
            'chart' => $chartRisk,
            'chartMaint' => $chartMaint
        ];
    }

    #[Route('/equipements/new', name: 'app_equipement_new', methods: ['POST'])]
    public function new(
        Request $request, 
        EntityManagerInterface $em,
        EquipementRepository $repo,
        ValidatorInterface $validator,
        ChartBuilderInterface $chartBuilder,
        AIPredictionService $ai
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
                    $equipements = $repo->findAll();
                    $charts = $this->generateCharts($equipements, $chartBuilder, $ai);
                    return $this->render('elfirma/equipement/equipements.html.twig', [
                        'equipements' => $equipements,
                        'form' => $form->createView(),
                        'formMaintenance' => $formMaintenance->createView(),
                        'chart' => $charts['chart'],
                        'chartMaint' => $charts['chartMaint'],
                        'show_modal' => true,
                        'modal_errors' => true,
                    ]);
                }

                // Aucune erreur, on persiste
                $em->persist($equipement);
                $em->flush();

                $this->addFlash('success', '✅ Équipement ajouté avec succès !');
                return $this->redirectToRoute('app_equipement_index');
            } else {
                // Le formulaire n'est pas valide
                $equipements = $repo->findAll();
                $charts = $this->generateCharts($equipements, $chartBuilder, $ai);
                return $this->render('elfirma/equipement/equipements.html.twig', [
                    'equipements' => $equipements,
                    'form' => $form->createView(),
                    'formMaintenance' => $formMaintenance->createView(),
                    'chart' => $charts['chart'],
                    'chartMaint' => $charts['chartMaint'],
                    'show_modal' => true,
                    'modal_errors' => true,
                ]);
            }
        }

        // GET request
        $equipements = $repo->findAll();
        $charts = $this->generateCharts($equipements, $chartBuilder, $ai);
        return $this->render('elfirma/equipement/equipements.html.twig', [
            'equipements' => $equipements,
            'form' => $form->createView(),
            'formMaintenance' => $formMaintenance->createView(),
            'chart' => $charts['chart'],
            'chartMaint' => $charts['chartMaint'],
            'show_modal' => false,
            'modal_errors' => false,
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

    private function updateEquipementEtat(
        Equipement $equipement,
        EntityManagerInterface $em,
        MailerInterface $mailer,
        Environment $twig,
        string $mailerFrom
    ) {
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
            
            // 📧 ENVOYER UN EMAIL AU TECHNICIEN
            try {
                $htmlContent = $twig->render('emails/maintenance_alert.html.twig', [
                    'recipientName' => $technicien->getPrenomU() . ' ' . $technicien->getNomU(),
                    'equipementName' => $equipement->getNomEq(),
                    'maintenanceType' => $maintenance->getTypeM(),
                    'maintenanceDate' => $maintenance->getDateM()->format('d/m/Y'),
                    'technicianName' => $technicien->getPrenomU() . ' ' . $technicien->getNomU(),
                ]);

                $email = (new Email())
                    ->from(new Address($mailerFrom, 'Système de Gestion Agricole'))
                    ->to(new Address($technicien->getEmailU(), $technicien->getPrenomU() . ' ' . $technicien->getNomU()))
                    ->subject('🚨 Alerte Maintenance - Équipement Critique')
                    ->html($htmlContent);

                $mailer->send($email);
                
                error_log('✅ Email de maintenance envoyé à ' . $technicien->getEmailU() . ' pour ' . $equipement->getNomEq());
            } catch (\Exception $e) {
                // Log l'erreur mais ne bloque pas le processus
                error_log('❌ Erreur d\'envoi email: ' . $e->getMessage());
            }
        }

            $em->persist($maintenance);

            return;
        }
        if ($currentEtat === 'maintenance') {
            return;
        }

        $equipement->setEtat(\App\Enum\EquipementEtat::from('disponible'));
    }

    #[Route('/analyse/{id}', name: 'ai_analyse')]
    public function analyse($id, EquipementRepository $repo, AIPredictionService $ai)
{
        $equipement = $repo->find($id);
        
        if (!$equipement) {
            return $this->json([
                'error' => 'Équipement non trouvé'
            ], Response::HTTP_NOT_FOUND);
        }

        $nbMaintenances = count($equipement->getMaintenances());

        $totalCout = 0;
        foreach ($equipement->getMaintenances() as $m) {
            $totalCout += $m->getCout();
        }

        $age = (new \DateTime())->diff($equipement->getDateAchat())->y;

        $etatMap = [
            'bon' => 'bon',
            'moyen' => 'moyen',
            'critique' => 'critique',
            'disponible' => 'bon',
            'maintenance' => 'moyen',
            'panne' => 'critique'
        ];

        $payload = [
            'etat' => $etatMap[strtolower($equipement->getEtat()->value)] ?? 'bon',
            'age' => $age,
            'cout' => $equipement->getCoutAchat(),
            'nb_maintenances' => $nbMaintenances,
            'total_cout' => $totalCout
        ];

        try {
            $result = $ai->predict($payload);
            
            return $this->json([
                'nom' => $equipement->getNomEq(),
                'score' => $result['score'] ?? 0,
                'risk' => $result['risk'] ?? 0,
                'analysis' => $result['analysis'] ?? [],
                'recommendations' => $result['recommendations'] ?? ['Consultez les logs pour plus de détails']
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Le service d\'analyse IA est temporairement indisponible',
                'analysis' => [
                    'Données récupérées mais analyse IA non disponible',
                    'Âge de l\'équipement: ' . $age . ' ans',
                    'Nombre de maintenances: ' . $nbMaintenances
                ],
                'recommendations' => [
                    'Vérifiez que le service Python d\'IA est en cours d\'exécution sur le port 8001',
                    'Consultez les logs d\'application pour plus de détails'
                ],
                'score' => 50,
                'risk' => 1
            ], Response::HTTP_OK);
        }
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