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
 * #2073 commit 12 (D2) — VOICE PARTS FROM TTML
 * ---------------------------------------------
 * ELI5: Apple Music TTML is the richest "who sings this?" signal iHymns
 * handles — a `<head>` lists every voice by name, each line says which
 * voice(s) sing it, and `ttm:role="x-bg"` marks an echo/background phrase —
 * and until this commit the parser threw all of that away. It now reads the
 * `<head>` agent definitions, fixes two bugs that mis-shaped the per-word
 * data (a background-vocal GROUP of several timed words was being read as
 * ONE fake word; a word never inherited its line's or its group's voice),
 * and the writer turns the result into real rows in the #1137/#2073
 * vocal-parts tables — DIRECTLY, not queued for review, because a TTML
 * `<ttm:agent>` is a STRUCTURED fact the source FILE states, unlike the
 * text-marker detector's guesses over plain lyric lines (see
 * `includes/vocal_part_detect.php`'s own doc-block for that distinction).
 *
 * DETAILED / WHY: see `vocalPartsFindOrCreate()` (`includes/vocal_parts.php`,
 * #1137) for the registry write, `vocalPartsAssignLinesForVersion()` /
 * `vocalPartsAssignWords()` for the line/word assignment rows, and
 * `vocalPartsKindFromTtmlAgent()` for the TTML2 §12.2.1 `ttm:agent type`
 * vocabulary map — all three already existed on this branch (#2073 commit 5)
 * with no caller; this commit is that caller. Offsets and slicing anywhere
 * in this feature are CODE POINTS, never bytes/UTF-16 (rule #21) — though in
 * practice this commit never slices a string by offset at all, only splits
 * whitespace-separated attribute lists and walks whole DOM text nodes.
 *
 * @see .claude/vocal-parts-2073-plan.md  "Design pass 6" §7 (the TTML spec
 *      this commit implements) and "Design pass 7" §1/§10 (its correction —
 *      the container-span rule is whitespace-only, dropping Pass 6's extra
 *      "and carries no ttm:role/ttm:agent" clause)
 * @see https://www.w3.org/TR/ttml2/#metadata-vocabulary-agent  TTML2 §12.2.1
 *      ttm:agent — the `type` vocabulary (person|group|character|other) this
 *      file's `vocalPartsKindFromTtmlAgent()` call reads
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
 * ELI5: "does this element have a real GAP of actual whitespace between any
 * two of its children?" — that one question is the whole difference between
 * Apple's two nested-`<span>` shapes: several SEPARATE timed WORDS (spaces
 * between them, e.g. a background-vocal echo phrase) versus one word spelled
 * out as several touching SYLLABLES (no space between them at all, e.g.
 * "Oh" + "yeah" written as two syllable spans that read as "Ohyeah" with no
 * gap). #2073 D2, "Design pass 7" §10 — the container rule is WHITESPACE
 * ONLY (an earlier draft, "Design pass 6" §7.1, also required the container
 * to carry no `ttm:role`/`ttm:agent` of its own; Pass 7 drops that extra
 * clause on a contradiction and wins by this plan's own stated precedence —
 * see this file's header doc-block).
 *
 * DETAILED / WHY: a direct child TEXT NODE of $el that is non-empty but
 * TRIMS to empty (i.e. it is made of nothing but spaces/tabs/newlines) is
 * exactly what XML puts between two sibling elements that were written with
 * a literal space in the source — `<span>a</span> <span>b</span>` has one
 * such node between the two spans; `<span>a</span><span>b</span>` (no space
 * in the source at all) has none. This is a plain boolean scan, no DOM
 * position/adjacency bookkeeping needed, because TTML never legitimately
 * puts a "just whitespace" text node ANYWHERE inside a real syllable
 * container — a syllable container's only text lives inside its child
 * spans. @see https://www.w3.org/TR/xml/#sec-white-space (XML whitespace,
 * for why a text node can be present at all here)
 */
function _ttmlSpanChildrenHaveWhitespaceGap(\DOMElement $el): bool
{
    foreach ($el->childNodes as $child) {
        if ($child->nodeType === XML_TEXT_NODE && $child->textContent !== '' && trim($child->textContent) === '') {
            return true;
        }
    }
    return false;
}

/**
 * ELI5: given the attribute bag `_ttmlMeta()` already collected for a line
 * or a word, pull out "which voice(s) sing this?" (`ttm:agent`, a
 * whitespace-separated IDREFS list — a duet line can name two) and "is this
 * an echo?" (`ttm:role` containing the token `x-bg`). ONE small function so
 * a `<p>` line and a word's already-inherited merged meta are read the
 * EXACT same way (rule #35: agreement by shared code, never by two call
 * sites that merely happen to match today).
 *
 * DETAILED / WHY: `_ttmlMeta()` keys its result by the attribute's qualified
 * DOM name, which is the PREFIXED form (`ttm:agent`) when the source XML
 * used the `ttm:` prefix and the bare form (`agent`) when it didn't (both
 * are seen in real files) — this checks both, same as `_ttmlMeta()`'s own
 * probe list does when collecting them in the first place. `ttm:role` can in
 * principle carry more than one space-separated token (TTML2 §6.2.9 defines
 * it as a set); `x-bg` is Apple's own extension token for a background
 * vocal, not part of the base TTML2 vocabulary, so it is matched by
 * substring-of-the-token-list rather than an exact-equals on the whole
 * attribute value.
 *
 * @param ?array<string,string> $meta  `_ttmlMeta()`'s return (qualified attr
 *        name => value), or null when the element/merge carried none
 * @return array{agentIds:list<string>,isBackground:bool}
 */
function _ttmlAgentAndBg(?array $meta): array
{
    if ($meta === null) {
        return ['agentIds' => [], 'isBackground' => false];
    }
    $agentRaw = (string)($meta['ttm:agent'] ?? $meta['agent'] ?? '');
    $agentIds = [];
    if (trim($agentRaw) !== '') {
        foreach (preg_split('/\s+/u', trim($agentRaw)) ?: [] as $piece) {
            if ($piece !== '') { $agentIds[] = $piece; }
        }
    }
    $roleRaw = (string)($meta['ttm:role'] ?? $meta['role'] ?? '');
    $roleTokens = trim($roleRaw) !== '' ? (preg_split('/\s+/u', trim($roleRaw)) ?: []) : [];
    return ['agentIds' => $agentIds, 'isBackground' => in_array('x-bg', $roleTokens, true)];
}

/**
 * Parse a TTML document into a neutral timed-lyrics structure:
 *
 *   [
 *     'language'          => 'en'|null,        // root xml:lang
 *     'hasTiming'         => bool,             // line-level
 *     'hasWordTiming'     => bool,
 *     'hasSyllableTiming' => bool,
 *     'agents'            => [ 'v1' => ['type','name','meta'], … ],  // #2073 D2 — <head> ttm:agent DEFINITIONS, keyed by xml:id; [] when none
 *     'hasVoiceParts'     => bool,                                   // #2073 D2 — any agent/echo signal anywhere in the file
 *     'lines' => [
 *       [ 'text','startMs','endMs','languageCode','isInstrumental','meta',
 *         'agentIds'       => ['v1', …],   // #2073 D2 — this line's OWN ttm:agent IDREFS (never inherited — a <p> has no parent line)
 *         'isBackground'   => bool,        // #2073 D2 — this line's OWN ttm:role="x-bg"
 *         'words' => [ [ 'text','startMs','endMs','meta',
 *                        'agentIds'     => ['v1', …],  // #2073 D2 — ALREADY resolved through every enclosing container/line (see the parser's own comment on the word-walk)
 *                        'isBackground' => bool,        // #2073 D2 — likewise already-inherited
 *                        'syllables' => [ ['text','startMs','endMs','meta'], … ] ], … ] ],
 *       …
 *     ],
 *   ]
 *
 * `agentIds`/`isBackground` are derived from `meta` by `_ttmlAgentAndBg()` —
 * see that function's own doc-block for the exact `ttm:agent`/`ttm:role`
 * reading rules (#2073 D2).
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

    /* #2073 D2 fix (1 of 3): read the <head><metadata><ttm:agent xml:id=…
       type=…><ttm:name>…</ttm:name></ttm:agent> voice DEFINITIONS. Before
       this fix, the parser never looked inside <head> at all — only the
       per-line/per-word ATTRIBUTE STRING that references one of these ids
       ("v1") was ever kept (in MetaJson), never what "v1" actually IS. Keyed
       by xml:id so the writer can find-or-create the SAME tblVocalParts row
       on every re-ingest (the table's existing uq_Lyrics_Agent unique key,
       #1137, is exactly this idempotency key — no new schema needed).
       @see https://www.w3.org/TR/ttml2/#metadata-vocabulary-agent */
    $agents = [];
    foreach ($doc->getElementsByTagNameNS('*', 'agent') as $agentEl) {
        /** @var \DOMElement $agentEl */
        if (_ttmlLocalName($agentEl) !== 'agent') { continue; }
        /* TTML/ttm:agent only ever means anything under <head> — require an
           ancestor by that local name so an unrelated same-named element
           elsewhere in the document (not valid TTML, but nothing stops a
           hand-edited file from containing one) is never mistaken for one. */
        $underHead = false;
        for ($anc = $agentEl->parentNode; $anc !== null; $anc = $anc->parentNode) {
            if ($anc instanceof \DOMElement && _ttmlLocalName($anc) === 'head') { $underHead = true; break; }
        }
        if (!$underHead) { continue; }

        $agentId = $agentEl->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'id');
        if ($agentId === '') { $agentId = $agentEl->getAttribute('xml:id'); }
        if ($agentId === '') { continue; } /* an id-less agent can never be REFERENCED by a line/word — nothing to key it by */

        $agentType = $agentEl->hasAttribute('type') ? strtolower(trim($agentEl->getAttribute('type'))) : '';
        $agentType = $agentType !== '' ? $agentType : null;

        /* A real file can carry more than one <ttm:name> (e.g. one per
           language); keep ALL of them losslessly in `meta`, but pick ONE
           display name — preferring type="full" (TTML2's own "the whole
           name as one string" type) when present, else the first name at all. */
        $names = [];
        $fullName = null;
        $anyName  = null;
        foreach ($agentEl->childNodes as $nameEl) {
            if ($nameEl->nodeType !== XML_ELEMENT_NODE || _ttmlLocalName($nameEl) !== 'name') { continue; }
            $nameText = trim((string)$nameEl->textContent);
            if ($nameText === '') { continue; }
            $nameType = $nameEl->hasAttribute('type') ? strtolower(trim($nameEl->getAttribute('type'))) : null;
            $names[]  = ['type' => $nameType, 'text' => $nameText];
            $anyName ??= $nameText;
            if ($nameType === 'full') { $fullName ??= $nameText; }
        }

        $agents[$agentId] = [
            'type' => $agentType,
            'name' => $fullName ?? $anyName,
            'meta' => ['type' => $agentType, 'names' => $names],
        ];
    }

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

        $pMeta = _ttmlMeta($p);

        /* Build words + syllables by walking the <p>'s children in order.
           Consecutive leaf <span>s (no whitespace between) are syllables of
           one word; a whitespace text node ends a word; a <span> that itself
           contains child <span>s is EITHER:
             - a true syllable container (its children touch, no whitespace
               between them — one word, several timed syllables), OR
             - #2073 D2 fix (2 of 3): a WORD-GROUP container — Apple wraps a
               background-vocal echo phrase in e.g.
               `<span ttm:role="x-bg"><span begin=…>He</span> <span
               begin=…>is</span> <span begin=…>my</span></span>`, i.e.
               several whitespace-SEPARATED timed words, not syllables of
               one. Before this fix EVERY nested-span container took the
               first branch unconditionally, so a 3-word echo group read
               back as one bogus "word" whose text was the three words
               jammed together with no space ("Heismy") — see
               `_ttmlSpanChildrenHaveWhitespaceGap()`'s own doc-block, and
               this file's header doc-block for why this is a WHITESPACE-only
               test (not also gated on the container's own attributes, which
               an earlier plan draft required — dropped by "Design pass 7").
           A word-group container is walked RECURSIVELY by this SAME loop
           (`$walk` calls itself), passing its own merged meta down as the
           new "inherited" baseline — which is also #2073 D2 fix (3 of 3):
           every word's `meta` is `array_merge(inherited, ownMeta)`, so a
           word with NO attributes of its own still picks up whichever
           voice/background flag its enclosing container (or, with no
           container, its line) declared, instead of the pre-fix behaviour
           of every word inheriting NOTHING from any ancestor at all. */
        $words = [];
        $cur   = null;
        $flush = function () use (&$cur, &$words) {
            if ($cur !== null && trim($cur['text']) !== '') { $words[] = $cur; }
            $cur = null;
        };
        $newWord = static function (): array {
            return ['text' => '', 'startMs' => null, 'endMs' => null, 'meta' => null, 'syllables' => []];
        };
        $walk = function (\DOMNode $container, ?array $inherited) use (&$walk, &$cur, &$words, $flush, $newWord): void {
            foreach ($container->childNodes as $node) {
                if ($node->nodeType === XML_ELEMENT_NODE && _ttmlLocalName($node) === 'span') {
                    /** @var \DOMElement $node */
                    $nested  = _ttmlChildSpans($node);
                    $ownMeta = _ttmlMeta($node);
                    $merged  = ($inherited !== null || $ownMeta !== null) ? array_merge($inherited ?? [], $ownMeta ?? []) : null;

                    if (!empty($nested) && _ttmlSpanChildrenHaveWhitespaceGap($node)) {
                        /* Word GROUP: flush whatever word was mid-build, recurse
                           with THIS container's merged meta as the new baseline
                           every word inside it inherits from, then flush again
                           so nothing bleeds across the container's boundary. */
                        $flush();
                        $walk($node, $merged);
                        $flush();
                    } elseif (!empty($nested)) {
                        /* Unchanged shape: a true syllable container. */
                        $flush();
                        $w = $newWord();
                        $w['startMs'] = _ttmlAttrMs($node, 'begin');
                        $w['endMs']   = _ttmlAttrMs($node, 'end');
                        $w['meta']    = $merged;
                        foreach ($nested as $syl) {
                            $st  = $syl->textContent;
                            $sMs = _ttmlAttrMs($syl, 'begin');
                            $eMs = _ttmlAttrMs($syl, 'end');
                            $w['syllables'][] = ['text' => $st, 'startMs' => $sMs, 'endMs' => $eMs, 'meta' => _ttmlMeta($syl)];
                            $w['text'] .= $st;
                            if ($w['startMs'] === null) { $w['startMs'] = $sMs; }
                            if ($eMs !== null) { $w['endMs'] = $eMs; }
                        }
                        $words[] = $w;
                    } else {
                        /* Leaf span: one syllable of the word currently being
                           built (or the first syllable of a brand-new one). */
                        if ($cur === null) {
                            $cur = $newWord();
                            $cur['meta'] = $inherited; /* baseline BEFORE this leaf's own attrs, so a plain word still inherits its line/group */
                        }
                        if ($ownMeta !== null) {
                            $cur['meta'] = array_merge($cur['meta'] ?? [], $ownMeta);
                        }
                        $st  = $node->textContent;
                        $sMs = _ttmlAttrMs($node, 'begin');
                        $eMs = _ttmlAttrMs($node, 'end');
                        $cur['syllables'][] = ['text' => $st, 'startMs' => $sMs, 'endMs' => $eMs, 'meta' => $ownMeta];
                        $cur['text'] .= $st;
                        if ($cur['startMs'] === null) { $cur['startMs'] = $sMs; }
                        if ($eMs !== null) { $cur['endMs'] = $eMs; }
                    }
                } elseif ($node->nodeType === XML_TEXT_NODE) {
                    if (trim($node->textContent) === '') {
                        $flush(); /* whitespace = word boundary */
                    } else {
                        if ($cur === null) { $cur = $newWord(); $cur['meta'] = $inherited; }
                        $cur['text'] .= $node->textContent;
                    }
                }
            }
        };
        $walk($p, $pMeta);
        $flush();

        /* Normalise each word: a single leaf syllable identical to the word
           carries no extra info, so we don't emit it as a syllable row;
           genuine multi-syllable words set hasSyllableTiming. Also resolve
           each word's OWN (already-inherited, per the fix above) voice/echo
           signal — see `_ttmlAgentAndBg()`'s doc-block. */
        foreach ($words as &$w) {
            if ($w['startMs'] !== null) { $hasWordTiming = true; }
            if (count($w['syllables']) > 1) {
                $hasSyllableTiming = true;
            } else {
                $w['syllables'] = []; /* drop the redundant 1:1 syllable */
            }
            $wSig = _ttmlAgentAndBg($w['meta']);
            $w['agentIds']     = $wSig['agentIds'];
            $w['isBackground'] = $wSig['isBackground'];
        }
        unset($w);

        $lineText = trim((string) preg_replace('/\s+/u', ' ', $p->textContent));
        $isInstrumental = ($lineText === '');
        $lineSig = _ttmlAgentAndBg($pMeta);

        $lines[] = [
            'text'           => $lineText,
            'startMs'        => $lineStart,
            'endMs'          => $lineEnd,
            'languageCode'   => $lineLang,
            'isInstrumental' => $isInstrumental,
            'meta'           => $pMeta,
            'agentIds'       => $lineSig['agentIds'],
            'isBackground'   => $lineSig['isBackground'],
            'words'          => $words,
        ];
    }

    if (empty($lines)) {
        throw new \RuntimeException('no <p> lines found in TTML <body>');
    }

    /* "Has this file got ANY voice-part signal worth writing?" — a cheap
       short-circuit the writer (lyricsIngest_writeToDb()) uses so the
       overwhelming majority of plain (single-voice, no echo) TTML files
       never even requires vocal_parts.php. A declared-but-unused <head>
       agent still counts (a curator can see it was declared, even if no
       line ended up referencing it). */
    $hasVoiceParts = $agents !== [];
    if (!$hasVoiceParts) {
        foreach ($lines as $checkLine) {
            if ($checkLine['agentIds'] !== [] || $checkLine['isBackground']) { $hasVoiceParts = true; break; }
            foreach ($checkLine['words'] as $checkWord) {
                if ($checkWord['agentIds'] !== [] || $checkWord['isBackground']) { $hasVoiceParts = true; break 2; }
            }
        }
    }

    return [
        'language'          => $language,
        'hasTiming'         => $hasTiming,
        'hasWordTiming'     => $hasWordTiming,
        'hasSyllableTiming' => $hasSyllableTiming,
        'agents'            => $agents,
        'hasVoiceParts'     => $hasVoiceParts,
        'lines'             => $lines,
    ];
}

