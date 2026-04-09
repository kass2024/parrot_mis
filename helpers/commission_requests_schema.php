<?php
declare(strict_types=1);

/**
 * Add columns for amounts, workflow, and MoMo tracking (idempotent).
 */
function pcvc_ensure_commission_requests_schema(mysqli $conn): void
{
    $tbl = 'commission_requests';
    $chk = $conn->query("SHOW TABLES LIKE '{$tbl}'");
    if (!$chk || $chk->num_rows === 0) {
        return;
    }
    $chk->free();

    $have = [];
    $cols = $conn->query('SHOW COLUMNS FROM `' . $tbl . '`');
    if ($cols) {
        while ($r = $cols->fetch_assoc()) {
            $have[strtolower((string) ($r['Field'] ?? ''))] = true;
        }
        $cols->free();
    }

    $alters = [];
    if (empty($have['amount_usd'])) {
        $alters[] = 'ADD COLUMN `amount_usd` DECIMAL(12,2) NULL DEFAULT NULL';
    }
    if (empty($have['amount_rwf'])) {
        $alters[] = 'ADD COLUMN `amount_rwf` DECIMAL(15,2) NULL DEFAULT NULL';
    }
    if (empty($have['fx_rate_used'])) {
        $alters[] = 'ADD COLUMN `fx_rate_used` DECIMAL(14,6) NULL DEFAULT NULL';
    }
    if (empty($have['request_status'])) {
        $alters[] = "ADD COLUMN `request_status` VARCHAR(32) NOT NULL DEFAULT 'pending'";
    }
    if (empty($have['paid_rwf_total'])) {
        $alters[] = 'ADD COLUMN `paid_rwf_total` DECIMAL(15,2) NOT NULL DEFAULT 0';
    }
    if (empty($have['internal_note'])) {
        $alters[] = 'ADD COLUMN `internal_note` TEXT NULL';
    }
    if (empty($have['last_momo_transaction_id'])) {
        $alters[] = 'ADD COLUMN `last_momo_transaction_id` VARCHAR(96) NULL DEFAULT NULL';
    }

    foreach ($alters as $fragment) {
        @$conn->query("ALTER TABLE `{$tbl}` {$fragment}");
    }
}
