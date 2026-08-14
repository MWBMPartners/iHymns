<?php

declare(strict_types=1);

/**
 * iHymns — Song Editor v2 SHELL (#1200, Phase 5)
 *
 * The real v2 editor: a song-list sidebar (api2.php load_index) + a tabbed
 * editor that loads songs on click WITHOUT a full page reload — each switch
 * tears down the current tab instances, re-hydrates the reactive store from
 * load_song, and remounts the tabs for the new song. New / Delete / Import /
 * Paste & Reflow / Export live in the toolbar. Every edit still saves instantly,
 * atomically + granularly through the clean v2 API (api2.php). This lives
 * ALONGSIDE the legacy editor until per-phase cutover.
 *
 * Open: /manage/editor/editor2.php   (optionally ?song=<SongId> to deep-link)
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';

if (!isAuthenticated()) {
    header('Location: /manage/login.php');
    exit;
}
$u = getCurrentUser();
if (!$u || !hasRole((string)($u['role'] ?? ''), 'editor')) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="en"><body><h1>403 — editor access required</h1></body></html>';
    exit;
}

/* CSRF token api2.php validates (X-CSRF-Token header), emitted as a <meta> for
   the v2 api-client. */
$csrf   = csrfToken();

/* `?song=` — and `?open=` as an alias (#1680).
   v1 keeps that alias deliberately (editor.js:6234): /manage/revisions linked
   with `?open=` for its whole life while the editor read only `?song=`, so
   "Open in editor" silently did nothing (#1623). The alias exists so a
   bookmarked or pasted link from that era still works instead of failing the
   same invisible way — which means v2 needs it too, or the flip re-breaks
   exactly the links the alias was created to rescue. */
$songId = preg_replace('/[^A-Za-z0-9\-]/', '', (string)($_GET['song'] ?? $_GET['open'] ?? ''));
/* #1783 — ?duplicate=<sourceId>: cold-load entry to duplicate a song (a handled
   param; no in-tree emitter beyond the editor's own button yet — safe under
   rule #33, the reverse is the bug). Same sanitiser as ?song=. The confirm in
   runDuplicate() converts a forced top-level navigation into intent, so this
   GET-triggered write can't silently mint rows in a signed-in curator's name. */
$duplicateSource = preg_replace('/[^A-Za-z0-9\-]/', '', (string)($_GET['duplicate'] ?? ''));

/**
 * Missing-Numbers prefill: `?songbook=<ABBR>` + `?number=N` or `#number=N` (#1680).
 *
 * ELI5: a link can say "start a new song, in this book, at this number".
 *
 * `manage/missing-numbers.php:236` emits exactly this shape, using the FRAGMENT
 * form. v1 honours both forms (editor.js:6257-6262). Without it here, flipping
 * `/manage/editor/` to v2 would make that page's "Open in editor" button load
 * the editor and do nothing at all — no prefill, no draft, no error. That is
 * #1623's failure re-created on a different button, which is precisely the
 * class this cutover keeps producing.
 *
 * The fragment is NOT sent to the server by the browser, so the number can only
 * be read client-side; the songbook is read here so an invalid one never
 * reaches the JS. Abbreviation charset is the SongId-prefix charset (rule #27):
 * alphanumeric, <= 10.
 */
$prefillBook = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['songbook'] ?? '')));
if (strlen($prefillBook) > 10) { $prefillBook = ''; }
$prefillNum  = (string)($_GET['number'] ?? '');
$prefillNum  = ctype_digit($prefillNum) ? $prefillNum : '';

/**
 * `?tab=` deep-link (#1628 item 1).
 *
 * ELI5: a link can say which tab to open, not just which song.
 *
 * WHY THIS IS NOT OPTIONAL FOR THE #1601 FLIP
 * -------------------------------------------
 * `manage/revisions.php` links "Open in editor" as
 * `/manage/editor/?song=<id>&tab=history`. v1 honours `tab` (editor.js:6250);
 * this shell read only `song` and ignored `tab` entirely.
 *
 * That link was JUST fixed in #1623 — it previously said `?open=`, which the
 * editor has no handler for, so it was silently ignored: the editor loaded fine
 * and simply never selected anything. Flipping the default to v2 without `tab`
 * support would reintroduce the identical failure, in the identical invisible
 * way, on the identical button. Hence it ships with the parity work rather than
 * as a follow-up.
 *
 * The map is an exact allow-list, not a sanitised passthrough: the value is
 * interpolated into a CSS selector below, and an allow-list means the selector
 * can only ever be one of eight literals this file wrote itself.
 *
 * `history` is v1's name for what v2 calls the Revisions tab — accepted as an
 * alias so existing links keep working, alongside every real pane name so
 * `?tab=metadata` and friends work too.
 */
$_ED2_TABS = [
    'structure' => 'structure',
    'metadata'  => 'metadata',
    'credits'   => 'credits',
    'links'     => 'links',
    'tags'      => 'tags',
    'media'     => 'media',
    'preview'   => 'preview',
    'revisions' => 'revisions',
    'history'   => 'revisions',   // v1 alias — revisions.php + any bookmarked link
];
$tabParam   = strtolower(trim((string)($_GET['tab'] ?? '')));
$initialTab = $_ED2_TABS[$tabParam] ?? '';

/* External-link type registry for the Links tab, shipped via window._iHymnsLinkTypes. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'external_link_helpers.php';
$linkTypesForSong = loadExternalLinkTypesFor(getDbMysqli(), 'song');

/**
 * #1741 P5b — the recording/release/product external-ID vocabulary for the
 * Metadata tab's external-ids-panel.js, shipped the SAME way the Links tab's
 * window._iHymnsLinkTypes is (rule #35's "server-derive the vocab, no second
 * list" applied here): the panel builds its add-row dropdown from THIS
 * object, never from a hand-typed provider list of its own.
 *
 * ELI5: this is the list of "Spotify / ISRC / MusicBrainz / …" choices the
 * "Add external id" dropdown offers — generated from the SAME registry
 * api2.php's song_external_id_add validates against, so the dropdown can
 * never offer a provider the server would then 422 on.
 *
 * ONLY `label` + `scope` are shipped — deliberately NOT the PCRE `validate`
 * patterns (media_identifiers.php's RECORDING_EXTERNAL_ID_TYPES also carries
 * those). PHP's PCRE and JavaScript's RegExp use different delimiter/flag
 * conventions; a half-translated pattern shipped to the client would be a
 * SECOND, silently-divergent validator. The server's 422 on
 * mediaIdentifierValidateValue() stays the ONE shape check — the client only
 * needs to know WHAT to offer and WHAT to call it, never whether a value is
 * valid before asking the server.
 *
 * @link .claude/catalogue-1741-P5-plan.md §2.3
 * @link appWeb/public_html/includes/media_identifiers.php mediaIdentifierRecordingTypes() / RECORDING_EXTERNAL_ID_TYPES
 * @link appWeb/public_html/manage/editor/v2/external-ids-panel.js the sole consumer
 */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'media_identifiers.php';