/**
 * Write a parsed timed-lyrics structure into the normalized schema for one
 * song, UPSERTing on (SongId, Source) — re-ingesting the same source replaces
 * its rows (CASCADE clears old lines/words/syllables) rather than duplicating.
 *
 * #2073 D2: that same CASCADE also clears every OLD line/word vocal-part
 * ASSIGNMENT row for this version for free (`tblLyricLineVocalParts.LineId`
 * / `tblLyricWordVocalParts.WordId` both `ON DELETE CASCADE` back to the
 * lines/words this function just deleted) — so a re-ingest re-creates them
 * fresh from the re-parsed file without this function ever deleting them
 * itself. The PART REGISTRY rows (`tblVocalParts`) are NOT cascaded away
 * (they have no FK to a line/word) and are correctly reused across a
 * re-ingest via `vocalPartsFindOrCreate()`'s `TtmlAgentId` match.
 *
 * @param \mysqli $db
 * @param string  $songId   tblSongs.SongId (must exist).
 * @param array   $parsed   Output of lyricsIngest_parseTtml().
 * @param array   $opts     { source?, sourceUrl?, formatVersion?, isPrimary?,
 *                            isExplicit?, status?, submittedBy?, language? }
 * @return array { lyricsId, lines, words, syllables, vocalParts,
 *                 lineAssignments, wordAssignments } — the last three are
 *                 always present (0 when the file had no voice signal, or
 *                 when the #1137 tables are not migrated on this install;
 *                 #2073 D2)
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

    /* Song must exist (FK would reject anyway, but a clear error is nicer).
       @deleted-visible: write-path FK pre-check (#1694) — ingesting lyrics
       into a hidden row is harmless and restore-preserving.
       @disabled-visible: same reasoning, one predicate over (#1765) —
       ingesting lyrics into a song whose songbook has been disabled is
       equally harmless (writes are disable-invariant). */
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
           first (CASCADE → words → syllables) so a re-ingest is clean.
           @lyrics-version-exempt: (#2076) $source is whatever this ingest
           call was given (e.g. 'ttml', a syllable-timing import), NOT
           necessarily 'ihymns' — this is finding-or-creating THAT SOURCE's
           own row to write into, not deciding which version is the song's
           current/primary one. Nothing here disagrees with
           lyricLinesPrimaryLyricsId(); it is simply answering a different
           question. */
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
        /* #2073 D2 — remember which real database Id each parsed line/word
           ended up at, so the vocal-parts writer below (which runs AFTER
           every line/word already exists) can turn "line index 3's own
           ttm:agent" into "tblLyricLines.Id 48291 is sung by part 7" — by
           IDENTITY, never by re-deriving position later (rule #21/#25's own
           "never carry per-line data by array position" lesson, applied here
           to a same-pass hand-off rather than a later rewrite). */
        $lineIdByIndex = [];
        $wordIdByIndex = [];
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
            $lineIdByIndex[$li] = $lineId;
            $nLines++;

            foreach (($line['words'] ?? []) as $wi => $word) {
                $wMeta = isset($word['meta']) ? json_encode($word['meta'], JSON_UNESCAPED_UNICODE) : null;
                $wText = (string)($word['text'] ?? '');
                $wStart = $word['startMs']; $wEnd = $word['endMs'];
                $wordStmt->bind_param('iisiis', $lineId, $wi, $wText, $wStart, $wEnd, $wMeta);
                $wordStmt->execute();
                $wordId = (int)$db->insert_id;
                $wordIdByIndex[$li][$wi] = $wordId;
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

        /* #2073 D2 — apply the TTML's voice/echo signal to the #1137
           vocal-parts tables, INSIDE this same transaction: a TTML
           `<ttm:agent>` is a STRUCTURED, source-declared fact (see this
           file's header doc-block), so it is written DIRECTLY here, the same
           way #2071's OpenLyrics `part=` import applies directly — never
           routed through the text-marker review queue
           (`tblVocalPartSuggestions`), which exists for GUESSES over plain
           lyric text, not for a format that already says the answer.
           Deliberately NOT wrapped in its own try/catch: this runs inside
           the surrounding try/catch's transaction, so any failure here rolls
           back the WHOLE ingest via the SAME path a line/word insert failure
           already takes — the file must never end up half-voiced (some
           lines/words written, their voice assignments silently dropped)
           any more than it may end up with half its lines. Skipped entirely
           (no-op, no error) when either the parse found no voice signal at
           all or the #1137 tables are not migrated on this install — see
           `vocalPartsTablesReady()`'s own doc-block for why that gate
           degrades gracefully rather than throwing. */
        $vocalCounts = ['vocalParts' => 0, 'lineAssignments' => 0, 'wordAssignments' => 0];
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'vocal_parts.php';
        if (!empty($parsed['hasVoiceParts']) && vocalPartsTablesReady($db)) {
            $vocalCounts = _lyricsIngestApplyVocalParts($db, $lyricsId, $source, $parsed, $lineIdByIndex, $wordIdByIndex);
        }

        $db->commit();
        return ['lyricsId' => $lyricsId, 'lines' => $nLines, 'words' => $nWords, 'syllables' => $nSyl] + $vocalCounts;
    } catch (\Throwable $e) {
        try { $db->rollback(); } catch (\Throwable $_) {}
        throw new \RuntimeException('lyrics ingest write failed: ' . $e->getMessage(), 0, $e);
    }
}

