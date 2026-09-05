<?php

declare(strict_types=1);

/**
 * iHymns — Voice-part / echo / round render helpers (#2073 commit 8)
 *
 * ELI5: this file turns "who sings this line" DATA (the sparse `voices` /
 * `voiceSpans` keys a song's components already carry, plus a song's
 * `rounds` list) into the actual HTML a page shows a visitor — chip badges
 * ("Women", "Men", "Echo"), a thin coloured rule down the side of a group of
 * lines that share a singer, italic sub-line echo text, and the plain-
 * English note above a round ("Round · 2 voices · Voice 2 enters after 2
 * lines"). It does NOT touch the database and does NOT decide who sings
 * what — `includes/vocal_parts.php` (parts + line assignment) and
 * `includes/lyric_rounds.php` (rounds) already decided that; this file only
 * draws the answer.
 *
 * WHY A SEPARATE FILE, AND WHY IT MUST STAY PURE (rule #22 of
 * .claude/CLAUDE.md — one shared core, reused, never re-forked): the exact
 * same markup is needed by more than one caller — `includes/pages/song.php`
 * (server-rendered) today, and `js/modules/setlist.js` / `js/modules/print.js`
 * tomorrow via this file's JavaScript twin, `js/modules/voice-parts-render.js`.
 * Every function below takes plain arrays in and returns a plain string out
 * — no `\mysqli`, no globals, no `$_SESSION` — so it can be unit-tested with
 * hand-built fixtures and so the JS twin can be proven BYTE-IDENTICAL to it
 * (`tests/test-voice-render-lockstep.js`) by feeding both the exact same
 * `tests/fixtures/voice-render-cases.json`. Two hand-written copies of "how
 * do we draw a voice chip" WILL drift (rule #35 — "a comment saying keep
 * these in sync is the failure, not the fix"); the fixture + lockstep test
 * is the MECHANISM that makes drift impossible to ship unnoticed.
 *
 * 🔴 THE SINGLE MOST IMPORTANT RULE THIS FILE ENFORCES — a voice chip is a
 * SIBLING of `<p class="lyric-line">`, inside a wrapping `<div>`, NEVER A
 * CHILD of the `<p>` itself. Five other modules read a `.lyric-line`
 * element's `textContent` and treat it as the pure sung words with nothing
 * else mixed in: `present-mode.js` (builds presentation slides from it),
 * `share.js` (builds the "share this song" text snippet), `setlist.js`
 * (twice — the arrangement preview and the custom-arrangement playback
 * re-render), and `song-markup.js` (personal highlight/notes). If a chip's
 * `<span>` ever ended up NESTED INSIDE the `<p>`, its text ("Women") would
 * silently glue itself onto the front of every one of those readers' output
 * — a real corruption (a Present slide reading "WomenYou are holy," instead
 * of "You are holy,"), not a missing feature, and with no error anywhere to
 * find it by. `ihymnsVoiceRunOpenTag()` below only ever opens a `<div>`; the
 * `<p class="lyric-line">` for each line in the run is written by the
 * CALLER as an ordinary sibling inside it. See #2073's design-pass-7
 * synthesis, "Contradictions between passes" C1, for the earlier (wrong)
 * draft that put the chip inside the `<p>`.
 *
 * ACCESSIBILITY (cite these in review — WCAG 2.1, https://www.w3.org/TR/WCAG21/):
 *   - 1.3.1 Info and Relationships — "who is singing this" is today conveyed
 *     ONLY by where a chip happens to sit on the page; a screen-reader user
 *     gets nothing. This file fixes that by giving the wrapping `<div>` a
 *     `role="group"` and a real `aria-label` ("Women", "Women and Men",
 *     "Women, echoed by Backing") — the STRUCTURE carries the relationship,
 *     not just the visual layout.
 *   - 4.1.2 Name, Role, Value — the run's accessible NAME lives on the
 *     group; the visible chip row is `aria-hidden="true"` so a screen
 *     reader does not announce "Women" twice (once for the group, once for
 *     the chip text) — the exact pattern `includes/pages/song.php` already
 *     uses for its language badge (see that file's own :1307-ish comment).
 *   - 1.4.1 Use of Colour — nothing here is colour-only. The chip's TEXT is
 *     the cue ("Women", "Echo"); an echo/background line is ALSO italic,
 *     indented and given a dashed left rule; a sub-line echo span is ALSO
 *     underlined. A colour-blind reader (or a printed, black-and-white
 *     page) loses nothing.
 * https://www.w3.org/WAI/ARIA/apg/practices/ (role="group" naming pattern)
 *
 * WHAT THIS FILE DOES *NOT* DO (scope, honestly stated rather than silently
 * skipped — see the session hand-off for the tracked follow-ups):
 *   - It does not touch `manage/editor/*` — the Editor2 "Who sings" panel
 *     and its live preview are a separate piece of work, out of this
 *     commit's assigned files.
 *   - It does not touch `js/modules/present-mode.js` — the staggered round
 *     PROJECTOR (split-screen playback) is a separate commit's job; this
 *     file only emits the passive `data-voice-rounds` JSON + the on-page
 *     round note song.php shows every visitor, which present-mode.js can
 *     later read from the DOM the same way it already reads `.lyric-line`.
 *   - It does not attempt Android/Apple rendering. `appAndroid/…/Song.kt`
 *     and `appApple/…/SongComponent.swift` already TOLERATE the new
 *     `voices`/`voiceSpans` keys (commit `c2985fef`, #2073) but nothing in
 *     either app's UI actually DRAWS a chip yet — that is a real, tracked
 *     gap (see this commit's hand-off note, which files it rather than
 *     leaving it to be found by accident the way component `Label` was
 *     before `tests/test-component-label-sites.js` existed).
 *
 * ⚠️ THE ROUND-SHAPE ADAPTER, `ihymnsVoiceRoundsExpand()`, BELOW — WHY IT
 * EXISTS (a plan gap, flagged rather than silently patched around):
 * Design-pass-5 §0 froze ONE shape for a round — `{id, kind, label,
 * endingMode, lineIds, voices, timeline}` — and said Pass 1's read core
 * (`vocalPartsForSong()` / `lyricRoundsForVersion()`) would hand it over
 * ready-made. What actually landed there (commit 5, `includes/lyric_rounds.php`)
 * is the raw DATABASE row shape instead — `startLineId`/`endLineId` rather
 * than an already-expanded `lineIds` list, and no `timeline` at all — even
 * though the two PURE building blocks that shape needs
 * (`lyricRoundSubjectLineIds()`, `lyricRoundTimeline()`) were written and
 * exported from that very file; nothing in the tree calls either of them
 * yet. This commit's job is RENDER, and `includes/lyric_rounds.php` is not
 * one of this commit's assigned files, so rather than editing that file (or
 * silently re-deriving its maths a second time — rule #22 forbids exactly
 * that), `ihymnsVoiceRoundsExpand()` below REUSES those two existing pure
 * functions to bridge the gap for the one thing THIS commit's render sites
 * need: which lines belong to a round, so a note can be shown and
 * `data-round-id` can be stamped on them. A step-by-step `timeline` (the
 * thing an actual playback projector needs) is deliberately left OUT of the
 * `data-voice-rounds` JSON this commit emits — the key is simply absent,
 * matching this codebase's "sparse means unavailable" convention — because
 * building one correctly needs per-line timing data this read path does not
 * carry, and the only consumer of a `timeline` (the projector, a separate,
 * later commit) does not exist in the tree yet either. The natural, better
 * home for this adapter is really `lyricRoundsForVersion()`'s own caller
 * inside `includes/lyric_rounds.php` (matching the plan's own
 * `lyricRoundsForSong()` name) — flagged in this commit's report as a
 * follow-up rather than reached for here.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'vocal_parts.php';

/* ---------------------------------------------------------------------
 * SMALL PURE HELPERS (private — no leading `ihymnsVoice`, so a caller
 * outside this file has no reason to reach for them directly).
 * ------------------------------------------------------------------ */

