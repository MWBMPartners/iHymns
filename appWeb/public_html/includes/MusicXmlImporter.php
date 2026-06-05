<?php

declare(strict_types=1);

/**
 * iHymns — MusicXML notation importer (#1096 / #1090 N7)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * Extracts the catalogue-useful content from a MusicXML score — title,
 * lyricist/composer credits, key signature, time signature, and the sung
 * lyrics (per verse) — so a curator can ingest engraved notation rather
 * than retype it. MusicXML is a well-specified, widely-exported format
 * (MuseScore, Finale, Sibelius, Dorico all emit it), which is why #1096
 * marks it "do now" ahead of the proprietary formats.
 *
 * Handles both container shapes:
 *   - plain `.musicxml` / `.xml` (score-partwise root), and
 *   - compressed `.mxl` (a zip whose META-INF/container.xml points at the
 *     rootfile; we resolve it, falling back to the first *.xml that isn't
 *     the container).
 *
 * Reliable vs best-effort:
 *   - Title, creators, <key><fifths>, and <time> are unambiguous in the
 *     spec and extracted with confidence.
 *   - Lyrics are best-effort: MusicXML attaches syllables to notes
 *     (<lyric number="1"><syllabic>begin</syllabic><text>A</text></lyric>)
 *     with NO explicit line breaks, so we join syllables into words per
 *     the syllabic value and segment lines on system/page print breaks
 *     when present — otherwise we emit the verse as one block and SAY SO
 *     in `warnings` rather than guessing silently (same honest-degradation
 *     contract as PptxImporter / the #1109 diagnostics path).
 *
 * Pure parsing — NO database writes. The caller (editor bulk-import
 * dispatch) decides what to do with the result: preview, smart-match,
 * create/enrich a song (writing tblSongComponents + ChordsJson +
 * tblSongArrangements.KeySignature), and store the source blob in
 * tblSongMedia (Kind 'musicxml').
 *
 * @requires PHP 8.1+ with ext-dom (+ ext-zip for .mxl)
 */
final class MusicXmlImporter
{
    /**
     * Parse a MusicXML file (plain or compressed) into structured content.
     *
     * @return array{
     *   ok: bool,
     *   error?: string,
     *   title: string,
     *   lyricist: string,
     *   composer: string,
     *   keySignature: ?string,
     *   timeSignature: ?string,
     *   verses: array<int,array{number:string,lines:string[]}>,
     *   confidence: string,
     *   warnings: string[]
     * }
     */
    public static function parseFile(string $path): array
    {
        if (!is_readable($path)) {
            return self::fail('File is not readable.');
        }
        $xml = self::readXml($path);
        if ($xml === null) {
            return self::fail('Could not read MusicXML (not a valid .musicxml/.xml or .mxl).');
        }
        return self::parseString($xml);
    }

    /**
     * Parse a raw MusicXML string. Split out so it is unit-testable without
     * touching the filesystem.
     *
     * @return array see parseFile()
     */
    public static function parseString(string $xml): array
    {
        $dom = new DOMDocument();
        /* Suppress libxml warnings on the (often verbose) engraver XML.
           LIBXML_NONET blocks network access; PHP 8 does not expand external
           entities by default — together these neutralise XXE/SSRF on the
           untrusted uploaded file. */
        $prev   = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) {
            return self::fail('The file is not well-formed XML.');
        }

        $root = $dom->documentElement;
        if ($root === null
            || !in_array($root->localName, ['score-partwise', 'score-timewise'], true)) {
            return self::fail('Not a MusicXML score (expected a <score-partwise> root).');
        }
        if ($root->localName === 'score-timewise') {
            /* score-timewise is legal but rare; the lyric walk below assumes
               partwise note order. Report rather than mis-extract. */
            return self::fail('score-timewise scores are not supported yet — re-export as score-partwise.');
        }

        $warnings = [];

        $title    = self::firstText($dom, ['work/work-title', 'movement-title']);
        $lyricist = self::creator($dom, ['lyricist', 'poet', 'writer']);
        $composer = self::creator($dom, ['composer']);
        $key      = self::keySignature($dom);
        $time     = self::timeSignature($dom);
        $verses   = self::lyrics($dom, $warnings);

        if ($verses === []) {
            $warnings[] = 'No sung lyrics were found in the score (notation may be melody-only). '
                        . 'Key/metadata were still extracted.';
        }

        $confidence = $verses !== [] ? 'medium' : 'low';

