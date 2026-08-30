<?php

declare(strict_types=1);

/**
 * iHymns — Static WCAG 2.2 AA guards from the #1150/#1151 accessibility
 * sweep, extended by the #1990 M2 (table header scope) + M8 (icon
 * aria-hidden / icon-only control naming) sweep.
 *
 * ELI5: cheap, purely-textual checks over the actual page templates that
 * catch a screen-reader user landing on an invisible control, a screen
 * reader losing track of which element an id refers to, a table column
 * with no announced header, a decorative icon a screen reader tries to
 * read out loud, or an icon-only button/link with nothing to say about
 * itself. None of these need a browser — they are facts about the HTML
 * source, derivable from the tree.
 *
 * #1990 additions (M2/M8):
 *   (M2) a11yThMissingScope() — every `<th>` in the admin + public tree
 *        must carry a `scope="…"` attribute (PRESENCE only; col-vs-row is
 *        a review call, not machine-decidable from source text alone).
 *   (M8) a11yIconAccessibility() — (a) every Bootstrap-Icons `<i class="bi
 *        …">` must be `aria-hidden="true"` OR properly named via
 *        `role="img"` + `aria-label="…"`; (b) every `<button>`/`<a>` whose
 *        entire content is one or more bi-icons and whitespace (no visible
 *        text, no PHP-echoed text) must carry an accessible name — an
 *        `aria-label`/`aria-labelledby`/`title` on the opening tag, or a
 *        `.visually-hidden` span inside.
 *
 * WHAT IT ASSERTS
 *
 *   (1) `.card-layout-handle` (includes/pages/home.php) is never rendered
 *       `aria-hidden="true"`. That element is the drag-handle strip
 *       card-layout.js injects real "Move up" / "Move down" / "Hide this
 *       card" <button>s into once edit mode is entered (#1151) — an
 *       aria-hidden ancestor removes focusable descendants from the
 *       accessibility tree while leaving them in the visual/keyboard tab
 *       order (WCAG 4.1.2 Name, Role, Value): a screen-reader user
 *       tabbing through lands on a control with no announced name or
 *       role at all. This was the actual shipped state before #1151;
 *       this guard is the regression tie.
 *
 *   (2) No PHP template under includes/pages/, includes/partials/ or
 *       manage/*.php declares the same STATIC `id="…"` twice. Two
 *       elements sharing an id is invalid HTML5 and breaks `aria-
 *       labelledby` / `for` / `aria-controls` references and any
 *       `#id` deep link — whichever element the browser picks first
 *       "wins" every `getElementById` / `:target` / label association,
 *       silently mis-wiring the other. The file list is DERIVED from
 *       the tree (glob), not hand-typed, per rule #34.
 *
 *   (3) Every `<img>` tag in the same tree carries an `alt` attribute
 *       (decorative images still need `alt=""` — an absent attribute is
 *       the one shape screen readers cannot recover from; they announce
 *       the filename instead of nothing).
 *
 * Checks (2) and (3) are PROVEN able to fail (rule #34) via the
 * self-test at the bottom of this file, which feeds the same scanner
 * functions a deliberately-broken fixture string before the real
 * source-tree scan runs — the guard is exercised against a known-bad
 * input on every single run, not just asserted to have once gone red.
 *
 * No DB, no network — a source-tree scan, same shape as
 * test-fragment-inline-scripts.php / test-accessibility-css-coverage.php.
 *
 * Usage: php tests/php/test-a11y-static-checks.php
 * Exit 0 = pass, 1 = fail.
 */

/**
 * Strip HTML comments and PHP comments before any pattern matching, so
 * prose that MENTIONS a tag/attribute (like this very file's doc-block)
 * is never mistaken for the real thing. Newlines are preserved so any
 * line-numbered diagnostic below stays truthful.
 * (test-fragment-inline-scripts.php / test-accessibility-css-coverage.php
 * hit this same trap first — worth repeating here rather than assuming.)
 */
