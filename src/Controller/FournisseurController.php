<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Fournisseur;
use App\Entity\Contrat;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class FournisseurController extends AbstractController
{
    #[Route('/elfirma/fournisseurs-contrats', name: 'fournisseur_page', methods: ['GET'], priority: 10)]
    public function page(EntityManagerInterface $entityManager): Response
    {
        $fournisseurRepo = $entityManager->getRepository(Fournisseur::class);
        $contratRepo = $entityManager->getRepository(Contrat::class);
        $allSuppliers = $fournisseurRepo->findAll();
        $allContracts = $contratRepo->findAll();

        // Calculate supplier statistics by status
        $activeCount = 0;
        $inactiveCount = 0;
        $suspendedCount = 0;

        foreach ($allSuppliers as $supplier) {
            $statut = $supplier->getStatutF();
            if ($statut === 'Active') {
                $activeCount++;
            } elseif ($statut === 'Inactive') {
                $inactiveCount++;
            } elseif ($statut === 'Suspended') {
                $suspendedCount++;
            }
        }

        // Calculate contract statistics
        $totalContracts = count($allContracts);
        $activeContracts = 0;
        $inactiveContracts = 0;
        $expiredContracts = 0;
        $expiringContracts = 0;
        $today = new \DateTime('today');
        $thirtyDaysFromNow = (new \DateTime('today'))->add(new \DateInterval('P30D'));

        foreach ($allContracts as $contract) {
            $statut = $contract->getStatutCF();
            $endDate = $contract->getDateFinF();

            // Check if contract is expired (end date passed)
            if ($endDate && $endDate < $today) {
                $expiredContracts++;
            }
            // Check if contract is expiring soon (within 30 days)
            elseif ($endDate && $endDate <= $thirtyDaysFromNow) {
                $expiringContracts++;
            }
            // Contract is still active
            elseif ($statut === 'Active') {
                $activeContracts++;
            }

            // Also count inactive contracts
            if ($statut === 'Inactive') {
                $inactiveContracts++;
            }
        }

        return $this->render('elfirma/fournisseurs_contrats.html.twig', [
            'suppliers' => $allSuppliers,
            'contracts' => $allContracts,
            'supplierStats' => [
                'active' => $activeCount,
                'inactive' => $inactiveCount,
                'suspended' => $suspendedCount,
                'total' => count($allSuppliers)
            ],
            'contractStats' => [
                'total' => $totalContracts,
                'active' => $activeContracts,
                'inactive' => $inactiveContracts,
                'expired' => $expiredContracts,
                'expiring' => $expiringContracts,
            ],
            'module_meta' => [
                'folder' => 'fournisseurs_contrats',
                'title' => 'Suppliers & Contracts',
            ],
            'current_module' => 'fournisseurs-contrats',
        ]);
    }

    #[Route('/elfirma/supplier/add', name: 'elfirma_add_supplier', methods: ['POST'])]
    public function addSupplier(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $errors = [];

        // Get form data
        $type = trim($request->request->get('type', ''));
        $description = trim($request->request->get('description', ''));
        $adresse = trim($request->request->get('adresse', ''));
        $tel = trim($request->request->get('tel', ''));
        $email = trim($request->request->get('email', ''));
        $statut = trim($request->request->get('statut', ''));

        // PHP Validations
        if (empty($type)) {
            $errors['type'] = 'Supplier type is required';
        } elseif (strlen($type) > 50) {
            $errors['type'] = 'Supplier type must not exceed 50 characters';
        }

        if (empty($description)) {
            $errors['description'] = 'Description is required';
        } elseif (strlen($description) > 100) {
            $errors['description'] = 'Description must not exceed 100 characters';
        }

        if (empty($adresse)) {
            $errors['adresse'] = 'Address is required';
        } elseif (!preg_match('/[a-zA-Z]/', $adresse)) {
            // Address must contain at least one letter
            $errors['adresse'] = 'Address must contain at least one letter (e.g., 123 marsa or marsa)';
        }

        if (empty($tel)) {
            $errors['tel'] = 'Telephone is required';
        } elseif (!preg_match('/^\d{8}$/', $tel)) {
            $errors['tel'] = 'Telephone must be exactly 8 digits';
        }

        if (empty($email)) {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Email is not valid';
        }

        if (empty($statut) || !in_array($statut, ['Active', 'Inactive', 'Suspended'])) {
            $errors['statut'] = 'Please select a valid status';
        }

        // If there are errors, return them
        if (!empty($errors)) {
            return new JsonResponse([
                'success' => false,
                'errors' => $errors
            ]);
        }

        // Create new supplier
        $fournisseur = new Fournisseur();
        $fournisseur->setTypeF($type);
        $fournisseur->setDescriptionF($description);
        $fournisseur->setAdresseF($adresse);
        $fournisseur->setTelF($tel);
        $fournisseur->setEmailF($email);
        $fournisseur->setStatutF($statut);

        // Save to database
        try {
            $entityManager->persist($fournisseur);
            $entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Supplier created successfully',
                'id' => $fournisseur->getIdF()
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['general' => 'Error creating supplier: ' . $e->getMessage()]
            ]);
        }
    }

    #[Route('/elfirma/supplier/update', name: 'elfirma_update_supplier', methods: ['POST'])]
    public function updateSupplier(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $errors = [];

        // Get form data
        $supplierId = $request->request->get('supplier_id', '');
        $type = trim($request->request->get('type', ''));
        $description = trim($request->request->get('description', ''));
        $adresse = trim($request->request->get('adresse', ''));
        $tel = trim($request->request->get('tel', ''));
        $email = trim($request->request->get('email', ''));
        $statut = trim($request->request->get('statut', ''));

        // Find supplier
        $fournisseurRepo = $entityManager->getRepository(Fournisseur::class);
        $fournisseur = $fournisseurRepo->find($supplierId);

        if (!$fournisseur) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['general' => 'Supplier not found']
            ]);
        }

        // PHP Validations
        if (empty($type)) {
            $errors['type'] = 'Supplier type is required';
        } elseif (strlen($type) > 50) {
            $errors['type'] = 'Supplier type must not exceed 50 characters';
        }

        if (empty($description)) {
            $errors['description'] = 'Description is required';
        } elseif (strlen($description) > 100) {
            $errors['description'] = 'Description must not exceed 100 characters';
        }

        if (empty($adresse)) {
            $errors['adresse'] = 'Address is required';
        } elseif (!preg_match('/[a-zA-Z]/', $adresse)) {
            $errors['adresse'] = 'Address must contain at least one letter (e.g., 123 marsa or marsa)';
        }

        if (empty($tel)) {
            $errors['tel'] = 'Telephone is required';
        } elseif (!preg_match('/^\d{8}$/', $tel)) {
            $errors['tel'] = 'Telephone must be exactly 8 digits';
        }

        if (empty($email)) {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Email is not valid';
        }

        if (empty($statut) || !in_array($statut, ['Active', 'Inactive', 'Suspended'])) {
            $errors['statut'] = 'Please select a valid status';
        }

        // If there are errors, return them
        if (!empty($errors)) {
            return new JsonResponse([
                'success' => false,
                'errors' => $errors
            ]);
        }

        // Update supplier
        $fournisseur->setTypeF($type);
        $fournisseur->setDescriptionF($description);
        $fournisseur->setAdresseF($adresse);
        $fournisseur->setTelF($tel);
        $fournisseur->setEmailF($email);
        $fournisseur->setStatutF($statut);

        try {
            $entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Supplier updated successfully'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['general' => 'Error updating supplier: ' . $e->getMessage()]
            ]);
        }
    }

    #[Route('/elfirma/supplier/delete', name: 'elfirma_delete_supplier', methods: ['POST'])]
    public function deleteSupplier(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $supplierId = $request->request->get('supplier_id', '');

        if (!$supplierId) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Supplier ID is required'
            ]);
        }

        try {
            $fournisseurRepo = $entityManager->getRepository(Fournisseur::class);
            $fournisseur = $fournisseurRepo->find($supplierId);

            if (!$fournisseur) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Supplier not found'
                ]);
            }

            // Delete the supplier
            $entityManager->remove($fournisseur);
            $entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Supplier deleted successfully'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error deleting supplier: ' . $e->getMessage()
            ]);
        }
    }
}