/**
 * ELI5: "do these two lists of part ids name the exact same PARTS, ignoring
 * order?" — used by `_lyricsIngestApplyVocalParts()` to tell whether a
 * word's voice is genuinely different from its line's (worth its own row)
 * or just repeats it (leave it to inherit, per the DDL's own override rule —
 * see `tblLyricWordVocalParts`'s table COMMENT in schema.sql). PURE — a
 * plain sort-and-compare, split out on its own so it can be truth-tabled
 * without a live mysqli (this repo's PHP test image has none — see
 * `tests/php/test-vocal-parts-core.php`'s own doc-block for that same
 * constraint).
 *
 * @param list<int> $a
 * @param list<int> $b
 */
function _lyricsIngestIntSetsEqual(array $a, array $b): bool
{
    sort($a);
    sort($b);
    return $a === $b;
}

/**
 * ELI5: turn what the (now head-agent-aware, container-fixed,
 * inheritance-fixed) TTML parser found out about WHO SINGS each line/word
 * into real rows in the #1137/#2073 vocal-parts tables — registry rows for
 * every `<head>` agent, a line-level assignment per line that named one, a
 * synthesised "backing" part for a bare `ttm:role="x-bg"` with no named
 * agent, and a word-level OVERRIDE row only where a word's own (already-
 * inherited) voice/echo signal genuinely differs from its line's.
 *
 * DETAILED / WHY: called ONCE, straight after `lyricsIngest_writeToDb()`
 * has just inserted every line/word for this ingest — so `$lineIdByIndex` /
 * `$wordIdByIndex` are freshly-minted real database Ids handed to us by
 * IDENTITY (the caller's own array index), never re-derived by guessing a
 * line back from its position/type/text later (the "per-line data survives
 * by Id or not at all" rule this whole programme keeps re-learning the hard
 * way — #2072/#2087 and the earlier voice-mark bug this same brief cites).
 *
 * Ordering ("head order, then first-reference order for undeclared"): a
 * PHP associative array preserves insertion order, so building `$ordered`
 * by first copying `$parsed['agents']` (already in `<head>` document order)
 * and only THEN discovering ids referenced-but-never-declared (scanning
 * lines then words, in that same order) produces exactly that ordering with
 * no separate sort step.
 *
 * `$personOrdinal` is read 0-BASED, matching `vocalPartsKindFromTtmlAgent()`'s
 * OWN doc-block ("the FIRST person-type agent, $personOrdinal === 0, is
 * 'lead'") — read the ordinal BEFORE bumping it for a person-type agent,
 * not after. (Flagging this because an earlier plan snippet for this exact
 * commit — "Design pass 6" §7.2 — incremented first and passed the
 * incremented value, which would make the FIRST person agent read as
 * 'soloist' instead of 'lead'. Per this brief's own instruction to report a
 * plan/reality mismatch loudly rather than silently working around it: this
 * function follows the ALREADY-LANDED `vocalPartsKindFromTtmlAgent()`
 * contract, not the plan snippet.)
 *
 * @param array<int,int>            $lineIdByIndex   $parsed['lines'] index -> tblLyricLines.Id
 * @param array<int,array<int,int>> $wordIdByIndex   [$lineIndex][$wordIndex] -> tblLyricWords.Id
 * @return array{vocalParts:int,lineAssignments:int,wordAssignments:int}
 * @throws \RuntimeException  bubbles up unmodified from the vocal_parts.php
 *         core (see the call site's own comment: this is deliberately NOT
 *         swallowed — a failure here must roll back the whole ingest)
 */
