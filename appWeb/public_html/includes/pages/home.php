<?php

/**
 * iHymns — Home Page Template
 *
 * PURPOSE:
 * Landing page for the iHymns application. Displays a welcome message,
 * collection statistics, quick-access songbook cards, and action buttons
 * for searching, shuffling, and number lookup.
 *
 * This file is loaded as an HTML fragment via AJAX (api.php?page=home).
 * It should NOT include <html>, <head>, or <body> tags.
 */

declare(strict_types=1);

/* Language-name resolver (#856) — used for the songbook tile badge
   tooltip. Static-cached per request so this require + map build
   only fires once per page load. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'language_names.php';
/* Songbook display helper (#1328) — ihymns_songbook_show_abbr() hides the
   abbreviation badge when it just repeats the title (e.g. "Psalty"/"Psalty"). */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'songbook_display.php';

/* #1725/#1733 — the FIRST real intappsFlag() consumer, wiring the whole
   IntAppsAPI feature-flag pipeline end to end (design §6.2b: "ship at
   least one real web-side consumer in the launch PR so the pipeline is
   exercised, not merely renderable"). Loading intapps_client.php has no
   side effect (see its own docblock); intappsFlag() itself never throws
   and never performs HTTP (cache-only), so this call is safe even while
   the module is entirely dormant (the shipped default) — it returns the
   compiled-in default `true` instantly, byte-identical to the card
   simply always rendering, which is exactly today's behaviour.
   IMPORTANT (rule #6 + rule #30): `/api?page=home` is a SHARED-CACHE
   fragment. This flag is safe to bake into that cache because it is a
   GLOBAL, admin/gateway-controlled cosmetic on/off (never per-user, never
   an auth/content decision), so every viewer who receives this cached
   fragment sees the identical, correct answer — unlike card-layout's
   reorder/hide state (#448), which is deliberately kept OUT of the cached
   markup and applied client-side per viewer instead. Never gate anything
   per-user here; if a future flag needs per-viewer divergence, it belongs
   in a client-side apply step (the card-layout.js pattern), not a raw
   intappsFlag() call inside a cached fragment. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'intapps_client.php';
$showSongOfTheDayCard = intappsFlag(getDbMysqli(), 'web.sotd_card', true);

/* Load song data for statistics */
$stats = $songData->getStats();
$songbooks = $songData->getSongbooks();

/* #448 — each top-level home section is wrapped in a `.card-layout-item`
   so a signed-in user can reorder/hide it. The WRAPPER (not the inner
   section) is the draggable unit, so loaders that innerHTML-replace or
   .remove() the inner element never destroy the drag handle. The handle
   strip is hidden until edit mode (see app.css). applyCardLayout() in
   card-layout.js hydrates the viewer's saved order/hidden set on load —
   the home fragment is shared-cache, so the server emits the canonical
   default order and personalisation happens client-side. */
$homeCard = static function (string $cardId): string {
    $id = htmlspecialchars($cardId, ENT_QUOTES, 'UTF-8');
    /* #1151 — the handle strip is NOT aria-hidden: card-layout.js injects
       real, focusable "Move up" / "Move down" / "Hide this card" buttons
       into it once edit mode is entered, and an aria-hidden ancestor
       removes focusable descendants from the accessibility tree while
       leaving them in the visual/keyboard tab order (WCAG 4.1.2 Name,
       Role, Value) — a screen-reader user tabbing through could land on
       a control with no announced name or role at all. Only the
       decorative grip glyph is hidden from AT; the "Drag to reorder"
       text stays available as a plain-text label for anyone who does
       reach the (only-visible-in-edit-mode) strip. */
    return '<div class="card-layout-item" data-card-id="' . $id . '">'
         . '<div class="card-layout-handle">'
         . '<i class="bi bi-grip-vertical" aria-hidden="true"></i><span class="ms-1">Drag to reorder</span>'
         . '</div>';
};
$homeCardEnd = '</div>';

?>

<!-- ================================================================
     HOME PAGE — Welcome & Quick Access
     ================================================================ -->
