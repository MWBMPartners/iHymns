<?php
/**
 * lyrics_ingest.php — TTML (and timed-lyrics) ingest core (#1064)
 * ===============================================================
 *
 * Parses Apple-Music-style **TTML** with word + syllable timing into the
 * normalized lyrics-timing schema (tblLyrics → tblLyricLines → tblLyricWords
 * → tblLyricSyllables, #1047 / #141), and writes it transactionally.
 *
 * This is the shared "core receiver": the iHymns HTTP ingest endpoint (#1064)
 * and the iLyricsDB shared-DB receiver (#146) both call into here, and the
 * MeedyaDL pusher (#907) produces the TTML these functions consume. It is
 * deliberately framework-free (just functions + a mysqli) so it can move into
 * the shared backend unchanged when iHymns/iLyricsDB merge.
 *
 * Auth is the CALLER's responsibility — these functions never read $_SERVER /
 * sessions. Parsing (`lyricsIngest_parseTtml`) does no I/O and is unit-tested.
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

declare(strict_types=1);

/**
 * Convert a TTML time expression to integer milliseconds.
 *   - clock-time:   "00:00:27.300", "01:27.300" (MM:SS)
 *   - offset-time:  "27.300s", "27.300", "27300ms", "1.5m", "2h"
 * Returns null for an empty / unparseable value.
 */
function _ttmlTimeToMs(?string $expr): ?int
{
    if ($expr === null) { return null; }
    $e = trim($expr);
    if ($e === '') { return null; }

    /* HH:MM:SS(.fff) */
    if (preg_match('/^(\d+):(\d{1,2}):(\d{1,2}(?:\.\d+)?)$/', $e, $m)) {
        return (int) round(((int)$m[1] * 3600 + (int)$m[2] * 60 + (float)$m[3]) * 1000);
    }
    /* MM:SS(.fff) */
    if (preg_match('/^(\d+):(\d{1,2}(?:\.\d+)?)$/', $e, $m)) {
        return (int) round(((int)$m[1] * 60 + (float)$m[2]) * 1000);
    }
    /* offset-time with optional unit (default seconds, per Apple's decimal form) */
    if (preg_match('/^(\d+(?:\.\d+)?)(ms|s|m|h)?$/', $e, $m)) {
        $v = (float)$m[1];
        switch ($m[2] ?? 's') {
            case 'ms': return (int) round($v);
            case 's':  return (int) round($v * 1000);
            case 'm':  return (int) round($v * 60000);
            case 'h':  return (int) round($v * 3600000);
        }
    }
    return null;
}

/** Local name of a DOM node, namespace-agnostic. */
function _ttmlLocalName(\DOMNode $n): string
{
    return $n->localName !== null ? strtolower((string)$n->localName) : strtolower($n->nodeName);
}

/** Read a TTML timing attribute (begin/end) off an element → ms (or null). */
function _ttmlAttrMs(\DOMElement $el, string $name): ?int
{
    return $el->hasAttribute($name) ? _ttmlTimeToMs($el->getAttribute($name)) : null;
}

/**
 * Collect the lossless TTML attributes worth keeping (roles, agents, keys,
 * song-part) off an element into an assoc array, or null if none.
 */
function _ttmlMeta(\DOMElement $el): ?array
{
    $meta = [];
    /* DOM exposes namespaced attrs by qualified name; probe the ones Apple/TTML use. */
    static $probe = [
        'ttm:role', 'ttm:agent', 'itunes:key', 'itunes:song-part',
        'itunes:songPart', 'role', 'agent', 'xml:lang',
    ];
    foreach ($el->attributes as $attr) {
        /** @var \DOMAttr $attr */
        $qn = $attr->nodeName; // qualified (prefixed) name
        $ln = strtolower((string)$attr->localName);
        if (in_array($ln, ['begin', 'end'], true)) { continue; }
        if (in_array($qn, $probe, true) || in_array($ln, ['role', 'agent', 'key', 'song-part', 'songpart'], true)) {
            $meta[$qn] = $attr->value;
        }
    }
    return $meta === [] ? null : $meta;
}

/** Direct child <span> elements of $el (one level). */
function _ttmlChildSpans(\DOMElement $el): array
{
    $out = [];
    foreach ($el->childNodes as $c) {
        if ($c->nodeType === XML_ELEMENT_NODE && _ttmlLocalName($c) === 'span') {
            $out[] = $c;
        }
    }
    return $out;
}