function _lyricsIngestApplyVocalParts(\mysqli $db, int $lyricsId, string $source, array $parsed, array $lineIdByIndex, array $wordIdByIndex): array
{
    /* 1. Registry — every declared <head> agent, then every id a line/word
       references that <head> never defined (a hand-edited or third-party
       file's sloppiness; a genuine Apple Music export never does this). */
    $ordered = [];
    foreach (($parsed['agents'] ?? []) as $agentId => $agent) {
        $ordered[(string)$agentId] = $agent;
    }
    foreach ($parsed['lines'] as $line) {
        foreach (($line['agentIds'] ?? []) as $agentId) {
            $ordered[$agentId] ??= ['type' => null, 'name' => null, 'meta' => ['undeclared' => true]];
        }
        foreach (($line['words'] ?? []) as $word) {
            foreach (($word['agentIds'] ?? []) as $agentId) {
                $ordered[$agentId] ??= ['type' => null, 'name' => null, 'meta' => ['undeclared' => true]];
            }
        }
    }

    $personOrdinal = 0;
    $groupOrdinal  = 0;
    $partIdByAgent = [];
    foreach ($ordered as $agentId => $agent) {
        $kind = vocalPartsKindFromTtmlAgent($agent, $personOrdinal);
        if (strtolower((string)($agent['type'] ?? '')) === 'person') {
            $personOrdinal++; /* bumped AFTER reading — see this function's own doc-block */
        }
        $label = ($kind === 'group') ? ('Group ' . (++$groupOrdinal)) : null;
        $meta  = (isset($agent['meta']) && is_array($agent['meta'])) ? $agent['meta'] : null;
        $partIdByAgent[$agentId] = vocalPartsFindOrCreate(
            $db,
            $lyricsId,
            $kind,
            label: $label,
            source: $source,
            ttmlAgentId: $agentId,
            singerName: $agent['name'] ?? null,
            meta: $meta,
        );
    }

    /* A role-only background span/line with NO named agent still needs
       somewhere to record "this is an echo" — one synthesised 'backing'
       part per lyrics version, keyed by the reserved TtmlAgentId handle
       'x-bg' so a re-ingest reuses the SAME row (tblVocalParts' EXISTING
       uq_Lyrics_Agent unique key is exactly this idempotency key — no new
       column, per this commit's own brief). Built lazily: a file with no
       bare background signal never creates it at all. */
    $bgPartId = null;
    $bgPart = static function () use (&$bgPartId, $db, $lyricsId, $source): int {
        return $bgPartId ??= vocalPartsFindOrCreate($db, $lyricsId, 'backing', ttmlAgentId: 'x-bg', source: $source, meta: ['synthetic' => true]);
    };

    /* Stale-agent cleanup: an agent this version used to reference but the
       just-(re)parsed file no longer does gets its MACHINE-owned part
       removed. 'x-bg' is always kept in the keep-list (even on a file with
       no background signal this run) so the synthetic part, once created,
       is never pruned just because this particular re-ingest happened not
       to use it — vocalPartsPruneAgents() only deletes rows that already
       exist, so listing an unused key here is harmless either way. A
       curator's OWN 'ihymns'-sourced part is never touched (Source is an
       exact-match filter — see that function's own doc-block). */
    vocalPartsPruneAgents($db, $lyricsId, $source, array_merge(array_keys($partIdByAgent), ['x-bg']));

    $resolvePartIds = static function (array $agentIds, bool $isBackground) use ($partIdByAgent, $bgPart): array {
        $ids = [];
        foreach ($agentIds as $agentId) {
            if (isset($partIdByAgent[$agentId])) { $ids[] = $partIdByAgent[$agentId]; }
        }
        if ($ids === [] && $isBackground) { $ids = [$bgPart()]; }
        return $ids;
    };

    $lineAssignments = 0;
    $wordAssignments = 0;
    foreach ($parsed['lines'] as $li => $line) {
        $lineId = $lineIdByIndex[$li] ?? null;
        if ($lineId === null) {
            continue; /* defensive only — every parsed line was inserted immediately above */
        }
        $lineIsBackground = !empty($line['isBackground']);
        $linePartIds      = $resolvePartIds($line['agentIds'] ?? [], $lineIsBackground);
        if ($linePartIds !== []) {
            vocalPartsAssignLinesForVersion($db, $lyricsId, $lineId, $linePartIds, $lineIsBackground);
            $lineAssignments++;
        }

        foreach (($line['words'] ?? []) as $wi => $word) {
            $wordId = $wordIdByIndex[$li][$wi] ?? null;
            if ($wordId === null) {
                continue; /* defensive only — every parsed word was inserted immediately above */
            }
            $wordIsBackground = !empty($word['isBackground']);
            $wordPartIds      = $resolvePartIds($word['agentIds'] ?? [], $wordIsBackground);

            /* The DDL's own override rule (tblLyricWordVocalParts' schema.sql
               COMMENT): "a word with rows overrides its line parts; a word
               with none inherits the line" — so a word row is written ONLY
               when the word's (already fully inherited — see the parser's
               own comment) effective voice genuinely differs from its
               line's. Both sides were resolved through the SAME
               $resolvePartIds() above, so an ordinary word with no override
               anywhere in its ancestry always compares exactly equal here
               and is correctly left unwritten. */
            if ($wordIsBackground === $lineIsBackground && _lyricsIngestIntSetsEqual($wordPartIds, $linePartIds)) {
                continue;
            }
            if ($wordPartIds === []) {
                continue; /* nothing to assert about this word beyond what the line already says */
            }
            foreach ($wordPartIds as $partId) {
                vocalPartsAssignWords($db, $lyricsId, [$wordId], $partId, $wordIsBackground);
            }
            $wordAssignments++;
        }
    }

    return [
        'vocalParts'      => count($partIdByAgent) + ($bgPartId !== null ? 1 : 0),
        'lineAssignments' => $lineAssignments,
        'wordAssignments' => $wordAssignments,
    ];
}

