<?php

declare(strict_types=1);

/**
 * iHymns — Dynamic Open Graph Image Generator
 *
 * PURPOSE:
 * Generates a 1200×630 social sharing preview image (OG image) for
 * link previews in iMessage, Facebook, Twitter, Slack, Discord, etc.
 *
 * MODES:
 *   /og-image.php            — Generic app branding image
 *   /og-image.php?song=CP-1  — Contextual image for a specific song (#173)
 *
 * LAYOUT:
 *   All critical content is centred within a ~630×630 "safe zone" so that
 *   iMessage's square centre-crop still shows the key information (#172).
 *
 * OUTPUT:
 *   PNG image, 1200×630px, Content-Type: image/png
 */

/* =========================================================================
 * BOOTSTRAP (minimal — only what we need for song data)
 * ========================================================================= */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/SongData.php';

/* Cache for 24 hours — the image rarely changes */
header('Cache-Control: public, max-age=86400');
header('Content-Type: image/png');

/* =========================================================================
 * CONSTANTS
 * ========================================================================= */
$W = 1200;
$H = 630;

/* Safe zone for iMessage square crop — centred 630×630 */
$safeLeft  = ($W - $H) / 2;   /* 285 */
$safeRight = $safeLeft + $H;   /* 915 */

/* Font paths (DejaVu Sans — available on most Linux servers) */
$fontRegular = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
$fontBold    = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';

/* Songbook accent colours — matches CSS --songbook-XX-solid values */
$songbookColours = [
    'CP'   => [99, 102, 241],   /* #6366f1 — indigo */
    'JP'   => [236, 72, 153],   /* #ec4899 — pink */
    'MP'   => [20, 184, 166],   /* #14b8a6 — teal */
    'SDAH' => [245, 158, 11],   /* #f59e0b — amber */
    'CH'   => [239, 68, 68],    /* #ef4444 — red */
    'Misc' => [139, 92, 246],   /* #8b5cf6 — purple */
];

/* =========================================================================
 * DETECT MODE — generic or song-specific
 * ========================================================================= */
$songId   = $_GET['song'] ?? null;
$songInfo = null;

if ($songId !== null && preg_match('/^[A-Za-z]+-\d+$/', $songId)) {
    try {
        $songData = new SongData();
        $songInfo = $songData->getSongById($songId);
    } catch (\Throwable $e) {
        /* Fall through to generic image */
    }
}

/* =========================================================================
 * CREATE CANVAS
 * ========================================================================= */
$img = imagecreatetruecolor($W, $H);
if (!$img) {
    http_response_code(500);
    exit;
}
imagealphablending($img, true);
imagesavealpha($img, true);

/* =========================================================================
 * DRAW BACKGROUND GRADIENT — dark navy matching the app's dark theme
 * ========================================================================= */
for ($y = 0; $y < $H; $y++) {
    $ratio = $y / $H;
    $r = (int)(30 + (42 - 30) * $ratio);
    $g = (int)(32 + (45 - 32) * $ratio);
    $b = (int)(48 + (68 - 48) * $ratio);
    $color = imagecolorallocate($img, $r, $g, $b);
    imageline($img, 0, $y, $W, $y, $color);
}

/* =========================================================================
 * ALLOCATE COMMON COLOURS
 * ========================================================================= */
$white     = imagecolorallocate($img, 255, 255, 255);
$grey      = imagecolorallocate($img, 160, 165, 185);
$greyLight = imagecolorallocate($img, 120, 125, 145);
$accent    = imagecolorallocate($img, 124, 88, 246);  /* Purple accent */

/* =========================================================================
 * HELPER FUNCTIONS
 * ========================================================================= */

/**
 * Draw text centred horizontally within the canvas.
 */
function drawCentredText(GdImage $img, float $size, string $font, string $text, int $y, int $color, int $canvasWidth): void
{
    $bbox = imagettfbbox($size, 0, $font, $text);
    $textWidth = abs($bbox[4] - $bbox[0]);
    $x = (int)(($canvasWidth - $textWidth) / 2);
    imagettftext($img, $size, 0, $x, $y, $color, $font, $text);
}

