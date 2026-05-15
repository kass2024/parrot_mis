<?php
declare(strict_types=1);

/**
 * Flatten signature data-URL PNGs onto a white background for reliable display/print.
 */
function contract_signature_to_display_png(string $dataUrl): ?string
{
    if (!preg_match('#^data:image/(png|jpeg);base64,(.+)$#i', $dataUrl, $m)) {
        return null;
    }

    $raw = base64_decode($m[2], true);
    if ($raw === false || $raw === '') {
        return null;
    }

    $src = @imagecreatefromstring($raw);
    if ($src === false) {
        return null;
    }

    $w = imagesx($src);
    $h = imagesy($src);
    if ($w < 1 || $h < 1) {
        imagedestroy($src);
        return null;
    }

    $dest = imagecreatetruecolor($w, $h);
    if ($dest === false) {
        imagedestroy($src);
        return null;
    }

    $white = imagecolorallocate($dest, 255, 255, 255);
    $black = imagecolorallocate($dest, 0, 0, 0);
    imagefill($dest, 0, 0, $white);

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgba = imagecolorat($src, $x, $y);
            $r = ($rgba >> 16) & 0xFF;
            $g = ($rgba >> 8) & 0xFF;
            $b = $rgba & 0xFF;
            $alpha = ($rgba >> 24) & 0x7F;

            // GD: 127 = transparent, 0 = opaque
            if ($alpha >= 120) {
                continue;
            }

            $max = max($r, $g, $b);
            $lum = (int) round(0.299 * $r + 0.587 * $g + 0.114 * $b);

            // Keep ink (strokes), drop near-black backgrounds
            if ($max > 16 || $lum > 12) {
                imagesetpixel($dest, $x, $y, $black);
            }
        }
    }

    imagedestroy($src);

    ob_start();
    imagepng($dest);
    $png = ob_get_clean();
    imagedestroy($dest);

    return is_string($png) && $png !== '' ? $png : null;
}