/**
 * Parse a TTML document into a neutral timed-lyrics structure:
 *
 *   [
 *     'language'          => 'en'|null,        // root xml:lang
 *     'hasTiming'         => bool,             // line-level
 *     'hasWordTiming'     => bool,
 *     'hasSyllableTiming' => bool,
 *     'lines' => [
 *       [ 'text','startMs','endMs','languageCode','isInstrumental','meta',
 *         'words' => [ [ 'text','startMs','endMs','meta',
 *                        'syllables' => [ ['text','startMs','endMs','meta'], … ] ], … ] ],
 *       …
 *     ],
 *   ]
 *
 * @throws \RuntimeException on malformed XML / not-TTML.
 */
function lyricsIngest_parseTtml(string $ttml): array
{
    $ttml = (string) preg_replace('/^\xEF\xBB\xBF/', '', $ttml);
    $prev = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $doc = new \DOMDocument();
    /* LIBXML_NONET blocks network fetches; we do NOT pass LIBXML_NOENT, so
       external/general entities are never expanded (XXE / billion-laughs
       hardening) — TTML is element/attribute data only. */
    $ok = $doc->loadXML($ttml, LIBXML_NONET | LIBXML_COMPACT);
    if ($ok === false) {
        $err = libxml_get_last_error();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        throw new \RuntimeException('invalid TTML XML' . ($err ? ': ' . trim($err->message) : ''));
    }
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $root = $doc->documentElement;
    if ($root === null || _ttmlLocalName($root) !== 'tt') {
        throw new \RuntimeException('root element is not <tt> (not a TTML document)');
    }

    $language = $root->hasAttribute('xml:lang') ? trim($root->getAttribute('xml:lang')) : null;
    if ($language === '') { $language = null; }

    $pNodes = $doc->getElementsByTagNameNS('*', 'p');
    $lines  = [];
    $hasTiming = false;
    $hasWordTiming = false;
    $hasSyllableTiming = false;

    foreach ($pNodes as $p) {
        /** @var \DOMElement $p */
        $lineStart = _ttmlAttrMs($p, 'begin');
        $lineEnd   = _ttmlAttrMs($p, 'end');
        if ($lineStart !== null) { $hasTiming = true; }

        $lineLang = $p->hasAttribute('xml:lang') ? trim($p->getAttribute('xml:lang')) : null;
        if ($lineLang === '') { $lineLang = null; }

        /* Build words + syllables by walking the <p>'s children in order.
           Consecutive leaf <span>s (no whitespace between) are syllables of
           one word; a whitespace text node ends a word; a <span> that itself
           contains child <span>s is a word with explicit syllables. */
        $words = [];
        $cur   = null;
        $flush = function () use (&$cur, &$words) {
            if ($cur !== null && trim($cur['text']) !== '') { $words[] = $cur; }
            $cur = null;
        };
        $newWord = static function (): array {
            return ['text' => '', 'startMs' => null, 'endMs' => null, 'meta' => null, 'syllables' => []];
        };

        foreach ($p->childNodes as $node) {
            if ($node->nodeType === XML_ELEMENT_NODE && _ttmlLocalName($node) === 'span') {
                /** @var \DOMElement $node */
                $nested = _ttmlChildSpans($node);
                if (!empty($nested)) {
                    $flush();
                    $w = $newWord();
                    $w['startMs'] = _ttmlAttrMs($node, 'begin');
                    $w['endMs']   = _ttmlAttrMs($node, 'end');
                    $w['meta']    = _ttmlMeta($node);
                    foreach ($nested as $syl) {
                        $st = $syl->textContent;
                        $sMs = _ttmlAttrMs($syl, 'begin');
                        $eMs = _ttmlAttrMs($syl, 'end');
                        $w['syllables'][] = ['text' => $st, 'startMs' => $sMs, 'endMs' => $eMs, 'meta' => _ttmlMeta($syl)];
                        $w['text'] .= $st;
                        if ($w['startMs'] === null) { $w['startMs'] = $sMs; }
                        if ($eMs !== null) { $w['endMs'] = $eMs; }
                    }
                    $words[] = $w;
                } else {
                    if ($cur === null) { $cur = $newWord(); }
                    $st  = $node->textContent;
                    $sMs = _ttmlAttrMs($node, 'begin');
                    $eMs = _ttmlAttrMs($node, 'end');
                    $cur['syllables'][] = ['text' => $st, 'startMs' => $sMs, 'endMs' => $eMs, 'meta' => _ttmlMeta($node)];
                    $cur['text'] .= $st;
                    if ($cur['startMs'] === null) { $cur['startMs'] = $sMs; }
                    if ($eMs !== null) { $cur['endMs'] = $eMs; }
                }
            } elseif ($node->nodeType === XML_TEXT_NODE) {
                if (trim($node->textContent) === '') {
                    $flush(); /* whitespace = word boundary */
                } else {
                    if ($cur === null) { $cur = $newWord(); }
                    $cur['text'] .= $node->textContent;
                }
            }
        }
        $flush();

        /* Normalise each word: a single leaf syllable identical to the word
           carries no extra info, so we don't emit it as a syllable row;
           genuine multi-syllable words set hasSyllableTiming. */
        foreach ($words as &$w) {
            if ($w['startMs'] !== null) { $hasWordTiming = true; }
            if (count($w['syllables']) > 1) {
                $hasSyllableTiming = true;
            } else {
                $w['syllables'] = []; /* drop the redundant 1:1 syllable */
            }
        }
        unset($w);

        $lineText = trim((string) preg_replace('/\s+/u', ' ', $p->textContent));
        $isInstrumental = ($lineText === '');

        $lines[] = [
            'text'           => $lineText,
            'startMs'        => $lineStart,
            'endMs'          => $lineEnd,
            'languageCode'   => $lineLang,
            'isInstrumental' => $isInstrumental,
            'meta'           => _ttmlMeta($p),
            'words'          => $words,
        ];
    }

    if (empty($lines)) {
        throw new \RuntimeException('no <p> lines found in TTML <body>');
    }

    return [
        'language'          => $language,
        'hasTiming'         => $hasTiming,
        'hasWordTiming'     => $hasWordTiming,
        'hasSyllableTiming' => $hasSyllableTiming,
        'lines'             => $lines,
    ];
}