/**
 * Draw text left-aligned at a given x position. Returns the bounding box.
 */
function drawText(GdImage $img, float $size, string $font, string $text, int $x, int $y, int $color): array
{
    imagettftext($img, $size, 0, $x, $y, $color, $font, $text);
    return imagettfbbox($size, 0, $font, $text);
}

/**
 * Truncate text to fit within a max pixel width, adding ellipsis if needed.
 */
function truncateText(string $text, float $size, string $font, int $maxWidth): string
{
    $bbox = imagettfbbox($size, 0, $font, $text);
    if (abs($bbox[4] - $bbox[0]) <= $maxWidth) {
        return $text;
    }
    while (mb_strlen($text) > 0) {
        $text = mb_substr($text, 0, -1);
        $bbox = imagettfbbox($size, 0, $font, trim($text) . '…');
        if (abs($bbox[4] - $bbox[0]) <= $maxWidth) {
            return trim($text) . '…';
        }
    }
    return '…';
}

/**
 * Draw a filled rounded rectangle.
 */
function drawRoundedRect(GdImage $img, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
{
    imagefilledrectangle($img, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
    imagefilledrectangle($img, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
    imagefilledellipse($img, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($img, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($img, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($img, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
}

/* =========================================================================
 * RENDER — SONG-SPECIFIC IMAGE
 * ========================================================================= */
if ($songInfo !== null) {
    $bookId = strtoupper($songInfo['songbook'] ?? 'Misc');
    $accentRgb = $songbookColours[$bookId] ?? $songbookColours['Misc'];
    $bookAccent = imagecolorallocate($img, $accentRgb[0], $accentRgb[1], $accentRgb[2]);

    /* --- Songbook accent bar (left side, decorative) --- */
    imagefilledrectangle($img, 0, 0, 6, $H, $bookAccent);

    /* --- Songbook badge (top-left of safe zone) --- */
    $badgeX = (int)$safeLeft + 30;
    $badgeY = 50;
    drawRoundedRect($img, $badgeX, $badgeY, $badgeX + 80, $badgeY + 36, 6, $bookAccent);

    /* Badge text — songbook abbreviation */
    $abbr = $songInfo['songbook'] ?? '';
    $abbrBbox = imagettfbbox(11, 0, $fontBold, $abbr);
    $abbrW = abs($abbrBbox[4] - $abbrBbox[0]);
    imagettftext($img, 11, 0, $badgeX + (int)((80 - $abbrW) / 2), $badgeY + 25, $white, $fontBold, $abbr);

    /* Song number next to badge */
    $numText = '#' . (int)$songInfo['number'];
    imagettftext($img, 13, 0, $badgeX + 92, $badgeY + 25, $grey, $fontRegular, $numText);

    /* --- Song title (large, centred in safe zone) --- */
    $title = $songInfo['title'] ?? 'Untitled';
    $maxTextW = (int)($H - 60); /* Safe zone width minus padding */

    /* Split title across up to 2 lines if needed */
    $titleSize = 32;
    $bbox = imagettfbbox($titleSize, 0, $fontBold, $title);
    $titleW = abs($bbox[4] - $bbox[0]);

    $titleY = 160;
    if ($titleW <= $maxTextW) {
        /* Single line — centre it */
        drawCentredText($img, $titleSize, $fontBold, $title, $titleY, $white, $W);
    } else {
        /* Word-wrap into 2 lines */
        $words = explode(' ', $title);
        $line1 = '';
        $line2 = '';
        foreach ($words as $word) {
            $test = $line1 === '' ? $word : $line1 . ' ' . $word;
            $testBbox = imagettfbbox($titleSize, 0, $fontBold, $test);
            if (abs($testBbox[4] - $testBbox[0]) <= $maxTextW) {
                $line1 = $test;
            } else {
                $line2 .= ($line2 === '' ? '' : ' ') . $word;
            }
        }
        $line2 = truncateText($line2, $titleSize, $fontBold, $maxTextW);
        drawCentredText($img, $titleSize, $fontBold, $line1, $titleY, $white, $W);
        if ($line2 !== '' && $line2 !== '…') {
            drawCentredText($img, $titleSize, $fontBold, $line2, $titleY + 44, $white, $W);
            $titleY += 44; /* Shift subsequent content down */
        }
    }

    /* --- Songbook name (below title) --- */
    $bookName = $songInfo['songbookName'] ?? '';
    if ($bookName !== '') {
        drawCentredText($img, 16, $fontRegular, $bookName, $titleY + 50, $bookAccent, $W);
    }

    /* --- Writers (below songbook name) --- */
    $writers = $songInfo['writers'] ?? [];
    if (!empty($writers)) {
        $writerText = implode(', ', $writers);
        $writerText = truncateText($writerText, 13, $fontRegular, $maxTextW);
        drawCentredText($img, 13, $fontRegular, $writerText, $titleY + 80, $grey, $W);
    }

    /* --- Accent line --- */
    $lineY = $titleY + 105;
    imagefilledrectangle($img, ($W / 2) - 100, $lineY, ($W / 2) + 100, $lineY + 2, $bookAccent);

    /* --- First verse lyrics (faded, below accent) --- */
    $lyricsY = $lineY + 30;
    if (!empty($songInfo['components'])) {
        $linesShown = 0;
        foreach ($songInfo['components'] as $comp) {
            if ($linesShown >= 4) break;
            foreach ($comp['lines'] ?? [] as $line) {
                if ($linesShown >= 4) break;
                $lyricLine = truncateText($line, 12, $fontRegular, $maxTextW);
                drawCentredText($img, 12, $fontRegular, $lyricLine, $lyricsY, $greyLight, $W);
                $lyricsY += 22;
                $linesShown++;
            }
            break; /* Only first component */
        }
    }

    /* --- App branding (bottom centre, within safe zone) --- */
    $iconPath = __DIR__ . '/assets/icon-512.png';
    if (file_exists($iconPath)) {
        $icon = imagecreatefrompng($iconPath);
        if ($icon) {
            $iconSize = 28;
            $brandY = $H - 55;
            $iconX = ($W / 2) - 55;
            imagecopyresampled($img, $icon, (int)$iconX, $brandY, 0, 0, $iconSize, $iconSize, imagesx($icon), imagesy($icon));
            imagedestroy($icon);
            imagettftext($img, 12, 0, (int)$iconX + 34, $brandY + 20, $grey, $fontBold, 'iHymns');
        }
    }
}

/* =========================================================================
 * RENDER — GENERIC BRANDING IMAGE
 * ========================================================================= */
else {
    /* Load and composite the app icon — centred in safe zone */
    $iconPath = __DIR__ . '/assets/icon-512.png';
    if (file_exists($iconPath)) {
        $icon = imagecreatefrompng($iconPath);
        if ($icon) {
            $iconSize = 200;
            $iconX = (int)(($W - $iconSize) / 2);
            $iconY = 100;
            imagecopyresampled(
                $img, $icon,
                $iconX, $iconY, 0, 0,
                $iconSize, $iconSize,
                imagesx($icon), imagesy($icon)
            );
            imagedestroy($icon);
        }
    }

    /* App name — large, centred below icon */
    drawCentredText($img, 36, $fontBold, 'iHymns', 365, $white, $W);

    /* Tagline */
    drawCentredText($img, 16, $fontRegular, 'Christian Hymns & Worship Songs', 405, $grey, $W);

    /* Accent line */
    imagefilledrectangle($img, ($W / 2) - 120, 430, ($W / 2) + 120, 433, $accent);

    /* Domain */
    drawCentredText($img, 13, $fontRegular, 'ihymns.app', 460, $greyLight, $W);
}

/* =========================================================================
 * OUTPUT
 * ========================================================================= */
imagepng($img);
imagedestroy($img);