/**
 * ELI5: join a list of names the way a person would say them out loud —
 * "Alice", "Alice and Bob", "Alice, Bob and Carol" — never the Oxford-comma
 * form ("Alice, Bob, and Carol"), matching the plain, everyday-English house
 * style (.claude/CLAUDE.md's "plain English" rule) and the exact wording
 * Design-pass-5 §1.4 asked for ("Women, Men and Choir").
 *
 * DETAILED: empty strings are dropped first (a part with no resolvable
 * label is not announced as a blank comma); an empty result returns ''.
 *
 * @param list<string> $labels
 */
function _ihymnsVoiceSerialJoin(array $labels): string
{
    $labels = array_values(array_filter(
        $labels,
        static fn(string $l): bool => trim($l) !== ''
    ));
    $n = count($labels);
    if ($n === 0) {
        return '';
    }
    if ($n === 1) {
        return $labels[0];
    }
    if ($n === 2) {
        return $labels[0] . ' and ' . $labels[1];
    }
    $last = array_pop($labels);
    return implode(', ', $labels) . ' and ' . $last;
}

/**
 * ELI5: "Round" / "Canon" / "Partner song" — the plain-English word for a
 * round's `kind`, read straight from `IHYMNS_ROUND_KINDS`'s own vocabulary
 * rather than a second, hand-typed copy of the three words (rule #34/#43 —
 * never duplicate a central vocabulary).
 */
