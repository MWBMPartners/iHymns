<?php

declare(strict_types=1);

/**
 * iHymns — Voice-part marker detector (#2073 commit 10, #2075, feeds #1260)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5: this file answers ONE question — "does this single line of lyric
 * text look like it is telling us WHO sings next (the men, the women,
 * everyone, a soloist), rather than being an ordinary sung line?" It does
 * not touch the database, does not change anything, and does not decide
 * anything on its own — it only classifies a line and hands back an
 * opinion, with a confidence level attached, for something else to act on.
 *
 * WHY A SEPARATE, PURE FILE (rule #22 of .claude/CLAUDE.md — one shared
 * core, reused, never re-forked): TWO very different pieces of code need
 * the exact same answer to "is this line a voice cue?":
 *   1. the four bulk importers in `song_importers.php` (this commit) — so
 *      they stop turning an unrecognised marker word into a fake `refrain`
 *      component and silently throwing the word away (#2075);
 *   2. the future #1260 catalogue clean-up sweep, which needs to walk
 *      songs ALREADY in the database (not being imported right now) and
 *      propose fixes for markers that got baked into the lyric text years
 *      ago — CP-0220 ("We Three Kings"), whose five `chorus` components are
 *      literally the stage directions `ALL` / `FIRST` / `SECOND` / `THIRD`,
 *      is the worked example in #2075's own issue text.
 * Both need the identical rule for "is this a voice cue", so it lives
 * exactly once, here, and both sides call it rather than keeping their own
 * copy that could quietly drift apart (rule #35 — "a comment saying keep
 * these in sync is the failure, not the fix").
 *
 * WHY THIS FILE NEVER DECIDES ANYTHING ON ITS OWN — every finding this file
 * returns is a plain-text HEURISTIC (a guess from the shape of the words),
 * never a structured, machine-authored fact (an OpenLyrics `<lines
 * part="...">` attribute, a TTML `<ttm:agent>` — those are handled by the
 * `includes/vocal_parts.php` ingest helpers instead, because a format that
 * EXPLICITLY states "these are the women's lines" is not guessing). #2073's
 * own rule for this whole feature is that "text heuristics may only ever
 * produce SUGGESTIONS, never auto-apply" — nothing in this file writes a
 * row, assigns a voice to a line, or invents song structure; it only
 * classifies one line of text and returns an opinion plus a confidence
 * level for a HUMAN (via a future review queue) or an importer's own
 * "don't discard this word" logic to act on.
 *
 * THE THREE WAYS A VOICE CUE ACTUALLY SHOWS UP IN REAL SONG TEXT (found by
 * hand-reading a sample of the iHymns text-import corpus for #2075):
 *   (a) STANDALONE  — the marker is the WHOLE line on its own, e.g. a line
 *       that reads only "WOMEN" (optionally with a trailing colon, or
 *       wrapped in brackets/parens: "(WOMEN)"). This is the shape #2075's
 *       own bug report shows: "WOMEN" / "He who dwells…" / "MEN" / … .
 *   (b) PREFIX      — the marker is glued to the FRONT of the very same
 *       line as the lyric it introduces, separated by either a colon or a
 *       run of TWO OR MORE whitespace-like characters, most often a run of
 *       real Unicode NON-BREAKING SPACES (U+00A0) — this is what a lyric
 *       sheet exported from a word processor or a PDF actually looks like
 *       once tabs get "print-preview aligned" into repeated NBSPs rather
 *       than a genuine tab character. A hand count across the corpus found
 *       this the single MOST COMMON of the three shapes (89 lines used it,
 *       against 31 for the standalone shape) — so getting the NBSP case
 *       right matters more than the tidy standalone case.
 *       ⚠️ WHY THIS FILE NEVER RELIES ON A BARE `\s` FOR THE GAP: whether
 *       PCRE's `\s` character class expands to the full Unicode
 *       White_Space set (which WOULD include U+00A0) or stays ASCII-only
 *       under the `/u` (UTF-8) modifier is a detail of the PCRE BUILD and
 *       version, not something this codebase controls or should have to
 *       track — the classic, widely-repeated PCRE guidance is that a bare
 *       `\s` is ASCII-only unless Unicode-property matching (`PCRE2_UCP`)
 *       is separately enabled, but the exact runtime this ships to
 *       (shared hosting, an unknown PHP/PCRE point release) is not one
 *       this file can verify in advance, and a build where the guidance
 *       DOESN'T hold is no safer to depend on than one where it does —
 *       either way, silently trusting `\s` to include NBSP is trusting an
 *       implementation detail this file has no business assuming either
 *       direction of. So every regex here that needs to match a "gap"
 *       between a marker and its lyric spells out `\x{00A0}` EXPLICITLY
 *       alongside the ordinary space/tab characters, in its own small
 *       named class (`_VOCAL_DETECT_GAP_CHARS`) — deterministic and
 *       portable across every PCRE build, never a bare `\s` whose
 *       Unicode-awareness this file would otherwise be silently betting
 *       on. See the PCRE manual link below for the classic ASCII-only
 *       behaviour this defends against even though it did not reproduce
 *       on the PCRE2 10.42 / Unicode 14 build this commit was verified
 *       against (where `\s` under `/u` DID already match every Unicode
 *       space separator, NBSP included — a genuinely useful thing to
 *       know, and recorded here rather than left for the next person to
 *       rediscover by surprise, but not a reason to remove a defensive,
 *       zero-cost, explicit character class that is correct either way).
 *   (c) PAREN       — the WHOLE line is a single parenthesised aside, e.g.
 *       "(Women echo)". This shape is genuinely ambiguous: a hand count
 *       found it means "a background/echo voice" only about ONE TIME IN
 *       FIVE (14 lines) — the other four times out of five (59 lines) it
 *       is a plain STAGE DIRECTION with nothing to do with who sings
 *       ("(repeat verse 2)", "(instrumental)", "(x2)", …). Getting this
 *       wrong in the confident direction would be actively harmful (it
 *       would flag ordinary stage directions as echo voices), so a paren
 *       line that doesn't look like a direction is proposed only at LOW
 *       confidence, never higher, and a line that DOES look like a
 *       direction is not even returned as a finding — it is not a queued
 *       "low-confidence maybe", it is a hard "no, don't ask about this
 *       one" (`vocalPartDetectClassifyLine()` returns `null`).
 *
 * THE "SOLO" AMBIGUITY (#2073 — already resolved and documented on
 * `includes/vocal_parts.php`'s `IHYMNS_VOCAL_AMBIGUOUS_SECTION_MARKERS`):
 * the bare word "SOLO" is genuinely ambiguous with `tblSongPartTypes`'
 * unrelated structural "Solo" SECTION (an instrumental break — no singing
 * at all). This file never resolves that ambiguity itself — deciding what
 * is or isn't a structural section belongs to a completely different
 * vocabulary (rule #22) — it only ever forces the CONFIDENCE down to
 * `'low'` on a "SOLO" finding, via the already-landed, already-tested
 * `vocalPartsMarkerIsAmbiguousWithSection()` helper, so a human reviewer
 * (never code) makes the actual call. This is checked LAST, after a form
 * has already produced a candidate finding, so it applies uniformly no
 * matter which of the three shapes above the word turned up in.
 *
 * VOCABULARY REUSE (rule #22 — never re-fork): every "is this word a voice
 * cue, and if so which kind" question is answered by the ALREADY-LANDED
 * `vocalPartsKindFromWord()` in `includes/vocal_parts.php` (#2073 commit 1)
 * — this file adds NO second copy of the marker word list. Requiring that
 * file pulls in `db_mysql.php` and `lyric_lines_read.php` transitively, but
 * both of those are documented lazy-connect singletons (requiring them
 * opens no connection and runs no query on its own — see db_mysql.php's own
 * doc-block) — so this file is still, in the sense that matters for #1260's
 * batch consumer, a PURE classifier: calling any function below never
 * touches the database, no matter how many files got pulled in to reuse
 * the one shared vocabulary.
 *
 * CONFIDENCE — a VARCHAR string (`'high' | 'medium' | 'low'`), matching
 * `tblVocalPartSuggestions.Confidence`'s already-migrated column shape
 * (`appWeb/.sql/migrate-vocal-parts-rounds.php`) exactly, so a future
 * caller can store a finding's confidence straight into that column with
 * no translation step — rule #20's "VARCHAR, app-validated, never an
 * ENUM" applied consistently end to end.
 *
 * @see https://www.pcre.org/current/doc/html/pcre2pattern.html#SEC5   PCRE's \s does NOT include U+00A0
 * @see https://www.php.net/manual/en/regexp.reference.unicode.php     \x{...} Unicode code-point escapes under /u
 * @see https://en.wikipedia.org/wiki/Non-breaking_space               U+00A0 and why word processors insert runs of it
 * @see appWeb/public_html/includes/vocal_parts.php                    the ONE vocabulary this file classifies against
 * @see .claude/vocal-parts-2073-plan.md                               the plan of record ("Design pass 7" §3.4, pass 6 §5-6)
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* The ONE voice-part vocabulary (`IHYMNS_VOCAL_PART_KINDS`,
   `vocalPartsKindFromWord()`, `vocalPartsMarkerIsAmbiguousWithSection()`) —
   see this file's own doc-block above for why requiring it does not make
   this file any less "pure" for a caller's purposes (rule #22). */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'vocal_parts.php';

