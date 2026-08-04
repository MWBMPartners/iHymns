<?php

/**
 * iHymns — Song Request Submission (#403)
 *
 * Public-facing form that writes to tblSongRequests. Admins can
 * later triage submissions via the admin dashboard (follow-up).
 *
 * Deep-link prefill (#666): the editor's missing-numbers panel and
 * the standalone admin missing-numbers page send users here with
 * `?songbook=<ABBR>&number=<n>` query params. The router forwards
 * those through to /api?page=request, so they arrive in $_GET on
 * this partial — we echo them straight into the input value
 * attributes so prefill works the moment the HTML reaches the
 * browser, without depending on client-side rehydration that proved
 * racy in #666 (SW caches + module-import timing). The same resolved
 * values are ALSO mirrored onto the section as `data-prefill-*` so
 * js/modules/request-a-song.js has a DOM-first source that can never
 * disagree with what was baked into the inputs.
 *
 * Behaviour is wired by js/modules/request-a-song.js, imported by the
 * SPA router's afterPageLoad() — NOT by a <script> in this file. An
 * inline script here is CSP-dead: the document's enforcing nonce CSP
 * (#117) refuses nonce-less inline scripts, this fragment is a
 * separate HTTP response that never sees the document's nonce, and
 * `request` is in api.php's $_cacheablePages so the same bytes are
 * replayed to everyone (rule #6). That silently killed this form's
 * JS path entirely until #1572 — every submit was quietly taken by
 * the no-JS fallback below. CI guard:
 * tests/php/test-fragment-inline-scripts.php (#1565 / #1569).
 */

declare(strict_types=1);

/* Read + length-cap the prefill values to match the inputs' own
   maxlength attributes so a crafted URL can't bypass the form's
   caps. htmlspecialchars below makes the values safe to interpolate
   inside the value="" attribute regardless of content. */
$_prefillSongbook = isset($_GET['songbook']) ? trim((string)$_GET['songbook']) : '';
if ($_prefillSongbook !== '') {
    $_prefillSongbook = mb_substr($_prefillSongbook, 0, 100);
    /* Resolve abbrev → full songbook name (#683). The deep-link from
       the editor's Find-Missing-Numbers panel and the standalone
       admin missing-numbers page sends `?songbook=<ABBREV>`; without
       this lookup the form arrives showing "MP" instead of "Mission
       Praise", which is confusing once the request lands in front
       of a curator (or the user themselves). One prepared SELECT;
       falls through to the raw param if no row matches (defensive —
       covers a user typing a free-text value or a deleted songbook). */
    try {
        if (!function_exists('getDbMysqli')) {
            require_once dirname(__DIR__, 2) . '/includes/db_mysql.php';
        }
        $_resolveDb = getDbMysqli();
        if ($_resolveDb) {
            $_lookup = $_resolveDb->prepare(
                'SELECT Name FROM tblSongbooks WHERE Abbreviation = ? LIMIT 1'
            );
            if ($_lookup) {
                $_lookup->bind_param('s', $_prefillSongbook);
                $_lookup->execute();
                $_row = $_lookup->get_result()->fetch_row();
                $_lookup->close();
                if ($_row && $_row[0] !== '') {
                    /* mb_substr again so a column with a name longer
                       than the input's maxlength still fits. */
                    $_prefillSongbook = mb_substr((string)$_row[0], 0, 100);
                }
            }
        }
    } catch (\Throwable $_e) {
        /* Best-effort lookup — a DB hiccup must not break the page
           render. The user keeps the abbreviation as a fallback. */
        error_log('[request-a-song] songbook abbrev lookup skipped: ' . $_e->getMessage());
    }
}
$_prefillNumber = isset($_GET['number']) ? trim((string)$_GET['number']) : '';
if ($_prefillNumber !== '') {
    $_prefillNumber = mb_substr($_prefillNumber, 0, 500);
}

/* Server-rendered confirmation / error banners (#711). When the form
   submits via the no-JS fallback path (action= attribute on <form>),
   the API endpoint redirects back here with ?submitted=1&id=N (success)
   or ?error=… (failure). We render the banner server-side so the user
   gets feedback whether or not js/modules/request-a-song.js loaded.
   The JS path keeps using its own fetch() flow + the d-none banners
   below — both surfaces work independently. */
$_serverSubmitted = isset($_GET['submitted']) && $_GET['submitted'] === '1';
$_serverTrackingId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$_serverError      = isset($_GET['error']) ? trim((string)$_GET['error']) : '';
if ($_serverError !== '') {
    $_serverError = mb_substr($_serverError, 0, 200);
}

