<?php
declare(strict_types=1);

/**
 * German-language B2B agreements and the special-contract room.
 * German language issues a new sign link each time.
 * A special contract has one link, and that link can still be edited after signing.
 */

function pcvc_service_agreement_ensure_schema(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS service_agreements (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            kind VARCHAR(40) NOT NULL,
            catalog_key VARCHAR(80) DEFAULT NULL,
            contract_token CHAR(32) NOT NULL,
            editable_after_sign TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            company_name VARCHAR(255) DEFAULT NULL,
            company_email VARCHAR(255) DEFAULT NULL,
            company_phone VARCHAR(80) DEFAULT NULL,
            company_address TEXT,
            company_registration VARCHAR(160) DEFAULT NULL,
            representative_name VARCHAR(255) DEFAULT NULL,
            representative_title VARCHAR(255) DEFAULT NULL,
            governing_law VARCHAR(255) DEFAULT NULL,
            jurisdiction VARCHAR(255) DEFAULT NULL,
            signed_date DATE DEFAULT NULL,
            signature_image MEDIUMTEXT,
            annex_json TEXT NULL,
            language VARCHAR(16) NOT NULL DEFAULT 'english',
            pdf_path VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            signed_at DATETIME DEFAULT NULL,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_service_agreement_token (contract_token),
            KEY idx_service_agreement_catalog (catalog_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
        try {
            $uniqueCatalog = $conn->query("SHOW INDEX FROM service_agreements WHERE Key_name = 'uq_service_agreement_catalog'");
            if ($uniqueCatalog instanceof mysqli_result && $uniqueCatalog->num_rows > 0) {
                $conn->query('ALTER TABLE service_agreements DROP INDEX uq_service_agreement_catalog');
            }
            $catalogIndex = $conn->query("SHOW INDEX FROM service_agreements WHERE Key_name = 'idx_service_agreement_catalog'");
            if ($catalogIndex instanceof mysqli_result && $catalogIndex->num_rows === 0) {
                $conn->query('ALTER TABLE service_agreements ADD INDEX idx_service_agreement_catalog (catalog_key)');
            }
            $col = $conn->query("SHOW COLUMNS FROM service_agreements LIKE 'annex_json'");
            if ($col instanceof mysqli_result && $col->num_rows === 0) {
                $conn->query("ALTER TABLE service_agreements ADD COLUMN annex_json MEDIUMTEXT NULL");
            } elseif ($col instanceof mysqli_result) {
                $info = $col->fetch_assoc();
                if (is_array($info) && stripos((string) ($info['Type'] ?? ''), 'mediumtext') === false) {
                    $conn->query("ALTER TABLE service_agreements MODIFY annex_json MEDIUMTEXT NULL");
                }
            }
            $lang = $conn->query("SHOW COLUMNS FROM service_agreements LIKE 'language'");
            if ($lang instanceof mysqli_result && $lang->num_rows === 0) {
                $conn->query("ALTER TABLE service_agreements ADD COLUMN language VARCHAR(16) NOT NULL DEFAULT 'english'");
            }
        } catch (Throwable $e) {
            // The column is already there. Issuing a link must still continue.
        }
}

/**
 * Room of special contracts. Add another entry here when a new one-link agreement is needed.
 *
 * @return array<string, array{title:string,note:string,file:string,company_name:string,reference:string}>
 */
function pcvc_special_contract_catalog(): array
{
    return [
        'mutokambare' => [
            'title' => 'Mutokambare — Germany language',
            'note' => 'One sign link for Study and Work Abroad Expert Ltd. It can be edited after it is signed.',
            'file' => 'mutokambare-germany.txt',
            'company_name' => 'STUDY AND WORK ABROAD EXPERT LTD.',
            'reference' => 'PCVCCL/GER/',
        ],
        'cedrick' => [
            'title' => 'Cedrick — Study Canada funds agreement',
            'note' => 'One sign link for Sinziheba Cedrick. It stays separate from the other special contracts and can be edited after it is signed.',
            'file' => 'cedrick-study-canada.txt',
            'company_name' => 'SINZIHEBA CEDRICK',
            'reference' => 'PCVCCL/CA-CEDRICK/',
            'separate' => true,
            'layout' => 'document',
            'html' => 'cedrick-document.html',
            'page_title' => 'Study Canada Inclusive Service and Funds Management Agreement',
            'page_subtitle' => 'Sinziheba Cedrick — service and client funds administration',
            'representative_name' => 'SINZIHEBA CEDRICK',
            'representative_title' => 'Client',
            'company_email' => 'cedrikprattsinziheba@gmail.com',
            'company_phone' => '+250 788 290 473',
            'company_address' => 'Ngoma, Gishyita, Karongi District, Western Province, Rwanda',
            'governing_law' => 'Republic of Rwanda',
            'jurisdiction' => 'Republic of Rwanda',
        ],
        'ccn_burundi' => [
            'title' => 'CCN Burundi partnership (English)',
            'note' => 'English version. Create a separate sign link for each CCN agreement. A link can be signed only after every blank is filled.',
            'many' => true,
            'file' => 'ccn-burundi.html',
            'company_name' => 'CCN COMPANY',
            'reference' => 'PCVCCL/BI-CCN/',
            'separate' => true,
            'layout' => 'document',
            'html' => 'ccn-burundi.html',
            'page_title' => 'Burundi Representation Partnership and Client Referral Agreement',
            'page_subtitle' => 'CCN Company — Burundi representative',
            'representative_name' => 'CCN COMPANY',
            'representative_title' => 'Burundi Representative',
            'company_email' => 'infos@visaconsultantcanada.com',
            'company_phone' => '',
            'company_address' => 'Building Tropicana Net Café, Chaussée du Prince Louis Rwagasore, Bureau No. 2, Burundi',
            'governing_law' => 'Republic of Rwanda and Republic of Burundi',
            'jurisdiction' => 'Rwanda and Burundi',
        ],
        'ccn_burundi_fr' => [
            'title' => 'CCN Burundi partnership (French)',
            'note' => 'Version française. Créez un lien de signature distinct pour chaque convention. Elle ne peut être signée que lorsque toutes les cases sont remplies.',
            'many' => true,
            'file' => 'ccn-burundi-fr.html',
            'company_name' => 'CCN COMPANY',
            'reference' => 'PCVCCL/BI-CCN-FR/',
            'separate' => true,
            'layout' => 'document',
            'language' => 'french',
            'html' => 'ccn-burundi-fr.html',
            'page_title' => 'Convention de partenariat de représentation et de référencement de clients au Burundi',
            'page_subtitle' => 'CCN Company — représentant au Burundi',
            'representative_name' => 'CCN COMPANY',
            'representative_title' => 'Représentant au Burundi',
            'company_email' => 'infos@visaconsultantcanada.com',
            'company_phone' => '',
            'company_address' => 'Building Tropicana Net Café, Chaussée du Prince Louis Rwagasore, Bureau No. 2, Burundi',
            'governing_law' => 'République du Rwanda et République du Burundi',
            'jurisdiction' => 'Rwanda et Burundi',
        ],
    ];
}

function pcvc_service_agreement_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function pcvc_service_agreement_field(string $key, string $value, bool $live): string
{
    $shown = trim($value) !== '' ? trim($value) : '';
    if ($live) {
        return '<span data-mirror="' . pcvc_service_agreement_h($key) . '">' . pcvc_service_agreement_h($shown) . '</span>';
    }

    return pcvc_service_agreement_h($shown !== '' ? $shown : '—');
}

function pcvc_service_agreement_blank_key(string $label): string
{
    $label = function_exists('mb_strtolower')
        ? mb_strtolower(trim($label), 'UTF-8')
        : strtolower(trim($label));
    $map = [
        'represented by' => 'representative_name',
        'position' => 'representative_title',
        'registered address' => 'company_address',
        'company registration number' => 'company_registration',
        'email' => 'company_email',
        'telephone' => 'company_phone',
        "the partner's designated email" => 'company_email',
        'the partner email' => 'company_email',
        'governing law' => 'governing_law',
        'competent jurisdiction' => 'jurisdiction',
        'name' => 'representative_name',
        'date' => 'signed_date',
        'company registration or tin' => 'company_registration',
        'effective date' => 'signed_date',
        'candidate full name' => 'candidate_name',
        'nationality' => 'candidate_nationality',
        'passport number' => 'candidate_passport',
        'selected german language school' => 'language_school',
        'school location in germany' => 'school_location',
        'course level' => 'course_level',
        'german language course level' => 'course_level',
        'course start date' => 'course_start',
        'course duration' => 'course_duration',
        'school tuition fee' => 'school_tuition',
        'candidate reference number' => 'candidate_reference',
        'date of acceptance' => 'acceptance_date',
        'authorized parrot representative' => 'parrot_representative',
        'authorized partner representative' => 'partner_representative',
        'représenté par' => 'representative_name',
        'fonction' => 'representative_title',
        'adresse enregistrée' => 'company_address',
        "numéro d'immatriculation" => 'company_registration',
        'téléphone' => 'company_phone',
        'courriel du partenaire' => 'company_email',
        'droit applicable' => 'governing_law',
        'juridiction compétente' => 'jurisdiction',
        "date d'entrée en vigueur" => 'signed_date',
        'nom' => 'representative_name',
        'nom complet du candidat' => 'candidate_name',
        'nationalité' => 'candidate_nationality',
        'numéro de passeport' => 'candidate_passport',
        'ecole de langue allemande choisie' => 'language_school',
        "école de langue allemande choisie" => 'language_school',
        "lieu de l'école en allemagne" => 'school_location',
        'niveau du cours' => 'course_level',
        'date de début du cours' => 'course_start',
        'durée du cours' => 'course_duration',
        "frais de scolarité de l'école" => 'school_tuition',
        'numéro de référence du candidat' => 'candidate_reference',
        "date d'acceptation" => 'acceptance_date',
        'représentant autorisé de parrot' => 'parrot_representative',
        'représentant autorisé du partenaire' => 'partner_representative',
    ];

    return $map[strtolower(trim($label))] ?? '';
}

/** @return list<string> */
function pcvc_service_agreement_annex_keys(): array
{
    return [
        'candidate_name',
        'candidate_nationality',
        'candidate_passport',
        'language_school',
        'school_location',
        'course_level',
        'course_start',
        'course_duration',
        'school_tuition',
        'candidate_reference',
        'acceptance_date',
        'parrot_representative',
        'partner_representative',
    ];
}

/**
 * @param array<string, mixed> $row
 */
function pcvc_service_agreement_body(array $row, bool $live, bool $annexOnly = false, bool $beforeSignature = false): string
{
    $language = strtolower((string) ($row['language'] ?? 'english'));
    $file = $row['kind'] === 'special'
        ? (pcvc_special_contract_catalog()[(string) ($row['catalog_key'] ?? '')]['file'] ?? '')
        : ($language === 'french' ? 'german-language-french.txt' : 'german-language.txt');
    $path = __DIR__ . '/agreements/' . $file;
    if ($file === '' || !is_file($path)) {
        return '<p>This agreement text is not available.</p>';
    }
    $text = (string) file_get_contents($path);
    $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
    $html = '';
    $reference = $row['kind'] === 'special'
        ? (pcvc_special_contract_catalog()[(string) $row['catalog_key']]['reference'] ?? 'PCVCCL/GER/')
        : 'PCVCCL/GERMAN-LANGUAGE/';
    $reference .= str_pad((string) ((int) ($row['id'] ?? 0)), 4, '0', STR_PAD_LEFT);
    $started = $annexOnly;
    $inSignature = false;

    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '') {
            continue;
        }
        if (!$started && (stripos($trim, 'BETWEEN') === 0 || stripos($trim, 'ENTRE') === 0 || stripos($trim, 'Agreement Reference') === 0 || stripos($trim, 'Référence de') === 0)) {
            $started = true;
        }
        if (!$started) {
            continue;
        }
        $isAnnexA = (bool) preg_match('/^ANNEXE? A\b/iu', $trim);
        $isAnnex = (bool) preg_match('/^ANNEXE?\b/iu', $trim);
        if ($isAnnexA) {
            $inSignature = false;
            if ($beforeSignature) {
                break;
            }
        }
        if ($annexOnly && !$isAnnexA && !isset($seenAnnex)) {
            if (!$isAnnex) {
                continue;
            }
        }
        if ($isAnnexA) {
            $seenAnnex = true;
        }
        if ($annexOnly && empty($seenAnnex)) {
            continue;
        }
        if ($beforeSignature && (stripos($trim, 'FOR PARTY A') === 0 || stripos($trim, 'POUR LA PARTIE A') === 0 || stripos($trim, 'ACCEPTANCE AND SIGNATURES') !== false || stripos($trim, 'ACCEPTATION ET SIGNATURES') !== false)) {
            $inSignature = true;
        }
        if ($beforeSignature && $inSignature) {
            continue;
        }

        if (preg_match("/^(Agreement Reference|Référence de l'accord):\\s*.+$/u", $trim, $refMatch)) {
            $html .= '<p><strong>' . pcvc_service_agreement_h($refMatch[1]) . ':</strong> ' . pcvc_service_agreement_h($reference) . '</p>';
            continue;
        }

        if (preg_match('/^(.+?):\s*_{3,}\s*$/u', $trim, $m)) {
            $key = pcvc_service_agreement_blank_key($m[1]);
            if ($key !== '') {
                $value = (string) ($row[$key] ?? '');
                $control = in_array($key, pcvc_service_agreement_annex_keys(), true) && $live
                    ? '<input class="annex-field" id="' . pcvc_service_agreement_h($key) . '" value="' . pcvc_service_agreement_h($value) . '">'
                    : pcvc_service_agreement_field($key, $value, $live);
                $html .= '<p>' . pcvc_service_agreement_h($m[1]) . ': ' . $control . '</p>';
                continue;
            }
        }

        $rendered = pcvc_service_agreement_h($trim);
        $company = trim((string) ($row['company_name'] ?? ''));
        $companyHtml = pcvc_service_agreement_field('company_name', $company, $live);
        $rendered = str_replace(pcvc_service_agreement_h('STUDY AND WORK ABROAD EXPERT LTD.'), $companyHtml, $rendered);

        if (preg_match('/^(ARTICLE \d+|ANNEXE? [A-Z])\b/u', $trim)) {
            $html .= '<h2>' . $rendered . '</h2>';
            continue;
        }
        if (preg_match('/^\d+\.\d+\s/u', $trim)) {
            $html .= '<h3>' . $rendered . '</h3>';
            continue;
        }
        $html .= '<p>' . $rendered . '</p>';
    }

    return $html;
}

