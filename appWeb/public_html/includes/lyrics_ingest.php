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

/* ===========================================================================
 *  Song resolution + enrichment (#1064)
 * ---------------------------------------------------------------------------
 * When an external pusher (MeedyaDL #907) supplies Apple-Music metadata but no
 * songId, resolve the song: explicit songId → ISRC → normalized TITLE (with an
 * artist tiebreak) → CREATE a provisional song (in the canonical 'Misc'
 * songbook, Verified=0, surfaced for moderator review at /manage/duplicate-
 * songs). Then store the external IDs/URLs the payload carries so future
 * matches get stronger. Title-first, create-when-absent — matching the early-
 * days "most songs will be added" reality.
 * =========================================================================== */

/**
 * Resolve the payload to a tblSongs.SongId, creating a provisional song if
 * nothing matches. $lyricsText (joined line text) seeds a created song's
 * FULLTEXT search column.
 *
 * @return array{songId:string, matched:bool, created:bool}
 * @throws \RuntimeException on an explicit-but-missing songId, or no title to match/create.
 */
function lyricsIngest_resolveSong(\mysqli $db, array $payload, string $lyricsText = ''): array
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'title_normalize.php';

    /* 1. Explicit songId → verify it exists. */
    $songId = trim((string)($payload['songId'] ?? $payload['song_id'] ?? ''));
    if ($songId !== '') {
        $chk = $db->prepare('SELECT 1 FROM tblSongs WHERE SongId = ? LIMIT 1');
        $chk->bind_param('s', $songId);
        $chk->execute();
        $exists = $chk->get_result()->fetch_row() !== null;
        $chk->close();
        if (!$exists) {
            throw new \RuntimeException("song '$songId' not found");
        }
        return ['songId' => $songId, 'matched' => true, 'created' => false];
    }

    /* 2. Match by ISRC (exact) — the strongest signal once songs carry one. */
    $isrc = trim((string)($payload['isrc'] ?? ''));
    if ($isrc !== '') {
        $st = $db->prepare('SELECT SongId FROM tblSongs WHERE Isrc = ? LIMIT 1');
        $st->bind_param('s', $isrc);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if ($row !== null) {
            return ['songId' => (string)$row['SongId'], 'matched' => true, 'created' => false];
        }
    }

    /* 3. Match by NORMALIZED TITLE (owner's first-instance signal). */
    $title = trim((string)($payload['title'] ?? ''));
    if ($title === '') {
        throw new \RuntimeException('cannot resolve a song without a songId, ISRC or title');
    }
    $norm = ihymns_normalize_title($title);
    if ($norm !== '') {
        $candidates = [];
        /* Exact-title fast path. */
        $st = $db->prepare('SELECT SongId, Title FROM tblSongs WHERE Title = ? LIMIT 50');
        $st->bind_param('s', $title);
        $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) { $candidates[] = $r; }
        $st->close();
        /* Broader scan, bounded by a LIKE on the first normalized word so the
           cost stays small even as the catalogue grows. */
        if (empty($candidates)) {
            $firstWord = preg_split('/\s+/', $norm)[0] ?? '';
            if ($firstWord !== '' && mb_strlen($firstWord) >= 2) {
                $like = '%' . $firstWord . '%';
                $st = $db->prepare('SELECT SongId, Title FROM tblSongs WHERE Title LIKE ? LIMIT 300');
                $st->bind_param('s', $like);
                $st->execute();
                $res = $st->get_result();
                while ($r = $res->fetch_assoc()) { $candidates[] = $r; }
                $st->close();
            }
        }
        $matches = [];
        foreach ($candidates as $c) {
            if (ihymns_normalize_title((string)$c['Title']) === $norm) {
                $matches[(string)$c['SongId']] = true;
            }
        }
        $matches = array_keys($matches);

        if (count($matches) === 1) {
            return ['songId' => $matches[0], 'matched' => true, 'created' => false];
        }
        if (count($matches) > 1) {
            /* Artist tiebreak — pick the one whose artist intersects the
               payload's; if still ambiguous, create rather than guess wrong
               (the duplicate-songs page surfaces them for a human). */
            $artist = trim((string)($payload['artist'] ?? ''));
            if ($artist !== '') {
                $artistNorm   = ihymns_normalize_title($artist);
                $placeholders = implode(',', array_fill(0, count($matches), '?'));
                $types        = str_repeat('s', count($matches));
                $st = $db->prepare("SELECT SongId, Name FROM tblSongArtists WHERE SongId IN ($placeholders)");
                $st->bind_param($types, ...$matches);
                $st->execute();
                $res = $st->get_result();
                $artistHit = [];
                while ($r = $res->fetch_assoc()) {
                    $n = ihymns_normalize_title((string)$r['Name']);
                    if ($n !== '' && ($n === $artistNorm || str_contains($artistNorm, $n) || str_contains($n, $artistNorm))) {
                        $artistHit[(string)$r['SongId']] = true;
                    }
                }
                $st->close();
                if (count($artistHit) === 1) {
                    return ['songId' => array_key_first($artistHit), 'matched' => true, 'created' => false];
                }
            }
            /* Ambiguous → fall through to create. */
        }
    }

    /* 4. Create a provisional song. */
    $newId = lyricsIngest_createSong($db, $payload, $lyricsText);
    return ['songId' => $newId, 'matched' => false, 'created' => true];
}

