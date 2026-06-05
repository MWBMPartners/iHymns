<?php

declare(strict_types=1);

/**
 * iHymns — PowerPoint (.pptx) worship-deck importer (#1095)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * Extracts lyrics from a PowerPoint Open-XML (.pptx) worship deck and segments
 * the slides into songs. Worship decks vary wildly in design, so this is a
 * best-effort heuristic that REPORTS its confidence rather than guessing
 * silently — low-confidence / unparseable decks route to the import-diagnostics
 * graceful-degradation path (#1109).
 *
 * The common, reliable pattern (verified against the repo's sample decks): a
 * TITLE slide carries the song title (often in quotes) plus a songbook reference
 * like "# 599-Mission Praise"; the slides that follow are that song's verses,
 * until the next title slide. Announcement slides before the first song are
 * ignored. Image-only slides yield no text and are counted as unparsed.
 *
 * Pure parsing — NO database writes. The caller (bulk-import dispatch) decides
 * what to do with the result (preview, smart-match to the catalogue via the
 * songbook ref, import, or submit-for-analysis).
 *
 * Legacy binary .ppt is NOT handled here (it needs a converter); the caller
 * routes .ppt to the convert-or-submit path.
 *
 * @requires PHP 8.1+ with ext-zip + ext-dom
 */