/**
 * @return array<string, mixed>|null
 */
function pcvc_service_agreement_by_token(mysqli $conn, string $token): ?array
{
    $stmt = $conn->prepare('SELECT * FROM service_agreements WHERE contract_token = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return is_array($row) ? pcvc_service_agreement_hydrate($row) : null;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function pcvc_service_agreement_hydrate(array $row): array
{
    $extra = json_decode((string) ($row['annex_json'] ?? ''), true);
    if (!is_array($extra)) {
        return $row;
    }
    foreach (pcvc_service_agreement_annex_keys() as $key) {
        if (isset($extra[$key]) && is_scalar($extra[$key])) {
            $row[$key] = (string) $extra[$key];
        }
    }

    return $row;
}

/**
 * @param array<string, mixed> $data
 */
function pcvc_service_agreement_annex_json(array $data, string $existingJson = ''): string
{
    $annex = json_decode($existingJson, true);
    if (!is_array($annex)) {
        $annex = [];
    }
    $germanKeys = pcvc_german_student_field_keys();
    $useGerman = array_key_exists('student_name', $data) || array_key_exists('student_signature', $data);
    $documentKeys = array_values(array_unique(array_merge(pcvc_cedrick_field_keys(), pcvc_ccn_field_keys())));
    $useDocument = false;
    if (!$useGerman) {
        foreach ($documentKeys as $key) {
            if (array_key_exists($key, $data)) {
                $useDocument = true;
                break;
            }
        }
    }
    $keys = $useGerman ? $germanKeys : ($useDocument ? $documentKeys : pcvc_service_agreement_annex_keys());
    foreach ($keys as $key) {
        if (!array_key_exists($key, $data)) {
            continue;
        }
        $value = trim((string) $data[$key]);
        $kept = (string) ($annex[$key] ?? '');
        if ($value === '' && str_ends_with($key, '_signature') && str_starts_with($kept, 'data:image/')) {
            continue;
        }
        $annex[$key] = $value;
    }
    if ($useDocument || $useGerman) {
        unset($annex['co_signature'], $annex['parrot_signature']);
    }

    return json_encode($annex, JSON_UNESCAPED_UNICODE) ?: '{}';
}

/** @return list<string> */
function pcvc_cedrick_field_keys(): array
{
    return [
        'company_tin',
        'registered_office',
        'effective_date',
        'co_rep_name',
        'co_title',
        'client_contact',
        'co_signature',
        'client_signature',
        'co_datetime',
        'client_datetime',
        'co_place',
        'client_place',
        'wit_co_name',
        'wit_cl_name',
        'wit_co_id',
        'wit_cl_id',
        'wit_co_phone',
        'wit_cl_phone',
        'wit_co_signature',
        'wit_cl_signature',
        'wit_co_date',
        'wit_cl_date',
        'co_initials',
        'client_initials',
    ];
}

/** @return list<string> */
function pcvc_ccn_field_keys(): array
{
    return [
        'parrot_tin',
        'parrot_office',
        'ccn_registration',
        'ccn_contact',
        'effective_date',
        'parrot_rep',
        'ccn_rep',
        'parrot_title',
        'ccn_title',
        'parrot_reg',
        'ccn_reg',
        'parrot_signature',
        'ccn_signature',
        'parrot_date',
        'parrot_place',
        'ccn_date',
        'ccn_place',
        'dispute_forum',
        'parrot_initials',
        'ccn_initials',
    ];
}

/** @return list<string> */
function pcvc_ccn_required_field_keys(): array
{
    return array_values(array_filter(
        pcvc_ccn_field_keys(),
        static fn (string $key): bool => $key !== 'parrot_signature' && $key !== 'ccn_signature'
    ));
}

/** @return list<string> */
function pcvc_ccn_fr_required_field_keys(): array
{
    $skip = ['parrot_signature', 'ccn_signature', 'dispute_forum', 'parrot_initials', 'ccn_initials'];

    return array_values(array_filter(
        pcvc_ccn_field_keys(),
        static fn (string $key): bool => !in_array($key, $skip, true)
    ));
}

/** @return list<string> */
function pcvc_german_student_field_keys(): array
{
    return [
        'effective_date',
        'parrot_rep',
        'parrot_title',
        'parrot_date',
        'parrot_place',
        'student_name',
        'student_dob',
        'nationality',
        'passport',
        'address',
        'email',
        'phone',
        'guardian',
        'governing_law',
        'jurisdiction',
        'student_date',
        'student_place',
        'student_signature',
        'school',
        'school_location',
        'course_level',
        'course_start',
        'course_duration',
        'school_tuition',
        'blocked_account',
        'student_reference',
        'payment_method',
        'initial_payment_date',
    ];
}

/** @return list<string> */
function pcvc_german_student_required_field_keys(): array
{
    $skip = ['student_signature', 'guardian'];

    return array_values(array_filter(
        pcvc_german_student_field_keys(),
        static fn (string $key): bool => !in_array($key, $skip, true)
    ));
}

/**
 * @param array<string, mixed> $row
 * @return array<string, string>
 */
function pcvc_cedrick_values(array $row): array
{
    $extra = json_decode((string) ($row['annex_json'] ?? ''), true);
    $values = [];
    if (is_array($extra)) {
        foreach (array_merge(pcvc_cedrick_field_keys(), pcvc_ccn_field_keys(), pcvc_german_student_field_keys()) as $key) {
            if (isset($extra[$key]) && is_scalar($extra[$key])) {
                $values[$key] = (string) $extra[$key];
            }
        }
    }
    if (($values['company_tin'] ?? '') === '' && trim((string) ($row['company_registration'] ?? '')) !== '') {
        $values['company_tin'] = (string) $row['company_registration'];
    }
    $signature = (string) ($row['signature_image'] ?? '');
    if (!str_starts_with($signature, 'data:image/png;base64,')) {
        $signature = '';
    }
    if ($signature !== '' && in_array((string) ($row['catalog_key'] ?? ''), ['ccn_burundi', 'ccn_burundi_fr'], true) && ($values['ccn_signature'] ?? '') === '') {
        $values['ccn_signature'] = $signature;
    }
    if ($signature !== '' && ($row['catalog_key'] ?? '') === 'cedrick' && ($values['client_signature'] ?? '') === '') {
        $values['client_signature'] = $signature;
    }
    if (($row['kind'] ?? '') === 'german_language') {
        $signedDay = substr(trim((string) ($row['signed_date'] ?? '')), 0, 10);
        $fallbacks = [
            'student_name' => trim((string) ($row['company_name'] ?? '')),
            'email' => trim((string) ($row['company_email'] ?? '')),
            'phone' => trim((string) ($row['company_phone'] ?? '')),
            'address' => trim((string) ($row['company_address'] ?? '')),
            'governing_law' => trim((string) ($row['governing_law'] ?? '')),
            'jurisdiction' => trim((string) ($row['jurisdiction'] ?? '')),
            'student_date' => $signedDay,
            'parrot_date' => $signedDay,
            'effective_date' => $signedDay,
        ];
        foreach ($fallbacks as $key => $fallback) {
            if (($values[$key] ?? '') === '' && $fallback !== '') {
                $values[$key] = $fallback;
            }
        }
        if ($signature !== '' && ($values['student_signature'] ?? '') === '') {
            $values['student_signature'] = $signature;
        }
    }
    if (in_array((string) ($row['catalog_key'] ?? ''), ['ccn_burundi', 'ccn_burundi_fr'], true)) {
        $fallbacks = [
            'ccn_rep' => trim((string) ($row['representative_name'] ?? '')),
            'ccn_title' => trim((string) ($row['representative_title'] ?? '')),
            'ccn_registration' => trim((string) ($row['company_registration'] ?? '')),
            'ccn_reg' => trim((string) ($row['company_registration'] ?? '')),
            'ccn_contact' => trim((string) (($row['company_email'] ?? '') !== '' ? $row['company_email'] : ($row['company_phone'] ?? ''))),
            'ccn_date' => substr(trim((string) ($row['signed_date'] ?? '')), 0, 10),
            'parrot_date' => substr(trim((string) ($row['signed_date'] ?? '')), 0, 10),
            'effective_date' => substr(trim((string) ($row['signed_date'] ?? '')), 0, 10),
        ];
        foreach ($fallbacks as $key => $fallback) {
            if (($values[$key] ?? '') === '' && $fallback !== '') {
                $values[$key] = $fallback;
            }
        }
    }
    $parrotDefaults = [
        'parrot_rep' => 'Dr. Jean Pierre Twajamahoro',
        'co_rep_name' => 'Dr. Jean Pierre Twajamahoro',
        'parrot_title' => 'Owner and Managing Director',
        'co_title' => 'Owner and Managing Director',
        'parrot_reg' => '122190556',
        'parrot_tin' => '122190556',
        'company_tin' => '122190556',
        'parrot_office' => 'GISOZI, KIGALI RWANDA',
        'registered_office' => 'GISOZI, KIGALI RWANDA',
        'parrot_place' => 'GISOZI, KIGALI RWANDA',
        'co_place' => 'GISOZI, KIGALI RWANDA',
    ];
    foreach ($parrotDefaults as $key => $fallback) {
        if (trim((string) ($values[$key] ?? '')) === '') {
            $values[$key] = $fallback;
        }
    }

    return $values;
}

function pcvc_cedrick_document_css(): string
{
    return <<<'CSS'
body { margin: 0; background: #fff; color: #000; font-family: "Times New Roman", Times, serif; font-size: 12pt; line-height: 1.5; }
.doc-head { text-align: center; font-weight: 700; font-size: 12pt; color: #505050; margin: 0 0 2pt; }
.doc-title { font-weight: 700; text-align: center; font-size: 12pt; margin: 0 0 8pt; padding-bottom: 3pt; border-bottom: 1.5pt solid #4F81BD; }
p { margin: 0 0 2pt; font-size: 12pt; line-height: 1.5; }
p.center { text-align: center; }
p.just { text-align: justify; }
p.doc-h { font-weight: 700; margin-top: 10pt; }
p.doc-li { margin-left: 0.35in; text-indent: -0.2in; }
p.doc-li:before { content: "• "; }
.doc-table { width: 100%; border-collapse: collapse; margin: 8pt 0 10pt; }
.doc-table td { border: 1px solid #D9D9D9; padding: 5.5pt; vertical-align: middle; font-size: 12pt; line-height: 1.5; }
.doc-table p { margin: 0; }
input.doc-field, input.doc-when { display: block; width: 100%; max-width: 100%; box-sizing: border-box; margin-top: 4px; min-height: 30px; padding: 4px 8px; border: 1px solid #94a3b8; border-radius: 4px; background: #fffef5; font-family: "Times New Roman", Times, serif; font-size: 12pt; line-height: 1.3; color: #111; cursor: text; }
p.center input.doc-field, p.center input.doc-when { display: inline-block; width: 2.3in; margin: 0 0 0 6px; vertical-align: middle; }
input.doc-field:focus, input.doc-when:focus { outline: 2px solid #166534; background: #fff; border-color: #166534; }
.doc-foot input.doc-field { display: inline-block; width: 1.2in; min-height: 24px; margin-top: 0; }
.sig-block { display: block; margin-top: 4px; max-width: 2.7in; }
canvas.signpad { display: block; width: 2.55in; max-width: 100%; height: 92px; border: 1px dashed #166534; border-radius: 6px; background: #f0fdf4; touch-action: none; cursor: crosshair; }
.sig-hint { display: block; color: #166534; font-family: "Segoe UI", sans-serif; font-size: 10px; margin-top: 2px; }
button.sig-clear, button.wit-email { font-family: "Segoe UI", sans-serif; font-size: 11px; font-weight: 700; border-radius: 6px; padding: 4px 8px; cursor: pointer; margin-top: 4px; }
button.sig-clear { background: #fff; color: #334155; border: 1px solid #cbd5e1; }
button.wit-email { display: block; background: #166534; color: #fff; border: 0; }
.wit-status { display: block; font-family: "Segoe UI", sans-serif; font-size: 11px; color: #166534; margin-top: 3px; }
img.doc-sig { display: block; height: 70px; max-width: 2.55in; margin-top: 4px; }
.parrot-sign { display: block; margin-top: 4px; }
img.parrot-sig { height: 64px; max-width: 180px; vertical-align: middle; }
img.parrot-stamp { height: 108px; max-width: 140px; vertical-align: middle; margin-left: 10px; }
.doc-line { display: inline-block; width: 2.15in; border-bottom: 1px solid #222; height: 1em; vertical-align: bottom; }
.doc-mirror { font-weight: 700; }
.doc-table td[style*="243746"], .doc-table td[style*="243746"] p, .doc-table td[style*="243746"] strong { color: #fff; }
.doc-foot { margin-top: 16pt; text-align: center; color: #646464; font-size: 12pt; border-top: 1px solid #d0d0d0; padding-top: 6pt; }
CSS;
}

/**
 * @param array<string, mixed> $row
 */
function pcvc_cedrick_document_html(array $row, bool $live, string $active = 'client'): string
{
    if (($row['kind'] ?? '') === 'german_language') {
        $file = strtolower((string) ($row['language'] ?? '')) === 'french'
            ? 'german-student-fr.html'
            : 'german-student.html';
    } else {
        $catalogKey = (string) ($row['catalog_key'] ?? 'cedrick');
        $meta = pcvc_special_contract_catalog()[$catalogKey] ?? [];
        $file = (string) ($meta['html'] ?? 'cedrick-document.html');
    }
    if (!preg_match('/^[a-z0-9.-]+$/', $file)) {
        $file = 'cedrick-document.html';
    }
    $path = __DIR__ . '/agreements/' . $file;
    $html = is_file($path) ? (string) file_get_contents($path) : '';
    if ($html === '') {
        return '<p>This agreement text is not available.</p>';
    }
    $values = pcvc_cedrick_values($row);
    $html = str_replace(
        '{{ref}}',
        htmlspecialchars('PCVCCL/GERMAN-STUDENT/' . str_pad((string) ((int) ($row['id'] ?? 0)), 4, '0', STR_PAD_LEFT), ENT_QUOTES, 'UTF-8'),
        $html
    );
    $seen = [];
    $dateKeys = ['effective_date', 'parrot_date', 'ccn_date', 'student_date', 'student_dob', 'course_start', 'initial_payment_date'];
    $autoDates = ['effective_date', 'parrot_date', 'ccn_date', 'student_date'];
    $optionalKeys = ['guardian' => true];
    $placeholder = strtolower((string) ($row['language'] ?? '')) === 'french' ? 'Écrire ici' : 'Type here';
    $drawHint = $placeholder === 'Écrire ici' ? 'Dessinez la signature ici' : 'Draw signature here';
    $clearLabel = $placeholder === 'Écrire ici' ? 'Effacer' : 'Clear';
    $rendered = preg_replace_callback(
        '/\{\{(in|sign):([a-z0-9_]+)\}\}/',
        function (array $match) use ($values, $live, $active, &$seen, $dateKeys, $autoDates, $optionalKeys, $placeholder, $drawHint, $clearLabel): string {
            $key = $match[2];
            $value = $values[$key] ?? '';
            if ($match[1] === 'in' && $live && isset($seen[$key])) {
                return '<span class="doc-mirror" data-mirror="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '"></span>';
            }
            if ($match[1] === 'in' && $live) {
                $seen[$key] = true;
            }
            if ($match[1] === 'sign') {
                if ($key === 'co_signature' || $key === 'parrot_signature') {
                    return pcvc_cedrick_parrot_sign_html();
                }
                $img = '';
                if (str_starts_with($value, 'data:image/png;base64,')) {
                    $img = '<img class="doc-sig" alt="" src="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">';
                }
                $owner = $key === 'client_signature' || $key === 'ccn_signature' || $key === 'student_signature' ? 'client' : ($key === 'wit_co_signature' ? 'wit_co' : ($key === 'wit_cl_signature' ? 'wit_cl' : ''));
                $isActive = $live && $owner !== '' && $owner === $active;
                if (!$isActive) {
                    $waiting = '';
                    if ($live && $active === 'client' && ($owner === 'wit_co' || $owner === 'wit_cl')) {
                        $waiting = ($img === '' ? '<span class="sig-hint">This witness signs after opening the email.</span>' : '')
                            . '<button type="button" class="wit-email" data-role="' . $owner . '">Email this witness to sign</button>'
                            . '<span class="wit-status" data-role="' . $owner . '"></span>';
                    }

                    return ($img !== '' ? $img : '<span class="doc-line"></span>') . $waiting;
                }
                $safeKey = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');

                return '<span class="sig-block">' . $img
                    . '<canvas class="signpad" data-sign="' . $safeKey . '"></canvas>'
                    . '<span class="sig-hint">' . htmlspecialchars($drawHint, ENT_QUOTES, 'UTF-8') . '</span>'
                    . '<button type="button" class="sig-clear">' . htmlspecialchars($clearLabel, ENT_QUOTES, 'UTF-8') . '</button>'
                    . '</span>';
            }
            $safe = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            if (in_array($key, $dateKeys, true)) {
                $when = trim($value);
                $valid = preg_match('/^\d{4}-\d{2}-\d{2}/', $when) === 1;
                if ($valid) {
                    $when = substr($when, 0, 10);
                } elseif (in_array($key, $autoDates, true)) {
                    $when = (new DateTime('now', new DateTimeZone('Africa/Kigali')))->format('Y-m-d');
                    $valid = true;
                }
                if (!$live) {
                    if (!$valid) {
                        return '<span class="doc-line"></span>';
                    }
                    $day = DateTime::createFromFormat('Y-m-d', $when);
                    $label = $day instanceof DateTime ? $day->format('j F Y') : $when;
                    return htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
                }

                return '<input class="doc-field doc-when" type="date" id="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($valid ? $when : '', ENT_QUOTES, 'UTF-8') . '">';
            }
            if ($key === 'co_datetime' || $key === 'client_datetime') {
                $when = pcvc_cedrick_datetime_value($value);
                if (!$live) {
                    return htmlspecialchars(pcvc_cedrick_datetime_label($when), ENT_QUOTES, 'UTF-8');
                }

                return '<input class="doc-field doc-when" type="datetime-local" id="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($when, ENT_QUOTES, 'UTF-8') . '">';
            }
            if (!$live) {
                return $safe !== '' ? $safe : '<span class="doc-line"></span>';
            }

            $optional = isset($optionalKeys[$key]) ? ' data-optional="1"' : '';

            return '<input class="doc-field" id="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '"' . $optional . ' placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '" value="' . $safe . '">';
        },
        $html
    );

    return is_string($rendered) ? $rendered : $html;
}

function pcvc_cedrick_image_src(string $path): string
{
    if (!is_file($path)) {
        return '';
    }
    $raw = (string) file_get_contents($path);
    if ($raw === '') {
        return '';
    }

    return 'data:image/png;base64,' . base64_encode($raw);
}

function pcvc_cedrick_datetime_value(string $value): string
{
    $value = trim($value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value) === 1) {
        return substr($value, 0, 16);
    }
    $moment = new DateTime('now', new DateTimeZone('Africa/Kigali'));

    return $moment->format('Y-m-d\TH:i');
}

function pcvc_cedrick_datetime_label(string $value): string
{
    $moment = DateTime::createFromFormat('Y-m-d\TH:i', substr($value, 0, 16));
    if (!$moment instanceof DateTime) {
        return $value;
    }

    return $moment->format('j F Y, H:i');
}

function pcvc_cedrick_parrot_sign_html(): string
{
    $sig = pcvc_cedrick_image_src(__DIR__ . '/../admin/signature-manager.png');
    $stamp = pcvc_cedrick_image_src(__DIR__ . '/../admin/employer-signature.png');
    if ($sig === '' && $stamp === '') {
        return '<span class="doc-line"></span>';
    }
    $html = '<span class="parrot-sign">';
    if ($sig !== '') {
        $html .= '<img class="parrot-sig" alt="Parrot signature" src="' . $sig . '">';
    }
    if ($stamp !== '') {
        $html .= '<img class="parrot-stamp" alt="Company stamp" src="' . $stamp . '">';
    }

    return $html . '</span>';
}

function pcvc_cedrick_merge_annex(string $previous, string $next): string
{
    $old = json_decode($previous, true);
    $new = json_decode($next, true);
    if (!is_array($new)) {
        return $next;
    }
    if (!is_array($old)) {
        $old = [];
    }
    foreach (['client_signature', 'wit_co_signature', 'wit_cl_signature', 'wit_co_token', 'wit_cl_token'] as $key) {
        $incoming = trim((string) ($new[$key] ?? ''));
        $kept = trim((string) ($old[$key] ?? ''));
        if ($incoming === '' && $kept !== '') {
            $new[$key] = $kept;
        }
    }
    unset($new['co_signature']);

    return json_encode($new, JSON_UNESCAPED_UNICODE) ?: $next;
}

/**
 * @return list<array<string, mixed>>
 */
function pcvc_service_agreement_special_rows(mysqli $conn, string $catalogKey): array
{
    pcvc_service_agreement_ensure_schema($conn);
    $stmt = $conn->prepare("SELECT * FROM service_agreements WHERE kind = 'special' AND catalog_key = ? ORDER BY id DESC");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('s', $catalogKey);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $rows[] = pcvc_service_agreement_hydrate($row);
    }
    $stmt->close();

    return $rows;
}

/**
 * @return array<string, mixed>|null
 */
function pcvc_service_agreement_insert_special(mysqli $conn, string $catalogKey): ?array
{
    $catalog = pcvc_special_contract_catalog();
    if (!isset($catalog[$catalogKey])) {
        return null;
    }
    pcvc_service_agreement_ensure_schema($conn);
    $token = bin2hex(random_bytes(16));
    $meta = $catalog[$catalogKey];
    $company = (string) $meta['company_name'];
    $rep = (string) ($meta['representative_name'] ?? '');
    $title = (string) ($meta['representative_title'] ?? '');
    $email = (string) ($meta['company_email'] ?? '');
    $phone = (string) ($meta['company_phone'] ?? '');
    $address = (string) ($meta['company_address'] ?? '');
    $law = (string) ($meta['governing_law'] ?? '');
    $jurisdiction = (string) ($meta['jurisdiction'] ?? '');
    $stmt = $conn->prepare(
        "INSERT INTO service_agreements
        (kind, catalog_key, contract_token, editable_after_sign, status, company_name,
         representative_name, representative_title, company_email, company_phone, company_address,
         governing_law, jurisdiction, created_at)
        VALUES ('special', ?, ?, 1, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ssssssssss', $catalogKey, $token, $company, $rep, $title, $email, $phone, $address, $law, $jurisdiction);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) {
        return null;
    }

    return pcvc_service_agreement_by_token($conn, $token);
}

/**
 * Create the single special-contract link the first time it is opened.
 * Agreements marked many are issued from the admin page instead.
 *
 * @return array<string, mixed>|null
 */
function pcvc_service_agreement_special(mysqli $conn, string $catalogKey): ?array
{
    $catalog = pcvc_special_contract_catalog();
    if (!isset($catalog[$catalogKey])) {
        return null;
    }
    if (!empty($catalog[$catalogKey]['many'])) {
        $rows = pcvc_service_agreement_special_rows($conn, $catalogKey);

        return $rows[0] ?? null;
    }
    pcvc_service_agreement_ensure_schema($conn);
    $stmt = $conn->prepare('SELECT * FROM service_agreements WHERE catalog_key = ? ORDER BY id ASC LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $catalogKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (is_array($row)) {
        return pcvc_service_agreement_hydrate($row);
    }

    return pcvc_service_agreement_insert_special($conn, $catalogKey);
}

/**
 * @return array<string, array{label:string,name:string,id:string,phone:string,date:string,signature:string,token:string}>
 */
function pcvc_cedrick_witness_roles(): array
{
    return [
        'wit_co' => [
            'label' => 'Witness for the company',
            'name' => 'wit_co_name',
            'id' => 'wit_co_id',
            'phone' => 'wit_co_phone',
            'date' => 'wit_co_date',
            'signature' => 'wit_co_signature',
            'token' => 'wit_co_token',
        ],
        'wit_cl' => [
            'label' => 'Witness for the client',
            'name' => 'wit_cl_name',
            'id' => 'wit_cl_id',
            'phone' => 'wit_cl_phone',
            'date' => 'wit_cl_date',
            'signature' => 'wit_cl_signature',
            'token' => 'wit_cl_token',
        ],
    ];
}

/**
 * @param array<string, mixed> $row
 * @param array<string, string> $prefill
 * @return array{ok:bool, error?:string, email?:string}
 */
function pcvc_service_agreement_email_witness(mysqli $conn, array $row, string $role, string $email, array $prefill = []): array
{
    $roles = pcvc_cedrick_witness_roles();
    if (!isset($roles[$role])) {
        return ['ok' => false, 'error' => 'Choose a witness.'];
    }
    $email = trim($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Type the witness email in Telephone or email, then share.'];
    }
    $spec = $roles[$role];
    $annex = json_decode((string) ($row['annex_json'] ?? ''), true);
    if (!is_array($annex)) {
        $annex = [];
    }
    $token = trim((string) ($annex[$spec['token']] ?? ''));
    if ($token === '') {
        $token = bin2hex(random_bytes(16));
    }
    $annex[$spec['token']] = $token;
    $annex[$spec['phone']] = $email;
    foreach (['name', 'id', 'date'] as $part) {
        $posted = trim((string) ($prefill[$part] ?? ''));
        if ($posted !== '') {
            $annex[$spec[$part]] = $posted;
        }
    }
    $json = json_encode($annex, JSON_UNESCAPED_UNICODE) ?: '{}';
    $id = (int) ($row['id'] ?? 0);
    $stmt = $conn->prepare('UPDATE service_agreements SET annex_json = ?, updated_at = NOW() WHERE id = ?');
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Could not save the witness link.'];
    }
    $stmt->bind_param('si', $json, $id);
    $stmt->execute();
    $stmt->close();

    $contractToken = (string) ($row['contract_token'] ?? '');
    $link = pcvc_service_agreement_witness_url($contractToken, $role, $token);
    $client = trim((string) ($row['representative_name'] ?? ''));
    if ($client === '') {
        $client = trim((string) ($row['company_name'] ?? '')) ?: 'the client';
    }
    $safeClient = htmlspecialchars($client, ENT_QUOTES, 'UTF-8');
    $safeRole = htmlspecialchars($spec['label'], ENT_QUOTES, 'UTF-8');
    $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:600px;color:#1e293b;line-height:1.5;">'
        . '<p>Hello,</p>'
        . '<p>You are asked to sign as <strong>' . $safeRole . '</strong> on the agreement for <strong>' . $safeClient . '</strong>.</p>'
        . '<p>Open the page, check your details, draw your signature, and press Submit. Nothing is signed until you submit it yourself.</p>'
        . '<p style="margin:24px 0;"><a href="' . $safeLink . '" style="display:inline-block;background:#166534;color:#fff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:700;">Draw signature and submit</a></p>'
        . '<p style="font-size:14px;color:#475569;">Link: ' . $safeLink . '</p>'
        . '<p style="margin-top:28px;font-size:13px;color:#64748b;">Parrot Canada Visa Consultant</p></div>';
    $text = "You are asked to sign as {$spec['label']} on the agreement for {$client}.\n\nDraw your signature and submit here:\n{$link}\n";

    try {
        require_once __DIR__ . '/mailer.php';
        $mail = pcvc_staff_contract_smtp_mailer();
        $mail->addAddress($email);
        $mail->Subject = 'Please sign as witness — ' . $client;
        $mail->Body = $html;
        $mail->AltBody = $text;
        $mail->send();
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'The witness email could not be sent. Check the address and try again.'];
    }

    return ['ok' => true, 'email' => $email];
}

function pcvc_service_agreement_witness_url(string $contractToken, string $role, string $witnessToken): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'mis.visaconsultantcanada.com');
    $base = rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/service-agreement.php')), '/\\');

    return $scheme . '://' . $host . $base . '/service-agreement.php?token=' . rawurlencode($contractToken)
        . '&as=' . rawurlencode($role) . '&w=' . rawurlencode($witnessToken);
}

/**
 * @param array<string, mixed> $row
 * @param array<string, string> $fields
 * @return array{ok:bool, error?:string}
 */
function pcvc_service_agreement_save_witness(mysqli $conn, array $row, string $role, string $witnessToken, array $fields): array
{
    $roles = pcvc_cedrick_witness_roles();
    if (!isset($roles[$role])) {
        return ['ok' => false, 'error' => 'This witness link is not valid.'];
    }
    $spec = $roles[$role];
    $annex = json_decode((string) ($row['annex_json'] ?? ''), true);
    if (!is_array($annex)) {
        $annex = [];
    }
    if (!hash_equals((string) ($annex[$spec['token']] ?? ''), $witnessToken)) {
        return ['ok' => false, 'error' => 'This witness link is not valid.'];
    }
    $name = trim((string) ($fields['name'] ?? ''));
    $nationalId = trim((string) ($fields['id'] ?? ''));
    $contact = trim((string) ($fields['phone'] ?? ''));
    $when = trim((string) ($fields['date'] ?? ''));
    $signature = trim((string) ($fields['signature'] ?? ''));
    if (strlen($name) < 2) {
        return ['ok' => false, 'error' => 'Enter your full name.'];
    }
    if ($nationalId === '') {
        return ['ok' => false, 'error' => 'Enter your national ID or passport.'];
    }
    if ($contact === '') {
        return ['ok' => false, 'error' => 'Enter your telephone or email.'];
    }
    if ($when === '') {
        return ['ok' => false, 'error' => 'Enter the date and place.'];
    }
    if (!str_starts_with($signature, 'data:image/png;base64,')) {
        return ['ok' => false, 'error' => 'Draw your signature, then press Submit.'];
    }
    $annex[$spec['name']] = $name;
    $annex[$spec['id']] = $nationalId;
    $annex[$spec['phone']] = $contact;
    $annex[$spec['date']] = $when;
    $annex[$spec['signature']] = $signature;
    $json = json_encode($annex, JSON_UNESCAPED_UNICODE) ?: '{}';
    $id = (int) ($row['id'] ?? 0);
    $stmt = $conn->prepare('UPDATE service_agreements SET annex_json = ?, updated_at = NOW() WHERE id = ?');
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Could not save the signature.'];
    }
    $stmt->bind_param('si', $json, $id);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) {
        return ['ok' => false, 'error' => 'Could not save the signature.'];
    }
    if (($row['status'] ?? '') === 'signed') {
        pcvc_service_agreement_pdf($conn, $id);
    }

    return ['ok' => true];
}

function pcvc_service_agreement_public_url(string $token): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'mis.visaconsultantcanada.com');
    $base = rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')), '/\\');

    return $scheme . '://' . $host . $base . '/service-agreement.php?token=' . rawurlencode($token);
}

function pcvc_service_agreement_pdf(mysqli $conn, int $id): string
{
    $stmt = $conn->prepare('SELECT * FROM service_agreements WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!is_array($row)) {
        return '';
    }
    $row = pcvc_service_agreement_hydrate($row);
    $specialMeta = ($row['kind'] ?? '') === 'special'
        ? (pcvc_special_contract_catalog()[(string) ($row['catalog_key'] ?? '')] ?? [])
        : [];
    $documentPdf = ($specialMeta['layout'] ?? '') === 'document' || ($row['kind'] ?? '') === 'german_language';

    if ($documentPdf) {
        $html = '<html><head><meta charset="UTF-8"><style>@page { margin: 0.6in; }' . pcvc_cedrick_document_css() . '</style></head><body>'
            . pcvc_cedrick_document_html($row, false)
            . '</body></html>';
    } else {
    $frenchPdf = strtolower((string) ($row['language'] ?? '')) === 'french' && ($row['kind'] ?? '') !== 'special';
    $title = $specialMeta['page_title'] ?? ($row['kind'] === 'special'
        ? 'B2B Partnership and Service Cooperation Agreement'
        : ($frenchPdf
            ? 'Accord B2B de partenariat pour les études de langue allemande'
            : 'German Language B2B Partnership and Service Cooperation Agreement'));
    $clientLayout = ($specialMeta['layout'] ?? '') === 'document';
    $signature = (string) ($row['signature_image'] ?? '');
    $sigHtml = str_starts_with($signature, 'data:image/png;base64,')
        ? '<img src="' . $signature . '" alt="Signature" style="height:70px;">'
        : '—';
    $parrotSig = __DIR__ . '/../admin/signature-manager.png';
    $parrotHtml = '—';
    if (is_file($parrotSig)) {
        $parrotHtml = '<img src="data:image/png;base64,' . base64_encode((string) file_get_contents($parrotSig)) . '" alt="Parrot signature" style="height:70px;">';
    }
    $html = '<html><head><meta charset="UTF-8"><style>
        body { font-family: DejaVu Sans, serif; font-size: 11px; color: #111; line-height: 1.45; }
        h1 { font-size: 16px; text-align: center; }
        h2 { font-size: 13px; margin: 16px 0 6px; }
        h3 { font-size: 12px; margin: 10px 0 4px; }
        p { margin: 0 0 6px; }
        .sign { width: 100%; margin-top: 18px; }
        .sign td { width: 50%; vertical-align: top; padding: 8px; }
    </style></head><body>';
    $html .= '<h1>' . pcvc_service_agreement_h($title) . '</h1>';
    $html .= pcvc_service_agreement_body($row, false, false, true);
    $html .= $frenchPdf
        ? '<h2>Signatures</h2><table class="sign"><tr>
        <td><strong>Parrot Canada Visa Consultant Co. Ltd.</strong><br>Dr Jean Pierre Twajamahoro<br>Directeur général / Propriétaire<br>' . $parrotHtml . '<br>Date : ' . pcvc_service_agreement_h((string) ($row['signed_date'] ?? '')) . '</td>
        <td><strong>' . pcvc_service_agreement_h((string) ($row['company_name'] ?? '')) . '</strong><br>'
        . pcvc_service_agreement_h((string) ($row['representative_name'] ?? '')) . '<br>'
        . pcvc_service_agreement_h((string) ($row['representative_title'] ?? '')) . '<br>'
        . $sigHtml . '<br>Date : ' . pcvc_service_agreement_h((string) ($row['signed_date'] ?? '')) . '</td>
        </tr></table>'
        : '<h2>Signatures</h2><table class="sign"><tr>
        <td><strong>Parrot Canada Visa Consultant Co. Ltd.</strong><br>Dr Jean Pierre Twajamahoro<br>Managing Director / Owner<br>' . $parrotHtml . '<br>Date: ' . pcvc_service_agreement_h((string) ($row['signed_date'] ?? '')) . '</td>
        <td><strong>' . pcvc_service_agreement_h((string) ($clientLayout ? ($row['representative_name'] ?? $row['company_name'] ?? 'Client') : ($row['company_name'] ?? ''))) . '</strong><br>'
        . pcvc_service_agreement_h((string) ($clientLayout ? 'Client' : ($row['representative_title'] ?? ''))) . '<br>'
        . ($clientLayout ? '' : pcvc_service_agreement_h((string) ($row['representative_name'] ?? '')) . '<br>')
        . $sigHtml . '<br>Date: ' . pcvc_service_agreement_h((string) ($row['signed_date'] ?? '')) . '</td>
        </tr></table>';
    $html .= pcvc_service_agreement_body($row, false, true, false);
    $html .= '</body></html>';
    }

    if (!is_file(__DIR__ . '/../vendor/autoload.php')) {
        return '';
    }
    require_once __DIR__ . '/../vendor/autoload.php';
    $dir = __DIR__ . '/../uploads/service-agreements';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $relative = 'uploads/service-agreements/agreement-' . $id . '.pdf';
    $absolute = __DIR__ . '/../' . $relative;
    $dompdf = new Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => $documentPdf ? 'Times' : 'DejaVu Sans']);
    $dompdf->loadHtml($html);
    $dompdf->setPaper($documentPdf ? 'letter' : 'A4');
    $dompdf->render();
    file_put_contents($absolute, $dompdf->output());
    $stmt = $conn->prepare('UPDATE service_agreements SET pdf_path = ? WHERE id = ?');
    $stmt->bind_param('si', $relative, $id);
    $stmt->execute();
    $stmt->close();

    return $relative;
}
