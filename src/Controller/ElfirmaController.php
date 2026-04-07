<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Categorie;
use App\Entity\Produit;

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

    #[Route('/elfirma/{module}', name: 'elfirma_page', methods: ['GET'])]
    public function page(string $module, EntityManagerInterface $em): Response
    {
        if (!isset(self::MODULES[$module])) {
            throw $this->createNotFoundException(sprintf('Module "%s" was not found.', $module));
        }

        $moduleMeta = self::MODULES[$module];
        
        $templateData = [
            'module_meta' => $moduleMeta,
            'current_module' => $module,
            'modules' => self::MODULES,
        ];

        // Add specific data for categories page
        if ($module === 'categories') {
            $templateData['categories'] = $em->getRepository(Categorie::class)->findAll();
        }

        // Add specific data for products page
        if ($module === 'produits') {
            $templateData['produits'] = $em->getRepository(Produit::class)->findAll();
            $templateData['categories'] = $em->getRepository(Categorie::class)->findAll();
        }

        return $this->render(sprintf('elfirma/%s.html.twig', $moduleMeta['folder']), $templateData);
    }

    // ========== CRUD CATEGORIE ==========

    #[Route('/elfirma/categorie/create', name: 'categorie_create', methods: ['POST'])]
    public function createCategorie(Request $request, EntityManagerInterface $em): Response
    {
        $nom = $request->request->get('nom');
        
        if (!$nom || strlen(trim($nom)) < 3) {
            $this->addFlash('error', 'Le nom de la catégorie doit contenir au moins 3 caractères');
            return $this->redirectToRoute('elfirma_page', ['module' => 'categories']);
        }

        $categorie = new Categorie();
        $categorie->setNom($nom);

        try {
            $em->persist($categorie);
            $em->flush();
            $this->addFlash('success', 'Catégorie créée avec succès');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la création de la catégorie');
        }

        return $this->redirectToRoute('elfirma_page', ['module' => 'categories']);
    }

    #[Route('/elfirma/categorie/edit/{id}', name: 'categorie_edit', methods: ['POST'])]
    public function editCategorie(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $categorie = $em->getRepository(Categorie::class)->find($id);
        
        if (!$categorie) {
            $this->addFlash('error', 'Catégorie non trouvée');
            return $this->redirectToRoute('elfirma_page', ['module' => 'categories']);
        }

        $nom = $request->request->get('nom');
        
        if (!$nom || strlen(trim($nom)) < 3) {
            $this->addFlash('error', 'Le nom de la catégorie doit contenir au moins 3 caractères');
            return $this->redirectToRoute('elfirma_page', ['module' => 'categories']);
        }

        $categorie->setNom($nom);

        try {
            $em->flush();
            $this->addFlash('success', 'Catégorie modifiée avec succès');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la modification de la catégorie');
        }

        return $this->redirectToRoute('elfirma_page', ['module' => 'categories']);
    }

    #[Route('/elfirma/categorie/delete/{id}', name: 'categorie_delete', methods: ['POST'])]
    public function deleteCategorie(int $id, EntityManagerInterface $em): Response
    {
        $categorie = $em->getRepository(Categorie::class)->find($id);
        
        if (!$categorie) {
            $this->addFlash('error', 'Catégorie non trouvée');
            return $this->redirectToRoute('elfirma_page', ['module' => 'categories']);
        }

        if (count($categorie->getProduits()) > 0) {
            $this->addFlash('error', 'Impossible de supprimer une catégorie contenant des produits');
            return $this->redirectToRoute('elfirma_page', ['module' => 'categories']);
        }

        try {
            $em->remove($categorie);
            $em->flush();
            $this->addFlash('success', 'Catégorie supprimée avec succès');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la suppression de la catégorie');
        }

        return $this->redirectToRoute('elfirma_page', ['module' => 'categories']);
    }

    // ========== CRUD PRODUIT ==========

    #[Route('/elfirma/produit/create', name: 'produit_create', methods: ['POST'])]
    public function createProduit(Request $request, EntityManagerInterface $em, SluggerInterface $slugger): Response
    {
        $produit = new Produit();
        $produit->setNom($request->request->get('nom'));
        $produit->setType($request->request->get('type'));
        $produit->setPrixUnitaire($request->request->get('prix_unitaire'));
        $produit->setQuantiteStock((int)$request->request->get('quantite_stock'));
        $produit->setQualite($request->request->get('qualite'));
        $produit->setStatut($request->request->get('statut', 'Disponible'));
        
        if ($request->request->get('date_production')) {
            $produit->setDateProduction(new \DateTime($request->request->get('date_production')));
        }
        
        if ($request->request->get('date_expiration')) {
            $produit->setDateExpiration(new \DateTime($request->request->get('date_expiration')));
        }

        $categorieId = $request->request->get('categorie_id');
        if ($categorieId) {
            $categorie = $em->getRepository(Categorie::class)->find($categorieId);
            if ($categorie) {
                $produit->setCategorie($categorie);
            }
        }

        // Handle image upload
        /** @var UploadedFile $imageFile */
        $imageFile = $request->files->get('image');
        if ($imageFile) {
            $originalFilename = pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME);
            $safeFilename = $slugger->slug($originalFilename);
            $newFilename = $safeFilename.'-'.uniqid().'.'.$imageFile->guessExtension();

            try {
                $uploadsDirectory = $this->getParameter('kernel.project_dir').'/public/uploads/produits';
                if (!is_dir($uploadsDirectory)) {
                    mkdir($uploadsDirectory, 0777, true);
                }
                $imageFile->move($uploadsDirectory, $newFilename);
                $produit->setImage($newFilename);
            } catch (FileException $e) {
                $this->addFlash('error', 'Erreur lors de l\'upload de l\'image');
            }
        }

        try {
            $em->persist($produit);
            $em->flush();
            $this->addFlash('success', 'Produit créé avec succès');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la création du produit: ' . $e->getMessage());
        }

        return $this->redirectToRoute('elfirma_page', ['module' => 'produits']);
    }

    #[Route('/elfirma/produit/edit/{id}', name: 'produit_edit', methods: ['POST'])]
    public function editProduit(int $id, Request $request, EntityManagerInterface $em, SluggerInterface $slugger): Response
    {
        $produit = $em->getRepository(Produit::class)->find($id);
        
        if (!$produit) {
            $this->addFlash('error', 'Produit non trouvé');
            return $this->redirectToRoute('elfirma_page', ['module' => 'produits']);
        }

        $produit->setNom($request->request->get('nom'));
        $produit->setType($request->request->get('type'));
        $produit->setPrixUnitaire($request->request->get('prix_unitaire'));
        $produit->setQuantiteStock((int)$request->request->get('quantite_stock'));
        $produit->setQualite($request->request->get('qualite'));
        $produit->setStatut($request->request->get('statut', 'Disponible'));
        
        if ($request->request->get('date_production')) {
            $produit->setDateProduction(new \DateTime($request->request->get('date_production')));
        }
        
        if ($request->request->get('date_expiration')) {
            $produit->setDateExpiration(new \DateTime($request->request->get('date_expiration')));
        }

        $categorieId = $request->request->get('categorie_id');
        if ($categorieId) {
            $categorie = $em->getRepository(Categorie::class)->find($categorieId);
            if ($categorie) {
                $produit->setCategorie($categorie);
            }
        }

        // Handle image upload
        /** @var UploadedFile $imageFile */
        $imageFile = $request->files->get('image');
        if ($imageFile) {
            // Delete old image if exists
            if ($produit->getImage()) {
                $oldImagePath = $this->getParameter('kernel.project_dir').'/public/uploads/produits/'.$produit->getImage();
                if (file_exists($oldImagePath)) {
                    unlink($oldImagePath);
                }
            }

            $originalFilename = pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME);
            $safeFilename = $slugger->slug($originalFilename);
            $newFilename = $safeFilename.'-'.uniqid().'.'.$imageFile->guessExtension();

            try {
                $uploadsDirectory = $this->getParameter('kernel.project_dir').'/public/uploads/produits';
                if (!is_dir($uploadsDirectory)) {
                    mkdir($uploadsDirectory, 0777, true);
                }
                $imageFile->move($uploadsDirectory, $newFilename);
                $produit->setImage($newFilename);
            } catch (FileException $e) {
                $this->addFlash('error', 'Erreur lors de l\'upload de l\'image');
            }
        }

        try {
            $em->flush();
            $this->addFlash('success', 'Produit modifié avec succès');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la modification du produit');
        }

        return $this->redirectToRoute('elfirma_page', ['module' => 'produits']);
    }

    #[Route('/elfirma/produit/delete/{id}', name: 'produit_delete', methods: ['POST'])]
    public function deleteProduit(int $id, EntityManagerInterface $em): Response
    {
        $produit = $em->getRepository(Produit::class)->find($id);
        
        if (!$produit) {
            $this->addFlash('error', 'Produit non trouvé');
            return $this->redirectToRoute('elfirma_page', ['module' => 'produits']);
        }

        // Delete image if exists
        if ($produit->getImage()) {
            $imagePath = $this->getParameter('kernel.project_dir').'/public/uploads/produits/'.$produit->getImage();
            if (file_exists($imagePath)) {
                unlink($imagePath);
            }
        }

        try {
            $em->remove($produit);
            $em->flush();
            $this->addFlash('success', 'Produit supprimé avec succès');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la suppression du produit');
        }

        return $this->redirectToRoute('elfirma_page', ['module' => 'produits']);
    }

    #[Route('/elfirma/produit/api/list', name: 'produit_api_list', methods: ['GET'])]
    public function getProduitsList(EntityManagerInterface $em): Response
    {
        $produits = $em->getRepository(Produit::class)->findAll();
        
        $data = array_map(function(Produit $produit) {
            return [
                'id' => $produit->getIdProduit(),
                'nom' => $produit->getNom(),
                'type' => $produit->getType(),
                'qualite' => $produit->getQualite(),
                'prix' => $produit->getPrixUnitaire(),
                'stock' => $produit->getQuantiteStock(),
                'categorie' => $produit->getCategorie()?->getNom(),
                'statut' => $produit->getStatut(),
                'dateProduction' => $produit->getDateProduction()?->format('d/m/Y'),
                'dateExpiration' => $produit->getDateExpiration()?->format('d/m/Y'),
            ];
        }, $produits);
        
        return $this->json($data);
    }

    private function generatePDFContent(array $produits): string
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Product List</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; color: #333; }
        .header { background: linear-gradient(135deg, #2d5016 0%, #1e3a0f 100%); color: white; padding: 30px; text-align: center; margin-bottom: 30px; }
        .header h1 { font-size: 28px; margin-bottom: 5px; }
        .header p { font-size: 14px; opacity: 0.9; }
        .content { padding: 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th { background: #2d5016; color: white; padding: 12px; text-align: left; font-weight: bold; font-size: 12px; }
        td { padding: 12px; border-bottom: 1px solid #ddd; font-size: 11px; }
        tr:nth-child(even) { background: #f9f9f9; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 10px; border-top: 1px solid #ddd; margin-top: 30px; }
        .status-available { background: #d4edda; color: #155724; padding: 4px 8px; border-radius: 4px; font-weight: bold; }
        .status-rupture { background: #f8d7da; color: #721c24; padding: 4px 8px; border-radius: 4px; font-weight: bold; }
        .status-expired { background: #d3d3d3; color: #333; padding: 4px 8px; border-radius: 4px; font-weight: bold; }
        .price { font-weight: bold; color: #2d5016; }
    </style>
</head>
<body>
    <div class="header">
        <h1>📋 Elfirma - Product List</h1>
        <p>Generated on HTML
HTML;
        
        $html .= date('Y-m-d H:i:s');
        
        $html .= <<<'HTML'
</p>
    </div>
    
    <div class="content">
        <table>
            <thead>
                <tr>
                    <th>Product Name</th>
                    <th>Category</th>
                    <th>Type</th>
                    <th>Quality</th>
                    <th>Price (DT)</th>
                    <th>Stock</th>
                    <th>Prod. Date</th>
                    <th>Exp. Date</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
HTML;
        
        if (count($produits) === 0) {
            $html .= '<tr><td colspan="9" style="text-align: center; color: #999;">No products found</td></tr>';
        } else {
            foreach ($produits as $produit) {
                $status = $produit->getStatut();
                $statusClass = match($status) {
                    'Disponible' => 'status-available',
                    'Rupture' => 'status-rupture',
                    'Expiré' => 'status-expired',
                    default => 'status-available'
                };
                
                $html .= '<tr>';
                $html .= '<td><strong>' . htmlspecialchars($produit->getNom() ?? 'N/A') . '</strong></td>';
                $html .= '<td>' . htmlspecialchars($produit->getCategorie()?->getNom() ?? 'N/A') . '</td>';
                $html .= '<td>' . htmlspecialchars($produit->getType() ?? 'N/A') . '</td>';
                $html .= '<td>' . htmlspecialchars($produit->getQualite() ?? 'N/A') . '</td>';
                $html .= '<td class="price">' . number_format((float)$produit->getPrixUnitaire(), 2, ',', ' ') . ' DT</td>';
                $html .= '<td><strong>' . $produit->getQuantiteStock() . '</strong></td>';
                $html .= '<td>' . ($produit->getDateProduction()?->format('d/m/Y') ?? 'N/A') . '</td>';
                $html .= '<td>' . ($produit->getDateExpiration()?->format('d/m/Y') ?? 'N/A') . '</td>';
                $html .= '<td><span class="' . $statusClass . '">' . htmlspecialchars($status) . '</span></td>';
                $html .= '</tr>';
            }
        }
        
        $html .= <<<'HTML'
            </tbody>
        </table>
    </div>
    
    <div class="footer">
        <p>© Elfirma Agricultural Management System</p>
        <p>This document was automatically generated and contains confidential information.</p>
    </div>
</body>
</html>
HTML;
        
        return $html;
    }
}