function a11yStripComments(string $src): string
{
    // HTML comments: <!-- ... -->
    $src = preg_replace_callback(
        '~<!--.*?-->~s',
        static fn(array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
        $src
    ) ?? $src;
    // PHP block comments: /* ... */ (deliberately not touching // or # —
    // those are rare in this codebase's PHP and risk eating real code
    // after a URL containing '//').
    $src = preg_replace_callback(
        '~/\*.*?\*/~s',
        static fn(array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
        $src
    ) ?? $src;
    return $src;
}

/**
 * Find every STATIC `id="…"` attribute value in a source string.
 * Deliberately anchored on a preceding whitespace/tag-open boundary so a
 * `data-colour-picker-id="…"` or similar suffix match never counts as a
 * real `id` attribute (the naive `id="[^"]+"` regex this replaces caught
 * exactly that false positive during the #1150/#1151 sweep — `manage/
 * songbooks.php`'s `data-colour-picker-id="edit-songbook-colour"`
 * appeared "duplicated" purely because the substring `id="edit-songbook-
 * colour"` occurs at the tail of that longer attribute name three times).
 * Only literal, fully-static values are considered — an id built from a
 * PHP loop variable (`id="row-<?= $i ?>"`) never reaches this regex
 * because the `<?php ... ?>` tag breaks the `[^">?]+` character class,
 * and an id built from a JS TEMPLATE LITERAL inside an embedded
 * `<script>` block (`id="${id}"`, `manage/print-templates.php`'s
 * dynamic option-row builder) never reaches it either, because `$`/`{`
 * are also excluded — both are already per-instance unique by
 * construction (a fresh value substituted per PHP loop iteration / per
 * JS call), and a text scanner cannot evaluate PHP or JS to check them.
 * A first pass of this scanner DIDN'T exclude `${…}` and flagged four
 * `id="${id}"` JS template-literal occurrences in print-templates.php as
 * "duplicates" — kept as the a11yFindStaticIds self-test below so that
 * false positive can't come back.
 *
 * @return string[] every static id value found, in source order (with duplicates)
 */
function a11yFindStaticIds(string $src): array
{
    if (preg_match_all('~(?:^|[\s<])id="([^">?${}]+)"~m', $src, $m) !== false) {
        return $m[1];
    }
    return [];
}

/**
 * @return array<string,int> id => how many times it appears (only ids seen 2+ times)
 */
function a11yDuplicateIds(string $src): array
{
    $counts = [];
    foreach (a11yFindStaticIds($src) as $id) {
        $counts[$id] = ($counts[$id] ?? 0) + 1;
    }
    return array_filter($counts, static fn(int $n): bool => $n > 1);
}

/**
 * @return int[] 1-based line numbers of every `<img` tag missing `alt="…"` (or `alt`)
 */
function a11yImagesMissingAlt(string $src): array
{
    $lines = [];
    // Strip PHP tags first (#1968 P4): an interpolated attribute value (a PHP
    // short-echo in src) ends with a PHP close tag, whose bare > would otherwise
    // truncate the non-greedy <img …> match BEFORE a later alt="…" — a false
    // "no alt", the exact "interpolated close-tag truncates the match" class rule
    // #34 warns about. Replace each PHP block with only its own newlines so no >
    // survives inside it AND the byte offsets used for line reporting stay exact.
    $src = preg_replace_callback('~<\?(?:php|=)?[\s\S]*?\?>~', static function (array $mm): string {
        return str_repeat("\n", substr_count($mm[0], "\n"));
    }, $src) ?? $src;
    // A single <img ...> can span multiple lines in this codebase's formatted
    // markup, so match across the whole string (DOTALL-ish via [\s\S]) up to
    // the closing '>', not line-by-line.
    if (preg_match_all('~<img\b[\s\S]*?>~i', $src, $m, PREG_OFFSET_CAPTURE) !== false) {
        foreach ($m[0] as [$tag, $offset]) {
            if (!preg_match('~\balt\s*=~i', $tag)) {
                $lines[] = substr_count(substr($src, 0, $offset), "\n") + 1;
            }
        }
    }
    return $lines;
}

/**
 * @return int[] 1-based line numbers of every bare `<i>`/`<span>` that carries
 * `aria-label` with no `role` attribute anywhere on the same opening tag
 * (a11y audit M8, 2026-08-28 — ARIA 1.2 prohibits naming a generic element;
 * `role="img"` is what the codebase's own correct model, song.php's verified
 * badge, already uses).
 */
function a11yBareGenericAriaLabel(string $src): array
{
    $lines = [];
    // Same interpolated-close-tag hazard a11yImagesMissingAlt() documents —
    // strip PHP blocks to their own newlines first so a PHP short-echo inside
    // an attribute value can't truncate the `[^>]*` match early.
    $src = preg_replace_callback('~<\?(?:php|=)?[\s\S]*?\?>~', static function (array $mm): string {
        return str_repeat("\n", substr_count($mm[0], "\n"));
    }, $src) ?? $src;
    if (preg_match_all('~<(?:i|span)\b([^>]*)>~i', $src, $m, PREG_OFFSET_CAPTURE) !== false) {
        foreach ($m[1] as $idx => [$attrs, $_offset]) {
            if (preg_match('~\baria-label\s*=~i', $attrs) === 1
                && preg_match('~\brole\s*=~i', $attrs) !== 1
            ) {
                $wholeOffset = $m[0][$idx][1];
                $lines[] = substr_count(substr($src, 0, $wholeOffset), "\n") + 1;
            }
        }
    }
    return $lines;
}

/**
 * @return int[] 1-based line numbers of every `<h1>`-`<h6>` that carries
 * `role="button"` with no `tabindex` attribute on the same tag (a11y audit
 * M1, 2026-08-28 — never focusable, and Bootstrap's collapse data-API only
 * listens for `click`, so Enter/Space can never trigger it from the
 * keyboard). Deliberately scoped to HEADINGS, not every `role="button"` in
 * the tree — a real `<a href role="button">` (song.php's own correction-
 * form toggle) is natively focusable already and is the correct pattern,
 * not a variant of this bug.
 */
function a11yHeadingRoleButtonWithoutTabindex(string $src): array
{
    $lines = [];
    if (preg_match_all('~<h[1-6]\b([^>]*)>~i', $src, $m, PREG_OFFSET_CAPTURE) !== false) {
        foreach ($m[1] as $idx => [$attrs, $_offset]) {
            if (preg_match('~\brole\s*=\s*"button"~i', $attrs) === 1
                && preg_match('~\btabindex\s*=~i', $attrs) !== 1
            ) {
                $wholeOffset = $m[0][$idx][1];
                $lines[] = substr_count(substr($src, 0, $wholeOffset), "\n") + 1;
            }
        }
    }
    return $lines;
}

/**
 * @return int[] 1-based line numbers of every `<th …>` (a11y audit M2,
 * #1990) missing a `scope="…"` attribute. PHP blocks are neutralised to
 * same-count newlines FIRST (the same trap a11yImagesMissingAlt() /
 * a11yBareGenericAriaLabel() already document) so a `title="<?= $hint ?>"`
 * inside the tag can never truncate the `[^>]*` match at the embedded
 * `?>` and produce a false negative two attributes later. Tag-local — a
 * loop-generated, conditionally-rendered, string-`echo`'d, empty, or
 * multi-line `<th>` is each still caught, and a tag can never be
 * double-reported. Enforces PRESENCE only: whether the value should be
 * "col" or "row" is a review judgement (row headers are the first cell of
 * a data row, not a `<thead>` column label), not something a text scanner
 * can decide.
 */
function a11yThMissingScope(string $src): array
{
    $lines = [];
    // Same PHP-block-neutralisation as a11yImagesMissingAlt() — replace each
    // PHP short-echo/tag block with only its own newlines so an embedded
    // close tag inside an attribute value can't truncate the <th …> match early.
    $src = preg_replace_callback('~<\?(?:php|=)?[\s\S]*?\?>~', static function (array $mm): string {
        return str_repeat("\n", substr_count($mm[0], "\n"));
    }, $src) ?? $src;
    if (preg_match_all('~<th\b[^>]*>~i', $src, $m, PREG_OFFSET_CAPTURE) !== false) {
        foreach ($m[0] as [$tag, $offset]) {
            if (!preg_match('~\bscope\s*=~i', $tag)) {
                $lines[] = substr_count(substr($src, 0, $offset), "\n") + 1;
            }
        }
    }
    return $lines;
}

/**
 * Bootstrap-Icons class token test shared by both halves of
 * a11yIconAccessibility() below: true when `$classAttrValue` (the raw
 * text between the quotes of a `class="…"` attribute — may still contain
 * a literal, un-evaluated `<?= … ?>` short-echo for a dynamic SUFFIX, e.g.
 * `class="bi <?= $bannerIcon ?> mt-1"`) contains the literal `bi` token
 * that Bootstrap Icons always pairs with a `bi-<glyph>` class. A class
 * built ENTIRELY from a PHP variable with no literal "bi" text at all
 * (`class="<?= $t['iconClass'] ?>"`) cannot be proven to be a Bootstrap
 * icon from the source text alone and is deliberately NOT matched — a
 * text scanner cannot evaluate PHP to find out what the variable holds.
 */
function a11yIsBiIconClass(string $classAttrValue): bool
{
    return preg_match('~(?:^|\s)bi(?:$|[\s-])~', $classAttrValue) === 1;
}

/**
 * @return array{icons: int[], controls: int[]} the M8 remainder (a11y
 * audit #1990) over `$src` (already comment-stripped):
 *
 *   'icons'    — every `<i class="bi …">` that is neither `aria-hidden=
 *                "true"` nor already named via `role="…"` + `aria-label=
 *                "…"` together (the Step 1a pattern this same sweep put
 *                on musicians.php's sticky-note badge and venues.php's
 *                map-pin badge — ARIA 1.2 prohibits naming a bare `<i>`
 *                without a role, so `role` alone or `aria-label` alone is
 *                still wrong, mirroring a11yBareGenericAriaLabel() above).
 *   'controls' — every `<button>`/`<a>` whose ENTIRE content, once every
 *                tag is stripped, is blank AND that blank content
 *                contains at least one real `bi-` icon (never a totally
 *                empty `<button id="…"></button>` a script populates
 *                later — that is a different, legitimate pattern, not an
 *                icon standing in for a name) but carries no `aria-label`/
 *                `aria-labelledby`/`title` on the opening tag and no
 *                `.visually-hidden` span inside.
 *
 * Classification order (the "distinguish classes" rule from the #1990
 * plan): a PHP echo (`<?=`/`<?php`) anywhere in the element's ORIGINAL
 * (non-neutralised) span means the control's name comes from rendered
 * text at runtime — decorative-beside-text, skip rule (b) entirely, only
 * rule (a) (on any icon inside) still applies. Only once that's ruled out
 * does a real, literal, non-whitespace text node win it visible-text
 * naming and skip rule (b) too. Only a control with NEITHER wins the
 * "icon-only, name required" classification.
 *
 * Implementation note: `$src` is first neutralised into `$neutral`, a
 * SAME-LENGTH copy with every PHP block's non-newline bytes replaced by a
 * single space (never removed) — this keeps every byte OFFSET identical
 * between `$src` and `$neutral`, so (1) tag/element boundaries can be
 * found in `$neutral` without a stray `?>` truncating a match, while (2)
 * the ORIGINAL bytes at that same offset range in `$src` can still be
 * inspected afterwards to detect a PHP echo the neutralised copy erased
 * on purpose. Line numbers are counted against `$neutral`, which has the
 * exact same newline positions as `$src` (only non-newline bytes moved).
 *
 * The inner-content capture is bounded to ~600 chars and guarded with a
 * negative lookahead against a nested `<button`/`</button`/`<a`/`</a` so
 * a single regex pass can never straddle two sibling controls or run away
 * across a whole page on an unclosed tag.
 */
function a11yIconAccessibility(string $src): array
{
    $neutral = preg_replace_callback('~<\?(?:php|=)?[\s\S]*?\?>~', static function (array $mm): string {
        return (string) preg_replace('~[^\n]~', ' ', $mm[0]);
    }, $src) ?? $src;

    $iconLines = [];
    if (preg_match_all('~<i\b[^>]*>~i', $neutral, $m, PREG_OFFSET_CAPTURE) !== false) {
        foreach ($m[0] as [$tag, $offset]) {
            if (!preg_match('~class\s*=\s*"([^"]*)"~i', $tag, $cm) || !a11yIsBiIconClass($cm[1])) {
                continue; // not a Bootstrap-Icons glyph — out of scope
            }
            $hidden = preg_match('~\baria-hidden\s*=~i', $tag) === 1;
            $named  = preg_match('~\brole\s*=~i', $tag) === 1 && preg_match('~\baria-label\s*=~i', $tag) === 1;
            if (!$hidden && !$named) {
                $iconLines[] = substr_count(substr($neutral, 0, $offset), "\n") + 1;
            }
        }
    }

    $controlLines = [];
    $pattern = '~<(button|a)\b([^>]*)>((?:(?!</?(?:button|a)\b)[\s\S]){0,600}?)</\1>~i';
    if (preg_match_all($pattern, $neutral, $m, PREG_OFFSET_CAPTURE) !== false) {
        foreach ($m[0] as $idx => [$whole, $offset]) {
            $attrs = $m[2][$idx][0];
            $inner = $m[3][$idx][0];
            $origSlice = substr($src, $offset, strlen($whole));
            if (str_contains($origSlice, '<?=') || str_contains($origSlice, '<?php')) {
                continue; // decorative-beside-text: a PHP echo stands in for a name at runtime
            }
            if (trim((string) preg_replace('~<[^>]*>~', '', $inner)) !== '') {
                continue; // real visible text names the control
            }
            $hasBiIcon = false;
            if (preg_match_all('~<i\b[^>]*>~i', $inner, $im) !== false) {
                foreach ($im[0] as $iconTag) {
                    if (preg_match('~class\s*=\s*"([^"]*)"~i', $iconTag, $icm) && a11yIsBiIconClass($icm[1])) {
                        $hasBiIcon = true;
                        break;
                    }
                }
            }
            if (!$hasBiIcon) {
                continue; // no bi-icon inside — a script-populated empty placeholder, not this check's concern
            }
            $named = preg_match('~\b(?:aria-label|aria-labelledby|title)\s*=~i', $attrs) === 1
                || preg_match('~class\s*=\s*"[^"]*\bvisually-hidden\b[^"]*"~i', $inner) === 1;
            if (!$named) {
                $controlLines[] = substr_count(substr($neutral, 0, $offset), "\n") + 1;
            }
        }
    }

    return ['icons' => $iconLines, 'controls' => $controlLines];
}

/**
 * True when `$src` (already comment-stripped) both emits the "Skip to main
 * content" link targeting `#main-content` AND opens a `<main id="main-
 * content">` landmark — the two-part M7 fix. Both live in the same file
 * (manage/includes/admin-nav.php), so one boolean check is enough; a real
 * scan below also proves admin-footer.php closes what this opens.
 */
function a11yHasSkipLinkAndMainLandmark(string $src): bool
{
    $hasSkipLink = preg_match('~<a\s[^>]*href="#main-content"~', $src) === 1;
    $hasMain     = preg_match('~<main\b[^>]*\bid="main-content"~', $src) === 1;
    return $hasSkipLink && $hasMain;
}

/**
 * @return array{offset:int,line:int,tag:string,window:string}[] every
 * guided-wizard modal in `$src` (already comment-stripped — see below for
 * why that matters) — a `<div class="modal fade" …>` whose bounded window
 * (from its own opening tag up to the START of the NEXT such modal, or
 * 6000 chars, whichever is smaller) contains `data-wiz-progress`, the
 * js/modules/admin-wizard.js framework's own step-progress placeholder
 * (`renderProgress()` injects it into every step-based wizard modal and
 * NOTHING else in this codebase emits that string). This is a genuine
 * STRUCTURAL fingerprint, not a hand-typed file/id list (rule #34) — a
 * sixth wizard modal built on the shared stepper is picked up
 * automatically, and an ordinary Bootstrap modal (`editModal`,
 * `deleteModal`, editor2.php's plain `v2-new-modal`, …) is never swept in.
 *
 * Comment-stripping is LOAD-BEARING, not decoration: editor2.php's own
 * `v2-new-modal` (an ordinary, non-wizard modal) sits immediately before
 * `v2-new-wizard-modal` (the real wizard), separated only by a PHP
 * doc-comment that itself PROSE-MENTIONS "[data-wiz-progress]" while
 * explaining the wizard markup below it — on unstripped source, that
 * mention falls inside `v2-new-modal`'s own bounded window (it ends only
 * at the NEXT modal's opening tag, and the comment sits before that tag)
 * and misclassifies the wrong modal as a wizard. This is the exact same
 * "a doc-comment mentioning the fingerprint is not the fingerprint" trap
 * `a11yFindStaticIds()`'s own doc-block warns about for `${…}` — caught
 * here by a dedicated fixture below (rule #34) rather than assumed away.
 */
function a11yFindWizardModals(string $src): array
{
    return array_values(array_filter(
        a11yFindAllModalTags($src),
        static fn(array $modal): bool => str_contains($modal['window'], 'data-wiz-progress')
    ));
}

/**
 * @return array{offset:int,line:int,tag:string,window:string}[] EVERY
 * `<div class="modal fade" …>` in `$src` (already comment-stripped),
 * regardless of whether it's a guided-wizard modal — the shared base
 * a11yFindWizardModals() above now filters (a11y audit G2, M6,
 * 2026-08-30). Same offset/window-bounding rules as that function always
 * had (window = from the modal's own opening tag up to the START of the
 * NEXT such modal, or 6000 chars, whichever is smaller); extracted here
 * so a SECOND check (a11yModalsMissingAccessibleName() below) can reuse
 * the exact same "which modal is this, and what's inside it" logic
 * without a second, divergent copy (rule #22).
 */
function a11yFindAllModalTags(string $src): array
{
    $out = [];
    if (preg_match_all('~<div\s+class="modal fade"[^>]*>~', $src, $m, PREG_OFFSET_CAPTURE) === false) {
        return $out;
    }
    $offsets = array_map(static fn(array $x): int => $x[1], $m[0]);
    $tags    = array_map(static fn(array $x): string => $x[0], $m[0]);
    $n = count($offsets);
    for ($i = 0; $i < $n; $i++) {
        $start = $offsets[$i];
        $cap   = $i + 1 < $n ? min($offsets[$i + 1], $start + 6000) : min($start + 6000, strlen($src));
        $window = substr($src, $start, max(0, $cap - $start));
        $out[] = [
            'offset' => $start,
            'line'   => substr_count(substr($src, 0, $start), "\n") + 1,
            'tag'    => $tags[$i],
            'window' => $window,
        ];
    }
    return $out;
}

/**
 * @return int[] 1-based line numbers of every modal (a11yFindAllModalTags())
 * with no accessible name (a11y audit M6/G2, 2026-08-30) — WCAG 4.1.2 /
 * 2.4.6. Passes when the modal's own opening tag carries `aria-label="…"`
 * directly, OR its `aria-labelledby="…"` value matches the `id="…"` of a
 * heading that also carries the `modal-title` class somewhere in the
 * modal's own window — the SAME matching rule
 * a11yWizardModalsMissingLabelledby() already uses for wizard modals,
 * just applied to EVERY modal rather than only ones built on the shared
 * stepper (which is the exact gap that let editor2.php's four plain
 * modals — #v2-new-modal, #v2-bulk-move-modal, #v2-bulk-export-modal,
 * #v2-bulk-result-modal — ship with no name at all).
 */
function a11yModalsMissingAccessibleName(string $src): array
{
    $lines = [];
    foreach (a11yFindAllModalTags($src) as $modal) {
        if (preg_match('~\baria-label="[^"]+"~', $modal['tag']) === 1) {
            continue; // named directly — no heading id needed
        }
        if (!preg_match('~\baria-labelledby="([^"]+)"~', $modal['tag'], $lm)) {
            $lines[] = $modal['line'];
            continue;
        }
        $idNeedle = preg_quote($lm[1], '~');
        $hasHeadingId =
            preg_match('~<h[1-6]\b[^>]*\bclass="[^"]*\bmodal-title\b[^"]*"[^>]*\bid="' . $idNeedle . '"~', $modal['window']) === 1
            || preg_match('~<h[1-6]\b[^>]*\bid="' . $idNeedle . '"[^>]*\bclass="[^"]*\bmodal-title\b[^"]*"~', $modal['window']) === 1;
        if (!$hasHeadingId) {
            $lines[] = $modal['line'];
        }
    }
    return $lines;
}

/**
 * @return int[] 1-based line numbers of every wizard modal
 * (a11yFindWizardModals()) that is missing `aria-labelledby="…"` on the
 * modal element itself, OR whose `aria-labelledby` value has no matching
 * `id="…"` on a heading that also carries the `modal-title` class
 * anywhere in the modal's own window (a11y audit F4, 2026-08-29 wizard
 * audit) — WCAG 4.1.2, a modal with no accessible name announces only
 * "dialog" when a screen reader enters it.
 */
function a11yWizardModalsMissingLabelledby(string $src): array
{
    $lines = [];
    foreach (a11yFindWizardModals($src) as $modal) {
        if (!preg_match('~\baria-labelledby="([^"]+)"~', $modal['tag'], $lm)) {
            $lines[] = $modal['line'];
            continue;
        }
        $idNeedle = preg_quote($lm[1], '~');
        $hasHeadingId =
            preg_match('~<h[1-6]\b[^>]*\bclass="[^"]*\bmodal-title\b[^"]*"[^>]*\bid="' . $idNeedle . '"~', $modal['window']) === 1
            || preg_match('~<h[1-6]\b[^>]*\bid="' . $idNeedle . '"[^>]*\bclass="[^"]*\bmodal-title\b[^"]*"~', $modal['window']) === 1;
        if (!$hasHeadingId) {
            $lines[] = $modal['line'];
        }
    }
    return $lines;
}

/**
 * @return int[] 1-based line numbers of every wizard modal
 * (a11yFindWizardModals()) still carrying `btn-close-white` instead of the
 * theme-aware plain `btn-close` (a11y audit F5, 2026-08-29 wizard audit —
 * #953/#955's regression: white-on-white in light theme).
 */
function a11yWizardModalsBtnCloseWhite(string $src): array
{
    $lines = [];
    foreach (a11yFindWizardModals($src) as $modal) {
        if (str_contains($modal['window'], 'btn-close-white')) {
            $lines[] = $modal['line'];
        }
    }
    return $lines;
}

/* ---------------------------------------------------------------------------
 * SELF-TEST — prove the scanners can actually fail (rule #34) before
 * trusting them against the real tree.
 * ------------------------------------------------------------------------- */
$selfTestFailures = [];

$dupFixture = '<div id="thing"></div><span id="thing"></span><p data-colour-picker-id="thing"></p>';
$dupFound = a11yDuplicateIds($dupFixture);
if (!isset($dupFound['thing']) || $dupFound['thing'] !== 2) {
    $selfTestFailures[] = 'a11yDuplicateIds() did not catch a deliberately duplicated id="thing" '
        . '(or miscounted the data-colour-picker-id look-alike) — the scanner cannot be trusted.';
}
$cleanFixture = '<div id="thing-a"></div><span id="thing-b"></span>';
if (a11yDuplicateIds($cleanFixture) !== []) {
    $selfTestFailures[] = 'a11yDuplicateIds() flagged two DIFFERENT ids as duplicates — false positive.';
}

// Regression fixture for the real false positive this scanner produced on its
// first run against manage/print-templates.php: a JS template literal
// `id="${id}"` repeated across several independent, runtime-branched code
// paths inside an embedded <script> block is NOT a static duplicate id.
$jsTemplateFixture = 'let s = `<select id="${id}">` + `<input id="${id}">` + `<input id="${id}">`;';
if (a11yDuplicateIds($jsTemplateFixture) !== []) {
    $selfTestFailures[] = 'a11yDuplicateIds() flagged a JS template-literal id="${id}" as a static duplicate '
        . '(the print-templates.php false positive) — the ${…} exclusion regressed.';
}

$altFixture = '<img src="/x.png" alt="A photo"><img src="/y.png">';
$altMissing = a11yImagesMissingAlt($altFixture);
if ($altMissing !== [1]) {
    $selfTestFailures[] = 'a11yImagesMissingAlt() did not flag the second <img> (no alt) on fixture line 1, '
        . 'or wrongly flagged the first (which has alt) — got line(s): ' . implode(',', $altMissing);
}
$altOkFixture = "<img src=\"/x.png\" alt=\"\">\n<img src=\"/y.png\" alt=\"Decorative\">";
if (a11yImagesMissingAlt($altOkFixture) !== []) {
    $selfTestFailures[] = 'a11yImagesMissingAlt() flagged an <img> that HAS alt (including alt="") — false positive.';
}

$commentFixture = "<!-- an <img src=x> in a comment --><div id=\"real\"></div>";
if (a11yImagesMissingAlt(a11yStripComments($commentFixture)) !== []) {
    $selfTestFailures[] = 'a11yImagesMissingAlt() flagged an <img> that only existed inside an HTML comment.';
}

// #1968 P4 — an <img> whose src is a PHP short-echo (a PHP close tag inside the
// tag) but which DOES carry an alt must NOT be flagged (the truncation removed)...
$phpOpen = '<' . '?=';
$phpClose = '?' . '>';
$altInterpOk = '<img class="c"' . "\n"
    . '     src="' . $phpOpen . ' h($u) ' . $phpClose . '"' . "\n"
    . '     alt="' . $phpOpen . ' h($a) ' . $phpClose . '">';
if (a11yImagesMissingAlt($altInterpOk) !== []) {
    $selfTestFailures[] = 'a11yImagesMissingAlt() false-flagged an interpolated <img> that HAS alt after a PHP echo in src — the PHP-strip regressed.';
}
// ...while an interpolated <img> with NO alt is STILL flagged (no over-strip).
$altInterpMissing = '<img src="' . $phpOpen . ' h($u) ' . $phpClose . '">';
if (a11yImagesMissingAlt($altInterpMissing) === []) {
    $selfTestFailures[] = 'a11yImagesMissingAlt() missed an interpolated <img> with NO alt — the PHP-strip over-stripped the tag.';
}

// M8 — a11yBareGenericAriaLabel() must catch a bare <i>/<span> aria-label
// with no role, must NOT flag one that already has role="img", and must NOT
// flag an aria-hidden icon (no aria-label at all) or a REAL element (e.g.
// <button aria-label="…">, which is a valid target for naming).
$bareFixture = '<i class="fa-solid fa-star" aria-label="Canonical version" title="x"></i>' . "\n"
    . '<span class="badge" aria-label="Has audio"></span>';
$bareLines = a11yBareGenericAriaLabel($bareFixture);
if ($bareLines !== [1, 2]) {
    $selfTestFailures[] = 'a11yBareGenericAriaLabel() did not flag both the bare <i> (line 1) and <span> '
        . '(line 2) with aria-label and no role — got line(s): ' . implode(',', $bareLines);
}
$roleOkFixture = '<i class="fa-solid fa-star" role="img" aria-label="Canonical version"></i>'
    . '<span aria-hidden="true"></span>'
    . '<button aria-label="Close"></button>';
if (a11yBareGenericAriaLabel($roleOkFixture) !== []) {
    $selfTestFailures[] = 'a11yBareGenericAriaLabel() false-flagged an <i role="img">, an aria-hidden <span> with '
        . 'no aria-label, or a real <button aria-label> — only a BARE i/span missing role should ever be flagged.';
}
// Interpolated attribute (PHP short-echo) must not truncate the tag match
// early and produce a false negative on a real defect two lines down.
$bareInterpFixture = '<i class="' . $phpOpen . ' h($c) ' . $phpClose . '"></i>' . "\n"
    . "\n"
    . '<i class="fa-solid fa-star" aria-label="Canonical version"></i>';
if (a11yBareGenericAriaLabel($bareInterpFixture) !== [3]) {
    $selfTestFailures[] = 'a11yBareGenericAriaLabel() mishandled a PHP-interpolated <i> attribute — expected only '
        . 'line 3 flagged, got: ' . implode(',', a11yBareGenericAriaLabel($bareInterpFixture));
}

// M7 — a11yHasSkipLinkAndMainLandmark() must require BOTH halves of the fix,
// not either alone (a skip link with no target, or a <main id> nobody can
// reach by keyboard, are each individually still the bug).
if (a11yHasSkipLinkAndMainLandmark('<a href="#main-content">Skip</a><main id="main-content">') !== true) {
    $selfTestFailures[] = 'a11yHasSkipLinkAndMainLandmark() did not accept a fixture with both the skip link '
        . 'and the id="main-content" landmark present.';
}
if (a11yHasSkipLinkAndMainLandmark('<a href="#main-content">Skip</a><main class="admin-main">') !== false) {
    $selfTestFailures[] = 'a11yHasSkipLinkAndMainLandmark() accepted a <main> with no id="main-content" — '
        . 'the skip link would target nothing.';
}
if (a11yHasSkipLinkAndMainLandmark('<main id="main-content">') !== false) {
    $selfTestFailures[] = 'a11yHasSkipLinkAndMainLandmark() accepted a page with the landmark but no skip link.';
}

// M1 — a11yHeadingRoleButtonWithoutTabindex() must catch a heading used as a
// click target with no tabindex (never keyboard-reachable, Bootstrap's
// collapse data-API is click-only), and must NOT flag one that already
// carries tabindex, nor a non-heading element (e.g. the real <a role=
// "button"> pattern this codebase correctly uses elsewhere).
$h2ButtonFixture = '<h2 role="button" data-bs-toggle="collapse">Translations</h2>';
if (a11yHeadingRoleButtonWithoutTabindex($h2ButtonFixture) !== [1]) {
    $selfTestFailures[] = 'a11yHeadingRoleButtonWithoutTabindex() did not flag <h2 role="button"> with no tabindex.';
}
$h2ButtonOkFixture = '<h2 role="button" tabindex="0" data-bs-toggle="collapse">Translations</h2>'
    . '<a role="button" href="#x">Fine</a>';
if (a11yHeadingRoleButtonWithoutTabindex($h2ButtonOkFixture) !== []) {
    $selfTestFailures[] = 'a11yHeadingRoleButtonWithoutTabindex() false-flagged a heading WITH tabindex, or a '
        . 'non-heading <a role="button"> (a real focusable element — correct usage elsewhere in this codebase).';
}

// M2 — a11yThMissingScope() must flag a scopeless <th>, accept one with
// scope="col", and — the neutraliser proof — still flag a scopeless <th>
// whose OTHER attribute is a PHP short-echo (a naive [^>]* would truncate
// at the embedded close tag and never see the missing scope=).
$thFixture = '<th>Plain</th><th scope="col">Fine</th>'
    . '<th title="' . $phpOpen . ' $hint ' . $phpClose . '">Scopeless</th>';
$thLines = a11yThMissingScope($thFixture);
if ($thLines !== [1, 1]) {
    // both scopeless <th> sit on fixture line 1 (no newlines in the fixture) —
    // expect exactly two flags, not one (which would mean the neutraliser
    // either ate the whole tag or the PHP-attribute case was missed).
    $selfTestFailures[] = 'a11yThMissingScope() did not flag both the plain scopeless <th> and the PHP-in-'
        . 'attribute scopeless <th> (and only those two) — got: ' . implode(',', $thLines);
}

// M8(a) — a11yIconAccessibility()['icons'] must flag a bare bi-icon, accept
// aria-hidden="true", accept role="img"+aria-label together, and — the
// neutraliser proof — still flag a dynamic-SUFFIX bi-icon class (a literal
// "bi" followed by a PHP short-echo suffix) with no aria-hidden.
$iconBareFixture = '<i class="bi bi-x"></i>';
if (a11yIconAccessibility($iconBareFixture)['icons'] !== [1]) {
    $selfTestFailures[] = 'a11yIconAccessibility() did not flag a bare <i class="bi bi-x"> with no aria-hidden.';
}
$iconHiddenFixture = '<i class="bi bi-x" aria-hidden="true"></i>';
if (a11yIconAccessibility($iconHiddenFixture)['icons'] !== []) {
    $selfTestFailures[] = 'a11yIconAccessibility() false-flagged an <i> that already has aria-hidden="true".';
}
$iconNamedFixture = '<i class="bi bi-x" role="img" aria-label="Canonical"></i>';
if (a11yIconAccessibility($iconNamedFixture)['icons'] !== []) {
    $selfTestFailures[] = 'a11yIconAccessibility() false-flagged an <i> already named via role="img"+aria-label.';
}
$iconDynamicFixture = '<i class="bi ' . $phpOpen . ' $c ' . $phpClose . '"></i>';
if (a11yIconAccessibility($iconDynamicFixture)['icons'] !== [1]) {
    $selfTestFailures[] = 'a11yIconAccessibility() mishandled a dynamic-suffix bi-icon class '
        . '(class="bi <?= $c ?>") with no aria-hidden — the neutraliser regressed.';
}

// M8(b) — a11yIconAccessibility()['controls'] must flag an icon-only
// <button>, accept aria-label, accept title (the codebase's own accepted
// weak-but-valid convention), NOT flag icon+visible-text (false-positive
// tripwire), NOT flag icon+PHP-echoed text (PHP-as-text, decorative-
// beside-text classification), and accept a .visually-hidden span inside.
$ctrlIconOnlyFixture = '<button type="button"><i class="bi bi-x"></i></button>';
if (a11yIconAccessibility($ctrlIconOnlyFixture)['controls'] !== [1]) {
    $selfTestFailures[] = 'a11yIconAccessibility() did not flag an icon-only <button> with no accessible name.';
}
$ctrlAriaLabelFixture = '<button type="button" aria-label="Remove"><i class="bi bi-x"></i></button>';
if (a11yIconAccessibility($ctrlAriaLabelFixture)['controls'] !== []) {
    $selfTestFailures[] = 'a11yIconAccessibility() false-flagged an icon-only <button> that has aria-label.';
}
$ctrlTitleFixture = '<button type="button" title="Remove"><i class="bi bi-x"></i></button>';
if (a11yIconAccessibility($ctrlTitleFixture)['controls'] !== []) {
    $selfTestFailures[] = 'a11yIconAccessibility() false-flagged an icon-only <button> that has title (the '
        . 'codebase\'s own accepted weak-but-valid naming convention).';
}
$ctrlIconTextFixture = '<button type="button"><i class="bi bi-x"></i> Add</button>';
if (a11yIconAccessibility($ctrlIconTextFixture)['controls'] !== []) {
    $selfTestFailures[] = 'a11yIconAccessibility() false-flagged an icon+VISIBLE-TEXT <button> ("Add") as '
        . 'icon-only — the false-positive tripwire fired.';
}
$ctrlIconPhpTextFixture = '<button type="button"><i class="bi bi-x"></i>' . $phpOpen . ' $label ' . $phpClose . '</button>';
if (a11yIconAccessibility($ctrlIconPhpTextFixture)['controls'] !== []) {
    $selfTestFailures[] = 'a11yIconAccessibility() false-flagged an icon+PHP-ECHOED-text <button> as icon-only '
        . '— a PHP echo block stands in for text at runtime (decorative-beside-text), not a violation.';
}
$ctrlVisuallyHiddenFixture = '<button type="button"><i class="bi bi-x"></i><span class="visually-hidden">Remove</span></button>';
if (a11yIconAccessibility($ctrlVisuallyHiddenFixture)['controls'] !== []) {
    $selfTestFailures[] = 'a11yIconAccessibility() false-flagged an icon-only <button> that has a '
        . '.visually-hidden span inside.';
}
// A totally empty <button id="…"></button> (no icon at all — script-populated
// later, e.g. settings.php's push-toggle-btn) must NEVER be flagged — this is
// a different, legitimate pattern, not an icon standing in for a name.
$ctrlEmptyNoIconFixture = '<button type="button" id="js-fill-me"></button>';
if (a11yIconAccessibility($ctrlEmptyNoIconFixture)['controls'] !== []) {
    $selfTestFailures[] = 'a11yIconAccessibility() false-flagged a totally empty <button> with NO icon inside '
        . '(a legitimate script-populated-later placeholder) — the has-a-bi-icon precondition regressed.';
}

// Wizard-suite audit F4/F5 (2026-08-29) — a11yFindWizardModals() /
// a11yWizardModalsMissingLabelledby() / a11yWizardModalsBtnCloseWhite().
$wizGoodFixture = '<div class="modal fade" id="xWizardModal" aria-labelledby="xWizardModalLabel">'
    . '<div class="modal-header"><h2 class="modal-title h5" id="xWizardModalLabel">X — guided</h2>'
    . '<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
    . '<div class="modal-body"><div data-wiz-progress></div></div></div>';
if (a11yFindWizardModals($wizGoodFixture) === []) {
    $selfTestFailures[] = 'a11yFindWizardModals() did not classify a fixture modal genuinely carrying '
        . 'data-wiz-progress as a wizard modal.';
}
if (a11yWizardModalsMissingLabelledby($wizGoodFixture) !== []) {
    $selfTestFailures[] = 'a11yWizardModalsMissingLabelledby() false-flagged an already-correct wizard modal '
        . '(aria-labelledby present + matching id on a modal-title heading).';
}
if (a11yWizardModalsBtnCloseWhite($wizGoodFixture) !== []) {
    $selfTestFailures[] = 'a11yWizardModalsBtnCloseWhite() false-flagged a wizard modal that already uses plain btn-close.';
}
$wizNonWizardFixture = '<div class="modal fade" id="plainModal"><div class="modal-header">'
    . '<h2 class="modal-title">Plain</h2><button type="button" class="btn-close-white" data-bs-dismiss="modal"></button>'
    . '</div><div class="modal-body">no stepper here</div></div>';
if (a11yFindWizardModals($wizNonWizardFixture) !== []) {
    $selfTestFailures[] = 'a11yFindWizardModals() misclassified an ORDINARY modal (no data-wiz-progress, even '
        . 'with a bare btn-close-white and no aria-labelledby) as a wizard modal — both wizard checks would '
        . 'wrongly fire on every plain Bootstrap modal in the app.';
}
if (a11yWizardModalsMissingLabelledby($wizNonWizardFixture) !== [] || a11yWizardModalsBtnCloseWhite($wizNonWizardFixture) !== []) {
    $selfTestFailures[] = 'a11yWizardModalsMissingLabelledby()/a11yWizardModalsBtnCloseWhite() flagged an '
        . 'ordinary (non-wizard) modal — these two checks must only ever apply to a11yFindWizardModals() output.';
}
$wizNoLabelledbyFixture = '<div class="modal fade" id="yWizardModal"><div class="modal-header">'
    . '<h2 class="modal-title">Y — guided</h2><button type="button" class="btn-close-white" data-bs-dismiss="modal"></button>'
    . '</div><div class="modal-body"><div data-wiz-progress></div></div></div>';
if (a11yWizardModalsMissingLabelledby($wizNoLabelledbyFixture) === []) {
    $selfTestFailures[] = 'a11yWizardModalsMissingLabelledby() did not flag a wizard modal with NO aria-labelledby at all.';
}
if (a11yWizardModalsBtnCloseWhite($wizNoLabelledbyFixture) === []) {
    $selfTestFailures[] = 'a11yWizardModalsBtnCloseWhite() did not flag a wizard modal still carrying btn-close-white.';
}
$wizMismatchedIdFixture = '<div class="modal fade" id="zWizardModal" aria-labelledby="zWizardModalLabel">'
    . '<div class="modal-header"><h2 class="modal-title" id="totally-different-id">Z — guided</h2>'
    . '<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
    . '<div class="modal-body"><div data-wiz-progress></div></div></div>';
if (a11yWizardModalsMissingLabelledby($wizMismatchedIdFixture) === []) {
    $selfTestFailures[] = 'a11yWizardModalsMissingLabelledby() did not flag a wizard modal whose aria-labelledby '
        . 'value points at an id nothing on the heading actually carries (a dangling reference).';
}
// The editor2.php regression this function's own doc-block names: an
// ordinary modal immediately followed by a PHP doc-comment that itself
// PROSE-MENTIONS "data-wiz-progress" while documenting the REAL wizard
// modal below it must not leak that mention into the ordinary modal's
// window once comments are stripped first (the real scan always strips
// comments before calling this — see the two live-tree assertions below).
$wizCommentLeakFixture = '<div class="modal fade" id="plainModal"><div class="modal-header">'
    . '<h2 class="modal-title">Plain</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button>'
    . '</div><div class="modal-body">no stepper here</div></div>'
    . '/* mentions [data-wiz-progress] in prose only, documenting the wizard below */'
    . '<div class="modal fade" id="realWizardModal" aria-labelledby="realWizardModalLabel">'
    . '<div class="modal-header"><h2 class="modal-title" id="realWizardModalLabel">Real</h2>'
    . '<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
    . '<div class="modal-body"><div data-wiz-progress></div></div></div>';
$wizCommentLeakStripped = a11yStripComments($wizCommentLeakFixture);
$wizCommentLeakModals = a11yFindWizardModals($wizCommentLeakStripped);
if (count($wizCommentLeakModals) !== 1 || $wizCommentLeakModals[0]['tag'] !== '<div class="modal fade" id="realWizardModal" aria-labelledby="realWizardModalLabel">') {
    $selfTestFailures[] = 'MUTATION PROOF (the editor2.php v2-new-modal false positive): a11yFindWizardModals() '
        . 'must classify EXACTLY the real wizard modal, not the preceding ordinary modal a following doc-comment '
        . 'happens to mention data-wiz-progress about — got ' . count($wizCommentLeakModals) . ' match(es).';
}

// M6 (a11y audit 2026-08-30) — a11yModalsMissingAccessibleName() must flag
// a plain (non-wizard) modal with no name at all, accept one with a direct
// aria-label, accept one whose aria-labelledby correctly matches its
// modal-title id, and flag one whose aria-labelledby points at an id
// nothing on the heading actually carries.
$m6NoNameFixture = '<div class="modal fade" id="plainModal"><div class="modal-header">'
    . '<h2 class="modal-title h6">New song</h2></div></div>';
if (a11yModalsMissingAccessibleName($m6NoNameFixture) !== [1]) {
    $selfTestFailures[] = 'a11yModalsMissingAccessibleName() did not flag a plain modal with no '
        . 'aria-label/aria-labelledby at all.';
}
$m6AriaLabelFixture = '<div class="modal fade" id="plainModal" aria-label="New song"><div class="modal-header">'
    . '<h2 class="modal-title h6">New song</h2></div></div>';
if (a11yModalsMissingAccessibleName($m6AriaLabelFixture) !== []) {
    $selfTestFailures[] = 'a11yModalsMissingAccessibleName() false-flagged a modal with a direct aria-label.';
}
$m6LabelledbyFixture = '<div class="modal fade" id="plainModal" aria-labelledby="plainModalLabel">'
    . '<div class="modal-header"><h2 class="modal-title h6" id="plainModalLabel">New song</h2></div></div>';
if (a11yModalsMissingAccessibleName($m6LabelledbyFixture) !== []) {
    $selfTestFailures[] = 'a11yModalsMissingAccessibleName() false-flagged a modal whose aria-labelledby '
        . 'correctly matches its modal-title id.';
}
$m6MismatchFixture = '<div class="modal fade" id="plainModal" aria-labelledby="wrongId">'
    . '<div class="modal-header"><h2 class="modal-title h6" id="plainModalLabel">New song</h2></div></div>';
if (a11yModalsMissingAccessibleName($m6MismatchFixture) !== [1]) {
    $selfTestFailures[] = 'a11yModalsMissingAccessibleName() did not flag a modal whose aria-labelledby does '
        . 'not match any real id on its heading.';
}

/* -----------------------------------------------------------------------
 * LIVE MUTATION PROOF (rule #34) — the fixture strings above prove the
 * scanners CAN fail, but only against hand-typed text. Take two REAL,
 * currently-clean spots in the actual tree, break each in memory ONLY
 * (never written back to disk), and confirm the scanner goes red on the
 * mutated copy while staying clean on the untouched original — proof the
 * guard can catch a regression in the real files, not just a fixture.
 * ------------------------------------------------------------------- */
$a11yLiveRoot = dirname(__DIR__, 2) . '/appWeb/public_html';

$languagesPath = $a11yLiveRoot . '/manage/languages.php';
if (!is_file($languagesPath)) {
    $selfTestFailures[] = 'live-mutation proof: manage/languages.php not found.';
} else {
    $languagesSrc = a11yStripComments((string) file_get_contents($languagesPath));
    $scopeAnchor = '<th scope="col" data-col-priority="primary">Tag</th>';
    if (!str_contains($languagesSrc, $scopeAnchor)) {
        $selfTestFailures[] = 'live-mutation proof: the expected scope="col" anchor is no longer present in '
            . 'manage/languages.php ("' . $scopeAnchor . '") — the file changed shape and this proof needs a new anchor.';
    } else {
        if (a11yThMissingScope($languagesSrc) !== []) {
            $selfTestFailures[] = 'a11yThMissingScope() flagged manage/languages.php AS-IS (unmutated) — false '
                . 'positive on real, already-correct source.';
        }
        $languagesMutated = preg_replace('~<th scope="col" data-col-priority="primary">Tag</th>~',
            '<th data-col-priority="primary">Tag</th>', $languagesSrc, 1);
        if (a11yThMissingScope($languagesMutated) === []) {
            $selfTestFailures[] = 'a11yThMissingScope() did NOT go red when a real scope="col" was deleted from '
                . 'manage/languages.php IN MEMORY — the guard cannot be trusted against the real tree.';
        }
    }
}

$groupsPath = $a11yLiveRoot . '/manage/groups.php';
if (!is_file($groupsPath)) {
    $selfTestFailures[] = 'live-mutation proof: manage/groups.php not found.';
} else {
    $groupsSrc = a11yStripComments((string) file_get_contents($groupsPath));
    // The "Add selected user" button — icon-only, named ONLY via aria-label
    // (no title fallback), so deleting the aria-label makes it genuinely
    // unnamed rather than merely losing a redundant second name.
    $labelAnchor = 'aria-label="Add selected user to this group"';
    if (!str_contains($groupsSrc, $labelAnchor)) {
        $selfTestFailures[] = 'live-mutation proof: the expected aria-label anchor is no longer present in '
            . 'manage/groups.php ("' . $labelAnchor . '") — the file changed shape and this proof needs a new anchor.';
    } else {
        if (a11yIconAccessibility($groupsSrc)['controls'] !== []) {
            $selfTestFailures[] = 'a11yIconAccessibility() flagged manage/groups.php AS-IS (unmutated) — false '
                . 'positive on real, already-correct source.';
        }
        $groupsMutated = str_replace($labelAnchor, '', $groupsSrc);
        if (a11yIconAccessibility($groupsMutated)['controls'] === []) {
            $selfTestFailures[] = 'a11yIconAccessibility() did NOT go red when a real aria-label was deleted from '
                . 'manage/groups.php\'s "Add selected user" button IN MEMORY — the guard cannot be trusted '
                . 'against the real tree.';
        }
    }
}

// M6/G2 (a11y audit 2026-08-30) — live mutation proof against the real,
// now-fixed manage/editor/editor2.php: confirm a11yModalsMissingAccessibleName()
// stays clean on the actual file, then confirm it goes RED when the
// aria-labelledby this M6 fix added to #v2-new-modal is stripped IN
// MEMORY ONLY — proving this guard would have caught the M6 regression.
$editor2Path = $a11yLiveRoot . '/manage/editor/editor2.php';
if (!is_file($editor2Path)) {
    $selfTestFailures[] = 'live-mutation proof: manage/editor/editor2.php not found.';
} else {
    $editor2Src = a11yStripComments((string) file_get_contents($editor2Path));
    $labelledbyAnchor = 'aria-labelledby="v2-new-modal-label"';
    if (!str_contains($editor2Src, $labelledbyAnchor)) {
        $selfTestFailures[] = 'live-mutation proof: the expected aria-labelledby anchor is no longer present in '
            . 'manage/editor/editor2.php ("' . $labelledbyAnchor . '") — the file changed shape and this proof '
            . 'needs a new anchor.';
    } else {
        if (a11yModalsMissingAccessibleName($editor2Src) !== []) {
            $selfTestFailures[] = 'a11yModalsMissingAccessibleName() flagged manage/editor/editor2.php AS-IS '
                . '(unmutated) — false positive on real, already-correct source.';
        }
        $editor2Mutated = str_replace($labelledbyAnchor, '', $editor2Src);
        if (a11yModalsMissingAccessibleName($editor2Mutated) === []) {
            $selfTestFailures[] = 'a11yModalsMissingAccessibleName() did NOT go red when #v2-new-modal\'s '
                . 'aria-labelledby was deleted from manage/editor/editor2.php IN MEMORY — the guard would not '
                . 'have caught the M6 regression.';
        }
    }
}

if ($selfTestFailures) {
    fwrite(STDERR, "FAIL: test-a11y-static-checks.php self-test (the scanners themselves are broken):\n\n");
    foreach ($selfTestFailures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    exit(1);
}

/* ---------------------------------------------------------------------------
 * REAL SCAN — derived from the tree, not a hand-typed list (rule #34).
 * ------------------------------------------------------------------------- */
$root   = dirname(__DIR__, 2);
$public = $root . '/appWeb/public_html';

$targets = [];
foreach ([$public . '/includes/pages', $public . '/includes/partials'] as $dir) {
    if (!is_dir($dir)) {
        continue;
    }
    foreach (glob($dir . '/*.php') ?: [] as $f) {
        $targets[] = $f;
    }
}
foreach (glob($public . '/manage/*.php') ?: [] as $f) {
    $targets[] = $f;
}
sort($targets);

if (!$targets) {
    fwrite(STDERR, "FAIL: no target .php files found under includes/pages, includes/partials or manage/ — "
        . "the tree moved and this guard's glob needs updating.\n");
    exit(1);
}

$failures = [];

/* --- Assertion 1: home.php's card-layout-handle is never aria-hidden --- */
$homePath = $public . '/includes/pages/home.php';
if (!is_file($homePath)) {
    $failures[] = "includes/pages/home.php not found — cannot check the card-layout-handle regression.";
} else {
    $homeSrc = a11yStripComments((string) file_get_contents($homePath));
    if (preg_match('~class="card-layout-handle"[^>]*aria-hidden="true"~', $homeSrc)
        || preg_match('~aria-hidden="true"[^>]*class="card-layout-handle"~', $homeSrc)
    ) {
        $failures[] = 'includes/pages/home.php: .card-layout-handle is aria-hidden="true" again. '
            . 'card-layout.js injects real, focusable "Move up"/"Move down"/"Hide this card" buttons '
            . 'into this element in edit mode — an aria-hidden ancestor strips them from the '
            . 'accessibility tree while they stay in the visual/keyboard tab order (WCAG 4.1.2). '
            . 'Only the decorative grip <i> icon should carry aria-hidden, not the strip itself.';
    }
}

/* --- Assertions 2 & 3: duplicate ids + missing alt, across every target --- */
foreach ($targets as $file) {
    $rel = substr($file, strlen($public) + 1);
    $src = a11yStripComments((string) file_get_contents($file));

    $dups = a11yDuplicateIds($src);
    foreach ($dups as $id => $count) {
        $failures[] = sprintf(
            '%s: id="%s" is declared %d times — invalid HTML5, and breaks any aria-labelledby/for/'
            . 'aria-controls reference or #%s deep link that expects a single element.',
            $rel,
            $id,
            $count,
            $id
        );
    }

    $missingAltLines = a11yImagesMissingAlt($src);
    foreach ($missingAltLines as $line) {
        $failures[] = sprintf(
            '%s:%d — <img> with no alt attribute. Use a meaningful alt="…", or alt="" + aria-hidden="true" '
            . 'if the image is purely decorative — an absent alt makes a screen reader announce the file path instead.',
            $rel,
            $line
        );
    }

    /* --- Assertion 4 (M1, a11y audit 2026-08-28): a collapsible heading
       never regrows role="button" with no tabindex — the exact shape that
       made song.php's Translations/Related-Songs toggles keyboard-
       unreachable. Runs over the same $targets every other check here
       does (pages/partials/manage) since no legitimate use of this
       pattern exists anywhere in the tree today (a real clickable heading
       uses a real <button> INSIDE the heading, song.php's own fix). */
    foreach (a11yHeadingRoleButtonWithoutTabindex($src) as $line) {
        $failures[] = sprintf(
            '%s:%d — a heading (<h1>-<h6>) carries role="button" with no tabindex, so it is never keyboard-'
            . 'focusable and Bootstrap\'s collapse data-API (click-only) can never be triggered from a keyboard '
            . '(WCAG 2.1.1/4.1.2). Put a real <button> INSIDE the heading instead (song.php\'s Translations/'
            . 'Related-Songs toggles are the reference pattern) rather than adding tabindex here.',
            $rel,
            $line
        );
    }
}

/* ---------------------------------------------------------------------------
 * Assertion 5 (M7, a11y audit 2026-08-28) — the admin skip link + <main
 * id="main-content"> landmark, and its matching </main> close, stay wired
 * into the two shared partials every /manage/*.php page requires. Losing
 * either half here silently removes the skip link (or its target) from
 * ALL ~39+ admin pages at once, which is exactly the shape M7 found and
 * exactly why the fix lives in the shared chrome rather than per-page.
 * ------------------------------------------------------------------------- */
$adminNavPath    = $public . '/manage/includes/admin-nav.php';
$adminFooterPath = $public . '/manage/includes/admin-footer.php';
if (!is_file($adminNavPath) || !is_file($adminFooterPath)) {
    $failures[] = 'manage/includes/admin-nav.php or admin-footer.php not found — cannot check the admin '
        . 'skip-link + <main> landmark (M7).';
} else {
    $adminNavSrc = a11yStripComments((string) file_get_contents($adminNavPath));
    if (!a11yHasSkipLinkAndMainLandmark($adminNavSrc)) {
        $failures[] = 'manage/includes/admin-nav.php no longer emits BOTH the "Skip to main content" link '
            . '(href="#main-content") AND the <main id="main-content"> landmark it targets — this shared '
            . 'partial is the ONE place that fixes the skip link on every /manage/*.php page at once (WCAG '
            . '2.4.1); losing either half here silently removes it everywhere.';
    }
    $adminFooterSrc = a11yStripComments((string) file_get_contents($adminFooterPath));
    if (!str_contains($adminFooterSrc, '</main>')) {
        $failures[] = 'manage/includes/admin-footer.php no longer closes </main> — admin-nav.php opens the '
            . 'landmark, so every page using both partials would be left with an unclosed <main>.';
    }
}

/* ---------------------------------------------------------------------------
 * Assertion 6 (M8, a11y audit 2026-08-28) — no bare <i>/<span> carries
 * aria-label without role="img" (or another naming-capable role) across the
 * PUBLIC surface. Deliberately scoped to includes/pages, includes/partials,
 * js/modules and js/utils (all glob-derived, rule #34) — NOT manage/*.php,
 * which is a separate, larger, tracked sweep (this guard would otherwise
 * fail on pre-existing admin-only instances this pass never touched).
 * ------------------------------------------------------------------------- */
$publicScanTargets = [];
foreach ([
    $public . '/includes/pages',
    $public . '/includes/partials',
    $public . '/js/modules',
    $public . '/js/utils',
] as $dir) {
    if (!is_dir($dir)) { continue; }
    foreach (array_merge(glob($dir . '/*.php') ?: [], glob($dir . '/*.js') ?: []) as $f) {
        $publicScanTargets[] = $f;
    }
}
sort($publicScanTargets);

if (!$publicScanTargets) {
    $failures[] = 'no public-surface .php/.js files found under includes/pages, includes/partials, '
        . 'js/modules or js/utils — the tree moved and this guard\'s glob needs updating.';
}

foreach ($publicScanTargets as $file) {
    $rel = substr($file, strlen($public) + 1);
    $src = a11yStripComments((string) file_get_contents($file));
    foreach (a11yBareGenericAriaLabel($src) as $line) {
        $failures[] = sprintf(
            '%s:%d — a bare <i>/<span> carries aria-label with no role attribute. ARIA 1.2 prohibits naming a '
            . 'generic element this way (screen readers largely ignore it) — add role="img" (the codebase\'s '
            . 'own correct model: song.php\'s verified badge).',
            $rel,
            $line
        );
    }
}

/* ---------------------------------------------------------------------------
 * Assertion 7 (M2 + M8 remainder, #1990) — every <th> across the admin AND
 * public tree carries scope=, and every bi-icon / icon-only button-or-link
 * across that SAME wider tree is perceivable/nameable. Deliberately a
 * BROADER glob than $targets above (this adds manage/includes/*.php,
 * manage/includes/partials/*.php and manage/editor/*.php, since #1990's own
 * mechanical sweep reached those directories too via `find manage -name
 * "*.php"` — narrower than this glob would silently stop enforcing the
 * result there) — includes/pages and includes/partials are shared with
 * $targets so this "locks" the public help table (manage/help.php's admin
 * help doc is already inside $targets via manage/*.php) rather than
 * re-scanning it under a different rule.
 * ------------------------------------------------------------------------- */
$m2m8Targets = [];
foreach ([
    $public . '/manage',
    $public . '/manage/includes',
    $public . '/manage/includes/partials',
    $public . '/manage/editor',
    $public . '/includes/pages',
    $public . '/includes/partials',
] as $dir) {
    if (!is_dir($dir)) { continue; }
    foreach (glob($dir . '/*.php') ?: [] as $f) {
        $m2m8Targets[] = $f;
    }
}
$m2m8Targets = array_values(array_unique($m2m8Targets));
sort($m2m8Targets);

if (!$m2m8Targets) {
    $failures[] = 'no target .php files found for the M2/M8 <th>-scope + icon-accessibility scan — the tree '
        . 'moved and this guard\'s glob needs updating.';
}

foreach ($m2m8Targets as $file) {
    $rel = substr($file, strlen($public) + 1);
    $src = a11yStripComments((string) file_get_contents($file));

    foreach (a11yThMissingScope($src) as $line) {
        $failures[] = sprintf(
            '%s:%d — <th> with no scope="…" attribute (M2, #1990). Add scope="col" for a column header or '
            . 'scope="row" for the first cell of a data row — a screen reader cannot otherwise associate this '
            . 'header with the cells it labels.',
            $rel,
            $line
        );
    }

    $iconIssues = a11yIconAccessibility($src);
    foreach ($iconIssues['icons'] as $line) {
        $failures[] = sprintf(
            '%s:%d — a Bootstrap-Icons <i class="bi …"> is neither aria-hidden="true" nor named via '
            . 'role="img"+aria-label (M8, #1990). A screen reader otherwise tries to read the icon glyph itself '
            . 'out loud — add aria-hidden="true" if it is purely decorative, or role="img" + a real aria-label '
            . 'if it carries meaning on its own (musicians.php\'s "Has curator notes" badge is the reference).',
            $rel,
            $line
        );
    }
    foreach ($iconIssues['controls'] as $line) {
        $failures[] = sprintf(
            '%s:%d — a <button>/<a> whose entire content is one or more bi-icons (no visible text) carries no '
            . 'accessible name (M8, #1990). Add aria-label/title on the opening tag, or a '
            . '<span class="visually-hidden">…</span> inside — otherwise a screen reader announces only '
            . '"button"/"link" with no indication of what it does.',
            $rel,
            $line
        );
    }
}

/* ---------------------------------------------------------------------------
 * Assertion 8 (wizard-suite a11y audit F4/F5, 2026-08-29) — every guided-
 * wizard modal (tree-derived via the data-wiz-progress fingerprint,
 * a11yFindWizardModals() — never a hand-typed modal-id list, so a future
 * 6th wizard is covered automatically) carries aria-labelledby matching a
 * real id on its modal-title heading, and none still carries the
 * white-on-white btn-close-white regression #953/#955 already fixed
 * everywhere else. Scoped to the SAME $m2m8Targets tree this file already
 * walks for M2/M8 (manage/, manage/includes, manage/editor, …).
 * ------------------------------------------------------------------------- */
foreach ($m2m8Targets as $file) {
    $rel = substr($file, strlen($public) + 1);
    $src = a11yStripComments((string) file_get_contents($file));

    foreach (a11yWizardModalsMissingLabelledby($src) as $line) {
        $failures[] = sprintf(
            '%s:%d — a guided-wizard modal (data-wiz-progress present) has no aria-labelledby, or its '
            . 'aria-labelledby value does not match a real id="…" on a .modal-title heading (a11y audit F4). '
            . 'A screen reader entering this dialog announces only "dialog" with no name.',
            $rel,
            $line
        );
    }
    foreach (a11yWizardModalsBtnCloseWhite($src) as $line) {
        $failures[] = sprintf(
            '%s:%d — a guided-wizard modal still uses btn-close-white instead of the theme-aware plain '
            . 'btn-close (a11y audit F5) — white-on-white in light theme (#953/#955 regression).',
            $rel,
            $line
        );
    }
}

/* ---------------------------------------------------------------------------
 * Assertion 9 (M6, a11y audit 2026-08-30, guard G2) — EVERY modal (not
 * just guided-wizard ones) across the SAME $m2m8Targets tree carries an
 * accessible name. Widens Assertion 8's wizard-only check to
 * a11yFindAllModalTags()'s full set — caught editor2.php's four plain
 * modals (#v2-new-modal, #v2-bulk-move-modal, #v2-bulk-export-modal,
 * #v2-bulk-result-modal) before the M6 fix; $m2m8Targets already includes
 * manage/editor (see its own comment above), so no NEW target-list
 * widening was needed here — only the wider modal-finder function was.
 * ------------------------------------------------------------------------- */
foreach ($m2m8Targets as $file) {
    $rel = substr($file, strlen($public) + 1);
    $src = a11yStripComments((string) file_get_contents($file));

    foreach (a11yModalsMissingAccessibleName($src) as $line) {
        $failures[] = sprintf(
            '%s:%d — a modal (class="modal fade") has no accessible name: no aria-label on the modal itself, '
            . 'and no aria-labelledby resolving to a real id="…" on its .modal-title heading (M6, a11y audit '
            . '2026-08-30). A screen reader entering this dialog announces only "dialog".',
            $rel,
            $line
        );
    }
}

/* ------------------------------------------------------------------------- */
if ($failures) {
    fwrite(STDERR, "FAIL: static WCAG 2.1/2.2 AA checks (#1150/#1151, a11y audit 2026-08-28 M1/M7/M8, #1990 M2/M8, "
        . "2026-08-30 M6):\n\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    exit(1);
}

printf(
    "PASS: %d template(s) scanned under includes/pages, includes/partials and manage/ (ids/alt/heading-"
    . "role-button), %d public-surface file(s) scanned for bare aria-label (M8), %d file(s) scanned for <th> "
    . "scope + icon accessibility (M2/M8, #1990), guided-wizard modal naming (F4/F5) AND every other modal's "
    . "accessible name (M6, 2026-08-30), admin skip-link + <main> landmark wired (M7), home.php's "
    . "card-layout-handle stays perceivable.\n",
    count($targets),
    count($publicScanTargets),
    count($m2m8Targets)
);
exit(0);
