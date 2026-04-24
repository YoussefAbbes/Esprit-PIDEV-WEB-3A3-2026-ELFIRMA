<?php

namespace App\Service;

use TCPDF;

class ContractPdfService
{
    /**
     * Generate a contract PDF from form data
     */
    public function generateContractPdf(array $data): string
    {
        // Create new PDF document
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

        // Set document properties
        $pdf->SetCreator('Elfirma');
        $pdf->SetAuthor('Elfirma Agriculture');
        $pdf->SetTitle('Contract Agreement');

        // Remove default header and footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Set margins
        $pdf->SetMargins(15, 15, 15);

        // Add page
        $pdf->AddPage();

        // Set font for header
        $pdf->SetFont('Helvetica', 'B', 24);
        $pdf->SetTextColor(16, 101, 48); // Elfirma green (#116530)

        // Header
        $pdf->Cell(0, 15, 'ELFIRMA', 0, 1, 'C');
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(79, 107, 56); // Darker green
        $pdf->Cell(0, 5, 'Agriculture Supplier Management', 0, 1, 'C');
        $pdf->Ln(5);

        // Line separator
        $pdf->SetDrawColor(16, 101, 48);
        $pdf->SetLineWidth(0.5);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(3);

        // Title
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->SetTextColor(16, 101, 48);
        $pdf->Cell(0, 10, 'CONTRACT AGREEMENT', 0, 1, 'C');
        $pdf->Ln(2);

        // Contract details
        $pdf->SetFont('Helvetica', '', 11);
        $pdf->SetTextColor(0, 0, 0);

        // Contract info table
        $tableData = [
            ['Field', 'Details'],
            ['Supplier Name', $data['supplier_name'] ?? 'N/A'],
            ['Contract Start Date', $this->formatDate($data['date_debut'] ?? '')],
            ['Contract End Date', $this->formatDate($data['date_fin'] ?? '')],
            ['Contract Type', ucfirst($data['type'] ?? 'N/A')],
            ['Status', $data['statut'] ?? 'N/A'],
            ['Generated Date', date('d/m/Y')],
        ];

        // Table styling
        $pdf->SetFillColor(240, 245, 235); // Light green background
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Helvetica', 'B', 10);

        // Header row
        $pdf->Cell(70, 8, $tableData[0][0], 1, 0, 'L', true);
        $pdf->Cell(105, 8, $tableData[0][1], 1, 1, 'L', true);

        // Data rows
        $pdf->SetFont('Helvetica', '', 10);
        $fill = false;
        for ($i = 1; $i < count($tableData); $i++) {
            if ($fill) {
                $pdf->SetFillColor(250, 252, 246);
            } else {
                $pdf->SetFillColor(255, 255, 255);
            }
            $pdf->Cell(70, 8, $tableData[$i][0], 1, 0, 'L', $fill);
            $pdf->Cell(105, 8, $tableData[$i][1], 1, 1, 'L', $fill);
            $fill = !$fill;
        }

        $pdf->Ln(5);

        // Terms section
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetTextColor(16, 101, 48);
        $pdf->Cell(0, 8, 'Contract Terms', 0, 1);

        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->MultiCell(0, 5,
            "This contract establishes the terms and conditions between Elfirma and the supplier named above. Both parties agree to adhere to the specified dates, contract type, and status as outlined in this agreement. This document serves as an official record of the contract initiation."
        );

        $pdf->Ln(10);

        // Footer line
        $pdf->SetDrawColor(16, 101, 48);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(3);

        // Footer text
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(79, 107, 56);
        $pdf->Cell(0, 5, 'Elfirma © 2026 - All Rights Reserved', 0, 1, 'C');
        $pdf->Cell(0, 5, 'This is an electronically generated document', 0, 1, 'C');

        // Return PDF as string
        return $pdf->Output('', 'S');
    }

    /**
     * Generate a contract PDF from extracted image text
     */
    public function generatePdfFromExtractedText(array $data): string
    {
        // Create new PDF document
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

        // Set document properties
        $pdf->SetCreator('Elfirma');
        $pdf->SetAuthor('Elfirma Agriculture');
        $pdf->SetTitle('Extracted Contract Document');

        // Remove default header and footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Set margins
        $pdf->SetMargins(15, 15, 15);

        // Add page
        $pdf->AddPage();

        // Set font for header
        $pdf->SetFont('Helvetica', 'B', 24);
        $pdf->SetTextColor(16, 101, 48); // Elfirma green (#116530)

        // Header
        $pdf->Cell(0, 15, 'ELFIRMA', 0, 1, 'C');
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(79, 107, 56); // Darker green
        $pdf->Cell(0, 5, 'Agriculture Supplier Management', 0, 1, 'C');
        $pdf->Ln(5);

        // Line separator
        $pdf->SetDrawColor(16, 101, 48);
        $pdf->SetLineWidth(0.5);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(3);

        // Title
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->SetTextColor(16, 101, 48);
        $pdf->Cell(0, 10, 'EXTRACTED CONTRACT DOCUMENT', 0, 1, 'C');
        $pdf->Ln(2);

        // Document metadata
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetTextColor(16, 101, 48);
        $pdf->Cell(0, 8, 'Document Information', 0, 1);

        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFillColor(240, 245, 235);

        // Information table
        $infoTable = [
            ['Label', 'Value'],
            ['Extraction Date', date('d/m/Y H:i:s')],
            ['Supplier Name', $data['supplier_name'] ?? 'N/A'],
        ];

        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(70, 8, $infoTable[0][0], 1, 0, 'L', true);
        $pdf->Cell(105, 8, $infoTable[0][1], 1, 1, 'L', true);

        $pdf->SetFont('Helvetica', '', 10);
        $fill = false;
        for ($i = 1; $i < count($infoTable); $i++) {
            if ($fill) {
                $pdf->SetFillColor(250, 252, 246);
            } else {
                $pdf->SetFillColor(255, 255, 255);
            }
            $pdf->Cell(70, 8, $infoTable[$i][0], 1, 0, 'L', $fill);
            $pdf->Cell(105, 8, $infoTable[$i][1], 1, 1, 'L', $fill);
            $fill = !$fill;
        }

        $pdf->Ln(8);

        // Extracted content section
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetTextColor(16, 101, 48);
        $pdf->Cell(0, 8, 'Extracted Content', 0, 1);

        // Content box
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetDrawColor(16, 101, 48);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetLineWidth(0.3);

        // Draw border for content
        $startY = $pdf->GetY();
        $contentHeight = 100; // Approximate height for content

        // Extract text content
        $extractedText = $data['extracted_text'] ?? 'No text content';
        if (empty($extractedText)) {
            $extractedText = 'No text content';
        }

        // Add extracted text with word wrapping
        $pdf->MultiCell(0, 5, $extractedText, 1);

        $pdf->Ln(5);

        // Footer line
        $pdf->SetDrawColor(16, 101, 48);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(3);

        // Footer text
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(79, 107, 56);
        $pdf->Cell(0, 5, 'Elfirma © 2026 - All Rights Reserved', 0, 1, 'C');
        $pdf->Cell(0, 5, 'This document was generated from extracted image content', 0, 1, 'C');

        // Return PDF as string
        return $pdf->Output('', 'S');
    }

    /**
     * Format date from YYYY-MM-DD to DD/MM/YYYY
     */
    private function formatDate(string $date): string
    {
        if (empty($date)) {
            return 'N/A';
        }

        try {
            $dateObj = new \DateTime($date);
            return $dateObj->format('d/m/Y');
        } catch (\Exception $e) {
            return 'N/A';
        }
    }
}