/* =====================================================================
 * CONSTANTS
 * ===================================================================== */

/**
 * The three plain-text shapes a voice cue can take in real lyric text —
 * see this file's own doc-block for a worked example of each. A future
 * detector version that learns a fourth shape adds it here (rule #20 —
 * one central vocabulary list, never a value re-typed at each call site).
 */
const IHYMNS_VOCAL_DETECT_FORMS = ['standalone', 'prefix', 'paren'];

/**
 * Bumped only when the DETECTION RULES themselves change in a way that
 * would alter an already-stored finding's meaning (a regex gets stricter
 * or looser, a new form is added, the confidence policy changes) — never
 * for an unrelated code change in this file. `tblVocalPartSuggestions.
 * DetectorVersion` (already migrated, #2073 commit 2) stores this on every
 * row, so a later detector version can cheaply find + re-check every
 * suggestion an older version produced (`Status = 'stale'`, rule #20's
 * "a re-detection after the detector improves needs no ALTER" design
 * goal) rather than every row silently going unreviewed forever.
 */
const IHYMNS_VOCAL_DETECT_VERSION = 1;

/**
 * Whitespace-LIKE characters this file treats as "a gap between a marker
 * and the lyric it introduces": an ordinary space, a tab, and — the one
 * that actually matters most in real corpus text, see the file doc-block's
 * form (b) — a genuine Unicode NON-BREAKING SPACE (U+00A0). Deliberately
 * the SAME three characters `vocalPartsKindFromWord()` already folds
 * together (rule #35 — matching, not re-deriving, the already-landed
 * vocabulary helper's own whitespace policy) — no U+2007/U+202F or other
 * exotic space variants, because THAT helper doesn't fold those either,
 * and disagreeing between the two would mean a line one function accepts
 * as a marker, the other silently refuses to recognise the SAME word in.
 */
