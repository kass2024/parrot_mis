<?php

function pcvc_caq_missing_doc_options(): array
{
    return [
        'cv_resume' => 'CV/Resume',
        'passport' => 'passport',
        'degree_transcript' => 'degree / Transcript',
        'loa' => 'LOA',
        'personal_statement' => 'Personal Statement',
        'signed_declaration' => 'Signed Form Of Declaration',
        'na' => 'N/A',
    ];
}

function pcvc_caq_form_options(): array
{
    return ['' => '—', 'Filled' => 'Filled', 'Sent' => 'Sent', 'N/A' => 'N/A'];
}

function pcvc_caq_signed_options(): array
{
    return ['' => '—', 'Yes' => 'Yes', 'No' => 'No'];
}

function pcvc_caq_submitted_options(): array
{
    return ['' => '—', 'Submitted' => 'Submitted', 'Re-submitted' => 'Re-submitted', 'Yes' => 'Yes', 'N/A' => 'N/A'];
}

function pcvc_caq_received_options(): array
{
    return ['' => '—', 'Received' => 'Received', 'Not yet / signature' => 'Not yet / signature', 'Error / not yet' => 'Error / not yet', 'Sent' => 'Sent'];
}

function pcvc_caq_ircc_options(): array
{
    return ['' => '—', 'Yes' => 'Yes', 'No' => 'No'];
}

function pcvc_caq_ensure_schema(mysqli $conn): void
{
    $sql = "CREATE TABLE IF NOT EXISTS caq_applications (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        full_name VARCHAR(200) NOT NULL,
        caq_form VARCHAR(40) NOT NULL DEFAULT '',
        signed_form VARCHAR(20) NOT NULL DEFAULT '',
        submitted_status VARCHAR(40) NOT NULL DEFAULT '',
        caq_received VARCHAR(80) NOT NULL DEFAULT '',
        missing_docs VARCHAR(255) NOT NULL DEFAULT '',
        comment VARCHAR(255) NOT NULL DEFAULT '',
        ircc_account VARCHAR(20) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_caq_full_name (full_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($sql);
}

function pcvc_caq_docs_to_keys(string $raw): array
{
    $allowed = pcvc_caq_missing_doc_options();
    $keys = [];
    foreach (preg_split('/\s*,\s*/', trim($raw)) as $part) {
        $part = strtolower(trim($part));
        if ($part !== '' && isset($allowed[$part])) {
            $keys[] = $part;
        }
    }
    return array_values(array_unique($keys));
}

function pcvc_caq_keys_to_docs(array $keys): string
{
    $clean = pcvc_caq_docs_to_keys(implode(',', $keys));
    if (in_array('na', $clean, true)) {
        $clean = ['na'];
    }
    return implode(',', $clean);
}
