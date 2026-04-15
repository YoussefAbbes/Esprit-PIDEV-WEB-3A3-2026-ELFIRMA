<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Culture;
use App\Form\CultureType;
use App\Repository\CultureRepository;
use App\Repository\ParcelleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormError;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\Parcelle;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use App\Service\CropGuideService;
use App\Service\PixabayService;

#[Route("/elfirma/cultures")]
final class CultureController extends AbstractController
{
    #[Route("", name: "culture_index", methods: ["GET"])]
    public function index(
        Request $request,
        CultureRepository $cultureRepository,
        ParcelleRepository $parcelleRepository,
    ): Response {
        $page = max(1, $request->query->getInt("page", 1));
        $limit = $request->query->getInt("limit", 10);
        $search = $request->query->get("search", "");
        $sortBy = $request->query->get("sort", "id");
        $sortOrder = $request->query->get("order", "DESC");
        $statut = $request->query->get("statut", "");
        $parcelleId = $request->query->getInt("parcelleId", 0) ?: null;

        $result = $cultureRepository->findPaginated(
            $page,
            $limit,
            $search ?: null,
            $sortBy,
            $sortOrder,
            $statut ?: null,
            $parcelleId,
        );

        $globalStats = [
            "inProgress" => $cultureRepository->countByStatus("In Progress"),
            "planned" => $cultureRepository->countByStatus("Planned"),
            "harvested" => $cultureRepository->countByStatus("Harvested"),
        ];

        return $this->render("elfirma/cultures/index.html.twig", [
            "cultures" => $result["data"],
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
            "parcelleId" => $parcelleId,
            "parcelles" => $parcelleRepository->findAll(),
            "globalStats" => $globalStats,
        ]);
    }

