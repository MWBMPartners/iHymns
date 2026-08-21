<?php

declare(strict_types=1);

/**
 * iHymns — server PDF non-Latin AUTOFONT guard (#1908 Wave 1 Commit 7)
 * ============================================================================
 *
 * ELI5: this makes sure a server-rendered PDF (`manage/print-pdf.php` →
 * `includes/pdf_renderer.php`) can actually SHOW a CJK/Arabic/Hebrew/Thai/…
 * song instead of leaving blank space where the lyrics should be, that the
 * flags which fix that stay ON in the mPDF constructor, and that the
 * unrelated `@page`-strip workaround they interact with (the 104-111-page
 * runaway fix, see `pdf_renderer.php` ~:196-221) never quietly disappears
 * alongside them.
 *
 * DETAIL — TWO PARTS, MIRRORING `test-print-pdf-batch.php`'s SHAPE
 * (comment-stripped static source-scan, ALWAYS runs; a direct behavioural
 * call into the real `ihymnsPdfRender()`, SKIPPED with a clear message when
 * the mPDF engine isn't vendored on this checkout — same dormant-503
 * discipline `qr.php`/`pdf_renderer.php` already use):
 *
 *   PART A (static, comment-stripped via the real tokenizer — never a
 *   slash-star regex, `test-print-one-renderer.php`'s own precedent):
 *     A1. `includes/pdf_renderer.php`'s `new \Mpdf\Mpdf([ … ])` constructor
 *         array literal (balanced-bracket extracted, not just "somewhere in
 *         the file" — rule #34's `test-editor-api2-contract.php` lesson:
 *         a fixed-width scan window is a class of bug on its own) contains
 *         BOTH `'autoScriptToLang' => true` and `'autoLangToFont' => true`.
 *     A2. The `@page { … }`-strip `preg_replace('/@page…` (the P3 page-
 *         metrics-runaway workaround, ~:221) STILL EXISTS — this Commit-7
 *         change touches font resolution, which is exactly what that strip
 *         guards against interacting badly with `@page`; a future edit that
 *         silently drops the strip while leaving the autofont flags in would
 *         reopen the 104-111-page bug with no static signal anywhere else.
 *
 *   PART B (behavioural, mPDF engine required — SKIP with a message and
 *   exit 0 otherwise, mirroring `qr.php`'s dormant-503 discipline applied to
 *   a CI guard instead of an HTTP response):
 *     B1. `ihymnsPdfRender()` called DIRECTLY with ONE document whose body
 *         mixes CJK (`耶稣爱我`) + Hebrew (`עִבְרִית`) + Arabic (`العربية`)
 *         + Latin chrome renders PDF bytes starting `%PDF-`.
 *     B2. That SAME render produces EXACTLY 1 PAGE — the metrics-runaway
 *         canary (§0.3 fact 5 / D8 of the plan): if turning autofont ON ever
 *         reopens the `@page` interaction bug, THIS assertion goes red.
 *     B3. FONT-EMBED EVIDENCE: the mixed-script PDF's raw bytes reference at
 *         least one `/BaseFont` NOT prefixed `DejaVu` (mPDF subset-tags
 *         embedded fonts as `<6-char-tag>+<FontName>`, e.g. this session's
 *         REAL, OBSERVED, PINNED output against the vendored engine —
 *         printed in full below rather than guessed:
 *             MPDFAA+DejaVuSerifCondensed   (the Latin default body font)
 *             MPDFAA+Sun-ExtA               (CJK  — 耶稣爱我)
 *             MPDFAA+TaameyDavidCLM-Medium  (Hebrew — עִבְרִית)
 *             MPDFAA+XBRiyaz                (Arabic — العربية; note mPDF
 *                                             drops the space from the
 *                                             vendored "XB Riyaz.ttf" name)
 *         The 6-char subset-tag PREFIX is an mPDF implementation detail this
 *         test does NOT pin (a different font-load ORDER on a different
 *         checkout/PHP build could reassign it); what's pinned is the FONT
 *         NAME after the `+`, which is derived from the vendored `.ttf`
 *         filenames themselves and is stable.
 *     B4. LATIN-ONLY CONTROL: the SAME call shape, but with a body that
 *         contains no non-Latin script at all, embeds `/BaseFont`s that are
 *         ALL `DejaVu`-prefixed — proving B3's non-DejaVu evidence is
 *         actually caused by the non-Latin CONTENT, not by every render
 *         incidentally picking up extra fonts regardless of body text. This
 *         session's REAL, OBSERVED, PINNED control output:
 *             MPDFAA+DejaVuSerifCondensed   (only)
 *
 * MUTATION-PROVEN (rule #34) — this session: with the two `autoScriptToLang`/
 * `autoLangToFont` constructor lines REMOVED, the SAME mixed-script render
 * (B1/B2 still pass — `%PDF-`, still exactly 1 page) embeds `/BaseFont`s
 * that are ALL `DejaVu`-prefixed (`MPDFAA+DejaVuSerifCondensed` only,
 * IDENTICAL to the Latin-only control) — i.e. B3 goes RED with the flags
 * removed, proving this assertion actually detects the pre-fix state. The
 * fix was then restored and re-verified byte-identical (`git diff` clean)
 * and green. See the #1908 Commit 7 PR/commit message for the transcript.
 *
 *   php tests/php/test-pdf-nonlatin-fonts.php
 *
 * Exit status 0 = all pass (Part B may SKIP), 1 = at least one failure.
 *
 * @see .claude/unicode-nonlatin-1908-plan.md §7           the locked spec this test implements EXACTLY
 * @see appWeb/public_html/includes/pdf_renderer.php        the ONE engine wrapper (Part A/B both target this file)
 * @see tests/php/test-print-pdf-batch.php                  the Part A / Part B shape + page-count technique this mirrors
 * @see tests/php/test-print-one-renderer.php                the comment-stripping + balanced-brace-extraction pattern this mirrors
 * @link https://mpdf.github.io/reference/mpdf-functions/construct.html      mPDF constructor options (autoScriptToLang / autoLangToFont)
 * @link https://mpdf.github.io/multi-language-support/language-support.html mPDF's per-script automatic font selection
 */

