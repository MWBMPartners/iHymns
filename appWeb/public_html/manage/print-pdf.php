<?php

declare(strict_types=1);

/**
 * iHymns — Server-PDF endpoint (#1767 remainder P3)
 *
 * ELI5: the browser already built the exact HTML page it would print (the
 * SAME code path as clicking "Print"); this endpoint's only job is to turn
 * that finished HTML into a real PDF file and hand it back. It never
 * decides what a song's printout looks like — it only converts.
 *
 * DETAIL — THE ONE-RENDERER INVARIANT (`.claude/print-templates-1767-remainder-plan.md`
 * §2): `renderTemplateBodyHtml()` in `js/modules/print.js` is the SINGLE
 * source of truth for a printed page. This endpoint receives the FINISHED
 * HTML+CSS the client already built with that function and does
 * SANITISE → ADAPT → CONVERT → STREAM — nothing here ever decides what a
 * "block" looks like. `tests/php/test-print-one-renderer.php` is the
 * mutation-proven guard that keeps this true.
 *
 * NOT PUBLIC (#94 discipline): standalone script, no `api.php` action, no
 * page fragment, never referenced from `includes/pages/` or
 * `includes/partials/`, never in `$_cacheablePages`. It is reachable ONLY
 * by a same-origin authenticated POST to `/manage/print-pdf.php` —
 * `tests/php/test-print-one-renderer.php` also asserts the not-public shape.
 *
 * GATE — AUTHENTICATION ONLY, NO ADDITIONAL ENTITLEMENT (deliberate; see
 * plan §3.1): the HTML this endpoint converts is data the caller could
 * already read through the public `song_data` API (which is itself already
 * tier-gated server-side, `api.php` `song_detail`/`song_data` actions) — a
 * PDF is a FORMATTING service, never an access grant (plan §2 "trust
 * note"). The surfaces that will call this endpoint in a later phase (the
 * template editor's Preview-as-PDF, a future Download-PDF button) each
 * carry their OWN page-level gate (`manage_songbooks` for the editor);
 * requiring a second, narrower entitlement HERE would just be a second copy
 * of a check those callers already make. This is why
 * `tests/php/test-admin-gate-parity.php` (which pairs a NAV ENTRY's
 * advertised entitlement with its page's own gate) correctly has nothing to
 * check here — this file has no nav entry, because it is not a page.
 *
 * AUTH — BEARER-THEN-COOKIE (API-coverage C6,
 * `.claude/api-coverage-2026-08-28.md` §4.1): a native curator app has no
 * cookie jar, so `_pdfResolveAuthenticatedUser()` below tries an
 * `Authorization: Bearer <token>` header FIRST — resolved through the ONE
 * shared verification core, `apiTokenResolveBearerUser()`
 * (`includes/api_tokens.php`, rule #22 — never a second, forked token
 * query) — and only when that is absent or doesn't verify falls through to
 * EXACTLY the pre-existing `isAuthenticated()`/`getCurrentUser()` `/manage/`
 * cookie-session check, unchanged in every particular. This is the SAME
 * Bearer-then-cookie ordering `song-media.php`'s `_songMedia_resolveViewer()`
 * uses, and the SAME shared-core wiring `manage/editor/api2.php`,
 * `manage/editor/api.php`, and `manage/places-api.php` already use for their
 * own Bearer support. A Bearer request that resolves to a valid user is
 * authenticated exactly as a cookie session would be — same $currentUser
 * shape, same "no additional entitlement" contract above, same everything
 * downstream. This endpoint's CSRF gate (403, below) is UNCHANGED by this —
 * it still requires `validateCsrfRequest()` to pass regardless of which auth
 * path won; a native caller sends `X-Requested-With` like any other
 * same-origin AJAX writer.
 *
 * STATUS-CODE CONTRACT (rule #35 — branch on the CODE, never the prose):
 *   200  application/pdf bytes, streamed.
 *   204  `?ping=1` GET only — authenticated AND the engine is available.
 *   400  malformed JSON body / an unsupported `mode` / a cap breached
 *        (document count, `bodyHtml` size, `css` size, total request size).
 *   401  not authenticated (`?ping=1` too).
 *   403  CSRF check failed (`validateCsrfRequest()`, rule #29).
 *   405  method is neither POST nor a `?ping=1` GET.
 *   429  rate-limited (`enforceReadRateLimitKeyed('pdf', 20)`, fail-open).
 *   503  the mPDF engine isn't vendored/loadable on this install
 *        (`?ping=1` too), OR mPDF itself failed mid-render — mirrors
 *        `qr.php`'s dormant-503 discipline; the client's browser-Print path
 *        is completely unaffected by either.
 *
 * REQUEST CONTRACT (POST, `Content-Type: application/json`):
 *   {
 *     "mode": "song" | "batch" | "preview",
 *     "documents": [ { "bodyHtml": "…", "meta": { "songId", "title", "lang", "dir", "book" } }, … ],
 *     "css": "…printCss() output…",
 *     "pageOptions": { … },        // re-validated against the SAME schema the editor uses
 *     "filename": "amazing-grace", // optional; slug-sanitised server-side either way
 *     "copies": 1,                 // optional int 1..10000 — #1767 remainder P5, rides into printUsageLog() PER QUALIFYING DOCUMENT
 *     "setlistId": "abc123"        // optional — #1897 W3, the set-list this batch was printed FOR; sanitised + ridden into each printUsageLog() meta
 *   }
 * `meta.book` (#1767 remainder P4, §4.3) feeds the OPTIONAL `runningHeader`
 * page option's 'titleBook' mode only — capped/control-stripped exactly like
 * every other meta string below, never rendered as markup.
 * `documents` is an ARRAY on purpose — `mode: "song"`/`"preview"` send
 * exactly one, `mode: "batch"` (#1767 remainder P6 — the whole set-list/
 * songbook as ONE PDF) sends up to `PDF_MAX_DOCUMENTS`. The renderer
 * (`includes/pdf_renderer.php ihymnsPdfRender()`) concatenates every
 * document into ONE PDF via its existing `AddPage()`-between-docs loop —
 * built forward-compat for this in P3, unchanged by this phase beyond
 * raising the cap. Each document is sanitised, gets its OWN server-resolved
 * running header + CCLI footer (never the first document's), and — when
 * `copies` rides along — its OWN `tblSongUsageEvents` row when (and only
 * when) ITS song resolves to a qualifying CCLI licence (§6.2/§6.3, looped).
 *
 * PIPELINE ORDER (fixed, mirrors `qr.php`'s cheap-checks-first shape):
 *   auth(401) → CSRF(403) → rate-limit(429) → parse+cap(400) →
 *   sanitise HTML+CSS (`includes/html_sanitizer.php`, the P2 module — reused,
 *   never forked) → engine-available(503) → `ihymnsPdfRender()` (adapt +
 *   convert, `includes/pdf_renderer.php`) → stream.
 * Sanitising ALWAYS happens, even though the client's own renderer already
 * escapes everything it emits: the POST body is attacker-writable
 * independently of whether `print.js` ever ran (plan §5.2).
 *
 * @see .claude/print-templates-1767-remainder-plan.md §3.1  the full design this file implements
 * @see appWeb/public_html/includes/pdf_renderer.php   the ONE engine wrapper (adapt + convert)
 * @see appWeb/public_html/includes/html_sanitizer.php  the ONE sanitiser (P2, reused here)
 * @see appWeb/public_html/includes/print_template_schema.php  the shared block/page-option allow-list
 * @see appWeb/public_html/js/modules/print.js          the ONE HTML/CSS renderer this endpoint never re-implements
 * @see appWeb/public_html/qr.php                        the dormant-503 + not-public pattern this mirrors
 * @see tests/php/test-print-one-renderer.php            the mutation-proven "never a second renderer" + not-public guard
 */