<section class="page-home" aria-label="Home">

    <!-- Hero Section — App introduction with gradient background -->
    <div class="hero-section text-center mb-4">
        <div class="hero-content">
            <h1 class="hero-title">
                <i class="fa-solid fa-music me-2" aria-hidden="true"></i>
                <?= htmlspecialchars($app["Application"]["Name"]) ?>
            </h1>
            <p class="hero-subtitle">
                Your library of Christian hymns &amp; worship songs
            </p>
            <p class="hero-stats">
                <span class="badge bg-primary bg-gradient rounded-pill px-3 py-2">
                    <i class="fa-solid fa-music me-1" aria-hidden="true"></i>
                    <?= number_format($stats['totalSongs']) ?> Songs
                </span>
                <span class="badge bg-secondary bg-gradient rounded-pill px-3 py-2 ms-2">
                    <i class="fa-solid fa-book me-1" aria-hidden="true"></i>
                    <?= $stats['totalSongbooks'] ?> Songbooks
                </span>
            </p>
        </div>
    </div>

    <!-- Join a live service (#1335) — a congregant enters the rotating code shown
         on their church's screen to follow the service in sync. Outside the
         reorderable grid so it stays discoverable; service-follow.js wires the
         [data-action="join-service"] click. -->
    <div class="text-center mb-4">
        <button type="button" class="btn btn-outline-success" data-action="join-service">
            <i class="bi bi-broadcast-pin me-1" aria-hidden="true"></i>Join a live service
        </button>
        <div class="small text-muted mt-1">Enter the code shown on your church’s screen to follow along.</div>
    </div>

    <!-- Customise-home toolbar (#448). Hidden until applyCardLayout()
         confirms the viewer may edit (canCustomiseOwn / canSetDefault),
         so the logged-out majority never sees a dead "Customise" button. -->
    <div class="d-flex align-items-center flex-wrap gap-2 mb-3 d-none" id="card-layout-toolbar">
        <button type="button" class="btn btn-sm btn-outline-info" id="btn-card-layout-edit">
            <i class="bi bi-grid-3x3-gap me-1" aria-hidden="true"></i>Customise home
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="btn-card-layout-done">
            <i class="bi bi-check2 me-1" aria-hidden="true"></i>Done
        </button>
        <button type="button" class="btn btn-sm btn-outline-warning d-none" id="btn-card-layout-reset">
            <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reset to default
        </button>
        <button type="button" class="btn btn-sm btn-outline-danger d-none" id="btn-card-layout-save-default"
                title="Save the current order as the home default for all users">
            <i class="bi bi-save me-1" aria-hidden="true"></i>Save as site default
        </button>
        <span class="small text-muted d-none" id="card-layout-help">
            Drag a section's handle to reorder; click &times; to hide it. Hidden sections reappear from
            <a href="/settings#tab-profile" class="text-info">Settings &rarr; Profile</a>.
        </span>
    </div>

    <!-- #448 — reorderable / hideable home sections. A vertical stack of
         card-layout-item wrappers; applyCardLayout() reorders/hides them
         per the signed-in viewer's saved layout after this fragment loads. -->
    <div id="home-section-grid"
         data-layout-surface="home"
         data-layout-handle=".card-layout-handle">

    <?= $homeCard('quick-actions') ?>
    <!-- Quick Action Buttons -->
    <section id="quick-actions" aria-label="Quick actions">
    <div class="row g-3 mb-4">
        <!-- Search by text -->
        <div class="col-6 col-md-3">
            <button type="button"
                    class="btn btn-action-card w-100 h-100"
                    data-action="open-search"
                    aria-label="Search songs by text">
                <i class="fa-solid fa-magnifying-glass fa-2x mb-2" aria-hidden="true"></i>
                <span class="d-block fw-semibold">Search</span>
                <small class="text-muted">Find by title or lyrics</small>
            </button>
        </div>

        <!-- Search by number -->
        <div class="col-6 col-md-3">
            <button type="button"
                    class="btn btn-action-card w-100 h-100"
                    data-action="open-numpad"
                    aria-label="Search by song number">
                <i class="fa-solid fa-hashtag fa-2x mb-2" aria-hidden="true"></i>
                <span class="d-block fw-semibold">By Number</span>
                <small class="text-muted">Use the number pad</small>
            </button>
        </div>

        <!-- Shuffle / Random song -->
        <div class="col-6 col-md-3">
            <button type="button"
                    class="btn btn-action-card w-100 h-100"
                    data-action="open-shuffle"
                    aria-label="Pick a random song">
                <i class="fa-solid fa-shuffle fa-2x mb-2" aria-hidden="true"></i>
                <span class="d-block fw-semibold">Shuffle</span>
                <small class="text-muted">Random song pick</small>
            </button>
        </div>

        <!-- Favourites -->
        <div class="col-6 col-md-3">
            <a href="/favorites"
               class="btn btn-action-card w-100 h-100"
               data-navigate="favorites"
               aria-label="View your favourites">
                <i class="fa-solid fa-heart fa-2x mb-2" aria-hidden="true"></i>
                <span class="d-block fw-semibold">Favourites</span>
                <small class="text-muted">Your saved songs</small>
            </a>
        </div>
    </div>
    </section>
    <?= $homeCardEnd ?>

    <?= $homeCard('recent-songbooks') ?>
    <!-- Recent songbooks quick tabs (#121) — populated by JS -->
    <div id="recent-songbooks" class="d-none mb-4"></div>
    <?= $homeCardEnd ?>

<?php /* #1725/#1733 — card presence gated by the intappsFlag('web.sotd_card')
         read above; omitted entirely, not just emptied, when the gateway
         says it's off. Deliberately a PHP comment, not an HTML one, and the
         `if`/`endif` tags sit at column 0 with no surrounding blank lines:
         with the module dormant (the compiled default `true`) this whole
         block's OUTPUT BYTES must be identical to before this flag existed
         (the dormancy no-op proof diffs `page=home` byte-for-byte) — any
         stray literal whitespace/comment text here would leak into the
         cached fragment even while functionally inert. */ if ($showSongOfTheDayCard): ?>
    <?= $homeCard('song-of-the-day') ?>
    <!-- Song of the Day (#108) — populated by JS -->
    <div id="song-of-the-day"></div>
    <?= $homeCardEnd ?>
<?php endif; ?>

    <!-- Songbook Cards Grid (#151 — section ID for sitelink eligibility).
         Moved up from below "Browse by Theme" in #678 so a returning
         user sees the full songbook list straight after the Recent
         badges + Song of the Day, without having to scroll past
         Recently Viewed / Popular Songs / Themes first. -->
    <?= $homeCard('songbooks') ?>
    <section id="songbooks" aria-label="Songbooks">
        <h2 class="h5 mb-3">
            <i class="fa-solid fa-book-open me-2" aria-hidden="true"></i>
            Songbooks
        </h2>

        <!-- Language filter (#679). The partial silently returns
             early if the catalogue spans only one language, so a
             single-English deployment doesn't see a useless filter.
             Pure client-side hide/show via the booted JS module
             below. -->
        <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'songbook-language-filter.php'; ?>

        <!-- `row-cols-*` ladders the column count with the viewport so
             cards stop stretching on xl/xxl monitors: 2 → 3 → 4 → 5 → 6
             as the breakpoints unlock. Each child is just `.col`. -->
        <div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 row-cols-xl-5 row-cols-xxl-6 g-3 mb-4">
            <?php foreach ($songbooks as $index => $book): ?>
                <?php if (($book['songCount'] ?? 0) > 0): ?>
                    <?php
                        /* Pull the IETF BCP 47 tag (or empty) and extract
                           the 2-3 letter language subtag for the badge
                           (#680). Empty = no badge — communicates
                           "multi-lingual / not specified" the same way
                           the filter in #679 does.
                           #857: the songbook's contained-languages
                           union (book.Language ∪ DISTINCT songs.Language
                           within this book) is attached as
                           data-songbook-languages so a book tagged
                           English but holding Afrikaans songs surfaces
                           when the user filters for Afrikaans. */
                        $bookLang = (string)($book['language'] ?? '');
                        $langCode = '';
                        if ($bookLang !== '' && preg_match('/^([a-z]{2,3})/i', $bookLang, $m)) {
                            $langCode = mb_strtoupper($m[1]);
                        }
                        $bookLangs    = $book['languages'] ?? [];
                        $bookLangsCsv = !empty($bookLangs) ? implode(',', $bookLangs) : '';
                        /* Tooltip lists every contained language by its
                           full English name. Falls back to just the
                           primary tag when there's only one. */
                        $bookLangNames = [];
                        foreach ($bookLangs as $sub) {
                            $bookLangNames[] = resolveLanguageName($sub);
                        }
                        $bookLangsTitle = !empty($bookLangNames)
                            ? implode(', ', $bookLangNames)
                            : resolveLanguageName($bookLang);
                        /* #1223 — unofficial-songbook flag (see
                           includes/pages/songbooks.php for full rationale).
                           A global per-book property (tblSongbooks.IsOfficial,
                           #502), so it's safe to render in the cached home
                           fragment — no per-user state, no personalisation of
                           a shared cache (CLAUDE.md rule #6). */
                        $isUnofficial = empty($book['isOfficial']);
                    ?>
                    <div class="col" id="songbook-<?= htmlspecialchars($book['id']) ?>">
                        <div class="card card-songbook h-100 position-relative"
                             data-songbook-id="<?= htmlspecialchars($book['id']) ?>"
                             data-songbook-songs="<?= (int)$book['songCount'] ?>"
                             <?php if ($langCode !== ''): ?>data-songbook-language="<?= htmlspecialchars($bookLang) ?>"<?php endif; ?>
                             <?php if ($bookLangsCsv !== ''): ?>data-songbook-languages="<?= htmlspecialchars($bookLangsCsv) ?>"<?php endif; ?>>
                            <!-- Stretched link covers the whole card body,
                                 keeping the download button clickable because
                                 the button's stacking context is raised by
                                 position: relative + z-index on the button. -->
                            <a href="/songbook/<?= htmlspecialchars($book['id']) ?>"
                               class="stretched-link text-decoration-none text-reset"
                               data-navigate="songbook"
                               aria-label="<?= htmlspecialchars($book['name']) ?><?= $isUnofficial ? ' (unofficial songbook)' : '' ?> — <?= $book['songCount'] ?> songs<?= $langCode !== '' ? ' (' . htmlspecialchars($langCode) . ')' : '' ?>"></a>
                            <?php if ($langCode !== ''): ?>
                                <!-- Language indicator badge (#680) — small uppercase
                                     ISO 639 code in the tile's top-right corner.
                                     #856: tooltip resolves to the full language
                                     name. #857: when the songbook contains songs
                                     in multiple languages, the tooltip lists ALL
                                     of them (e.g. "English, Afrikaans") so the
                                     user knows why an EN-tagged book is appearing
                                     under an Afrikaans filter. -->
                                <span class="songbook-tile-language-badge"
                                      title="<?= htmlspecialchars($bookLangsTitle) ?>"
                                      aria-hidden="true"><?= htmlspecialchars($langCode) ?></span>
                            <?php endif; ?>
                            <div class="card-body text-center">
                                <div class="songbook-icon songbook-icon-<?= htmlspecialchars($book['id']) ?> mb-2">
                                    <i class="fa-solid fa-book" aria-hidden="true"></i>
                                </div>
                                <h3 class="card-title h6 mb-1">
                                    <?= htmlspecialchars($book['name']) ?>
                                </h3>
                                <?php $sbAbbr = ihymns_songbook_abbr_label($book['id'] ?? '', $book['displayAbbr'] ?? null); ?>
                                <?php if (ihymns_songbook_show_abbr($book['name'] ?? '', $sbAbbr)): ?>
                                <span class="badge bg-body-secondary rounded-pill">
                                    <?= htmlspecialchars($sbAbbr) ?>
                                </span>
                                <?php endif; ?>
                                <?php if ($isUnofficial): ?>
                                    <!-- #1223 — "Unofficial" badge (see
                                         includes/pages/songbooks.php for the full
                                         rationale). rounded-pill + ms-1 to match the
                                         sibling abbreviation pill on the centred home
                                         tile. aria-hidden — the accessible name in the
                                         stretched-link already carries it. -->
                                    <span class="badge songbook-unofficial-badge rounded-pill ms-1"
                                          title="Unofficial songbook"
                                          aria-hidden="true">Unofficial</span>
                                <?php endif; ?>
                                <p class="card-text text-muted small mt-2 mb-0">
                                    <?= number_format($book['songCount']) ?> songs
                                </p>
                                <?php
                                /* #782 phase D — "Part of: <Series>" line.
                                   Renders only when the songbook belongs to one
                                   or more series (Songs of Fellowship volumes,
                                   themed compilations). Multiple series are
                                   joined with " · " — clean separator that
                                   doesn't fight with commas inside series
                                   names. The series links route to the
                                   /series/<slug> page (phase D adds the page
                                   itself in a future PR; until then the
                                   anchors 404 — captured in the PR's known-
                                   limits list). */
                                $bookSeries = (array)($book['series'] ?? []);
                                if ($bookSeries):
                                    $names = array_map(
                                        static fn(array $s): string => htmlspecialchars((string)($s['name'] ?? '')),
                                        $bookSeries
                                    );
                                ?>
                                <p class="card-text text-muted small mt-1 mb-0 songbook-tile-series"
                                   title="Part of <?= count($bookSeries) ?> series">
                                    <i class="fa-solid fa-layer-group me-1" aria-hidden="true"></i>
                                    <span class="visually-hidden">Part of </span><?= implode(' · ', $names) ?>
                                </p>
                                <?php endif; ?>
                            </div>
                            <!-- Offline-download button (#453). Hidden by
                                 default; the offline-support check in
                                 js/modules/offline.js reveals it on capable
                                 browsers. Always rendered so server-HTML
                                 stays stable; never rendered enabled if
                                 the browser can't act on it. -->
                            <button type="button"
                                    class="btn btn-sm btn-outline-secondary songbook-download-btn"
                                    data-songbook-download="<?= htmlspecialchars($book['id']) ?>"
                                    aria-label="Download <?= htmlspecialchars($book['name']) ?> for offline use"
                                    title="Download this songbook for offline use">
                                <i class="fa-solid fa-cloud-arrow-down" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </section>
    <?= $homeCardEnd ?>

    <?= $homeCard('recent-songs') ?>
    <!-- Recently Viewed Songs (#304) — shown for authenticated users -->
    <div class="mb-4" id="recent-songs-section" style="display:none">
        <h2 class="h5"><i class="fa-solid fa-clock-rotate-left me-2" aria-hidden="true"></i>Recently Viewed</h2>
        <?php /* a11y audit M10 (2026-08-28): aria-live removed — this whole list is
                 injected once per visit to Home, so it queued dozens of song rows for
                 announcement on every navigation here, competing with the router's own
                 one-line route announcement (#1645). The <h2> above already makes this
                 section discoverable via normal heading navigation once it loads. */ ?>
        <div id="recent-songs-list" class="list-group list-group-flush"></div>
    </div>
    <?= $homeCardEnd ?>

    <?= $homeCard('popular-songs') ?>
    <!-- Popular Songs (#303) -->
    <div class="mb-4" id="popular-songs-section">
        <h2 class="h5"><i class="fa-solid fa-fire me-2 text-warning" aria-hidden="true"></i>Popular Songs</h2>
        <?php /* a11y audit M10 — see the matching comment on Recently Viewed above. */ ?>
        <div id="popular-songs-list" class="list-group list-group-flush">
            <div class="text-muted small p-2">Loading...</div>
        </div>
    </div>
    <?= $homeCardEnd ?>

    <?= $homeCard('tags') ?>
    <!-- Popular Themes (#305 → rethought in #1148). A compact strip of
         the top themes ranked by usage (rendered client-side with song
         counts), capped so it can't become an unbounded chip wall as the
         tag vocabulary grows. "Browse all themes" reveals the full set
         inline; the dedicated searchable /themes index is the follow-on. -->
    <div class="mb-4" id="tags-section">
        <h2 class="h5"><i class="fa-solid fa-tags me-2" aria-hidden="true"></i>Popular Themes</h2>
        <?php /* a11y audit M10 — see the matching comment on Recently Viewed above. */ ?>
        <div id="tags-list" class="d-flex flex-wrap gap-2 align-items-center">
            <span class="text-muted small">Loading...</span>
        </div>
    </div>
    <?= $homeCardEnd ?>

    </div><!-- /#home-section-grid -->

    <!-- The dynamic sections above (Popular Songs, Recently Viewed,
         Browse by Theme) are populated client-side by
         js/modules/home-page.js, which the SPA router imports and
         invokes after loading this template. Keeping the logic in a
         proper module instead of an inline <script> means it runs
         reliably through the normal import graph — inline <script>
         tags in AJAX-injected HTML do not execute natively, and the
         previous re-parse shim was an extra transport dependency this
         feature does not need. -->

</section>