$repoRoot        = dirname(__DIR__, 2);
$publicRoot      = $repoRoot . '/appWeb/public_html';
$pdfRendererPath = $publicRoot . '/includes/pdf_renderer.php';

$failures = 0;
$passed   = 0;
function pnf(bool $cond, string $label): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "PASS: $label\n"; }
    else       { $failures++; fwrite(STDERR, "FAIL: $label\n"); }
}

/** Strip comments via the real tokenizer (never a slash-star regex — see
 *  test-print-one-renderer.php's doc-block for the false-positive class a
 *  naive regex hits; identical shape to that file's + the batch test's own
 *  stripper). */
function pnfStripComments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                $out .= preg_replace('/[^\n]/', ' ', $tok[1]); // keep line numbers stable
                continue;
            }
            $out .= $tok[1];
            continue;
        }
        $out .= $tok;
    }
    return $out;
}

/**
 * Balanced-BRACKET extraction of the FIRST `[ … ]` block whose opening looks
 * like `$needleWithBracket` (`$needleWithBracket` MUST already include
 * everything up to and including the opening `[`) — the square-bracket
 * sibling of `test-print-pdf-batch.php`'s `ppbExtractBracedBlock()` (that one
 * matches `{ … }`; the mPDF constructor's array literal is `[ … ]`).
 * Deliberately NOT a fixed-width scan window (rule #34's own worked example:
 * `test-editor-api2-contract.php` needed its window widened from 120 to 300
 * chars against real source and STILL reported a confident, incomplete
 * green until fixed — a proper balanced-delimiter scan has no such width to
 * get wrong). Returns null when `$needleWithBracket` isn't found or the
 * brackets never close.
 */