const _VOCAL_DETECT_GAP_CHARS = " \t\u{00A0}";

/* =====================================================================
 * INTERNAL HELPERS — pure, not part of this file's public contract.
 * ===================================================================== */

/**
 * Title-case one ALL-CAPS word/phrase for a friendlier proposed label —
 * "WOMEN" -> "Women", "BOYS" -> "Boys". Deliberately simple (first letter
 * of each space-separated token, rest lower-cased) rather than a full
 * locale-aware title-caser: every input here is already a short, plain
 * ASCII-range marker word drawn from `IHYMNS_VOCAL_PART_KINDS`'s own
 * `markers` lists, never free-form prose.
 */
function _vocalDetectTitleCase(string $word): string
{
    $lower = mb_strtolower(trim($word), 'UTF-8');
    if ($lower === '') {
        return '';
    }
    $parts = preg_split('/\s+/u', $lower) ?: [$lower];
    $titled = array_map(
        static function (string $p): string {
            if ($p === '') {
                return $p;
            }
            $first = mb_substr($p, 0, 1, 'UTF-8');
            $rest  = mb_substr($p, 1, null, 'UTF-8');
            return mb_strtoupper($first, 'UTF-8') . $rest;
        },
        $parts
    );
    return implode(' ', $titled);
}

/**
 * Force a finding's confidence down to 'low' when its marker word is on
 * the shared ambiguous list (today just "SOLO" — see this file's own
 * doc-block, and `IHYMNS_VOCAL_AMBIGUOUS_SECTION_MARKERS`'s doc-block in
 * `vocal_parts.php`, for the full reasoning). Applied uniformly to every
 * form's candidate finding, in ONE place, so a future fourth form can
 * never forget the check.
 */
