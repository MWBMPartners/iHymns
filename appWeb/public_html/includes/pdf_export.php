<?php

declare(strict_types=1);

/**
 * iHymns — Setlist PDF Export (#302)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Generates a print-friendly PDF of a setlist with all song lyrics.
 * Uses basic PHP GD or a lightweight HTML-to-PDF approach.
 * For full-featured PDF generation, consider integrating TCPDF or FPDF.
 *
 * This initial implementation generates a simple text-based PDF
 * using PHP's built-in capabilities without external dependencies.
 *
 * @requires PHP 8.1+
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * Generate a simple PDF of a setlist's songs.
 *
 * Returns raw PDF bytes. The caller should set appropriate headers:
 *   header('Content-Type: application/pdf');
 *   header('Content-Disposition: inline; filename="setlist.pdf"');
 *
 * @param string $setlistName  Name of the setlist
 * @param array  $songs        Array of full song objects (with components)
 * @return string Raw PDF content
 */
function generateSetlistPdf(string $setlistName, array $songs): string
{
    $pages = [];
    $pageWidth  = 595;  /* A4 width in points */
    $pageHeight = 842;  /* A4 height in points */
    $margin     = 50;
    $lineHeight = 14;
    $titleSize  = 18;
    $bodySize   = 11;

    /* Build page content as structured text */
    foreach ($songs as $index => $song) {
        $pageLines = [];
        $pageLines[] = ['type' => 'title', 'text' => ($index + 1) . '. ' . ($song['title'] ?? 'Untitled')];
        $pageLines[] = ['type' => 'subtitle', 'text' => ($song['songbookName'] ?? '') . ' #' . ($song['number'] ?? '')];

        if (!empty($song['writers'])) {
            $pageLines[] = ['type' => 'meta', 'text' => 'Words: ' . implode(', ', $song['writers'])];
        }
        if (!empty($song['composers'])) {
            $pageLines[] = ['type' => 'meta', 'text' => 'Music: ' . implode(', ', $song['composers'])];
        }

        $pageLines[] = ['type' => 'spacer', 'text' => ''];

        foreach ($song['components'] ?? [] as $comp) {
            $label = ucfirst($comp['type'] ?? 'verse');
            if (isset($comp['number']) && $comp['number'] > 0) {
                $label .= ' ' . $comp['number'];
            }
            $pageLines[] = ['type' => 'label', 'text' => $label];

            foreach ($comp['lines'] ?? [] as $line) {
                $pageLines[] = ['type' => 'lyric', 'text' => $line];
            }
            $pageLines[] = ['type' => 'spacer', 'text' => ''];
        }

        $pages[] = $pageLines;
    }

    /* Generate minimal valid PDF */
    return _buildSimplePdf($setlistName, $pages, $pageWidth, $pageHeight, $margin);
}

/**
 * Build a minimal valid PDF document.
 * This is a lightweight PDF generator that creates text-only pages
 * without requiring external libraries.
 */
function _buildSimplePdf(string $title, array $pages, int $w, int $h, int $margin): string
{
    $objects = [];
    $objectOffsets = [];
    $pageObjectIds = [];

    /* Object 1: Catalog */
    $objects[1] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj";

    /* Object 2: Pages (will be updated with page refs) */
    /* Object 3: Font */
    $objects[3] = "3 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj";
    $objects[4] = "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\nendobj";

    $nextId = 5;

    foreach ($pages as $pageLines) {
        /* Build content stream */
        $stream = "BT\n";
        $y = $h - $margin;

        foreach ($pageLines as $line) {
            if ($y < $margin + 30) break; /* Stop before bottom margin */

            switch ($line['type']) {
                case 'title':
                    $stream .= "/F2 16 Tf\n";
                    $stream .= "{$margin} {$y} Td\n";
                    $stream .= "(" . _pdfEscape($line['text']) . ") Tj\n";
                    $y -= 22;
                    break;
                case 'subtitle':
                    $stream .= "/F1 10 Tf\n";
                    $stream .= "{$margin} {$y} Td\n";
                    $stream .= "(" . _pdfEscape($line['text']) . ") Tj\n";
                    $y -= 14;
                    break;
                case 'meta':
                    $stream .= "/F1 9 Tf\n";
                    $stream .= "{$margin} {$y} Td\n";
                    $stream .= "(" . _pdfEscape($line['text']) . ") Tj\n";
                    $y -= 12;
                    break;
                case 'label':
                    $stream .= "/F2 11 Tf\n";
                    $stream .= "{$margin} {$y} Td\n";
                    $stream .= "(" . _pdfEscape($line['text']) . ") Tj\n";
                    $y -= 15;
                    break;
                case 'lyric':
                    $stream .= "/F1 11 Tf\n";
                    $stream .= "{$margin} {$y} Td\n";
                    $stream .= "(" . _pdfEscape($line['text']) . ") Tj\n";
                    $y -= 14;
                    break;
                case 'spacer':
                    $y -= 8;
                    break;
            }
        }

        /* Footer */
        $stream .= "/F1 8 Tf\n";
        $footerY = $margin - 20;
        $stream .= "{$margin} {$footerY} Td\n";
        $stream .= "(" . _pdfEscape($title . ' — iHymns') . ") Tj\n";

        $stream .= "ET\n";

        /* Content stream object */
        $contentId = $nextId++;
        $objects[$contentId] = "{$contentId} 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n{$stream}endstream\nendobj";

        /* Page object */
        $pageId = $nextId++;
        $objects[$pageId] = "{$pageId} 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$w} {$h}] /Contents {$contentId} 0 R /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> >>\nendobj";
        $pageObjectIds[] = $pageId;
    }

    /* Object 2: Pages */
    $pageRefs = implode(' ', array_map(fn($id) => "{$id} 0 R", $pageObjectIds));
    $objects[2] = "2 0 obj\n<< /Type /Pages /Kids [{$pageRefs}] /Count " . count($pageObjectIds) . " >>\nendobj";

    /* Build PDF file */
    $pdf = "%PDF-1.4\n";

    foreach ($objects as $id => $obj) {
        $objectOffsets[$id] = strlen($pdf);
        $pdf .= $obj . "\n";
    }

    /* Cross-reference table */
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . ($nextId) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i < $nextId; $i++) {
        $offset = $objectOffsets[$i] ?? 0;
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    $pdf .= "trailer\n<< /Size {$nextId} /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

    return $pdf;
}

/**
 * Escape special PDF characters in text.
 */
function _pdfEscape(string $text): string
{
    return str_replace(
        ['\\', '(', ')', "\r"],
        ['\\\\', '\\(', '\\)', ''],
        $text
    );
}
