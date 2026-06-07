<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Parcelle;
use App\Form\ParcelleType;
use App\Repository\ParcelleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormError;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use App\Service\CropRecommendationService;

#[Route("/elfirma/parcelles")]
final class ParcelleController extends AbstractController
{
    #[Route("", name: "parcelle_index", methods: ["GET"])]
    public function index(
        Request $request,
        ParcelleRepository $parcelleRepository,
    ): Response {
        $page = max(1, $request->query->getInt("page", 1));
        $limit = $request->query->getInt("limit", 10);
        $search = $request->query->get("search", "");
        $sortBy = $request->query->get("sort", "id");
        $sortOrder = $request->query->get("order", "DESC");
        $statut = $request->query->get("statut", "");
        $typeSol = $request->query->get("typeSol", "");

        $result = $parcelleRepository->findPaginated(
            $page,
            $limit,
            $search ?: null,
            $sortBy,
            $sortOrder,
            $statut ?: null,
            $typeSol ?: null,
        );

        $globalStats = [
            "available" => $parcelleRepository->countByStatus("Available"),
            "occupied" => $parcelleRepository->countByStatus("Occupied"),
            "resting" => $parcelleRepository->countByStatus("Resting"),
            "totalArea" => $parcelleRepository->getTotalArea(),
        ];

        return $this->render("elfirma/parcelles/index.html.twig", [
            "parcelles" => $result["data"],
            "pagination" => [
                "page" => $result["page"],
                "limit" => $result["limit"],
                "total" => $result["total"],
                "totalPages" => $result["totalPages"],
            ],
            "search" => $search,
            "sortBy" => $sortBy,
            "sortOrder" => $sortOrder,
            "statut" => $statut,
            "typeSol" => $typeSol,
            "globalStats" => $globalStats,
        ]);
    }

    #[Route("/new", name: "parcelle_new", methods: ["GET", "POST"])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $parcelle = new Parcelle();
        $form = $this->createForm(ParcelleType::class, $parcelle);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile|null $imageFile */
            $imageFile = $form->get("imageFile")->getData();
            if ($imageFile instanceof UploadedFile) {
                $error = null;
                $content = $this->readValidatedImageBlob($imageFile, $error);
                if ($content === null) {
                    $form->addError(
                        new FormError($error ?? "Invalid image upload."),
                    );
                } else {
                    $parcelle->setImage($content);
                }
            }

            if ($form->getErrors(true)->count() > 0) {
                return $this->render("elfirma/parcelles/new.html.twig", [
                    "parcelle" => $parcelle,
                    "form" => $form,
                ]);
            }