/* A require/parse FATAL before the try/catch below surfaces as a bare HTTP 500 with
   NO body — invisible to any JSON-expecting client. Mirrors manage/places-api.php's
   identical shutdown-handler precedent (that file cites #1180/#1365 — two prior
   incidents of exactly this failure mode on a manage/*-api.php script). */
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e === null || !in_array($e['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
        return; // normal shutdown — nothing to report
    }
    $where = basename((string)($e['file'] ?? '')) . ':' . (int)($e['line'] ?? 0);
    error_log('[print-pdf] FATAL ' . ($e['message'] ?? '') . ' @ ' . $where);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode([
        'error'  => 'Internal error (load).',
        'detail' => (defined('IHYMNS_DEBUG') && IHYMNS_DEBUG) ? (($e['message'] ?? '') . ' @ ' . $where) : null,
    ]);
});

/* manage/includes/auth.php (THIS file's own directory — mirrors places-api.php's
   documented __DIR__ vs dirname(__DIR__) distinction) transitively pulls in
   config.php / db_mysql.php / entitlements.php / activity_log.php and installs
   the whole-/manage/ fatal→activity-log mirror — isAuthenticated(), getCurrentUser()
   and validateCsrfRequest() all come from here. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
/* apiTokenResolveBearerUser() — the ONE shared Authorization: Bearer
   verification core (rule #22), also used by song-media.php's own
   Bearer-then-cookie resolver and by api2.php / api.php / places-api.php.
   API-coverage C6, `.claude/api-coverage-2026-08-28.md` §4.1. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'api_tokens.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'read_rate_limit.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'html_sanitizer.php';
/* $PAGE_OPTION_SCHEMA + ptSanitisePageOptions() — the SAME allow-list
   manage/print-templates.php saves against (rule #35; #1767 remainder P3
   extracted it out of that page specifically so this endpoint could share
   it instead of forking a third copy). */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'print_template_schema.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'pdf_renderer.php';