$recordingIdTypesForJs = array_map(
    static fn(array $t): array => ['label' => $t['label'], 'scope' => $t['scope']],
    mediaIdentifierRecordingTypes()
);

/* #1769 P4 — the licence vocabulary for the Metadata tab's rights-panel.js,
   shipped the SAME way the two registries above are (rule #35's "server-derive
   the vocab, no second list"): the panel builds its two rights pickers from
   THIS map (key => {label, description}), never a hand-typed licence list, so
   it can never offer a key api2.php's rights branch would then 422 on. On an
   un-migrated install licenceTypesForPicker() falls back to the byte-exact P1
   seeds (licence_registry.php), so the pickers still populate. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'licence_registry.php';
$licenceTypesForJs = licenceTypesForPicker(getDbMysqli());
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
    <title>Song Editor v2</title>
    <?php
    /* #1676 — Bootstrap CSS from the shared emitter, NOT hardcoded here.
       ELI5: this page used to type the Bootstrap address itself, and had quietly
       lost the "check this file hasn't been tampered with" attribute.
       Detail: these two <link>s were pinned to 5.3.3 with NO `integrity`, while
       APP_CONFIG says 5.3.6 and every page using head-libs.php loads that with
       SRI. Third-party CSS inside an authenticated admin session with no
       integrity check is the #1587 hazard — and #1601 promotes THIS page to the
       default song editor, so it would have become the most-used admin page in
       the app while being the least protected. */
    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'bootstrap_assets.php';
    echo ihymns_bootstrap_css_links();
    $_pubRoot = dirname(__DIR__, 2);
    ?>
    <link rel="stylesheet" href="/css/app.css?v=<?= filemtime($_pubRoot . '/css/app.css') ?>">
    <link rel="stylesheet" href="/css/admin.css?v=<?= filemtime($_pubRoot . '/css/admin.css') ?>">
    <!-- Accessibility modes (#1643). This shell includes admin-theme-init.php
         below, so it DOES stamp data-ihymns-contrast / data-ihymns-cvd on <html>
         — without this link it stamps an intent it then ships no CSS to honour.
         Must stay after admin.css so the high-contrast !important rules win. -->
    <link rel="stylesheet" href="/css/accessibility.css?v=<?= filemtime($_pubRoot . '/css/accessibility.css') ?>">
    <?php
    $themeInit = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-theme-init.php';
    if (is_file($themeInit)) { include $themeInit; }
    ?>
    <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body class="p-0">
    <?php
    /* Slim editor navbar (Issue A, #1856) — adapted from the legacy editor's
       (manage/editor/index.php:170-269) minus its Save/Revisions/Import
       actions, which live in the v2 toolbar inside <main> instead.
       Deliberately NOT admin-nav.php: the editor is a focused full-screen
       tool (same call the legacy editor made); .navbar-editor is the shared
       class the admin.css narrow-viewport rules (<576px) already compact. */
    ?>
    <nav class="navbar navbar-editor d-flex align-items-center">
        <a class="navbar-brand d-flex align-items-center gap-2" href="/manage/"
           title="Back to Admin Dashboard">
            <i class="bi bi-music-note-beamed"></i>
            <span class="navbar-brand-text">iHymns Song Editor</span>
        </a>
        <div class="d-flex align-items-center gap-2 me-auto ms-2">
            <a href="/manage/" class="btn btn-sm btn-outline-secondary" title="Back to Admin Dashboard">
                <i class="bi bi-speedometer2 me-1"></i>Dashboard
            </a>
            <a href="/" class="btn btn-sm btn-outline-secondary" title="Back to the iHymns app home">
                <i class="bi bi-house me-1"></i>Home
            </a>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php if (hasRole((string)($u['role'] ?? ''), 'admin')): ?>
            <a href="/manage/users" class="btn btn-sm btn-outline-secondary me-1" title="User management">
                <i class="bi bi-people me-1"></i>Users
            </a>
            <?php endif; ?>
            <span class="text-muted small d-none d-md-inline me-1"><?= htmlspecialchars((string)($u['display_name'] ?? $u['username'] ?? '')) ?></span>
            <a href="/manage/logout" class="btn btn-sm btn-outline-secondary" title="Sign out">
                <i class="bi bi-box-arrow-right me-1"></i>Logout
            </a>
        </div>
    </nav>
    <?php
    /* #1845 — the song-list aside becomes a Bootstrap 5.3 responsive
       offcanvas (`.offcanvas-lg`): in-flow content at >=992px (unchanged from
       before), a slide-in drawer below it (opened by the hamburger button in
       the header rail, see below). The MOUNT target for sidebar.js stays the
       INNER `#v2-sidebar` div, not the offcanvas panel itself — sidebar.js
       does `container.className = 'd-flex flex-column h-100'`, which would
       strip the `.offcanvas-lg`/`.offcanvas-start` classes right back off if
       it ran on the panel node. Sizing/background/cursor that used to be
       inline style="" here now live in admin.css's `#v2-shell`/`#v2-sidebar-
       panel`/`#v2-grip` rules (search "v2 SONG EDITOR SHELL"). */
    ?>
    <div id="v2-shell" class="d-flex">
        <aside id="v2-sidebar-panel" class="offcanvas-lg offcanvas-start" tabindex="-1" aria-labelledby="v2-sidebar-title">
            <div class="offcanvas-header border-bottom">
                <h2 class="offcanvas-title h6" id="v2-sidebar-title"><i class="bi bi-music-note-list me-1" aria-hidden="true"></i>Songs</h2>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#v2-sidebar-panel" aria-label="Close song list"></button>
            </div>
            <div class="offcanvas-body">
                <div id="v2-sidebar"></div>
            </div>
        </aside>
        <div id="v2-grip" class="border-end border-start d-none d-lg-block" title="Drag to resize"></div>

        <main class="flex-grow-1 overflow-auto p-3" style="min-width: 0;">
            <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                <?php /* #1845 — below lg the song list is the offcanvas drawer above,
                         not in-flow content; this is its only way in. Bootstrap's own
                         data-bs-toggle/data-bs-target wiring (no custom JS needed to
                         OPEN it — only to close it programmatically, see the boot
                         script's hideSidebarPanel() below). */ ?>
                <button type="button" class="btn btn-sm btn-outline-secondary d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#v2-sidebar-panel" aria-controls="v2-sidebar-panel"><i class="bi bi-list" aria-hidden="true"></i><span class="ms-1">Songs</span></button>
                <h1 class="h5 mb-0"><i class="bi bi-music-note-list me-2"></i>Song Editor <span class="badge bg-info">v2</span></h1>
                <div class="ms-auto d-flex gap-2 flex-wrap">
                    <?php /* #1846 — manual Save: flushes every tab's pending DEBOUNCED
                             writes early + confirms once they've settled. Autosave keeps
                             working unchanged underneath — this is additive, not a
                             replacement. Starts disabled: it's meaningless with no song
                             loaded, and is enabled in loadSong() alongside the Duplicate
                             button reveal a few lines below. */ ?>
                    <button id="v2-save-btn" type="button" class="btn btn-sm btn-outline-success" disabled><i class="bi bi-check2-all me-1" aria-hidden="true"></i>Save</button>
                    <button id="v2-new-btn" type="button" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>New</button>
                    <!-- #1783 — Duplicate the open song as a starting point for a new
                         songbook. Hidden until a song is loaded (shown in loadSong). -->
                    <button id="v2-duplicate-btn" type="button" class="btn btn-sm btn-outline-primary d-none" title="Duplicate this song as a starting point for a new songbook"><i class="bi bi-files me-1"></i>Duplicate</button>
                    <a href="/manage/editor/import2.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-upload me-1"></i>Import</a>
                    <button id="v2-reflow-btn" type="button" class="btn btn-sm btn-outline-secondary"><i class="bi bi-text-paragraph me-1"></i>Reflow</button>
                    <div class="dropdown">
                        <button id="v2-export-btn" type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-download me-1"></i>Export</button>
                        <ul class="dropdown-menu dropdown-menu-end" id="v2-export-menu"></ul>
                    </div>
                    <button id="v2-delete-btn" type="button" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Delete</button>
                    <?php /* #1601 — MUST carry ?legacy=1: /manage/editor/ now redirects
                             here, so a bare link would bounce straight back and read as
                             a broken button. */ ?>
                    <a href="/manage/editor/?legacy=1" class="btn btn-sm btn-outline-secondary">Legacy</a>
                </div>
            </div>
            <div id="v2-status" class="alert alert-secondary py-2 small" role="status">Loading…</div>

            <!-- Bulk-actions bar (shown when songs are selected in the sidebar's Select mode) -->
            <div id="v2-bulk-bar" class="alert alert-info py-2 px-3 d-none d-flex align-items-center gap-2 flex-wrap">
                <span id="v2-bulk-count" class="small fw-semibold"></span>
                <button id="v2-bulk-verify" type="button" class="btn btn-sm btn-outline-secondary"><i class="bi bi-check2-circle me-1"></i>Mark verified</button>
                <button id="v2-bulk-tag" type="button" class="btn btn-sm btn-outline-secondary"><i class="bi bi-tag me-1"></i>Add tag…</button>
                <button id="v2-bulk-untag" type="button" class="btn btn-sm btn-outline-secondary"><i class="bi bi-tag-fill me-1"></i>Remove tag…</button>
                <button id="v2-bulk-clear" type="button" class="btn btn-sm btn-outline-secondary ms-auto">Clear</button>
            </div>

            <?php /* #1845 — flex-nowrap + overflow-x-auto: eight icon+label tabs
                     wrapped to 3 rows on a phone-width nav-tabs before this;
                     now they're one horizontally-scrollable row instead. */ ?>
            <ul class="nav nav-tabs mb-3 flex-nowrap overflow-x-auto" role="tablist">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pane-structure" type="button"><i class="bi bi-list-ol me-1"></i>Structure</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-metadata" type="button"><i class="bi bi-info-circle me-1"></i>Metadata</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-credits" type="button"><i class="bi bi-people me-1"></i>Credits</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-links" type="button"><i class="bi bi-link-45deg me-1"></i>Links</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-tags" type="button"><i class="bi bi-tags me-1"></i>Tags</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-media" type="button"><i class="bi bi-collection-play me-1"></i>Media</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-preview" type="button"><i class="bi bi-eye me-1"></i>Preview</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-revisions" type="button"><i class="bi bi-clock-history me-1"></i>Revisions</button></li>
            </ul>
            <div class="tab-content">
                <div class="tab-pane fade show active" id="pane-structure"><div id="v2-structure"></div><div id="v2-arrangement" class="mt-3"></div></div>
                <div class="tab-pane fade" id="pane-metadata"><div id="v2-metadata"></div></div>
                <div class="tab-pane fade" id="pane-credits"><div id="v2-credits"></div></div>
                <div class="tab-pane fade" id="pane-links"><div id="v2-links"></div><div id="v2-counterparts" class="mt-4"></div></div>
                <div class="tab-pane fade" id="pane-tags"><div id="v2-tags"></div></div>
                <div class="tab-pane fade" id="pane-media"><div id="v2-media"></div></div>
                <div class="tab-pane fade" id="pane-preview"><div id="v2-preview"></div></div>
                <div class="tab-pane fade" id="pane-revisions"><div id="v2-revisions"></div></div>
            </div>
        </main>
    </div>

    <!-- New song modal -->
    <div class="modal fade" id="v2-new-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h6"><i class="bi bi-plus-lg me-1"></i>New song</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label small mb-1" for="v2-new-songbook">Songbook</label>
                        <select class="form-select form-select-sm" id="v2-new-songbook"></select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-1" for="v2-new-title">Title</label>
                        <input type="text" class="form-control form-control-sm" id="v2-new-title" placeholder="Song title" maxlength="500">
                    </div>
                    <div id="v2-new-err" class="text-danger small"></div>
                    <p class="text-muted small mb-0">The server assigns the canonical id (<code>&lt;ABBR&gt;-NNNNNN</code>).</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-sm btn-primary" id="v2-new-create">Create</button>
                </div>
            </div>
        </div>
    </div>

    <?php /* Bootstrap JS is emitted ONCE, by admin-footer.php below (#1676's
             shared emitter) — re-emitting here would double-load the bundle
             and double-register its delegated data-API listeners (#1856). */ ?>

    <!-- Shared external-links modules (#833/#845) — classic globals the Links tab reuses. -->
    <script>window._iHymnsLinkTypes = <?= json_encode($linkTypesForSong, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
    <script src="/js/modules/external-link-detect.js"></script>
    <script src="/js/modules/external-links-editor.js"></script>

    <!-- #1741 P5b — recording/release/product external-ID vocabulary (slug -> {label,scope}
         only, no validate patterns — see the PHP-side doc-block above) for the Metadata
         tab's external-ids-panel.js "Add external id" dropdown. Same emit shape + flags as
         window._iHymnsLinkTypes just above, on purpose (one convention for "a registry map
         shipped to a classic global"). -->
    <script>window._iHymnsRecordingIdTypes = <?= json_encode($recordingIdTypesForJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>

    <!-- #1769 P4 — licence vocabulary (key -> {label,description}) for the Metadata tab's
         rights-panel.js pickers. Same emit shape + flags + "classic global registry map"
         convention as the two above. -->
    <script>window._iHymnsLicenceTypes = <?= json_encode($licenceTypesForJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>

    <!-- Place-search (geocoder) for the Composition-origin picker — window.iHymnsPlaceSearch.
         #1594 part 2 — cache-bust with filemtime like every OTHER consumer of this file
         (editor/index.php, organisations.php, songbooks.php, musicians.php, venues.php,
         works.php all do). This one script tag was the odd one out with no `?v=` at all —
         a real staleness vector: an admin with this file already cached would silently keep
         running a stale place-search.js across deploys instead of picking up the fix. -->
    <script src="/js/modules/place-search.js?v=<?= filemtime(dirname(__DIR__, 2) . '/js/modules/place-search.js') ?>"></script>

    <!-- #1594 part 2 — shared combobox keyboard + ARIA helper (window.iHymnsComboboxA11y),
         consumed by editor/v2/tags-tab.js's Tab-add-tag typeahead. This page has no
         head-libs.php (bespoke <head>, see editor/index.php's identical note), so it's
         loaded explicitly here rather than relying solely on tags-tab.js's own
         side-effect `import` (belt-and-braces: keeps the global available even if a
         future consumer on this page wants it before that module executes). -->
    <script src="/js/modules/combobox-a11y.js?v=<?= filemtime(dirname(__DIR__, 2) . '/js/modules/combobox-a11y.js') ?>"></script>

    <!-- Export serializers (reused by export.js). propresenter-export.js first (format-export reuses its ZIP writer).
         #1567 — protobuf.min.js loaded FIRST: propresenter-export.js's ProPresenter
         7+ (.pro) encoder reads the `window.protobuf` global via its getProtobuf()
         helper (mirrors the v1 editor's index.php load order, #887); v2's export.js
         (ITEMS registry, mounted below via mountExportMenu) calls into the same
         window.iHymnsProPresenter this pair exposes, so without it PP7 export
         throws "protobufjs runtime not found" the first time it's invoked.
         #1788 — pp7-proto-static.js (CSP-safe `pbjs -t static` schema) loads
         between the runtime and the exporter; the exporter prefers it over the
         old reflection descriptor whose lazy codegen the nonce CSP #117 refuses. -->
    <script src="vendor/protobuf.min.js"></script>
    <script src="protos/pp7-proto-static.js"></script>
    <script src="propresenter-export.js"></script>
    <script src="format-export.js"></script>

    <script type="module">
        import { createStore }       from './v2/store.js';
        import { editorApi }         from './v2/api-client.js';
        import { mountSidebar }      from './v2/sidebar.js';
        import { mountStructureTab } from './v2/structure-tab.js';
        import { mountArrangementEditor } from './v2/arrangement-editor.js';
        import { mountMetadataTab }  from './v2/metadata-tab.js';
        import { mountCreditsTab }   from './v2/credits-tab.js';
        import { mountLinksTab }     from './v2/links-tab.js';
        import { mountCounterpartsPanel } from './v2/counterparts-panel.js';
        import { mountTagsTab }      from './v2/tags-tab.js';
        import { mountMediaTab }     from './v2/media-tab.js';
        import { mountPreviewTab }   from './v2/preview-tab.js';
        import { mountReflowModal }  from './v2/reflow-modal.js';
        import { mountExportMenu }   from './v2/export.js';
        import { mountRevisionsTab } from './v2/revisions-tab.js';

        const byId = (id) => document.getElementById(id);
        const initialSongId = <?= json_encode($songId) ?>;
        const duplicateSource = <?= json_encode($duplicateSource) ?>;   // #1783 — ?duplicate=<sourceId>

        /* ?tab= deep-link (#1628 item 1). Consumed ONCE, after the first song
           finishes loading — the tab panes exist from page load, but their
           contents are mounted by mountTabs(), so activating earlier would show
           an empty Revisions pane and look like the feature is broken. */
        let pendingInitialTab = <?= json_encode($initialTab) ?>;
        function consumeInitialTab() {
            const want = pendingInitialTab;
            pendingInitialTab = '';                       // once only — a later song switch must not re-jump
            if (!want) { return; }
            /* `want` is one of eight literals from the PHP allow-list above, so
               this selector cannot be attacker-shaped. */
            const btn = document.querySelector('[data-bs-target="#pane-' + want + '"]');
            if (!btn || !window.bootstrap || !window.bootstrap.Tab) { return; }
            window.bootstrap.Tab.getOrCreateInstance(btn).show();
        }

        const statusEl = byId('v2-status');
        function status(msg, kind) {
            statusEl.textContent = msg;
            statusEl.className = 'alert py-2 small alert-' +
                (kind === 'danger' ? 'danger' : (kind === 'success' ? 'success' : 'secondary'));
        }
        /* Transient outcome notifications ride the app-standard bottom-right
           toast (window.iHymnsToast, loaded by admin-footer.php below); the
           #v2-status strip stays the PERSISTENT editor-state line (Loading… /
           Editing "X" / Saving…). Tab-module type vocab (danger|success|
           warning|info) maps 1:1 onto toast.js — no translation. Falls back to
           the status strip if toast.js failed to load (#1856). */
        const toast = (msg, type) => {
            if (window.iHymnsToast && typeof window.iHymnsToast.show === 'function') {
                window.iHymnsToast.show(msg, type);
            } else {
                status(msg, type);
            }
        };

        /* One store for the whole shell; slices are replaced on each song switch.
           lineTranslations / lineAnnotations (#1627 item 3) hold api2.php
           load_song's per-line enrichment rows (#1235 P3 / #1088) — a separate
           concern from `components` (the lyric content itself), same as `media`
           is separate from the song scalars. structure-tab.js's per-component
           enrichment panel (enrichment-panel.js) reads + writes these two
           slices directly. */
        const store = createStore({ song: {}, components: [], credits: {}, tags: [], links: [], media: [], lineTranslations: [], lineAnnotations: [], songbookRightsDefaults: null, pendingDuplicate: false });
        let teardowns = [];
        /* #1846 — flush functions registered by whichever mounted tabs hold a
           pending DEBOUNCED save (metadata / structure / credits / links /
           media — see each tab's own registerFlush() call). Same lifecycle as
           `teardowns` immediately above: rebuilt fresh by mountTabs() on every
           song switch, reset to empty by teardownTabs() so a remount can never
           flush a torn-down tab's stale timers. A plain injected callback
           (ctx.registerFlush), not a DOM event — rule #35/#1581 bans a new
           event-name literal for something a function reference already does. */
        let flushers = [];
        let currentSongId = null;
        let loadSeq = 0;   // monotonic token: only the latest load/delete applies (drops out-of-order results)

        function teardownTabs() {
            teardowns.forEach((fn) => { try { if (typeof fn === 'function') { fn(); } } catch (_e) {} });
            teardowns = [];
            flushers = [];   // #1846 — same reset-on-remount lifecycle as teardowns above
        }
        /* #1679 — a songbook change re-keys the SongId server-side, so the id the
           tabs, the ?song= URL and the sidebar hold is dead the instant the move
           lands. Re-open under the new id: sidebar.refresh() re-pulls the index so
           the row shows the new id, loadSong() tears the tabs down, re-hydrates the
           store and history-replaces the URL. Passed to every tab as a callback
           (metadata-tab.js is the only caller today) rather than dispatched as a
           DOM event — no event-name literal to keep in sync (rule #35 / #1581). */
        function onSongIdChange(previousId, newId) {
            if (!newId || newId === previousId) { return; }
            status('Moved songbook — this song is now ' + newId + '.', 'success');
            try { sidebar.refresh(); } catch (_e) {}
            loadSong(newId);
        }

        function mountTabs(songId) {
            /* #1679 H1 — getSongbooks is the sidebar's songbook list (the one the
               New-song modal already uses), handed to the metadata tab so its
               songbook control is a closed <select> rather than a free-text box
               whose every keystroke pause re-keyed the song. Since #1679 A2 that
               list is the REAL catalogue from load_index, so a book with no songs
               yet is still a legal move target.

               whenSongbooksReady is the other half, and the arrow alone was NOT
               it: an earlier comment here claimed the arrow made the list
               "resolve at RENDER time", which is true and useless, because
               render() only runs when the `song` store slice changes and there is
               exactly ONE production `store.set('song', …)` — immediately before
               mountTabs(). The sidebar fetches its index in parallel, so a deep
               link (?song=…) reliably mounted the tabs first and the select froze
               holding one option. sidebar.whenLoaded() resolves once the index has
               landed OR failed, and the tab re-fills its options then. */
            const ctx = {
                store, api: editorApi, songId, toast, onSongIdChange,
                getSongbooks: () => sidebar.getSongbooks(),
                whenSongbooksReady: () => sidebar.whenLoaded(),
                /* #1608 — the counterparts panel's add-by-SongId datalist reads
                   the sidebar's already-loaded slim index rather than fetching
                   its own copy (mirrors getSongbooks() above; sidebar.js's
                   getAllSongs() is the read-only accessor). */
                getSongs: () => sidebar.getAllSongs(),
                /* #1846 — a tab with a pending debounced save calls this ONCE at
                   mount time to hand back a "flush me now" function; the Save
                   button's click handler (below, wired once — not inside
                   mountTabs()) awaits every registered flusher. Pushes onto the
                   CURRENT `flushers` array by closure, so it's always the live
                   array for whichever song is presently mounted. */
                registerFlush: (fn) => { if (typeof fn === 'function') { flushers.push(fn); } },
            };
            teardowns = [
                mountStructureTab(byId('v2-structure'), ctx),
                /* #1627 item 2 — below the section cards, mirroring v1's own
                   placement (editor.js's arrangement editor sits under the
                   component list in the same Structure pane). */
                mountArrangementEditor(byId('v2-arrangement'), ctx),
                mountMetadataTab(byId('v2-metadata'), ctx),
                mountCreditsTab(byId('v2-credits'), ctx),
                mountLinksTab(byId('v2-links'), ctx),
                /* #1608 — counterparts (cross-book "same hymn" links + fuzzy
                   suggestions) mounts INTO the same Links pane, below the
                   external-links editor — one tab for "everything that
                   connects this song to other things", per the plan; no new
                   nav-tabs entry. */
                mountCounterpartsPanel(byId('v2-counterparts'), ctx),
                mountTagsTab(byId('v2-tags'), ctx),
                mountMediaTab(byId('v2-media'), ctx),
                mountPreviewTab(byId('v2-preview'), ctx),
                mountRevisionsTab(byId('v2-revisions'), ctx),
                mountReflowModal(byId('v2-reflow-btn'), ctx),
                mountExportMenu(byId('v2-export-menu'), ctx),
            ];
        }

        /* Load-on-select: tear down the current song's tabs, re-hydrate the store
           from load_song, remount for the new song. No full page reload. */
        async function loadSong(id) {
            if (!id) { return; }
            /* #1851 FIX #1 — flush every CURRENTLY-mounted tab's pending
               debounced save BEFORE tearing them down for the switch.
               teardownTabs() (called a few lines below, inside the try)
               clears each tab's debounce timers + resets `flushers` to [] —
               an edit still sitting inside its debounce window at the
               moment of a song switch was silently discarded with no
               warning. `flushers` is [] on the very first load (nothing
               mounted yet), so this is a harmless no-op then. Each flusher
               already resolves rather than rejects (see FIX #6's contract
               above every tab's flushPending()), so `.catch(() => {})` here
               is belt-and-braces, not the normal path. */
            await Promise.all(flushers.map((fn) => Promise.resolve(fn()).catch(() => {})));
            const seq = ++loadSeq;
            status('Loading…');
            try {
                const data = await editorApi.loadSong(id);
                if (seq !== loadSeq) { return; }   // a newer load/delete superseded this — drop the stale result
                teardownTabs();
                store.set('song', data.song || {});
                store.set('components', (data.components || []).map((c, i) => Object.assign({ _key: 'c' + i + '_' + (c.id || 'x') }, c)));
                store.set('credits', data.credits || {});
                store.set('tags', data.tags || []);
                store.set('links', data.links || []);
                store.set('media', data.media || []);
                /* #1627 item 3 — api2 load_song already returns these top-level
                   (see api2.php's load_song case); this shell used to drop them
                   on the floor, which is the ONE thing blocking the v2 Structure
                   tab's per-line enrichment panel from having anything to show. */
                store.set('lineTranslations', data.lineTranslations || []);
                store.set('lineAnnotations', data.lineAnnotations || []);
                /* #1769 P4 — the songbook's default rights keys, a prefill HINT
                   for the Metadata tab's rights panel (D4). null on an
                   un-migrated install, so the panel simply shows no hint. */
                store.set('songbookRightsDefaults', data.songbookRightsDefaults || null);
                /* #1783 — a not-yet-assigned duplicate lives in the hidden staging
                   book; the Metadata tab reads this to render the Songbook + Number
                   fields EMPTY (the "Assign to songbook" panel). */
                store.set('pendingDuplicate', !!data.isPendingDuplicate);
                currentSongId = id;
                mountTabs(id);
                try { history.replaceState(null, '', '?song=' + encodeURIComponent(id)); } catch (_e) {}
                sidebar.setActive(id);
                /* #1783 — the Duplicate button acts on the open song, so reveal it now. */
                { const dupBtn = byId('v2-duplicate-btn'); if (dupBtn) { dupBtn.classList.remove('d-none'); } }
                /* #1846 — the Save button acts on the open song too; enable it now
                   (it starts `disabled` — meaningless with nothing loaded). */
                { const saveBtn = byId('v2-save-btn'); if (saveBtn) { saveBtn.disabled = false; } }
                if (data.isPendingDuplicate) {
                    status('Duplicated song — assign it a songbook and number (both are empty) on the Metadata tab, then it becomes a new song. Edit anything first.', 'success');
                } else {
                status('Editing "' + ((data.song && data.song.Title) || id) + '" — edits save instantly + atomically.', 'success');
                }
                consumeInitialTab();   /* #1628 — after mountTabs(), so the pane has content */
            } catch (e) {
                if (seq !== loadSeq) { return; }   // don't surface a stale error after a newer switch
                status('Load failed: ' + e.message, 'danger');
            }
        }

        const bulkBar = byId('v2-bulk-bar');
        const bulkCount = byId('v2-bulk-count');
        function onSelChange(n) {
            bulkBar.classList.toggle('d-none', n === 0);
            bulkCount.textContent = n + ' song' + (n === 1 ? '' : 's') + ' selected';
        }
        /* #1845 — below lg the song list is a responsive-offcanvas drawer;
           picking a song must dismiss it or the editor stays hidden behind it.
           Gated on .show so it's a no-op on desktop (or when the drawer is
           already closed). Global window.bootstrap, same as the Tab/Modal usage
           elsewhere on this page — never a module import. */
        function hideSidebarPanel() {
            const el = byId('v2-sidebar-panel');
            if (!el || !el.classList.contains('show')) { return; }
            if (window.bootstrap && window.bootstrap.Offcanvas) {
                window.bootstrap.Offcanvas.getOrCreateInstance(el).hide();
            }
        }
        /* Rotating a tablet from portrait (<992px, drawer open) to landscape
           (>=992px) can strand Bootstrap's JS-inserted backdrop; hide-on-cross
           cleans it up. The 992 MUST match the .offcanvas-lg breakpoint. */
        try { window.matchMedia('(min-width: 992px)').addEventListener('change', (e) => { if (e.matches) { hideSidebarPanel(); } }); } catch (_e) {}

        const sidebar = mountSidebar(byId('v2-sidebar'), { api: editorApi, toast, onSelect: (id) => { hideSidebarPanel(); loadSong(id); }, onSelectionChange: onSelChange });

        /* ---- bulk actions (multi-select) ---- */
        byId('v2-bulk-clear').addEventListener('click', () => sidebar.clearSelection());
        byId('v2-bulk-verify').addEventListener('click', async () => {
            const ids = sidebar.getSelectedIds();
            if (!ids.length) { return; }
            try {
                await editorApi.bulkVerify(ids, true);
                toast('Marked ' + ids.length + ' song(s) verified.', 'success');
                sidebar.clearSelection();
            } catch (e) { toast('Bulk verify failed: ' + e.message, 'danger'); }
        });
        byId('v2-bulk-tag').addEventListener('click', async () => {
            const ids = sidebar.getSelectedIds();
            if (!ids.length) { return; }
            const name = window.prompt('Tag to add to ' + ids.length + ' selected song(s):', '');
            if (name === null || name.trim() === '') { return; }
            try {
                const r = await editorApi.bulkTagAttach(ids, name.trim());
                toast('Tagged ' + (r.attached || 0) + ' of ' + ids.length + ' song(s) with "' + (r.tag ? r.tag.name : name.trim()) + '".', 'success');
                sidebar.clearSelection();
            } catch (e) { toast('Bulk tag failed: ' + e.message, 'danger'); }
        });

        /* Remove a tag from the selection — the other half of bulk tagging.
           v1's single `bulk_tag` action took add[] AND remove[]; v2 shipped only
           attach, so a curator who bulk-tagged 200 songs with the wrong tag had
           no way back except one song at a time. The server side landed in
           `33f583e1`; this button is what makes it reachable.

           Detaching a tag nothing carries is a successful no-op server-side, so
           `detached: 0` is reported as an outcome ("none of them had it"), not
           an error — the curator's intent is satisfied either way. */
        byId('v2-bulk-untag').addEventListener('click', async () => {
            const ids = sidebar.getSelectedIds();
            if (!ids.length) { return; }
            const name = window.prompt('Tag to remove from ' + ids.length + ' selected song(s):', '');
            if (name === null || name.trim() === '') { return; }
            try {
                const r = await editorApi.bulkTagDetach(ids, name.trim());
                const n = r.detached || 0;
                const label = (r.tag && r.tag.name) ? r.tag.name : name.trim();
                toast(n === 0
                    ? 'None of the ' + ids.length + ' selected song(s) had the tag "' + label + '".'
                    : 'Removed "' + label + '" from ' + n + ' of ' + ids.length + ' song(s).',
                    n === 0 ? 'info' : 'success');
                sidebar.clearSelection();
            } catch (e) { toast('Bulk untag failed: ' + e.message, 'danger'); }
        });

        /* ---- resizable sidebar (#1193) — drag the grip; width persists ----
           #1845 — retargeted from #v2-sidebar (the inner mount div, now sized
           by its offcanvas-body flex parent) to #v2-sidebar-panel (the actual
           flex item in #v2-shell). The saved width still lands as an INLINE
           flex-basis on that item, which wins over admin.css's `flex: 0 0
           300px` default at >=lg (inline style beats a stylesheet rule of
           equal specificity) — and is simply inert below lg, where the grip
           itself is `d-none` so these listeners can never fire in the first
           place (verified: grip has zero hit-area while hidden). */
        (function () {
            const aside = byId('v2-sidebar-panel');
            const grip = byId('v2-grip');
            const KEY = 'ihymns_editor_sidebar_w';
            const clampW = (w) => Math.max(200, Math.min(w, Math.round(window.innerWidth * 0.6)));
            const saved = parseInt(window.localStorage.getItem(KEY), 10);
            if (!isNaN(saved) && saved > 0) { aside.style.flexBasis = clampW(saved) + 'px'; }
            let dragging = false;
            grip.addEventListener('mousedown', (e) => { dragging = true; e.preventDefault(); document.body.style.userSelect = 'none'; });
            window.addEventListener('mousemove', (e) => {
                if (!dragging) { return; }
                aside.style.flexBasis = clampW(e.clientX - aside.getBoundingClientRect().left) + 'px';
            });
            window.addEventListener('mouseup', () => {
                if (!dragging) { return; }
                dragging = false;
                document.body.style.userSelect = '';
                const w = parseInt(aside.style.flexBasis, 10);
                if (!isNaN(w)) { try { window.localStorage.setItem(KEY, String(w)); } catch (_e) {} }
            });
        })();

        /* ---- New song ---- */
        const newModal = (window.bootstrap && window.bootstrap.Modal) ? new window.bootstrap.Modal(byId('v2-new-modal')) : null;
        byId('v2-new-btn').addEventListener('click', () => {
            const sel = byId('v2-new-songbook');
            sel.innerHTML = '';
            const books = sidebar.getSongbooks();
            if (!books.length) {
                const o = document.createElement('option');
                o.value = ''; o.textContent = '(song list still loading…)';
                sel.appendChild(o);
            } else {
                books.forEach((b) => {
                    const o = document.createElement('option');
                    o.value = b.abbr;
                    o.textContent = b.name + ' (' + b.abbr + ')';
                    sel.appendChild(o);
                });
            }
            byId('v2-new-title').value = '';
            byId('v2-new-err').textContent = '';
            if (newModal) { newModal.show(); }
        });
        byId('v2-new-create').addEventListener('click', async () => {
            const sb = byId('v2-new-songbook').value;
            const title = byId('v2-new-title').value.trim();
            const errEl = byId('v2-new-err');
            errEl.textContent = '';
            if (!sb) { errEl.textContent = 'Pick a songbook.'; return; }
            try {
                const res = await editorApi.createSong(sb, title || 'New Song');
                const book = sidebar.getSongbooks().find((b) => b.abbr === res.songbook);
                sidebar.addSong({ id: res.songId, number: null, title: res.title, songbook: res.songbook, songbookName: book ? book.name : res.songbook });
                if (newModal) { newModal.hide(); }
                loadSong(res.songId);
            } catch (e) {
                errEl.textContent = e.message;
            }
        });

        /* ---- Duplicate current song (#1783) ----
           Copies the open song into the hidden staging book and re-opens the
           duplicate, which the Metadata tab shows with empty Songbook + Number.
           A confirm converts a navigation (or a click) into intent — the
           `?duplicate=` deep-link below routes through the SAME function, so a
           forced top-level navigation can't silently mint rows. */
        async function runDuplicate(sourceId) {
            if (!sourceId) { return; }
            if (!window.confirm('Duplicate this song as a starting point for a new songbook?\n\nThe copy opens with an empty Songbook and Song number for you to set, and you can change anything before saving it as a new song.')) { return; }
            status('Duplicating…');
            try {
                const res = await editorApi.duplicateSong(sourceId);
                try { sidebar.refresh(); } catch (_e) {}
                loadSong(res.songId);
            } catch (e) {
                status('Could not duplicate: ' + e.message, 'danger');
            }
        }
        byId('v2-duplicate-btn').addEventListener('click', () => { runDuplicate(currentSongId); });

        /* ---- Manual Save (#1846) ----
           ELI5: autosave already saves every field a second or so after you
           stop typing; this button says "don't make me wait — save what's
           pending right now, and tell me when it's done."

           Wired ONCE here (not inside mountTabs()) so the listener survives a
           song switch — it reads the CURRENT `flushers` array by closure on
           every click, which mountTabs()/teardownTabs() keep pointed at
           whichever song is presently mounted. Each registered flusher already
           settles (resolve, never reject — see each tab's own flushPending()),
           so the outer try/catch is a belt-and-braces guard against a flusher
           that somehow still throws, not the normal path.

           #1851 — flushPending() used to be fired purely for its side effect
           (`.catch(() => {})` swallowed EVERY outcome, success or failure)
           and this handler then reported "All changes saved." unconditionally
           — even when a flusher's inner save had already toasted a failure.
           The toast scrolled past unnoticed and the status bar lied. Each
           tab's flushPending() now RESOLVES TO THE COUNT of saves that
           failed during that flush (0 = all ok; see each tab's contract
           doc-comment on its own flushPending()), so this sums those counts
           and reports the real outcome instead of a fixed success string. */
        {
            const saveBtn = byId('v2-save-btn');
            if (saveBtn) {
                saveBtn.addEventListener('click', async () => {
                    if (!currentSongId) { return; }
                    saveBtn.disabled = true;
                    status('Saving…');
                    try {
                        const results = await Promise.all(flushers.map((fn) => Promise.resolve(fn()).catch(() => 1)));
                        const failed = results.reduce((n, r) => n + (Number(r) || 0), 0);
                        status(
                            failed
                                ? ('Saved — but ' + failed + ' change' + (failed > 1 ? 's' : '') + ' could not be saved (see messages above)')
                                : 'All changes saved.',
                            failed ? 'danger' : 'success'
                        );
                    } catch (e) {
                        status('Save failed: ' + (e && e.message ? e.message : e), 'danger');
                    } finally {
                        saveBtn.disabled = false;
                    }
                });
            }
        }

        /* ---- Delete current song ---- */
        byId('v2-delete-btn').addEventListener('click', async () => {
            if (!currentSongId) { toast('No song open to delete.', 'danger'); return; }
            const name = (store.get('song') && store.get('song').Title) || currentSongId;
            /* SOFT delete since #1694 — honest copy: the song is hidden and
               restorable; only the admin-only Purge on /manage/deleted-songs
               is permanent. */
            if (!window.confirm('Delete "' + name + '"?\n\nThis moves the song to Deleted songs: it disappears from the catalogue and every listing, but nothing is permanently removed. An admin can restore it — or permanently purge it — from /manage/deleted-songs.')) { return; }
            const gone = currentSongId;
            loadSeq++;   // invalidate any in-flight loadSong so it can't repaint the deleted song
            try {
                await editorApi.deleteSong(gone);
                /* #1851 FIX #1 — discard (not flush) the deleted song's
                   pending edits: teardownTabs() runs BEFORE the next-song
                   load or the empty-list branch, so loadSong()'s own
                   top-of-function flush (see FIX #1's comment there) finds
                   `flushers` already reset to [] and has nothing stale left
                   to fire against a song that no longer exists. */
                teardownTabs();
                sidebar.removeSong(gone);
                currentSongId = null;
                const next = sidebar.getFirstId();
                if (next) { loadSong(next); }
                else {
                    status('Song moved to Deleted songs (restorable). Create a New song or pick one.', 'success');
                    /* #1851 FIX #7 — loadSong() enables Save + reveals
                       Duplicate for the song it loads, but nothing reversed
                       that after deleting the LAST song: both stayed
                       active/visible with no song open to act on. */
                    { const b = byId('v2-save-btn'); if (b) { b.disabled = true; } }
                    { const d = byId('v2-duplicate-btn'); if (d) { d.classList.add('d-none'); } }
                }
            } catch (e) {
                toast('Delete failed: ' + e.message, 'danger');
            }
        });

        /**
         * Missing-Numbers prefill (#1680) — ?songbook=<ABBR> + ?number=N | #number=N
         *
         * ELI5: land ready to type lyrics for song N of book XX, instead of an
         * empty list and having to re-discover what to enter.
         *
         * The number may arrive as a query param OR as the FRAGMENT, because
         * missing-numbers.php emits the fragment form and the browser never
         * sends a fragment to the server — so PHP can pre-validate the songbook
         * but only JS can see the number. v1 accepts both shapes; so does this.
         *
         * If that (songbook, number) ALREADY exists, open it rather than
         * creating a duplicate. v1 checks this too, and it matters: Missing
         * Numbers is a list of gaps, and a gap that was filled since the page
         * was rendered would otherwise silently mint a second song on the same
         * number.
         *
         * Sequenced rather than parameterised because v2's create_song mints
         * its own canonical id and takes no number — Number is a separate
         * metadata field. So: create -> set number -> load.
         */
        const prefillBook = <?= json_encode($prefillBook) ?>;
        function prefillNumber() {
            const qs = <?= json_encode($prefillNum) ?>;
            if (qs) { return qs; }
            const hash = String(window.location.hash || '').replace(/^#/, '');
            const m = /^number=(\d+)$/.exec(hash);
            return m ? m[1] : '';
        }

        async function runPrefill(book, numStr) {
            const num = parseInt(numStr, 10);
            status('Preparing ' + book + ' ' + num + '…');

            /* Already exists? Open it — never mint a duplicate on the same number. */
            const existing = sidebar.findByBookAndNumber(book, num);
            if (existing) {
                status('Song ' + num + ' already exists in ' + book + ' — opening it.', 'success');
                loadSong(existing);
                return;
            }

            try {
                const res = await editorApi.createSong(book, 'New Song');
                try {
                    await editorApi.updateMetadata(res.songId, 'number', num);
                } catch (e) {
                    /* The song EXISTS at this point; only the number failed. Say so
                       precisely — "create failed" would send the curator hunting for
                       a song that is actually there, and they would create a second. */
                    status('Created ' + res.songId + ' but could not set number ' + num
                           + ': ' + e.message + ' — set it on the Metadata tab.', 'danger');
                }
                const book0 = sidebar.getSongbooks().find((b) => b.abbr === res.songbook);
                sidebar.addSong({
                    id: res.songId, number: num, title: res.title,
                    songbook: res.songbook, songbookName: book0 ? book0.name : res.songbook,
                });
                loadSong(res.songId);
            } catch (e) {
                status('Could not start ' + book + ' ' + num + ': ' + e.message, 'danger');
            }
        }

        /* ---- initial ---- */
        if (duplicateSource) {
            /* #1783 — cold ?duplicate= entry. runDuplicate() confirms first, then
               mints the copy and opens it (empty Songbook + Number on Metadata). */
            runDuplicate(duplicateSource);
        } else if (initialSongId) {
            loadSong(initialSongId);
        } else if (prefillBook && prefillNumber()) {
            /* Wait for the index: findByBookAndNumber and getSongbooks both need
               it, and mountSidebar's load() is async. */
            sidebar.whenLoaded().then(() => runPrefill(prefillBook, prefillNumber()));
        } else {
            status('Pick a song from the list, or create a New one.');
        }
    </script>
    <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
