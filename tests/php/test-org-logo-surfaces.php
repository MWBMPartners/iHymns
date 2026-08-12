<?php

declare(strict_types=1);

/**
 * iHymns — Organisation-logo surfaces wiring guard (#1830)
 *
 * ELI5: derives EVERY file in the tree that talks about organisation logos
 * and checks the load-bearing safety rules from
 * `.claude/org-logos-1830-plan.md` §9.2 hold across every one of them —
 * never a typed list of "the files to check" (rule #34), so a NEW file that
 * starts touching logos is covered for free.
 *
 * DERIVATION FINGERPRINT — CAUGHT ITS OWN UNDER-REPORT BEFORE COMMITTING
 * (rule #34's "a scanner that under-reports is worse than no scanner"): the
 * first draft anchored ONLY on the two literal strings `org-logo.php` and
 * `tblOrganisationLogos`. `manage/organisations.php`/`manage/my-
 * organisations.php` (commit 5's admin UI) call `orgLogoRenderAdminCard()`/
 * `orgLogoValidateAndStage()`/`ihymnsOrgLogoKindKeys()` — but never spell
 * out either literal string anywhere in their own source. Actually counting
 * `$mentioning` (not just eyeballing that the guard passed) showed 5 files
 * where a healthy commit 5 should show 7 — both admin pages were SILENTLY
 * never scanned. Fixed by ALSO matching the `orgLogo`/`OrgLogo` camelCase
 * function-name family and the `ORG_LOGO_` constant-name family, which
 * catches every symbol this feature exports regardless of which literal
 * string a given caller happens to spell out.
 *
 * GROWS ACROSS TWO COMMITS (by design, per the plan's §11 commit table):
 * this file landed in commit 4 (alongside `org-logo.php`) with checks
 * (a) never-inline-SVG, (b) the serving endpoint's own header/delegation
 * shape, and (d) the sanitiser wired into the write core — all provable
 * then. Checks (c) upload-handler wiring, (e) the sanitiser-profile +
 * PDF-resolver PAIR, and (f) the kind-registry PHP<->JS lockstep are
 * written to be a VACUOUS PASS until the file they look for exists, then
 * SELF-ACTIVATE — (c) started firing for real the moment commit 5 added a
 * `logo_upload` POST action, proven below; (e)/(f) remain vacuous until
 * commit 6.
 *
 * MUTATION-PROVEN (rule #34) — each broken on purpose in a scratch copy,
 * confirmed red, reverted:
 *   - Added a raw `echo $row['ContentOriginal']`-shaped line to org-logo.php
 *     -> RED ("org-logo.php never reads ContentOriginal").
 *   - Removed the `Content-Security-Policy` header line from org-logo.php
 *     -> RED ("org-logo.php sends nosniff + a CSP + Content-Disposition").
 *   - Replaced org-logo.php's `orgLogoFetchServeRow()` call with a raw
 *     `SELECT * FROM tblOrganisationLogos` -> RED ("org-logo.php delegates
 *     to orgLogoFetchServeRow(), never a raw SELECT").
 *   - Removed the `require_once … svg_sanitizer.php` line from
 *     org_logo_admin.php -> RED ("org_logo_admin.php requires svg_sanitizer.php").
 *   - Removed the `ihymnsSanitizeSvg(` call from org_logo_admin.php ->
 *     RED ("org_logo_admin.php calls ihymnsSanitizeSvg() on the SVG branch").
 *   - (commit 5) Replaced `manage/organisations.php`'s `logo_upload` case's
 *     `orgLogoValidateAndStage()` call with a hand-built staged array
 *     (bypassing validation entirely) -> RED ("has a 'logo_upload' action
 *     that doesn't call orgLogoValidateAndStage()… a second, unvalidated
 *     upload path").
 *   Each restored -> green.
 *
 *   php tests/php/test-org-logo-surfaces.php
 *
 * Exit status 0 = clean, 1 = at least one drift.
 */

$repoRoot = dirname(__DIR__, 2);
$pub      = $repoRoot . '/appWeb/public_html';

$failures = [];
function orgLogoFail(array &$failures, string $msg): void
{
    $failures[] = $msg;
}

/**
 * Strip PHP/JS block comments AND line comments so a doc-block that
 * legitimately DESCRIBES a banned pattern (this very file, org-logo.php's
 * own doc-block, the plan) never false-positives a scan — mirrors
 * `tests/test-qr-cuercode.js`'s identical rationale.
 */