/* printUsageResolveCcliLicence() / printUsageSongCcliNumber() /
   printUsageCcliNoticeText() / printUsageLog() — #1767 remainder P5, §6.
   Side-effect-free to require (mirrors pdf_renderer.php's own discipline). */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'print_usage.php';

/* ---- Caps (400 on breach) — constants at top of file, per the plan §3.1 ----
   #1767 remainder P6 (§4.4) — batch: a whole set-list/songbook as ONE PDF.
   PDF_MAX_DOCUMENTS raised from the P3-P5 placeholder of 1 to a sane batch
   ceiling (a large set list or songbook, not "the whole corpus" — rule #17's
   spirit applied to a PDF instead of a JSON corpus). PDF_MAX_TOTAL_BYTES
   raised PROPORTIONATELY (not to `50 * PDF_MAX_BODY_HTML_BYTES` ≈ 25 MiB —
   that would let a crafted batch alone approach the renderer's memory
   budget) and stays BOUNDED at 8 MiB so a batch can never OOM the shared-
   hosting renderer regardless of how many documents are packed under that
   ceiling; the per-document cap (unchanged) still applies to each one. */
const PDF_MAX_DOCUMENTS        = 50;            // #1767 remainder P6 — whole set-list/songbook as one PDF
const PDF_MAX_BODY_HTML_BYTES  = 512 * 1024;    // per-document bodyHtml (unchanged)
const PDF_MAX_CSS_BYTES        = 64 * 1024;
const PDF_MAX_TOTAL_BYTES      = 8 * 1024 * 1024; // whole raw POST body — bounded, never proportional to the full 50-doc ceiling
const PDF_MAX_FILENAME_LEN     = 100;
const PDF_ALLOWED_MODES        = ['song', 'batch', 'preview'];

/**
 * Emit a JSON error body with the given HTTP status and stop.
 *
 * @return never
 */
function _pdfFailJson(int $status, string $message): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
    }
    echo json_encode(['error' => $message]);
    exit;
}

/**
 * Resolve the caller's identity — Bearer token first (native apps), then the
 * `/manage/` cookie session — mirroring `song-media.php`'s
 * `_songMedia_resolveViewer()` two-step ordering, but delegating the Bearer
 * half to the ONE shared verification core instead of a second, forked
 * `tblApiTokens` query (rule #22; API-coverage C6, §4.1 — see the file
 * doc-block's "AUTH" section).
 *
 * ELI5: a browser proves who you are with a cookie it sends automatically; a
 * native app has no cookie jar, so it proves who it is with an explicit
 * `Authorization: Bearer <token>` header instead. This tries that header
 * FIRST and, only when it's absent or doesn't verify, falls back to EXACTLY
 * the pre-existing `isAuthenticated()`/`getCurrentUser()` cookie check this
 * endpoint always used — so an existing `/manage/` browser request that
 * carries no `Authorization` header runs through the SAME code path it
 * always did, byte-identical.
 *
 * Side-effect-free (never calls `_pdfFailJson()` / sets a response code) so
 * both the `?ping=1` probe and the main POST flow below can share it and
 * decide separately how to react to a miss. Returns the same lowercase-key
 * shape `getCurrentUser()` returns (`id`/`username`/`display_name`/`role`/
 * `email`) either way, so every downstream `$currentUser['id']` read is
 * unaffected by which path authenticated the request.
 *
 * @return array{id:int,username:string,display_name:?string,role:string,email:?string}|null
 * @see appWeb/public_html/song-media.php                          _songMedia_resolveViewer() — the two-step this mirrors
 * @see appWeb/public_html/includes/api_tokens.php                 apiTokenResolveBearerUser() — the ONE shared core (rule #22)
 */
