<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/weekly_advert.php';

weekly_advert_ensure_schema($conn);
$code = (string) ($_GET['c'] ?? '');
$items = weekly_advert_bundle($conn, $code);
if ($items === []) {
    http_response_code(404);
    echo 'This advert is no longer available.';
    exit;
}

$first = $items[0];
$caption = (string) $first['caption'];
$lines = preg_split("/\r\n|\n|\r/", trim($caption)) ?: [];
$title = trim((string) ($lines[0] ?? 'Weekly advert'));
if (count($items) > 1) {
    $title = count($items) . ' adverts — ' . $title;
}
if ($title === '') {
    $title = 'Parrot Canada Visa Consultant';
}
$description = count($items) > 1
    ? count($items) . ' flyers in this advert. ' . trim(preg_replace('/\s+/', ' ', $caption) ?? '')
    : trim(preg_replace('/\s+/', ' ', $caption) ?? '');
if (mb_strlen($description) > 140) {
    $description = mb_substr($description, 0, 137) . '...';
}
if (mb_strlen($title) > 70) {
    $title = mb_substr($title, 0, 67) . '...';
}
$base = 'https://mis.visaconsultantcanada.com/';
$previewRel = (string) $first['preview_file'];
$previewUrl = $base . implode('/', array_map('rawurlencode', explode('/', $previewRel)));
$pageUrl = weekly_advert_share_url((string) ($first['set_code'] ?? $code));
if (trim((string) ($first['set_code'] ?? '')) === '') {
    $pageUrl = weekly_advert_share_url((string) $first['code']);
}
$previewPath = __DIR__ . '/' . $previewRel;
$size = is_file($previewPath) ? @getimagesize($previewPath) : false;
$imgW = (int) ($size[0] ?? 1080);
$imgH = (int) ($size[1] ?? 1350);
$esc = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $esc($title) ?></title>
<meta name="description" content="<?= $esc($description) ?>">
<link rel="canonical" href="<?= $esc($pageUrl) ?>">
<meta property="og:type" content="article">
<meta property="og:site_name" content="Parrot Canada Visa Consultant">
<meta property="og:title" content="<?= $esc($title) ?>">
<meta property="og:description" content="<?= $esc($description) ?>">
<meta property="og:url" content="<?= $esc($pageUrl) ?>">
<meta property="og:image" content="<?= $esc($previewUrl) ?>">
<meta property="og:image:secure_url" content="<?= $esc($previewUrl) ?>">
<link rel="image_src" href="<?= $esc($previewUrl) ?>">
<meta property="og:image:type" content="image/jpeg">
<meta property="og:image:width" content="<?= $imgW ?>">
<meta property="og:image:height" content="<?= $imgH ?>">
<meta property="og:image:alt" content="<?= $esc($title) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= $esc($title) ?>">
<meta name="twitter:description" content="<?= $esc($description) ?>">
<meta name="twitter:image" content="<?= $esc($previewUrl) ?>">
<style>
* { box-sizing: border-box; }
body { margin: 0; background: #f4f1ea; color: #142018; font-family: "Segoe UI", system-ui, sans-serif; }
.sheet { max-width: 720px; margin: 0 auto; background: #fff; min-height: 100vh; }
.sheet img { display: block; width: 100%; height: auto; }
.caption { margin: 0; padding: 1.1rem 1.15rem 1.4rem; white-space: pre-wrap; font-size: 16.5px; line-height: 1.5; }
.advert + .advert { border-top: 10px solid #f4f1ea; }
</style>
</head>
<body>
<article class="sheet">
  <?php foreach ($items as $item):
    $flyerRel = (string) $item['flyer_file'];
    $flyerUrl = $base . implode('/', array_map('rawurlencode', explode('/', $flyerRel)));
  ?>
  <section class="advert">
    <img src="<?= $esc($flyerUrl) ?>" alt="<?= $esc($title) ?>">
    <p class="caption"><?= $esc((string) $item['caption']) ?></p>
  </section>
  <?php endforeach; ?>
</article>
</body>
</html>