            try {
                $entityManager->persist($parcelle);
                $entityManager->flush();

                $this->addFlash("success", "Parcel created successfully!");

                return $this->redirectToRoute("parcelle_index");
            } catch (\Throwable $e) {
                $form->addError(
                    new FormError(
                        "Unable to save parcel. Please check highlighted fields and try again.",
                    ),
                );
            }
        }

        return $this->render("elfirma/parcelles/new.html.twig", [
            "parcelle" => $parcelle,
            "form" => $form,
        ]);
    }

    #[Route("/map", name: "parcelle_map", methods: ["GET"])]
    public function map(ParcelleRepository $parcelleRepository): Response
    {
        $parcelles = $parcelleRepository->findAllWithCultures();

        // Prepare parcelle data for JS (with coordinates)
        $parcellesData = [];
        foreach ($parcelles as $p) {
            if ($p->getLatitude() !== null && $p->getLongitude() !== null) {
                $parcellesData[] = [
                    "id" => $p->getId(),
                    "nom" => $p->getNom(),
                    "localisation" => $p->getLocalisation(),
                    "superficie" => $p->getSuperficie(),
                    "typeSol" => $p->getTypeSol(),
                    "statut" => $p->getStatut(),
                    "latitude" => $p->getLatitude(),
                    "longitude" => $p->getLongitude(),
                    "cultures" => $p->getCultures()->count(),
                    "url" => $this->generateUrl("parcelle_show", [
                        "id" => $p->getId(),
                    ]),
                    "editUrl" => $this->generateUrl("parcelle_edit", [
                        "id" => $p->getId(),
                    ]),
                ];
            }
        }

        return $this->render("elfirma/parcelles/map.html.twig", [
            "parcelles" => $parcelles,
            "parcellesData" => json_encode($parcellesData),
            "totalCount" => count($parcelles),
            "mappedCount" => count($parcellesData),
        ]);
    }

    #[Route("/{id}", name: "parcelle_show", methods: ["GET"])]
    public function show(
        Parcelle $parcelle,
        CropRecommendationService $cropRecommendationService,
    ): Response
    {
        return $this->render("elfirma/parcelles/show.html.twig", [
            "parcelle" => $parcelle,
            "recommendationModel" => $cropRecommendationService->getModelSummary(),
        ]);
    }

    #[Route(
        "/{id}/recommendation",
        name: "parcelle_recommendation",
        methods: ["POST"],
    )]
    public function recommendCrop(
        Request $request,
        Parcelle $parcelle,
        CropRecommendationService $cropRecommendationService,
    ): JsonResponse {
        try {
            /** @var mixed $payload */
            $payload = json_decode(
                $request->getContent(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            if (!is_array($payload)) {
                throw new \InvalidArgumentException(
                    "Invalid payload: expected a JSON object.",
                );
            }

            $features = isset($payload["features"]) && is_array($payload["features"])
                ? $payload["features"]
                : $payload;

            $recommendation = $cropRecommendationService->recommend($features);
            $recommendation["parcel"] = [
                "id" => $parcelle->getId(),
                "name" => $parcelle->getNom(),
                "soil_type" => $parcelle->getTypeSol(),
                "status" => $parcelle->getStatut(),
            ];

            return $this->json($recommendation);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(
                [
                    "error" => $exception->getMessage(),
                ],
                Response::HTTP_BAD_REQUEST,
            );
        } catch (\Throwable $exception) {
            return $this->json(
                [
                    "error" =>
                        "Unable to compute crop recommendation right now.",
                    "details" => $exception->getMessage(),
                ],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }
    }

    #[Route("/{id}/edit", name: "parcelle_edit", methods: ["GET", "POST"])]
    public function edit(
        Request $request,
        Parcelle $parcelle,
        EntityManagerInterface $entityManager,
    ): Response {
        $form = $this->createForm(ParcelleType::class, $parcelle);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile|null $imageFile */
            $imageFile = $form->get("imageFile")->getData();
            if ($imageFile instanceof UploadedFile) {
                $error = null;
                $content = $this->readValidatedImageBlob($imageFile, $error);
                if ($content === null) {
                    $form->addError(
                        new FormError($error ?? "Invalid image upload."),
                    );
                } else {
                    $parcelle->setImage($content);
                }
            }

            if ($form->getErrors(true)->count() > 0) {
                return $this->render("elfirma/parcelles/edit.html.twig", [
                    "parcelle" => $parcelle,
                    "form" => $form,
                ]);
            }

            try {
                $entityManager->flush();

                $this->addFlash("success", "Parcel updated successfully!");

                return $this->redirectToRoute("parcelle_index");
            } catch (\Throwable $e) {
                $form->addError(
                    new FormError(
                        "Unable to update parcel. Please check highlighted fields and try again.",
                    ),
                );
            }
        }

        return $this->render("elfirma/parcelles/edit.html.twig", [
            "parcelle" => $parcelle,
            "form" => $form,
        ]);
    }

    #[Route("/{id}/delete", name: "parcelle_delete", methods: ["POST"])]
    public function delete(
        Request $request,
        Parcelle $parcelle,
        EntityManagerInterface $entityManager,
    ): Response {
        if (
            $this->isCsrfTokenValid(
                "delete" . $parcelle->getId(),
                $request->request->get("_token"),
            )
        ) {
            $entityManager->remove($parcelle);
            $entityManager->flush();

            $this->addFlash("success", "Parcel deleted successfully!");
        }

        return $this->redirectToRoute("parcelle_index");
    }

    #[
        Route(
            "/delete-multiple",
            name: "parcelle_delete_multiple",
            methods: ["POST"],
        ),
    ]
    public function deleteMultiple(
        Request $request,
        EntityManagerInterface $entityManager,
        ParcelleRepository $parcelleRepository,
    ): Response {
        if (
            !$this->isCsrfTokenValid(
                "parcelle_bulk_delete",
                $request->request->get("_token"),
            )
        ) {
            $this->addFlash("error", "Invalid CSRF token.");
            return $this->redirectToRoute("parcelle_index");
        }

        $ids = $request->request->all("ids");
        if (empty($ids)) {
            $this->addFlash("warning", "No parcels selected.");
            return $this->redirectToRoute("parcelle_index");
        }

        $deleted = 0;
        foreach ($ids as $id) {
            $parcelle = $parcelleRepository->find((int) $id);
            if ($parcelle) {
                $entityManager->remove($parcelle);
                $deleted++;
            }
        }
        $entityManager->flush();
        $this->addFlash(
            "success",
            "Successfully deleted {$deleted} parcel(s).",
        );
        return $this->redirectToRoute("parcelle_index");
    }

    #[Route("/{id}/image", name: "parcelle_image", methods: ["GET"])]
    public function image(Parcelle $parcelle): Response
    {
        $blob = $parcelle->getImage();
        if ($blob === null) {
            return new Response("", Response::HTTP_NOT_FOUND);
        }

        $content = \is_resource($blob)
            ? stream_get_contents($blob)
            : (string) $blob;
        if ($content === false || $content === "") {
            return new Response("", Response::HTTP_NOT_FOUND);
        }

        $mimeType = $this->detectImageMimeTypeFromContent($content);

        return new Response($content, Response::HTTP_OK, [
            "Content-Type" => $mimeType,
            "Cache-Control" => "public, max-age=3600",
        ]);
    }

    private function readValidatedImageBlob(
        UploadedFile $imageFile,
        ?string &$error = null,
    ): ?string {
        $maxSizeBytes = 5 * 1024 * 1024;
        $size = (int) ($imageFile->getSize() ?? 0);
        if ($size > $maxSizeBytes) {
            $error = "Image is too large (max 5MB).";
            return null;
        }

        $allowedExtensions = ["jpg", "jpeg", "png", "webp", "gif"];
        $extension = strtolower(
            (string) ($imageFile->getClientOriginalExtension() ?:
            pathinfo($imageFile->getClientOriginalName(), PATHINFO_EXTENSION)),
        );
        if (
            $extension !== "" &&
            !in_array($extension, $allowedExtensions, true)
        ) {
            $error =
                "Invalid image format. Allowed: JPG, JPEG, PNG, WEBP, GIF.";
            return null;
        }

        $content = @file_get_contents($imageFile->getPathname());
        if ($content === false || $content === "") {
            $error = "Unable to read uploaded image.";
            return null;
        }

        if (@getimagesizefromstring($content) === false) {
            $error = "Uploaded file is not a valid image.";
            return null;
        }

        return $content;
    }

    private function detectImageMimeTypeFromContent(string $content): string
    {
        $info = @getimagesizefromstring($content);
        if (
            is_array($info) &&
            isset($info["mime"]) &&
            is_string($info["mime"]) &&
            $info["mime"] !== ""
        ) {
            return $info["mime"];
        }

        return "image/jpeg";
    }

    #[Route("/export/csv", name: "parcelle_export_csv", methods: ["GET"])]
    public function exportCsv(ParcelleRepository $parcelleRepository): Response
    {
        $parcelles = $parcelleRepository->findAll();

        $rows = [];
        $rows[] = implode(",", [
            "ID",
            "Name",
            "Location",
            "Area (ha)",
            "Soil Type",
            "Status",
            "Creation Date",
            "Latitude",
            "Longitude",
        ]);

        foreach ($parcelles as $p) {
            $rows[] = implode(",", [
                $p->getId(),
                '"' . str_replace('"', '""', (string) $p->getNom()) . '"',
                '"' .
                str_replace('"', '""', (string) $p->getLocalisation()) .
                '"',
                $p->getSuperficie(),
                $p->getTypeSol() ?? "",
                $p->getStatut() ?? "",
                $p->getDateCreation()?->format("Y-m-d") ?? "",
                $p->getLatitude() ?? "",
                $p->getLongitude() ?? "",
            ]);
        }

        $csv = implode("\n", $rows);

        return new Response($csv, 200, [
            "Content-Type" => "text/csv; charset=UTF-8",
            "Content-Disposition" =>
                'attachment; filename="parcelles_' . date("Y-m-d") . '.csv"',
        ]);
    }

    #[Route("/export/excel", name: "parcelle_export_excel", methods: ["GET"])]
    public function exportExcel(
        ParcelleRepository $parcelleRepository,
    ): StreamedResponse {
        $parcelles = $parcelleRepository->findAll();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle("Parcelles");

        // Header row
        $headers = [
            "ID",
            "Name",
            "Location",
            "Area (ha)",
            "Soil Type",
            "Status",
            "Creation Date",
            "Latitude",
            "Longitude",
            "Cultures Count",
        ];
        foreach ($headers as $col => $header) {
            $cell =
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
                    $col + 1,
                ) . "1";
            $sheet->setCellValue($cell, $header);
            $sheet->getStyle($cell)->getFont()->setBold(true);
            $sheet
                ->getStyle($cell)
                ->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()
                ->setRGB("0A2200");
            $sheet->getStyle($cell)->getFont()->getColor()->setRGB("FFFFFF");
        }

        // Data rows
        foreach ($parcelles as $row => $p) {
            $rowNum = $row + 2;
            $sheet->setCellValue("A{$rowNum}", $p->getId());
            $sheet->setCellValue("B{$rowNum}", $p->getNom());
            $sheet->setCellValue("C{$rowNum}", $p->getLocalisation());
            $sheet->setCellValue("D{$rowNum}", $p->getSuperficie());
            $sheet->setCellValue("E{$rowNum}", $p->getTypeSol() ?? "");
            $sheet->setCellValue("F{$rowNum}", $p->getStatut() ?? "");
            $sheet->setCellValue(
                "G{$rowNum}",
                $p->getDateCreation()?->format("Y-m-d") ?? "",
            );
            $sheet->setCellValue("H{$rowNum}", $p->getLatitude() ?? "");
            $sheet->setCellValue("I{$rowNum}", $p->getLongitude() ?? "");
            $sheet->setCellValue("J{$rowNum}", $p->getCultures()->count());
        }

        // Auto-size columns
        foreach (range("A", "J") as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $response = new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save("php://output");
        });

        $response->headers->set(
            "Content-Type",
            "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        );
        $response->headers->set(
            "Content-Disposition",
            'attachment; filename="parcelles_' . date("Y-m-d") . '.xlsx"',
        );

        return $response;
    }

    #[Route("/import", name: "parcelle_import", methods: ["POST"])]
    public function import(
        Request $request,
        EntityManagerInterface $entityManager,
        ParcelleRepository $parcelleRepository,
    ): Response {
        $file = $request->files->get("importFile");
        if (!$file) {
            $this->addFlash("error", "No file uploaded.");
            return $this->redirectToRoute("parcelle_index");
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $imported = 0;
        $errors = [];

        try {
            /** @var array<array<string,string>> $dataRows */
            $dataRows = [];

            if ($extension === "csv") {
                $delimiter = $this->detectCsvDelimiter($file->getPathname());
                $handle = fopen($file->getPathname(), "r");
                $rawHeader = fgetcsv($handle, 0, $delimiter);
                $header = $this->normalizeCsvHeader($rawHeader);

                while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                    if (count($row) >= 2) {
                        $dataRows[] = array_combine(
                            $header,
                            array_pad($row, count($header), ""),
                        );
                    }
                }
                fclose($handle);
            } elseif (in_array($extension, ["xlsx", "xls"])) {
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile(
                    $file->getPathname(),
                );
                $spreadsheet = $reader->load($file->getPathname());
                $rows = $spreadsheet->getActiveSheet()->toArray();

                if (empty($rows)) {
                    $this->addFlash("error", "File is empty.");
                    return $this->redirectToRoute("parcelle_index");
                }

                $header = $this->normalizeCsvHeader($rows[0]);
                for ($i = 1; $i < count($rows); $i++) {
                    $dataRows[] = array_combine(
                        $header,
                        array_pad($rows[$i], count($header), ""),
                    );
                }
            } else {
                $this->addFlash(
                    "error",
                    "Unsupported file format. Please upload a CSV or Excel file.",
                );
                return $this->redirectToRoute("parcelle_index");
            }

            foreach ($dataRows as $idx => $data) {
                $rowNum = $idx + 2;

                $nom = trim((string) ($data["name"] ?? ($data["nom"] ?? "")));
                $localisation = trim(
                    (string) ($data["location"] ??
                        ($data["localisation"] ?? "")),
                );

                if (!$nom || !$localisation) {
                    $errors[] = "Row {$rowNum}: Name and Location are required.";
                    continue;
                }

                $superficie =
                    (float) ($data["area"] ?? ($data["superficie"] ?? 1.0));
                if ($superficie <= 0) {
                    $superficie = 1.0;
                }

                $typeSol = trim(
                    (string) ($data["soiltype"] ?? ($data["typesol"] ?? "")),
                );
                if (!in_array($typeSol, ["Sandy", "Loamy", "Clay", "Humus"])) {
                    $typeSol = "Loamy";
                }

                $statut = trim(
                    (string) ($data["status"] ?? ($data["statut"] ?? "")),
                );
                if (!in_array($statut, ["Available", "Occupied", "Resting"])) {
                    $statut = "Available";
                }

                $dateStr = trim(
                    (string) ($data["creationdate"] ??
                        ($data["datecreation"] ?? "")),
                );
                $dateCreation = $dateStr ? $this->parseDate($dateStr) : null;

                $p = new Parcelle();
                $p->setNom($nom);
                $p->setLocalisation($localisation);
                $p->setSuperficie($superficie);
                $p->setTypeSol($typeSol);
                $p->setStatut($statut);
                $p->setDateCreation($dateCreation ?: new \DateTime());
                $p->setLatitude(
                    isset($data["latitude"]) && $data["latitude"] !== ""
                        ? (float) $data["latitude"]
                        : null,
                );
                $p->setLongitude(
                    isset($data["longitude"]) && $data["longitude"] !== ""
                        ? (float) $data["longitude"]
                        : null,
                );

                // Images skipped during import to avoid blocking HTTP calls per row.

                $entityManager->persist($p);
                $imported++;
            }

            $entityManager->flush();
            $this->addFlash(
                "success",
                "Successfully imported {$imported} parcel(s)." .
                    (count($errors) > 0
                        ? " " . count($errors) . " row(s) skipped."
                        : ""),
            );

            if (!empty($errors)) {
                foreach (array_slice($errors, 0, 5) as $err) {
                    $this->addFlash("warning", $err);
                }
            }
        } catch (\Throwable $e) {
            $this->addFlash("error", "Import failed: " . $e->getMessage());
        }

        return $this->redirectToRoute("parcelle_index");
    }

    // -------------------------------------------------------------------------
    // CSV helpers
    // -------------------------------------------------------------------------

    /**
     * Sniff the first line of a CSV file and return the most likely delimiter
     * (one of: ';', ',', '\t', '|').
     */
    private function detectCsvDelimiter(string $filePath): string
    {
        $handle = fopen($filePath, "r");
        $firstLine = fgets($handle) ?: "";
        fclose($handle);

        $candidates = [";", ",", "\t", "|"];
        $counts = [];
        foreach ($candidates as $d) {
            $counts[$d] = substr_count($firstLine, $d);
        }
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * Normalise a CSV header row: lowercase, strip spaces / underscores /
     * parentheses so that "Soil Type", "soil_type" and "SoilType" all
     * become "soiltype".
     *
     * @param array<mixed> $raw
     * @return array<string>
     */
    private function normalizeCsvHeader(array $raw): array
    {
        return array_map(
            fn($h) => strtolower(
                trim(
                    str_replace(
                        [" ", "_", "(", ")", "(ha)"],
                        ["", "", "", "", ""],
                        (string) $h,
                    ),
                ),
            ),
            $raw,
        );
    }

    /**
     * Try several common date formats and return a DateTime, or null on failure.
     * Formats tried: d/m/Y  •  Y-m-d  •  m/d/Y  •  d-m-Y  •  d.m.Y
     */
    private function parseDate(string $dateStr): ?\DateTime
    {
        $dateStr = trim($dateStr);
        if ($dateStr === "") {
            return null;
        }

        foreach (["d/m/Y", "Y-m-d", "m/d/Y", "d-m-Y", "d.m.Y"] as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $dateStr);
            if ($dt !== false) {
                return $dt;
            }
        }

        return null;
    }
}
