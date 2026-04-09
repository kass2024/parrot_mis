<?php
require_once __DIR__ . '/db.php';
$id     = intval($_POST['id']);
$field  = $_POST['field'] ?? '';
$value  = trim($_POST['value'] ?? '');
$source = $_POST['source'] ?? 'student_applications'; // default

// Allowed fields per table
$allowed_fields = [
  'student_applications' => [
    'first_name', 'last_name', 'email', 'phone_number', 'gender', 'dob',
    'nationality', 'city', 'address_line1', 'masters_program',
    'destination', 'application_date'
  ],
  'malta_applications' => [
    'name', 'surname', 'email', 'contact_number', 'gender', 'dob',
    'nationality', 'birth_place', 'address', 'degree_program', 'created_at'
  ],
  'turkey_applications' => [
    'transfer_student', 'have_tc', 'blue_card',
    'first_name', 'last_name', 'passport_no', 'issue_date', 'expiry_date',
    'gender', 'dob', 'nationality', 'residence_country', 'student_id',
    'email', 'area_code', 'mobile', 'address', 'city', 'province',
    'postal_code', 'country', 'father_name', 'father_mobile',
    'father_occupation', 'mother_name', 'agent_first_name', 'agent_last_name',
    'agent_email', 'photo', 'degree', 'transcript', 'cv', 'valid_passport',
    'is_read', 'submitted_at', 'region_id', 'university_id'
  ]
];

// Mapping for malta_applications (UI → DB)
$malta_map = [
  'first_name'       => 'name',
  'last_name'        => 'surname',
  'phone_number'     => 'contact_number',
  'city'             => 'birth_place',
  'address_line1'    => 'address',
  'masters_program'  => 'degree_program',
  'application_date' => 'created_at'
];

// Mapping for turkey_applications (UI → DB)
$turkey_map = [
  'phone_number'     => 'mobile',
  'address_line1'    => 'address',
  'application_date' => 'submitted_at'
];

// Normalize for Malta
if ($source === 'malta_applications') {
  if (isset($malta_map[$field])) {
    $field = $malta_map[$field];
  }
}

// Normalize for Turkey
if ($source === 'turkey_applications') {
  if (isset($turkey_map[$field])) {
    $field = $turkey_map[$field];
  }
}

// Final safety check
if (!in_array($field, $allowed_fields[$source] ?? [])) {
  exit('invalid');
}

// Execute update
$stmt = $conn->prepare("UPDATE `$source` SET `$field` = ? WHERE id = ?");
$stmt->bind_param("si", $value, $id);

if ($stmt->execute()) {
  echo 'ok';
} else {
  echo 'error';
}
?>