function _pdfResolveAuthenticatedUser(): ?array
{
    $bearerUser = apiTokenResolveBearerUser(getDbMysqli());
    if ($bearerUser !== null) {
        return $bearerUser;
    }
    if (!isAuthenticated()) {
        return null;
    }
    return getCurrentUser();
}

/**
 * `?ping=1` GET mode — no body either way. Lets a future client decide
 * whether to show a "Download PDF" affordance without hardcoding roles
 * client-side (plan §3.1). Handled FIRST, before the POST-only method gate
 * below, since it is the one legitimate non-POST request shape. Auth here is
 * the SAME Bearer-then-cookie resolution as the main POST flow below, so a
 * native app's feature-detect probe reflects the SAME identity its real
 * request would use.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['ping'])) {
    if (_pdfResolveAuthenticatedUser() === null) {
        http_response_code(401);
        exit; // no body — mirrors qr.php's "status only" failure shape
    }
    if (!ihymnsPdfEngineAvailable()) {
        http_response_code(503);
        exit;
    }
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    _pdfFailJson(405, 'POST required (or GET ?ping=1).');
}

/* ---- Auth (401) — Bearer-then-cookie via _pdfResolveAuthenticatedUser(),
   401 JSON, NOT requireAuth() ----
   requireAuth() 302-redirects to /manage/login, which a JSON/fetch caller
   cannot usefully consume as "unauthenticated" — mirrors the proven
   manage/places-api.php pattern (an explicit auth check → 401 JSON) rather
   than the redirect-shaped helper most FULL ADMIN PAGES use. A Bearer
   request that resolves to a valid user is authenticated exactly as a
   cookie session would be (see the file doc-block's "AUTH" section); an
   invalid/absent Bearer with no cookie session falls through to the SAME
   401 this endpoint always returned. */
$currentUser = _pdfResolveAuthenticatedUser();
if (!$currentUser) {
    _pdfFailJson(401, 'Authentication required.');
}
/* Deliberately NO userHasEntitlement() check here — see the file doc-block's
   "GATE" section for why that is the considered design, not an omission. */

/* ---- CSRF (403) ---- */
$csrfHeaderToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!validateCsrfRequest(is_string($csrfHeaderToken) ? $csrfHeaderToken : null)) {
    _pdfFailJson(403, 'CSRF check failed — please retry.');
}

/* ---- Rate limit (429) — fail-open; PDF renders are heavy, mirrors qr.php ---- */
try {
    enforceReadRateLimitKeyed('pdf', 20);
} catch (\Throwable $e) {
    /* Never let the limiter itself break the endpoint. */
}

/* ---- Parse + validate + cap (400) ---- */
$raw = (string)file_get_contents('php://input');
if ($raw === '' || strlen($raw) > PDF_MAX_TOTAL_BYTES) {
    _pdfFailJson(400, 'Request body missing or too large.');
}
$body = json_decode($raw, true);
if (!is_array($body)) {
    _pdfFailJson(400, 'Malformed JSON body.');
}

$mode = (string)($body['mode'] ?? 'song');
if (!in_array($mode, PDF_ALLOWED_MODES, true)) {
    /* Any OTHER value is a deliberate, documented 400, never an unexpected
       500 — 'song' (one document), 'batch' (#1767 remainder P6 — up to
       PDF_MAX_DOCUMENTS), and 'preview' (the admin editor's sample render)
       are the only three recognised shapes. */
    _pdfFailJson(400, "Unsupported mode '{$mode}'.");
}

