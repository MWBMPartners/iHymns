<?php

/**
 * iHymns — Songbooks List Page Template
 *
 * PURPOSE:
 * Displays all available songbooks in a grid layout with song counts
 * and quick navigation. Each songbook card links to the song list
 * for that songbook.
 *
 * Loaded via AJAX: api.php?page=songbooks
 */

declare(strict_types=1);

/* #856 — language-name resolver for the tile badge tooltip. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'language_names.php';
/* #1328 — hide the abbreviation badge when it just repeats the title. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'songbook_display.php';

$songbooks = $songData->getSongbooks();
$stats = $songData->getStats();

?>

<!-- ================================================================
     SONGBOOKS PAGE — Browse all songbooks
     ================================================================ -->
<section class="page-songbooks" aria-label="Songbooks">

    <!-- Page header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0">
            <i class="fa-solid fa-book-open me-2" aria-hidden="true"></i>
            Songbooks
        </h1>
        <span class="badge bg-primary bg-gradient rounded-pill">
            <?= number_format($stats['totalSongs']) ?> songs total
        </span>
    </div>

    <!-- Language filter (#679) — same partial as the home-page
         Songbooks section. Silently returns early when the catalogue
         spans only one language. -->
    <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'songbook-language-filter.php'; ?>

    <!-- Songbook Grid -->
    <div class="row g-3">
        <?php foreach ($songbooks as $index => $book): ?>
            <?php if (($book['songCount'] ?? 0) > 0): ?>
                <?php
                    /* Language indicator badge data (#680) — same pattern
                       as home.php: pull the IETF tag, extract the 2-3
                       letter language subtag, uppercase. Empty = no
                       badge (multi-lingual / not specified).
                       #857 — also expose the contained-languages union
                       so a book tagged English but holding Afrikaans
                       songs surfaces under an Afrikaans filter. */
                    $bookLang = (string)($book['language'] ?? '');
                    $langCode = '';
                    if ($bookLang !== '' && preg_match('/^([a-z]{2,3})/i', $bookLang, $m)) {
                        $langCode = mb_strtoupper($m[1]);
                    }
                    $bookLangs    = $book['languages'] ?? [];
                    $bookLangsCsv = !empty($bookLangs) ? implode(',', $bookLangs) : '';
                    $bookLangNames = [];
                    foreach ($bookLangs as $sub) {
                        $bookLangNames[] = resolveLanguageName($sub);
                    }
                    $bookLangsTitle = !empty($bookLangNames)
                        ? implode(', ', $bookLangNames)
                        : resolveLanguageName($bookLang);
                    /* #1223 — unofficial-songbook flag. SongData casts
                       tblSongbooks.IsOfficial (#502) to a strict bool;
                       empty() also treats a missing key (pre-migration /
                       cached fragment) as unofficial-safe. Drives the
                       "Unofficial" badge below + the "(unofficial songbook)"
                       clause folded into the card's accessible name, mirroring
                       the language-badge a11y pattern (#680 / #856). */
                    $isUnofficial = empty($book['isOfficial']);
                ?>
                <div class="col-12 col-sm-6 col-md-4 col-lg-3" id="songbook-<?= htmlspecialchars($book['id']) ?>">
                    <div class="card card-songbook h-100 position-relative"
                         data-songbook-id="<?= htmlspecialchars($book['id']) ?>"
                         data-songbook-songs="<?= (int)$book['songCount'] ?>"
                         <?php if ($langCode !== ''): ?>data-songbook-language="<?= htmlspecialchars($bookLang) ?>"<?php endif; ?>
                         <?php if ($bookLangsCsv !== ''): ?>data-songbook-languages="<?= htmlspecialchars($bookLangsCsv) ?>"<?php endif; ?>>
                        <!-- Stretched link covers the whole card; the
                             absolute-positioned download button below
                             stays clickable because its z-index sits
                             above the link. Mirrors the home-page
                             tile pattern (#453 / #785). -->
                        <a href="/songbook/<?= htmlspecialchars($book['id']) ?>"
                           class="stretched-link text-decoration-none text-reset"
                           data-navigate="songbook"
                           aria-label="Open <?= htmlspecialchars($book['name']) ?><?= $isUnofficial ? ' (unofficial songbook)' : '' ?> — <?= number_format($book['songCount']) ?> songs<?= $langCode !== '' ? ' (' . htmlspecialchars($langCode) . ')' : '' ?>"></a>
                        <?php if ($langCode !== ''): ?>
                            <!-- #856 / #857: tooltip resolves the IETF tag to
                                 the full language name; when the book contains
                                 songs in multiple languages, all are listed. -->
                            <span class="songbook-tile-language-badge"
                                  title="<?= htmlspecialchars($bookLangsTitle) ?>"
                                  aria-hidden="true"><?= htmlspecialchars($langCode) ?></span>
                        <?php endif; ?>
                        <div class="card-body">
                            <div class="d-flex align-items-start gap-3">
                                <!-- Songbook icon -->
                                <div class="songbook-icon songbook-icon-<?= htmlspecialchars($book['id']) ?> flex-shrink-0">
                                    <i class="fa-solid fa-book" aria-hidden="true"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <h2 class="h6 card-title mb-1">
                                        <?= htmlspecialchars($book['name']) ?>
                                    </h2>
                                    <?php $sbAbbr = ihymns_songbook_abbr_label($book['id'] ?? '', $book['displayAbbr'] ?? null); ?>
                                    <?php if (ihymns_songbook_show_abbr($book['name'] ?? '', $sbAbbr)): ?>
                                    <span class="badge bg-body-secondary me-1">
                                        <?= htmlspecialchars($sbAbbr) ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php if ($isUnofficial): ?>
                                        <!-- #1223 — "Unofficial" badge. Surfaces unofficial
                                             songbooks as first-class-but-badged alongside the
                                             official ones (owner decision; presentation-only,
                                             no storage merge — converting a book to a catalogue
                                             would orphan its songs, see the issue). aria-hidden
                                             because the stretched-link's accessible name above
                                             already carries "(unofficial songbook)" — same a11y
                                             pattern as the language badge (#680 / #856). -->
                                        <span class="badge songbook-unofficial-badge"
                                              title="Unofficial songbook"
                                              aria-hidden="true">Unofficial</span>
                                    <?php endif; ?>
                                    <p class="text-muted small mb-0 mt-1">
                                        <?= number_format($book['songCount']) ?> songs
                                    </p>
                                    <?php
                                    /* #782 phase D — "Part of: <Series>" line.
                                       Same shape as the home-grid tile in
                                       includes/pages/home.php. Renders only
                                       when the songbook is in at least one
                                       series; multiple series joined with " · ".
                                       Kept on its own row to avoid stretching
                                       the songCount line on tight viewports. */
                                    $bookSeries = (array)($book['series'] ?? []);
                                    if ($bookSeries):
                                        $names = array_map(
                                            static fn(array $s): string => htmlspecialchars((string)($s['name'] ?? '')),
                                            $bookSeries
                                        );
                                    ?>
                                    <p class="text-muted small mb-0 mt-1 songbook-tile-series"
                                       title="Part of <?= count($bookSeries) ?> series">
                                        <i class="fa-solid fa-layer-group me-1" aria-hidden="true"></i>
                                        <span class="visually-hidden">Part of </span><?= implode(' · ', $names) ?>
                                    </p>
                                    <?php endif; ?>
                                </div>
                                <!-- "More to see" chevron. Hidden on xs
                                     (portrait phone) where the absolute
                                     [badge + download-button] cluster in
                                     the top-right already occupies the
                                     right side and full-width tiles need
                                     every horizontal pixel for the title.
                                     From sm up the tile is narrower per
                                     column but the page chrome is more
                                     spacious, so the chevron returns as
                                     a navigation hint. -->
                                <i class="fa-solid fa-chevron-right text-muted mt-1 d-none d-sm-block card-songbook-arrow"
                                   aria-hidden="true"></i>
                            </div>
                        </div>
                        <!-- Offline-download button (#453). Hidden on
                             browsers that don't support offline caching
                             via body.offline-unsupported in app.css.
                             On xs, this is the sole right-side
                             affordance (the chevron is hidden); from sm
                             up it sits in the corner with the chevron
                             tucked underneath in the flex row. -->
                        <button type="button"
                                class="btn btn-sm btn-outline-secondary songbook-download-btn"
                                data-songbook-download="<?= htmlspecialchars($book['id']) ?>"
                                aria-label="Download <?= htmlspecialchars($book['name']) ?> for offline use"
                                title="Download this songbook for offline use">
                            <i class="fa-solid fa-cloud-arrow-down" aria-hidden="true"></i>
                        </button>
                        <!-- Export this whole songbook to a worship-presentation format
                             (#1607). Owner decision: whole-songbook export lives on
                             /songbooks and /songbook/<abbr> ONLY, never inside the
                             single-song view (keeps that dropdown uncluttered) — see
                             song.php's .song-export-menu, which stays single-song-only.
                             Sits directly below the download button in the same
                             top-right corner cluster (raised z-index above the
                             stretched-link, same trick as that button). Wired by
                             export-ui.js's initSongbookListExport() from router.js's
                             afterPageLoad() (rule #30 — this fragment is shared-cache,
                             #1565, so it can never carry an inline <script>).
                             The button's aria-label names THIS book by name so N tiles
                             on the page each get a distinguishing accessible name
                             (WCAG 2.5.3 Label in Name — same pattern as #680/#856's
                             language badge). Guarded against a 0-song tile even though
                             the enclosing `if ($book['songCount'] > 0)` above already
                             excludes those from this page entirely — belt-and-braces
                             in case that filter ever changes; an empty book would
                             otherwise throw 'no songs to export' deep inside the
                             fetch/format pipeline instead of failing at the control. -->
                        <div class="btn-group songbook-export-tile-group">
                            <button type="button"
                                    class="btn btn-sm btn-outline-secondary dropdown-toggle songbook-export-tile-btn"
                                    data-bs-toggle="dropdown" aria-expanded="false"
                                    aria-label="Export <?= htmlspecialchars($book['name']) ?> to a worship-presentation format"
                                    title="Export this songbook"
                                    <?php if (((int)($book['songCount'] ?? 0)) < 1): ?>disabled aria-disabled="true"<?php endif; ?>>
                                <i class="fa-solid fa-file-export" aria-hidden="true"></i>
                            </button>
                            <?php
                                $exportMenuSurface = 'songbook-list';
                                require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'export-menu.php';
                            ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

</section>