function pnfExtractBracketedBlock(string $src, string $needleWithBracket): ?string
{
    $pos = strpos($src, $needleWithBracket);
    if ($pos === false) {
        return null;
    }
    $bracketPos = $pos + strlen($needleWithBracket) - 1; // the opening '[' itself
    $depth = 0;
    $len = strlen($src);
    for ($i = $bracketPos; $i < $len; $i++) {
        if ($src[$i] === '[') { $depth++; }
        elseif ($src[$i] === ']') {
            $depth--;
            if ($depth === 0) { return substr($src, $bracketPos + 1, $i - $bracketPos - 1); }
        }
    }
    return null;
}

pnf(is_file($pdfRendererPath), 'A0 includes/pdf_renderer.php exists');
if ($failures > 0) {
    fwrite(STDERR, "\nFATAL: pdf_renderer.php is missing — cannot continue.\n");
    exit(1);
}

$pdfRendererRaw = (string)file_get_contents($pdfRendererPath);
$pdfRendererSrc = pnfStripComments($pdfRendererRaw);

/* =============================================================================
 * PART A — static source scan
 * ============================================================================= */

echo "Part A — static source scan (includes/pdf_renderer.php)\n";

/* A1 — the mPDF constructor array literally contains both autofont flags. */
$ctorArray = pnfExtractBracketedBlock($pdfRendererSrc, 'new \Mpdf\Mpdf([');
pnf($ctorArray !== null, 'A1.0 extracted the new \Mpdf\Mpdf([ … ]) constructor array literal');
if ($ctorArray !== null) {
    pnf(
        (bool)preg_match('/[\'"]autoScriptToLang[\'"]\s*=>\s*true/', $ctorArray),
        "A1.1 the constructor array contains 'autoScriptToLang' => true"
    );
    pnf(
        (bool)preg_match('/[\'"]autoLangToFont[\'"]\s*=>\s*true/', $ctorArray),
        "A1.2 the constructor array contains 'autoLangToFont' => true"
    );
}

/* A2 — the @page-strip workaround (the P3 page-metrics-runaway fix) still
   exists — Commit 7 changes font resolution, which is exactly the other half
   of the interaction that bug needed, so this strip staying in place is
   load-bearing for THIS change too, not just its own P3 history. */
pnf(
    str_contains($pdfRendererSrc, "preg_replace('/@page"),
    'A2 the @page{...}-strip preg_replace (~:221, the page-metrics-runaway workaround) still exists'
);

/* =============================================================================
 * PART B — behavioural: real ihymnsPdfRender() calls (mPDF engine required)
 * ============================================================================= */

echo "\nPart B — behavioural: real ihymnsPdfRender() calls with non-Latin scripts\n";

require_once $pdfRendererPath;