$documentsRaw = $body['documents'] ?? null;
if (!is_array($documentsRaw) || $documentsRaw === [] || count($documentsRaw) > PDF_MAX_DOCUMENTS) {
    /* #1767 remainder P6 — this cap check runs BEFORE any sanitise/adapt/
       convert work (and long before ihymnsPdfRender()), so a batch that
       breaches it never reaches the renderer at all. */
    _pdfFailJson(400, 'documents must be a non-empty array of at most ' . PDF_MAX_DOCUMENTS . ' item(s).');
}
foreach ($documentsRaw as $d) {
    if (!is_array($d) || !isset($d['bodyHtml']) || !is_string($d['bodyHtml']) || $d['bodyHtml'] === '') {
        _pdfFailJson(400, 'Each document requires a non-empty bodyHtml string.');
    }
    if (strlen($d['bodyHtml']) > PDF_MAX_BODY_HTML_BYTES) {
        _pdfFailJson(400, 'bodyHtml exceeds the per-document size cap.');
    }
}

$cssRaw = (string)($body['css'] ?? '');
if (strlen($cssRaw) > PDF_MAX_CSS_BYTES) {
    _pdfFailJson(400, 'css exceeds the size cap.');
}

$pageOptionsRaw = $body['pageOptions'] ?? null;
$filenameRaw    = (string)($body['filename'] ?? 'ihymns-print');

/* #1897 W3 — the OPTIONAL set-list this batch was printed FOR (the batch caller
   js/modules/setlist.js sends `list.id`). Sanitised ONCE here and ridden into
   every qualifying document's printUsageLog() meta below. Charset mirrors the
   client-minted-setlist-id sanitiser at api.php (the setlist_share path); length
   mirrors printUsageLog()'s own 100-char clamp. Empty → NULL (no attribution). */
$pdfSetlistId = preg_replace('/[^a-zA-Z0-9_\-]/', '', mb_substr(trim((string)($body['setlistId'] ?? '')), 0, 100));

/* ---- Sanitise (P2's ONE sanitiser — reused, never forked, plan §5.2) ---- */
$sanitisedDocs = [];
foreach ($documentsRaw as $d) {
    /* #1767 remainder P7 (§7.1 point 4) — sanitise with the 'layout'
       profile, not 'print'. 'layout' is a STRICT superset of 'print' (every
       tag/attribute/class-pattern/img-src shape 'print' allows, 'layout'
       also allows — includes/html_sanitizer.php's two profiles, §5.1), so
       this is "one profile at the endpoint, tightest at upload" (the plan's
       own words): a plain block-only bodyHtml (no custom layout) sanitises
       byte-identically either way, while a custom-layout-wrapped bodyHtml
       (whose wrapper tags — h2, table, header, … — the narrower 'print'
       profile would have stripped) now survives correctly. The ACTUAL
       security boundary for an uploaded layout is upload-time
       (includes/print_custom_layout.php::printCustomLayoutSave(), ALSO
       'layout') — this is defence-in-depth re-sanitisation of whatever the
       client claims to have already merged, exactly like the 'print'
       profile always was for a plain template (§5.2 — "the POST body is
       attacker-writable independently of whether print.js ever ran"). */
    $sanHtml = ihymnsSanitizeHtml((string)$d['bodyHtml'], 'layout');
    $rawMeta = is_array($d['meta'] ?? null) ? $d['meta'] : [];
    /* Metadata strings are NOT rendered as markup (they feed mPDF's PDF
       Info dictionary via pdf_renderer.php's own _pdfSanitiseMetaString()),
       but are still attacker-influenced request data, so cap + strip control
       characters here too rather than trust a bare (string) cast further down. */
    $safeMeta = [
        'songId' => isset($rawMeta['songId']) ? mb_substr(trim((string)$rawMeta['songId']), 0, 40) : null,
        'title'  => isset($rawMeta['title'])  ? mb_substr(trim((string)$rawMeta['title']), 0, 200) : null,
        'lang'   => isset($rawMeta['lang'])   ? mb_substr(trim((string)$rawMeta['lang']), 0, 35) : null,
        'dir'    => (($rawMeta['dir'] ?? '') === 'rtl') ? 'rtl' : 'ltr',
        /* #1767 remainder P4 (§4.3) — feeds ONLY the optional `runningHeader`
           page option's 'titleBook' mode (includes/pdf_renderer.php
           ::_pdfRunningHeaderHtml()); never rendered as markup. */
        'book'   => isset($rawMeta['book'])   ? mb_substr(trim((string)$rawMeta['book']), 0, 120) : null,
    ];
    $sanitisedDocs[] = ['bodyHtml' => $sanHtml, 'meta' => $safeMeta];
}
$sanCss = ihymnsSanitizeCss($cssRaw);

