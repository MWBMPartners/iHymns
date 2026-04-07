<?php

declare(strict_types=1);

/**
 * iHymns — Dynamic Open Graph Image Generator
 *
 * PURPOSE:
 * Generates a 1200×630 social sharing preview image (OG image) for
 * link previews in iMessage, Facebook, Twitter, Slack, Discord, etc.
 *
 * Composites the app icon centred on a branded gradient background
 * with the app name rendered below.
 *
 * ACCESSED VIA:
 *   /og-image.php (called directly — not rewritten)
 *
 * OUTPUT:
 *   PNG image, 1200×630px, Content-Type: image/png
 */

/* Cache for 24 hours — the image rarely changes */
header('Cache-Control: public, max-age=86400');
header('Content-Type: image/png');

/* Dimensions per Open Graph / social platform recommendations */
$width  = 1200;
$height = 630;

/* Create the canvas */
$img = imagecreatetruecolor($width, $height);
if (!$img) {
    http_response_code(500);
    exit;
}

/* Enable alpha blending */
imagealphablending($img, true);
imagesavealpha($img, true);

/* Background gradient — dark navy matching the app's dark theme */
$bgTop    = imagecolorallocate($img, 30, 32, 48);    /* #1e2030 */
$bgBottom = imagecolorallocate($img, 42, 45, 68);    /* #2a2d44 */

/* Draw gradient */
for ($y = 0; $y < $height; $y++) {
    $ratio = $y / $height;
    $r = (int)(30 + (42 - 30) * $ratio);
    $g = (int)(32 + (45 - 32) * $ratio);
    $b = (int)(48 + (68 - 48) * $ratio);
    $color = imagecolorallocate($img, $r, $g, $b);
    imageline($img, 0, $y, $width, $y, $color);
}

/* Load and composite the app icon */
$iconPath = __DIR__ . '/assets/icon-512.png';
if (file_exists($iconPath)) {
    $icon = imagecreatefrompng($iconPath);
    if ($icon) {
        $iconSize = 240;
        $iconX = (int)(($width - $iconSize) / 2);
        $iconY = 120;

        /* Resize and composite */
        imagecopyresampled(
            $img, $icon,
            $iconX, $iconY,
            0, 0,
            $iconSize, $iconSize,
            imagesx($icon), imagesy($icon)
        );
        imagedestroy($icon);
    }
}

/* Draw app name text */
$white = imagecolorallocate($img, 255, 255, 255);
$grey  = imagecolorallocate($img, 160, 165, 185);

/* App name — large, centred below icon */
$appName = 'iHymns';
$fontSize = 7; /* GD built-in font size (1-5), but we'll use imagestring for simplicity */

/* Use larger built-in font for the app name */
$nameWidth = imagefontwidth(5) * strlen($appName);
$nameX = (int)(($width - $nameWidth) / 2);
imagestring($img, 5, $nameX, 390, $appName, $white);

/* Tagline — smaller, below app name */
$tagline = 'Christian Hymns & Worship Songs';
$tagWidth = imagefontwidth(4) * strlen($tagline);
$tagX = (int)(($width - $tagWidth) / 2);
imagestring($img, 4, $tagX, 420, $tagline, $grey);

/* Subtle accent line */
$accent = imagecolorallocate($img, 124, 88, 246); /* Purple accent */
imagefilledrectangle($img, 480, 460, 720, 463, $accent);

/* Domain */
$domain = 'ihymns.app';
$domainWidth = imagefontwidth(3) * strlen($domain);
$domainX = (int)(($width - $domainWidth) / 2);
imagestring($img, 3, $domainX, 480, $domain, $grey);

/* Output */
imagepng($img);
imagedestroy($img);