/**
 * ELI5: every TTML file ingested since #1064 has ALWAYS stashed the raw
 * `ttm:agent`/`ttm:role` attribute string into `tblLyricLines.MetaJson` (and
 * `tblLyricWords.MetaJson`) — nothing ever read it back until this commit,
 * so those historical ingests are sitting on voice information this feature
 * could use RIGHT NOW without anyone re-uploading a file. This function
 * finds them and reports what it found; it does NOT write anything.
 *
 * DETAILED / WHY THIS IS READ-ONLY (this is the "provide a backfill path,
 * but do NOT assume what is in it" instruction this commit's own brief
 * gives, honoured as narrowly as possible): the `<head><ttm:agent xml:id
 * type>` DEFINITIONS were never parsed before this commit (the very first
 * defect this commit fixes) — so NONE of those historical MetaJson blobs
 * can say what kind of voice "v1" or "v2" actually WAS, only that some
 * line/word referenced an id by that name. Reading them back can tell you
 * WHICH lines/words shared a voice and WHICH were background — genuinely
 * useful — but it CANNOT recover the real kind, name, or gender: turning
 * this into applied `tblVocalParts` rows would have to guess the SAME
 * default (`vocalPartsKindFromTtmlAgent()`'s missing-type fallback,
 * `'lead'`) for every single id in a file, which is almost certainly wrong
 * for every voice in a real multi-voice recording but at most one of them.
 * That is precisely the "do not assume what is in them" risk this commit's
 * own brief calls out — so this function stops at reporting, and does not
 * offer a mode that writes.
 *
 * **The right fix for any ONE song is almost always to re-ingest its
 * ORIGINAL TTML file** — now that the head-agent bug is fixed, a fresh
 * ingest captures the real agent types/names correctly. This scan exists
 * for the case where that original file is no longer available and someone
 * needs to know, before trusting anything, which lyrics versions have
 * signal worth a human's attention and how much.
 *
 * This function has NO caller anywhere in this commit (dormant, by design —
 * see this function's own "@see" below for the gap between what the design
 * plan describes here and what actually got scheduled).
 *
 * @param \mysqli $db
 * @param ?int    $lyricsId  scope to one lyrics version, or null to scan
 *                           every non-'ihymns' version in the database
 * @return list<array{lyricsId:int,songId:string,source:string,linesWithSignal:int,distinctAgentIds:list<string>,wordsWithOwnSignal:int}>
 *         one row per lyrics version that has ANY signal at all;
 *         `wordsWithOwnSignal` > 0 flags a version whose word-grain data was
 *         written while the container-span bug (this commit's fix #2 of 3)
 *         was still live — those word rows are not just "unverified", they
 *         are known to have been mis-shaped, and re-ingesting the original
 *         file is the only way to correct them (see this function's own
 *         "DETAILED / WHY" above).
 *
 * @see .claude/vocal-parts-2073-plan.md "Design pass 6" §7.4 specs a full,
 *      WRITE-APPLYING migration (`migrate-backfill-ttml-vocal-parts.php`)
 *      built on a scan much like this one. That migration is OUT OF SCOPE
 *      for this commit (file list = lyrics_ingest.php + its test only) —
 *      AND, separately, "Design pass 7" §12's own final 17-commit synthesis
 *      does not schedule that migration in ANY of its 17 commits either.
 *      Flagging this loudly, as this brief asks, rather than silently
 *      picking a side: the plan's prose and the plan's own commit list
 *      disagree about whether that migration exists at all.
 */