    #[Route("/new", name: "culture_new", methods: ["GET", "POST"])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        ParcelleRepository $parcelleRepository,
    ): Response {
        $culture = new Culture();
        $form = $this->createForm(CultureType::class, $culture);
        $form->handleRequest($request);

        $prefilledParcelleId = $request->query->getInt("parcelleId", 0);
        $prefilledCropName = trim($request->query->getString("prefillCrop", ""));
        $prefilledVariety = trim(
            $request->query->getString("prefillVariety", ""),
        );

        if (!$form->isSubmitted()) {
            if ($prefilledParcelleId > 0) {
                $prefilledParcelle = $parcelleRepository->find(
                    $prefilledParcelleId,
                );
                if ($prefilledParcelle !== null) {
                    $culture->setParcelle($prefilledParcelle);
                }
            }

            if ($prefilledCropName !== "") {
                $culture->setNomCulture($prefilledCropName);
            }

            if ($prefilledVariety !== "") {
                $culture->setVariete($prefilledVariety);
            }
        }

        $recommendationContext = [
            "prefilledParcelleId" => $prefilledParcelleId > 0
                ? $prefilledParcelleId
                : null,
            "prefilledCropName" => $prefilledCropName !== ""
                ? $prefilledCropName
                : null,
        ];

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
                    $culture->setImage($content);
                }
            }

            if ($form->getErrors(true)->count() > 0) {
                return $this->render("elfirma/cultures/new.html.twig", [
                    "culture" => $culture,
                    "form" => $form->createView(),
                    "parcelles" => $parcelleRepository->findAll(),
                    "recommendationContext" => $recommendationContext,
                ]);
            }

            // Auto-calculate rendement
            if ($culture->getQuantitePlantee() > 0) {
                $rendement = round(
                    ($culture->getQuantiteRecoltee() /
                        $culture->getQuantitePlantee()) *
                        100,
                    2,
                );
                $culture->setRendement($rendement);
            }

            try {
                $entityManager->persist($culture);
                $entityManager->flush();

                $this->addFlash("success", "Crop created successfully!");

                return $this->redirectToRoute("culture_index");
            } catch (\Throwable $e) {
                $form->addError(
                    new FormError(
                        "Unable to save crop. Please check highlighted fields and try again.",
                    ),
                );
            }
        }

        return $this->render("elfirma/cultures/new.html.twig", [
            "culture" => $culture,
            "form" => $form->createView(),
            "parcelles" => $parcelleRepository->findAll(),
            "recommendationContext" => $recommendationContext,
        ]);
    }

    #[Route("/calendar", name: "culture_calendar", methods: ["GET"])]
    public function calendar(CultureRepository $cultureRepository): Response
    {
        $cultures = $cultureRepository->findAllWithParcelle();
        return $this->render("elfirma/cultures/calendar.html.twig", [
            "cultures" => $cultures,
            "totalCount" => count($cultures),
        ]);
    }

    #[
        Route(
            "/calendar/events",
            name: "culture_calendar_events",
            methods: ["GET"],
        ),
    ]
    public function calendarEvents(
        CultureRepository $cultureRepository,
    ): Response {
        $cultures = $cultureRepository->findAllWithParcelle();
        $events = [];
        $today = new \DateTime();

        foreach ($cultures as $c) {
            $parcelName = $c->getParcelle()?->getNom() ?? "Unknown parcel";

            // Planting event
            if ($c->getDatePlantation()) {
                $events[] = [
                    "id" => "plant_" . $c->getId(),
                    "title" => "🌱 " . $c->getNomCulture(),
                    "start" => $c->getDatePlantation()->format("Y-m-d"),
                    "color" => "#10b981",
                    "extendedProps" => [
                        "type" => "Planting",
                        "crop" => $c->getNomCulture(),
                        "parcel" => $parcelName,
                        "status" => $c->getStatut(),
                        "url" => $this->generateUrl("culture_show", [
                            "id" => $c->getId(),
                        ]),
                    ],
                ];
            }

            // Expected harvest
            if ($c->getDateRecoltePrevue()) {
                $isOverdue =
                    $c->getDateRecoltePrevue() < $today &&
                    $c->getStatut() !== "Harvested";
                $events[] = [
                    "id" => "harvest_exp_" . $c->getId(),
                    "title" =>
                        ($isOverdue ? "⚠️ " : "🌾 ") . $c->getNomCulture(),
                    "start" => $c->getDateRecoltePrevue()->format("Y-m-d"),
                    "color" => $isOverdue ? "#ef4444" : "#f97316",
                    "extendedProps" => [
                        "type" => $isOverdue
                            ? "Overdue Harvest"
                            : "Expected Harvest",
                        "crop" => $c->getNomCulture(),
                        "parcel" => $parcelName,
                        "status" => $c->getStatut(),
                        "url" => $this->generateUrl("culture_show", [
                            "id" => $c->getId(),
                        ]),
                    ],
                ];
            }

            // Actual harvest
            if ($c->getDateRecolteReelle()) {
                $events[] = [
                    "id" => "harvest_real_" . $c->getId(),
                    "title" => "✅ " . $c->getNomCulture(),
                    "start" => $c->getDateRecolteReelle()->format("Y-m-d"),
                    "color" => "#6366f1",
                    "extendedProps" => [
                        "type" => "Actual Harvest",
                        "crop" => $c->getNomCulture(),
                        "parcel" => $parcelName,
                        "status" => $c->getStatut(),
                        "url" => $this->generateUrl("culture_show", [
                            "id" => $c->getId(),
                        ]),
                    ],
                ];
            }
        }

        return new Response(json_encode($events), 200, [
            "Content-Type" => "application/json",
        ]);
    }

    #[Route("/{id}", name: "culture_show", methods: ["GET"])]
    public function show(Culture $culture): Response
    {
        return $this->render("elfirma/cultures/show.html.twig", [
            "culture" => $culture,
        ]);
    }

    #[Route("/{id}/guide", name: "culture_guide", methods: ["GET"])]
    public function guide(
        Culture $culture,
        CropGuideService $cropGuide,
    ): JsonResponse {
        $data = $cropGuide->buildCropGuide(
            $culture->getNomCulture(),
            $culture->getVariete() ?? "",
        );

        return $this->json($data);
    }

    #[Route("/{id}/edit", name: "culture_edit", methods: ["GET", "POST"])]
    public function edit(
        Request $request,
        Culture $culture,
        EntityManagerInterface $entityManager,
    ): Response {
        $form = $this->createForm(CultureType::class, $culture);
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
                    $culture->setImage($content);
                }
            }

            if ($form->getErrors(true)->count() > 0) {
                return $this->render("elfirma/cultures/edit.html.twig", [
                    "culture" => $culture,
                    "form" => $form->createView(),
                ]);
            }

            // Auto-calculate rendement
            if ($culture->getQuantitePlantee() > 0) {
                $rendement = round(
                    ($culture->getQuantiteRecoltee() /
                        $culture->getQuantitePlantee()) *
                        100,
                    2,
                );
                $culture->setRendement($rendement);
            }

            try {
                $entityManager->flush();

                $this->addFlash("success", "Crop updated successfully!");

                return $this->redirectToRoute("culture_index");
            } catch (\Throwable $e) {
                $form->addError(
                    new FormError(
                        "Unable to update crop. Please check highlighted fields and try again.",
                    ),
                );
            }
        }

        return $this->render("elfirma/cultures/edit.html.twig", [
            "culture" => $culture,
            "form" => $form->createView(),
        ]);
    }

    #[Route("/{id}/delete", name: "culture_delete", methods: ["POST"])]
    public function delete(
        Request $request,
        Culture $culture,
        EntityManagerInterface $entityManager,
    ): Response {
        if (
            $this->isCsrfTokenValid(
                "delete" . $culture->getId(),
                $request->request->get("_token"),
            )
        ) {
            $entityManager->remove($culture);
            $entityManager->flush();

            $this->addFlash("success", "Crop deleted successfully!");
        }

        return $this->redirectToRoute("culture_index");
    }

    #[
        Route(
            "/delete-multiple",
            name: "culture_delete_multiple",
            methods: ["POST"],
        ),
    ]
    public function deleteMultiple(
        Request $request,
        EntityManagerInterface $entityManager,
        CultureRepository $cultureRepository,
    ): Response {
        if (
            !$this->isCsrfTokenValid(
                "culture_bulk_delete",
                $request->request->get("_token"),
            )
        ) {
            $this->addFlash("error", "Invalid CSRF token.");
            return $this->redirectToRoute("culture_index");
        }

        $ids = $request->request->all("ids");
        if (empty($ids)) {
            $this->addFlash("warning", "No crops selected.");
            return $this->redirectToRoute("culture_index");
        }

        $deleted = 0;
        foreach ($ids as $id) {
            $culture = $cultureRepository->find((int) $id);
            if ($culture) {
                $entityManager->remove($culture);
                $deleted++;
            }
        }
        $entityManager->flush();
        $this->addFlash("success", "Successfully deleted {$deleted} crop(s).");
        return $this->redirectToRoute("culture_index");
    }

    #[Route("/{id}/image", name: "culture_image", methods: ["GET"])]
    public function image(Culture $culture): Response
    {
        $blob = $culture->getImage();
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

    #[Route("/export/csv", name: "culture_export_csv", methods: ["GET"])]
    public function exportCsv(CultureRepository $cultureRepository): Response
    {
        $cultures = $cultureRepository->findAllWithParcelle();

        $rows = [];
        $rows[] = implode(",", [
            "ID",
            "Crop Name",
            "Variety",
            "Parcel",
            "Planting Date",
            "Expected Harvest",
            "Actual Harvest",
            "Qty Planted",
            "Qty Harvested",
            "Production Cost",
            "Yield",
            "Status",
            "Observations",
        ]);

        foreach ($cultures as $c) {
            $rows[] = implode(",", [
                $c->getId(),
                '"' .
                str_replace('"', '""', (string) $c->getNomCulture()) .
                '"',
                '"' .
                str_replace('"', '""', (string) ($c->getVariete() ?? "")) .
                '"',
                '"' .
                str_replace(
                    '"',
                    '""',
                    (string) ($c->getParcelle()?->getNom() ?? ""),
                ) .
                '"',
                $c->getDatePlantation()?->format("Y-m-d") ?? "",
                $c->getDateRecoltePrevue()?->format("Y-m-d") ?? "",
                $c->getDateRecolteReelle()?->format("Y-m-d") ?? "",
                $c->getQuantitePlantee(),
                $c->getQuantiteRecoltee(),
                $c->getCoutProduction(),
                $c->getRendement() ?? 0,
                $c->getStatut() ?? "",
                '"' .
                str_replace('"', '""', (string) ($c->getObservations() ?? "")) .
                '"',
            ]);
        }

        $csv = implode("\n", $rows);

        return new Response($csv, 200, [
            "Content-Type" => "text/csv; charset=UTF-8",
            "Content-Disposition" =>
                'attachment; filename="cultures_' . date("Y-m-d") . '.csv"',
        ]);
    }

    #[Route("/export/excel", name: "culture_export_excel", methods: ["GET"])]
    public function exportExcel(
        CultureRepository $cultureRepository,
    ): StreamedResponse {
        $cultures = $cultureRepository->findAllWithParcelle();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle("Cultures");

        $headers = [
            "ID",
            "Crop Name",
            "Variety",
            "Parcel",
            "Planting Date",
            "Expected Harvest",
            "Actual Harvest",
            "Qty Planted",
            "Qty Harvested",
            "Production Cost",
            "Yield (%)",
            "Status",
            "Observations",
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

        foreach ($cultures as $row => $c) {
            $rowNum = $row + 2;
            $sheet->setCellValue("A{$rowNum}", $c->getId());
            $sheet->setCellValue("B{$rowNum}", $c->getNomCulture());
            $sheet->setCellValue("C{$rowNum}", $c->getVariete() ?? "");
            $sheet->setCellValue(
                "D{$rowNum}",
                $c->getParcelle()?->getNom() ?? "",
            );
            $sheet->setCellValue(
                "E{$rowNum}",
                $c->getDatePlantation()?->format("Y-m-d") ?? "",
            );
            $sheet->setCellValue(
                "F{$rowNum}",
                $c->getDateRecoltePrevue()?->format("Y-m-d") ?? "",
            );
            $sheet->setCellValue(
                "G{$rowNum}",
                $c->getDateRecolteReelle()?->format("Y-m-d") ?? "",
            );
            $sheet->setCellValue("H{$rowNum}", $c->getQuantitePlantee());
            $sheet->setCellValue("I{$rowNum}", $c->getQuantiteRecoltee());
            $sheet->setCellValue("J{$rowNum}", $c->getCoutProduction());
            $sheet->setCellValue("K{$rowNum}", $c->getRendement() ?? 0);
            $sheet->setCellValue("L{$rowNum}", $c->getStatut() ?? "");
            $sheet->setCellValue("M{$rowNum}", $c->getObservations() ?? "");
        }

        foreach (range("A", "M") as $col) {
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
            'attachment; filename="cultures_' . date("Y-m-d") . '.xlsx"',
        );

        return $response;
    }

    #[Route("/import", name: "culture_import", methods: ["POST"])]
    public function import(
        Request $request,
        EntityManagerInterface $entityManager,
        ParcelleRepository $parcelleRepository,
        PixabayService $pixabay,
    ): Response {
        $file = $request->files->get("importFile");
        if (!$file) {
            $this->addFlash("error", "No file uploaded.");
            return $this->redirectToRoute("culture_index");
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
                if (!empty($rows)) {
                    $header = $this->normalizeCsvHeader($rows[0]);
                    for ($i = 1; $i < count($rows); $i++) {
                        $dataRows[] = array_combine(
                            $header,
                            array_pad($rows[$i], count($header), ""),
                        );
                    }
                }
            } else {
                $this->addFlash(
                    "error",
                    "Unsupported file format. Please upload a CSV or Excel file.",
                );
                return $this->redirectToRoute("culture_index");
            }

            foreach ($dataRows as $idx => $data) {
                $rowNum = $idx + 2;

                // Resolve parcelle by name
                $parcelName = trim(
                    (string) ($data["parcelname"] ??
                        ($data["parcelle"] ?? ($data["parcel"] ?? ""))),
                );
                if (!$parcelName) {
                    $errors[] = "Row {$rowNum}: Parcel name is required.";
                    continue;
                }

                $parcelle = null;
                foreach ($parcelleRepository->findAll() as $p) {
                    if (
                        strtolower(trim($p->getNom())) ===
                        strtolower($parcelName)
                    ) {
                        $parcelle = $p;
                        break;
                    }
                }

                if (!$parcelle) {
                    $errors[] = "Row {$rowNum}: Parcel '{$parcelName}' not found.";
                    continue;
                }

                $nomCulture = trim(
                    (string) ($data["cropname"] ??
                        ($data["nomculture"] ?? ($data["name"] ?? ""))),
                );
                if (!$nomCulture) {
                    $errors[] = "Row {$rowNum}: Crop name is required.";
                    continue;
                }

                $variete = trim(
                    (string) ($data["variety"] ??
                        ($data["variete"] ?? "Unknown")),
                );

                $c = new Culture();
                $c->setParcelle($parcelle);
                $c->setNomCulture($nomCulture);
                $c->setVariete($variete);

                $plantingStr = trim(
                    (string) ($data["plantingdate"] ??
                        ($data["dateplantation"] ?? "")),
                );
                $planting =
                    $plantingStr !== ""
                        ? $this->parseDate($plantingStr) ?? new \DateTime()
                        : new \DateTime();
                $c->setDatePlantation($planting);

                $harvestStr = trim(
                    (string) ($data["expectedharvestdate"] ??
                        ($data["daterecolteprevue"] ?? "")),
                );
                $harvest =
                    $harvestStr !== ""
                        ? $this->parseDate($harvestStr) ??
                            (clone $planting)->modify("+3 months")
                        : (clone $planting)->modify("+3 months");
                $c->setDateRecoltePrevue($harvest);

                $actualStr = trim(
                    (string) ($data["actualharvestdate"] ??
                        ($data["daterecolteReelle"] ?? "")),
                );
                $c->setDateRecolteReelle(
                    $actualStr !== "" ? $this->parseDate($actualStr) : null,
                );

                $qtyPlanted =
                    (float) ($data["quantityplanted"] ??
                        ($data["quantiteplantee"] ?? 0));
                $c->setQuantitePlantee($qtyPlanted > 0 ? $qtyPlanted : 1.0);

                $qtyHarvested =
                    (float) ($data["harvestedqty"] ??
                        ($data["quantiterecoltee"] ?? 0));
                $c->setQuantiteRecoltee(max(0.0, $qtyHarvested));

                $cost =
                    (float) ($data["productioncost"] ??
                        ($data["coutproduction"] ?? 0));
                // Guard: entity has @Assert\Positive so use at least 0.01
                $c->setCoutProduction($cost > 0 ? $cost : 0.01);

                // Auto-calculate yield
                $yieldVal =
                    $c->getQuantitePlantee() > 0
                        ? round(
                            ($c->getQuantiteRecoltee() /
                                $c->getQuantitePlantee()) *
                                100,
                            2,
                        )
                        : 0.0;
                $c->setRendement($yieldVal);

                $statutVal = trim(
                    (string) ($data["status"] ??
                        ($data["statut"] ?? "Planned")),
                );
                $c->setStatut(
                    in_array($statutVal, [
                        "Planned",
                        "In Progress",
                        "Harvested",
                    ])
                        ? $statutVal
                        : "Planned",
                );

                $c->setObservations(
                    trim(
                        (string) ($data["notes"] ??
                            ($data["observations"] ?? "")),
                    ),
                );

                // --- Pixabay: auto-fetch image based on crop name + variety ---
                $imageBlob = $pixabay->fetchImageBlob(
                    $pixabay->buildCultureQuery($nomCulture, $variete),
                    $idx % 5,
                );
                if ($imageBlob !== null) {
                    $c->setImage($imageBlob);
                }

                $entityManager->persist($c);
                $imported++;
            }

            $entityManager->flush();
            $this->addFlash(
                "success",
                "Successfully imported {$imported} crop(s)." .
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

        return $this->redirectToRoute("culture_index");
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
     * parentheses so that "Crop Name", "crop_name" and "CropName" all
     * become "cropname".
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
