<?php

declare(strict_types=1);

/**
 * iHymns — public list-sort coverage guard (#1786 Option B, rule #34)
 *
 * ELI5
 * ----
 * Every public catalogue page that shows a list of songs (or songbooks, or
 * publishers' books…) should offer the "Sort ▾" control, and the control's
 * markup should actually MATCH what's on the page — the right container
 * tagged, the right per-item attributes present for every key the panel
 * offers. This test walks every public page fragment, finds the ones that
 * look like a sortable catalogue list, and proves each one is wired
 * correctly rather than just LOOKING wired.
 *
 * WHY IT IS TREE-DERIVED, NOT A HAND-TYPED LIST
 * ----------------------------------------------
 * Candidates come from `glob('includes/pages/*.php')` filtered to files
 * whose stripped source either (a) carries a `class="…song-list…"` or
 * `class="…card-songbook…"` fingerprint — the marker convention every
 * DOM-mode sortable list/tile-grid container (or its tile) in this tree
 * uses — or (b) `require`s `list-sort-control.php` at all (catches an
 * array/server-mode surface, which has no DOM container to fingerprint).
 * Only TWO allow-lists exist, and both are DOCUMENTED WITH A REASON
 * (rule #34):
 *
 *   $PAGE_EXCLUSIONS    — whole pages whose matching list is NOT meant to
 *                          be sortable at all (a curated shortlist, a
 *                          ranked section) — never checked further.
 *   $PARTIAL_ENTRIES     — pages that DO offer the control but in
 *                          array/server mode (no `data-list-sort-list`
 *                          container to check at all — favourites is
 *                          JS-rendered from localStorage, search is
 *                          paginated from the server). Only the control's
 *                          presence + its `$listSortOptions` block are
 *                          checked; the corresponding JS wiring is
 *                          `tests/test-list-sort.js`'s job.
 *   $TILE_INDIRECTION    — pages where the fingerprinted tag and the
 *                          `data-list-sort-list` container are DIFFERENT
 *                          tags (`/songbooks`' tile grid: the sort
 *                          container is the outer `.row`, but the
 *                          fingerprint lives on each inner `.card
 *                          .card-songbook` tile) — so the same-tag
 *                          co-location check below is skipped for them.
 *
 * SCOPE / LIMITS (stated honestly, matching
 * test-admin-tables-sortable.php's convention)
 *   - Static source analysis over PHP-tag-stripped markup. It proves the
 *     MARKUP is wired, not that the control renders/behaves correctly in a
 *     browser — that is `tests/test-list-sort.js`'s job for the module's
 *     own comparator + wiring logic.
 *   - The same-tag co-location check (assertion 3) is PAGE-scoped: it
 *     proves every FINGERPRINTED tag on an in-scope page also carries
 *     `data-list-sort-list`, which is exactly the "a second sortable list
 *     was added without wiring it" class of regression — but it cannot
 *     prove a container CORRECTLY groups the right items, only that it is
 *     tagged.
 *
 * Usage: php tests/php/test-public-list-sort.php
 * Exit 0 = pass, 1 = fail.
 */

$root  = dirname(__DIR__, 2);
$pages = $root . '/appWeb/public_html/includes/pages';

if (!is_dir($pages)) {
    fwrite(STDERR, "FAIL: {$pages} not found.\n");
    exit(1);
}

/* ---------------------------------------------------------------------
 * Whole-page exclusions — matches the fingerprint or requires the
 * partial, but is NOT meant to offer user-controllable sort at all.
 * ------------------------------------------------------------------- */
$PAGE_EXCLUSIONS = [
    'song.php' => 'JS-populated 5-item relevance-scored related/translations shortlists — a curated shortlist, not a user-orderable record list (plan §2 row 15)',
    'home.php' => 'Popular/Recently-Viewed/Theme-chip sections are server-RANKED (views-30d, recency, usage) — re-sorting a popularity list falsifies it; the songbook grid duplicates /songbooks one tap away (⚑ N6, plan §2 rows 11-12)',
];

