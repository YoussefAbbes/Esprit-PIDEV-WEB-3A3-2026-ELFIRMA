<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Categorie;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CategoryController extends AbstractController
{
    #[Route('/elfirma/categories', name: 'elfirma_categories', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $sort = (string) $request->query->get('sort', 'id-desc');

        $categories = $em->getRepository(Categorie::class)->findAll();
        $categories = $this->filterAndSortCategories($categories, $q, $sort);

        $totalCategories = count($categories);
        $totalProducts = 0;
        foreach ($categories as $category) {
            $totalProducts += $category->getProduits()->count();
        }

        $averagePerCategory = $totalCategories > 0
            ? round($totalProducts / $totalCategories, 1)
            : 0.0;

        return $this->render('elfirma/categories.html.twig', [
            'categories' => $categories,
            'category_stats' => [
                'total_categories' => $totalCategories,
                'total_products' => $totalProducts,
                'average_per_category' => $averagePerCategory,
            ],
            'filters' => [
                'q' => $q,
                'sort' => $sort,
            ],
        ]);
    }

    #[Route('/elfirma/categories/export/pdf', name: 'elfirma_categories_export_pdf', methods: ['GET'])]
    public function exportPdf(Request $request, EntityManagerInterface $em): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $sort = (string) $request->query->get('sort', 'id-desc');

        $categories = $em->getRepository(Categorie::class)->findAll();
        $categories = $this->filterAndSortCategories($categories, $q, $sort);

        $lines = [
            'ELFIRMA - Categories Report',
            'Generated: ' . (new \DateTimeImmutable())->format('Y-m-d H:i'),
            'Search: ' . ($q !== '' ? $q : '-'),
            'Sort: ' . $sort,
            '',
            'ID | Name | Products',
            str_repeat('-', 70),
        ];

        foreach ($categories as $category) {
            $lines[] = sprintf(
                '%d | %s | %d',
                (int) $category->getId(),
                $category->getNom() ?? '-',
                $category->getProduits()->count()
            );
        }

        $pdfBinary = $this->buildSimplePdf($lines);

        return new Response($pdfBinary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="categories-report.pdf"',
        ]);
    }

    #[Route('/elfirma/categorie/create', name: 'categorie_create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em): Response
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

            return $this->redirectToRoute('elfirma_categories');
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

        return $this->redirectToRoute('elfirma_categories');
    }

    #[Route('/elfirma/categorie/edit/{id}', name: 'categorie_edit', methods: ['POST'])]
    public function edit(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $categorie = $em->getRepository(Categorie::class)->find($id);
        if (!$categorie) {
            $this->addFlash('error', 'Category not found.');

            return $this->redirectToRoute('elfirma_categories');
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

            return $this->redirectToRoute('elfirma_categories');
        }

        try {
            $categorie->setNom($nom);
            $em->flush();
            $this->addFlash('success', 'Category updated successfully.');
        } catch (\Throwable $e) {
            $this->addFlash('form_errors_categorie_edit', ['_global' => ['Unable to update category right now.']]);
            $this->addFlash('form_old_categorie_edit', ['id' => $id, 'nom' => $nom]);
        }

        return $this->redirectToRoute('elfirma_categories');
    }

    #[Route('/elfirma/categorie/delete/{id}', name: 'categorie_delete', methods: ['POST'])]
    public function delete(int $id, EntityManagerInterface $em): Response
    {
        $categorie = $em->getRepository(Categorie::class)->find($id);
        if (!$categorie) {
            $this->addFlash('error', 'Category not found.');

            return $this->redirectToRoute('elfirma_categories');
        }

        if ($categorie->getProduits()->count() > 0) {
            $this->addFlash('error', 'Cannot delete a category that still contains products.');

            return $this->redirectToRoute('elfirma_categories');
        }

        try {
            $em->remove($categorie);
            $em->flush();
            $this->addFlash('success', 'Category deleted successfully.');
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Unable to delete category right now.');
        }

        return $this->redirectToRoute('elfirma_categories');
    }

    /**
     * @param list<Categorie> $categories
     *
     * @return list<Categorie>
     */
    private function filterAndSortCategories(array $categories, string $q, string $sort): array
    {
        if ($q !== '') {
            $needle = $this->normalizeFilterText($q);
            $categories = array_values(array_filter(
                $categories,
                function (Categorie $category) use ($needle): bool {
                    return str_contains($this->normalizeFilterText((string) $category->getNom()), $needle);
                }
            ));
        }

        usort($categories, static function (Categorie $a, Categorie $b) use ($sort): int {
            $aName = mb_strtolower((string) $a->getNom());
            $bName = mb_strtolower((string) $b->getNom());
            $aCount = $a->getProduits()->count();
            $bCount = $b->getProduits()->count();
            $aId = (int) $a->getId();
            $bId = (int) $b->getId();

            return match ($sort) {
                'name-asc' => $aName <=> $bName,
                'name-desc' => $bName <=> $aName,
                'products-asc' => $aCount <=> $bCount,
                'products-desc' => $bCount <=> $aCount,
                'id-asc' => $aId <=> $bId,
                default => $bId <=> $aId,
            };
        });

        return $categories;
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

    /**
     * @param list<string> $lines
     */
    private function buildSimplePdf(array $lines): string
    {
        $maxLines = 55;
        $lines = array_slice($lines, 0, $maxLines);

        $content = "BT\n/F1 10 Tf\n40 800 Td\n";
        foreach ($lines as $index => $line) {
            $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
            if ($index === 0) {
                $content .= sprintf('(%s) Tj\n', $escaped);
                continue;
            }
            $content .= sprintf('0 -14 Td (%s) Tj\n', $escaped);
        }
        $content .= "ET";

        $objects = [];
        $objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $objects[] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
        $objects[] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n";
        $objects[] = "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
        $objects[] = "5 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream\nendobj\n";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }

        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefPos . "\n%%EOF";

        return $pdf;
    }
}
