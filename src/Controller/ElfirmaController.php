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
}