/* ---------------------------------------------------------------------
 * Array/server-mode surfaces — the control exists and is checked for
 * presence + a sane $listSortOptions block, but there is NO
 * data-list-sort-list container to check (see the module doc-block).
 * ------------------------------------------------------------------- */
$PARTIAL_ENTRIES = [
    'favorites.php' => 'array mode — JS-rendered from a localStorage array (favorites.js); wired via wireListSortControl(), asserted in tests/test-list-sort.js',
    'search.php'    => 'server-sort mode — paginated results (search.js); a client DOM-reorder would sort only the loaded page, so sorting is a server sort= param instead, asserted in tests/test-list-sort.js',
];

/* ---------------------------------------------------------------------
 * Pages where the fingerprinted tag and the data-list-sort-list
 * container are deliberately DIFFERENT tags — the same-tag co-location
 * check (assertion 3) does not apply to these.
 * ------------------------------------------------------------------- */
$TILE_INDIRECTION = [
    'songbooks.php' => 'the sortable container is the outer .row.g-3 tile GRID; the song-list/card-songbook fingerprint lives on each inner .card.card-songbook TILE, a different tag',
];

/** Strip PHP tags so a `?>` or stray `>` inside a PHP expression can never
 *  desynchronise the HTML-tag regexes below (test-admin-tables-sortable.php's
 *  stripPhp() idiom, applied here too). */
function lsStripPhp(string $s): string
{
    $s = preg_replace('~<\?php.*?\?>~s', '', $s) ?? $s;
    $s = preg_replace('~<\?=.*?\?>~s', '', $s) ?? $s;
    return $s;
}

/** Does `$stripped` contain a `class="…"` attribute carrying the EXACT
 *  token 'song-list' or 'card-songbook'? A plain `\bsong-list\b` regex
 *  would ALSO match inside "song-list-item" (a hyphen is a non-word
 *  character, so a word boundary sits right after "list" there too) —
 *  every per-song row anchor would then false-positive as a fingerprinted
 *  CONTAINER. Real token-list membership is the only precise test. */
function lsHasFingerprintClass(string $stripped): bool
{
    if (!preg_match_all('~class="([^"]*)"~', $stripped, $all)) {
        return false;
    }
    foreach ($all[1] as $classAttr) {
        $classes = preg_split('~\s+~', trim($classAttr)) ?: [];
        if (in_array('song-list', $classes, true) || in_array('card-songbook', $classes, true)) {
            return true;
        }
    }
    return false;
}

$files = glob($pages . '/*.php') ?: [];
sort($files);

$candidates = [];
foreach ($files as $path) {
    $base = basename($path);
    $raw  = (string) file_get_contents($path);
    $stripped = lsStripPhp($raw);
    $isFingerprinted = lsHasFingerprintClass($stripped);
    $requiresControl = str_contains($raw, 'list-sort-control.php');
    if (!$isFingerprinted && !$requiresControl) {
        continue; // not a candidate at all — nothing sortable-looking here
    }
    if (isset($PAGE_EXCLUSIONS[$base])) {
        continue; // documented exclusion
    }
    $candidates[$base] = ['raw' => $raw, 'stripped' => $stripped];
}

if (count($candidates) < 8) {
    fwrite(STDERR, "FAIL: only " . count($candidates) . " candidate public page(s) found — the fingerprint\n");
    fwrite(STDERR, "      glob or the exclusion list has gone wrong; this guard would be vacuous.\n");
    exit(1);
}

$failures      = [];
$pagesChecked  = 0;
$fullChecked   = 0;
$optionsChecked = 0;

