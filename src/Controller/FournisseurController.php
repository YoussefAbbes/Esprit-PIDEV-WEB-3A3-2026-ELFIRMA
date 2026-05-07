<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Fournisseur;
use App\Entity\Contrat;
use App\Entity\Meeting;
use App\Entity\Rating;
use App\Service\JitsiMeetingService;
use App\Service\GeocodingService;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
// import corrigé pour MailerInterface 
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

use Symfony\Component\Routing\Attribute\Route;

final class FournisseurController extends AbstractController
{
    #[Route('/elfirma/fournisseurs-contrats', name: 'fournisseur_page', methods: ['GET'], priority: 10)]
    public function page(EntityManagerInterface $entityManager, Request $request, PaginatorInterface $paginator): Response
    {
        $fournisseurRepo = $entityManager->getRepository(Fournisseur::class);
        $contratRepo = $entityManager->getRepository(Contrat::class);
        $allSuppliers = $fournisseurRepo->findAll();
        $allContracts = $contratRepo->findAll();
        $suppliers = $paginator->paginate($allSuppliers, $request->query->getInt('page', 1), 10);

        // Calculate supplier statistics by status
        $activeCount = 0;
        $inactiveCount = 0;
        $suspendedCount = 0;

        foreach ($allSuppliers as $supplier) {
            $statut = $supplier->getStatutF();
            if ($statut === "Active") {
                $activeCount++;
            } elseif ($statut === "Inactive") {
                $inactiveCount++;
            } elseif ($statut === "Suspended") {
                $suspendedCount++;
            }
        }

        // Calculate contract statistics
        $totalContracts = count($allContracts);
        $activeContracts = 0;
        $inactiveContracts = 0;
        $expiredContracts = 0;
        $expiringContracts = 0;
        $today = new \DateTime("today");
        $thirtyDaysFromNow = new \DateTime("today");
        $thirtyDaysFromNow->add(new \DateInterval("P30D"));

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
            elseif ($statut === "Active") {
                $activeContracts++;
            }

            // Also count inactive contracts
            if ($statut === "Inactive") {
                $inactiveContracts++;
            }
        }

