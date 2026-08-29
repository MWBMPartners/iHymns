<?php

declare(strict_types=1);

/**
 * iHymns — Admin form-control accessible-name guard (WCAG 2.1 §3.3.2/4.1.2, S3)
 *
 * ELI5: an admin `<label>` that doesn't say WHICH input it's for is a
 * label a screen reader can't use — the reader lands on the field and
 * announces "edit, blank" instead of the field's name. This scan walks
 * every top-level `/manage/*.php` page (plus the legacy `/manage/editor/`
 * pages) looking for exactly that shape and fails the build if it finds
 * one that isn't accounted for.
 *
 * Detail: the 2026-08-28 accessibility audit found 222 orphan `<label>`s
 * across 25 admin pages — `<label class="form-label small">Title</label>`
 * sitting next to `<input name="title">` with neither a `for=`/`id=` pair
 * nor a wrapping relationship, so the association a screen reader relies
 * on (WebAIM/WCAG techniques H44/G162 — see
 * https://www.w3.org/WAI/WCAG21/Techniques/html/H44 and
 * https://webaim.org/techniques/forms/controls) simply doesn't exist.
 * Every one of those 222 was paired up (rule #34 discipline — derive the
 * check from the tree, then prove it can go red).
 *
 * A `<label>` is treated as COMPLIANT if any of these hold:
 *   (a) it carries a `for="…"` attribute (the standard association);
 *   (b) it directly WRAPS an `<input>`/`<select>`/`<textarea>` (the
 *       implicit-association form — no `for=` needed, WCAG H44 alt);
 *   (c) it carries its own `id="…"` that is referenced by an
 *       `aria-labelledby="…"` elsewhere in the SAME file — the
 *       deliberate "group caption" shape this pass introduced for a
 *       caption sitting over several already-individually-labelled
 *       controls (a checkbox group, a JS-populated chip list, a
 *       repeating sub-form) rather than one single field. This is
 *       checked for REAL — an `id=` with no matching `aria-labelledby`
 *       anywhere in the file does NOT pass, so a copy-pasted `id=` with
 *       the wiring forgotten still fails loudly.
 *
 * Scope + limitation (stated per the task's own instruction: scope a
 * guard to what it can reliably check, and say so): this walks the
 * STATIC markup of `manage/*.php` and `manage/editor/*.php` only. It
 * does NOT parse JS string-built markup inside `<script>` blocks or
 * external `.js` modules (e.g. a template string in
 * `structure-tab.js` or `external-links-editor.js` that builds a
 * `<label>` at runtime) — `<script>…</script>` regions are stripped
 * before scanning for exactly this reason, so a label-shaped string
 * inside one never triggers a false positive OR a false negative here.
 * That JS-built class of control is the "aria-label on the control
 * itself" fix, not a `<label>`/`for` pairing, and needs its own
 * lint pass over the `.js` tree if ever automated.
 *
 * Mutation-proof (rule #34): reintroduce one orphan
 * `<label class="form-label small">X</label>` next to an unlabelled
 * `<input>` in any scanned file and this goes red; the fix is always
 * either adding the matching `for=`/`id=` pair, or (for a genuine
 * group caption) an `id=` + a real `aria-labelledby=` reference.
 *
 *   php tests/php/test-admin-form-labels.php
 *
 * Exit status 0 = clean, 1 = at least one unaccounted-for orphan label.
 */

/**
 * Blank out HTML comments, PHP comments, and <script>…</script> bodies
 * while preserving line count, so a reported line number always points
 * at the real source line (mirrors test-fragment-inline-scripts.php's
 * stripCommentsPreservingLines(), extended to also blank <script> bodies
 * since this guard's false-positive risk is JS-string label lookalikes,
 * not comment prose).
 *
 * @param string $src Raw file contents.
 * @return string Same length in lines; comments + script bodies blanked.
 */
function adminLabelsStripNoise(string $src): string
{
    $blank = static function (array $m): string {
        return str_repeat("\n", substr_count($m[0], "\n"));
    };

    // HTML comments.
    $src = preg_replace_callback('/<!--.*?-->/s', $blank, $src);
    // PHP /* ... */ block comments.
    $src = preg_replace_callback('~/\*.*?\*/~s', $blank, $src);
    // <script>...</script> bodies — JS-built label-shaped strings live
    // here and are explicitly out of this guard's static-markup scope
    // (see file doc-block).
    $src = preg_replace_callback('~<script\b[^>]*>.*?</script>~is', $blank, $src);

    return $src;
}

/**
 * @return array<int, string> one "file:line  <label …>" entry per orphan.
 */
