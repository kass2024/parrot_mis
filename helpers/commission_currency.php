<?php
declare(strict_types=1);

/**
 * USD → RWF for commission requests.
 * Uses the same live FX source as payments checkout (open.er-api.com, cached in payments/storage).
 * Falls back to PCVC_USD_TO_RWF_RATE in .env if the fetched rate is missing or implausible.
 *
 * @return array{rwf: int, rate: float, usd: float}
 */
function pcvc_usd_to_rwf_conversion(float $usd): array
{
    if ($usd <= 0) {
        return ['rwf' => 0, 'rate' => 0.0, 'usd' => $usd];
    }

    $fxPath = __DIR__ . '/../payments/lib/fx.php';
    $rate = 0.0;
    if (is_file($fxPath)) {
        require_once $fxPath;
        if (function_exists('payments_fx_get_rate_to_rwf')) {
            $rate = (float) payments_fx_get_rate_to_rwf('USD');
        }
    }

    // USD/RWF is typically well above 100; 1.0 is the API-failure sentinel in payments_fx_get_rate_to_rwf.
    if ($rate < 100.0 || $rate > 50000.0) {
        $raw = getenv('PCVC_USD_TO_RWF_RATE');
        $fallback = ($raw !== false && trim((string) $raw) !== '')
            ? (float) trim((string) $raw)
            : 1300.0;
        if ($fallback > 0) {
            $rate = $fallback;
        }
    }
    if ($rate <= 0) {
        $rate = 1300.0;
    }

    $rwf = (int) round($usd * $rate);

    return ['rwf' => max(1, $rwf), 'rate' => $rate, 'usd' => $usd];
}