function _vocalDetectApplyAmbiguityFloor(array $finding): array
{
    if (vocalPartsMarkerIsAmbiguousWithSection($finding['marker'])) {
        $finding['confidence'] = 'low';
    }
    return $finding;
}

/**
 * Resolve one already-isolated marker WORD (no surrounding punctuation)
 * into a kind/label pair, additionally accepting a two-part "X AND Y" /
 * "X & Y" / "X/Y" combination (e.g. "MEN AND WOMEN") by resolving each
 * side separately and folding to the `all` kind with a combined label —
 * per "Design pass 7" §3.4's own worked example. Returns null when the
 * word (or either half of a two-part combination) is not in the
 * vocabulary at all — this function never invents a kind.
 *
 * @return array{kind:string,label:?string}|null
 */
function _vocalDetectResolveWord(string $word): ?array
{
    $direct = vocalPartsKindFromWord($word);
    if ($direct !== null) {
        return $direct;
    }

    /* Two-part combination — split on "AND" (as its own word), "&" or
       "/". Both halves must independently resolve; anything else (three+
       parts, or a half that isn't in the vocabulary) is not a match. */
    $pieces = preg_split('/\s+AND\s+|\s*&\s*|\s*\/\s*/u', trim($word)) ?: [];
    $pieces = array_values(array_filter(array_map('trim', $pieces), static fn (string $p): bool => $p !== ''));
    if (count($pieces) !== 2) {
        return null;
    }
    $a = vocalPartsKindFromWord($pieces[0]);
    $b = vocalPartsKindFromWord($pieces[1]);
    if ($a === null || $b === null) {
        return null;
    }
    $labelA = $a['label'] ?? _vocalDetectTitleCase($pieces[0]);
    $labelB = $b['label'] ?? _vocalDetectTitleCase($pieces[1]);
    return ['kind' => 'all', 'label' => $labelA . ' and ' . mb_strtolower($labelB, 'UTF-8')];
}

/* =====================================================================
 * PUBLIC CONTRACT
 * ===================================================================== */

/**
 * ELI5: look at ONE line of lyric text and say whether it looks like it is
 * announcing who sings next — and if so, how sure we are.
 *
 * Tries the three forms in a fixed order — STANDALONE, then PREFIX, then
 * PAREN (see this file's doc-block for what each one looks like) — and
 * returns the FIRST one that matches; a line can only ever be one shape.
 * Returns null when none of the three forms match, OR when a paren line
 * matches but its content reads as a stage DIRECTION rather than a voice
 * cue (e.g. "(repeat verse 2)") — that second case is a deliberate "no",
 * never a low-confidence "maybe", because confidently mis-flagging a
 * direction as an echo voice would be actively worse than staying silent.
 *
 * @return array{
 *   form: 'standalone'|'prefix'|'paren',
 *   marker: string,
 *   kind: string,
 *   label: ?string,
 *   bg: bool,
 *   rest: string,
 *   confidence: 'high'|'medium'|'low'
 * }|null
 */