/**
 * Create a minimal provisional song from the payload, in the canonical 'Misc'
 * songbook (Verified=0). Inserts a single verse component from the TTML lines
 * (so the song renders + is editable) and seeds the FULLTEXT search text.
 * Returns the new SongId.
 */
function lyricsIngest_createSong(\mysqli $db, array $payload, string $lyricsText = ''): string
{
    $title = trim((string)($payload['title'] ?? ''));
    if ($title === '') {
        throw new \RuntimeException('cannot create a song without a title');
    }
    $abbr     = 'Misc';
    $language = trim((string)($payload['language'] ?? 'en')) ?: 'en';
    $isrc     = trim((string)($payload['isrc'] ?? '')) ?: null;
    $upc      = trim((string)($payload['upc'] ?? '')) ?: null;

    $db->begin_transaction();
    try {
        /* Generate a unique SongId of the form MISC-NNNN (Misc carries NULL
           Numbers, so derive the suffix from the max existing id). Retry on the
           rare race. */
        $songId = '';
        for ($try = 0; $try < 5; $try++) {
            $st = $db->prepare("SELECT SongId FROM tblSongs WHERE SongId LIKE ? ORDER BY LENGTH(SongId) DESC, SongId DESC LIMIT 1");
            $like = $abbr . '-%';
            $st->bind_param('s', $like);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            $next = 1;
            if ($row !== null && preg_match('/-(\d+)$/', (string)$row['SongId'], $m)) {
                $next = (int)$m[1] + 1 + $try;
            }
            $candidate = sprintf('%s-%04d', $abbr, $next);
            $st = $db->prepare('SELECT 1 FROM tblSongs WHERE SongId = ? LIMIT 1');
            $st->bind_param('s', $candidate);
            $st->execute();
            $taken = $st->get_result()->fetch_row() !== null;
            $st->close();
            if (!$taken) { $songId = $candidate; break; }
        }
        if ($songId === '') {
            throw new \RuntimeException('could not allocate a SongId');
        }

        $ins = $db->prepare(
            'INSERT INTO tblSongs (SongId, Number, Title, SongbookAbbr, Language, Isrc, Upc, Verified, LyricsText)
             VALUES (?, NULL, ?, ?, ?, ?, ?, 0, ?)'
        );
        $ins->bind_param('sssssss', $songId, $title, $abbr, $language, $isrc, $upc, $lyricsText);
        $ins->execute();
        $ins->close();

        /* One verse component from the TTML lines, so the provisional song
           renders + is editable in the curator UI. */
        $lines = array_values(array_filter(explode("\n", $lyricsText), static fn($l) => trim($l) !== ''));
        if (!empty($lines)) {
            $linesJson = json_encode($lines, JSON_UNESCAPED_UNICODE);
            $comp = $db->prepare(
                'INSERT INTO tblSongComponents (SongId, Type, Number, LinesJson, SortOrder)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $type = 'verse'; $num = 1; $sort = 0;
            $comp->bind_param('ssisi', $songId, $type, $num, $linesJson, $sort);
            $comp->execute();
            $comp->close();
        }

        $db->commit();
        return $songId;
    } catch (\Throwable $e) {
        try { $db->rollback(); } catch (\Throwable $_) {}
        throw new \RuntimeException('lyrics ingest create-song failed: ' . $e->getMessage(), 0, $e);
    }
}

/**
 * Store the payload's external identifiers/URLs on the song, idempotently
 * (add-if-absent — never the destructive replace-all the editor's
 * saveExternalLinksForRow does). Fills blank Isrc/Upc columns, adds artists to
 * tblSongArtists, and adds Apple-Music / MusicBrainz / Spotify / YouTube /
 * Genius URLs to tblSongExternalLinks under their existing type slugs. Returns
 * the number of rows added.
 */
function lyricsIngest_storeExternalIds(\mysqli $db, string $songId, array $payload): int
{
    $added = 0;

    /* ISRC / UPC → fill blank columns only (don't clobber a curator's value). */
    $isrc = trim((string)($payload['isrc'] ?? ''));
    $upc  = trim((string)($payload['upc'] ?? ''));
    if ($isrc !== '' || $upc !== '') {
        $i = $isrc !== '' ? $isrc : null;
        $u = $upc !== '' ? $upc : null;
        $st = $db->prepare('UPDATE tblSongs SET Isrc = COALESCE(NULLIF(Isrc, ""), ?), Upc = COALESCE(NULLIF(Upc, ""), ?) WHERE SongId = ?');
        $st->bind_param('sss', $i, $u, $songId);
        $st->execute();
        $st->close();
    }

    /* Artists → tblSongArtists add-if-absent. */
    $artist = trim((string)($payload['artist'] ?? ''));
    if ($artist !== '') {
        foreach (preg_split('/\s*[\/&,;]\s*/u', $artist) as $a) {
            $a = trim((string)$a);
            if ($a === '') { continue; }
            $st = $db->prepare(
                'INSERT INTO tblSongArtists (SongId, Name, SortOrder)
                 SELECT ?, ?, 0 FROM DUAL
                 WHERE NOT EXISTS (SELECT 1 FROM tblSongArtists WHERE SongId = ? AND Name = ?)'
            );
            $st->bind_param('ssss', $songId, $a, $songId, $a);
            $st->execute();
            $added += $st->affected_rows;
            $st->close();
        }
    }

    /* External-link URLs → tblSongExternalLinks add-if-absent, under the
       already-seeded type slugs. http(s) only. */
    $links   = [];
    $appleUrl = trim((string)($payload['appleMusicUrl'] ?? $payload['apple_music_url'] ?? ''));
    if ($appleUrl !== '' && preg_match('#^https?://#i', $appleUrl)) {
        $links['apple-music'] = $appleUrl;
    }
    $cp = $payload['crossPlatform'] ?? $payload['cross_platform'] ?? [];
    if (is_array($cp)) {
        $cpMap = [
            'spotify' => 'spotify', 'youtube' => 'youtube',
            'youtubemusic' => 'youtube-music', 'youtube_music' => 'youtube-music',
            'youtube-music' => 'youtube-music', 'genius' => 'genius',
            'musicbrainzrecording' => 'musicbrainz-recording', 'musicbrainz_recording' => 'musicbrainz-recording',
            'musicbrainz-recording' => 'musicbrainz-recording',
            'musicbrainzwork' => 'musicbrainz-work', 'musicbrainz-work' => 'musicbrainz-work',
            'musicbrainzartist' => 'musicbrainz-artist', 'musicbrainz-artist' => 'musicbrainz-artist',
        ];
        foreach ($cp as $k => $v) {
            $v = trim((string)$v);
            if ($v === '' || !preg_match('#^https?://#i', $v)) { continue; }
            $kl   = strtolower((string)$k);
            $slug = $cpMap[$kl] ?? (preg_match('/^[a-z0-9:_-]+$/', $kl) ? $kl : null);
            if ($slug !== null) { $links[$slug] = $v; }
        }
    }
    foreach ($links as $slug => $url) {
        $st = $db->prepare('SELECT Id FROM tblExternalLinkTypes WHERE Slug = ? AND COALESCE(IsActive,1) = 1 LIMIT 1');
        $st->bind_param('s', $slug);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        if ($r === null) { continue; }
        $typeId = (int)$r['Id'];
        $st = $db->prepare(
            'INSERT INTO tblSongExternalLinks (SongId, LinkTypeId, Url, Verified)
             SELECT ?, ?, ?, 0 FROM DUAL
             WHERE NOT EXISTS (SELECT 1 FROM tblSongExternalLinks WHERE SongId = ? AND LinkTypeId = ? AND Url = ?)'
        );
        $st->bind_param('sissis', $songId, $typeId, $url, $songId, $typeId, $url);
        $st->execute();
        $added += $st->affected_rows;
        $st->close();
    }

    return $added;
}