function orgLogoStripComments(string $src): string
{
    $src = (string)preg_replace_callback('#/\*.*?\*/#s', static fn(array $m) => str_repeat("\n", substr_count($m[0], "\n")), $src);
    $src = (string)preg_replace('#(^|\s)//[^\n]*#', '$1', $src);
    return $src;
}

/** Recursively list every .php/.js file under $dir, skipping vendor/node_modules/.git. */
function orgLogoWalk(string $dir): array
{
    $acc = [];
    $it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        $path = $file->getPathname();
        if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)
            || str_contains($path, DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR)) {
            continue;
        }
        $ext = $file->getExtension();
        if ($ext === 'php' || $ext === 'js') {
            $acc[] = $path;
        }
    }
    return $acc;
}

$allFiles = orgLogoWalk($pub);
if (count($allFiles) < 50) {
    fwrite(STDERR, "FATAL: only " . count($allFiles) . " files walked under appWeb/public_html — tree walk under-read (rule #34 anti-under-report).\n");
    exit(1);
}

/* ---- Derive every file that touches organisation logos AT ALL (never a
   typed list of "the files to check"). Four independent fingerprints, ORed
   — a file can mention the feature WITHOUT ever spelling out the endpoint
   URL or the table name: manage/organisations.php and
   manage/my-organisations.php (the admin UI, commit 5) call
   `orgLogoRenderAdminCard()`/`orgLogoValidateAndStage()`/… and validate
   against `ihymnsOrgLogoKindKeys()`, but never literally write
   'org-logo.php' or 'tblOrganisationLogos' anywhere in their own source —
   an early draft of this guard anchored on only those two strings and
   would have silently never scanned either admin page (caught by actually
   grep'ing for them before trusting the anchor, per rule #34's "prove a
   scanner isn't under-reporting" lesson). `orgLogo`/`OrgLogo` catches every
   camelCase function name in this family (orgLogoUpsert, orgLogoDelete,
   ihymnsOrgLogoResolveKind, …); `ORG_LOGO_` catches every UPPER_SNAKE
   constant (IHYMNS_ORG_LOGO_KINDS, …). */
$mentioning = [];
foreach ($allFiles as $path) {
    $raw = (string)file_get_contents($path);
    if (str_contains($raw, 'org-logo.php') || str_contains($raw, 'tblOrganisationLogos')
        || str_contains($raw, 'orgLogo') || str_contains($raw, 'OrgLogo') || str_contains($raw, 'ORG_LOGO_')) {
        $mentioning[$path] = $raw;
    }
}
if (count($mentioning) < 3) {
    fwrite(STDERR, "FATAL: only " . count($mentioning) . " file(s) mention the organisation-logo feature — parser anchor moved, or the feature's own files are missing.\n");
    exit(1);
}

$orgLogoPhp   = $pub . '/org-logo.php';
$helpersPhp   = $pub . '/includes/org_logo_helpers.php';
$adminPhp     = $pub . '/includes/org_logo_admin.php';
$svgSanitizer = $pub . '/includes/svg_sanitizer.php';

foreach ([$orgLogoPhp, $helpersPhp, $adminPhp, $svgSanitizer] as $must) {
    if (!is_file($must)) {
        fwrite(STDERR, "FATAL: expected file missing: $must\n");
        exit(1);
    }
}

/* =============================================================================
 * (a) NO SURFACE INLINES SVG.
 * §9.2(a): no `<svg` emission fed from logo content; ContentSanitised/
 * ContentOriginal is echoed ONLY from org-logo.php (+ pdf_renderer.php's
 * data-URI resolver, once it exists — commit 6); every OTHER consumer
 * emission is an `<img` whose src contains `/org-logo.php?`.
 * ============================================================================= */

foreach ($mentioning as $path => $raw) {
    $rel  = 'appWeb/public_html/' . substr($path, strlen($pub) + 1);
    $code = orgLogoStripComments($raw);

    /* ContentOriginal must NEVER be referenced outside the write core
       (org_logo_admin.php writes it) — org-logo.php and every future
       consumer must never read it. (The migration/schema.sql DDL and this
       guard's own doc-block also mention the column name, but neither is
       under appWeb/public_html so neither is in $mentioning at all.) */
    $isAllowedForContentOriginal = (realpath($path) === realpath($adminPhp));
    if (!$isAllowedForContentOriginal && str_contains($code, 'ContentOriginal')) {
        orgLogoFail($failures, "$rel references ContentOriginal — that column is guard-banned from every serving/read path (org_logo_admin.php is the only writer; org_logo_helpers.php's orgLogoFetchServeRow() deliberately never selects it).");
    }

    /* A literal `<svg` string built from a PHP/JS variable that plausibly
       holds logo bytes (ContentSanitised, a `$logo`/`$row['Content...']`
       shaped read, or the sanitiser's own output) is the "inlined SVG"
       smell §9.2(a) bans. org-logo.php itself never assembles an `<svg`
       string — it streams raw bytes — so this scan is safe to apply to
       every mentioning file uniformly. */
    if (preg_match('/<svg[^>]*\$/', $code) === 1 || preg_match('/\$\{[^}]*svg[^}]*\}\s*<svg/i', $code) === 1) {
        orgLogoFail($failures, "$rel appears to build a literal <svg …> string with an interpolated variable — logos must NEVER be inlined; emit <img src=\"/org-logo.php?…\"> instead.");
    }
}

