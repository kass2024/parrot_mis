<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/professional-pdf-generator.php';
require_once __DIR__ . '/helpers/b2b_partnership_agreement.php';

class FrenchContractPDF extends ProfessionalPDFGenerator {

    protected function getMainContent(): string {
        return pcvc_b2b_agreement_body('fr', $this->contract, false);
    }
}

function generateProfessionalFrenchPDF(int $contractId): ?string {
    global $conn;
    $generator = new FrenchContractPDF($conn, $contractId);
    return $generator->generate();
}
