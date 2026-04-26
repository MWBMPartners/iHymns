<?php

declare(strict_types=1);

/**
 * iHymns — Dynamic Open Graph Image Generator
 *
 * PURPOSE:
 * Generates social sharing preview images (OG images) for link previews
 * in iMessage, Facebook, Twitter/X, LinkedIn, Slack, Discord, WhatsApp,
 * Telegram, Pinterest, and Android share sheets.
 *
 * MODES:
 *   /og-image.php                   — Generic app branding image
 *   /og-image.php?song=CP-1        — Song-specific image (#173)
 *   /og-image.php?songbook=CP      — Songbook-specific image
 *   /og-image.php?setlist=abc123   — Shared setlist image
 *
 * LAYOUT:
 *   All critical content is centred within a ~630x630 "safe zone" so that
 *   iMessage's square centre-crop still shows the key information (#172).
 *
 * OUTPUT:
 *   PNG image, 1200x630px, Content-Type: image/png
 *
 * PLATFORM COMPATIBILITY:
 *   - Facebook/LinkedIn: 1200x630 (1.91:1) — native
 *   - Twitter/X: summary_large_image uses 1200x628 — near-identical
 *   - WhatsApp: Crops to ~1.91:1, falls back to square thumbnail — safe zone handles both
 *   - iMessage: Centre-crops to ~630x630 square — safe zone designed for this
 *   - Slack/Discord: 1200x630 — native
 *   - Telegram: Uses 1200x630 — native
 *   - Pinterest: Can crop tall; safe zone keeps content visible
 *   - Google Search: Rich results use this image — native
 *   - Android share sheets: Uses og:image directly — native
 */

/* =========================================================================
 * BOOTSTRAP (minimal — only what we need for song/setlist data)
 * ========================================================================= */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'SongData.php';

/* Cache for 24 hours — images rarely change */
header('Cache-Control: public, max-age=86400');
header('Content-Type: image/png');

/* =========================================================================
 * CONSTANTS
 * ========================================================================= */
$W = 1200;
$H = 630;

/* Safe zone for iMessage square crop — centred 630x630 */
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
 * DETECT MODE — generic, song, songbook, or setlist
 * ========================================================================= */
$songId     = $_GET['song'] ?? null;
$songbookId = $_GET['songbook'] ?? null;
$setlistId  = $_GET['setlist'] ?? null;

$songInfo    = null;
$bookInfo    = null;
$setlistInfo = null;
$mode        = 'generic';