function vocalPartDetectClassifyLine(string $line): ?array
{
    /* An empty/whitespace-only line is never a marker of any kind — most
       callers already skip blank lines before reaching here, but a
       defensive early-out costs nothing and keeps every regex below from
       having to special-case it. */
    if (trim($line) === '') {
        return null;
    }

    /* --- (a) STANDALONE: the whole line is (optionally bracketed/
       colon-terminated) an upper-case word or short phrase and NOTHING
       else. `\x{00A0}` is included in the captured word's own character
       class so a line like "MEN\u{00A0}" (a trailing NBSP some exporters
       leave behind) still matches — the surrounding trim below folds it
       away before the vocabulary lookup either way. */
    if (preg_match(
        '/^[' . _VOCAL_DETECT_GAP_CHARS . ']*[\(\[]?[' . _VOCAL_DETECT_GAP_CHARS . ']*'
        . '(?<w>[\p{Lu}0-9][\p{Lu}0-9' . _VOCAL_DETECT_GAP_CHARS . '.&\/\'\-]{0,60}?)'
        . '[' . _VOCAL_DETECT_GAP_CHARS . ']*[\)\]]?[' . _VOCAL_DETECT_GAP_CHARS . ']*:?['
        . _VOCAL_DETECT_GAP_CHARS . ']*$/u',
        $line,
        $m
    )) {
        $word = trim($m['w']);
        if ($word !== '') {
            $resolved = _vocalDetectResolveWord($word);
            if ($resolved !== null) {
                /* Confidence: a real, standalone marker line is a strong
                   signal — "Design pass 7" §3.4 rates ALL-CAPS (which this
                   whole form already requires, since `\p{Lu}` demands an
                   upper-case first letter and the class allows only more
                   upper-case letters) as high regardless of the optional
                   bracket/colon dressing. */
                $finding = [
                    'form'       => 'standalone',
                    'marker'     => $word,
                    'kind'       => $resolved['kind'],
                    'label'      => $resolved['label'],
                    'bg'         => $resolved['kind'] === 'backing',
                    'rest'       => '',
                    'confidence' => 'high',
                ];
                return _vocalDetectApplyAmbiguityFloor($finding);
            }
        }
    }

    /* --- (b) PREFIX: a marker word glued to the front of the SAME line
       as the lyric it introduces, via a colon and/or a run of gap
       characters. `\x{00A0}` appears explicitly in the gap class (see the
       file doc-block's warning — a bare `\s` would silently miss this,
       the MOST COMMON real-world shape). A single plain space with no
       colon is deliberately NOT enough on its own (it would turn an
       ordinary sentence like "ALL creation sings" into a false positive)
       — the separator must contain either a colon, a genuine NBSP (any
       run length), or two-or-more plain space/tab characters.
       ⚠️ `(?<sep>...)` is ONE greedy group, deliberately not split into
       "optional gap, optional colon, mandatory gap" — an earlier draft of
       this regex split it that way and an optional `[gap]*` ahead of the
       colon silently STOLE characters from what should have counted as
       the separator (PCRE tries the greedy optional piece first, then
       only gives characters back to the later mandatory group when
       backtracking is forced to) — "ALL" + two plain spaces + "And I
       will say" measured as a ONE-character gap instead of two, so the
       "two-or-more spaces" rule below silently rejected a real match. A
       single ungreedy-free capture of the WHOLE run has no such split to
       go wrong. Unlike the standalone form above, a prefix marker is
       always a SINGLE token (no "AND"/space inside it) — matching
       "Design pass 7" §3.4's own prefix regex, which draws the same
       line; a spelled-out two-part combination ("MEN AND WOMEN: …") is
       vanishingly rare glued to a lyric on one physical line and is not
       worth the ambiguity of letting $w itself swallow whitespace here. */
    if (preg_match(
        '/^[' . _VOCAL_DETECT_GAP_CHARS . ']*(?<w>[\p{Lu}0-9][\p{Lu}0-9&\/\'\-]{0,40}?)'
        . '(?<sep>[:' . _VOCAL_DETECT_GAP_CHARS . ']+)(?<rest>\S.*)$/u',
        $line,
        $m
    )) {
        $sep      = $m['sep'];
        $hasColon = str_contains($sep, ':');
        $gapOnly  = str_replace(':', '', $sep);
        $hasNbsp  = str_contains($gapOnly, "\u{00A0}");
        $gapLen   = mb_strlen($gapOnly, 'UTF-8');
        if ($hasColon || $hasNbsp || $gapLen >= 2) {
            $word = trim($m['w']);
            $resolved = ($word !== '') ? _vocalDetectResolveWord($word) : null;
            if ($resolved !== null) {
                $finding = [
                    'form'       => 'prefix',
                    'marker'     => $word,
                    'kind'       => $resolved['kind'],
                    'label'      => $resolved['label'],
                    'bg'         => $resolved['kind'] === 'backing',
                    'rest'       => ltrim($m['rest']),
                    /* A colon or an NBSP run is a deliberate, intentional
                       separator a human typed on purpose; a bare run of
                       plain spaces is more likely an accidental column
                       alignment, so it earns one notch less confidence. */
                    'confidence' => ($hasColon || $hasNbsp) ? 'high' : 'medium',
                ];
                return _vocalDetectApplyAmbiguityFloor($finding);
            }
        }
    }

    /* --- (c) PAREN: the whole line is one parenthesised aside. A
       direction (see the file doc-block — "(repeat verse 2)" etc.) is a
       hard NO, not a low-confidence maybe. Anything else is proposed as a
       generic background/echo cue at LOW confidence only — see the file
       doc-block's "1 time in 5" corpus finding for why this form never
       earns anything higher, even when the parenthesised text happens to
       independently resolve to a specific kind. */
    if (preg_match('/^[' . _VOCAL_DETECT_GAP_CHARS . ']*\((?<inner>[^()]{2,80})\)[' . _VOCAL_DETECT_GAP_CHARS . ']*$/u', $line, $m)) {
        $inner = trim($m['inner']);
        if ($inner !== '' && !preg_match(
            '/^(repeat|sing|to\s|go\s+to|d\.?\s*[sc]\.?|x\s?\d|\d+\s?x|instrumental|interlude|'
            . 'chorus|verse|refrain|bridge|last\s+time|first\s+time|twice|three\s+times|'
            . 'tag|coda|ending|end|fine|spoken|optional|softly|slowly|louder|quietly|\d)/iu',
            $inner
        )) {
            $resolved = _vocalDetectResolveWord($inner);
            $finding  = [
                'form'       => 'paren',
                'marker'     => $inner,
                'kind'       => $resolved['kind'] ?? 'backing',
                'label'      => $resolved['label'] ?? null,
                'bg'         => true,
                'rest'       => '',
                'confidence' => 'low',
            ];
            return _vocalDetectApplyAmbiguityFloor($finding);
        }
        /* Matched the direction list (or was empty after trimming) — a
           deliberate "never queue this", not a weak finding. */
        return null;
    }

    return null;
}

