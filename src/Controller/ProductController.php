<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Entity\Produit;
use App\Entity\Commande;
use App\Entity\Categorie;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ProductController extends AbstractController
{
    private const OPENWEATHER_API_BASE = 'https://api.openweathermap.org/data/2.5/weather';

    private const TUNISIA_REGIONS = [
        'Tunis' => 'Tunis,TN',
        'Ariana' => 'Ariana,TN',
        'Ben Arous' => 'Ben Arous,TN',
        'Manouba' => 'Manouba,TN',
        'Nabeul' => 'Nabeul,TN',
        'Sousse' => 'Sousse,TN',
        'Monastir' => 'Monastir,TN',
        'Mahdia' => 'Mahdia,TN',
        'Sfax' => 'Sfax,TN',
        'Kairouan' => 'Kairouan,TN',
        'Bizerte' => 'Bizerte,TN',
        'Beja' => 'Beja,TN',
        'Jendouba' => 'Jendouba,TN',
        'Le Kef' => 'Kef,TN',
        'Siliana' => 'Siliana,TN',
        'Zaghouan' => 'Zaghouan,TN',
        'Kasserine' => 'Kasserine,TN',
        'Sidi Bouzid' => 'Sidi Bouzid,TN',
        'Gabes' => 'Gabes,TN',
        'Medenine' => 'Medenine,TN',
        'Tataouine' => 'Tataouine,TN',
        'Gafsa' => 'Gafsa,TN',
        'Tozeur' => 'Tozeur,TN',
        'Kebili' => 'Kebili,TN',
    ];

    #[Route('/elfirma/produits', name: 'elfirma_products', methods: ['GET'])]
    public function adminIndex(Request $request, EntityManagerInterface $em, HttpClientInterface $httpClient): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $categoryFilter = trim((string) $request->query->get('category', ''));
        $statusFilter = trim((string) $request->query->get('status', ''));
        $sort = (string) $request->query->get('sort', 'id-desc');
        $selectedRegion = trim((string) $request->query->get('meteo_region', 'Tunis'));

        if (!array_key_exists($selectedRegion, self::TUNISIA_REGIONS)) {
            $selectedRegion = 'Tunis';
        }

        $allProduits = $em->getRepository(Produit::class)->findAll();
        $produits = $this->filterAndSortProducts($allProduits, $q, $categoryFilter, $statusFilter, $sort);
        $categories = $em->getRepository(Categorie::class)->findBy([], ['nom' => 'ASC']);

        $stats = $this->buildProductStats($produits);
        $chartData = $this->buildProductChartData($produits);
        $weatherOverview = $this->fetchTunisiaWeatherOverview($selectedRegion, $httpClient);
        $stockAlertData = $this->buildStockAlertData($allProduits);
        $stockAlertStats = $this->buildStockAlertStats($allProduits, $stockAlertData);

        return $this->render('elfirma/produits.html.twig', [
            'produits' => $produits,
            'categories' => $categories,
            'product_stats' => $stats,
            'product_chart_data' => $chartData,
            'stock_alert_data' => $stockAlertData,
            'stock_alert_stats' => $stockAlertStats,
            'weather_overview' => $weatherOverview,
            'weather_regions' => array_keys(self::TUNISIA_REGIONS),
            'weather_selected_region' => $selectedRegion,
            'filters' => [
                'q' => $q,
                'category' => $categoryFilter,
                'status' => $statusFilter,
                'sort' => $sort,
            ],
        ]);
    }

    #[Route('/elfirma/produits/export/pdf', name: 'elfirma_products_export_pdf', methods: ['GET'])]
    public function exportProductsPdf(Request $request, EntityManagerInterface $em): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $categoryFilter = trim((string) $request->query->get('category', ''));
        $statusFilter = trim((string) $request->query->get('status', ''));
        $sort = (string) $request->query->get('sort', 'id-desc');

        $produits = $em->getRepository(Produit::class)->findAll();
        $produits = $this->filterAndSortProducts($produits, $q, $categoryFilter, $statusFilter, $sort);
        $stats = $this->buildProductStats($produits);

        return $this->render('elfirma/products_report_print.html.twig', [
            'produits' => $produits,
            'stats' => $stats,
            'generated_at' => (new \DateTimeImmutable())->format('d/m/Y H:i:s'),
            'filters' => [
                'q' => $q,
                'category' => $categoryFilter,
                'status' => $statusFilter,
                'sort' => $sort,
            ],
        ]);
    }

    #[Route('/elfirma/produit/create', name: 'produit_create', methods: ['POST'])]
    public function createProduit(Request $request, EntityManagerInterface $em, SluggerInterface $slugger, ValidatorInterface $validator): Response
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

        $categorieId = filter_var($formData['categorie_id'], FILTER_VALIDATE_INT);
        $categorie = $categorieId !== false
            ? $em->getRepository(Categorie::class)->find((int) $categorieId)
            : null;

        $dateProduction = $this->parseDateValue($formData['date_production']);
        $dateExpiration = $this->parseDateValue($formData['date_expiration']);

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

        $produit = new Produit();
        $produit->setNom($formData['nom']);
        $produit->setType($formData['type']);
        $produit->setPrixUnitaire(trim((string) $formData['prix_unitaire']));
        $produit->setQuantiteStock(filter_var($formData['quantite_stock'], FILTER_VALIDATE_INT) !== false ? (int) $formData['quantite_stock'] : null);
        $produit->setQualite($formData['qualite'] !== '' ? $formData['qualite'] : null);
        $produit->setStatut($formData['statut'] !== '' ? $formData['statut'] : null);
        $produit->setDateProduction($dateProduction);
        $produit->setDateExpiration($dateExpiration);
        $produit->setCategorie($categorie);
        $produit->setImage($imageFilename);

        $this->appendEntityValidationErrors($errors, $validator->validate($produit));

        if ($errors !== []) {
            $this->addFlash('form_errors_produit_create', $errors);
            $this->addFlash('form_old_produit_create', $formData);

            return $this->redirectToRoute('elfirma_products');
        }

        try {
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

        return $this->redirectToRoute('elfirma_products');
    }

    #[Route('/elfirma/produit/{id}/restock', name: 'produit_restock', methods: ['POST'])]
    public function restockProduit(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $produit = $em->getRepository(Produit::class)->find($id);
        if (!$produit) {
            $this->addFlash('error', 'Product not found for restock.');

            return $this->redirectToRoute('elfirma_products');
        }

        $qtyRaw = trim((string) $request->request->get('quantity_add', '0'));
        $quantityToAdd = filter_var($qtyRaw, FILTER_VALIDATE_INT);

        if ($quantityToAdd === false || $quantityToAdd <= 0) {
            $this->addFlash('error', 'Restock quantity must be a positive integer.');

            return $this->redirectToRoute('elfirma_products', [
                'q' => trim((string) $request->request->get('q', '')),
                'category' => trim((string) $request->request->get('category', '')),
                'status' => trim((string) $request->request->get('status', '')),
                'sort' => trim((string) $request->request->get('sort', 'id-desc')),
                'meteo_region' => trim((string) $request->request->get('meteo_region', 'Tunis')),
            ]);
        }

        $newStock = (int) ($produit->getQuantiteStock() ?? 0) + (int) $quantityToAdd;
        $produit->setQuantiteStock($newStock);

        if ($this->isProductExpired($produit)) {
            $produit->setStatut('Expiré');
        } else {
            $produit->setStatut($newStock <= 0 ? 'Rupture' : 'Disponible');
        }

        try {
            $em->flush();
            $this->addFlash('success', sprintf('Stock updated for %s (+%d).', (string) $produit->getNom(), (int) $quantityToAdd));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Unable to update stock right now.');
        }

        return $this->redirectToRoute('elfirma_products', [
            'q' => trim((string) $request->request->get('q', '')),
            'category' => trim((string) $request->request->get('category', '')),
            'status' => trim((string) $request->request->get('status', '')),
            'sort' => trim((string) $request->request->get('sort', 'id-desc')),
            'meteo_region' => trim((string) $request->request->get('meteo_region', 'Tunis')),
        ]);
    }

    #[Route('/elfirma/produit/edit/{id}', name: 'produit_edit', methods: ['POST'])]
    public function editProduit(int $id, Request $request, EntityManagerInterface $em, SluggerInterface $slugger, ValidatorInterface $validator): Response
    {
        $produit = $em->getRepository(Produit::class)->find($id);
        if (!$produit) {
            $this->addFlash('error', 'Product not found.');

            return $this->redirectToRoute('elfirma_products');
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

        $categorieId = filter_var($formData['categorie_id'], FILTER_VALIDATE_INT);
        $categorie = $categorieId !== false
            ? $em->getRepository(Categorie::class)->find((int) $categorieId)
            : null;

        $dateProduction = $this->parseDateValue($formData['date_production']);
        $dateExpiration = $this->parseDateValue($formData['date_expiration']);

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

        $produit->setNom($formData['nom']);
        $produit->setType($formData['type']);
        $produit->setPrixUnitaire(trim((string) $formData['prix_unitaire']));
        $produit->setQuantiteStock(filter_var($formData['quantite_stock'], FILTER_VALIDATE_INT) !== false ? (int) $formData['quantite_stock'] : null);
        $produit->setQualite($formData['qualite'] !== '' ? $formData['qualite'] : null);
        $produit->setStatut($formData['statut'] !== '' ? $formData['statut'] : null);
        $produit->setDateProduction($dateProduction);
        $produit->setDateExpiration($dateExpiration);
        $produit->setCategorie($categorie);
        $produit->setImage($formData['image'] !== '' ? $formData['image'] : null);

        $this->appendEntityValidationErrors($errors, $validator->validate($produit));

        if ($errors !== []) {
            $this->addFlash('form_errors_produit_edit', $errors);
            $this->addFlash('form_old_produit_edit', $formData);

            return $this->redirectToRoute('elfirma_products');
        }

        try {
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

        return $this->redirectToRoute('elfirma_products');
    }

    #[Route('/elfirma/produit/delete/{id}', name: 'produit_delete', methods: ['POST'])]
    public function deleteProduit(int $id, EntityManagerInterface $em): Response
    {
        $produit = $em->getRepository(Produit::class)->find($id);
        if (!$produit) {
            $this->addFlash('error', 'Product not found.');

            return $this->redirectToRoute('elfirma_products');
        }

        try {
            $em->remove($produit);
            $em->flush();
            $this->addFlash('success', 'Product deleted successfully.');
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Unable to delete product right now.');
        }

        return $this->redirectToRoute('elfirma_products');
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

    #[Route('/produit/{id}', name: 'app_product_details', methods: ['GET'])]
    public function details(int $id, EntityManagerInterface $em): Response
    {
        $produit = $em->getRepository(Produit::class)->find($id);

        if (!$produit) {
            throw $this->createNotFoundException('Product not found');
        }

        return $this->render('product/details.html.twig', [
            'produit' => $produit
        ]);
    }

    #[Route('/api/produit/{id}', name: 'app_api_product_details', methods: ['GET'])]
    public function apiDetails(int $id, EntityManagerInterface $em): JsonResponse
    {
        $produit = $em->getRepository(Produit::class)->find($id);

        if (!$produit) {
            return new JsonResponse(['error' => 'Product not found'], 404);
        }

        $data = [
            'id' => $produit->getIdProduit(),
            'nom' => $produit->getNom(),
            'type' => $produit->getType(),
            'prix' => $produit->getPrixUnitaire(),
            'stock' => $produit->getQuantiteStock(),
            'qualite' => $produit->getQualite(),
            'statut' => $produit->getStatut(),
            'categorie' => $produit->getCategorie() ? $produit->getCategorie()->getNom() : null,
            'image' => $produit->getImage(),
            'dateProduction' => $produit->getDateProduction() ? $produit->getDateProduction()->format('d/m/Y') : null,
            'dateExpiration' => $produit->getDateExpiration() ? $produit->getDateExpiration()->format('d/m/Y') : null
        ];

        return new JsonResponse($data);
    }

    #[Route('/commande/create', name: 'app_order_create', methods: ['POST'])]
// COMMANDE PAS TERMINÉE
    public function createOrder(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        // Validation des données
        if (!isset($data['produit_id'], $data['quantite'], $data['nom_client'])) {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        $produit = $em->getRepository(Produit::class)->find($data['produit_id']);
        
        if (!$produit) {
            return new JsonResponse(['error' => 'Product not found'], 404);
        }

        if ($produit->getStatut() !== 'Disponible') {
            return new JsonResponse(['error' => 'Product not available'], 400);
        }

        if ($data['quantite'] > $produit->getQuantiteStock()) {
            return new JsonResponse(['error' => 'Not enough stock'], 400);
        }

        // Créer la commande
        $commande = new Commande();
        $commande->setProduit($produit);
        $commande->setQuantite($data['quantite']);
        $commande->setPrixTotal($data['quantite'] * $produit->getPrixUnitaire());
        $commande->setNomClient($data['nom_client']);
        $commande->setStatutCommande('En attente');
        $commande->setStatutPaiement('Non payé');
        $commande->setModePaiement($data['mode_paiement'] ?? 'Cash');
        $commande->setDateCommande(new \DateTime());

        // Mettre à jour le stock
        $nouveauStock = $produit->getQuantiteStock() - $data['quantite'];
        $produit->setQuantiteStock($nouveauStock);

        // Si le stock est épuisé, changer le statut
        if ($nouveauStock <= 0) {
            $produit->setStatut('Rupture');
        }

        $em->persist($commande);
        $em->persist($produit);
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Order created successfully',
            'order_id' => $commande->getIdCommande(),
            'total' => $commande->getPrixTotal()
        ]);
    }

    #[Route('/catalogue', name: 'app_product_catalog', methods: ['GET'])]
    public function catalog(EntityManagerInterface $em): Response
    {
        $produits = $em->getRepository(Produit::class)->findBy(
            ['statut' => 'Disponible'],
            ['nom' => 'ASC']
        );

        $categories = $em->getRepository(\App\Entity\Categorie::class)->findAll();

        return $this->render('product/catalog.html.twig', [
            'produits' => $produits,
            'categories' => $categories
        ]);
    }

    #[Route('/api/catalogue', name: 'app_api_product_catalog', methods: ['GET'])]
    public function apiCatalog(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $categoryId = $request->query->get('category');
        
        $criteria = ['statut' => 'Disponible'];
        if ($categoryId) {
            $criteria['categorie'] = $categoryId;
        }

        $produits = $em->getRepository(Produit::class)->findBy(
            $criteria,
            ['nom' => 'ASC']
        );

        $data = [];
        foreach ($produits as $produit) {
            $data[] = [
                'id' => $produit->getIdProduit(),
                'nom' => $produit->getNom(),
                'type' => $produit->getType(),
                'prix' => $produit->getPrixUnitaire(),
                'stock' => $produit->getQuantiteStock(),
                'qualite' => $produit->getQualite(),
                'categorie' => $produit->getCategorie() ? $produit->getCategorie()->getNom() : null,
                'image' => $produit->getImage()
            ];
        }

        return new JsonResponse($data);
    }
//C’est une fonction utilitaire de conversion,pas la validation métier finale (la validation est surtout dans l’entité).
    private function parseDateValue(string $value): ?\DateTimeInterface
    {
        if ($value === '') {
            return null;
        }

        $date = \DateTime::createFromFormat('Y-m-d', $value);
        if ($date === false) {
            return null;
        }

        return $date;
    }

    private function appendEntityValidationErrors(array &$errors, iterable $violations): void
    {
        foreach ($violations as $violation) {
            $path = (string) $violation->getPropertyPath();
            $field = match ($path) {
                'date_production', 'dateProduction' => 'date_production',
                'date_expiration', 'dateExpiration' => 'date_expiration',
                default => $path !== '' ? $path : '_global',
            };

            $message = (string) $violation->getMessage();
            if (!isset($errors[$field]) || !in_array($message, $errors[$field], true)) {
                $errors[$field][] = $message;
            }
        }
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

    /**
     * @param list<Produit> $products
     *
     * @return list<Produit>
     */
    private function filterAndSortProducts(array $products, string $q, string $category, string $status, string $sort): array
    {
        $normalizedQ = $this->normalizeFilterText($q);
        $normalizedCategory = $this->normalizeFilterText($category);
        $normalizedStatus = $this->normalizeStatus($status);

        $products = array_values(array_filter(
            $products,
            function (Produit $product) use ($normalizedQ, $normalizedCategory, $normalizedStatus): bool {
                $name = $this->normalizeFilterText((string) $product->getNom());
                $productCategory = $this->normalizeFilterText((string) ($product->getCategorie()?->getNom() ?? ''));
                $productType = $this->normalizeFilterText((string) ($product->getType() ?? ''));
                $productStatus = $this->normalizeStatus((string) ($product->getStatut() ?? ''));

                $matchesSearch = $normalizedQ === ''
                    || str_contains($name, $normalizedQ)
                    || str_contains($productCategory, $normalizedQ)
                    || str_contains($productType, $normalizedQ);
                $matchesCategory = $normalizedCategory === '' || $productCategory === $normalizedCategory;
                $matchesStatus = $normalizedStatus === '' || $productStatus === $normalizedStatus;

                return $matchesSearch && $matchesCategory && $matchesStatus;
            }
        ));

        usort($products, static function (Produit $a, Produit $b) use ($sort): int {
            $aName = mb_strtolower((string) $a->getNom());
            $bName = mb_strtolower((string) $b->getNom());
            $aStock = (int) ($a->getQuantiteStock() ?? 0);
            $bStock = (int) ($b->getQuantiteStock() ?? 0);
            $aPrice = (float) ($a->getPrixUnitaire() ?? 0);
            $bPrice = (float) ($b->getPrixUnitaire() ?? 0);
            $aId = (int) ($a->getIdProduit() ?? 0);
            $bId = (int) ($b->getIdProduit() ?? 0);

            return match ($sort) {
                'name-asc' => $aName <=> $bName,
                'name-desc' => $bName <=> $aName,
                'stock-asc' => $aStock <=> $bStock,
                'stock-desc' => $bStock <=> $aStock,
                'price-asc' => $aPrice <=> $bPrice,
                'price-desc' => $bPrice <=> $aPrice,
                'id-asc' => $aId <=> $bId,
                default => $bId <=> $aId,
            };
        });

        return $products;
    }

    private function normalizeFilterText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = mb_strtolower($value);
        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($converted !== false) {
                $value = $converted;
            }
        }

        $value = preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? '';

        return trim($value);
    }

    private function normalizeStatus(string $status): string
    {
        $normalized = $this->normalizeFilterText($status);

        if ($normalized === '') {
            return '';
        }

        return match (true) {
            str_contains($normalized, 'disponible'), str_contains($normalized, 'available') => 'disponible',
            str_contains($normalized, 'rupture'), str_contains($normalized, 'out of stock') => 'rupture',
            str_contains($normalized, 'expire'), str_contains($normalized, 'expired') => 'expire',
            default => $normalized,
        };
    }

    /**
     * @param list<Produit> $products
     *
     * @return array{total_products:int,total_stock:int,total_value:float,average_value:float,availability_rate:float,top_category:string,top_category_count:int}
     */
    private function buildProductStats(array $products): array
    {
        $totalProducts = count($products);
        $totalStock = 0;
        $totalValue = 0.0;
        $availableCount = 0;
        $categoryCounts = [];

        foreach ($products as $product) {
            $stock = (int) ($product->getQuantiteStock() ?? 0);
            $price = (float) ($product->getPrixUnitaire() ?? 0);
            $status = mb_strtolower((string) $product->getStatut());
            $category = $product->getCategorie()?->getNom() ?? 'Uncategorized';

            $totalStock += $stock;
            $totalValue += $stock * $price;
            $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;

            if ($status === 'disponible' || $status === 'available') {
                $availableCount++;
            }
        }

        arsort($categoryCounts);
        $topCategory = array_key_first($categoryCounts) ?? '-';
        $topCategoryCount = $topCategory !== '-' ? (int) ($categoryCounts[$topCategory] ?? 0) : 0;

        return [
            'total_products' => $totalProducts,
            'total_stock' => $totalStock,
            'total_value' => round($totalValue, 2),
            'average_value' => $totalProducts > 0 ? round($totalValue / $totalProducts, 2) : 0.0,
            'availability_rate' => $totalProducts > 0 ? round(($availableCount / $totalProducts) * 100, 1) : 0.0,
            'top_category' => $topCategory,
            'top_category_count' => $topCategoryCount,
        ];
    }

    /**
     * @param list<Produit> $products
     *
     * @return array<string,mixed>
     */
    private function buildProductChartData(array $products): array
    {
        $status = ['available' => 0, 'out_of_stock' => 0, 'expired' => 0, 'other' => 0];
        $stockByCategory = [];
        $valueRows = [];

        foreach ($products as $product) {
            $name = $product->getNom() ?? '-';
            $category = $product->getCategorie()?->getNom() ?? 'Uncategorized';
            $stock = (int) ($product->getQuantiteStock() ?? 0);
            $price = (float) ($product->getPrixUnitaire() ?? 0);
            $state = mb_strtolower((string) $product->getStatut());

            $stockByCategory[$category] = ($stockByCategory[$category] ?? 0) + $stock;
            $valueRows[] = ['name' => $name, 'value' => round($stock * $price, 2)];

            if ($state === 'disponible' || $state === 'available') {
                $status['available']++;
            } elseif ($state === 'rupture' || $state === 'out of stock') {
                $status['out_of_stock']++;
            } elseif ($state === 'expiré' || $state === 'expired') {
                $status['expired']++;
            } else {
                $status['other']++;
            }
        }

        usort($valueRows, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);
        $valueRows = array_slice($valueRows, 0, 7);
        arsort($stockByCategory);

        return [
            'top_value_names' => array_map(static fn (array $row): string => (string) $row['name'], $valueRows),
            'top_value_values' => array_map(static fn (array $row): float => (float) $row['value'], $valueRows),
            'status' => $status,
            'category_labels' => array_keys($stockByCategory),
            'category_stock_values' => array_values($stockByCategory),
            'generated_at' => (new \DateTimeImmutable())->format('d M Y, H:i'),
        ];
    }

    /**
     * @param list<string> $lines
     */
    private function buildSimplePdf(array $lines): string
    {
        $pageChunks = array_chunk($lines, 48);
        if ($pageChunks === []) {
            $pageChunks = [['ELFIRMA - Empty report']];
        }

        $catalogId = 1;
        $pagesId = 2;
        $nextId = 3;
        $pageIds = [];
        $contentIds = [];

        foreach ($pageChunks as $_chunk) {
            $pageIds[] = $nextId++;
            $contentIds[] = $nextId++;
        }

        $fontId = $nextId;
        $objects = [];
        $kids = implode(' ', array_map(static fn (int $id): string => $id . ' 0 R', $pageIds));

        $objects[$catalogId] = "<< /Type /Catalog /Pages {$pagesId} 0 R >>";
        $objects[$pagesId] = "<< /Type /Pages /Kids [ {$kids} ] /Count " . count($pageIds) . " >>";

        foreach ($pageChunks as $index => $chunk) {
            $content = "BT\n/F1 10 Tf\n40 800 Td\n";
            foreach ($chunk as $lineIndex => $line) {
                $escaped = $this->escapePdfText($line);
                if ($lineIndex === 0) {
                    $content .= sprintf('(%s) Tj\n', $escaped);
                    continue;
                }
                $content .= sprintf('0 -14 Td (%s) Tj\n', $escaped);
            }
            $content .= "ET";

            $contentId = $contentIds[$index];
            $pageId = $pageIds[$index];

            $objects[$contentId] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
            $objects[$pageId] = "<< /Type /Page /Parent {$pagesId} 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 {$fontId} 0 R >> >> /Contents {$contentId} 0 R >>";
        }

        $objects[$fontId] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $id => $objectBody) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $objectBody . "\nendobj\n";
        }

        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        $maxId = max(array_keys($objects));
        for ($id = 1; $id <= $maxId; $id++) {
            $offset = $offsets[$id] ?? 0;
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root {$catalogId} 0 R >>\n";
        $pdf .= "startxref\n" . $xrefPos . "\n%%EOF";

        return $pdf;
    }

    private function escapePdfText(string $line): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($line)) ?? '';
        if (function_exists('iconv')) {
            $encoded = iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $normalized);
            if ($encoded !== false) {
                $normalized = $encoded;
            }
        }

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $normalized);
    }

    /**
     * @return array{ok:bool,region:string,temp_c:float,humidity:int,description:string,icon:string,recommendation_title:string,recommendation_message:string,error:?string,updated_at:string}
     */
    private function fetchTunisiaWeatherOverview(string $region, HttpClientInterface $httpClient): array
    {
        $fallback = [
            'ok' => false,
            'region' => $region,
            'temp_c' => 0.0,
            'humidity' => 0,
            'description' => '-',
            'icon' => '',
            'recommendation_title' => 'Weather assistant is temporarily unavailable',
            'recommendation_message' => 'Weather data cannot be loaded right now. Please try again in a moment.',
            'error' => null,
            'updated_at' => (new \DateTimeImmutable())->format('d/m/Y H:i:s'),
        ];

        $apiKey = $this->getOpenWeatherApiKey();
        if ($apiKey === '') {
            $fallback['recommendation_title'] = 'Weather configuration required';
            $fallback['recommendation_message'] = 'Add your OpenWeather key in .env.local to enable weather-based recommendations.';
            return $fallback;
        }

        $query = self::TUNISIA_REGIONS[$region] ?? self::TUNISIA_REGIONS['Tunis'];

        try {
            $response = $httpClient->request('GET', self::OPENWEATHER_API_BASE, [
                'query' => [
                    'q' => $query,
                    'appid' => $apiKey,
                    'units' => 'metric',
                    'lang' => 'en',
                ],
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray(false);

            $apiCod = (string) ($data['cod'] ?? '200');
            $apiMessage = (string) ($data['message'] ?? '');

            if ($statusCode >= 400 || ($apiCod !== '' && $apiCod !== '200')) {
                $fallback['recommendation_title'] = 'OpenWeather activation in progress';
                $fallback['recommendation_message'] = 'Your API key may need a few minutes to activate. Please retry shortly.';

                if ($apiMessage !== '') {
                    $fallback['error'] = ucfirst($apiMessage) . '.';
                }

                return $fallback;
            }

            if (!isset($data['main']['temp'], $data['main']['humidity'])) {
                return $fallback;
            }

            $temp = (float) ($data['main']['temp'] ?? 0.0);
            $humidity = (int) ($data['main']['humidity'] ?? 0);
            $description = (string) ($data['weather'][0]['description'] ?? '-');
            $icon = (string) ($data['weather'][0]['icon'] ?? '');

            $recommendation = $this->buildWeatherRecommendation($temp, $humidity);

            return [
                'ok' => true,
                'region' => $region,
                'temp_c' => round($temp, 1),
                'humidity' => $humidity,
                'description' => $description,
                'icon' => $icon,
                'recommendation_title' => $recommendation['title'],
                'recommendation_message' => $recommendation['message'],
                'error' => null,
                'updated_at' => (new \DateTimeImmutable())->format('d/m/Y H:i:s'),
            ];
        } catch (\Throwable $e) {
            $fallback['error'] = 'Weather service connection is currently unavailable.';

            return $fallback;
        }
    }

    /**
     * @return array{title:string,message:string}
     */
    private function buildWeatherRecommendation(float $temperature, int $humidity): array
    {
        if ($temperature >= 35 && $humidity <= 50) {
            return [
                'title' => 'Very hot and dry conditions',
                'message' => 'Recommended focus: watermelon, melon, tomatoes, cucumbers, and hydration-related products. Highlight irrigation and mulching solutions as well.',
            ];
        }

        if ($temperature >= 30 && $humidity > 50) {
            return [
                'title' => 'Hot and humid weather',
                'message' => 'Recommended focus: fresh fruits, leafy vegetables, preservation products, and ventilated packaging. Increase rotation for sensitive stock.',
            ];
        }

        if ($temperature >= 20 && $temperature < 30) {
            return [
                'title' => 'Balanced conditions',
                'message' => 'Good time to diversify: citrus, seasonal vegetables, aromatic herbs, and premium local products.',
            ];
        }

        return [
            'title' => 'Cool weather',
            'message' => 'Recommended focus: potatoes, onions, carrots, root vegetables, and long shelf-life products.',
        ];
    }

    private function getOpenWeatherApiKey(): string
    {
        return trim((string) ($_SERVER['OPENWEATHER_API_KEY'] ?? $_ENV['OPENWEATHER_API_KEY'] ?? ''));
    }

    /**
     * @param list<Produit> $products
     *
     * @return list<array{id:int,name:string,stock:int,status:string,category:string,expires_on:?string,is_expired:bool,alert_level:string}>
     */
    private function buildStockAlertData(array $products): array
    {
        $alerts = [];
        $lowStockThreshold = 20;

        foreach ($products as $product) {
            $stock = (int) ($product->getQuantiteStock() ?? 0);
            $status = (string) ($product->getStatut() ?? '');
            $isExpired = $this->isProductExpired($product) || mb_strtolower($status) === 'expiré' || mb_strtolower($status) === 'expired';
            $isOutOfStock = $stock <= 0 || mb_strtolower($status) === 'rupture' || mb_strtolower($status) === 'out of stock';
            $isLow = !$isOutOfStock && $stock <= $lowStockThreshold;

            if (!$isExpired && !$isOutOfStock && !$isLow) {
                continue;
            }

            $alerts[] = [
                'id' => (int) ($product->getIdProduit() ?? 0),
                'name' => (string) ($product->getNom() ?? '-'),
                'stock' => $stock,
                'status' => $status,
                'category' => (string) ($product->getCategorie()?->getNom() ?? 'Uncategorized'),
                'expires_on' => $product->getDateExpiration()?->format('d/m/Y'),
                'is_expired' => $isExpired,
                'alert_level' => ($isExpired || $isOutOfStock) ? 'critical' : 'warning',
            ];
        }

        usort($alerts, static function (array $a, array $b): int {
            $rankA = $a['alert_level'] === 'critical' ? 0 : 1;
            $rankB = $b['alert_level'] === 'critical' ? 0 : 1;

            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }

            return ((int) $a['stock']) <=> ((int) $b['stock']);
        });

        return $alerts;
    }

    /**
     * @param list<Produit> $products
     * @param list<array{id:int,name:string,stock:int,status:string,category:string,expires_on:?string,is_expired:bool,alert_level:string}> $alertData
     *
     * @return array{expired_count:int,non_expired_count:int,low_stock_count:int,out_of_stock_count:int,alert_total:int}
     */
    private function buildStockAlertStats(array $products, array $alertData): array
    {
        $expiredCount = 0;
        $outOfStockCount = 0;
        $lowStockCount = 0;

        foreach ($products as $product) {
            $stock = (int) ($product->getQuantiteStock() ?? 0);
            $status = mb_strtolower((string) ($product->getStatut() ?? ''));
            $isExpired = $this->isProductExpired($product) || $status === 'expiré' || $status === 'expired';
            $isOut = $stock <= 0 || $status === 'rupture' || $status === 'out of stock';
            $isLow = !$isOut && $stock <= 20;

            if ($isExpired) {
                $expiredCount++;
            }

            if ($isOut) {
                $outOfStockCount++;
            } elseif ($isLow) {
                $lowStockCount++;
            }
        }

        return [
            'expired_count' => $expiredCount,
            'non_expired_count' => max(0, count($products) - $expiredCount),
            'low_stock_count' => $lowStockCount,
            'out_of_stock_count' => $outOfStockCount,
            'alert_total' => count($alertData),
        ];
    }

    private function isProductExpired(Produit $product): bool
    {
        $expirationDate = $product->getDateExpiration();
        if (!$expirationDate instanceof \DateTimeInterface) {
            return false;
        }

        $today = new \DateTimeImmutable('today');

        return $expirationDate < $today;
    }
}