        return [
            'ok'            => true,
            'title'         => $title,
            'lyricist'      => $lyricist,
            'composer'      => $composer,
            'keySignature'  => $key,
            'timeSignature' => $time,
            'verses'        => $verses,
            'confidence'    => $confidence,
            'warnings'      => $warnings,
        ];
    }

    /**
     * Resolve the MusicXML payload from a path: plain XML as-is, or the
     * rootfile inside a compressed .mxl zip.
     */
    private static function readXml(string $path): ?string
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        /* A .mxl is a zip; its first bytes are "PK". Plain MusicXML starts
           with optional BOM/whitespace then "<". */
        if (str_starts_with($raw, "PK\x03\x04")) {
            return self::readMxl($path);
        }
        return $raw;
    }

    /** Resolve the rootfile inside a compressed .mxl archive. */
    private static function readMxl(string $path): ?string
    {
        if (!class_exists('ZipArchive')) {
            return null;
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        /* META-INF/container.xml names the rootfile. */
        $rootPath = null;
        $container = $zip->getFromName('META-INF/container.xml');
        if ($container !== false) {
            $cdom = new DOMDocument();
            $prev = libxml_use_internal_errors(true);
            if ($cdom->loadXML($container, LIBXML_NONET)) {
                $rf = $cdom->getElementsByTagName('rootfile');
                if ($rf->length > 0) {
                    $rootPath = $rf->item(0)->getAttribute('full-path') ?: null;
                }
            }
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        $xml = false;
        if ($rootPath !== null) {
            $xml = $zip->getFromName($rootPath);
        }
        if ($xml === false) {
            /* Fall back to the first non-container .xml in the archive. */
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name !== false
                    && str_ends_with(strtolower($name), '.xml')
                    && !str_starts_with($name, 'META-INF/')) {
                    $xml = $zip->getFromName($name);
                    break;
                }
            }
        }
        $zip->close();
        return $xml === false ? null : $xml;
    }

    /**
     * Extract lyrics grouped by verse (MusicXML <lyric number=>). Joins
     * syllables into words per the <syllabic> value and segments lines on
     * system/page print breaks when the engraver recorded them.
     *
     * @param string[] $warnings collects best-effort notices (by ref)
     * @return array<int,array{number:string,lines:string[]}>
     */
    private static function lyrics(DOMDocument $dom, array &$warnings): array
    {
        /* verseKey => ['words' => string (current line buffer),
                        'lines' => string[]] */
        $verses     = [];
        $sawBreak   = false;
        $hadLyrics  = false;

        $parts = $dom->getElementsByTagName('part');
        /* Lyrics usually live in the first part (the vocal line). Walk just
           the first part to avoid duplicating words from instrument staves. */
        $part = $parts->item(0);
        if ($part === null) {
            return [];
        }

        foreach (self::childElements($part, 'measure') as $measure) {
            foreach (self::childElements($measure) as $el) {
                if ($el->localName === 'print') {
                    if ($el->getAttribute('new-system') === 'yes'
                        || $el->getAttribute('new-page') === 'yes') {
                        /* Close the current line in every verse. */
                        foreach ($verses as &$v) {
                            if ($v['words'] !== '') {
                                $v['lines'][] = self::tidy($v['words']);
                                $v['words'] = '';
                            }
                        }
                        unset($v);
                        $sawBreak = true;
                    }
                    continue;
                }
                if ($el->localName !== 'note') {
                    continue;
                }
                foreach (self::childElements($el, 'lyric') as $lyric) {
                    $num  = $lyric->getAttribute('number');
                    if ($num === '') {
                        $num = $lyric->getAttribute('name') ?: '1';
                    }
                    $textEl = self::firstChild($lyric, 'text');
                    if ($textEl === null) {
                        continue;
                    }
                    $syl  = self::firstChild($lyric, 'syllabic');
                    $mode = $syl !== null ? $syl->textContent : 'single';
                    $text = $textEl->textContent;

                    if (!isset($verses[$num])) {
                        $verses[$num] = ['words' => '', 'lines' => []];
                    }
                    $hadLyrics = true;

                    /* begin/middle continue the current word (no space);
                       single/end finish it (trailing space separates words). */
                    if ($mode === 'begin' || $mode === 'middle') {
                        $verses[$num]['words'] .= $text;
                    } else { // single | end | (unknown)
                        $verses[$num]['words'] .= $text . ' ';
                    }
                }
            }
        }

        if (!$hadLyrics) {
            return [];
        }

        /* Flush any trailing buffered words into a final line. */
        foreach ($verses as &$v) {
            if ($v['words'] !== '') {
                $v['lines'][] = self::tidy($v['words']);
                $v['words'] = '';
            }
        }
        unset($v);

        if (!$sawBreak) {
            $warnings[] = 'The score had no system/page break hints, so each verse was '
                        . 'extracted as a single line — review the line breaks before saving.';
        }

        /* Stable verse order by numeric key where possible. */
        uksort($verses, static function ($a, $b): int {
            $na = is_numeric($a) ? (int)$a : PHP_INT_MAX;
            $nb = is_numeric($b) ? (int)$b : PHP_INT_MAX;
            return $na <=> $nb ?: strcmp((string)$a, (string)$b);
        });

        $out = [];
        foreach ($verses as $num => $v) {
            $lines = array_values(array_filter($v['lines'], static fn(string $l): bool => $l !== ''));
            if ($lines !== []) {
                $out[] = ['number' => (string)$num, 'lines' => $lines];
            }
        }
        return $out;
    }

    /** Map <key><fifths> (+ optional <mode>) to a key name like "G" / "Em". */
    private static function keySignature(DOMDocument $dom): ?string
    {
        $keys = $dom->getElementsByTagName('key');
        if ($keys->length === 0) {
            return null;
        }
        $key = $keys->item(0);
        $fifthsEl = self::firstChild($key, 'fifths');
        if ($fifthsEl === null || !is_numeric(trim($fifthsEl->textContent))) {
            return null;
        }
        $fifths = (int)trim($fifthsEl->textContent);
        if ($fifths < -7 || $fifths > 7) {
            return null;
        }
        $modeEl = self::firstChild($key, 'mode');
        $minor  = $modeEl !== null && strtolower(trim($modeEl->textContent)) === 'minor';

        $major = ['Cb','Gb','Db','Ab','Eb','Bb','F','C','G','D','A','E','B','F#','C#'];
        $minorK = ['Abm','Ebm','Bbm','Fm','Cm','Gm','Dm','Am','Em','Bm','F#m','C#m','G#m','D#m','A#m'];
        $idx = $fifths + 7; // -7..7 → 0..14
        return $minor ? $minorK[$idx] : $major[$idx];
    }

    /** Map <time><beats>/<beat-type> to "4/4" etc. */
    private static function timeSignature(DOMDocument $dom): ?string
    {
        $times = $dom->getElementsByTagName('time');
        if ($times->length === 0) {
            return null;
        }
        $time  = $times->item(0);
        $beats = self::firstChild($time, 'beats');
        $type  = self::firstChild($time, 'beat-type');
        if ($beats === null || $type === null) {
            return null;
        }
        $b = trim($beats->textContent);
        $t = trim($type->textContent);
        if (!ctype_digit($b) || !ctype_digit($t)) {
            return null;
        }
        return $b . '/' . $t;
    }

    /** Read <identification><creator type="..."> by any of the given types. */
    private static function creator(DOMDocument $dom, array $types): string
    {
        foreach ($dom->getElementsByTagName('creator') as $c) {
            if (in_array(strtolower($c->getAttribute('type')), $types, true)) {
                $v = self::tidy($c->textContent);
                if ($v !== '') {
                    return $v;
                }
            }
        }
        return '';
    }

    /** First non-empty text at any of the given slash-paths (from root). */
    private static function firstText(DOMDocument $dom, array $paths): string
    {
        foreach ($paths as $path) {
            $parts = explode('/', $path);
            $nodes = [$dom->documentElement];
            foreach ($parts as $name) {
                $next = [];
                foreach ($nodes as $n) {
                    if ($n === null) {
                        continue;
                    }
                    foreach (self::childElements($n, $name) as $child) {
                        $next[] = $child;
                    }
                }
                $nodes = $next;
            }
            foreach ($nodes as $n) {
                $v = self::tidy($n->textContent);
                if ($v !== '') {
                    return $v;
                }
            }
        }
        return '';
    }

    /** @return DOMElement[] direct element children, optionally by localName. */
    private static function childElements(DOMNode $node, ?string $localName = null): array
    {
        $out = [];
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE
                && ($localName === null || $child->localName === $localName)) {
                $out[] = $child;
            }
        }
        return $out;
    }

    private static function firstChild(DOMNode $node, string $localName): ?DOMElement
    {
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && $child->localName === $localName) {
                return $child;
            }
        }
        return null;
    }

    /** Normalise whitespace + decode stray entities + trim. */
    private static function tidy(string $s): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    /** @return array{ok:false,error:string,title:string,lyricist:string,composer:string,keySignature:null,timeSignature:null,verses:array,confidence:string,warnings:string[]} */
    private static function fail(string $msg): array
    {
        return [
            'ok'            => false,
            'error'         => $msg,
            'title'         => '',
            'lyricist'      => '',
            'composer'      => '',
            'keySignature'  => null,
            'timeSignature' => null,
            'verses'        => [],
            'confidence'    => 'low',
            'warnings'      => [$msg],
        ];
    }
}