foreach ($candidates as $base => $entry) {
    $pagesChecked++;
    $raw      = $entry['raw'];
    $stripped = $entry['stripped'];

    /* ---- (a) $listSortSurface assignment ---- */
    if (!preg_match("~\\\$listSortSurface\\s*=\\s*'([a-z0-9-]{1,40})'~", $raw, $m)) {
        $failures[] = "{$base} — matches the sortable-list fingerprint (or requires list-sort-control.php) but has no "
            . "\$listSortSurface = '…' assignment. Either wire it up (a \$listSortSurface + \$listSortOptions block + "
            . "the list-sort-control.php require) or add {$base} to \$PAGE_EXCLUSIONS with a reason.";
        continue;
    }
    $surface = $m[1];

    /* ---- (b) the control partial is actually required ---- */
    if (!str_contains($raw, 'list-sort-control.php')) {
        $failures[] = "{$base} — has \$listSortSurface = '{$surface}' but never requires "
            . "includes/partials/list-sort-control.php. The control is declared but never rendered.";
        continue;
    }

    /* ---- (c) extract $listSortOptions keys (WIDE window — rule #34's
     *      narrow-window lesson: bound on the matching '];', not a fixed
     *      character count) ---- */
    if (!preg_match(
        "~\\\$listSortOptions\\s*=\\s*\\[(.*?)\\];~s",
        $raw,
        $optM
    )) {
        $failures[] = "{$base} — has \$listSortSurface = '{$surface}' but no \$listSortOptions = [ … ]; block was found.";
        continue;
    }
    /* Top-level option keys only — `'number' => [ 'label' => …, 'type' => …
     *  ]`. Requiring the `=>` to be followed by `[` (not a bare string
     *  value) is what excludes the NESTED label/type/dir keys inside each
     *  option's own sub-array from being mistaken for option keys
     *  themselves. */
    preg_match_all("~'([a-z0-9_-]+)'\\s*=>\\s*\\[~", $optM[1], $keyM);
    $optionKeys = array_values(array_unique($keyM[1] ?? []));
    if ($optionKeys === []) {
        $failures[] = "{$base} — \$listSortOptions block was found but no option keys were parsed out of it "
            . "(the array-literal shape may have drifted from what this guard expects).";
        continue;
    }
    $optionsChecked += count($optionKeys);

    /* ---- PARTIAL entries stop here — no container/attribute checks ---- */
    if (isset($PARTIAL_ENTRIES[$base])) {
        continue;
    }

    /* ---- FULL (DOM-mode) entries ---- */
    $fullChecked++;

    /* (d) at least one data-list-sort-list="<surface>" occurrence */
    $listAttrRe = '~data-list-sort-list="' . preg_quote($surface, '~') . '"~';
    if (!preg_match($listAttrRe, $stripped)) {
        $failures[] = "{$base} — \$listSortSurface = '{$surface}' but no container carries "
            . "data-list-sort-list=\"{$surface}\". The control renders but nothing on the page is wired to it "
            . "(the same 'tagged but dead' shape as #1799) — if this surface is genuinely array/server-mode, "
            . "add {$base} to \$PARTIAL_ENTRIES with a reason.";
        continue;
    }

    /* (e) option-key -> data-sort-<key> cross-check. This is the mechanism
     *     against the quietest failure this feature can produce: a typo'd
     *     key makes every row tie at that level — the control "works", the
     *     list never visibly moves, nothing errors. */
    foreach ($optionKeys as $key) {
        $attrRe = '~data-sort-' . preg_quote($key, '~') . '=~';
        if (!preg_match($attrRe, $stripped)) {
            $failures[] = "{$base} — \$listSortOptions has key '{$key}' but no item anywhere in the file carries "
                . "data-sort-{$key}=\"…\". Every row will tie on this level and the control will silently do nothing "
                . "when it is picked.";
        }
    }

    /* (f) same-tag co-location: every fingerprinted tag ALSO carries
     *     data-list-sort-list on the SAME tag, unless documented in
     *     $TILE_INDIRECTION. Catches a SECOND sortable-looking container
     *     added to an already-adopted page without wiring it. */
    if (!isset($TILE_INDIRECTION[$base])) {
        preg_match_all('~<[a-zA-Z][a-zA-Z0-9]*\s[^>]*class="[^"]*"[^>]*>~', $stripped, $tagM);
        foreach ($tagM[0] as $tag) {
            if (!preg_match('~\bclass="([^"]*)"~', $tag, $classM)) continue;
            /* EXACT class-token membership, not a substring/word-boundary
             * match: `\bsong-list\b` would ALSO match inside
             * "song-list-item" (a hyphen is a non-word character, so a
             * word boundary sits right after "list" there too) — every
             * per-song anchor on every one of these pages would then
             * false-positive as an unwired "container". Splitting the
             * class attribute into real tokens and checking membership
             * is the only way to tell "song-list" (the container) apart
             * from "song-list-item" (each row inside it). */
            $classes = preg_split('~\s+~', trim($classM[1])) ?: [];
            $isFingerprintTag = in_array('song-list', $classes, true) || in_array('card-songbook', $classes, true);
            if (!$isFingerprintTag) continue;
            if (!str_contains($tag, 'data-list-sort-list=')) {
                $failures[] = "{$base} — a song-list/card-songbook-fingerprinted tag has no data-list-sort-list "
                    . "attribute on the SAME tag: " . trim(preg_replace('~\s+~', ' ', $tag)) . " "
                    . "(if the container and the fingerprinted tag are genuinely different tags on this page, "
                    . "add {$base} to \$TILE_INDIRECTION with a reason).";
            }
        }
    }
}

