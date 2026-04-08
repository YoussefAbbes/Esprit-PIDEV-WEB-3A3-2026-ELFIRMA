<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AnimalRepository;
use App\Repository\LivestockRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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
            'title' => 'Livestock',
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

    #[Route('/elfirma/{module}', name: 'elfirma_page', methods: ['GET'])]
    public function page(
        string $module,
        Request $request,
        LivestockRepository $livestockRepository,
        AnimalRepository $animalRepository
    ): Response
    {
        if (!isset(self::MODULES[$module])) {
            throw $this->createNotFoundException(sprintf('Module "%s" was not found.', $module));
        }

        $moduleMeta = self::MODULES[$module];

        if ($module === 'animaux-elevages') {
            $view = $request->query->getString('view', 'livestock');
            if (!\in_array($view, ['livestock', 'animal'], true)) {
                $view = 'livestock';
            }

            if ($view === 'livestock') {
                $editId = $request->query->getInt('edit', 0);
                $editLivestock = null;
                if ($editId > 0) {
                    $editLivestock = $livestockRepository->findForEdit($editId);
                }

                $showAddForm = \in_array(strtolower($request->query->getString('add', '0')), ['1', 'true', 'yes'], true);

                $livestockStates = $livestockRepository->findDistinctStates();
                $elevages = $livestockRepository->findAllForManagement();
                $livestockStats = $livestockRepository->fetchStats();

                return $this->render('elfirma/Livestock&Animal Management/livestock.twig', [
                    'elevages' => $elevages,
                    'livestock_stats' => $livestockStats,
                    'livestock_states' => $livestockStates,
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
            $animalStats = $animalRepository->fetchStats();

            return $this->render('elfirma/Livestock&Animal Management/animal.twig', [
                'animals' => $animals,
                'livestock_options' => $livestockOptions,
                'animal_stats' => $animalStats,
                'animal_statuses' => $animalStatuses,
                'animal_health_options' => $animalHealthOptions,
                'show_add_animal_form' => $showAddAnimalForm,
                'edit_animal' => $editAnimal,
            ]);
        }

        return $this->render(sprintf('elfirma/%s.html.twig', $moduleMeta['folder']), [
            'module_meta' => $moduleMeta,
            'current_module' => $module,
            'modules' => self::MODULES,
        ]);
    }
}
