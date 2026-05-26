<?php
declare(strict_types=1);

/**
 * Send HTTP response body to the client and close the connection so background work can continue.
 */
function pcvc_finish_http_response(string $body, int $statusCode = 200): void
{
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Connection: close');
        header('Content-Length: ' . (string) strlen($body));
    }

    echo $body;

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }

    ignore_user_abort(true);

    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    flush();

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

/**
 * Hand off a POST request to another endpoint without waiting for it to finish (email, WhatsApp, PDF).
 */
function pcvc_trigger_background_post(string $relativeScript, array $postFields, int $timeoutMs = 900): void
{
    if (!function_exists('curl_init')) {
        error_log('pcvc_trigger_background_post: curl extension missing');
        return;
    }

    if (!function_exists('pcvc_public_base_url')) {
        require_once dirname(__DIR__) . '/includes/company_branding.php';
    }

    $url = rtrim(pcvc_public_base_url(), '/') . '/' . ltrim($relativeScript, '/');
    $payload = http_build_query($postFields);

    $ch = curl_init($url);
    if ($ch === false) {
        return;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST              => true,
        CURLOPT_POSTFIELDS        => $payload,
        CURLOPT_RETURNTRANSFER    => true,
        CURLOPT_HEADER            => false,
        CURLOPT_TIMEOUT_MS        => max(300, $timeoutMs),
        CURLOPT_CONNECTTIMEOUT_MS => 500,
        CURLOPT_NOSIGNAL          => true,
        CURLOPT_FRESH_CONNECT     => true,
        CURLOPT_FORBID_REUSE      => true,
        CURLOPT_SSL_VERIFYPEER    => false,
        CURLOPT_SSL_VERIFYHOST    => 0,
        CURLOPT_HTTPHEADER        => ['Connection: Close'],
    ]);

    @curl_exec($ch);
    curl_close($ch);
}