/* ---------------------------------------------------------------------
 * Self-cleaning: every documented exception must still be load-bearing —
 * an entry that no longer matches ANYTHING (or a partial entry that has
 * grown a data-list-sort-list container, meaning it silently stopped
 * being array/server-mode) is stale and must be pruned. Mirrors
 * tests/test-event-names.js's allowlist self-cleaning check.
 * ------------------------------------------------------------------- */
foreach ($PARTIAL_ENTRIES as $base => $reason) {
    $path = $pages . '/' . $base;
    if (!is_file($path)) {
        $failures[] = "\$PARTIAL_ENTRIES references {$base}, which no longer exists — stale entry.";
        continue;
    }
    $raw = (string) file_get_contents($path);
    if (!preg_match("~\\\$listSortSurface\\s*=\\s*'([a-z0-9-]{1,40})'~", $raw, $m)) {
        $failures[] = "\$PARTIAL_ENTRIES references {$base}, which no longer declares \$listSortSurface at all — stale entry.";
        continue;
    }
    if (str_contains($raw, 'data-list-sort-list="' . $m[1] . '"')) {
        $failures[] = "\$PARTIAL_ENTRIES references {$base}, but it now emits data-list-sort-list=\"{$m[1]}\" — "
            . "it looks DOM-mode now, so this is no longer a valid partial entry; remove it and let the full checks run.";
    }
}
foreach ($TILE_INDIRECTION as $base => $reason) {
    if (!isset($candidates[$base])) {
        $failures[] = "\$TILE_INDIRECTION references {$base}, which is not a checked candidate — stale entry.";
    }
}

if ($optionsChecked < 15) {
    fwrite(STDERR, "FAIL: only {$optionsChecked} option key(s) checked across all candidates — the option-key\n");
    fwrite(STDERR, "      parsing regex or the candidate set has gone wrong; this guard would be under-covering.\n");
    exit(1);
}
if ($fullChecked < 8) {
    fwrite(STDERR, "FAIL: only {$fullChecked} full (DOM-mode) page(s) checked — expected >= 8.\n");
    exit(1);
}

if ($failures) {
    fwrite(STDERR, "FAIL: public list-sort coverage (#1786):\n\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

printf(
    "PASS: public list-sort coverage — %d candidate page(s) (%d fully checked, %d partial), %d option key(s) checked, %d page(s) excluded.\n",
    $pagesChecked,
    $fullChecked,
    count($PARTIAL_ENTRIES),
    $optionsChecked,
    count($PAGE_EXCLUSIONS)
);
exit(0);
