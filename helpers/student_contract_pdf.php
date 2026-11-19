<?php
declare(strict_types=1);

function pcvc_student_contract_language(mysqli $conn, int $contractId): string
{
    $column = $conn->query("SHOW COLUMNS FROM student_contracts LIKE 'contract_language'");
    if (!$column || $column->num_rows === 0) {
        if ($column) {
            $column->free();
        }

        return 'en';
    }
    $column->free();

    $stmt = $conn->prepare('SELECT contract_language FROM student_contracts WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return 'en';
    }
    $stmt->bind_param('i', $contractId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $language = strtolower(trim((string) ($row['contract_language'] ?? 'en')));

    return $language === 'fr' ? 'fr' : 'en';
}

function pcvc_generate_student_contract_pdf(mysqli $conn, int $contractId): string
{
    if (pcvc_student_contract_language($conn, $contractId) === 'fr') {
        require_once dirname(__DIR__) . '/generate-contract-pdf-french.php';
        if (!function_exists('generateContractPDFFrench')) {
            throw new RuntimeException('French PDF generator missing');
        }

        return generateContractPDFFrench($contractId);
    }

    require_once dirname(__DIR__) . '/generate-contract-pdf.php';
    if (!function_exists('generateContractPDF')) {
        throw new RuntimeException('PDF generator missing');
    }

    return generateContractPDF($contractId);
}