function adminLabelsScanFile(string $path): array
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $src = adminLabelsStripNoise($raw);

    // Collect every id="…" that is referenced by an aria-labelledby=
    // ANYWHERE in the file (the group-caption escape hatch, checked for
    // real rather than assumed — see doc-block point (c)).
    //
    // Store the RAW attribute value as one token first — a PHP-templated
    // dynamic id (e.g. one built from an "applies-to-label-" prefix plus
    // an interpolated short-echo expression) is a single logical id even
    // though the interpolated PHP tag itself contains spaces, which
    // would corrupt a naive whitespace split.
    // ALSO store the whitespace-split tokens, for the genuine static
    // multi-id case (aria-labelledby="a b") a plain-string id= might
    // match one of. Skip the split when the value contains a PHP open
    // tag, since splitting THAT on whitespace produces bogus fragments
    // (see the false positive this guarded against on first run: a
    // dynamic id's own PHP source was chopped mid-expression and never
    // matched the id= attribute's equally-unsplit full value).
    $labelledByIds = [];
    if (preg_match_all('/aria-labelledby\s*=\s*"([^"]*)"/i', $src, $albMatches)) {
        foreach ($albMatches[1] as $tokenList) {
            $tokenList = trim($tokenList);
            if ($tokenList === '') {
                continue;
            }
            $labelledByIds[$tokenList] = true;
            if (strpos($tokenList, '<?') === false) {
                foreach (preg_split('/\s+/', $tokenList) as $tok) {
                    if ($tok !== '') {
                        $labelledByIds[$tok] = true;
                    }
                }
            }
        }
    }

    $violations = [];
    $base = basename($path);

    // Attribute-boundary note: a plain `[^>]*` for the tag's attribute
    // span breaks the moment an attribute VALUE itself contains a
    // literal `>` — which a PHP short-echo tag inside an id=/for=
    // attribute does constantly in this codebase (e.g. an id built as
    // "prefix-" + an interpolated expression). `(?:"[^"]*"|'[^']*'|[^>])*`
    // treats a whole quoted span as one atomic unit FIRST, so a `>`
    // living inside quotes never prematurely closes the tag match.
    if (!preg_match_all(
        '/<label\b((?:"[^"]*"|\'[^\']*\'|[^>])*)>(.*?)<\/label>/is',
        $src,
        $matches,
        PREG_OFFSET_CAPTURE
    )) {
        return [];
    }

    foreach ($matches[0] as $i => [$fullText, $offset]) {
        $attrs = $matches[1][$i][0];
        $inner = $matches[2][$i][0];

        // (a) has for= — compliant.
        if (preg_match('/\bfor\s*=/i', $attrs) === 1) {
            continue;
        }
        // (b) wraps a real control — compliant.
        if (preg_match('/<(input|select|textarea)\b/is', $inner) === 1) {
            continue;
        }
        // (c) carries an id= that some aria-labelledby= in this file
        // actually references — compliant (verified group caption).
        if (preg_match('/\bid\s*=\s*"([^"]*)"/i', $attrs, $idm) === 1
            && isset($labelledByIds[$idm[1]])
        ) {
            continue;
        }

        $lineNo   = substr_count($src, "\n", 0, $offset) + 1;
        $snippet  = trim(preg_replace('/\s+/', ' ', mb_substr($fullText, 0, 100)));
        $violations[] = sprintf('%s:%d  %s', $base, $lineNo, $snippet);
    }

    return $violations;
}

$repoRoot = dirname(__DIR__, 2);
$scanDirs = [
    $repoRoot . '/appWeb/public_html/manage',
    $repoRoot . '/appWeb/public_html/manage/editor',
];

$files = [];
foreach ($scanDirs as $dir) {
    if (!is_dir($dir)) {
        fwrite(STDERR, "FATAL: $dir not found\n");
        exit(1);
    }
    // Non-recursive per directory (mirrors test-fragment-inline-scripts.php):
    // manage/*.php is a flat list of pages; manage/includes/* (the shared
    // partials another agent owns) is deliberately NOT walked — this guard
    // is scoped to page bodies, not the shared chrome.
    foreach (glob($dir . '/*.php') ?: [] as $f) {
        $files[] = $f;
    }
}
sort($files);

if (!$files) {
    fwrite(STDERR, "No manage/*.php files found — that is almost certainly wrong.\n");
    exit(1);
}

$allViolations = [];
foreach ($files as $file) {
    foreach (adminLabelsScanFile($file) as $v) {
        $allViolations[] = $v;
    }
}

if ($allViolations) {
    fwrite(STDERR, "FAIL: orphan admin <label>(s) with no accessible association (WCAG 3.3.2/4.1.2):\n\n");
    foreach ($allViolations as $v) {
        fwrite(STDERR, "  $v\n");
    }
    fwrite(STDERR, "\nEach line above is a <label> with no for=, no wrapped control, and no id=\n");
    fwrite(STDERR, "referenced by an aria-labelledby= elsewhere in the file. Fix by adding a\n");
    fwrite(STDERR, "matching for=/id= pair to the control it labels, or — for a caption over a\n");
    fwrite(STDERR, "group of already-labelled controls — give the label an id= and reference it\n");
    fwrite(STDERR, "via role=\"group\" aria-labelledby=\"<that id>\" on the group's wrapper.\n");
    exit(1);
}

echo 'PASS: no orphan admin <label>s (' . count($files) . " files scanned).\n";
exit(0);
