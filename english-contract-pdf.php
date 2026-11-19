<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/professional-pdf-generator.php';
require_once __DIR__ . '/helpers/b2b_partnership_agreement.php';

class EnglishContractPDF extends ProfessionalPDFGenerator {

    protected function getMainContent(): string {
        return pcvc_b2b_agreement_body('en', $this->contract, false);
    }
}

function generateProfessionalEnglishPDF(int $contractId): ?string {
    global $conn;
    $generator = new EnglishContractPDF($conn, $contractId);
    return $generator->generate();
}
