<?php

namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;

class ContractPdfService
{
    /**
     * Generate a contract PDF from form data
     */
    public function generateContractPdf(array $data): string
    {
                $supplierName = $this->escape((string) ($data['supplier_name'] ?? 'N/A'));
                $startDate = $this->escape($this->formatDate((string) ($data['date_debut'] ?? '')));
                $endDate = $this->escape($this->formatDate((string) ($data['date_fin'] ?? '')));
                $type = $this->escape(ucfirst((string) ($data['type'] ?? 'N/A')));
                $status = $this->escape((string) ($data['statut'] ?? 'N/A'));
                $generatedDate = $this->escape(date('d/m/Y'));

                $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #111; margin: 24px; }
        .brand { text-align: center; color: #116530; font-size: 30px; font-weight: 700; }
        .subtitle { text-align: center; color: #4f6b38; margin-top: 4px; font-size: 12px; }
        .divider { border-bottom: 2px solid #116530; margin: 12px 0 16px; }
        h1 { text-align: center; color: #116530; font-size: 22px; margin: 0 0 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #d9e3d2; padding: 8px; font-size: 12px; text-align: left; }
        th { background: #f0f5eb; }
        .terms-title { margin-top: 14px; color: #116530; font-size: 14px; font-weight: 700; }
        .terms { font-size: 12px; line-height: 1.5; }
        .footer { margin-top: 18px; border-top: 1px solid #116530; padding-top: 8px; text-align: center; color: #4f6b38; font-size: 10px; }
    </style>
</head>
<body>
    <div class="brand">ELFIRMA</div>
    <div class="subtitle">Agriculture Supplier Management</div>
    <div class="divider"></div>
    <h1>CONTRACT AGREEMENT</h1>

    <table>
        <tr><th>Field</th><th>Details</th></tr>
        <tr><td>Supplier Name</td><td>{$supplierName}</td></tr>
        <tr><td>Contract Start Date</td><td>{$startDate}</td></tr>
        <tr><td>Contract End Date</td><td>{$endDate}</td></tr>
        <tr><td>Contract Type</td><td>{$type}</td></tr>
        <tr><td>Status</td><td>{$status}</td></tr>
        <tr><td>Generated Date</td><td>{$generatedDate}</td></tr>
    </table>

    <div class="terms-title">Contract Terms</div>
    <p class="terms">
        This contract establishes the terms and conditions between Elfirma and the supplier named above.
        Both parties agree to adhere to the specified dates, contract type, and status outlined in this agreement.
        This document serves as an official record of the contract initiation.
    </p>

    <div class="footer">
        Elfirma (c) 2026 - All Rights Reserved<br>
        This is an electronically generated document.
    </div>
</body>
</html>
HTML;

                return $this->renderPdfFromHtml($html);
    }

    /**
     * Generate a contract PDF from extracted image text
     */
    public function generatePdfFromExtractedText(array $data): string
    {
                $supplierName = $this->escape((string) ($data['supplier_name'] ?? 'N/A'));
                $extractedText = (string) ($data['extracted_text'] ?? 'No text content');
                if (trim($extractedText) === '') {
                        $extractedText = 'No text content';
                }
                $safeExtractedText = nl2br($this->escape($extractedText));
                $extractedAt = $this->escape(date('d/m/Y H:i:s'));

                $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #111; margin: 24px; }
        .brand { text-align: center; color: #116530; font-size: 30px; font-weight: 700; }
        .subtitle { text-align: center; color: #4f6b38; margin-top: 4px; font-size: 12px; }
        .divider { border-bottom: 2px solid #116530; margin: 12px 0 16px; }
        h1 { text-align: center; color: #116530; font-size: 22px; margin: 0 0 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #d9e3d2; padding: 8px; font-size: 12px; text-align: left; }
        th { background: #f0f5eb; }
        .content-title { margin-top: 14px; color: #116530; font-size: 14px; font-weight: 700; }
        .content-box { border: 1px solid #d9e3d2; background: #fff; padding: 10px; font-size: 12px; line-height: 1.5; min-height: 180px; }
        .footer { margin-top: 18px; border-top: 1px solid #116530; padding-top: 8px; text-align: center; color: #4f6b38; font-size: 10px; }
    </style>
</head>
<body>
    <div class="brand">ELFIRMA</div>
    <div class="subtitle">Agriculture Supplier Management</div>
    <div class="divider"></div>
    <h1>EXTRACTED CONTRACT DOCUMENT</h1>

    <table>
        <tr><th>Label</th><th>Value</th></tr>
        <tr><td>Extraction Date</td><td>{$extractedAt}</td></tr>
        <tr><td>Supplier Name</td><td>{$supplierName}</td></tr>
    </table>

    <div class="content-title">Extracted Content</div>
    <div class="content-box">{$safeExtractedText}</div>

    <div class="footer">
        Elfirma (c) 2026 - All Rights Reserved<br>
        This document was generated from extracted image content.
    </div>
</body>
</html>
HTML;

                return $this->renderPdfFromHtml($html);
    }

        private function renderPdfFromHtml(string $html): string
        {
                $options = new Options();
                $options->set('isRemoteEnabled', false);
                $options->set('defaultFont', 'DejaVu Sans');

                $dompdf = new Dompdf($options);
                $dompdf->loadHtml($html);
                $dompdf->setPaper('A4', 'portrait');
                $dompdf->render();

                return $dompdf->output();
        }

        private function escape(string $value): string
        {
                return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
