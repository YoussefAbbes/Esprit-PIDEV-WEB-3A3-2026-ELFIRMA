<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Entity\Reclamation;
// ...existing code...
use App\Repository\AnimalRepository;
use App\Repository\LivestockRepository;
use App\Repository\VaccinationRepository;
use App\Service\VaccinationSmsAlertService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Controller\AdminTwoFactorController;

final class ElfirmaController extends AbstractController
{
    public function __construct(
        private readonly VaccinationRepository $vaccinationRepository,
        private readonly VaccinationSmsAlertService $vaccinationSmsAlertService,
        // ...existing code...
    ) {
    }

    private const MODULES = [
        'tableau-de-bord' => [
            'folder' => 'tableau_de_bord',
            'title' => 'Dashboard',
        ],
        'utilisateurs' => [
            'folder' => 'utilisateurs',
            'title' => 'Users',
        ],
        'parcelles-cultures' => [
            'folder' => 'parcelles_cultures',
            'title' => 'Fields & Crops',
        ],
        'animaux-elevages' => [
            'folder' => 'animaux_levages',
            'title' => 'Livestock & Animals',
        ],
        'categories' => [
            'folder' => 'categories',
            'title' => 'Categories',
        ],
        'produits' => [
            'folder' => 'produits',
            'title' => 'Products',
        ],
        'produits-commandes' => [
            'folder' => 'produits_commandes',
            'title' => 'Products & Orders',
        ],
        'equipements-maintenance' => [
            'folder' => 'quipements_maintenance',
            'title' => 'Equipment & Maintenance',
        ],
        'fournisseurs-contrats' => [
            'folder' => 'fournisseurs_contrats',
            'title' => 'Suppliers & Contracts',
        ],
        'reclamations' => [
            'folder' => 'r_clamations',
            'title' => 'Claims',
        ],
    ];

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->redirectToRoute('elfirma_page', ['module' => 'tableau-de-bord']);
    }

    #[Route('/elfirma', name: 'elfirma_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('elfirma/index.html.twig', [
            'modules' => self::MODULES,
        ]);
    }
    
    #[Route('/elfirma/profile', name: 'elfirma_profile', methods: ['GET'])]
    public function profile(Request $request, EntityManagerInterface $entityManager): Response
    {
    // Get user ID from session
    $session = $request->getSession();
    $userId = $session->get('user_id');

    if (!$userId) {
        return $this->redirect('/');
    }

    // Get user from database
    $utilisateurRepo = $entityManager->getRepository(Utilisateur::class);
    $user = $utilisateurRepo->find($userId);

    if (!$user) {
        return $this->redirect('/');
    }

    // Get user complaints
    $reclamationRepo = $entityManager->getRepository(Reclamation::class);
    $complaints = $reclamationRepo->findBy(['utilisateur' => $userId]);

    return $this->render('elfirma/profile.html.twig', [
        'user' => $user,
        'complaints' => $complaints
    ]);
}

    #[Route('/elfirma/Livestock', name: 'elfirma_livestock', methods: ['GET'])]
    public function livestockPage(
        Request $request,
        LivestockRepository $livestockRepository,
        AnimalRepository $animalRepository
    ): Response
    {
        return $this->renderLivestockAnimalManagementView('livestock', $request, $livestockRepository, $animalRepository);
    }

    #[Route('/elfirma/animaux-elevages/export/pdf', name: 'elfirma_livestock_export_pdf', methods: ['GET'])]
    public function exportLivestockReport(Request $request, LivestockRepository $livestockRepository): Response
    {
        $searchTerm = trim($request->query->getString('search', ''));
        $searchError = $this->validateSearchTerm($searchTerm);

        $elevages = $livestockRepository->findAllForManagement();
        if ($searchTerm !== '' && $searchError === null) {
            $elevages = array_values(array_filter(
                $elevages,
                function (array $item) use ($searchTerm): bool {
                    return $this->matchesSearch($searchTerm, [
                        $item['type_elevage'] ?? '',
                        $item['etat_elevage'] ?? '',
                        $item['production'] ?? '',
                    ]);
                }
            ));
        }

        $generatedAt = (new \DateTimeImmutable())->format('d/m/Y');
        $pdfBinary = $this->buildLivestockExportPdf($elevages, $generatedAt);

        return new Response($pdfBinary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="livestock-export-report.pdf"',
        ]);
    }

    #[Route('/elfirma/animals', name: 'elfirma_animals', methods: ['GET'])]
    public function animalsPage(
        Request $request,
        LivestockRepository $livestockRepository,
        AnimalRepository $animalRepository
    ): Response
    {
        return $this->renderLivestockAnimalManagementView('animal', $request, $livestockRepository, $animalRepository);
    }

    #[Route('/elfirma/vaccinations', name: 'elfirma_vaccinations', methods: ['GET'])]
    public function vaccinationsPage(
        Request $request,
        LivestockRepository $livestockRepository,
        AnimalRepository $animalRepository
    ): Response
    {
        $sentSmsCount = $this->vaccinationSmsAlertService->checkAndSendAlerts(7); // Hardcoded value
        if ($sentSmsCount > 0) {
            $this->addFlash('success', sprintf('%d SMS alert(s) sent successfully.', $sentSmsCount));
        }

        return $this->renderLivestockAnimalManagementView('vaccination', $request, $livestockRepository, $animalRepository);
    }

    #[Route('/elfirma/map', name: 'elfirma_livestock_map', methods: ['GET'])]
    public function mapPage(
        Request $request,
        LivestockRepository $livestockRepository,
        AnimalRepository $animalRepository
    ): Response
    {
        return $this->renderLivestockAnimalManagementView('map', $request, $livestockRepository, $animalRepository);
    }

    #[Route('/elfirma/chatbot', name: 'elfirma_chatbot', methods: ['GET'])]
    public function chatbotPage(): Response
    {
        return $this->render('elfirma/Livestock&Animal Management/chatbot.html.twig');
    }

    #[Route(
        '/elfirma/{module}',
        name: 'elfirma_page',
        methods: ['GET'],
        priority: -100,
        requirements: [
            'module' => 'tableau-de-bord|utilisateurs|parcelles-cultures|animaux-elevages|categories|produits|produits-commandes|equipements-maintenance|fournisseurs-contrats|reclamations',
        ]
    )]
    public function page(
        string $module,
        Request $request,
        EntityManagerInterface $em,
        LivestockRepository $livestockRepository,
        AnimalRepository $animalRepository
    ): Response
    {
        if (!isset(self::MODULES[$module])) {
            throw $this->createNotFoundException(sprintf('Module "%s" was not found.', $module));
        }
        $moduleMeta = self::MODULES[$module];
        if ($module === 'employee_maintenances') {

            $session = $request->getSession();
            $userId = $session->get('user_id');

            if (!$userId) {
                return $this->redirectToRoute('app_login');
            }

            $user = $em->getRepository(Utilisateur::class)->find($userId);

            $maintenances = $em->getRepository(\App\Entity\Maintenance::class)
                ->findBy(['technicien' => $user]);

            return $this->render('elfirma/employee/maintenancesE.html.twig', [
                'maintenances' => $maintenances,
                'current_module' => $module,
                'modules' => self::MODULES,
            ]);
        }
        if ($module === 'utilisateurs') {
            $session = $request->getSession();
            if ($session->get('user_role') !== 'admin') {
                $session->invalidate();
                return $this->redirectToRoute('app_login');
            }

            if (!AdminTwoFactorController::hasValidAdminTwoFactor($request)) {
                return $this->redirectToRoute('app_admin_panel_2fa');
            }
        }

        if ($module === 'utilisateurs') {
    $session = $request->getSession();
    if ($session->get('user_role') !== 'admin' || !AdminTwoFactorController::hasValidAdminTwoFactor($request)) {
        $session->invalidate();
        return $this->redirectToRoute('app_login');
    }
}

        if ($module === 'utilisateurs') {
    $session = $request->getSession();
    if ($session->get('user_role') !== 'admin' || !AdminTwoFactorController::hasValidAdminTwoFactor($request)) {
        $session->invalidate();
        return $this->redirectToRoute('app_login');
    }
}

        $moduleMeta = self::MODULES[$module];

        if ($module === 'animaux-elevages') {
            $view = $request->query->getString('view', 'livestock');
            if (!\in_array($view, ['livestock', 'animal', 'vaccination', 'map'], true)) {
                $view = 'livestock';
            }

            $routeName = match ($view) {
                'animal' => 'elfirma_animals',
                'vaccination' => 'elfirma_vaccinations',
                'map' => 'elfirma_livestock_map',
                default => 'elfirma_livestock',
            };
            $queryParams = $request->query->all();
            unset($queryParams['view']);

            return $this->redirectToRoute($routeName, $queryParams);
        }

        if ($module === 'categories') {
            return $this->redirectToRoute('elfirma_categories');
        }

        if ($module === 'produits') {
            return $this->redirectToRoute('elfirma_products');
        }

        return $this->render(sprintf('elfirma/%s.html.twig', $moduleMeta['folder']), [
            'module_meta' => $moduleMeta,
            'current_module' => $module,
            'modules' => self::MODULES,
        ]);
    }

    private function renderLivestockAnimalManagementView(
        string $view,
        Request $request,
        LivestockRepository $livestockRepository,
        AnimalRepository $animalRepository
    ): Response
    {
        if (!\in_array($view, ['livestock', 'animal', 'vaccination', 'map'], true)) {
            $view = 'livestock';
        }

        $searchTerm = trim($request->query->getString('search', ''));
        $searchError = $this->validateSearchTerm($searchTerm);

        if ($view === 'livestock') {
            $editId = $request->query->getInt('edit', 0);
            $editLivestock = null;
            if ($editId > 0) {
                $editLivestock = $livestockRepository->findForEdit($editId);
            }

            $showAddForm = \in_array(strtolower($request->query->getString('add', '0')), ['1', 'true', 'yes'], true);

            $livestockStates = $livestockRepository->findDistinctStates();
            $elevages = $livestockRepository->findAllForManagement();
            if ($searchTerm !== '' && $searchError === null) {
                $elevages = array_values(array_filter(
                    $elevages,
                    function (array $item) use ($searchTerm): bool {
                        return $this->matchesSearch($searchTerm, [
                            $item['type_elevage'] ?? '',
                            $item['etat_elevage'] ?? '',
                            $item['production'] ?? '',
                        ]);
                    }
                ));
            }
            $livestockStats = $livestockRepository->fetchStats();

            return $this->render('elfirma/Livestock&Animal Management/livestock.html.twig', [
                'elevages' => $elevages,
                'livestock_stats' => $livestockStats,
                'livestock_states' => $livestockStates,
                'search_term' => $searchTerm,
                'search_error' => $searchError,
                'show_add_form' => $showAddForm,
                'edit_livestock' => $editLivestock,
                'maptiler_api_key' => (string) $this->getParameter('app.maptiler_api_key'),
            ]);
        }

        if ($view === 'animal') {
            $animalEditId = $request->query->getInt('edit', 0);
            $editAnimal = null;
            if ($animalEditId > 0) {
                $editAnimal = $animalRepository->findForEdit($animalEditId);
            }

            $livestockOptions = $livestockRepository->findOptionsForAnimalForm();

            $showAddAnimalForm = \in_array(strtolower($request->query->getString('add', '0')), ['1', 'true', 'yes'], true);

            $animalStatuses = $animalRepository->findDistinctStatuses();
            $animalHealthOptions = $animalRepository->findDistinctHealthOptions();
            $animals = $animalRepository->findAllForManagement();
            if ($searchTerm !== '' && $searchError === null) {
                $animals = array_values(array_filter(
                    $animals,
                    function (array $item) use ($searchTerm): bool {
                        return $this->matchesSearch($searchTerm, [
                            $item['type_animal'] ?? '',
                            $item['sexe'] ?? '',
                            $item['etat_sante'] ?? '',
                            $item['statut'] ?? '',
                        ]);
                    }
                ));
            }
            $animalStats = $animalRepository->fetchStats();

            return $this->render('elfirma/Livestock&Animal Management/animal.html.twig', [
                'animals' => $animals,
                'livestock_options' => $livestockOptions,
                'animal_stats' => $animalStats,
                'animal_statuses' => $animalStatuses,
                'animal_health_options' => $animalHealthOptions,
                'search_term' => $searchTerm,
                'search_error' => $searchError,
                'show_add_animal_form' => $showAddAnimalForm,
                'edit_animal' => $editAnimal,
            ]);
        }

        if ($view === 'map') {
            return $this->render('elfirma/Livestock&Animal Management/map.html.twig', [
                'elevages' => $livestockRepository->findAllForMap(),
                'maptiler_api_key' => (string) $this->getParameter('app.maptiler_api_key'),
            ]);
        }

        $vaccinationEditId = $request->query->getInt('edit', 0);
        $editVaccination = null;
        if ($vaccinationEditId > 0) {
            $editVaccination = $this->vaccinationRepository->findForEdit($vaccinationEditId);
        }

        $showAddVaccinationForm = \in_array(strtolower($request->query->getString('add', '0')), ['1', 'true', 'yes'], true);

        $vaccinations = $this->vaccinationRepository->findAllForManagement();
        if ($searchTerm !== '' && $searchError === null) {
            $vaccinations = array_values(array_filter(
                $vaccinations,
                function (array $item) use ($searchTerm): bool {
                    return $this->matchesSearch($searchTerm, [
                        $item['animal_type'] ?? '',
                        $item['vaccine_name'] ?? '',
                        $item['notes'] ?? '',
                        $item['status'] ?? '',
                    ]);
                }
            ));
        }

        return $this->render('elfirma/Livestock&Animal Management/vaccination.html.twig', [
            'vaccinations' => $vaccinations,
            'vaccination_stats' => $this->vaccinationRepository->fetchStats(),
            'animal_options' => $this->vaccinationRepository->findAnimalOptions(),
            'search_term' => $searchTerm,
            'search_error' => $searchError,
            'show_add_vaccination_form' => $showAddVaccinationForm,
            'edit_vaccination' => $editVaccination,
        ]);
    }

    private function validateSearchTerm(string $searchTerm): ?string
    {
        if ($searchTerm === '') {
            return null;
        }

        return preg_match('/^[A-Za-z\s]+$/', $searchTerm) === 1
            ? null
            : 'Search can contain letters and spaces only';
    }

    /**
     * @param list<mixed> $values
     */
    private function matchesSearch(string $searchTerm, array $values): bool
    {
        $needle = strtolower($searchTerm);

        foreach ($values as $value) {
            if (str_contains(strtolower((string) $value), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{type:string, production:string}> $elevages
     */
   private function buildLivestockExportPdf(array $elevages, string $generatedAt): string
{
    $rows = array_map(
        static fn (array $item): array => [
            'type' => (string) ($item['type_elevage'] ?? 'N/A'),
            'production' => (string) ($item['production'] ?? 'N/A'),
            'status' => (string) ($item['etat_elevage'] ?? 'N/A'),
        ],
        $elevages
    );

    // Limite pour éviter débordement
    $rows = array_slice($rows, 0, 10);

    $total = count($rows);

    $content = [];
    $content[] = 'q';

    /* ================= HEADER ================= */
    $content[] = '0.10 0.45 0.20 rg';
    $content[] = '0 800 595 50 re f';

    $content[] = 'BT';
    $content[] = '/F2 20 Tf';
    $content[] = '1 1 1 rg';
    $content[] = '25 815 Td';
    $content[] = '(' . $this->escapePdfText('EL FIRMA - LIVESTOCK REPORT') . ') Tj';
    $content[] = 'ET';

    $content[] = 'BT';
    $content[] = '/F1 10 Tf';
    $content[] = '1 1 1 rg';
    $content[] = '420 815 Td';
    $content[] = '(' . $this->escapePdfText('Date: ' . $generatedAt) . ') Tj';
    $content[] = 'ET';

    /* ================= TITLE ================= */
    $content[] = 'BT';
    $content[] = '/F2 16 Tf';
    $content[] = '0.1 0.35 0.15 rg';
    $content[] = '25 770 Td';
    $content[] = '(' . $this->escapePdfText('Livestock & Production Overview') . ') Tj';
    $content[] = 'ET';

    /* ================= DESCRIPTION (plus aérée) ================= */
    $description = [
        'This report summarizes livestock production data.',
        'It helps monitoring farm performance and animal status.',
        'All data below is generated automatically from the system.'
    ];

    $y = 750;
    foreach ($description as $line) {
        $content[] = 'BT';
        $content[] = '/F1 10 Tf';
        $content[] = '0.25 0.25 0.25 rg';
        $content[] = '25 ' . $y . ' Td';
        $content[] = '(' . $this->escapePdfText($line) . ') Tj';
        $content[] = 'ET';

        $y -= 18; // espacement plus propre
    }

    /* ================= STATS ================= */
    $content[] = 'BT';
    $content[] = '/F2 11 Tf';
    $content[] = '0.10 0.40 0.10 rg';
    $content[] = '25 700 Td';
    $content[] = '(' . $this->escapePdfText("Total Records: $total") . ') Tj';
    $content[] = 'ET';

    /* ================= TABLE ================= */
    $tableX = 25;
    $tableY = 680;
    $tableW = 545;
    $rowH = 30;

    $col1 = 200;
    $col2 = 200;
    $col3 = 145;

    $positions = [
        $tableX + 10,
        $tableX + $col1 + 10,
        $tableX + $col1 + $col2 + 10
    ];

    /* HEADER TABLE */
    $content[] = '0.18 0.55 0.22 rg';
    $content[] = sprintf('%d %d %d %d re f', $tableX, $tableY, $tableW, $rowH);

    $headers = ['Type', 'Production', 'Status'];

    foreach ($headers as $i => $h) {
        $content[] = 'BT';
        $content[] = '/F2 11 Tf';
        $content[] = '1 1 1 rg';
        $content[] = $positions[$i] . ' ' . ($tableY + 10) . ' Td';
        $content[] = '(' . $this->escapePdfText($h) . ') Tj';
        $content[] = 'ET';
    }

    /* ROWS */
    $y = $tableY - $rowH;

    foreach ($rows as $i => $row) {

        // alternance couleur
        $content[] = ($i % 2 === 0)
            ? '0.95 0.98 0.95 rg'
            : '1 1 1 rg';

        $content[] = sprintf('%d %d %d %d re f', $tableX, $y, $tableW, $rowH);

        $values = [$row['type'], $row['production'], $row['status']];

        foreach ($values as $j => $val) {
            $content[] = 'BT';
            $content[] = '/F1 10 Tf';
            $content[] = '0.15 0.15 0.15 rg';
            $content[] = $positions[$j] . ' ' . ($y + 10) . ' Td';
            $content[] = '(' . $this->escapePdfText($val) . ') Tj';
            $content[] = 'ET';
        }

        $y -= $rowH;
    }

    /* ================= FOOTER ================= */
   /* ================= FOOTER (remonté) ================= */
/* ================= FOOTER (beaucoup plus haut) ================= */
$content[] = '0.10 0.45 0.20 rg';

// 🔥 FOOTER TRÈS HAUT (y = 180)
$content[] = '0 180 595 60 re f';

$content[] = 'BT';
$content[] = '/F1 10 Tf';
$content[] = '1 1 1 rg';

// 🔥 texte très haut
$content[] = '25 100 Td';
$content[] = '(' . $this->escapePdfText('EL FIRMA - Farm Management System') . ') Tj';
$content[] = 'ET';

$content[] = 'BT';
$content[] = '/F1 10 Tf';
$content[] = '1 1 1 rg';

// 🔥 manager aligné avec le footer
$content[] = '400 200 Td';
$content[] = '(' . $this->escapePdfText('Manager: Ahmed Zouari') . ') Tj';
$content[] = 'ET';

    /* ================= PDF BUILD ================= */
    $stream = implode("\n", $content);

    $objects = [];
    $objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
    $objects[] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
    $objects[] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>\nendobj\n";
    $objects[] = "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
    $objects[] = "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>\nendobj\n";
    $objects[] = "6 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream\nendobj\n";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objects as $object) {
        $offsets[] = strlen($pdf);
        $pdf .= $object;
    }

    $xrefPos = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";

    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }

    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xrefPos . "\n%%EOF";

    return $pdf;
}
    private function normalizePdfText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'N/A';
        }

        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($converted !== false) {
                $value = $converted;
            }
        }

        return preg_replace('/[^\x20-\x7E]/', '', $value) ?? $value;
    }

    private function escapePdfText(string $value): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $this->normalizePdfText($value));
    }
}
