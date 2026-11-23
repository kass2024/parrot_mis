<?php
/**
 * Visit & study visa (form_17_applications) — same workflow labels as job applications.
 */
require_once __DIR__ . '/job_application_status.php';
require_once __DIR__ . '/rejection_reason_column.php';

function xander_ensure_form17_process_status_column(mysqli $conn): void
{
    $r = @$conn->query("SHOW COLUMNS FROM `form_17_applications` LIKE 'process_status'");
    if ($r && $r->num_rows > 0) {
        xander_ensure_rejection_reason_column($conn, 'form_17_applications');

        return;
    }
    $sql = "ALTER TABLE `form_17_applications` 
            ADD COLUMN `process_status` VARCHAR(64) NOT NULL DEFAULT 'submitted' 
            COMMENT 'Workflow stage'";
    if (!@$conn->query($sql)) {
        error_log('form_17_applications process_status migration: ' . $conn->error);
    }
    xander_ensure_rejection_reason_column($conn, 'form_17_applications');
}

/**
 * Final visa form submit sets submitted_at (draft rows stay NULL until step 2).
 */
function xander_ensure_form17_submitted_at_column(mysqli $conn): void
{
    $r = @$conn->query("SHOW COLUMNS FROM `form_17_applications` LIKE 'submitted_at'");
    if ($r && $r->num_rows > 0) {
        return;
    }
    $sql = "ALTER TABLE `form_17_applications` 
            ADD COLUMN `submitted_at` DATETIME NULL DEFAULT NULL 
            COMMENT 'Set when applicant completes step 2'";
    if (!@$conn->query($sql)) {
        error_log('form_17_applications submitted_at migration: ' . $conn->error);
    }
}

/**
 * Target intake for study / visit visa tracking (Canada & USA calendars).
 */
function xander_ensure_form17_intake_column(mysqli $conn): void
{
    $r = @$conn->query("SHOW COLUMNS FROM `form_17_applications` LIKE 'intake'");
    if ($r && $r->num_rows > 0) {
        return;
    }
    $sql = "ALTER TABLE `form_17_applications`
            ADD COLUMN `intake` VARCHAR(120) NOT NULL DEFAULT ''
            COMMENT 'Chosen Canada/USA intake label'
            AFTER `visa_type`";
    if (!@$conn->query($sql)) {
        error_log('form_17_applications intake migration: ' . $conn->error);
    }
}

/**
 * Full destination country list for the visa add form.
 *
 * @return list<string>
 */
function xander_visa_destination_countries(): array
{
    require_once __DIR__ . '/malta_courses_schema.php';

    return malta_country_options();
}

/**
 * Priority destinations shown at the top of the smart country selector.
 *
 * @return list<string>
 */
function xander_visa_featured_destination_countries(): array
{
    return [
        'Canada',
        'United States',
        'United Kingdom',
        'Germany',
        'Ireland',
        'Australia',
        'France',
        'Netherlands',
        'Poland',
        'United Arab Emirates',
    ];
}

/**
 * Canada (Jan / May / Sep) and USA (Spring / Summer / Fall) intakes for current + next year.
 *
 * @return array<string, list<string>> country label => intake labels
 */
function xander_visa_intake_options_by_country(): array
{
    $year = (int) date('Y');
    $years = [$year, $year + 1];
    $canada = [];
    $usa = [];
    foreach ($years as $y) {
        $canada[] = 'Canada — January ' . $y . ' (Winter)';
        $canada[] = 'Canada — May ' . $y . ' (Spring/Summer)';
        $canada[] = 'Canada — September ' . $y . ' (Fall)';
        $usa[] = 'USA — Spring ' . $y . ' (January)';
        $usa[] = 'USA — Summer ' . $y . ' (May)';
        $usa[] = 'USA — Fall ' . $y . ' (August)';
    }

    return [
        'Canada' => $canada,
        'United States' => $usa,
    ];
}

/**
 * Flat list of valid intake labels.
 *
 * @return list<string>
 */