/* Re-validate pageOptions against the SAME $PAGE_OPTION_SCHEMA the editor's
   save path coerces against ($PAGE_OPTION_SCHEMA / ptSanitisePageOptions()
   both come from includes/print_template_schema.php, required above) — a
   crafted POST cannot smuggle an unrecognised page option into the PDF
   pipeline any more than it could into a saved template row. */
$pageOptions = ptSanitisePageOptions($pageOptionsRaw, $PAGE_OPTION_SCHEMA) ?? [];

/* ---- Engine availability (503) — checked AFTER the cheap local sanitise
   step but BEFORE the one step that can make an outbound call (the QR-image
   adapter inside ihymnsPdfRender(), §4.5) — mirrors qr.php's own shape
   (input validation, THEN the config/dormant check, THEN the one outbound
   call). A dormant install therefore never dials CueRCode for nothing. ---- */
if (!ihymnsPdfEngineAvailable()) {
    _pdfFailJson(503, 'PDF engine is not installed on this server.');
}

/* ---- §6.3 — server-ENFORCED CCLI notice, PER DOCUMENT (#1767 remainder
   P5 single-song, extended to the WHOLE batch by P6) ----
   The licence is resolved ONCE (the same authenticated caller for every
   document in the request) but the SONG-specific half — does THIS
   document's song carry a CCLI number to attribute — is re-resolved for
   EACH document, never assumed from document 0: a batch's songs can, and
   routinely will, differ in whether they carry a CCLI number at all. A
   single-document 'song'/'preview' request runs this exact same loop body
   once, so it degrades to byte-identical P5 behaviour. Computed BEFORE the
   convert step so each document's own notice can ride into
   ihymnsPdfRender() as that document's ccliNotice — this is what makes it
   ENFORCED rather than advisory: the notice comes from the caller's OWN
   re-resolved licence (printUsageResolveCcliLicence(), NEVER trusting
   anything the POST claimed) and the song's REAL CCLI number
   (printUsageSongCcliNumber(), a fresh DB read keyed on the sanitised
   meta.songId — never parsed out of the POSTed bodyHtml), so a client
   cannot strip it by omitting/editing the HTML it sends. Empty for every
   document without a qualifying licence or a song with no CCLI number — a
   verified no-op for the overwhelming majority of renders/documents. */
$pdfMeta    = $sanitisedDocs[0]['meta'] ?? [];
$pdfLicence = printUsageResolveCcliLicence((int)($currentUser['id'] ?? 0));
foreach ($sanitisedDocs as $pdfDocIdx => $pdfDoc) {
    $ccliNotice     = '';
    $pdfDocCcliNumber = '';
    if ($pdfLicence !== null) {
        $pdfDocSongId = trim((string)($pdfDoc['meta']['songId'] ?? ''));
        if ($pdfDocSongId !== '' && preg_match('/^[A-Za-z0-9_-]{1,32}$/', $pdfDocSongId)) {
            $pdfDocCcliNumber = printUsageSongCcliNumber($pdfDocSongId);
            if ($pdfDocCcliNumber !== '') {
                $ccliNotice = printUsageCcliNoticeText($pdfDocCcliNumber, (string)($pdfLicence['key'] ?? ''));
            }
        }
    }
    /* 'ccliNumber' rides ONLY into the post-render logging loop below —
       includes/pdf_renderer.php never reads it (only 'bodyHtml'/'meta'/
       'ccliNotice' — the one-renderer guard's server-chrome allow-list is
       unaffected by an extra array key it never inspects). */
    $sanitisedDocs[$pdfDocIdx]['ccliNotice'] = $ccliNotice;
    $sanitisedDocs[$pdfDocIdx]['ccliNumber'] = $pdfDocCcliNumber;
}