/**
 * Write a parsed timed-lyrics structure into the normalized schema for one
 * song, UPSERTing on (SongId, Source) — re-ingesting the same source replaces
 * its rows (CASCADE clears old lines/words/syllables) rather than duplicating.
 *
 * @param \mysqli $db
 * @param string  $songId   tblSongs.SongId (must exist).
 * @param array   $parsed   Output of lyricsIngest_parseTtml().
 * @param array   $opts     { source?, sourceUrl?, formatVersion?, isPrimary?,
 *                            isExplicit?, status?, submittedBy?, language? }
 * @return array { lyricsId, lines, words, syllables }
 * @throws \RuntimeException on a missing song or DB error.
 */
function lyricsIngest_writeToDb(\mysqli $db, string $songId, array $parsed, array $opts = []): array
{
    $source        = (string)($opts['source'] ?? 'applemusic-ttml');
    $sourceUrl     = isset($opts['sourceUrl']) ? (string)$opts['sourceUrl'] : null;
    $formatVersion = (string)($opts['formatVersion'] ?? 'ttml-1.0');
    $isPrimary     = !empty($opts['isPrimary']) ? 1 : 0;
    $isExplicit    = !empty($opts['isExplicit']) ? 1 : 0;
    $status        = (string)($opts['status'] ?? 'pending_review');
    $submittedBy   = isset($opts['submittedBy']) ? (int)$opts['submittedBy'] : null;

    /* Song must exist (FK would reject anyway, but a clear error is nicer). */
    $chk = $db->prepare('SELECT 1 FROM tblSongs WHERE SongId = ? LIMIT 1');
    $chk->bind_param('s', $songId);
    $chk->execute();
    $exists = $chk->get_result()->fetch_row() !== null;
    $chk->close();
    if (!$exists) {
        throw new \RuntimeException("song '$songId' not found");
    }

    $hasTiming = $parsed['hasTiming'] ? 1 : 0;
    $hasWord   = $parsed['hasWordTiming'] ? 1 : 0;
    $hasSyl    = $parsed['hasSyllableTiming'] ? 1 : 0;

    $db->begin_transaction();
    try {
        /* UPSERT tblLyrics on (SongId, Source). Delete the old row's lines
           first (CASCADE → words → syllables) so a re-ingest is clean. */
        $sel = $db->prepare('SELECT Id FROM tblLyrics WHERE SongId = ? AND Source = ? LIMIT 1');
        $sel->bind_param('ss', $songId, $source);
        $sel->execute();
        $row = $sel->get_result()->fetch_assoc();
        $sel->close();

        if ($row !== null) {
            $lyricsId = (int)$row['Id'];
            $del = $db->prepare('DELETE FROM tblLyricLines WHERE LyricsId = ?');
            $del->bind_param('i', $lyricsId);
            $del->execute();
            $del->close();
            $upd = $db->prepare(
                'UPDATE tblLyrics
                    SET SourceUrl = ?, FormatVersion = ?, IsPrimary = ?, IsExplicit = ?,
                        HasTiming = ?, HasWordTiming = ?, HasSyllableTiming = ?,
                        Status = ?, SubmittedBy = ?
                  WHERE Id = ?'
            );
            $upd->bind_param(
                'ssiiiiisii',
                $sourceUrl, $formatVersion, $isPrimary, $isExplicit,
                $hasTiming, $hasWord, $hasSyl, $status, $submittedBy, $lyricsId
            );
            $upd->execute();
            $upd->close();
        } else {
            $ins = $db->prepare(
                'INSERT INTO tblLyrics
                    (SongId, Source, SourceUrl, FormatVersion, IsPrimary, IsExplicit,
                     HasTiming, HasWordTiming, HasSyllableTiming, Status, SubmittedBy)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            );
            $ins->bind_param(
                'ssssiiiiisi',
                $songId, $source, $sourceUrl, $formatVersion, $isPrimary, $isExplicit,
                $hasTiming, $hasWord, $hasSyl, $status, $submittedBy
            );
            $ins->execute();
            $lyricsId = (int)$db->insert_id;
            $ins->close();
        }

        /* If this is now the primary lyrics, demote any other primary. */
        if ($isPrimary) {
            $dp = $db->prepare('UPDATE tblLyrics SET IsPrimary = 0 WHERE SongId = ? AND Id <> ?');
            $dp->bind_param('si', $songId, $lyricsId);
            $dp->execute();
            $dp->close();
        }

        $lineStmt = $db->prepare(
            'INSERT INTO tblLyricLines
                (LyricsId, SortOrder, LineText, StartTimeMs, EndTimeMs, LanguageCode, IsInstrumental, MetaJson)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $wordStmt = $db->prepare(
            'INSERT INTO tblLyricWords (LineId, SortOrder, WordText, StartTimeMs, EndTimeMs, MetaJson)
             VALUES (?,?,?,?,?,?)'
        );
        $sylStmt = $db->prepare(
            'INSERT INTO tblLyricSyllables (WordId, SortOrder, SyllableText, StartTimeMs, EndTimeMs, MetaJson)
             VALUES (?,?,?,?,?,?)'
        );

        $nLines = 0; $nWords = 0; $nSyl = 0;
        foreach ($parsed['lines'] as $li => $line) {
            $lineMeta = isset($line['meta']) ? json_encode($line['meta'], JSON_UNESCAPED_UNICODE) : null;
            $isInst   = !empty($line['isInstrumental']) ? 1 : 0;
            $lineText = (string)($line['text'] ?? '');
            $lStart   = $line['startMs']; $lEnd = $line['endMs'];
            $lLang    = $line['languageCode'] ?? null;
            $lineStmt->bind_param(
                'iisiisis',
                $lyricsId, $li, $lineText, $lStart, $lEnd, $lLang, $isInst, $lineMeta
            );
            $lineStmt->execute();
            $lineId = (int)$db->insert_id;
            $nLines++;

            foreach (($line['words'] ?? []) as $wi => $word) {
                $wMeta = isset($word['meta']) ? json_encode($word['meta'], JSON_UNESCAPED_UNICODE) : null;
                $wText = (string)($word['text'] ?? '');
                $wStart = $word['startMs']; $wEnd = $word['endMs'];
                $wordStmt->bind_param('iisiis', $lineId, $wi, $wText, $wStart, $wEnd, $wMeta);
                $wordStmt->execute();
                $wordId = (int)$db->insert_id;
                $nWords++;

                foreach (($word['syllables'] ?? []) as $si => $syl) {
                    $sMeta = isset($syl['meta']) ? json_encode($syl['meta'], JSON_UNESCAPED_UNICODE) : null;
                    $sText = (string)($syl['text'] ?? '');
                    $sStart = $syl['startMs']; $sEnd = $syl['endMs'];
                    $sylStmt->bind_param('iisiis', $wordId, $si, $sText, $sStart, $sEnd, $sMeta);
                    $sylStmt->execute();
                    $nSyl++;
                }
            }
        }
        $lineStmt->close();
        $wordStmt->close();
        $sylStmt->close();

        $db->commit();
        return ['lyricsId' => $lyricsId, 'lines' => $nLines, 'words' => $nWords, 'syllables' => $nSyl];
    } catch (\Throwable $e) {
        try { $db->rollback(); } catch (\Throwable $_) {}
        throw new \RuntimeException('lyrics ingest write failed: ' . $e->getMessage(), 0, $e);
    }
}