function _ihymnsVoiceRoundKindLabel(string $kind): string
{
    switch ($kind) {
        case 'canon':
            return 'Canon';
        case 'partner-song':
            return 'Partner song';
        default:
            return 'Round';
    }
}

/**
 * ELI5: turn a whole number of milliseconds into a clock reading like
 * "0:04" or "1:23" — used only in a round note's "at 0:04" clause when a
 * voice's entry point is known in real time (the `ms` timing basis).
 */
function _ihymnsVoiceFormatMs(int $ms): string
{
    $totalSeconds = (int)round(max(0, $ms) / 1000);
    $m = intdiv($totalSeconds, 60);
    $s = $totalSeconds % 60;
    return $m . ':' . str_pad((string)$s, 2, '0', STR_PAD_LEFT);
}

/* ---------------------------------------------------------------------
 * VOICE RUNS + SPANS — reading the shape lyricLinesFoldVoiceRuns() /
 * lyricLinesAssembleFromRows() already produced (includes/lyric_lines_read.php,
 * #2073 commit 4) into a per-line lookup this file's render loop wants.
 * ------------------------------------------------------------------ */

/**
 * ELI5: "for THIS line (by its position in the component), is anyone
 * singing it as part of a group, and if so, does the group's coloured rule
 * / chip row START here, END here, or just carry on?"
 *
 * DETAILED: `$component['voices']` (when present) is a SPARSE list of RUNS
 * — `{from, to, parts}`, one entry per stretch of consecutive lines that
 * share the exact same singers (`includes/lyric_lines_read.php`'s
 * `lyricLinesFoldVoiceRuns()`), never one entry per line. This function
 * "unfolds" that back into a per-LINE-INDEX map so the render loop can ask
 * a single question per line ("is `$lineIdx` covered, and by what?") without
 * re-deriving the run maths itself. `allBg` is true only when EVERY part on
 * the run is a background/echo voice (`bg:true`) — the whole-line-echo case
 * from Design-pass-5 §1.2(c), which gets the `.lyric-line--bg` class and the
 * generic "Echo, sung by …" wording rather than a named lead voice's chip.
 *
 * @param array{voices?:list<array{from:int,to:int,parts:list<array{id:int,kind:string,label:string,bg:bool,enters:bool}>}>} $component
 * @return array<int, array{start:bool,end:bool,parts:list<array{id:int,kind:string,label:string,bg:bool,enters:bool}>,allBg:bool}>
 *   keyed by 0-based line INDEX (an index into `$component['lines']`), one
 *   entry per line a run actually covers — [] when `voices` is absent or []
 *   (the whole un-annotated corpus today), never a sparse guess.
 */
function ihymnsVoiceRunsByLineIndex(array $component): array
{
    $out  = [];
    $runs = $component['voices'] ?? [];
    if (!is_array($runs)) {
        return [];
    }
    foreach ($runs as $run) {
        $from  = (int)($run['from'] ?? 0);
        $to    = (int)($run['to'] ?? $from);
        $parts = is_array($run['parts'] ?? null) ? $run['parts'] : [];

        $allBg = $parts !== [];
        foreach ($parts as $p) {
            if (empty($p['bg'])) {
                $allBg = false;
                break;
            }
        }

        for ($i = $from; $i <= $to; $i++) {
            $out[$i] = [
                'start' => ($i === $from),
                'end'   => ($i === $to),
                'parts' => $parts,
                'allBg' => $allBg,
            ];
        }
    }
    return $out;
}

/**
 * ELI5: "does part of THIS line's text belong to a different voice, or get
 * echoed?" — e.g. "You are [holy]" where only the word "holy" is echoed.
 *
 * DETAILED: `$component['voiceSpans']` is a flat, SPARSE list (one entry per
 * span, each already carrying `line` = the 0-based line INDEX it belongs
 * to — `includes/lyric_lines_read.php`'s public shape). This groups them by
 * that index, sorts each line's spans by `start`, and — defensively, since
 * this function is a public entry point another caller could reach on its
 * own rather than only via the assembler that already de-overlaps once —
 * DROPS any span that starts before the previous KEPT span on the same line
 * ended, logging it with `error_log()` so a curator error (or a stale span
 * left behind after a line edit shortened the text) is visible in the logs
 * rather than producing malformed, overlapping `<span>` HTML.
 *
 * @param array{voiceSpans?:list<array{line:int,start:int,end:int,part:array{id:int,kind:string,label:string,bg:bool}}>} $component
 * @return array<int, list<array{line:int,start:int,end:int,part:array{id:int,kind:string,label:string,bg:bool}}>>
 *   keyed by 0-based line INDEX; [] when `voiceSpans` is absent or [].
 */
