<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Entity\Reclamation;
use App\Repository\AnimalRepository;
use App\Repository\LivestockRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Controller\AdminTwoFactorController;

final class ElfirmaController extends AbstractController
{
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

    #[Route('/elfirma/animals', name: 'elfirma_animals', methods: ['GET'])]
    public function animalsPage(
        Request $request,
        LivestockRepository $livestockRepository,
        AnimalRepository $animalRepository
    ): Response
    {
        return $this->renderLivestockAnimalManagementView('animal', $request, $livestockRepository, $animalRepository);
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
            if (!\in_array($view, ['livestock', 'animal'], true)) {
                $view = 'livestock';
            }

            $routeName = $view === 'animal' ? 'elfirma_animals' : 'elfirma_livestock';
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
        if (!\in_array($view, ['livestock', 'animal'], true)) {
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
            ]);
        }

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
}