try {
    $songData = new SongData();

    if ($songId !== null && preg_match('/^[A-Za-z]+-\d+$/', $songId)) {
        $songInfo = $songData->getSongById($songId);
        if ($songInfo !== null) $mode = 'song';
    } elseif ($songbookId !== null && preg_match('/^[A-Za-z]+$/', $songbookId)) {
        $bookInfo = $songData->getSongbook($songbookId);
        if ($bookInfo !== null) $mode = 'songbook';
    } elseif ($setlistId !== null) {
        $cleanId = preg_replace('/[^a-f0-9]/', '', strtolower(trim($setlistId)));
        if ($cleanId !== '' && strlen($cleanId) <= 16) {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'SharedSetlist.php';
            $setlistInfo = sharedSetlistGet($cleanId);
            if (is_array($setlistInfo)) $mode = 'setlist';
        }
    }
} catch (\Throwable $e) {
    /* Fall through to generic image. Logged so admins notice when
       share-preview generation is silently degraded — previously this
       catch swallowed every DB / SongData / SharedSetlist failure with
       no signal. (#526) */
    error_log('[og-image] fallback to generic — ' . $e->getMessage());
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
$greyDark  = imagecolorallocate($img, 80, 85, 100);
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

/**
 * Word-wrap text into multiple lines that fit within maxWidth.
 * Returns an array of line strings.
 */
function wordWrapText(string $text, float $size, string $font, int $maxWidth, int $maxLines = 2): array
{
    $bbox = imagettfbbox($size, 0, $font, $text);
    if (abs($bbox[4] - $bbox[0]) <= $maxWidth) {
        return [$text];
    }

    $words = explode(' ', $text);
    $lines = [];
    $current = '';

    foreach ($words as $word) {
        $test = $current === '' ? $word : $current . ' ' . $word;
        $testBbox = imagettfbbox($size, 0, $font, $test);
        if (abs($testBbox[4] - $testBbox[0]) <= $maxWidth) {
            $current = $test;
        } else {
            if ($current !== '') {
                $lines[] = $current;
            }
            $current = $word;
            if (count($lines) >= $maxLines - 1) {
                /* Last allowed line — truncate remainder */
                $remaining = implode(' ', array_slice($words, array_search($word, $words)));
                $lines[] = truncateText($remaining, $size, $font, $maxWidth);
                return $lines;
            }
        }
    }
    if ($current !== '') {
        $lines[] = $current;
    }

    return array_slice($lines, 0, $maxLines);
}

/**
 * Draw the iHymns branding at the bottom-centre of the image.
 */
function drawBranding(GdImage $img, int $W, int $H, int $grey, string $fontBold): void
{
    $iconPath = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'icon-512.png';
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
 * RENDER — SONG-SPECIFIC IMAGE
 * ========================================================================= */
if ($mode === 'song') {
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

    $titleSize = 32;
    $titleLines = wordWrapText($title, $titleSize, $fontBold, $maxTextW, 2);
    $titleY = 160;
    foreach ($titleLines as $i => $line) {
        drawCentredText($img, $titleSize, $fontBold, $line, $titleY + ($i * 44), $white, $W);
    }
    $titleBottom = $titleY + ((count($titleLines) - 1) * 44);

    /* --- Songbook name (below title) --- */
    $bookName = $songInfo['songbookName'] ?? '';
    if ($bookName !== '') {
        drawCentredText($img, 16, $fontRegular, $bookName, $titleBottom + 50, $bookAccent, $W);
    }

    /* --- Writers (below songbook name) --- */
    $writers = $songInfo['writers'] ?? [];
    if (!empty($writers)) {
        $writerText = implode(', ', $writers);
        $writerText = truncateText($writerText, 13, $fontRegular, $maxTextW);
        drawCentredText($img, 13, $fontRegular, $writerText, $titleBottom + 80, $grey, $W);
    }

    /* --- Accent line --- */
    $lineY = $titleBottom + 105;
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

    /* --- App branding (bottom centre) --- */
    drawBranding($img, $W, $H, $grey, $fontBold);
}

/* =========================================================================
 * RENDER — SONGBOOK-SPECIFIC IMAGE
 * ========================================================================= */
elseif ($mode === 'songbook') {
    $bookId = strtoupper($bookInfo['id'] ?? 'Misc');
    $accentRgb = $songbookColours[$bookId] ?? $songbookColours['Misc'];
    $bookAccent = imagecolorallocate($img, $accentRgb[0], $accentRgb[1], $accentRgb[2]);

    /* --- Songbook accent bar (left side) --- */
    imagefilledrectangle($img, 0, 0, 6, $H, $bookAccent);

    /* --- Large songbook abbreviation badge (centred, top area) --- */
    $badgeW = 140;
    $badgeH = 56;
    $badgeX = (int)(($W - $badgeW) / 2);
    $badgeY = 100;
    drawRoundedRect($img, $badgeX, $badgeY, $badgeX + $badgeW, $badgeY + $badgeH, 10, $bookAccent);

    /* Badge text — abbreviation */
    $abbr = $bookInfo['id'] ?? '';
    $abbrBbox = imagettfbbox(22, 0, $fontBold, $abbr);
    $abbrW = abs($abbrBbox[4] - $abbrBbox[0]);
    imagettftext($img, 22, 0, $badgeX + (int)(($badgeW - $abbrW) / 2), $badgeY + 40, $white, $fontBold, $abbr);

    /* --- Songbook full name --- */
    $bookName = $bookInfo['name'] ?? 'Songbook';
    $maxTextW = (int)($H - 60);
    $nameLines = wordWrapText($bookName, 28, $fontBold, $maxTextW, 2);
    $nameY = 220;
    foreach ($nameLines as $i => $line) {
        drawCentredText($img, 28, $fontBold, $line, $nameY + ($i * 38), $white, $W);
    }
    $nameBottom = $nameY + ((count($nameLines) - 1) * 38);

    /* --- Song count --- */
    $songCount = (int)($bookInfo['songCount'] ?? 0);
    $countText = number_format($songCount) . ' ' . ($songCount === 1 ? 'song' : 'songs');
    drawCentredText($img, 16, $fontRegular, $countText, $nameBottom + 45, $bookAccent, $W);

    /* --- Accent line --- */
    $lineY = $nameBottom + 75;
    imagefilledrectangle($img, ($W / 2) - 100, $lineY, ($W / 2) + 100, $lineY + 2, $bookAccent);

    /* --- Subtitle --- */
    drawCentredText($img, 14, $fontRegular, 'Browse hymns and worship songs', $lineY + 35, $grey, $W);

    /* --- App branding (bottom centre) --- */
    drawBranding($img, $W, $H, $grey, $fontBold);
}

/* =========================================================================
 * RENDER — SHARED SETLIST IMAGE
 * ========================================================================= */
elseif ($mode === 'setlist') {
    $setlistName = $setlistInfo['name'] ?? 'Shared Set List';
    $songs = $setlistInfo['songs'] ?? [];
    $songCount = count($songs);

    /* --- Purple accent bar (left side) for setlists --- */
    imagefilledrectangle($img, 0, 0, 6, $H, $accent);

    /* --- Setlist icon area (list icon placeholder) --- */
    $iconY = 65;
    /* Draw a simple list icon using rectangles */
    $iconCx = (int)($W / 2);
    for ($i = 0; $i < 3; $i++) {
        $dotY = $iconY + ($i * 16);
        /* Bullet dot */
        imagefilledellipse($img, $iconCx - 20, $dotY + 5, 6, 6, $accent);
        /* Line */
        imagefilledrectangle($img, $iconCx - 10, $dotY + 2, $iconCx + 30, $dotY + 8, $greyDark);
    }

    /* --- "SHARED SET LIST" label --- */
    drawCentredText($img, 11, $fontBold, 'SHARED SET LIST', 140, $accent, $W);

    /* --- Setlist name (large) --- */
    $maxTextW = (int)($H - 60);
    $nameLines = wordWrapText($setlistName, 28, $fontBold, $maxTextW, 2);
    $nameY = 200;
    foreach ($nameLines as $i => $line) {
        drawCentredText($img, 28, $fontBold, $line, $nameY + ($i * 38), $white, $W);
    }
    $nameBottom = $nameY + ((count($nameLines) - 1) * 38);

    /* --- Song count --- */
    $countText = number_format($songCount) . ' ' . ($songCount === 1 ? 'song' : 'songs');
    drawCentredText($img, 16, $fontRegular, $countText, $nameBottom + 45, $accent, $W);

    /* --- Accent line --- */
    $lineY = $nameBottom + 75;
    imagefilledrectangle($img, ($W / 2) - 100, $lineY, ($W / 2) + 100, $lineY + 2, $accent);

    /* --- Song titles preview (up to 5) --- */
    $previewY = $lineY + 30;
    $maxSongs = min(5, $songCount);
    if ($maxSongs > 0 && isset($songData)) {
        for ($i = 0; $i < $maxSongs; $i++) {
            if ($previewY > $H - 70) break; /* Leave room for branding */
            $sid = $songs[$i] ?? '';
            if (!is_string($sid)) continue;

            /* Try to resolve the song title */
            $previewTitle = $sid;
            try {
                $resolved = $songData->getSongById($sid);
                if ($resolved) {
                    $previewTitle = ($i + 1) . '. ' . $resolved['title'];
                } else {
                    $previewTitle = ($i + 1) . '. ' . $sid;
                }
            } catch (\Throwable $e) {
                $previewTitle = ($i + 1) . '. ' . $sid;
            }

            $previewTitle = truncateText($previewTitle, 12, $fontRegular, $maxTextW);
            drawCentredText($img, 12, $fontRegular, $previewTitle, $previewY, $greyLight, $W);
            $previewY += 22;
        }
        if ($songCount > $maxSongs) {
            $moreText = '+ ' . ($songCount - $maxSongs) . ' more';
            drawCentredText($img, 11, $fontRegular, $moreText, $previewY + 5, $greyDark, $W);
        }
    }

    /* --- App branding (bottom centre) --- */
    drawBranding($img, $W, $H, $grey, $fontBold);
}

/* =========================================================================
 * RENDER — GENERIC BRANDING IMAGE
 * ========================================================================= */
else {
    /* Load and composite the app icon — centred in safe zone */
    $iconPath = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'icon-512.png';
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
