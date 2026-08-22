<?php

declare(strict_types=1);

/**
 * iHymns — Organisation-logo surfaces wiring guard (#1830, extended #1840)
 *
 * ELI5: derives EVERY file in the tree that talks about organisation logos
 * and checks the load-bearing safety rules from
 * `.claude/org-logos-1830-plan.md` §9.2 (base feature) and
 * `.claude/org-logo-surfaces-1840-plan.md` §8.1 (the header/projector/
 * og-image/brand-colour screen surfaces this file was extended for) hold
 * across every one of them — never a typed list of "the files to check"
 * (rule #34), so a NEW file that starts touching logos is covered for free.
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
 * GROWS ACROSS FOUR COMMITS (by design, per the plan's §11 commit table):
 * this file landed in commit 4 (alongside `org-logo.php`) with checks
 * (a) never-inline-SVG, (b) the serving endpoint's own header/delegation
 * shape, and (d) the sanitiser wired into the write core — all provable
 * then. Checks (c) upload-handler wiring, (e) the sanitiser-profile +
 * PDF-resolver PAIR, and (f) the kind-registry PHP<->JS lockstep were
 * written to be a VACUOUS PASS until the file they look for exists, then
 * SELF-ACTIVATE: (c) started firing for real the moment commit 5 added a
 * `logo_upload` POST action; (e) and (f) started firing for real the
 * moment commit 6 added the `logo` print block (the sanitiser-pattern +
 * PDF-resolver pair, and the `ORG_LOGO_KINDS` client mirror) — all proven
 * below, no assertion here has EVER shipped unproven. Commit 7 (this
 * revision) is the plan's designated "finish the guard" commit: by then
 * every self-activating check had ALREADY fired for real in the commit
 * that first made it meaningful (recorded below as it happened, not
 * deferred), so this pass's own job was to close the two anti-under-report
 * gaps rule #34 calls for — a floor under check (c)'s site COUNT and under
 * check (f)'s parsed KEY count — and re-verify the WHOLE guard end-to-end
 * one more time as a single consolidated pass.
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
 *   - (commit 6, check e) Renamed every occurrence of `_pdfInlineOrgLogo`
 *     in pdf_renderer.php to a name NOT containing that substring (so it
 *     is genuinely ABSENT, not merely disguised — an earlier attempt that
 *     renamed it to `_pdfInlineOrgLogoDISABLED` stayed GREEN, because that
 *     new name still CONTAINS the original as a substring; caught by
 *     actually re-reading the "red" output instead of trusting the label)
 *     -> RED ("landed HALF a pair"). Restored -> green. Same mutation in
 *     the OTHER direction — stripped `/org-logo.php` from BOTH
 *     html_sanitizer.php profiles while leaving the PDF resolver intact ->
 *     RED (same message, other half missing). Restored -> green.
 *   - (commit 6, check f) Reordered `ORG_LOGO_KINDS`' first two keys in
 *     print.js (`primary`/`full` swapped) -> RED ("Kind-registry drift…
 *     order drift is ladder drift"). Restored -> green.
 *   - (commit 7, check c floor) Renamed ONE of the two `logo_upload` case
 *     labels — `manage/my-organisations.php` only — to
 *     `logo_upload_DISABLED` -> RED ("Found only 1 'logo_upload' handler
 *     site(s)… one of them may have silently lost its case label").
 *     Restored -> green.
 *   - (commit 7, check f floor) Truncated `ORG_LOGO_KINDS` in print.js to
 *     its first 3 entries -> RED, TWO assertions at once: the min-count
 *     floor ("parsed only 3 kind(s) (< 8)") AND the equality check itself
 *     ("Kind-registry drift" — 3 keys no longer equal 10). Restored ->
 *     green. (Confirms the floor adds real, INDEPENDENT coverage rather
 *     than being redundant with the equality check: a floor breach is
 *     reported with its OWN message even though the equality check would
 *     already have failed on the same input.)
 * *   Each restored -> green.
 *
 * ----------------------------------------------------------------------------
 * #1840 EXTENSION — checks (g)-(k), landed across commits 2/5/6/7/8, this
 * commit (9) the plan's designated "finish the guard" pass for THIS
 * feature (mirrors the #1830 commit-7 pass above): re-verify (g)-(k)
 * end-to-end as one consolidated run and record every mutation here.
 *
 *   - (g) IHYMNS_ORG_LOGO_SURFACE_PREFS (org_logo_helpers.php) <->
 *     ORG_LOGO_SURFACE_PREFS (org-logo.js) lockstep — landed commit 2,
 *     live immediately (both files existed in the SAME commit, unlike (c)/
 *     (e)/(f)'s self-activating shape). (j) print's fold stays
 *     variant-filtered — also commit 2.
 *   - (i) brand colour goes through the ONE normaliser — WRITE half landed
 *     commit 5 (live immediately, both admin pages' `brand_save` cases
 *     shipped in that same commit); READ half (og-image.php) self-activated
 *     commit 8.
 *   - (k) header/projector emit a real `<img src="/org-logo.php?...">` —
 *     half 1 (header-branding.js) landed commit 6, live immediately; half 2
 *     (service-projection.php) landed commit 7.
 *   - (h) og-image never self-requests — landed commit 8, live immediately.
 *
 * MUTATION-PROVEN (#1840 checks) — each broken on purpose in a scratch
 * copy, confirmed red, restored to a byte-identical diff:
 *   - (g) Swapped `emblem`/`favicon` order in org-logo.js's `header` kind
 *     list -> RED ("Surface-prefs drift: 'header' kind list/order
 *     differs"). Deleted the `'projector'` entry from PHP's
 *     IHYMNS_ORG_LOGO_SURFACE_PREFS -> RED, TWO assertions at once (the
 *     >=3-surface floor AND the key-order equality check — same
 *     independent-coverage shape as the base guard's (f) floor proof).
 *   - (j) Removed the `.variant` filter from print.js's
 *     `fetchPrintOrgLogos()` fold -> RED ("no longer references .variant").
 *   - (i) Replaced the normalise+persist calls in organisations.php's
 *     `brand_save` case with a raw UPDATE bypassing
 *     `ihymnsOrgBrandColourNormalise()` -> RED. (An EARLIER, weaker
 *     mutation — swapping the persisted ARGUMENT from `$normalised` to
 *     `$rawColour` while leaving the `orgSetBrandColour(` CALL text intact
 *     — stayed GREEN, correctly: this check is a textual call-presence
 *     assertion, not a data-flow analysis, so a call that's present but
 *     fed the wrong variable is genuinely outside its stated scope, not a
 *     missed catch.) Renamed my-organisations.php's `brand_save` case
 *     label -> RED (floor breach, "only 1 site"). Once commit 8 activated
 *     the READ half: replaced `ihymnsOrgBrandColourRgb(` with an inline
 *     `sscanf()` in og-image.php -> RED ("a second, inline hex-parse
 *     fork").
 *   - (h) Replaced og-image.php's direct `orgLogoFetchServeRow()` read with
 *     a `file_get_contents('https://.../org-logo.php...')` HTTP
 *     self-request -> RED, TWO assertions at once (missing direct-read
 *     call AND the banned self-request shape).
 *   - (k) — THIS CHECK CAUGHT ITS OWN UNDER-REPORT (rule #34's "a scanner
 *     that under-reports is worse than no scanner", the SAME lesson this
 *     file's own header section already documents for the base #1830
 *     fingerprint). The FIRST draft was a single file-wide test: "does
 *     ANY `<img>` exist in the file AND does `/org-logo.php?` (or
 *     `orgLogoUrl(`) appear ANYWHERE in the file?". A scripted trial that
 *     deleted service-projection.php's `<img id="svc-proj-logo">` outright
 *     stayed GREEN, because that page ALSO creates an unrelated `<img>`
 *     for its QR code (#1339), and the server-side `/org-logo.php?`
 *     resolution string was still present elsewhere in the file —
 *     each independently satisfied the loose file-wide test even though
 *     the ACTUAL corner-bug element was gone. Fixed by anchoring each half
 *     on the feature's own distinguishing marker instead of a generic tag
 *     search: header-branding.js's single `createElement('img')` call
 *     windowed forward for `orgLogoUrl(`; service-projection.php's literal
 *     `<img id="svc-proj-logo">` existence PLUS a symmetric (order-
 *     independent) proximity check between the `OrgLogoUrl` property name
 *     and the literal `/org-logo.php?` string (the PHP resolution code and
 *     the JSON property it feeds appear in DIFFERENT relative orders
 *     depending on how the surrounding code reads — a forward-only regex
 *     window missed this on the first attempt too, fixed by measuring the
 *     REAL byte offsets in the actual file rather than assuming an order).
 *     Re-run after the fix: deleting `<img id="svc-proj-logo">` -> RED;
 *     replacing the `/org-logo.php?` endpoint string -> RED; deleting
 *     header-branding.js's `<img>` creation -> RED; bypassing
 *     `orgLogoUrl()` with a hand-built URL -> RED. Every mutation restored
 *     -> byte-identical (diffed to confirm, not just re-run to green).
 *   - (commit 9, consolidation) A COMBINED mutation — breaking check (g) in
 *     org-logo.js AND check (k) in service-projection.php in the SAME run
 *     — reported BOTH failures together (the guard accumulates into
 *     `$failures` rather than stopping at the first hit), confirming the
 *     checks are independent and none masks another. Both restored ->
 *     byte-identical.
 *
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
/* Commit 7 anti-under-report floor (rule #34): once ANY 'logo_upload' site
   exists at all, there must be AT LEAST the two known admin pages
   (manage/organisations.php + manage/my-organisations.php, §7 of the plan)
   — `< 2` (not `!== 2`) so a legitimate future THIRD site using the same
   action name is never flagged as wrong, while a regex that only matched
   ONE of the two real handlers (e.g. a brace-tracking edge case on just
   the second file) still fails loud instead of silently under-reporting. */
