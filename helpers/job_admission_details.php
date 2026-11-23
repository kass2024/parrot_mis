<?php
declare(strict_types=1);

function pcvc_job_admission_fields(): array
{
    return [
        'applicant_name' => 255, 'application_id' => 100, 'email' => 255,
        'platform' => 200, 'destination' => 100, 'university_id' => 10,
        'phone_number' => 50, 'country' => 100, 'city' => 100,
        'status' => 100, 'application_remarks' => 16000, 'subagent' => 150,
    ];
}

function pcvc_job_admission_statuses(): array
{
    return ['Submitted', 'Processing', 'Additional Documents Required', 'No Documents'];
}

function pcvc_job_admission_load(mysqli $conn, int $jobId, bool $lock = false): ?array
{
    $stmt = $conn->prepare(
        'SELECT j.*, c.name AS destination_country, p.platform_name
         FROM job_list j
         LEFT JOIN universities u ON u.id = j.university_id
         LEFT JOIN countries c ON c.id = u.country_id
         LEFT JOIN platforms p ON p.id = j.platform_id
         WHERE j.id = ? LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
    );
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $job = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $job;
}

function pcvc_job_admission_can_edit(?array $job, int $adminId, bool $superadmin): bool
{
    return $job !== null && $job['status'] !== 'completed' && ($superadmin || (int) $job['admin_id'] === $adminId);
}

function pcvc_job_admission_form(array $job): array
{
    $defaults = [
        'applicant_name' => (string) $job['applicant_name'],
        'application_id' => '',
        'email' => (string) $job['applicant_email'],
        'platform' => (string) ($job['platform_name'] ?? ''),
        'destination' => (string) ($job['destination_country'] ?? ''),
        'university_id' => ((int) $job['university_id']) > 0 ? (string) $job['university_id'] : '',
        'phone_number' => '', 'country' => 'Rwanda', 'city' => 'Kigali',
        'status' => 'Submitted', 'application_remarks' => '', 'subagent' => '',
    ];
    $draft = json_decode((string) ($job['admission_draft'] ?? ''), true);
    return is_array($draft) ? array_replace($defaults, array_intersect_key($draft, $defaults)) : $defaults;
}

function pcvc_job_admission_validate_fields(array $input): array
{
    $limits = pcvc_job_admission_fields();
    foreach ($input as $field => $value) {
        if (!isset($limits[$field]) || !is_string($value) || !mb_check_encoding($value, 'UTF-8')
            || mb_strlen($value, 'UTF-8') > $limits[$field]) {
            throw new InvalidArgumentException('A field is invalid or too long.');
        }
        $input[$field] = trim($value);
    }
    if (isset($input['university_id']) && $input['university_id'] !== ''
        && filter_var($input['university_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) === false) {
        throw new InvalidArgumentException('Select a valid university.');
    }
    if (isset($input['status']) && !in_array($input['status'], pcvc_job_admission_statuses(), true)) {
        throw new InvalidArgumentException('Select a valid application status.');
    }
    return $input;
}