        return $this->render('elfirma/fournisseurs_contrats.html.twig', [
            'suppliers' => $suppliers,
            'contracts' => $allContracts,
            'supplierStats' => [
                'active' => $activeCount,
                'inactive' => $inactiveCount,
                'suspended' => $suspendedCount,
                'total' => count($allSuppliers)
            ],
            "contractStats" => [
                "total" => $totalContracts,
                "active" => $activeContracts,
                "inactive" => $inactiveContracts,
                "expired" => $expiredContracts,
                "expiring" => $expiringContracts,
            ],
            "module_meta" => [
                "folder" => "fournisseurs_contrats",
                "title" => "Suppliers & Contracts",
            ],
            "current_module" => "fournisseurs-contrats",
        ]);
    }

    #[Route('/api/geocode-address', name: 'api_geocode_address', methods: ['POST'])]
    public function geocodeAddress(Request $request, GeocodingService $geocodingService): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $address = trim($data['address'] ?? '');

        if ($address === '') {
            return new JsonResponse(['success' => false, 'error' => 'Address is required'], 400);
        }

        return new JsonResponse($geocodingService->geocodeAddress($address));
    }

    #[
        Route(
            "/elfirma/supplier/add",
            name: "elfirma_add_supplier",
            methods: ["POST"],
        ),
    ]
    public function addSupplier(
        Request $request,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $errors = [];

        // Get form data
        $type = trim($request->request->get("type", ""));
        $description = trim($request->request->get("description", ""));
        $adresse = trim($request->request->get("adresse", ""));
        $tel = trim($request->request->get("tel", ""));
        $email = trim($request->request->get("email", ""));
        $statut = trim($request->request->get("statut", ""));

        // PHP Validations
        if (empty($type)) {
            $errors["type"] = "Supplier type is required";
        } elseif (strlen($type) > 50) {
            $errors["type"] = "Supplier type must not exceed 50 characters";
        }

        if (empty($description)) {
            $errors["description"] = "Description is required";
        } elseif (strlen($description) > 100) {
            $errors["description"] =
                "Description must not exceed 100 characters";
        }

        if (empty($adresse)) {
            $errors["adresse"] = "Address is required";
        } elseif (!preg_match("/[a-zA-Z]/", $adresse)) {
            // Address must contain at least one letter
            $errors["adresse"] =
                "Address must contain at least one letter (e.g., 123 marsa or marsa)";
        }

        if (empty($tel)) {
            $errors["tel"] = "Telephone is required";
        } elseif (!preg_match('/^\d{8}$/', $tel)) {
            $errors["tel"] = "Telephone must be exactly 8 digits";
        }

        if (empty($email)) {
            $errors["email"] = "Email is required";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors["email"] = "Email is not valid";
        }

        if (
            empty($statut) ||
            !in_array($statut, ["Active", "Inactive", "Suspended"])
        ) {
            $errors["statut"] = "Please select a valid status";
        }

        // If there are errors, return them
        if (!empty($errors)) {
            return new JsonResponse([
                "success" => false,
                "errors" => $errors,
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
                "success" => true,
                "message" => "Supplier created successfully",
                "id" => $fournisseur->getIdF(),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                "success" => false,
                "errors" => [
                    "general" => "Error creating supplier: " . $e->getMessage(),
                ],
            ]);
        }
    }

    #[
        Route(
            "/elfirma/supplier/update",
            name: "elfirma_update_supplier",
            methods: ["POST"],
        ),
    ]
    public function updateSupplier(
        Request $request,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $errors = [];

        // Get form data
        $supplierId = $request->request->get("supplier_id", "");
        $type = trim($request->request->get("type", ""));
        $description = trim($request->request->get("description", ""));
        $adresse = trim($request->request->get("adresse", ""));
        $tel = trim($request->request->get("tel", ""));
        $email = trim($request->request->get("email", ""));
        $statut = trim($request->request->get("statut", ""));

        // Find supplier
        $fournisseurRepo = $entityManager->getRepository(Fournisseur::class);
        $fournisseur = $fournisseurRepo->find($supplierId);

        if (!$fournisseur) {
            return new JsonResponse([
                "success" => false,
                "errors" => ["general" => "Supplier not found"],
            ]);
        }

        // PHP Validations
        if (empty($type)) {
            $errors["type"] = "Supplier type is required";
        } elseif (strlen($type) > 50) {
            $errors["type"] = "Supplier type must not exceed 50 characters";
        }

        if (empty($description)) {
            $errors["description"] = "Description is required";
        } elseif (strlen($description) > 100) {
            $errors["description"] =
                "Description must not exceed 100 characters";
        }

        if (empty($adresse)) {
            $errors["adresse"] = "Address is required";
        } elseif (!preg_match("/[a-zA-Z]/", $adresse)) {
            $errors["adresse"] =
                "Address must contain at least one letter (e.g., 123 marsa or marsa)";
        }

        if (empty($tel)) {
            $errors["tel"] = "Telephone is required";
        } elseif (!preg_match('/^\d{8}$/', $tel)) {
            $errors["tel"] = "Telephone must be exactly 8 digits";
        }

        if (empty($email)) {
            $errors["email"] = "Email is required";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors["email"] = "Email is not valid";
        }

        if (
            empty($statut) ||
            !in_array($statut, ["Active", "Inactive", "Suspended"])
        ) {
            $errors["statut"] = "Please select a valid status";
        }

        // If there are errors, return them
        if (!empty($errors)) {
            return new JsonResponse([
                "success" => false,
                "errors" => $errors,
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
                "success" => true,
                "message" => "Supplier updated successfully",
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                "success" => false,
                "errors" => [
                    "general" => "Error updating supplier: " . $e->getMessage(),
                ],
            ]);
        }
    }

    #[
        Route(
            "/elfirma/supplier/delete",
            name: "elfirma_delete_supplier",
            methods: ["POST"],
        ),
    ]
    public function deleteSupplier(
        Request $request,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $supplierId = $request->request->get("supplier_id", "");

        if (!$supplierId) {
            return new JsonResponse([
                "success" => false,
                "message" => "Supplier ID is required",
            ]);
        }

        try {
            $fournisseurRepo = $entityManager->getRepository(
                Fournisseur::class,
            );
            $fournisseur = $fournisseurRepo->find($supplierId);

            if (!$fournisseur) {
                return new JsonResponse([
                    "success" => false,
                    "message" => "Supplier not found",
                ]);
            }

            // Delete related meetings first
            foreach ($fournisseur->getMeetings() as $meeting) {
                $entityManager->remove($meeting);
            }

            // Delete related ratings
            foreach ($fournisseur->getRatings() as $rating) {
                $entityManager->remove($rating);
            }

            // Delete related meetings first
            foreach ($fournisseur->getMeetings() as $meeting) {
                $entityManager->remove($meeting);
            }

            // Delete related ratings
            foreach ($fournisseur->getRatings() as $rating) {
                $entityManager->remove($rating);
            }

            // Delete the supplier
            $entityManager->remove($fournisseur);
            $entityManager->flush();

            return new JsonResponse([
                "success" => true,
                "message" => "Supplier deleted successfully",
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                "success" => false,
                "message" => "Error deleting supplier: " . $e->getMessage(),
            ]);
        }
    }

    #[
        Route(
            "/elfirma/meeting/schedule",
            name: "elfirma_schedule_meeting",
            methods: ["POST"],
        ),
    ]
    public function scheduleMeeting(
        Request $request,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        JitsiMeetingService $jitsiMeetingService,
        \Twig\Environment $twig,
    ): JsonResponse {
        $errors = [];

        // Get form data
        $supplierId = $request->request->get("supplier_id", "");
        $meetingDateTime = $request->request->get("meeting_datetime", "");

        // Validate supplier ID
        if (empty($supplierId)) {
            $errors["general"] = "Supplier ID is required";
        }

        // Validate meeting datetime
        if (empty($meetingDateTime)) {
            $errors["datetime"] = "Meeting date and time are required";
        } else {
            try {
                $dateTime = new \DateTime($meetingDateTime);
                $now = new \DateTime("now");
                if ($dateTime <= $now) {
                    $errors["datetime"] =
                        "Meeting date and time must be in the future";
                }
            } catch (\Exception $e) {
                $errors["datetime"] = "Invalid date and time format";
            }
        }

        if (!empty($errors)) {
            return new JsonResponse([
                "success" => false,
                "errors" => $errors,
            ]);
        }

        try {
            // Find supplier
            $fournisseurRepo = $entityManager->getRepository(
                Fournisseur::class,
            );
            $supplier = $fournisseurRepo->find($supplierId);

            if (!$supplier) {
                return new JsonResponse([
                    "success" => false,
                    "message" => "Supplier not found",
                ]);
            }

            // Check if supplier has email
            if (empty($supplier->getEmailF())) {
                return new JsonResponse([
                    "success" => false,
                    "message" => "Supplier does not have an email address",
                ]);
            }

            // Generate Jitsi Meeting room and URL
            $dateTime = new \DateTime($meetingDateTime);
            $roomId = $jitsiMeetingService->generateMeetingRoomId(
                (int) $supplierId,
                $dateTime,
            );
            $meetingLink = $jitsiMeetingService->getMeetingUrl($roomId);

            // Create meeting entity
            $meeting = new Meeting();
            $meeting->setFournisseur($supplier);
            $meeting->setMeetingLink($meetingLink);
            $meeting->setMeetingDatetime($dateTime);
            $meeting->setCreatedAt(new \DateTime("now"));

            // Save to database
            $entityManager->persist($meeting);
            $entityManager->flush();

            // Send email
            $emailSent = false;
            $emailError = null;
            try {
                $fromEmail =
                    (string) ($_ENV["MAILER_FROM"] ??
                        ($_SERVER["MAILER_FROM"] ?? "islem.souid@esprit.tn"));
                $alternateMailerDsn =
                    (string) ($_ENV["MAILER_DSN_OTHER"] ??
                        ($_SERVER["MAILER_DSN_OTHER"] ?? ""));

                if (
                    $alternateMailerDsn !== "" &&
                    str_contains($alternateMailerDsn, "smtp.gmail.com")
                ) {
                    $fromEmail = "fethizouabi190@gmail.com";
                }

                $emailContent = $twig->render(
                    "emails/meeting_invitation.html.twig",
                    [
                        "supplierName" => $supplier->getTypeF(),
                        "meetingDateTime" => $meeting->getMeetingDatetime(),
                        "meetingLink" => $meetingLink,
                    ],
                );

                $email = new Email();
                $email->from($fromEmail);
                $email->to($supplier->getEmailF());
                $email->subject("Meeting Invitation - Elfirma");
                $email->html($emailContent);

                if ($alternateMailerDsn !== "") {
                    $alternateTransport = Transport::fromDsn(
                        $alternateMailerDsn,
                    );
                    $alternateMailer = new Mailer($alternateTransport);
                    $alternateMailer->send($email);
                } else {
                    $mailer->send($email);
                }

                $emailSent = true;
            } catch (\Exception $e) {
                // Log email error but don't fail the request
                $emailError = $e->getMessage();
                error_log("Failed to send meeting email: " . $emailError);
            }

            $response = [
                "success" => true,
                "message" => $emailSent
                    ? "Meeting scheduled and email sent successfully"
                    : "Meeting scheduled. Email delivery pending.",
                "meeting_id" => $meeting->getId(),
                "meeting_link" => $meetingLink,
                "email_sent" => $emailSent,
            ];

            if ($emailError) {
                $response["email_error"] = $emailError;
            }

            return new JsonResponse($response);
        } catch (\Exception $e) {
            return new JsonResponse([
                "success" => false,
                "message" => "Error scheduling meeting: " . $e->getMessage(),
            ]);
        }
    }

    #[
        Route(
            "/api/supplier/{id}/ratings-data",
            name: "supplier_ratings_data",
            methods: ["GET"],
        ),
    ]
    public function getSupplierRatingsData(
        int $id,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        try {
            $supplier = $entityManager
                ->getRepository(Fournisseur::class)
                ->find($id);
            if (!$supplier) {
                return new JsonResponse([
                    "success" => false,
                    "message" => "Supplier not found",
                ]);
            }

            $ratingRepo = $entityManager->getRepository(Rating::class);
            $ratings = $ratingRepo->findBySupplierOrderedByDate($id);
            $averageRating = $ratingRepo->getAverageRating($id);
            $totalCount = $ratingRepo->countBySupplier($id);
            $ratingStats = $ratingRepo->getRatingStats($id);

            // Format ratings for response
            $formattedRatings = [];
            foreach ($ratings as $rating) {
                $createdAt = $rating->getCreatedAt();
                $now = new \DateTime();
                $interval = $now->diff($createdAt);

                // Format date as relative time
                if ($interval->days == 0) {
                    if ($interval->h == 0) {
                        $relativeDate = $interval->i . " minutes ago";
                    } else {
                        $relativeDate = $interval->h . " hours ago";
                    }
                } elseif ($interval->days == 1) {
                    $relativeDate = "1 day ago";
                } elseif ($interval->days < 7) {
                    $relativeDate = $interval->days . " days ago";
                } elseif ($interval->days < 30) {
                    $weeks = floor($interval->days / 7);
                    $relativeDate =
                        $weeks . " week" . ($weeks > 1 ? "s" : "") . " ago";
                } else {
                    $relativeDate = $createdAt->format("d M, Y");
                }

                $formattedRatings[] = [
                    "id" => $rating->getIdRating(),
                    "stars" => $rating->getNumberOfStars(),
                    "comment" => $rating->getComment(),
                    "user_id" => $rating->getUserId(),
                    "date" => $relativeDate,
                    "created_at" => $createdAt->format("Y-m-d H:i:s"),
                ];
            }

            // Format star stats
            $stats = [];
            for ($i = 5; $i >= 1; $i--) {
                $count = 0;
                foreach ($ratingStats as $stat) {
                    if ($stat["stars"] == $i) {
                        $count = $stat["count"];
                        break;
                    }
                }
                $stats[] = [
                    "stars" => $i,
                    "count" => $count,
                    "percentage" =>
                        $totalCount > 0
                            ? round(($count / $totalCount) * 100)
                            : 0,
                ];
            }

            return new JsonResponse([
                "success" => true,
                "supplier_name" => $supplier->getTypeF(),
                "average_rating" => $averageRating
                    ? round($averageRating, 1)
                    : 0,
                "total_reviews" => $totalCount,
                "ratings" => $formattedRatings,
                "star_distribution" => $stats,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                "success" => false,
                "message" => "Error fetching ratings: " . $e->getMessage(),
            ]);
        }
    }
}
