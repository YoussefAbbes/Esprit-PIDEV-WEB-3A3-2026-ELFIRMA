<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Categorie;
use App\Entity\Produit;
use App\Repository\AnimalRepository;
use App\Repository\LivestockRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

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
            $categories = $em->getRepository(Categorie::class)->findBy([], ['id' => 'DESC']);

            return $this->render('elfirma/categories.html.twig', [
                'module_meta' => $moduleMeta,
                'current_module' => $module,
                'modules' => self::MODULES,
                'categories' => $categories,
            ]);
        }

        if ($module === 'produits') {
            $produits = $em->getRepository(Produit::class)->findBy([], ['id_produit' => 'DESC']);
            $categories = $em->getRepository(Categorie::class)->findBy([], ['nom' => 'ASC']);

            return $this->render('elfirma/produits.html.twig', [
                'module_meta' => $moduleMeta,
                'current_module' => $module,
                'modules' => self::MODULES,
                'produits' => $produits,
                'categories' => $categories,
            ]);
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

    #[Route('/elfirma/categorie/create', name: 'categorie_create', methods: ['POST'])]
    public function createCategorie(Request $request, EntityManagerInterface $em): Response
    {
        $nom = trim((string) $request->request->get('nom', ''));
        $errors = [];

        if ($nom === '') {
            $errors['nom'][] = 'Category name is required.';
        } elseif (mb_strlen($nom) < 2) {
            $errors['nom'][] = 'Category name must be at least 2 characters.';
        }

        if ($errors !== []) {
            $this->addFlash('form_errors_categorie_create', $errors);
            $this->addFlash('form_old_categorie_create', ['nom' => $nom]);

            return $this->redirectToRoute('elfirma_page', ['module' => 'categories']);
        }

        try {
            $categorie = new Categorie();
            $categorie->setNom($nom);
            $em->persist($categorie);
            $em->flush();
            $this->addFlash('success', 'Category created successfully.');
        } catch (\Throwable $e) {
            $this->addFlash('form_errors_categorie_create', ['_global' => ['Unable to create category right now.']]);
            $this->addFlash('form_old_categorie_create', ['nom' => $nom]);
        }

        return $this->redirectToRoute('elfirma_page', ['module' => 'categories']);
    }

    #[Route('/elfirma/categorie/edit/{id}', name: 'categorie_edit', methods: ['POST'])]
    public function editCategorie(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $categorie = $em->getRepository(Categorie::class)->find($id);
        if (!$categorie) {
            $this->addFlash('error', 'Category not found.');

            return $this->redirectToRoute('elfirma_page', ['module' => 'categories']);
        }

        $nom = trim((string) $request->request->get('nom', ''));
        $errors = [];

        if ($nom === '') {
            $errors['nom'][] = 'Category name is required.';
        } elseif (mb_strlen($nom) < 2) {
            $errors['nom'][] = 'Category name must be at least 2 characters.';
        }

        if ($errors !== []) {
            $this->addFlash('form_errors_categorie_edit', $errors);
            $this->addFlash('form_old_categorie_edit', ['id' => $id, 'nom' => $nom]);

            return $this->redirectToRoute('elfirma_page', ['module' => 'categories']);
        }

        try {
            $categorie->setNom($nom);
            $em->flush();
            $this->addFlash('success', 'Category updated successfully.');
        } catch (\Throwable $e) {
            $this->addFlash('form_errors_categorie_edit', ['_global' => ['Unable to update category right now.']]);
            $this->addFlash('form_old_categorie_edit', ['id' => $id, 'nom' => $nom]);
        }

        return $this->redirectToRoute('elfirma_page', ['module' => 'categories']);
    }

    #[Route('/elfirma/categorie/delete/{id}', name: 'categorie_delete', methods: ['POST'])]
    public function deleteCategorie(int $id, EntityManagerInterface $em): Response
    {
        $categorie = $em->getRepository(Categorie::class)->find($id);
        if (!$categorie) {
            $this->addFlash('error', 'Category not found.');

            return $this->redirectToRoute('elfirma_page', ['module' => 'categories']);
        }

        if ($categorie->getProduits()->count() > 0) {
            $this->addFlash('error', 'Cannot delete a category that still contains products.');

            return $this->redirectToRoute('elfirma_page', ['module' => 'categories']);
        }

        try {
            $em->remove($categorie);
            $em->flush();
            $this->addFlash('success', 'Category deleted successfully.');
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Unable to delete category right now.');
        }

        return $this->redirectToRoute('elfirma_page', ['module' => 'categories']);
    }

    #[Route('/elfirma/produit/create', name: 'produit_create', methods: ['POST'])]
    public function createProduit(Request $request, EntityManagerInterface $em, SluggerInterface $slugger): Response
    {
        $formData = [
            'nom' => trim((string) $request->request->get('nom', '')),
            'type' => trim((string) $request->request->get('type', '')),
            'prix_unitaire' => trim((string) $request->request->get('prix_unitaire', '')),
            'quantite_stock' => trim((string) $request->request->get('quantite_stock', '')),
            'qualite' => trim((string) $request->request->get('qualite', '')),
            'statut' => trim((string) $request->request->get('statut', 'Disponible')),
            'date_production' => trim((string) $request->request->get('date_production', '')),
            'date_expiration' => trim((string) $request->request->get('date_expiration', '')),
            'categorie_id' => trim((string) $request->request->get('categorie_id', '')),
        ];

        $errors = [];

        if ($formData['nom'] === '') {
            $errors['nom'][] = 'Product name is required.';
        } elseif (mb_strlen($formData['nom']) > 100) {
            $errors['nom'][] = 'Product name cannot exceed 100 characters.';
        }
        if ($formData['type'] === '') {
            $errors['type'][] = 'Type is required.';
        } elseif (mb_strlen($formData['type']) > 30) {
            $errors['type'][] = 'Type cannot exceed 30 characters.';
        }
        if ($formData['prix_unitaire'] === '' || !is_numeric($formData['prix_unitaire']) || (float) $formData['prix_unitaire'] <= 0) {
            $errors['prix_unitaire'][] = 'Price must be a number greater than 0.';
        }
        if ($formData['quantite_stock'] === '' || filter_var($formData['quantite_stock'], FILTER_VALIDATE_INT) === false || (int) $formData['quantite_stock'] < 0) {
            $errors['quantite_stock'][] = 'Stock quantity must be an integer greater than or equal to 0.';
        }
        if ($formData['qualite'] !== '' && mb_strlen($formData['qualite']) > 20) {
            $errors['qualite'][] = 'Quality cannot exceed 20 characters.';
        }
        if ($formData['statut'] !== '' && mb_strlen($formData['statut']) > 20) {
            $errors['statut'][] = 'Status cannot exceed 20 characters.';
        }

        $categorie = null;
        if ($formData['categorie_id'] === '' || filter_var($formData['categorie_id'], FILTER_VALIDATE_INT) === false) {
            $errors['categorie'][] = 'Category is required.';
        } else {
            $categorie = $em->getRepository(Categorie::class)->find((int) $formData['categorie_id']);
            if (!$categorie) {
                $errors['categorie'][] = 'Selected category does not exist.';
            }
        }

        $dateProduction = $this->parseDateValue($formData['date_production'], 'date_production', $errors);
        $dateExpiration = $this->parseDateValue($formData['date_expiration'], 'date_expiration', $errors);
        if ($dateProduction !== null && $dateExpiration !== null && $dateExpiration < $dateProduction) {
            $errors['date_expiration'][] = 'Expiration date must be on or after production date.';
        }

        $imageFilename = null;
        $imageFile = $request->files->get('image');
        if ($imageFile instanceof UploadedFile) {
            $imageFilename = $this->buildUploadFilename($imageFile, $slugger);
            try {
                $imageFile->move($this->getParameter('kernel.project_dir') . '/public/uploads/produits', $imageFilename);
            } catch (\Throwable $e) {
                $errors['image'][] = 'Unable to upload product image.';
            }
        }

        if ($errors !== []) {
            $this->addFlash('form_errors_produit_create', $errors);
            $this->addFlash('form_old_produit_create', $formData);

            return $this->redirectToRoute('elfirma_page', ['module' => 'produits']);
        }

        try {
            $produit = new Produit();
            $produit->setNom($formData['nom']);
            $produit->setType($formData['type']);
            $produit->setPrixUnitaire((string) number_format((float) $formData['prix_unitaire'], 2, '.', ''));
            $produit->setQuantiteStock((int) $formData['quantite_stock']);
            $produit->setQualite($formData['qualite'] !== '' ? $formData['qualite'] : null);
            $produit->setStatut($formData['statut'] !== '' ? $formData['statut'] : 'Disponible');
            $produit->setDateProduction($dateProduction);
            $produit->setDateExpiration($dateExpiration);
            $produit->setCategorie($categorie);
            if ($imageFilename !== null) {
                $produit->setImage($imageFilename);
            }

            $em->persist($produit);
            $em->flush();
            $this->addFlash('success', 'Product created successfully.');
        } catch (\Throwable $e) {
            $message = 'Unable to create product right now.';
            if ((bool) $this->getParameter('kernel.debug')) {
                $message .= ' ' . $e->getMessage();
            }
            $this->addFlash('form_errors_produit_create', ['_global' => [$message]]);
            $this->addFlash('form_old_produit_create', $formData);
        }

        return $this->redirectToRoute('elfirma_page', ['module' => 'produits']);
    }

    #[Route('/elfirma/produit/edit/{id}', name: 'produit_edit', methods: ['POST'])]
    public function editProduit(int $id, Request $request, EntityManagerInterface $em, SluggerInterface $slugger): Response
    {
        $produit = $em->getRepository(Produit::class)->find($id);
        if (!$produit) {
            $this->addFlash('error', 'Product not found.');

            return $this->redirectToRoute('elfirma_page', ['module' => 'produits']);
        }

        $formData = [
            'id' => $id,
            'nom' => trim((string) $request->request->get('nom', '')),
            'type' => trim((string) $request->request->get('type', '')),
            'prix_unitaire' => trim((string) $request->request->get('prix_unitaire', '')),
            'quantite_stock' => trim((string) $request->request->get('quantite_stock', '')),
            'qualite' => trim((string) $request->request->get('qualite', '')),
            'statut' => trim((string) $request->request->get('statut', 'Disponible')),
            'date_production' => trim((string) $request->request->get('date_production', '')),
            'date_expiration' => trim((string) $request->request->get('date_expiration', '')),
            'categorie_id' => trim((string) $request->request->get('categorie_id', '')),
            'image' => $produit->getImage() ?? '',
        ];

        $errors = [];

        if ($formData['nom'] === '') {
            $errors['nom'][] = 'Product name is required.';
        } elseif (mb_strlen($formData['nom']) > 100) {
            $errors['nom'][] = 'Product name cannot exceed 100 characters.';
        }
        if ($formData['type'] === '') {
            $errors['type'][] = 'Type is required.';
        } elseif (mb_strlen($formData['type']) > 30) {
            $errors['type'][] = 'Type cannot exceed 30 characters.';
        }
        if ($formData['prix_unitaire'] === '' || !is_numeric($formData['prix_unitaire']) || (float) $formData['prix_unitaire'] <= 0) {
            $errors['prix_unitaire'][] = 'Price must be a number greater than 0.';
        }
        if ($formData['quantite_stock'] === '' || filter_var($formData['quantite_stock'], FILTER_VALIDATE_INT) === false || (int) $formData['quantite_stock'] < 0) {
            $errors['quantite_stock'][] = 'Stock quantity must be an integer greater than or equal to 0.';
        }
        if ($formData['qualite'] !== '' && mb_strlen($formData['qualite']) > 20) {
            $errors['qualite'][] = 'Quality cannot exceed 20 characters.';
        }
        if ($formData['statut'] !== '' && mb_strlen($formData['statut']) > 20) {
            $errors['statut'][] = 'Status cannot exceed 20 characters.';
        }

        $categorie = null;
        if ($formData['categorie_id'] === '' || filter_var($formData['categorie_id'], FILTER_VALIDATE_INT) === false) {
            $errors['categorie'][] = 'Category is required.';
        } else {
            $categorie = $em->getRepository(Categorie::class)->find((int) $formData['categorie_id']);
            if (!$categorie) {
                $errors['categorie'][] = 'Selected category does not exist.';
            }
        }

        $dateProduction = $this->parseDateValue($formData['date_production'], 'date_production', $errors);
        $dateExpiration = $this->parseDateValue($formData['date_expiration'], 'date_expiration', $errors);
        if ($dateProduction !== null && $dateExpiration !== null && $dateExpiration < $dateProduction) {
            $errors['date_expiration'][] = 'Expiration date must be on or after production date.';
        }

        $imageFile = $request->files->get('image');
        if ($imageFile instanceof UploadedFile) {
            $imageFilename = $this->buildUploadFilename($imageFile, $slugger);
            try {
                $imageFile->move($this->getParameter('kernel.project_dir') . '/public/uploads/produits', $imageFilename);
                $formData['image'] = $imageFilename;
            } catch (\Throwable $e) {
                $errors['image'][] = 'Unable to upload product image.';
            }
        }

        if ($errors !== []) {
            $this->addFlash('form_errors_produit_edit', $errors);
            $this->addFlash('form_old_produit_edit', $formData);

            return $this->redirectToRoute('elfirma_page', ['module' => 'produits']);
        }

        try {
            $produit->setNom($formData['nom']);
            $produit->setType($formData['type']);
            $produit->setPrixUnitaire((string) number_format((float) $formData['prix_unitaire'], 2, '.', ''));
            $produit->setQuantiteStock((int) $formData['quantite_stock']);
            $produit->setQualite($formData['qualite'] !== '' ? $formData['qualite'] : null);
            $produit->setStatut($formData['statut'] !== '' ? $formData['statut'] : 'Disponible');
            $produit->setDateProduction($dateProduction);
            $produit->setDateExpiration($dateExpiration);
            $produit->setCategorie($categorie);
            if ($formData['image'] !== '') {
                $produit->setImage($formData['image']);
            }

            $em->flush();
            $this->addFlash('success', 'Product updated successfully.');
        } catch (\Throwable $e) {
            $message = 'Unable to update product right now.';
            if ((bool) $this->getParameter('kernel.debug')) {
                $message .= ' ' . $e->getMessage();
            }
            $this->addFlash('form_errors_produit_edit', ['_global' => [$message]]);
            $this->addFlash('form_old_produit_edit', $formData);
        }

        return $this->redirectToRoute('elfirma_page', ['module' => 'produits']);
    }

    #[Route('/elfirma/produit/delete/{id}', name: 'produit_delete', methods: ['POST'])]
    public function deleteProduit(int $id, EntityManagerInterface $em): Response
    {
        $produit = $em->getRepository(Produit::class)->find($id);
        if (!$produit) {
            $this->addFlash('error', 'Product not found.');

            return $this->redirectToRoute('elfirma_page', ['module' => 'produits']);
        }

        try {
            $em->remove($produit);
            $em->flush();
            $this->addFlash('success', 'Product deleted successfully.');
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Unable to delete product right now.');
        }

        return $this->redirectToRoute('elfirma_page', ['module' => 'produits']);
    }

    #[Route('/elfirma/produit/api/list', name: 'elfirma_produit_api_list', methods: ['GET'])]
    public function listProduitsApi(EntityManagerInterface $em): JsonResponse
    {
        $produits = $em->getRepository(Produit::class)->findBy([], ['id_produit' => 'DESC']);

        $data = [];
        foreach ($produits as $produit) {
            $data[] = [
                'id' => $produit->getIdProduit(),
                'nom' => $produit->getNom(),
                'categorie' => $produit->getCategorie() ? $produit->getCategorie()->getNom() : null,
                'type' => $produit->getType(),
                'qualite' => $produit->getQualite(),
                'prix' => (float) ($produit->getPrixUnitaire() ?? 0),
                'stock' => $produit->getQuantiteStock(),
                'dateProduction' => $produit->getDateProduction()?->format('d/m/Y'),
                'dateExpiration' => $produit->getDateExpiration()?->format('d/m/Y'),
                'statut' => $produit->getStatut(),
            ];
        }

        return new JsonResponse($data);
    }

    private function parseDateValue(string $value, string $field, array &$errors): ?\DateTimeInterface
    {
        if ($value === '') {
            return null;
        }

        $date = \DateTime::createFromFormat('Y-m-d', $value);
        if ($date === false) {
            $errors[$field][] = 'Invalid date value.';
            return null;
        }

        return $date;
    }

    private function buildUploadFilename(UploadedFile $file, SluggerInterface $slugger): string
    {
        $base = (string) $slugger->slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        if ($base === '') {
            $base = 'product';
        }

        $base = substr($base, 0, 40);
        $suffix = substr(md5(uniqid((string) mt_rand(), true)), 0, 12);
        $extension = $this->resolveUploadExtension($file);

        return sprintf('%s-%s.%s', $base, $suffix, $extension);
    }

    private function resolveUploadExtension(UploadedFile $file): string
    {
        $extension = trim((string) $file->getClientOriginalExtension());
        if ($extension === '') {
            $extension = trim((string) pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        }

        $normalized = strtolower((string) preg_replace('/[^a-z0-9]+/', '', $extension));

        return $normalized !== '' ? $normalized : 'bin';
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