final class PptxImporter
{
    /** DrawingML namespace (a:p paragraphs, a:t text runs). */
    private const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    /**
     * Parse a .pptx file into songs.
     *
     * @return array{
     *   ok: bool,
     *   error?: string,
     *   slideCount: int,
     *   unparsedSlides: int,
     *   songs: array<int,array{title:string,songbookName:string,songNumber:?int,
     *           lines:string[],slideIndices:int[],confidence:string}>,
     *   warnings: string[]
     * }
     */
    public static function parseFile(string $path): array
    {
        if (!is_readable($path)) {
            return self::fail('File is not readable.');
        }
        if (!class_exists('ZipArchive')) {
            return self::fail('The zip extension is required to read .pptx files.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return self::fail('Not a valid .pptx (could not open as a zip archive).');
        }

        /* Collect slide entries and sort them numerically (slide2 before slide10). */
        $slideEntries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false && preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m)) {
                $slideEntries[(int)$m[1]] = $name;
            }
        }
        ksort($slideEntries);

        if ($slideEntries === []) {
            $zip->close();
            return self::fail('No slides found in the presentation.');
        }

        /* Extract ordered lines per slide. */
        $slides = [];          // 1-based slide index => string[] lines
        $unparsed = 0;
        $slideNo = 0;
        foreach ($slideEntries as $entry) {
            $slideNo++;
            $xml = $zip->getFromName($entry);
            $lines = $xml !== false ? self::extractLines($xml) : [];
            $slides[$slideNo] = $lines;
            if ($lines === []) {
                $unparsed++;
            }
        }
        $zip->close();

        $songs    = self::segment($slides);
        $warnings = [];
        if ($unparsed > 0) {
            $warnings[] = $unparsed . ' slide(s) had no extractable text (likely images of lyrics) and were skipped.';
        }
        if ($songs === []) {
            $warnings[] = 'No song boundaries were detected — this deck does not use the expected "Title + # number-Songbook" layout. Consider submitting it for analysis.';
        }

        return [
            'ok'             => $songs !== [],
            'slideCount'     => count($slides),
            'unparsedSlides' => $unparsed,
            'songs'          => $songs,
            'warnings'       => $warnings,
        ];
    }

    /**
     * Extract per-paragraph text lines from one slide's XML. Each <a:p> is one
     * line; its <a:t> runs are concatenated. Empty lines are dropped.
     *
     * @return string[]
     */
    private static function extractLines(string $xml): array
    {
        $dom = new DOMDocument();
        /* Suppress libxml warnings on the (occasionally messy) office XML.
           LIBXML_NONET blocks any network access; PHP 8 already does not expand
           external entities by default — together these neutralise XXE/SSRF on
           the untrusted uploaded XML. */
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) {
            return [];
        }

        $lines = [];
        foreach ($dom->getElementsByTagNameNS(self::NS_A, 'p') as $p) {
            /* A paragraph may itself contain several visual lines separated by
               <a:br/>. Walk the paragraph in document order: <a:t> appends text,
               <a:br/> ends the current line. */
            foreach (self::paragraphLines($p) as $line) {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    /**
     * Split one <a:p> paragraph into its visual lines, honouring <a:br/> breaks.
     *
     * @return string[]
     */
    private static function paragraphLines(DOMElement $p): array
    {
        $lines = [];
        $cur   = '';
        $walk = static function (DOMNode $node) use (&$walk, &$lines, &$cur): void {
            foreach ($node->childNodes as $child) {
                if ($child->nodeType !== XML_ELEMENT_NODE) {
                    continue;
                }
                $local = $child->localName;
                if ($local === 't') {
                    $cur .= $child->textContent;          // text run — append
                } elseif ($local === 'br') {
                    $lines[] = $cur;                       // explicit line break
                    $cur = '';
                } else {
                    $walk($child);                         // descend (e.g. <a:r>)
                }
            }
        };
        $walk($p);
        $lines[] = $cur;

        $out = [];
        foreach ($lines as $l) {
            $l = self::tidy($l);
            if ($l !== '') {
                $out[] = $l;
            }
        }
        return $out;
    }

    /**
     * Segment ordered slides into songs. A slide carrying a songbook reference
     * ("# 599-Mission Praise") starts a new song; following text slides are its
     * verses; preamble slides before the first song are ignored.
     *
     * @param array<int,string[]> $slides 1-based index => lines
     * @return array<int,array{title:string,songbookName:string,songNumber:?int,lines:string[],slideIndices:int[],confidence:string}>
     */
    private static function segment(array $slides): array
    {
        $songs   = [];
        $current = null;

        foreach ($slides as $idx => $lines) {
            if ($lines === []) {
                continue; // image-only / blank
            }
            $ref = self::detectSongbookRef($lines);

            if ($ref !== null) {
                /* New song. */
                if ($current !== null) {
                    $songs[] = $current;
                }
                $current = [
                    'title'        => self::extractTitle($lines, $ref['line']),
                    'songbookName' => $ref['book'],
                    'songNumber'   => $ref['number'],
                    'lines'        => [],
                    'slideIndices' => [$idx],
                    'confidence'   => 'high',
                ];
                continue;
            }

            if ($current !== null) {
                /* Verse slide for the current song — skip the repeated slide
                   footer/attribution ("599. Title" / "Mission Praise"). */
                $added = false;
                foreach ($lines as $l) {
                    if (self::isFooterLine($l, $current)) {
                        continue;
                    }
                    $current['lines'][] = $l;
                    $added = true;
                }
                if ($added) {
                    $current['slideIndices'][] = $idx;
                }
            }
            /* else: preamble (announcements before the first song) — ignore. */
        }
        if ($current !== null) {
            $songs[] = $current;
        }
        return $songs;
    }

    /**
     * Detect a songbook reference line like "# 599-Mission Praise" or
     * "# 88 – Mission Praise" (various dashes/spacing).
     *
     * @param string[] $lines
     * @return array{number:int,book:string,line:string}|null
     */
    private static function detectSongbookRef(array $lines): ?array
    {
        foreach ($lines as $line) {
            if (preg_match('/#\s*(\d+)\s*[-\x{2010}-\x{2015}]\s*(.+)/u', $line, $m)) {
                return [
                    'number' => (int)$m[1],
                    'book'   => self::tidy($m[2]),
                    'line'   => $line,
                ];
            }
        }
        return null;
    }

    /**
     * Pick the song title from a title slide's lines (excluding the ref line).
     * Prefer a quoted line ("Sing A New Song To The Lord"); else the longest
     * remaining non-reference line.
     *
     * @param string[] $lines
     */
    private static function extractTitle(array $lines, string $refLine): string
    {
        $candidates = [];
        foreach ($lines as $l) {
            if ($l === $refLine) {
                continue;
            }
            $candidates[] = $l;
        }
        /* Prefer an explicitly quoted line. */
        foreach ($candidates as $c) {
            if (preg_match('/^["\x{201C}\x{201D}\x{2018}\x{2019}\'](.+?)["\x{201C}\x{201D}\x{2018}\x{2019}\']$/u', $c, $m)) {
                return self::tidy($m[1]);
            }
        }
        /* Else the longest candidate (titles are usually longer than a "Praise & Worship" header tag). */
        usort($candidates, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        return $candidates !== [] ? self::tidy($candidates[0]) : '(untitled)';
    }

    /**
     * Is this line the repeated per-slide footer/attribution rather than lyrics?
     * Conservative — only the "599. Some Title" attribution line and the bare
     * songbook name. We deliberately do NOT drop a line that merely equals the
     * title, because a worship song's first verse line is frequently identical
     * to its title ("Sing a new song to the Lord").
     *
     * @param array{title:string,songbookName:string,songNumber:?int} $song
     */
    private static function isFooterLine(string $line, array $song): bool
    {
        if (preg_match('/^\d+\.\s/u', $line)) {
            return true; // "599. Sing A New Song To The Lord"
        }
        if ($song['songbookName'] !== ''
            && mb_strtolower($line) === mb_strtolower($song['songbookName'])) {
            return true; // "Mission Praise"
        }
        return false;
    }

    /** Normalise whitespace + decode stray entities + trim. */
    private static function tidy(string $s): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    /** @return array{ok:false,error:string,slideCount:int,unparsedSlides:int,songs:array,warnings:string[]} */
    private static function fail(string $msg): array
    {
        return ['ok' => false, 'error' => $msg, 'slideCount' => 0, 'unparsedSlides' => 0, 'songs' => [], 'warnings' => [$msg]];
    }
}