/* ---- Convert (adapt + render happen INSIDE pdf_renderer.php — never here;
   this file must never grow a `case` on a block type, rule #35 / the
   one-renderer invariant) ---- */
$pdfBytes = ihymnsPdfRender($sanitisedDocs, $sanCss, $pageOptions, [
    'meta' => $pdfMeta,
]);
if ($pdfBytes === null) {
    _pdfFailJson(503, 'PDF render failed.');
}

/* ---- §6.2 — CCLI print-usage LOG, PER DOCUMENT (#1767 remainder P5
   single-song, extended to the WHOLE batch by P6) ----
   ONLY when the client supplied a `copies` count (the picker only ever
   sends one when the copies prompt was shown — an absent field means no
   prompt was shown, so nothing is logged here either; for a batch, ONE
   prompt covers the WHOLE set, so the SAME copies count is what every
   qualifying document's row carries). Each document gets its OWN
   `tblSongUsageEvents` row, and ONLY when THAT document's own song
   resolves to a qualifying CCLI number (the `ccliNumber` computed in the
   notice loop above, from a server-side re-check — never the client's
   claim) — a song with no CCLI number to attribute is skipped, never
   logged against nothing. printUsageLog() ALSO re-resolves the licence
   itself on every call regardless (its own internal gate,
   includes/print_usage.php — never trusted from here), so a forged
   `copies` on an unlicensed account still writes nothing even if this
   loop's own pre-check were somehow bypassed. Runs AFTER a successful
   render, on a best-effort basis: a logging failure must never turn a
   successful PDF into a failed request — the file the user is waiting for
   still streams below regardless of how many (if any) rows got written. */
$pdfCopiesRaw = $body['copies'] ?? null;
if ($pdfCopiesRaw !== null) {
    /* Defence-in-depth (security audit 2026-08-29, F3): clamp copies to
       1..10000 at the endpoint. printUsageLog() already clamps to the same
       range, but bounding here keeps a crafted negative/huge value out of the
       log call entirely — it only ever affects the caller's own org's CCLI
       usage report, so this is hardening, not a live cross-tenant issue. */
    $pdfCopiesRaw = max(1, min(10000, (int)$pdfCopiesRaw));
    foreach ($sanitisedDocs as $pdfDoc) {
        $pdfLogSongId = trim((string)($pdfDoc['meta']['songId'] ?? ''));
        $pdfLogCcli   = (string)($pdfDoc['ccliNumber'] ?? '');
        if ($pdfLogSongId === '' || $pdfLogCcli === '' || !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $pdfLogSongId)) {
            continue; // this document doesn't qualify — no licence, no CCLI number on the song, or an invalid songId
        }
        try {
            printUsageLog(
                (int)($currentUser['id'] ?? 0),
                $pdfLogSongId,
                (int)$pdfCopiesRaw,
                [
                    'surface'    => 'pdf',
                    'templateId' => isset($body['templateId']) ? (int)$body['templateId'] : null,
                    /* #1897 W3 — the set-list this batch was printed for (every
                       document in a batch was printed FOR the same list). */
                    'setlistId'  => ($pdfSetlistId !== '' ? $pdfSetlistId : null),
                ]
            );
        } catch (\Throwable $e) {
            error_log('[print-pdf] usage log failed: ' . $e->getMessage());
        }
    }
}

/* ---- Stream (200) ---- */
/* Content-Disposition filename: strip to a safe charset BEFORE it ever
   reaches a header() call — mirrors manage/analytics.php's CSV export
   (the house precedent for "never let an unvalidated request value land in
   Content-Disposition"). */
$safeFilename = preg_replace('/[^A-Za-z0-9_-]/', '', mb_substr($filenameRaw, 0, PDF_MAX_FILENAME_LEN));
if ($safeFilename === '' || $safeFilename === null) {
    $safeFilename = 'ihymns-print';
}
$disposition = ($mode === 'preview') ? 'inline' : 'attachment';

header('Content-Type: application/pdf');
header('Content-Disposition: ' . $disposition . '; filename="' . $safeFilename . '.pdf"');
header('Content-Length: ' . strlen($pdfBytes));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo $pdfBytes;
exit;
