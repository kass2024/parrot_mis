<?php
declare(strict_types=1);

/**
 * Embeddable Word contract viewer (docx-preview) for shared hosting.
 *
 * Query: type=source|signed, staff_id optional for superadmin.
 */
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/staff_contract_schema.php';
require_once __DIR__ . '/../helpers/staff_contract_word.php';

$viewerId = (int) ($_SESSION['id'] ?? $_SESSION['admin_id'] ?? 0);
if ($viewerId <= 0) {
    http_response_code(401);
    exit('Unauthorized');
}

$staffId = (int) ($_GET['staff_id'] ?? $viewerId);
$type = ($_GET['type'] ?? 'source') === 'signed' ? 'signed' : 'source';
$docxUrl = 'view-staff-contract-docx.php?type=' . rawurlencode($type);
if ($staffId !== $viewerId) {
    $docxUrl .= '&staff_id=' . $staffId;
}
$docxUrl .= '&ts=' . time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Contract preview</title>
  <style>
    html, body { margin: 0; padding: 0; height: 100%; background: #e8edf3; }
    #status {
      font: 14px/1.4 'Segoe UI', system-ui, sans-serif;
      color: #475569; padding: 1rem; text-align: center;
    }
    #docx-container {
      min-height: calc(100vh - 48px);
      padding: 12px;
      box-sizing: border-box;
    }
    #docx-container .docx-wrapper {
      background: #fff;
      margin: 0 auto;
      box-shadow: 0 2px 12px rgba(0,0,0,.08);
    }
    #docx-container .docx-wrapper > section.docx {
      padding: 24px 32px !important;
    }
  </style>
</head>
<body>
  <div id="status">Loading contract…</div>
  <div id="docx-container"></div>
  <script src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/docx-preview@0.3.3/dist/docx-preview.min.js"></script>
  <script>
  (function () {
    const status = document.getElementById('status');
    const container = document.getElementById('docx-container');
    fetch(<?= json_encode($docxUrl, JSON_UNESCAPED_SLASHES) ?>, { credentials: 'same-origin' })
      .then(function (res) {
        if (!res.ok) {
          return res.text().then(function (t) {
            throw new Error(t || ('HTTP ' + res.status));
          });
        }
        return res.blob();
      })
      .then(function (blob) {
        status.style.display = 'none';
        return docx.renderAsync(blob, container, null, {
          className: 'docx',
          inWrapper: true,
          ignoreWidth: false,
          ignoreHeight: false,
          breakPages: true
        });
      })
      .catch(function (err) {
        status.textContent = 'Could not load contract: ' + (err.message || 'Unknown error');
        status.style.color = '#b45309';
      });
  })();
  </script>
</body>
</html>