/* =============================================================================
 * (b) org-logo.php's OWN shape.
 * ============================================================================= */

$orgLogoSrc = orgLogoStripComments((string)file_get_contents($orgLogoPhp));

if (!str_contains($orgLogoSrc, 'nosniff')) {
    orgLogoFail($failures, 'org-logo.php does not send X-Content-Type-Options: nosniff.');
}
if (!str_contains($orgLogoSrc, 'Content-Security-Policy')) {
    orgLogoFail($failures, 'org-logo.php does not send a Content-Security-Policy header.');
}
if (!str_contains($orgLogoSrc, 'Content-Disposition')) {
    orgLogoFail($failures, 'org-logo.php does not send a Content-Disposition header.');
}
if (!str_contains($orgLogoSrc, 'orgLogoFetchServeRow(')) {
    orgLogoFail($failures, 'org-logo.php does not delegate to orgLogoFetchServeRow() — the ONE read path for serve-able logo bytes.');
}
/* org-logo.php must NEVER run its own raw SQL against the table — every
   read goes through the shared helper. A `SELECT` keyword anywhere in the
   file (comment-stripped) is the smell. */
if (preg_match('/\bSELECT\b/i', $orgLogoSrc) === 1) {
    orgLogoFail($failures, 'org-logo.php appears to run a raw SQL SELECT — it must delegate entirely to orgLogoFetchServeRow()/orgLogoTableExists().');
}

/* =============================================================================
 * (d) The write core actually calls the sanitiser (never a second SVG path).
 * ============================================================================= */

$adminSrc = orgLogoStripComments((string)file_get_contents($adminPhp));
if (!str_contains($adminSrc, 'svg_sanitizer.php')) {
    orgLogoFail($failures, 'org_logo_admin.php does not require includes/svg_sanitizer.php.');
}
if (!str_contains($adminSrc, 'ihymnsSanitizeSvg(')) {
    orgLogoFail($failures, 'org_logo_admin.php does not call ihymnsSanitizeSvg() — the SVG upload branch must go through the ONE hardened sanitiser.');
}
/* No move_uploaded_file() anywhere in the tree's logo-mentioning files
   OTHER than the write core — an upload handler must delegate validation
   to orgLogoValidateAndStage(), never re-stage a file itself. */
foreach ($mentioning as $path => $raw) {
    if (realpath($path) === realpath($adminPhp)) {
        continue;
    }
    $code = orgLogoStripComments($raw);
    if (str_contains($code, 'move_uploaded_file(')) {
        $rel = 'appWeb/public_html/' . substr($path, strlen($pub) + 1);
        orgLogoFail($failures, "$rel calls move_uploaded_file() directly — logo uploads must go through orgLogoValidateAndStage()/orgLogoUpsert(), never a second staging path.");
    }
}

/* =============================================================================
 * (c) Upload handlers call orgLogoValidateAndStage() — SELF-ACTIVATING once
 * commit 5 adds a `logo_upload` POST action; a vacuous pass until then.
 * ============================================================================= */

$logoUploadSites = 0;
foreach ($mentioning as $path => $raw) {
    $code = orgLogoStripComments($raw);
    if (preg_match_all("/case\\s+'logo_upload'\\s*:/", $code, $m) !== false && preg_match("/case\\s+'logo_upload'\\s*:/", $code, $m2, PREG_OFFSET_CAPTURE)) {
        $offset = $m2[0][1];
        /* Generous window (rule #34's "test-editor-api2-contract.php needed
           widening from 120 to 300 chars" lesson) — a real handler body can
           run well past a small window before it reaches the core call. */
        $window = substr($code, $offset, 4000);
        $logoUploadSites++;
        if (!str_contains($window, 'orgLogoValidateAndStage(')) {
            $rel = 'appWeb/public_html/' . substr($path, strlen($pub) + 1);
            orgLogoFail($failures, "$rel has a 'logo_upload' action that doesn't call orgLogoValidateAndStage() within a reasonable window — a second, unvalidated upload path.");
        }
    }
}

