<?php
declare(strict_types=1);

function pcvc_country_flag(string $code, bool $forPdf = false): string
{
    $code = strtolower($code);
    if (!in_array($code, ['us', 'ca', 'eu', 'kr'], true)) {
        return '';
    }

    if ($forPdf) {
        $src = str_replace('\\', '/', dirname(__DIR__) . '/assets/flags/' . $code . '.png');
    } else {
        $src = 'assets/flags/' . $code . '.png';
    }

    return '<img class="country-flag" src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="" width="22" height="16" aria-hidden="true">';
}

function pcvc_flag_code_for_text(string $text): string
{
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    $checks = [
        'us' => '/\b(usa|u\.s\.|united states|états-unis|etats-unis)\b/u',
        'ca' => '/\bcanada\b/u',
        'eu' => '/\beurope\b/u',
        'kr' => '/\b(south korea|corée du sud|coree du sud|corée|corée|korea)\b/u',
    ];
    $hits = [];
    foreach ($checks as $code => $pattern) {
        if (preg_match($pattern, $text)) {
            $hits[] = $code;
        }
    }

    return count($hits) === 1 ? $hits[0] : '';
}