?>
<!-- data-prefill-* mirrors the resolved values baked into the inputs below,
     giving js/modules/request-a-song.js a DOM-first input source in the same
     shape the router already trusts elsewhere (.page-song[data-song-id],
     .page-songbook[data-songbook-abbr]). The module prefers these over the
     route params / query string precisely because `songbook` here is the
     RESOLVED full name (#683), not the raw abbreviation in the URL. Absent
     on a stale service-worker copy of this fragment — which is exactly the
     case the module's param/query-string fallbacks cover (#666). -->
<section class="page-request-a-song" aria-label="Request a song"
         data-prefill-songbook="<?= htmlspecialchars($_prefillSongbook, ENT_QUOTES, 'UTF-8') ?>"
         data-prefill-number="<?= htmlspecialchars($_prefillNumber, ENT_QUOTES, 'UTF-8') ?>">

    <h1 class="h4 mb-3">
        <i class="fa-solid fa-lightbulb me-2" aria-hidden="true"></i>
        Request a Song
    </h1>

    <p class="text-muted small mb-4">
        Can't find a hymn you're looking for? Let us know and we'll do our best
        to add it. Only the details below are sent to our curators — no other
        personal data.
    </p>

    <?php if ($_serverSubmitted): ?>
        <div class="alert alert-success" role="alert">
            <i class="fa-solid fa-check-circle me-1" aria-hidden="true"></i>
            Thank you — your request has been received.
            <?php if ($_serverTrackingId > 0): ?>
                <span class="text-muted small">Reference: #<?= $_serverTrackingId ?></span>
            <?php endif; ?>
            <a href="/request" class="ms-2">Submit another</a>
        </div>
    <?php elseif ($_serverError !== ''): ?>
        <div class="alert alert-danger" role="alert">
            <i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
            <?= htmlspecialchars($_serverError, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div id="request-success" class="alert alert-success d-none" role="alert">
        <i class="fa-solid fa-check-circle me-1" aria-hidden="true"></i>
        Thank you — your request has been received.
        <span id="request-tracking-id" class="text-muted small"></span>
    </div>
    <div id="request-queued" class="alert alert-info d-none" role="status">
        <i class="fa-solid fa-cloud-arrow-up me-1" aria-hidden="true"></i>
        Saved offline — we'll send this request as soon as you're back online.
        <span id="request-queued-count" class="text-muted small ms-1"></span>
    </div>
    <div id="request-error" class="alert alert-danger d-none" role="alert"></div>

    <!-- The action="…" + method="POST" attributes are the no-JS fallback
         path (#711). js/modules/request-a-song.js (imported by the SPA
         router's afterPageLoad(), #1572) intercepts the submit event and
         uses fetch() instead, so the JS path is what runs in normal
         browsers. If that module fails to load (network, syntax error, SW
         cache miss) the form still works: the browser POSTs the
         form-encoded body straight to the API, the endpoint detects
         Content-Type=application/x-www-form-urlencoded and redirects back
         here with ?submitted=1&id=… for the server-rendered success banner
         above. That fallback is why the CSP-dead inline script this page
         used to carry went unnoticed for so long — the form never appeared
         broken, it just silently full-page-reloaded on every submit. -->
    <form id="request-form" action="/api?action=song_request_submit" method="POST" novalidate>
        <div class="row g-3">
            <div class="col-md-8">
                <label for="request-title" class="form-label">Song title <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="request-title" name="title"
                       required maxlength="500" autocomplete="off"
                       value="<?= htmlspecialchars($_prefillNumber, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-md-4">
                <label for="request-songbook" class="form-label">Songbook <span class="text-muted">(optional)</span></label>
                <input type="text" class="form-control" id="request-songbook" name="songbook"
                       maxlength="100" placeholder="e.g. Mission Praise" autocomplete="off"
                       value="<?= htmlspecialchars($_prefillSongbook, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-md-12">
                <label for="request-details" class="form-label">Any extra details? <span class="text-muted">(first line of lyrics, writer name, etc.)</span></label>
                <textarea class="form-control" id="request-details" name="details"
                          rows="3" maxlength="2000"></textarea>
            </div>
            <div class="col-md-12">
                <label for="request-email" class="form-label">
                    Your email <span class="text-muted">(optional — only if you'd like us to follow up)</span>
                </label>
                <input type="email" class="form-control" id="request-email" name="email"
                       maxlength="255" autocomplete="email">
            </div>
            <!-- Honeypot field for spam bots — real users never see it. -->
            <input type="text" name="website" tabindex="-1" autocomplete="off"
                   style="position:absolute; left:-9999px; top:-9999px;" aria-hidden="true">
        </div>

        <div class="mt-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary" id="request-submit-btn">
                <i class="fa-solid fa-paper-plane me-1" aria-hidden="true"></i>
                Send Request
            </button>
            <a href="/" data-navigate="home" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>

    <!-- ================================================================
         YOUR REQUESTS (#1671 F2; server #280)

         ELI5: after you send a request, this is where you find out what
         happened to it.

         Detail: `?action=my_song_requests` has existed and worked since
         #280 and had ZERO callers anywhere — web, Apple or Android. So a
         user could submit a request and never learn its outcome; the
         status field (pending → reviewed → added/declined) and the
         ResolvedSongId a curator fills in were written and never read.
         This section is the first caller that endpoint has ever had.

         WHY IT LIVES HERE rather than in Settings: this is the page the
         user is already on when they think about a request, and the
         status of a submission belongs beside the form that made it.

         EMPTY MARKUP ON PURPOSE — no inline <script>, and there can never
         be one. `request` is in api.php's $_cacheablePages, so the same
         bytes are replayed to every visitor and this fragment can never
         carry the document's per-request CSP nonce (#117 / rule #6 / rule
         #30). That is exactly what silently killed this page's ORIGINAL
         inline module until #1572. Behaviour comes from
         js/modules/my-song-requests.js, imported by router.js's
         afterPageLoad(). CI guard:
         tests/php/test-fragment-inline-scripts.php.

         `data-my-requests` is the module's DOM-first hook. Hidden by
         default because the endpoint is authenticated: the module reveals
         it (as either the list or a sign-in prompt) once it knows the
         auth state.
         ================================================================ -->
    <section id="my-requests" class="mt-5 pt-4 border-top d-none" data-my-requests
             aria-labelledby="my-requests-heading">
        <h2 class="h5 mb-3" id="my-requests-heading">
            <i class="fa-solid fa-clipboard-list me-2" aria-hidden="true"></i>
            Your requests
        </h2>
        <div id="my-requests-msg" class="alert d-none py-2 small" role="alert"></div>
        <!-- aria-live="polite" on the LIST only, never on a whole page region:
             a live region is for status messages, not for re-reading a page
             (see js/utils/announce.js's header for why the site-wide version
             of this was removed). -->
        <div id="my-requests-list" aria-live="polite"></div>
    </section>

</section>