/* =============================================================================
 * (e) sanitiser-profile img_src pattern + pdf_renderer.php resolver — a PAIR
 * that can't half-land (SELF-ACTIVATING once commit 6 adds either half).
 * ============================================================================= */

$htmlSanitizerPhp = $pub . '/includes/html_sanitizer.php';
$pdfRendererPhp   = $pub . '/includes/pdf_renderer.php';
$htmlSanitizerHasPattern = is_file($htmlSanitizerPhp) && str_contains((string)file_get_contents($htmlSanitizerPhp), '/org-logo\\.php');
$pdfRendererHasResolver  = is_file($pdfRendererPhp) && str_contains((string)file_get_contents($pdfRendererPhp), '_pdfInlineOrgLogo');
if ($htmlSanitizerHasPattern xor $pdfRendererHasResolver) {
    orgLogoFail($failures, 'html_sanitizer.php\'s /org-logo.php img_src pattern and pdf_renderer.php\'s _pdfInlineOrgLogo() resolver landed HALF a pair — a template with a logo block would sanitise-in but silently drop out of the PDF (or vice-versa).');
}

/* =============================================================================
 * (f) kind-registry PHP<->JS lockstep — SELF-ACTIVATING once commit 6 adds
 * ORG_LOGO_KINDS to print.js.
 * ============================================================================= */

$printJsPath = $pub . '/js/modules/print.js';
if (is_file($printJsPath)) {
    $printJsSrc = (string)file_get_contents($printJsPath);
    if (str_contains($printJsSrc, 'ORG_LOGO_KINDS')) {
        if (preg_match('/ORG_LOGO_KINDS\s*=\s*\{(.*?)\n\};/s', $printJsSrc, $m)) {
            preg_match_all('/^\s*(\w+)\s*:/m', $m[1], $jm);
            $jsKeys = $jm[1];
        } else {
            $jsKeys = [];
        }
        $phpKeys = ihymnsOrgLogoKindKeysForGuard($helpersPhp);
        if ($jsKeys === [] || $phpKeys === []) {
            orgLogoFail($failures, 'Could not parse ORG_LOGO_KINDS (print.js) and/or IHYMNS_ORG_LOGO_KINDS (org_logo_helpers.php) — parser anchor moved.');
        } elseif ($jsKeys !== $phpKeys) {
            orgLogoFail($failures, 'Kind-registry drift: ORG_LOGO_KINDS (print.js) keys ' . json_encode($jsKeys)
                . ' != IHYMNS_ORG_LOGO_KINDS (org_logo_helpers.php) keys ' . json_encode($phpKeys)
                . ' — the key ORDER is the \'auto\' fallback ladder (rule #35); order drift is ladder drift.');
        }
    }
}

/** Parse IHYMNS_ORG_LOGO_KINDS's keys, in source order, WITHOUT requiring
 *  the file (this guard must not couple its own process to org-logo's
 *  runtime side effects — a pure text parse mirrors test-print-block-registry.php's approach). */
function ihymnsOrgLogoKindKeysForGuard(string $helpersPhpPath): array
{
    $src = (string)file_get_contents($helpersPhpPath);
    if (!preg_match('/const\s+IHYMNS_ORG_LOGO_KINDS\s*=\s*\[(.*?)\n\];/s', $src, $m)) {
        return [];
    }
    preg_match_all("/^\\s*'(\\w+)'\\s*=>/m", $m[1], $km);
    return $km[1];
}

/* Never a second typed kind list anywhere else — fingerprint the ladder's
   first four keys as a contiguous quoted sequence; only org_logo_helpers.php
   (the ONE registry) may contain it. */
$ladderFingerprint = "'primary', 'full', 'horizontal', 'stacked'";
foreach ($mentioning as $path => $raw) {
    if (realpath($path) === realpath($helpersPhp)) {
        continue;
    }
    if (str_contains($raw, $ladderFingerprint)) {
        $rel = 'appWeb/public_html/' . substr($path, strlen($pub) + 1);
        orgLogoFail($failures, "$rel appears to carry a SECOND typed copy of the kind ladder — the ONE registry is IHYMNS_ORG_LOGO_KINDS in org_logo_helpers.php.");
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " organisation-logo surface wiring smell(s):\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}
printf("OK: organisation-logo surfaces wired correctly — %d file(s) mentioning org-logo.php/tblOrganisationLogos scanned, no inline-SVG/ContentOriginal/raw-SQL/second-sanitiser/second-kind-list smell.\n", count($mentioning));
exit(0);
