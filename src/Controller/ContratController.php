<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Contrat;
use App\Entity\Fournisseur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ContratController extends AbstractController
{
    #[Route('/elfirma/contracts', name: 'contrat_page', methods: ['GET'], priority: 10)]
    public function page(EntityManagerInterface $entityManager): Response
    {
        $contratRepo = $entityManager->getRepository(Contrat::class);
        $allContracts = $contratRepo->findAll();

        // Calculate contract statistics
        $activeCount = 0;
        $expiredCount = 0;
        $pendingCount = 0;
        $upcomingExpirations = 0;
        $today = new \DateTime('today');
        $thirtyDaysFromNow = (new \DateTime('today'))->add(new \DateInterval('P30D'));

        foreach ($allContracts as $contract) {
            $statut = $contract->getStatutCF();
            $endDate = $contract->getDateFinF();

            // First priority: check if contract has expired (date-based)
            if ($endDate && $endDate < $today) {
                $expiredCount++;
            }
            // Second: check status
            elseif ($statut === 'Active') {
                $activeCount++;
                // Check for upcoming expirations (within 30 days from today)
                if ($endDate && $endDate <= $thirtyDaysFromNow) {
                    $upcomingExpirations++;
                }
            }
            elseif ($statut === 'Pending') {
                $pendingCount++;
            }
            else {
                // Any other status (Inactive, Expired, etc.) count as expired
                $expiredCount++;
            }
        }

        return $this->render('elfirma/contracts.html.twig', [
            'contracts' => $allContracts,
            'contractStats' => [
                'active' => $activeCount,
                'expired' => $expiredCount,
                'pending' => $pendingCount,
                'total' => count($allContracts),
                'upcomingExpirations' => $upcomingExpirations
            ],
            'module_meta' => [
                'folder' => 'contracts',
                'title' => 'Contract Management',
            ],
            'current_module' => 'contracts',
        ]);
    }

    #[Route('/elfirma/contract/add', name: 'elfirma_add_contract', methods: ['POST'])]
    public function addContract(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $errors = [];
        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/contracts/';

        // Get form data
        $supplierId = $request->request->get('id_f', '');
        $dateDebut = $request->request->get('date_debut', '');
        $dateFin = $request->request->get('date_fin', '');
        $type = trim($request->request->get('type', ''));
        $statut = trim($request->request->get('statut', ''));
        $pdfFile = $request->files->get('pdf_file');
        $pdfPath = null;

        // PHP Validations
        if (empty($supplierId)) {
            $errors['id_f'] = 'Supplier is required';
        }

        if (empty($dateDebut)) {
            $errors['date_debut'] = 'Start date is required';
        } else {
            try {
                $dateDebutObj = new \DateTime($dateDebut);
            } catch (\Exception $e) {
                $errors['date_debut'] = 'Invalid start date';
            }
        }

        if (empty($dateFin)) {
            $errors['date_fin'] = 'End date is required';
        } else {
            try {
                $dateFinObj = new \DateTime($dateFin);
            } catch (\Exception $e) {
                $errors['date_fin'] = 'Invalid end date';
            }
        }

        // Validate end date is after start date
        if (!empty($dateDebut) && !empty($dateFin)) {
            try {
                $dateDebutObj = new \DateTime($dateDebut);
                $dateFinObj = new \DateTime($dateFin);
                if ($dateFinObj <= $dateDebutObj) {
                    $errors['date_fin'] = 'End date must be after start date';
                }
            } catch (\Exception $e) {
                $errors['date_fin'] = 'Invalid date format';
            }
        }

        if (empty($type) || !in_array($type, ['annual', 'monthly'])) {
            $errors['type'] = 'Please select a valid contract type';
        }

        if (empty($statut) || !in_array($statut, ['Active', 'Inactive'])) {
            $errors['statut'] = 'Please select a valid status';
        }

        // Upload PDF if present
        if ($pdfFile) {
            $fileName = md5(uniqid()) . '.' . $pdfFile->guessExtension();
            if (!in_array($pdfFile->guessExtension(), ['pdf', 'PDF'])) {
                $errors['pdf_file'] = 'Only PDF files are allowed';
            } else {
                try {
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    $pdfFile->move($uploadDir, $fileName);
                    $pdfPath = 'uploads/contracts/' . $fileName;
                } catch (\Exception $e) {
                    $errors['pdf_file'] = 'Error uploading the PDF file';
                }
            }
        }

        // If there are errors, return them
        if (!empty($errors)) {
            return new JsonResponse([
                'success' => false,
                'errors' => $errors
            ]);
        }

        // Create new contract
        try {
            $fournisseurRepo = $entityManager->getRepository(Fournisseur::class);
            $fournisseur = $fournisseurRepo->find($supplierId);

            if (!$fournisseur) {
                return new JsonResponse([
                    'success' => false,
                    'errors' => ['id_f' => 'Supplier not found']
                ]);
            }

            $contrat = new Contrat();
            $contrat->setDateDebutF(new \DateTime($dateDebut));
            $contrat->setDateFinF(new \DateTime($dateFin));
            $contrat->setTypeCF($type);
            $contrat->setStatutCF($statut);
            $contrat->setFournisseur($fournisseur);
            if ($pdfPath) {
                $contrat->setPdfFile($pdfPath);
            }

            $entityManager->persist($contrat);
            $entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Contract created successfully'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['general' => 'Error creating contract: ' . $e->getMessage()]
            ]);
        }
    }

    #[Route('/elfirma/supplier/contracts', name: 'elfirma_get_supplier_contracts', methods: ['GET'])]
    public function getSupplierContracts(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $supplierId = $request->query->get('supplier_id', '');

        if (!$supplierId) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Supplier ID is required'
            ]);
        }

        try {
            $contratRepo = $entityManager->getRepository(Contrat::class);
            $contracts = $contratRepo->findBy(['fournisseur' => $supplierId]);

            $contractsData = [];
            foreach ($contracts as $contract) {
                $pdfFile = $contract->getPdfFile();
                // Remove /public/ prefix if it exists (for old entries)
                if (strpos($pdfFile, 'public/') === 0) {
                    $pdfFile = substr($pdfFile, 7);
                }
                $contractsData[] = [
                    'id' => $contract->getIdContrat(),
                    'type' => $contract->getTypeCF(),
                    'date_debut' => $contract->getDateDebutF()->format('Y-m-d'),
                    'date_fin' => $contract->getDateFinF()->format('Y-m-d'),
                    'statut' => $contract->getStatutCF(),
                    'pdf_file' => $pdfFile
                ];
            }

            return new JsonResponse([
                'success' => true,
                'contracts' => $contractsData
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error fetching contracts: ' . $e->getMessage()
            ]);
        }
    }

    #[Route('/elfirma/contract/update', name: 'elfirma_update_contract', methods: ['POST'])]
    public function updateContract(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $errors = [];
        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/contracts/';

        // Get form data
        $contractId = $request->request->get('id_contrat', '');
        $dateDebut = $request->request->get('date_debut', '');
        $dateFin = $request->request->get('date_fin', '');
        $type = trim($request->request->get('type', ''));
        $statut = trim($request->request->get('statut', ''));
        $pdfFile = $request->files->get('pdf_file');

        // Find contract
        $contratRepo = $entityManager->getRepository(Contrat::class);
        $contrat = $contratRepo->find($contractId);

        if (!$contrat) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['general' => 'Contract not found']
            ]);
        }

        // PHP Validations
        if (empty($dateDebut)) {
            $errors['date_debut'] = 'Start date is required';
        } else {
            try {
                $dateDebutObj = new \DateTime($dateDebut);
            } catch (\Exception $e) {
                $errors['date_debut'] = 'Invalid start date';
            }
        }

        if (empty($dateFin)) {
            $errors['date_fin'] = 'End date is required';
        } else {
            try {
                $dateFinObj = new \DateTime($dateFin);
            } catch (\Exception $e) {
                $errors['date_fin'] = 'Invalid end date';
            }
        }

        // Validate end date is after start date
        if (!empty($dateDebut) && !empty($dateFin)) {
            try {
                $dateDebutObj = new \DateTime($dateDebut);
                $dateFinObj = new \DateTime($dateFin);
                if ($dateFinObj <= $dateDebutObj) {
                    $errors['date_fin'] = 'End date must be after start date';
                }
            } catch (\Exception $e) {
                $errors['date_fin'] = 'Invalid date format';
            }
        }

        if (empty($type) || !in_array($type, ['annual', 'monthly'])) {
            $errors['type'] = 'Please select a valid contract type';
        }

        if (empty($statut) || !in_array($statut, ['Active', 'Inactive'])) {
            $errors['statut'] = 'Please select a valid status';
        }

        // Upload PDF if present
        $pdfPath = null;
        if ($pdfFile) {
            $fileName = md5(uniqid()) . '.' . $pdfFile->guessExtension();
            if (!in_array($pdfFile->guessExtension(), ['pdf', 'PDF'])) {
                $errors['pdf_file'] = 'Only PDF files are allowed';
            } else {
                try {
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    $pdfFile->move($uploadDir, $fileName);
                    $pdfPath = 'uploads/contracts/' . $fileName;
                } catch (\Exception $e) {
                    $errors['pdf_file'] = 'Error uploading the PDF file';
                }
            }
        }

        // If there are errors, return them
        if (!empty($errors)) {
            return new JsonResponse([
                'success' => false,
                'errors' => $errors
            ]);
        }

        // Update contract
        $contrat->setDateDebutF(new \DateTime($dateDebut));
        $contrat->setDateFinF(new \DateTime($dateFin));
        $contrat->setTypeCF($type);
        $contrat->setStatutCF($statut);
        if ($pdfPath) {
            $contrat->setPdfFile($pdfPath);
        }

        try {
            $entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Contract updated successfully'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['general' => 'Error updating contract: ' . $e->getMessage()]
            ]);
        }
    }

    #[Route('/elfirma/contract/delete', name: 'elfirma_delete_contract', methods: ['POST'])]
    public function deleteContract(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $contractId = $request->request->get('id_contrat', '');

        if (!$contractId) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Contract ID is required'
            ]);
        }

        try {
            $contratRepo = $entityManager->getRepository(Contrat::class);
            $contrat = $contratRepo->find($contractId);

            if (!$contrat) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Contract not found'
                ]);
            }

            // Delete the contract
            $entityManager->remove($contrat);
            $entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Contract deleted successfully'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error deleting contract: ' . $e->getMessage()
            ]);
        }
    }

    // Contract Management Page Routes (Alternative parameter names)
    #[Route('/elfirma/contracts/create', name: 'elfirma_create_contract', methods: ['POST'])]
    public function createContractPage(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $errors = [];
        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/contracts/';

        // Get form data
        $dateDebut = $request->request->get('startDate', '');
        $dateFin = $request->request->get('endDate', '');
        $type = trim($request->request->get('type', ''));
        $statut = trim($request->request->get('status', ''));
        $pdfFile = $request->files->get('pdf');
        $pdfPath = null;

        // PHP Validations
        if (empty($dateDebut)) {
            $errors['startDate'] = 'Start date is required';
        } else {
            try {
                $dateDebutObj = new \DateTime($dateDebut);
            } catch (\Exception $e) {
                $errors['startDate'] = 'Invalid start date';
            }
        }

        if (empty($dateFin)) {
            $errors['endDate'] = 'End date is required';
        } else {
            try {
                $dateFinObj = new \DateTime($dateFin);
            } catch (\Exception $e) {
                $errors['endDate'] = 'Invalid end date';
            }
        }

        // Validate end date is after start date
        if (!empty($dateDebut) && !empty($dateFin)) {
            try {
                $dateDebutObj = new \DateTime($dateDebut);
                $dateFinObj = new \DateTime($dateFin);
                if ($dateFinObj <= $dateDebutObj) {
                    $errors['endDate'] = 'End date must be after start date';
                }
            } catch (\Exception $e) {
                $errors['endDate'] = 'Invalid date format';
            }
        }

        if (empty($type) || !in_array($type, ['Annual', 'Monthly'])) {
            $errors['type'] = 'Please select a valid contract type';
        }

        if (empty($statut) || !in_array($statut, ['Active', 'Inactive'])) {
            $errors['status'] = 'Please select a valid status';
        }

        // Upload PDF if present
        if ($pdfFile) {
            $fileName = md5(uniqid()) . '.' . $pdfFile->guessExtension();
            if (!in_array(strtolower($pdfFile->guessExtension()), ['pdf'])) {
                $errors['pdf'] = 'Only PDF files are allowed';
            } else {
                try {
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    $pdfFile->move($uploadDir, $fileName);
                    $pdfPath = 'uploads/contracts/' . $fileName;
                } catch (\Exception $e) {
                    $errors['pdf'] = 'Error uploading the PDF file';
                }
            }
        }

        // If there are errors, return them
        if (!empty($errors)) {
            return new JsonResponse([
                'success' => false,
                'errors' => $errors
            ]);
        }

        // Create new contract (without supplier requirement)
        try {
            $contrat = new Contrat();
            $contrat->setDateDebutF(new \DateTime($dateDebut));
            $contrat->setDateFinF(new \DateTime($dateFin));
            $contrat->setTypeCF($type);
            $contrat->setStatutCF($statut);
            if ($pdfPath) {
                $contrat->setPdfFile($pdfPath);
            }

            $entityManager->persist($contrat);
            $entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Contract created successfully'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['general' => 'Error creating contract: ' . $e->getMessage()]
            ]);
        }
    }

    #[Route('/elfirma/contracts/update', name: 'elfirma_update_contract_page', methods: ['POST'])]
    public function updateContractPage(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $errors = [];
        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/contracts/';

        // Get form data
        $contractId = $request->request->get('contract_id', '');
        $dateDebut = $request->request->get('startDate', '');
        $dateFin = $request->request->get('endDate', '');
        $type = trim($request->request->get('type', ''));
        $statut = trim($request->request->get('status', ''));
        $pdfFile = $request->files->get('pdf');

        // Find contract
        $contratRepo = $entityManager->getRepository(Contrat::class);
        $contrat = $contratRepo->find($contractId);

        if (!$contrat) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['general' => 'Contract not found']
            ]);
        }

        // PHP Validations
        if (empty($dateDebut)) {
            $errors['startDate'] = 'Start date is required';
        } else {
            try {
                $dateDebutObj = new \DateTime($dateDebut);
            } catch (\Exception $e) {
                $errors['startDate'] = 'Invalid start date';
            }
        }

        if (empty($dateFin)) {
            $errors['endDate'] = 'End date is required';
        } else {
            try {
                $dateFinObj = new \DateTime($dateFin);
            } catch (\Exception $e) {
                $errors['endDate'] = 'Invalid end date';
            }
        }

        // Validate end date is after start date
        if (!empty($dateDebut) && !empty($dateFin)) {
            try {
                $dateDebutObj = new \DateTime($dateDebut);
                $dateFinObj = new \DateTime($dateFin);
                if ($dateFinObj <= $dateDebutObj) {
                    $errors['endDate'] = 'End date must be after start date';
                }
            } catch (\Exception $e) {
                $errors['endDate'] = 'Invalid date format';
            }
        }

        if (empty($type) || !in_array($type, ['Annual', 'Monthly'])) {
            $errors['type'] = 'Please select a valid contract type';
        }

        if (empty($statut) || !in_array($statut, ['Active', 'Inactive'])) {
            $errors['status'] = 'Please select a valid status';
        }

        if (!empty($errors)) {
            return new JsonResponse([
                'success' => false,
                'errors' => $errors
            ]);
        }

        // Update contract
        try {
            $contrat->setDateDebutF(new \DateTime($dateDebut));
            $contrat->setDateFinF(new \DateTime($dateFin));
            $contrat->setTypeCF($type);
            $contrat->setStatutCF($statut);

            $entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Contract updated successfully'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['general' => 'Error updating contract: ' . $e->getMessage()]
            ]);
        }
    }

    #[Route('/elfirma/contracts/delete', name: 'elfirma_delete_contract_page', methods: ['POST'])]
    public function deleteContractPage(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $contractId = $request->request->get('contract_id', '');

        if (!$contractId) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Contract ID is required'
            ]);
        }

        try {
            $contratRepo = $entityManager->getRepository(Contrat::class);
            $contrat = $contratRepo->find($contractId);

            if (!$contrat) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Contract not found'
                ]);
            }

            $entityManager->remove($contrat);
            $entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Contract deleted successfully'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error deleting contract: ' . $e->getMessage()
            ]);
        }
    }
}