function xander_visa_intake_options(): array
{
    $all = [];
    foreach (xander_visa_intake_options_by_country() as $group) {
        foreach ($group as $label) {
            $all[] = $label;
        }
    }

    return $all;
}

function xander_is_valid_visa_intake(string $intake): bool
{
    return $intake === '' || in_array($intake, xander_visa_intake_options(), true);
}

/**
 * Visit and study visa statuses, including the student visa board.
 *
 * @return array<string, string>
 */
function xander_visa_application_process_statuses(): array
{
    return [
        'submitted' => 'Submitted',
        'under_review' => 'Under review',
        'waiting_decision' => 'Waiting for decision',
        'final_decision' => 'Final decision available',
        'visa_application_done' => 'Visa application done',
        'visa_approved' => 'Visa approved',
        'waiting_for_visa_application' => 'Waiting for visa application',
        'waiting_for_caq' => 'Waiting for CAQ',
        'waiting_for_deferral' => 'Waiting for deferral',
        'intake_changed' => 'Intake changed',
        'intake_changed_waiting_documents' => 'Intake changed — waiting for documents',
        'names_changed_waiting_documents' => 'Names changed — waiting for documents',
        'waiting_for_letters_and_visa' => 'Waiting for letters and visa application',
        'visit_visa' => 'Visit visa',
        'closed' => 'Closed',
        'rejected' => 'Rejected',
    ];
}

function xander_is_valid_visa_process_status(string $key): bool
{
    return array_key_exists($key, xander_visa_application_process_statuses());
}

function xander_normalize_visa_process_status(?string $raw): string
{
    $s = strtolower(trim((string) $raw));
    return xander_is_valid_visa_process_status($s) ? $s : 'submitted';
}

/**
 * Ensure form_17_applications.intake exists (Canada / USA study intakes).
 */
function xander_ensure_form17_intake_column(mysqli $conn): void
{
    $r = @$conn->query("SHOW COLUMNS FROM `form_17_applications` LIKE 'intake'");
    if ($r && $r->num_rows > 0) {
        return;
    }
    $sql = "ALTER TABLE `form_17_applications`
            ADD COLUMN `intake` VARCHAR(120) NULL DEFAULT NULL
            COMMENT 'Selected Canada or USA intake term'
            AFTER `visa_type`";
    if (!@$conn->query($sql)) {
        error_log('form_17_applications intake migration: ' . $conn->error);
    }
}

/**
 * Standard Canada and USA study intakes for the next few years.
 *
 * @return array{Canada: list<string>, USA: list<string>}
 */
function xander_visa_canada_usa_intakes(?int $fromYear = null, int $yearsAhead = 3): array
{
    $start = $fromYear ?? (int) date('Y');
    if ($start < 2024) {
        $start = (int) date('Y');
    }
    $canada = [];
    $usa = [];
    for ($y = $start; $y < $start + max(1, $yearsAhead); $y++) {
        $canada[] = "Canada — Winter (January) {$y}";
        $canada[] = "Canada — Spring (May) {$y}";
        $canada[] = "Canada — Fall (September) {$y}";
        $usa[] = "USA — Spring (January) {$y}";
        $usa[] = "USA — Summer (May) {$y}";
        $usa[] = "USA — Fall (August) {$y}";
    }

    return ['Canada' => $canada, 'USA' => $usa];
}

/**
 * @return list<string>
 */
function xander_visa_intake_flat_list(?int $fromYear = null, int $yearsAhead = 3): array
{
    $grouped = xander_visa_canada_usa_intakes($fromYear, $yearsAhead);
    return array_merge($grouped['Canada'], $grouped['USA']);
}

function xander_is_valid_visa_intake(string $intake): bool
{
    $intake = trim($intake);
    if ($intake === '') {
        return true;
    }
    return in_array($intake, xander_visa_intake_flat_list(), true)
        || in_array($intake, xander_visa_intake_flat_list((int) date('Y') - 1, 5), true);
}