function ihymnsVoiceSpansByLineIndex(array $component): array
{
    $spans = $component['voiceSpans'] ?? [];
    if (!is_array($spans) || $spans === []) {
        return [];
    }

    $byLine = [];
    foreach ($spans as $s) {
        if (!is_array($s) || !isset($s['line'])) {
            continue;
        }
        $byLine[(int)$s['line']][] = $s;
    }

    $out = [];
    foreach ($byLine as $line => $list) {
        usort($list, static fn(array $a, array $b): int => ((int)($a['start'] ?? 0)) <=> ((int)($b['start'] ?? 0)));
        $kept   = [];
        $cursor = 0;
        foreach ($list as $s) {
            $start = (int)($s['start'] ?? 0);
            $end   = (int)($s['end'] ?? 0);
            if ($end <= $start) {
                error_log('[voice_parts_render] dropped a zero/negative-length voice span on line ' . $line . " (start={$start}, end={$end})");
                continue;
            }
            if ($start < $cursor) {
                error_log('[voice_parts_render] dropped an OVERLAPPING voice span on line ' . $line . " (start={$start} is before the previous span's end={$cursor})");
                continue;
            }
            $kept[]  = $s;
            $cursor  = $end;
        }
        if ($kept !== []) {
            $out[$line] = $kept;
        }
    }
    return $out;
}

/* ---------------------------------------------------------------------
 * HTML BUILDERS — every string here is already `htmlspecialchars(...,
 * ENT_QUOTES, 'UTF-8')`-escaped; a caller echoes the return value RAW.
 * ------------------------------------------------------------------ */

/**
 * ELI5: the words a screen reader announces for a run — "Women", "Women and
 * Men", "Women, echoed by Backing", "Echo, sung by Backing".
 *
 * DETAILED: parts are split into LEAD (not background) and BG (background/
 * echo) groups, each joined with `_ihymnsVoiceSerialJoin()`. Three shapes:
 *   - only lead parts   → "Women" / "Women and Men" / "Women, Men and Choir"
 *   - only bg parts      → "Echo, sung by " + the bg names (a whole-line echo)
 *   - a mix of both       → "{lead names}, echoed by {bg names}" (a lead voice
 *                           WITH an echo riding underneath it on the same run
 *                           — NOT itself an echo line, so it keeps its own
 *                           lead label first).
 *
 * @param list<array{id:int,kind:string,label:string,bg:bool,enters?:bool}> $parts
 */
function ihymnsVoiceRunAriaLabel(array $parts): string
{
    $lead = [];
    $bg   = [];
    foreach ($parts as $p) {
        $label = trim((string)($p['label'] ?? ''));
        if ($label === '') {
            continue;
        }
        if (!empty($p['bg'])) {
            $bg[] = $label;
        } else {
            $lead[] = $label;
        }
    }
    if ($lead === [] && $bg === []) {
        return '';
    }
    if ($lead === []) {
        return 'Echo, sung by ' . _ihymnsVoiceSerialJoin($bg);
    }
    if ($bg === []) {
        return _ihymnsVoiceSerialJoin($lead);
    }
    return _ihymnsVoiceSerialJoin($lead) . ', echoed by ' . _ihymnsVoiceSerialJoin($bg);
}

/**
 * ELI5: the small pill-shaped badges above a group of lines — one per
 * singer, "Women" / "Men" / "Echo".
 *
 * DETAILED (chip text for a background/echo part): Design-pass-5 originally
 * wanted a bg chip to read the generic word "Echo" whenever the part had no
 * CUSTOM label (only a "{label} (echo)" form for a curator-named echo voice
 * like "Response"), by comparing the part's resolved label against its
 * KIND's own default label in `IHYMNS_VOCAL_PART_KINDS`. That comparison is
 * only possible where the vocabulary map lives — server-side, in PHP — and
 * `index.php`'s `$iHymnsConfig` does not (yet) export it to the browser (a
 * separate, tracked piece of work), so the JS twin of this file has no way
 * to make the SAME comparison. Baking one string ('Backing') into the JS
 * twin to fake it would be exactly the "duplicate a central map" regression
 * rules #34/#43 exist to prevent, for a purely cosmetic difference. So both
 * renderers use the SIMPLER rule below instead, which needs no vocabulary
 * lookup at all and is therefore always byte-identical between them: a
 * background part's chip ALWAYS reads "{label} (echo)" — an unnamed
 * "Backing" part shows "Backing (echo)", a curator-named "Response" part
 * shows "Response (echo)" — with the reply-arrow icon and (via CSS) the
 * dashed border / italics carrying the rest of the "this is an echo"
 * meaning (WCAG 1.4.1 — never colour alone). Reinstating the generic "Echo"
 * wording is a natural follow-up once `iHymnsConfig.vocalPartKinds` exists.
 *
 * @param list<array{id:int,kind:string,label:string,bg:bool,enters?:bool}> $parts
 * @param array{chipClass?:string,rowClass?:string,a11y?:bool,dataAttrs?:bool} $opts
 */
