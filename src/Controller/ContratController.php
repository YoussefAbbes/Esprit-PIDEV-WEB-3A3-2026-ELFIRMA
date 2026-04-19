<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Contrat;
use App\Entity\Fournisseur;
use App\Service\ContractPdfService;
use App\Service\ImageToTextService;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ContratController extends AbstractController
{
    #[Route('/elfirma/contracts', name: 'contrat_page', methods: ['GET'], priority: 10)]
    public function page(Request $request, EntityManagerInterface $entityManager, PaginatorInterface $paginator): Response
    {
        $contratRepo = $entityManager->getRepository(Contrat::class);
        $allContracts = $contratRepo->findAll();
        $contractsQueryBuilder = $contratRepo->createQueryBuilder('c')
            ->orderBy('c.id_contrat', 'DESC');

        $contracts = $paginator->paginate(
            $contractsQueryBuilder,
            max(1, (int) $request->query->get('contractPage', 1)),
            10,
            ['pageParameterName' => 'contractPage']
        );

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
            'contracts' => $contracts,
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
        $generatedPdfFile = $request->request->get('generated_pdf_file', '');
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

        // Handle PDF: either uploaded file or generated PDF
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
        } elseif ($generatedPdfFile) {
            // Use the generated PDF file
            $pdfPath = $generatedPdfFile;
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
        $sort = (string) $request->query->get('sort', 'date-desc');

        if (!$supplierId) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Supplier ID is required'
            ]);
        }

        try {
            $allowedSort = [
                'date-desc' => ['c.date_debut_f', 'DESC'],
                'date-asc' => ['c.date_debut_f', 'ASC'],
                'status' => ['c.statut_c_f', 'ASC'],
                'type-asc' => ['c.type_c_f', 'ASC'],
                'type-desc' => ['c.type_c_f', 'DESC'],
            ];

            if (!isset($allowedSort[$sort])) {
                $sort = 'date-desc';
            }

            [$sortField, $sortDirection] = $allowedSort[$sort];

            // Use QueryBuilder for more explicit query
            $contratRepo = $entityManager->getRepository(Contrat::class);
            $queryBuilder = $contratRepo->createQueryBuilder('c')
                ->where('c.fournisseur = :supplier_id')
                ->setParameter('supplier_id', $supplierId)
                ->orderBy($sortField, $sortDirection)
                ->addOrderBy('c.id_contrat', 'DESC');

            $contracts = $queryBuilder->getQuery()->getResult();

            $contractsData = [];
            foreach ($contracts as $contract) {
                $pdfFile = $contract->getPdfFile();
                // Remove /public/ prefix if it exists (for old entries)
                if ($pdfFile && strpos($pdfFile, 'public/') === 0) {
                    $pdfFile = substr($pdfFile, 7);
                }
                $contractsData[] = [
                    'id' => $contract->getIdContrat(),
                    'type' => $contract->getTypeCF(),
                    'date_debut' => $contract->getDateDebutF() ? $contract->getDateDebutF()->format('Y-m-d') : null,
                    'date_fin' => $contract->getDateFinF() ? $contract->getDateFinF()->format('Y-m-d') : null,
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

    #[Route('/elfirma/contract/generate-pdf', name: 'generate_contract_pdf', methods: ['POST'])]
    public function generateContractPdf(
        Request $request,
        ContractPdfService $pdfService,
        EntityManagerInterface $entityManager
    ): Response {
        try {
            $data = json_decode($request->getContent(), true);
            $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/contracts/';

            // Validate required fields
            if (!isset($data['supplier_id']) || !isset($data['date_debut']) || !isset($data['date_fin'])) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Missing required fields: supplier_id, date_debut, date_fin'
                ], 400);
            }

            // Get supplier details
            $supplier = $entityManager->getRepository(Fournisseur::class)->find($data['supplier_id']);
            if (!$supplier) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Supplier not found'
                ], 404);
            }

            // Validate dates
            try {
                $dateDebut = new \DateTime($data['date_debut']);
                $dateFin = new \DateTime($data['date_fin']);

                if ($dateFin <= $dateDebut) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => 'End date must be after start date'
                    ], 400);
                }
            } catch (\Exception $e) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Invalid date format'
                ], 400);
            }

            // Prepare data for PDF
            $pdfData = [
                'supplier_id' => $data['supplier_id'],
                'supplier_name' => $supplier->getTypeF() ?? 'Unknown Supplier',
                'date_debut' => $data['date_debut'],
                'date_fin' => $data['date_fin'],
                'type' => $data['type'] ?? 'N/A',
                'statut' => $data['statut'] ?? 'N/A'
            ];

            // Generate PDF
            $pdfContent = $pdfService->generateContractPdf($pdfData);

            // Create upload directory if it doesn't exist
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Save PDF file with hash name
            $fileName = md5(uniqid()) . '.pdf';
            $filePath = $uploadDir . $fileName;
            file_put_contents($filePath, $pdfContent);

            // Store path for database
            $pdfDbPath = 'uploads/contracts/' . $fileName;

            return new JsonResponse([
                'success' => true,
                'message' => 'PDF generated successfully',
                'pdf_file' => $pdfDbPath,
                'pdf_filename' => $fileName
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error generating PDF: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/elfirma/contract/image-to-pdf', name: 'elfirma_image_to_pdf', methods: ['POST'])]
    public function convertImageToPdf(
        Request $request,
        ImageToTextService $imageToTextService,
        ContractPdfService $pdfService,
        LoggerInterface $logger
    ): JsonResponse {
        try {
            $logger->info('=== IMAGE TO PDF CONVERSION STARTED ===');

            $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/contracts/';
            $tempDir = $this->getParameter('kernel.project_dir') . '/var/temp/';

            // Get image file from form data
            $imageFile = $request->files->get('image_file');
            $supplierId = $request->request->get('supplier_id', '');
            $supplieName = $request->request->get('supplier_name', 'Unknown Supplier');

            $logger->info('Received request - Supplier: ' . $supplieName . ', Has image: ' . ($imageFile ? 'YES' : 'NO'));

            // Validate image file exists
            if (!$imageFile) {
                $logger->error('No image file provided');
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Please select an image file to extract text from.'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Create temp directory if it doesn't exist
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            // Validate image file type
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
            $fileExtension = strtolower($imageFile->guessExtension());

            if (!in_array($fileExtension, $allowedExtensions)) {
                $logger->error('Invalid file type: ' . $fileExtension);
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Only image files (JPG, PNG, GIF, BMP, WebP) are allowed.'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate file size (max 5MB)
            $maxFileSize = 5 * 1024 * 1024;
            if ($imageFile->getSize() > $maxFileSize) {
                $logger->error('File too large: ' . ($imageFile->getSize() / 1024 / 1024) . 'MB');
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Image file must be smaller than 5MB.'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Save image temporarily
            $tempImageName = md5(uniqid() . time()) . '.' . $fileExtension;
            $tempImagePath = $tempDir . $tempImageName;

            try {
                $imageFile->move($tempDir, $tempImageName);
                $logger->info('Image saved temporarily to: ' . $tempImagePath);
            } catch (\Exception $e) {
                $logger->error('Failed to save temp image: ' . $e->getMessage());
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Failed to process image file.'
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            try {
                // Extract text from image using API Ninjas
                $logger->info('Starting text extraction via API Ninjas...');
                $extractionResult = $imageToTextService->extractTextFromImage($tempImagePath);

                if (!$extractionResult['success']) {
                    $logger->error('Extraction failed: ' . $extractionResult['error']);
                    @unlink($tempImagePath);
                    return new JsonResponse([
                        'success' => false,
                        'error' => $extractionResult['error']
                    ], Response::HTTP_INTERNAL_SERVER_ERROR);
                }

                $extractedText = $extractionResult['text'];
                $logger->info('Extraction result - Text length: ' . strlen($extractedText) . ' characters');

                // Check if any text was actually extracted
                if (empty(trim($extractedText))) {
                    @unlink($tempImagePath);
                    $logger->warning('API returned empty text for image');
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'No text found in the image. Please ensure the image contains clear, readable text.'
                    ], Response::HTTP_OK);
                }

                // Clean up temp image
                @unlink($tempImagePath);
                $logger->info('Temporary image deleted');

                // Generate PDF from extracted text
                $logger->info('Generating PDF from extracted text...');
                $pdfContent = $pdfService->generatePdfFromExtractedText([
                    'supplier_name' => $supplieName,
                    'extracted_text' => $extractedText
                ]);
                $logger->info('PDF generated successfully');

                // Create upload directory if needed
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                // Save PDF file
                $pdfFileName = md5(uniqid() . time()) . '.pdf';
                $pdfPath = $uploadDir . $pdfFileName;
                file_put_contents($pdfPath, $pdfContent);
                $logger->info('PDF saved to: ' . $pdfPath);

                $pdfDbPath = 'uploads/contracts/' . $pdfFileName;

                $logger->info('=== IMAGE TO PDF CONVERSION COMPLETED SUCCESSFULLY ===');

                return new JsonResponse([
                    'success' => true,
                    'message' => 'Text extracted and PDF generated successfully.',
                    'extracted_text' => $extractedText,
                    'pdf_file' => $pdfDbPath,
                    'pdf_filename' => $pdfFileName
                ]);

            } catch (\Exception $e) {
                @unlink($tempImagePath);
                $logger->error('Error during extraction/PDF generation: ' . $e->getMessage());

                return new JsonResponse([
                    'success' => false,
                    'error' => 'An error occurred: ' . $e->getMessage()
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

        } catch (\Exception $e) {
            $logger->error('EXCEPTION in convertImageToPdf: ' . $e->getMessage());
            $logger->error('File: ' . $e->getFile() . ':' . $e->getLine());

            return new JsonResponse([
                'success' => false,
                'error' => 'An unexpected error occurred.'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