if (!ihymnsPdfEngineAvailable()) {
    echo "  SKIP  mPDF engine not vendored on this checkout (appWeb/private_html/lib/pdf/vendor/ absent)"
        . " — Part B not run. Not a failure, not a pass.\n";
} else {
    /** Count `/Type /Page` OBJECTS in raw PDF bytes, excluding `/Type /Pages`
     *  (the parent page-tree node) — the SAME technique
     *  `test-print-pdf-batch.php`'s `ppbPdfPageCount()` uses (verified there
     *  against 1-, 3-, 4- and 50-document batches); this is the
     *  page-metrics-runaway canary for THIS commit. */
    function pnfPdfPageCount(string $bytes): int
    {
        return preg_match_all('/\/Type\s*\/Page(?!s)\b/', $bytes, $m);
    }

    /** Extract the unique set of embedded `/BaseFont` NAMES, with mPDF's
     *  6-character subset-tag PREFIX (e.g. `MPDFAA+`) stripped off — that
     *  prefix is an mPDF implementation detail (assigned by internal
     *  font-load order) this test deliberately does NOT pin; the FONT NAME
     *  itself (derived from the vendored .ttf filenames, e.g. `Sun-ExtA`,
     *  `TaameyDavidCLM-Medium`) is what's stable and what's asserted on. */
    function pnfBaseFontNames(string $bytes): array
    {
        preg_match_all('/\/BaseFont\s*\/(?:[A-Za-z0-9]{6}\+)?([A-Za-z0-9\-]+)/', $bytes, $m);
        return array_values(array_unique($m[1]));
    }

    /** True iff EVERY given font name is DejaVu-prefixed (the "nothing but
     *  the Latin fallback embedded" shape — both the pre-fix mutation and
     *  the Latin-only control produce this). */
    function pnfAllDejaVu(array $names): bool
    {
        foreach ($names as $n) {
            if (stripos($n, 'DejaVu') !== 0) {
                return false;
            }
        }
        return $names !== [];
    }

    /* B1/B2/B3 — the mixed-script document: CJK + Hebrew + Arabic + Latin
       chrome, run through the SAME body path `manage/print-pdf.php` uses
       (ihymnsPdfRender() with a real per-document meta array, mirroring
       test-print-pdf-batch.php's own single-document call shape). */
    $mixedDocs = [[
        'bodyHtml' => '<div class="print-title">Amazing Grace</div>'
            . '<div class="lyric-line">耶稣爱我</div>'
            . '<div class="lyric-line">עִבְרִית</div>'
            . '<div class="lyric-line" dir="rtl">العربية</div>',
        'meta' => ['title' => 'Non-Latin font smoke test', 'songId' => 'ZZ-1908M'],
    ]];
    $mixedBytes = ihymnsPdfRender($mixedDocs, '', [], ['meta' => $mixedDocs[0]['meta']]);

    pnf(is_string($mixedBytes) && str_starts_with($mixedBytes, '%PDF-'), 'B1 mixed-script render returns PDF bytes starting %PDF-');

    if (is_string($mixedBytes)) {
        $mixedPageCount = pnfPdfPageCount($mixedBytes);
        pnf(
            $mixedPageCount === 1,
            "B2 the mixed-script render is EXACTLY 1 page — the @page/font-substitution metrics-runaway canary (found {$mixedPageCount})"
        );

        $mixedFonts = pnfBaseFontNames($mixedBytes);
        echo '  INFO  mixed-script /BaseFont names observed: ' . implode(', ', $mixedFonts) . "\n";
        pnf(
            !pnfAllDejaVu($mixedFonts),
            'B3 the mixed-script PDF embeds at least one NON-DejaVu /BaseFont (CJK/RTL glyphs actually resolved, not blank)'
        );
    }

    /* B4 — Latin-only CONTROL, same call shape, no non-Latin script at all:
       proves B3's non-DejaVu evidence is caused by the non-Latin CONTENT,
       not incidental to every render. */
    $latinDocs = [[
        'bodyHtml' => '<div class="print-title">Amazing Grace</div>'
            . '<div class="lyric-line">Amazing grace, how sweet the sound</div>',
        'meta' => ['title' => 'Latin-only control', 'songId' => 'ZZ-1908L'],
    ]];
    $latinBytes = ihymnsPdfRender($latinDocs, '', [], ['meta' => $latinDocs[0]['meta']]);

    pnf(is_string($latinBytes) && str_starts_with($latinBytes, '%PDF-'), 'B4.0 Latin-only control render returns PDF bytes starting %PDF-');

    if (is_string($latinBytes)) {
        $latinPageCount = pnfPdfPageCount($latinBytes);
        pnf($latinPageCount === 1, "B4.1 …and is EXACTLY 1 page (found {$latinPageCount})");

        $latinFonts = pnfBaseFontNames($latinBytes);
        echo '  INFO  Latin-only control /BaseFont names observed: ' . implode(', ', $latinFonts) . "\n";
        pnf(
            pnfAllDejaVu($latinFonts),
            'B4.2 the Latin-only control PDF embeds ONLY DejaVu /BaseFont(s) — no non-Latin content, no non-DejaVu font pulled in'
        );
    }
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$passed} passed, {$failures} failed\n");
    exit(1);
}
echo "\n{$passed} passed, 0 failed.\n";
exit(0);