function lyricsIngestScanMetaJsonForVoiceSignal(\mysqli $db, ?int $lyricsId = null): array
{
    $sql = "SELECT ll.LyricsId, ly.SongId, ly.Source, ll.MetaJson
              FROM tblLyricLines ll
              JOIN tblLyrics ly ON ly.Id = ll.LyricsId
             WHERE ly.Source <> 'ihymns' AND ll.MetaJson IS NOT NULL"
        . ($lyricsId !== null ? ' AND ll.LyricsId = ?' : '');
    $stmt = $db->prepare($sql);
    if ($lyricsId !== null) {
        $stmt->bind_param('i', $lyricsId);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $byVersion = [];
    while ($row = $res->fetch_assoc()) {
        $lid  = (int)$row['LyricsId'];
        $meta = json_decode((string)$row['MetaJson'], true);
        $sig  = _ttmlAgentAndBg(is_array($meta) ? $meta : null);
        if ($sig['agentIds'] === [] && !$sig['isBackground']) {
            continue;
        }
        $byVersion[$lid] ??= [
            'lyricsId' => $lid, 'songId' => (string)$row['SongId'], 'source' => (string)$row['Source'],
            'linesWithSignal' => 0, 'distinctAgentIds' => [], 'wordsWithOwnSignal' => 0,
        ];
        $byVersion[$lid]['linesWithSignal']++;
        foreach ($sig['agentIds'] as $agentId) {
            $byVersion[$lid]['distinctAgentIds'][$agentId] = true;
        }
    }
    $stmt->close();

    if ($byVersion === []) {
        return [];
    }

    $ids   = array_keys($byVersion);
    $place = implode(',', array_fill(0, count($ids), '?'));
    $wStmt = $db->prepare(
        "SELECT l.LyricsId, w.MetaJson
           FROM tblLyricWords w
           JOIN tblLyricLines l ON l.Id = w.LineId
          WHERE l.LyricsId IN ($place) AND w.MetaJson IS NOT NULL"
    );
    $wStmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $wStmt->execute();
    $wRes = $wStmt->get_result();
    while ($row = $wRes->fetch_assoc()) {
        $meta = json_decode((string)$row['MetaJson'], true);
        $sig  = _ttmlAgentAndBg(is_array($meta) ? $meta : null);
        if ($sig['agentIds'] === [] && !$sig['isBackground']) {
            continue;
        }
        $lid = (int)$row['LyricsId'];
        if (isset($byVersion[$lid])) {
            $byVersion[$lid]['wordsWithOwnSignal']++;
        }
    }
    $wStmt->close();

    foreach ($byVersion as &$version) {
        $version['distinctAgentIds'] = array_keys($version['distinctAgentIds']);
        sort($version['distinctAgentIds']);
    }
    unset($version);

    return array_values($byVersion);
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
    /* #1749 full unification — hoisted above both this function's own use
       (step 2 below, the store arm) and the two existing write-site
       require_once calls further down in this file (lyricsIngest_createSong()
       / lyricsIngest_storeExternalIds()), so a single require_once covers the
       whole resolve+write funnel rather than three independently-typed ones. */
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_external_ids.php';
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'identifier_normalize.php';

    /* 1. Explicit songId → verify it exists.

       @deleted-visible: ingest identity RESOLVER (#1694, this and the ISRC /
       title matches below) — matching the hidden row preserves single
       identity: the ingest attaches to it and everything reconciles on
       restore, instead of minting a duplicate that collides then.
       @disabled-visible: same reasoning, one predicate over (#1765) —
       matching a song in a disabled songbook preserves single identity the
       same way; the ingest must not mint a duplicate just because the
       book's visibility was toggled off. */
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

    /* 2. Match by ISRC (exact) — the strongest signal once songs carry one.
     *
     * #1749 full unification — THREE fixes to this step, all landing
     * together (§5.5 of the build spec):
     *   (a) CANONICALISE the payload value first, through the ONE fold
     *       (ihymns_canonical_isrc(), rule #22 — the same one this file
     *       already uses at its two write sites below) — a raw-bound compare
     *       against the CANONICAL tblSongs.Isrc column could miss an
     *       otherwise-identical value that merely differs in separator style
     *       or case;
     *   (b) the gated store arm (songExternalIdUnionArmSql('s.SongId'),
     *       existence-probed via songExternalIdsTableExists()) — a store-only
     *       second-recording ISRC now resolves here too, instead of silently
     *       falling through to step 3's fuzzy TITLE match (a real
     *       wrong-song-attach risk this closes: two differently-titled songs
     *       sharing a first word could otherwise collide on the LIKE scan);
     *   (c) a DETERMINISTIC `ORDER BY s.SongId ASC LIMIT 1` — the bare
     *       `LIMIT 1` this replaces was storage-order roulette the moment two
     *       songs legitimately share an ISRC (possible via a manual store
     *       row), silently picking whichever the engine happened to return
     *       first.
     */
    $isrc = ihymns_canonical_isrc((string)($payload['isrc'] ?? ''));
    if ($isrc !== '') {
        $useIsrcStore = songExternalIdsTableExists($db);
        $isrcMatch = $useIsrcStore
            ? '(Isrc = ? OR ' . songExternalIdUnionArmSql('SongId') . ')'
            : 'Isrc = ?';
        /* @deleted-visible: identity resolver — see the marker at step 1. */
        $st = $db->prepare(
            "SELECT SongId FROM tblSongs WHERE {$isrcMatch} ORDER BY SongId ASC LIMIT 1"
        );
        if ($useIsrcStore) {
            $storeIdType = 'isrc';
            $st->bind_param('sss', $isrc, $storeIdType, $isrc);
        } else {
            $st->bind_param('s', $isrc);
        }
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
        /* @deleted-visible: identity resolver — see the marker at step 1. */
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
                /* @deleted-visible: identity resolver — see the marker at step 1. */
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
    /* #1751 — ELI5: clean up the ISRC the same way the editor already does,
       so whatever we save here reads identically to a curator-typed one.
       DETAILED / WHY: ONE fold (rule #22) — the same ihymns_canonical_isrc()
       the editor funnel uses, so tblSongs.Isrc and the store's IdValue can
       never diverge by formatting. ihymns_canonical_isrc() cleans, never
       rejects (identifier_normalize.php:213-216), so no previously-accepted
       payload starts failing; '' folds to null (nullable column, matches
       prior shape). @see #1751 */
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'identifier_normalize.php';
    $isrc     = ihymns_canonical_isrc((string)($payload['isrc'] ?? '')) ?: null;
    $upc      = trim((string)($payload['upc'] ?? '')) ?: null;
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'ilyrics_id.php';   /* #1860 go-live — ilidStampNewRow() below */

    $db->begin_transaction();
    try {
        /* Generate a unique SongId of the form MISC-NNNN (Misc carries NULL
           Numbers, so derive the suffix from the max existing id). Retry on the
           rare race.

           #1679 A9 — "unique" means unclaimed by tblSongs OR by a live
           tblSongRedirects row, which is what the shared songRelocateIdTaken()
           answers. This loop used to ask tblSongs only. Since a songbook move
           re-keys a song and leaves a permanent redirect behind, an id that no
           song holds can still be one an old bookmark is being forwarded away
           from — and getSongById() matches exactly before it consults the
           redirect layer, so re-issuing it serves that bookmark a DIFFERENT
           song with 200 OK. Misc is the very book a move is most likely to have
           emptied a slot in. The seed / retry shape is untouched; only the
           definition of "taken" is shared. */
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_relocate.php';
        $songId = '';
        for ($try = 0; $try < 5; $try++) {
            /* @deleted-visible: id MINT SEED (#1694) — a hidden song keeps
               its id reserved; songRelocateIdTaken() below shares the same
               contract.
               @disabled-visible: same reasoning, one predicate over (#1765) —
               a song's id stays reserved regardless of whether its songbook
               has been disabled; this is a number-slot-occupancy question,
               not a visibility one. */
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
            if (!songRelocateIdTaken($db, $candidate)) { $songId = $candidate; break; }
        }
        if ($songId === '') {
            throw new \RuntimeException('could not allocate a SongId');
        }

        /* #1343-B — mint a stable PublicId (IHUID) for the new song when the
           column has been migrated on this env. Gated so an un-migrated install
           still inserts cleanly (the 3 docroots run un-migrated under STRICT).
           Minted inside the open transaction so it commits / rolls back with the
           song row. PublicId sits right after SongId in the column list. */
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_public_id.php';
        $pubId = songPublicId_columnReady($db) ? songPublicId_mintUnique($db) : null;
        if ($pubId !== null) {
            $ins = $db->prepare(
                'INSERT INTO tblSongs (SongId, PublicId, Number, Title, SongbookAbbr, Language, Isrc, Upc, Verified, LyricsText)
                 VALUES (?, ?, NULL, ?, ?, ?, ?, ?, 0, ?)'
            );
            $ins->bind_param('ssssssss', $songId, $pubId, $title, $abbr, $language, $isrc, $upc, $lyricsText);
        } else {
            $ins = $db->prepare(
                'INSERT INTO tblSongs (SongId, Number, Title, SongbookAbbr, Language, Isrc, Upc, Verified, LyricsText)
                 VALUES (?, NULL, ?, ?, ?, ?, ?, 0, ?)'
            );
            $ins->bind_param('sssssss', $songId, $title, $abbr, $language, $isrc, $upc, $lyricsText);
        }
        $ins->execute();
        $ins->close();
        /* #1860 go-live — mint this song's permanent IL-id (ILS…). This
           INSERT carries no Ccli/Iswc column (lyrics-ingest songs arrive
           with no such identifiers), so there is nothing for
           workAutolinkSafe() to link here — the mint is independent of that
           and still applies. */
        ilidStampNewRow($db, 'song', $songId, 'SongId');

        /* #1039 Part A — maintain the diacritic-folded search mirror
           (LyricsTextFolded) + set NormalizedTitle for the newly-ingested song,
           in the SAME transaction. This INSERT path historically wrote NEITHER
           column (the #1039 NormalizedTitle funnel gap); the shared helper
           closes both here. Dormant + fail-open no-op on an un-migrated
           install. */
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'search_fold.php';
        searchFoldSyncSong($db, $songId, $title, $lyricsText);

        /* #1751 — ELI5: tell the external-IDs store about the ISRC we just
           saved, right after we save it, so the two never fall out of sync.
           DETAILED / WHY: dual-write mirror, inside the SAME transaction as
           the tblSongs INSERT above — a throw here propagates to the catch
           below and rolls back the whole create (the mirror is deliberately
           UNSWALLOWED — a half-mirrored pair is worse than the ingest
           failing outright; see song_external_ids.php's own doc-block).
           'ihymns-ingest' is provenance only; ownership stays
           SourceRef='tblSongs.Isrc' (song_external_ids.php's ownership
           model — Source and SourceRef are deliberately different axes).
           @see #1751 */
        if ($isrc !== null) {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_external_ids.php';
            songExternalIdMirrorIsrc($db, $songId, $isrc, 'ihymns-ingest');
        }

        /* One verse component from the TTML lines, so the provisional song
           renders + is editable in the curator UI. */
        $lines = array_values(array_filter(explode("\n", $lyricsText), static fn($l) => trim($l) !== ''));
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'lyric_lines_sync.php';
        if (!empty($lines)) {
            /* #1235 P4/C5 — write inversion: when the mirror exists, the shared write
               path makes the normalised lines authoritative and shadow-writes the
               still-present LinesJson, so the ingest survives the C6 drop. */
            if (lyricLinesSyncReady($db)) {
                lyricLinesWriteComponents($db, $songId, [
                    ['type' => 'verse', 'number' => 1, 'lines' => $lines],
                ]);
            } else {
                /* lines-json-fallback (#1235 P4): un-migrated install (no mirror) —
                   LinesJson provably still exists (C6 drop only runs once syncReady). */
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

    /* ISRC / UPC → fill blank columns only (don't clobber a curator's value).
       #1751 — ELI5: no built-in "start a transaction" here, on purpose — this
       function can be called after its caller has ALREADY committed, and
       opening a new one here would silently swallow whatever transaction a
       FUTURE caller has open around it.
       DETAILED / WHY: api.php:1732 calls this function AFTER
       lyricsIngest_writeToDb()'s own commit has already happened, so there
       is no transaction open when we get here — and a begin_transaction()
       inside a helper silently commits any future caller's outer
       transaction (the implicit-commit class of bug), so we deliberately do
       NOT introduce one. This function is documented add-if-absent
       idempotent, and the re-runnable #1747 backfill card self-heals the
       crash window between the UPDATE below and the mirror call that
       follows it. @see #1751 */
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'identifier_normalize.php';
    $isrc = ihymns_canonical_isrc((string)($payload['isrc'] ?? ''));   /* #1751 — one fold (rule #22) */
    $upc  = trim((string)($payload['upc'] ?? ''));
    if ($isrc !== '' || $upc !== '') {
        $i = $isrc !== '' ? $isrc : null;
        $u = $upc !== '' ? $upc : null;
        $st = $db->prepare('UPDATE tblSongs SET Isrc = COALESCE(NULLIF(Isrc, ""), ?), Upc = COALESCE(NULLIF(Upc, ""), ?) WHERE SongId = ?');
        $st->bind_param('sss', $i, $u, $songId);
        $st->execute();
        $st->close();

        /* #1751 — ELI5: after saving, go read back what the column ACTUALLY
           holds now, and mirror THAT value — not the value we were handed.
           DETAILED / WHY (load-bearing — do not "simplify" this away): the
           UPDATE above is a COALESCE fill-if-blank. When the column already
           held a curator's value, the payload value did NOT land — mirroring
           the payload value would write a store row whose IdValue != the
           tblSongs.Isrc column, breaking the mirror invariant (store row
           SourceRef='tblSongs.Isrc' must equal the column byte-for-byte).
           So: mirror the READ-BACK column value, whatever it now is. This
           also self-heals a never-mirrored pre-existing column value, and
           matches the backfill's copy-verbatim semantics for legacy raw
           values. No transaction here by design — see this function's
           opening comment / the re-runnable backfill card is the
           crash-window catch-all. @see #1751 */
        if ($isrc !== '') {
            /* @deleted-visible: write-path state read (#1694) — the UPDATE
               just above wrote into this exact $songId; reading its own
               column back to learn what to mirror is harmless and
               restore-preserving (mirrors save_song_core.php's identical
               "read the row I just targeted" pattern), never a general
               visibility-filtered listing.
               @disabled-visible: same reasoning, one predicate over (#1765). */
            $rb = $db->prepare('SELECT Isrc FROM tblSongs WHERE SongId = ? LIMIT 1');
            $rb->bind_param('s', $songId);
            $rb->execute();
            $rbRow = $rb->get_result()->fetch_row();
            $rb->close();
            $storedIsrc = $rbRow !== null ? trim((string)($rbRow[0] ?? '')) : '';
            if ($storedIsrc !== '') {
                require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_external_ids.php';
                songExternalIdMirrorIsrc($db, $songId, $storedIsrc, 'ihymns-ingest');
            }
        }
    }

    /* Artists → tblSongArtists add-if-absent. */
    $artist = trim((string)($payload['artist'] ?? ''));
    if ($artist !== '') {
        /* #960 — this ingest path wrote tblSongArtists directly and never
           touched the tblMusicians registry, one of the two sibling gaps
           found alongside the v2 editor regression (the other being v2's
           credit_upsert, fixed in api2.php). Same fix here: promote every
           artist name into the registry right after the role-table write.
           No parts (the payload's `artist` field is a raw, possibly
           multi-name string split on separators — not a structured
           first/surname/suffix entry) so this is a Name-only registration/
           backfill, same as registerMusicianByName($db, $name) alone. */
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'musician_helpers.php';
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
            musicianPromote($db, $a, []);
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