function ihymnsVoiceChipsHtml(array $parts, array $opts = []): string
{
    $opts = $opts + ['chipClass' => 'lyric-voice-chip', 'rowClass' => 'lyric-voice-chips', 'a11y' => true, 'dataAttrs' => true];
    if ($parts === []) {
        return '';
    }

    $chips = '';
    foreach ($parts as $p) {
        $isBg  = !empty($p['bg']);
        $label = trim((string)($p['label'] ?? ''));
        $text  = $isBg ? trim($label . ' (echo)') : $label;
        $cls   = $opts['chipClass'] . ($isBg ? ' ' . $opts['chipClass'] . '--bg' : '');

        $dataAttr = '';
        if ($opts['dataAttrs']) {
            $dataAttr = ' data-voice-kind="' . htmlspecialchars((string)($p['kind'] ?? ''), ENT_QUOTES, 'UTF-8') . '"';
        }
        /* The reply-arrow icon (flipped horizontally so the arrow points
           BACK over the text it echoes) is aria-hidden — the row itself is
           already aria-hidden (below), and the run wrapper's own
           aria-label already says "echoed by …" in words. */
        $icon = $isBg ? '<i class="fa-solid fa-reply fa-flip-horizontal" aria-hidden="true"></i>' : '';

        $chips .= '<span class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '"' . $dataAttr . '>'
                . $icon . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    $rowAttrs = $opts['a11y'] ? ' aria-hidden="true"' : '';
    return '<span class="' . htmlspecialchars($opts['rowClass'], ENT_QUOTES, 'UTF-8') . '"' . $rowAttrs . '>' . $chips . '</span>';
}

/**
 * ELI5: the escaped, span-aware HTML for ONE line's text — plain text when
 * nobody echoes part of it, or the text with an inline `<span>` wrapped
 * around the echoed/mid-line-voice-switch words when it does.
 *
 * DETAILED: `$spans` are the ALREADY-DEOVERLAPPED, start-sorted spans for
 * THIS line (`ihymnsVoiceSpansByLineIndex()`'s per-line list) — this
 * function re-validates anyway (clamping `end` to the text's own length and
 * dropping anything that still overlaps the running cursor) because it is a
 * public entry point a future caller could reach directly. Offsets are
 * 0-based UTF-8 CODE POINTS (rule #21 of .claude/CLAUDE.md) — sliced with
 * `mb_substr(...,'UTF-8')`, never a byte index, so a span that starts after
 * a multi-byte character (an accented letter, an emoji) still lands on the
 * right character. See MDN's own code-point-vs-code-unit explainer for why
 * this distinction matters: https://developer.mozilla.org/en-US/docs/Web/API/String/length#utf-16_characters_unicode_code_points_and_grapheme_clusters
 *
 * @param list<array{line?:int,start:int,end:int,part:array{id:int,kind:string,label:string,bg:bool}}> $spans
 * @param array{spanClass?:string,a11y?:bool,dataAttrs?:bool} $opts
 */
function ihymnsVoiceLineHtml(string $text, array $spans, array $opts = []): string
{
    $opts = $opts + ['spanClass' => 'lyric-voice-span', 'a11y' => true, 'dataAttrs' => true];
    if ($spans === []) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    usort($spans, static fn(array $a, array $b): int => ((int)($a['start'] ?? 0)) <=> ((int)($b['start'] ?? 0)));
    $totalLen = mb_strlen($text, 'UTF-8');

    $html   = '';
    $cursor = 0;
    foreach ($spans as $span) {
        $start = max(0, (int)($span['start'] ?? 0));
        $end   = min($totalLen, (int)($span['end'] ?? 0));
        if ($end <= $start || $start < $cursor) {
            /* Defensive drop — see the doc-block above; the normal caller
               already ran ihymnsVoiceSpansByLineIndex() first, so this path
               is a belt-and-braces guard, not the expected route. */
            error_log('[voice_parts_render] ihymnsVoiceLineHtml() dropped an out-of-range/overlapping span (start=' . $start . ', end=' . $end . ', cursor=' . $cursor . ')');
            continue;
        }
        if ($start > $cursor) {
            $html .= htmlspecialchars(mb_substr($text, $cursor, $start - $cursor, 'UTF-8'), ENT_QUOTES, 'UTF-8');
        }

        $part  = is_array($span['part'] ?? null) ? $span['part'] : [];
        $isBg  = !empty($part['bg']);
        $label = trim((string)($part['label'] ?? ''));
        $cls   = $opts['spanClass'] . ($isBg ? ' ' . $opts['spanClass'] . '--bg' : '');
        /* "Echo" for a background span, "{label} part" for a lead voice
           taking over mid-line — Design-pass-5 §1.2(c)'s exact wording. */
        $roleDescription = $isBg ? 'Echo' : ($label !== '' ? $label . ' part' : 'Voice part');

        $attrs = '';
        if ($opts['a11y']) {
            $attrs .= ' role="group" aria-roledescription="' . htmlspecialchars($roleDescription, ENT_QUOTES, 'UTF-8') . '"';
        }
        if ($opts['dataAttrs']) {
            $attrs .= ' data-voice-part="' . (int)($part['id'] ?? 0) . '"'
                    . ' data-voice-kind="' . htmlspecialchars((string)($part['kind'] ?? ''), ENT_QUOTES, 'UTF-8') . '"'
                    . ' data-voice-bg="' . ($isBg ? '1' : '0') . '"'
                    . ' data-voice-start="' . $start . '"'
                    . ' data-voice-end="' . $end . '"';
        }

        $segment = htmlspecialchars(mb_substr($text, $start, $end - $start, 'UTF-8'), ENT_QUOTES, 'UTF-8');
        $html   .= '<span class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '"' . $attrs . '>' . $segment . '</span>';
        $cursor  = $end;
    }
    if ($cursor < $totalLen) {
        $html .= htmlspecialchars(mb_substr($text, $cursor, $totalLen - $cursor, 'UTF-8'), ENT_QUOTES, 'UTF-8');
    }
    return $html;
}

/**
 * ELI5: the OPENING `<div>` tag for a run wrapper — the caller is
 * responsible for writing the chip row + each line's `<p>` INSIDE it and
 * then closing it with a plain `</div>` once the run's last line is done
 * (see 🔴 the file-header note on why this is a `<div>`, never wrapped
 * AROUND the `<p>`'s own attributes).
 *
 * @param list<array{id:int,kind:string,label:string,bg:bool,enters?:bool}> $parts
 * @param array{runClass?:string,a11y?:bool,dataAttrs?:bool} $opts
 */
function ihymnsVoiceRunOpenTag(array $parts, array $opts = []): string
{
    $opts = $opts + ['runClass' => 'lyric-voice-run', 'a11y' => true, 'dataAttrs' => true];

    $allBg = $parts !== [];
    foreach ($parts as $p) {
        if (empty($p['bg'])) {
            $allBg = false;
            break;
        }
    }
    $cls = $opts['runClass'] . ($allBg ? ' ' . $opts['runClass'] . '--bg' : '');

    $attrs = '';
    if ($opts['a11y']) {
        $attrs .= ' role="group" aria-label="' . htmlspecialchars(ihymnsVoiceRunAriaLabel($parts), ENT_QUOTES, 'UTF-8') . '"';
    }
    if ($opts['dataAttrs']) {
        $slim = array_map(
            static fn(array $p): array => [
                'id'    => (int)($p['id'] ?? 0),
                'kind'  => (string)($p['kind'] ?? ''),
                'label' => (string)($p['label'] ?? ''),
                'bg'    => !empty($p['bg']),
            ],
            $parts
        );
        $json   = json_encode($slim, JSON_UNESCAPED_UNICODE);
        $attrs .= ' data-voice-parts="' . htmlspecialchars($json !== false ? $json : '[]', ENT_QUOTES, 'UTF-8') . '"';
    }

    return '<div class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '"' . $attrs . '>';
}

/* ---------------------------------------------------------------------
 * ROUNDS — see the file-header note on ihymnsVoiceRoundsExpand() for why
 * this section exists and what it deliberately leaves out (`timeline`).
 * ------------------------------------------------------------------ */

/**
 * ELI5: turn the round rows `vocalPartsForSong()` hands back (raw
 * start/end line ids) into the small, render-ready shape this file's other
 * round functions read (an actual LIST of every line the round covers).
 *
 * DETAILED: see the file-header "⚠️ THE ROUND-SHAPE ADAPTER" note for the
 * full why. `$orderedLineIds` must be the SONG-WIDE line order (a round can
 * span more than one component, per `tblLyricRounds`' own schema comment),
 * not just one component's — the caller (`includes/pages/song.php`) builds
 * this once, up front, by concatenating every component's own `lineIds` in
 * render order. A round whose start/end ids are not both found in that list
 * (an un-migrated/inconsistent edge case `lyricRoundSubjectLineIds()`
 * already handles by returning []) is DROPPED entirely — "no round" is
 * always the safe degrade, matching every other un-migrated-install path in
 * this feature.
 *
 * @param list<array<string,mixed>> $rounds  vocalPartsForSong()['rounds'] — lyricRoundShape() rows
 * @param list<int> $orderedLineIds           every line id in this SONG, in render order
 * @return list<array{id:int,kind:string,label:?string,endingMode:string,lineIds:list<int>,codaLineIds:list<int>,voices:list<array{number:int,partId:?int,label:string,entryBasis:string,entryLines:int,entryBeats:?float,entryMs:?int}>}>
 */
function ihymnsVoiceRoundsExpand(array $rounds, array $orderedLineIds): array
{
    if ($rounds === [] || $orderedLineIds === []) {
        return [];
    }
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'lyric_rounds.php';
    if (!function_exists('lyricRoundSubjectLineIds')) {
        return [];
    }

    $out = [];
    foreach ($rounds as $round) {
        $startLineId = (int)($round['startLineId'] ?? 0);
        $endLineId   = isset($round['endLineId']) && $round['endLineId'] !== null ? (int)$round['endLineId'] : null;
        $lineIds     = lyricRoundSubjectLineIds($orderedLineIds, $startLineId, $endLineId);
        if ($lineIds === []) {
            continue;
        }

        $codaLineIds = [];
        if (($round['endingMode'] ?? '') === 'coda' && !empty($round['codaStartLineId'])) {
            $codaEnd     = isset($round['codaEndLineId']) && $round['codaEndLineId'] !== null ? (int)$round['codaEndLineId'] : null;
            $codaLineIds = lyricRoundSubjectLineIds($orderedLineIds, (int)$round['codaStartLineId'], $codaEnd);
        }

        $voices = [];
        foreach (($round['voices'] ?? []) as $v) {
            $voices[] = [
                'number'     => (int)($v['number'] ?? 0),
                'partId'     => isset($v['partId']) ? (int)$v['partId'] : null,
                'label'      => (string)($v['displayLabel'] ?? $v['label'] ?? ('Voice ' . (int)($v['number'] ?? 0))),
                'entryBasis' => (string)($v['entryBasis'] ?? 'lines'),
                'entryLines' => (int)($v['entryLines'] ?? 0),
                'entryBeats' => isset($v['entryBeats']) ? (float)$v['entryBeats'] : null,
                'entryMs'    => isset($v['entryMs']) ? (int)$v['entryMs'] : null,
            ];
        }

        $out[] = [
            'id'          => (int)($round['id'] ?? 0),
            'kind'        => (string)($round['kind'] ?? 'round'),
            'label'       => ($round['label'] ?? null) !== null && trim((string)$round['label']) !== '' ? (string)$round['label'] : null,
            'endingMode'  => (string)($round['endingMode'] ?? 'complete'),
            'lineIds'     => $lineIds,
            'codaLineIds' => $codaLineIds,
            'voices'      => $voices,
        ];
    }
    return $out;
}

/**
 * ELI5: the small note block shown above the FIRST line of a round —
 * "Round · 2 voices · Voice 2 enters after 2 lines".
 *
 * DETAILED: one visible clause per voice that actually has an entry offset
 * worth mentioning (voice 1 always enters at 0, so it is never named); the
 * `aria-label` says the same thing in a fuller sentence for a screen reader
 * ("Round for 2 voices. Voice 2 enters 2 lines after Voice 1."). Returns ''
 * when the round covers no lines at all (an inconsistent/un-migrated round
 * — never render a note that points at nothing).
 *
 * @param array{id:int,kind:string,endingMode:string,lineIds:list<int>,voices:list<array{number:int,label:string,entryBasis:string,entryLines:int,entryMs:?int}>} $round
 */
function ihymnsVoiceRoundNoteHtml(array $round): string
{
    $lineIds = $round['lineIds'] ?? [];
    if ($lineIds === []) {
        return '';
    }
    $voices    = $round['voices'] ?? [];
    $n         = count($voices);
    $kindLabel = _ihymnsVoiceRoundKindLabel((string)($round['kind'] ?? 'round'));

    $visibleClauses = [];
    $ariaClauses    = [];
    foreach ($voices as $v) {
        $entryLines = (int)($v['entryLines'] ?? 0);
        $entryMs    = $v['entryMs'] ?? null;
        $basis      = (string)($v['entryBasis'] ?? 'lines');
        if ($entryLines <= 0 && $entryMs === null) {
            continue;   // voice 1 (or any voice with nothing worth saying)
        }
        $num = (int)($v['number'] ?? 0);
        if ($basis === 'ms' && $entryMs !== null) {
            $when             = 'at ' . _ihymnsVoiceFormatMs((int)$entryMs);
            $visibleClauses[] = "Voice {$num} enters {$when}";
            $ariaClauses[]    = "Voice {$num} enters {$when} after Voice 1";
        } else {
            $plural           = $entryLines === 1 ? 'line' : 'lines';
            $visibleClauses[] = "Voice {$num} enters after {$entryLines} {$plural}";
            $ariaClauses[]    = "Voice {$num} enters {$entryLines} {$plural} after Voice 1";
        }
    }

    $voicesWord = $n === 1 ? 'voice' : 'voices';
    $visible    = htmlspecialchars($kindLabel, ENT_QUOTES, 'UTF-8') . ' &middot; ' . $n . ' ' . htmlspecialchars($voicesWord, ENT_QUOTES, 'UTF-8');
    if ($visibleClauses !== []) {
        $visible .= ' &middot; ' . htmlspecialchars(implode('; ', $visibleClauses), ENT_QUOTES, 'UTF-8');
    }

    $ariaLabel = $kindLabel . ' for ' . $n . ' ' . $voicesWord . '.';
    if ($ariaClauses !== []) {
        $ariaLabel .= ' ' . implode('. ', $ariaClauses) . '.';
    }

    $roundId = (int)($round['id'] ?? 0);
    return '<div class="lyric-round-note" role="note" aria-label="' . htmlspecialchars($ariaLabel, ENT_QUOTES, 'UTF-8') . '" data-round-id="' . $roundId . '">'
         . '<i class="fa-solid fa-rotate me-1" aria-hidden="true"></i>' . $visible . '</div>';
}

/**
 * ELI5: two lookup tables the render loop can query PER LINE — "does this
 * line need the note above it?" and "is this line part of a round at all
 * (so it should get `data-round-id`)?"
 *
 * DETAILED: first-round-wins on a genuine overlap (two rounds should not
 * normally share a line, but this function never throws over it — it just
 * picks whichever round it saw first, matching Design-pass-5's own "first-
 * wins" instruction for note placement).
 *
 * @param list<array{id:int,lineIds:list<int>,codaLineIds?:list<int>}> $rounds  ihymnsVoiceRoundsExpand() output
 * @return array{noteAt:array<int,array<string,mixed>>,lineRound:array<int,int>}
 */
function ihymnsVoiceRoundIndex(array $rounds): array
{
    $noteAt    = [];
    $lineRound = [];
    foreach ($rounds as $round) {
        $lineIds = $round['lineIds'] ?? [];
        if ($lineIds === []) {
            continue;
        }
        $first = (int)$lineIds[0];
        if (!isset($noteAt[$first])) {
            $noteAt[$first] = $round;
        }
        foreach (array_merge($lineIds, $round['codaLineIds'] ?? []) as $lid) {
            $lid = (int)$lid;
            if (!isset($lineRound[$lid])) {
                $lineRound[$lid] = (int)($round['id'] ?? 0);
            }
        }
    }
    return ['noteAt' => $noteAt, 'lineRound' => $lineRound];
}

/**
 * ELI5: the value for `.page-song[data-voice-rounds]` — the whole song's
 * round list, as JSON, safe to drop into an HTML attribute.
 *
 * DETAILED: only the keys a future consumer actually needs survive into the
 * JSON (never the raw DB row's every column) — `codaLineIds` is included
 * ONLY when non-empty (sparse, matching this codebase's usual convention).
 * No `timeline` key is emitted — see the file-header note on
 * `ihymnsVoiceRoundsExpand()` for why. Returns '' (no attribute at all,
 * never an empty `[]`) when there is nothing to say.
 *
 * @param list<array<string,mixed>> $rounds  ihymnsVoiceRoundsExpand() output
 */
function ihymnsVoiceRoundsDataAttr(array $rounds): string
{
    if ($rounds === []) {
        return '';
    }
    $slim = [];
    foreach ($rounds as $r) {
        $entry = [
            'id'         => (int)($r['id'] ?? 0),
            'kind'       => (string)($r['kind'] ?? 'round'),
            'label'      => $r['label'] ?? null,
            'endingMode' => (string)($r['endingMode'] ?? 'complete'),
            'lineIds'    => array_map('intval', $r['lineIds'] ?? []),
            'voices'     => array_map(
                static fn(array $v): array => [
                    'number'     => (int)($v['number'] ?? 0),
                    'partId'     => $v['partId'] ?? null,
                    'label'      => (string)($v['label'] ?? ''),
                    'entryLines' => (int)($v['entryLines'] ?? 0),
                    'entryMs'    => $v['entryMs'] ?? null,
                ],
                $r['voices'] ?? []
            ),
        ];
        if (!empty($r['codaLineIds'])) {
            $entry['codaLineIds'] = array_map('intval', $r['codaLineIds']);
        }
        $slim[] = $entry;
    }
    $json = json_encode($slim, JSON_UNESCAPED_UNICODE);
    return $json !== false ? htmlspecialchars($json, ENT_QUOTES, 'UTF-8') : '';
}