if ($logoUploadSites > 0 && $logoUploadSites < 2) {
    orgLogoFail($failures, "Found only {$logoUploadSites} 'logo_upload' handler site(s) — the plan wires TWO (manage/organisations.php AND manage/my-organisations.php); one of them may have silently lost its case label or its own scan may be failing.");
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
        /* Commit 7 anti-under-report floor (rule #34, mirrors
           test-print-block-registry.php's PT_MIN_TYPES): the real taxonomy
           is 10 kinds; a parser that silently matched only a handful on
           BOTH sides could otherwise agree-by-coincidence on a truncated
           subset and report a false "lockstep". */
        $orgLogoKindMinCount = 8;
        if (count($jsKeys) > 0 && count($jsKeys) < $orgLogoKindMinCount) {
            orgLogoFail($failures, sprintf('ORG_LOGO_KINDS (print.js) parsed only %d kind(s) (< %d) — parser anchor moved or the map was emptied.', count($jsKeys), $orgLogoKindMinCount));
        }
        if (count($phpKeys) > 0 && count($phpKeys) < $orgLogoKindMinCount) {
            orgLogoFail($failures, sprintf('IHYMNS_ORG_LOGO_KINDS (org_logo_helpers.php) parsed only %d kind(s) (< %d) — parser anchor moved or the map was emptied.', count($phpKeys), $orgLogoKindMinCount));
        }
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

/* =============================================================================
 * (g) #1840 — surface-prefs PHP<->JS lockstep: IHYMNS_ORG_LOGO_SURFACE_PREFS
 * (org_logo_helpers.php) must agree EXACTLY — same surface keys, in the same
 * order, same per-surface kind list in the same order, same darkCapableOnly
 * flag — with ORG_LOGO_SURFACE_PREFS (js/modules/org-logo.js). Order is the
 * per-surface resolution ladder (mirrors check (f)'s kind-ladder discipline,
 * rule #35: a mechanism, not a comment).
 * ============================================================================= */

/** Parse IHYMNS_ORG_LOGO_SURFACE_PREFS from org_logo_helpers.php, in source
 *  order, WITHOUT requiring the file (pure text parse, mirrors
 *  ihymnsOrgLogoKindKeysForGuard() above).
 *  @return array<string, array{kinds: list<string>, darkCapableOnly: bool}> */
function orgLogoSurfacePrefsFromPhp(string $helpersPhpPath): array
{
    $src = (string)file_get_contents($helpersPhpPath);
    if (!preg_match('/const\s+IHYMNS_ORG_LOGO_SURFACE_PREFS\s*=\s*\[(.*?)\n\];/s', $src, $m)) {
        return [];
    }
    $out = [];
    preg_match_all(
        "/'([\\w-]+)'\\s*=>\\s*\\['kinds'\\s*=>\\s*\\[([^\\]]*)\\]\\s*,\\s*'darkCapableOnly'\\s*=>\\s*(true|false)\\]/",
        $m[1],
        $entries,
        PREG_SET_ORDER
    );
    foreach ($entries as $e) {
        preg_match_all("/'(\\w+)'/", $e[2], $km);
        $out[$e[1]] = ['kinds' => $km[1], 'darkCapableOnly' => $e[3] === 'true'];
    }
    return $out;
}

/** Parse ORG_LOGO_SURFACE_PREFS from js/modules/org-logo.js, in source order.
 *  JS object keys may be bare identifiers (`header:`) or quoted (`'og-card':`).
 *  @return array<string, array{kinds: list<string>, darkCapableOnly: bool}> */
function orgLogoSurfacePrefsFromJs(string $jsPath): array
{
    $src = (string)file_get_contents($jsPath);
    if (!preg_match('/const\s+ORG_LOGO_SURFACE_PREFS\s*=\s*\{(.*?)\n\};/s', $src, $m)) {
        return [];
    }
    $out = [];
    preg_match_all(
        "/(?:'([\\w-]+)'|(\\w+))\\s*:\\s*\\{\\s*kinds:\\s*\\[([^\\]]*)\\]\\s*,\\s*darkCapableOnly:\\s*(true|false)\\s*\\}/",
        $m[1],
        $entries,
        PREG_SET_ORDER
    );
    foreach ($entries as $e) {
        $key = $e[1] !== '' ? $e[1] : $e[2];
        preg_match_all("/'(\\w+)'/", $e[3], $km);
        $out[$key] = ['kinds' => $km[1], 'darkCapableOnly' => $e[4] === 'true'];
    }
    return $out;
}

$orgLogoJsPath = $pub . '/js/modules/org-logo.js';
if (is_file($orgLogoJsPath)) {
    $surfacePrefsPhp = orgLogoSurfacePrefsFromPhp($helpersPhp);
    $surfacePrefsJs  = orgLogoSurfacePrefsFromJs($orgLogoJsPath);

    /* Anti-under-report floor (rule #34): the real registry is 3 surfaces,
       >= 2 kinds each — a parser that silently matched a truncated subset on
       BOTH sides could otherwise agree-by-coincidence. */
    $surfaceMinCount = 3;
    if (count($surfacePrefsPhp) > 0 && count($surfacePrefsPhp) < $surfaceMinCount) {
        orgLogoFail($failures, sprintf('IHYMNS_ORG_LOGO_SURFACE_PREFS (org_logo_helpers.php) parsed only %d surface(s) (< %d) — parser anchor moved or the map was emptied.', count($surfacePrefsPhp), $surfaceMinCount));
    }
    if (count($surfacePrefsJs) > 0 && count($surfacePrefsJs) < $surfaceMinCount) {
        orgLogoFail($failures, sprintf('ORG_LOGO_SURFACE_PREFS (org-logo.js) parsed only %d surface(s) (< %d) — parser anchor moved or the map was emptied.', count($surfacePrefsJs), $surfaceMinCount));
    }

    if ($surfacePrefsPhp === [] || $surfacePrefsJs === []) {
        orgLogoFail($failures, 'Could not parse IHYMNS_ORG_LOGO_SURFACE_PREFS (org_logo_helpers.php) and/or ORG_LOGO_SURFACE_PREFS (org-logo.js) — parser anchor moved.');
    } elseif (array_keys($surfacePrefsPhp) !== array_keys($surfacePrefsJs)) {
        orgLogoFail($failures, 'Surface-prefs drift: surface KEYS/ORDER differ — PHP has ' . json_encode(array_keys($surfacePrefsPhp))
            . ', JS has ' . json_encode(array_keys($surfacePrefsJs)) . ' (#1840).');
    } else {
        foreach ($surfacePrefsPhp as $surface => $phpDef) {
            $jsDef = $surfacePrefsJs[$surface];
            $kindMinCount = 2;
            if (count($phpDef['kinds']) < $kindMinCount) {
                orgLogoFail($failures, "Surface '$surface' (org_logo_helpers.php) parsed only " . count($phpDef['kinds']) . " kind(s) (< $kindMinCount).");
            }
            if ($phpDef['kinds'] !== $jsDef['kinds']) {
                orgLogoFail($failures, "Surface-prefs drift: '$surface' kind list/order differs — PHP has " . json_encode($phpDef['kinds'])
                    . ', JS has ' . json_encode($jsDef['kinds']) . ' — kind ORDER is the resolution ladder (rule #35); order drift is ladder drift (#1840).');
            }
            if ($phpDef['darkCapableOnly'] !== $jsDef['darkCapableOnly']) {
                orgLogoFail($failures, "Surface-prefs drift: '$surface' darkCapableOnly is " . json_encode($phpDef['darkCapableOnly']) . ' in PHP but ' . json_encode($jsDef['darkCapableOnly']) . ' in JS (#1840).');
            }
        }
    }
}

/* =============================================================================
 * (j) #1840 — print's fold stays variant-filtered. Narrow by design (rule
 * #34's "don't fail on correct code") — asserts the FILTER exists inside
 * fetchPrintOrgLogos()'s fold, not its exact spelling, so a legitimate
 * rewrite of the condition still passes as long as `.variant` is still
 * examined somewhere in the fold.
 * ============================================================================= */

if (is_file($printJsPath)) {
    $printJsSrcRaw = (string)file_get_contents($printJsPath);
    if (preg_match('/function\s+fetchPrintOrgLogos\s*\([^)]*\)\s*\{/', $printJsSrcRaw, $fm, PREG_OFFSET_CAPTURE)) {
        $window = substr($printJsSrcRaw, $fm[0][1], 2000);
        if (!str_contains($window, '.variant')) {
            orgLogoFail($failures, "print.js's fetchPrintOrgLogos() fold no longer references .variant — the byKind fold must stay pinned to the 'default' variant (#1840 §3.4), or the first light/dark logo upload will silently re-brand every print template (last-row-wins on an alphabetically-later variant).");
        }
    }
}

/* =============================================================================
 * (i) #1840 — brand colour goes through the ONE normaliser/write-path.
 * WRITE half is live from commit 5 (both admin pages' 'brand_save' actions);
 * the READ half (og-image.php) is SELF-ACTIVATING — vacuous until commit 8
 * makes og-image.php actually reference BrandColor, mirrors checks (e)/(f)'s
 * established self-activation shape from the base #1830 guard.
 * ============================================================================= */

$brandSaveSites = 0;
foreach ($allFiles as $path) {
    $raw  = (string)file_get_contents($path);
    $code = orgLogoStripComments($raw);
    if (preg_match("/case\\s+'brand_save'\\s*:/", $code, $bm, PREG_OFFSET_CAPTURE)) {
        $offset = $bm[0][1];
        /* Generous window (rule #34's "test-editor-api2-contract.php needed
           widening from 120 to 300 chars" lesson) — mirrors check (c)'s
           4000-char window. */
        $window = substr($code, $offset, 3000);
        $brandSaveSites++;
        if (!str_contains($window, 'ihymnsOrgBrandColourNormalise(') && !str_contains($window, 'orgSetBrandColour(')) {
            $rel = 'appWeb/public_html/' . substr($path, strlen($pub) + 1);
            orgLogoFail($failures, "$rel has a 'brand_save' action that doesn't call ihymnsOrgBrandColourNormalise()/orgSetBrandColour() within a reasonable window — a write that could bypass the ONE hex allowlist (#1840).");
        }
    }
}
/* Anti-under-report floor (rule #34), mirrors check (c)'s floor: once ANY
   'brand_save' site exists at all, there must be AT LEAST the two known
   admin pages (manage/organisations.php + manage/my-organisations.php). */
if ($brandSaveSites > 0 && $brandSaveSites < 2) {
    orgLogoFail($failures, "Found only {$brandSaveSites} 'brand_save' handler site(s) — the plan wires TWO (manage/organisations.php AND manage/my-organisations.php); one of them may have silently lost its case label or its own scan may be failing.");
}

/* =============================================================================
 * (h) #1840 — og-image never self-requests. Logo bytes must be read
 * DIRECTLY via orgLogoFetchServeRow() (the ONE read path) — mirrors
 * _pdfInlineOrgLogo()'s (pdf_renderer.php) identical "never mPDF/GD
 * self-request over HTTP" doctrine. Self-activating once og-image.php
 * exists AND mentions the org-logo feature at all (commit 8).
 * ============================================================================= */

$ogImagePhpPath = $pub . '/og-image.php';
if (is_file($ogImagePhpPath)) {
    $ogImageSrcRaw = orgLogoStripComments((string)file_get_contents($ogImagePhpPath));
    if (str_contains($ogImageSrcRaw, 'orgLogo') || str_contains($ogImageSrcRaw, 'OrgLogo') || str_contains($ogImageSrcRaw, 'BrandColor')) {
        if (!str_contains($ogImageSrcRaw, 'orgLogoFetchServeRow(')) {
            orgLogoFail($failures, 'og-image.php references org branding but never calls orgLogoFetchServeRow() — logo bytes must be read directly, never via an HTTP self-request to org-logo.php (#1840).');
        }
        /* Ban an HTTP-fetch shape (curl_init(...) or file_get_contents('http...'))
           whose target string contains org-logo.php — the string 'org-logo.php'
           is legitimately present elsewhere in this file (e.g. inside a doc
           comment describing the doctrine, already stripped above), so this
           bans the SELF-REQUEST SHAPE specifically, not the bare substring. */
        if (preg_match('/(curl_init\s*\(|file_get_contents\s*\(\s*[\'"]https?:)[^;]{0,400}org-logo\.php/s', $ogImageSrcRaw) === 1) {
            orgLogoFail($failures, 'og-image.php appears to fetch org-logo.php over HTTP (curl/file_get_contents) instead of reading bytes directly via orgLogoFetchServeRow() (#1840).');
        }
    }
}

/* og-image's READ side for BrandColor specifically — self-activating once
   it exists AND mentions BrandColor (commit 8). Never an inline hex-parse
   fork (e.g. a raw sscanf('#%02x...') or substr/hexdec chain bypassing the
   shared parser). */
if (is_file($ogImagePhpPath)) {
    $ogImageSrcRaw = orgLogoStripComments((string)file_get_contents($ogImagePhpPath));
    if (str_contains($ogImageSrcRaw, 'BrandColor') && !str_contains($ogImageSrcRaw, 'ihymnsOrgBrandColourRgb(')) {
        orgLogoFail($failures, 'og-image.php references BrandColor but never calls ihymnsOrgBrandColourRgb() — a second, inline hex-parse fork (#1840).');
    }
}

/* =============================================================================
 * (k) #1840 — the two NEW screen-surface consumers (header, projector) emit
 * a real <img> whose src TRACES to /org-logo.php? — the never-inline rule's
 * POSITIVE half (check (a) above already bans the negative: no inlined
 * <svg>, no data-URI). PROXIMITY-anchored (not "does an <img> exist
 * anywhere in the file" — service-projection.php ALSO creates an unrelated
 * <img> for its QR code, so a file-wide "both exist somewhere" check would
 * under-report: it stayed GREEN in a scripted trial that deleted the
 * corner-bug <img> outright, because the QR's own <img> and the file's
 * unrelated /org-logo.php? server-resolution string each independently
 * satisfied a loose file-wide check. Fixed by anchoring EACH half on the
 * feature's own distinguishing marker instead of a generic tag search).
 * Half 1 (header-branding.js) is live from commit 6; half 2
 * (service-projection.php) is live from commit 7.
 * ============================================================================= */

/* -- Half 1: header-branding.js — createElement('img') immediately followed
   (within a window) by a .src assignment that calls orgLogoUrl(). The file
   has exactly ONE <img>-creation site (the emblem), so this is safe to
   anchor on that call directly rather than a proximity window. */
$headerBrandingPath = $pub . '/js/modules/header-branding.js';
if (is_file($headerBrandingPath)) {
    $headerCode = orgLogoStripComments((string)file_get_contents($headerBrandingPath));
    $headerRel  = 'appWeb/public_html/' . substr($headerBrandingPath, strlen($pub) + 1);
    if (preg_match('/createElement\(\s*[\'"]img[\'"]\s*\)/', $headerCode, $hm, PREG_OFFSET_CAPTURE)) {
        $window = substr($headerCode, $hm[0][1], 600);
        if (!str_contains($window, 'orgLogoUrl(')) {
            orgLogoFail($failures, "$headerRel creates an <img> but its .src isn't built via the shared orgLogoUrl() (or a literal /org-logo.php?) within a reasonable window — logos must be served as <img src>, never inlined markup or a data-URI (#1840, rule #42).");
        }
    } elseif (str_contains($headerCode, 'orgLogo') || str_contains($headerCode, 'OrgLogo')) {
        orgLogoFail($failures, "$headerRel mentions the org-logo feature but never creates an <img> element (#1840, rule #42).");
    }
}

/* -- Half 2: service-projection.php — a mixed PHP/JS page where the URL is
   resolved SERVER-side (baked into the VENUES JSON as `OrgLogoUrl`) and
   consumed CLIENT-side by a literal <img id="svc-proj-logo"> — the two
   halves are naturally far apart in the file, so this checks EACH half's
   own distinguishing marker: (A) the literal corner-bug <img> element
   exists; (B) an `OrgLogoUrl` assignment/reference co-occurs with the
   literal `/org-logo.php?` endpoint string within a generous window
   (proximity, not "both exist somewhere in the whole file" — see this
   check's own doc-block above for why a file-wide test under-reports here). */
/** True when ANY occurrence of $needle1 sits within $maxDist characters of
 *  ANY occurrence of $needle2, in either direction — a symmetric proximity
 *  check (order-independent, unlike a single forward-only regex window),
 *  needed here because the server-side URL-building code and the
 *  `OrgLogoUrl` property NAME it feeds can appear in either order depending
 *  on how the surrounding code is written. */
function orgLogoProximity(string $code, string $needle1, string $needle2, int $maxDist): bool
{
    $pos1 = [];
    $off = 0;
    while (($p = strpos($code, $needle1, $off)) !== false) {
        $pos1[] = $p;
        $off = $p + 1;
    }
    $pos2 = [];
    $off = 0;
    while (($p = strpos($code, $needle2, $off)) !== false) {
        $pos2[] = $p;
        $off = $p + 1;
    }
    foreach ($pos1 as $a) {
        foreach ($pos2 as $b) {
            if (abs($a - $b) <= $maxDist) {
                return true;
            }
        }
    }
    return false;
}

$svcProjPath = $pub . '/manage/service-projection.php';
if (is_file($svcProjPath)) {
    $svcProjCode = orgLogoStripComments((string)file_get_contents($svcProjPath));
    $svcProjRel  = 'appWeb/public_html/' . substr($svcProjPath, strlen($pub) + 1);
    if (str_contains($svcProjCode, 'OrgLogoUrl')) {
        if (preg_match('/<img\b[^>]*\bid\s*=\s*["\']svc-proj-logo["\'][^>]*>/', $svcProjCode) !== 1) {
            orgLogoFail($failures, "$svcProjRel resolves OrgLogoUrl but no <img id=\"svc-proj-logo\"> element exists — logos must be served as <img src>, never inlined markup or a data-URI (#1840, rule #42).");
        }
        if (!orgLogoProximity($svcProjCode, 'OrgLogoUrl', '/org-logo.php?', 700)) {
            orgLogoFail($failures, "$svcProjRel references OrgLogoUrl but it isn't resolved from a literal /org-logo.php? URL within a reasonable window (#1840).");
        }
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " organisation-logo surface wiring smell(s):\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}
printf("OK: organisation-logo surfaces wired correctly — %d file(s) mentioning org-logo.php/tblOrganisationLogos scanned, no inline-SVG/ContentOriginal/raw-SQL/second-sanitiser/second-kind-list/surface-prefs-drift/unfiltered-print-fold smell.\n", count($mentioning));
exit(0);
