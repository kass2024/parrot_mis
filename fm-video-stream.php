<?php
declare(strict_types=1);

/**
 * Legacy local video stream endpoint.
 * Videos are now pCloud-only — redirect to the public video page / pCloud link.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers/francophonie_mobility_schema.php';

fm_ensure_schema($conn);

$token = trim((string) ($_GET['t'] ?? ''));
if ($token === '' || !preg_match('/^[a-f0-9]{16,64}$/i', $token)) {
    http_response_code(404);
    exit('Not found');
}

$st = $conn->prepare('SELECT video_pcloud_link FROM francophonie_mobility_applications WHERE video_public_token = ? LIMIT 1');
$st->bind_param('s', $token);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();

$link = trim((string) ($row['video_pcloud_link'] ?? ''));
if ($link !== '') {
    header('Location: ' . $link, true, 302);
    exit;
}

header('Location: fm-video-public.php?t=' . rawurlencode($token), true, 302);
exit;