/**
 * Classify every line of ONE component (a verse, a chorus, …), returning
 * one finding per line that matched, each stamped with its `lineIndex`
 * into the `$lines` array that was passed in. Lines that don't classify
 * simply produce no entry — this is a sparse list, not a parallel array.
 *
 * @param list<string> $lines  the component's plain lyric lines (the
 *                             public/editable shape's own `lines` array —
 *                             nothing else on a component is read)
 * @return list<array{lineIndex:int,form:string,marker:string,kind:string,
 *                     label:?string,bg:bool,rest:string,confidence:string}>
 */
function vocalPartDetectComponent(array $lines): array
{
    $findings = [];
    foreach ($lines as $i => $line) {
        if (!is_string($line)) {
            continue;
        }
        $found = vocalPartDetectClassifyLine($line);
        if ($found !== null) {
            $found['lineIndex'] = (int)$i;
            $findings[] = $found;
        }
    }
    return $findings;
}

/**
 * Classify an entire song's components in one pass, tagging every finding
 * with its `componentIndex` on top of `vocalPartDetectComponent()`'s own
 * `lineIndex` — this is the shape both #1260's future catalogue sweep and
 * an importer's own in-body scan want: "which component, which line,
 * what did you find".
 *
 * @param list<array{lines?: list<string>}> $components  the song's
 *        components in the public/editable shape — only each one's
 *        `lines` key is read; every other key (type, number, chords, …)
 *        is ignored, so this happily accepts the exact array the editor
 *        and every importer already produce.
 * @return list<array{componentIndex:int,lineIndex:int,form:string,marker:string,
 *                     kind:string,label:?string,bg:bool,rest:string,confidence:string}>
 */
function vocalPartDetectSong(array $components): array
{
    $out = [];
    foreach ($components as $ci => $component) {
        if (!is_array($component) || !isset($component['lines']) || !is_array($component['lines'])) {
            continue;
        }
        foreach (vocalPartDetectComponent($component['lines']) as $finding) {
            $finding['componentIndex'] = (int)$ci;
            $out[] = $finding;
        }
    }
    return $out;
}
