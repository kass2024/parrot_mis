<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');

/*
|--------------------------------------------------------------------------
| PURPOSE
|--------------------------------------------------------------------------
| 1. Load ALL fee packages (once)
| 2. Load TOTAL amount paid for an application
| 3. NO auto assignment
| 4. NO fee items here
|--------------------------------------------------------------------------
|
| JS sends: student_id
| DB uses: application_id
|--------------------------------------------------------------------------
*/

$applicationId = isset($_GET['student_id'])
  ? (int) $_GET['student_id']
  : 0;

$sourceTable = trim((string) ($_GET['table'] ?? ''));

if ($applicationId <= 0) {
  echo json_encode([
    'packages' => [],
    'paid'     => 0
  ]);
  exit;
}

/*
|--------------------------------------------------------------------------
| Preferred contract package order (7.1 → 7.24)
|--------------------------------------------------------------------------
*/
$contractPackageOrder = [
  'p70' => 1,
  'p71' => 2,
  'p72' => 3,
  'p73' => 4,
  'p74' => 5,
  'p75' => 6,
  'p76' => 7,
  'p77loa' => 8,
  'p77loalawyer' => 9,
  'p77' => 10,
  'p78' => 11,
  'p79' => 12,
  'p710' => 13,
  'p710b' => 14,
  'p710promo' => 15,
  'p711' => 16,
  'p712' => 17,
  'p713' => 18,
  'p714' => 19,
  'p715' => 20,
  'p716' => 21,
  'p717' => 22,
  'p718' => 23,
  'p719' => 24,
  'p720' => 25,
];

/*
|--------------------------------------------------------------------------
| 1. LOAD ALL PACKAGES (FOR DROPDOWN)
|--------------------------------------------------------------------------
*/
$packages = [];

$sql = "
  SELECT id, code, title, total_amount, currency
  FROM fee_packages
  WHERE code NOT LIKE '%removed'
    AND title NOT LIKE '[REMOVED]%'
  ORDER BY id ASC
";

$result = $conn->query($sql);

if ($result) {
  while ($row = $result->fetch_assoc()) {
    $code = (string) ($row['code'] ?? '');
    $packages[] = [
      'id'           => (int) $row['id'],
      'code'         => $code,
      'name'         => $row['title'],     // 👈 JS uses `name`
      'total_amount' => (float) $row['total_amount'],
      'currency'     => $row['currency'],
      '_sort'        => $contractPackageOrder[$code] ?? (1000 + (int) $row['id']),
    ];
  }
}

usort($packages, static function (array $a, array $b): int {
  if ($a['_sort'] === $b['_sort']) {
    return $a['id'] <=> $b['id'];
  }
  return $a['_sort'] <=> $b['_sort'];
});

$packages = array_map(static function (array $pkg): array {
  unset($pkg['_sort'], $pkg['code']);
  return $pkg;
}, $packages);

/*
|--------------------------------------------------------------------------
| 2. LOAD TOTAL PAID (APPLICATION-WIDE)
|--------------------------------------------------------------------------
*/
$paid = 0.0;

if ($sourceTable !== '') {
  $stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount_paid), 0)
    FROM application_payments
    WHERE application_id = ? AND source_table = ?
  ");
  if ($stmt) {
    $stmt->bind_param("is", $applicationId, $sourceTable);
    $stmt->execute();
    $stmt->bind_result($paid);
    $stmt->fetch();
    $stmt->close();
  }
} else {
  $stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount_paid), 0)
    FROM application_payments
    WHERE application_id = ?
  ");
  if ($stmt) {
    $stmt->bind_param("i", $applicationId);
    $stmt->execute();
    $stmt->bind_result($paid);
    $stmt->fetch();
    $stmt->close();
  }
}

echo json_encode([
  'packages' => $packages,
  'paid'     => (float) $paid
]);
