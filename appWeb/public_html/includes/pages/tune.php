<?php

declare(strict_types=1);

/**
 * iHymns — Tune Public Page (#940, rewritten registry-first #1741 P4c)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Lists every song that uses a given hymn tune ("Tune: HYFRYDOL" on the song
 * page → click → every hymn sung to HYFRYDOL). Before this file, that page
 * had NO idea `tblTunes` (the #1090 tune registry) existed — it walked every
 * song's free-text `TuneName`, guessed a URL-safe spelling for each, and
 * matched that guess against the URL. #1741 P1 gave tunes a real registry
 * row (Subtitle, Disambiguation, Meter, MusicBrainz/Hymnary ids, composer
 * credits, external links) — this rewrite reads that row FIRST, keeping the
 * old guesswork only as the last-resort fallback for a tune nobody has
 * curated yet.
 *
 * DETAILED / WHY REGISTRY-FIRST, NOT REGISTRY-ONLY
 * ----------------------------------------------------------------------------
 * `song.php` (:719 header credit row, :1261 footer credit row) has EMITTED
 * `/tune/<slug>` links for years using a PHP name-fold
 * (`strtolower(trim(preg_replace('/[^A-Za-z0-9]+/','-',$tuneName),'-'))`) —
 * it has NO idea whether a `tblTunes` row exists for that name, and it
 * cannot be taught to ask without a schema-shaped change to the song
 * payload (out of scope here, plan §3.7). Meanwhil the #1090 backfill
 * (`migrate-tunes-entity.php::_migTunes_slugify()`) built `tblTunes.Slug`
 * with a DIFFERENT fold (iconv transliteration first, `-N` collision
 * suffixes) — the two diverge for accented names and any name that collided
 * with another during the backfill. A registry-only rewrite would silently
 * break every one of song.php's existing links whose slug happens to diverge,
 * and EVERY link for a tune curated (or TuneName-edited) after the backfill
 * ran, since nothing yet writes a new `tblTunes` row live (that funnel is
 * P5's `song_tune_set`, plan §3.7). Rule #33: a URL another page emits is a
 * contract — honour it or stop emitting it. This page cannot stop emitting
 * it (song.php isn't touched in this phase), so it keeps resolving it.
 *
 * THE LOOKUP LADDER (each step try/catch-wrapped; falls through on failure
 * or miss — see the inline comments at each rung for why):
 *   (a) exact `tblTunes.Slug = ?` — the #1090 backfill's own fold;
 *   (b) PHP name-fold (song.php's SAME fold) over every `tblTunes.Name`;
 *   (c) the same PHP name-fold over `tblTuneAliases` (the spelling-variant
 *       mechanism, parent plan §3B) joined back to its canonical tune;
 *   (d) `tune-registry-fallback` — the ORIGINAL pre-P4c heuristic verbatim:
 *       PHP-fold every distinct `tblSongs.TuneName` and match the URL slug.
 *       This rung is not a stopgap being phased out on a timer; it is the
 *       PERMANENT answer for any tune with no registry row, which is most
 *       of them until curators (or P5's editor funnel) create rows.
 *
 * WHAT THE REGISTRY ROW ADDS ON TOP OF THE SONG LIST (all dormant-by-data —
 * they render only when a curator or import has populated them; see
 * §3.6 of the plan for the tune-admin-CRUD gap this surfaces, filed as a
 * follow-up issue, NOT built here): Subtitle/Disambiguation in the header,
 * a Meter badge + "tunes with this meter" cross-link section (exact-match
 * v1 — metre-NORMALISED matching, e.g. treating "CM" as "86.86", is P5),
 * a MusicBrainz Work chip (linked) and Hymnary.org tune id chip (UNLINKED —
 * no confirmed public per-id URL exists for it, matching the IPI/IPN
 * unlinked-chip precedent elsewhere in this codebase), a composer/arranger/
 * harmoniser/source credits card, and the shared categorised external-links
 * panel (#1741 P4b's extraction — this is its second consumer).
 *
 * Loaded via api.php?page=tune&slug=hyfrydol. Deliberately UNCACHED — `tune`
 * is absent from api.php's `$_cacheablePages` (precedent noted at its
 * `case 'iswc':` block) and this phase does not add it (plan §0.1). Pure
 * static markup — NO EXECUTABLE INLINE `<script>` (rule #30); nothing here
 * needs JS beyond the router's existing `data-navigate` click handling
 * (plan §0.2).
 *
 * @link .claude/catalogue-1741-P4-plan.md §3.3           the P4c build spec this file implements
 * @link appWeb/public_html/includes/pages/work.php        P4b sibling — same registry-first shape, same shared partial
 * @link appWeb/public_html/includes/pages/identifier.php  P3 sibling — the breadcrumb/list a11y conventions this file adopts
 * @link appWeb/public_html/includes/tune_helpers.php      tuneTunesTableExists() / ihymns_tune_slugify() this file consumes
 * @link appWeb/.sql/schema.sql                            tblTunes/tblTuneAliases/tblTuneCredits/tblTuneExternalLinks (~3623-3715)
 * @see #1741
 */

if (!isset($songData) || !is_object($songData)) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'SongData.php';
    $songData = new SongData();
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'song_soft_delete.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'songbook_visibility.php';   /* #1765 — songServableSql() */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sort_helpers.php';   /* #1786 — ihymns_title_sort_key() */
if (!function_exists('tuneTunesTableExists')) {
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tune_helpers.php';
}

/**
 * #1969 (API-coverage batch 1, C3) — the whole resolution + song-list +
 * enrichment block that used to live inline here (IL-id pre-step, the
 * (a)/(b)/(c)/(d) lookup ladder, the song list, credits/meter/links
 * enrichment) is now `tuneResolveDisplayData()` in `includes/tune_helpers.php`
 * — extracted verbatim so the new `?action=tune` JSON endpoint (api.php)
 * can share this EXACT read path instead of forking a second copy (rule
 * #22). Calling it with `$tuneId = null` (the default) reproduces this
 * page's behaviour byte-for-byte; see that function's doc-block for the
 * full per-rung rationale, not repeated here (rule #35).
 */
$tuneSlugRaw = isset($tuneSlug) ? (string)$tuneSlug : '';
$tdb = getDbMysqli();
$_tuneResolved = tuneResolveDisplayData($tdb, $tuneSlugRaw);

$tuneSlug          = $_tuneResolved['slug'];
$tune              = $_tuneResolved['tune'];
$canonicalTune     = $_tuneResolved['canonicalTune'];
$tuneRows          = $_tuneResolved['tuneRows'];
$tuneRowsByBook    = $_tuneResolved['tuneRowsByBook'];
$tuneTotalSongs    = $_tuneResolved['tuneTotalSongs'];
$tuneCreditsByRole = $_tuneResolved['tuneCreditsByRole'];
$tuneMeterSiblings = $_tuneResolved['tuneMeterSiblings'];
$tuneLinks         = $_tuneResolved['tuneLinks'];

/* #1748 — Role => human label render map is now the shared
   IHYMNS_TUNE_CREDIT_ROLES const (includes/tune_helpers.php), in the SAME
   order as the FIELD() clause above (both come from the same const, so
   they cannot diverge). The local $tuneCreditRoleLabels copy this
   replaces was the second of the two hand-typed copies this file's
   :368-369 comment falsely claimed a guard kept in lockstep — see
   IHYMNS_TUNE_CREDIT_ROLES's own doc-block for why a central const fixes
   that for real. */

?>

<nav aria-label="Breadcrumb" class="mb-3">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="/" data-navigate="home">Home</a></li>
        <li class="breadcrumb-item active" aria-current="page">
            <i class="fa-solid fa-music me-1" aria-hidden="true"></i>
            Tune: <?= htmlspecialchars($canonicalTune !== '' ? $canonicalTune : $tuneSlug) ?>
        </li>
    </ol>
</nav>

<?php if ($tuneSlug === ''): ?>
    <div class="alert alert-warning" role="alert">
        <i class="fa-solid fa-circle-exclamation me-2" aria-hidden="true"></i>
        No tune specified.
    </div>
    <a href="/" class="btn btn-primary" data-navigate="home">
        <i class="fa-solid fa-arrow-left me-2" aria-hidden="true"></i>Back to Home
    </a>
<?php elseif ($canonicalTune === ''): ?>
    <div class="alert alert-warning" role="alert">
        <i class="fa-solid fa-circle-exclamation me-2" aria-hidden="true"></i>
        No tune named <strong><?= htmlspecialchars($tuneSlug) ?></strong> in the catalogue.
    </div>
    <p class="text-muted small">
        Tune names are imported from songbook metadata; if you expected this tune to be present, it may not have been catalogued under that exact spelling.
    </p>
    <a href="/" class="btn btn-primary" data-navigate="home">
        <i class="fa-solid fa-arrow-left me-2" aria-hidden="true"></i>Back to Home
    </a>
<?php else: ?>
    <header class="mb-4">
        <h1 class="h3 d-flex align-items-baseline gap-2 flex-wrap">
            <i class="fa-solid fa-music text-muted" aria-hidden="true"></i>
            <span><?= htmlspecialchars($canonicalTune) ?></span>
            <?php if ($tune !== null && !empty($tune['Disambiguation'])): ?>
                <small class="text-muted fw-normal">(<?= htmlspecialchars((string)$tune['Disambiguation']) ?>)</small>
            <?php endif; ?>
            <small class="text-muted ms-1"><?= $tuneTotalSongs ?> song<?= $tuneTotalSongs === 1 ? '' : 's' ?></small>
        </h1>
        <?php if ($tune !== null && !empty($tune['Subtitle'])): ?>
            <p class="text-muted mb-1"><?= htmlspecialchars((string)$tune['Subtitle']) ?></p>
        <?php endif; ?>

        <!-- Meter / MusicBrainz Work / Hymnary.org identifier row (#1741 P4c)
             — mirrors work.php's identifier-chip row + musician.php's
             MusicBrainz chip (:519-520) for the linked case, and
             musician.php's unlinked-chip fallback (:514-516/:542) for
             Hymnary.org, which has no independently-confirmed per-id URL
             (the D5 "don't invent deep-link shapes" posture,
             media_identifiers.php:143-151). WORK_IDENTIFIER_TYPES carries
             no url key for MusicBrainz Work, so the template is hardcoded
             at this render site, same as musician.php:520. -->
        <?php if ($tune !== null && (!empty($tune['MeterCode']) || !empty($tune['MusicBrainzWorkMBID']) || !empty($tune['HymnaryTuneId']))): ?>
            <div class="text-muted small d-flex flex-wrap column-gap-4 row-gap-1 mb-1">
                <?php if (!empty($tune['MeterCode'])): ?>
                    <span>
                        <i class="fa-solid fa-ruler me-1" aria-hidden="true"></i>
                        <strong>Meter:</strong>
                        <span class="badge bg-body-secondary text-body-emphasis"><?= htmlspecialchars((string)$tune['MeterCode']) ?></span>
                    </span>
                <?php endif; ?>
                <?php if (!empty($tune['MusicBrainzWorkMBID'])): ?>
                    <span>
                        <i class="fa-solid fa-compact-disc me-1" aria-hidden="true"></i>
                        <strong>MusicBrainz:</strong>&nbsp;<a class="song-meta-link"
                           href="https://musicbrainz.org/work/<?= rawurlencode((string)$tune['MusicBrainzWorkMBID']) ?>"
                           target="_blank" rel="noopener nofollow external"
                           title="MusicBrainz Work — opens in a new tab"><?= htmlspecialchars((string)$tune['MusicBrainzWorkMBID']) ?></a>
                    </span>
                <?php endif; ?>
                <?php if (!empty($tune['HymnaryTuneId'])): ?>
                    <span title="No public lookup URL">
                        <i class="fa-solid fa-barcode me-1" aria-hidden="true"></i>
                        <strong>Hymnary.org tune id:</strong> <?= htmlspecialchars((string)$tune['HymnaryTuneId']) ?>
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <p class="small text-muted mb-0">
            Hymns and worship songs sung to the tune <strong><?= htmlspecialchars($canonicalTune) ?></strong> across the iHymns catalogue.
            Worship leaders can mix-and-match lyrics across hymns that share a melody.
        </p>
    </header>

    <?php if ($tune !== null && !empty($tune['Notes'])): ?>
        <div class="card mb-3">
            <div class="card-body small">
                <?= nl2br(htmlspecialchars((string)$tune['Notes'])) ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Credits card (#1741 P4c) — composer/arranger/harmoniser/source,
         grouped; each name links to /musician/<slug> when the dormant FK
         is set, else the song.php:700 name-fold. Hidden entirely when no
         role has a credit row. -->
    <?php if (!empty($tuneCreditsByRole)): ?>
        <section class="mb-4">
            <h2 class="h6 mb-2 text-muted">
                <i class="fa-solid fa-pen-nib me-1" aria-hidden="true"></i>
                Credits
            </h2>
            <?php foreach (IHYMNS_TUNE_CREDIT_ROLES as $roleKey => $roleLabel): ?>
                <?php if (empty($tuneCreditsByRole[$roleKey])) continue; ?>
                <p class="mb-1 small">
                    <strong><?= htmlspecialchars($roleLabel) ?>:</strong>
                    <?php foreach ($tuneCreditsByRole[$roleKey] as $i => $tc): ?>
                        <?php
                            $tcName = (string)$tc['Name'];
                            $tcSlug = !empty($tc['MusicianSlug'])
                                ? (string)$tc['MusicianSlug']
                                : strtolower(str_replace(' ', '-', $tcName));
                        ?><a href="/musician/<?= rawurlencode($tcSlug) ?>"
                               class="song-meta-link"
                               data-navigate="musician"><?= htmlspecialchars($tcName) ?></a><?php if ($i < count($tuneCreditsByRole[$roleKey]) - 1): ?>;&nbsp;<?php endif; ?>
                    <?php endforeach; ?>
                </p>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <!-- "Tunes with this meter" (#1741 P4c) — exact-match v1; see the
         build-side comment above for the P5 metre-normalisation upgrade. -->
    <?php if (!empty($tuneMeterSiblings)): ?>
        <section class="mb-4">
            <h2 class="h6 mb-2 text-muted">
                <i class="fa-solid fa-ruler me-1" aria-hidden="true"></i>
                Tunes with this meter
            </h2>
            <p class="text-muted small mb-2">
                Lyrics written in this meter can be sung to any of these tunes.
            </p>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($tuneMeterSiblings as $ts): ?>
                    <a class="btn btn-sm btn-outline-secondary"
                       href="/tune/<?= htmlspecialchars((string)$ts['Slug']) ?>"
                       data-navigate="tune"><?= htmlspecialchars((string)$ts['Name']) ?></a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if (empty($tuneRowsByBook)): ?>
        <p class="text-muted small mb-0">No catalogued songs use this tune yet.</p>
    <?php else: ?>
        <!-- Sort control (#1786) — Number / Title / Songbook. One control
             governs EVERY per-songbook group below (multi-container). -->
        <?php
            $listSortSurface = 'tune-songs';
            $listSortDefault = 'Songbook & number';
            $listSortOptions = [
                'number' => ['label' => 'Number',   'type' => 'number', 'dir' => 'asc'],
                'title'  => ['label' => 'Title',    'type' => 'text',   'dir' => 'asc'],
                'book'   => ['label' => 'Songbook', 'type' => 'text',   'dir' => 'asc'],
            ];
            require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'list-sort-control.php';
        ?>
        <?php foreach ($tuneRowsByBook as $abbr => $book): ?>
            <section class="mb-4">
                <h2 class="h6 text-muted">
                    <span class="badge bg-body-secondary text-body-emphasis me-2"><?= htmlspecialchars($abbr) ?></span>
                    <?= htmlspecialchars($book['name']) ?>
                </h2>
                <div class="list-group list-group-flush song-list" role="list" data-list-sort-list="tune-songs">
                    <?php foreach ($book['rows'] as $r): ?>
                        <a class="list-group-item list-group-item-action song-list-item"
                           href="/song/<?= htmlspecialchars($r['SongId']) ?>"
                           data-navigate="song"
                           data-song-id="<?= htmlspecialchars($r['SongId']) ?>"
                           role="listitem"
                           <?php if ((int)$r['Number'] > 0): ?>data-sort-number="<?= (int)$r['Number'] ?>"<?php endif; ?>
                           data-sort-title="<?= htmlspecialchars(ihymns_title_sort_key((string)$r['Title'])) ?>"
                           data-sort-book="<?= htmlspecialchars(mb_strtolower((string)$abbr, 'UTF-8')) ?>">
                            <span class="song-number-badge"><?= (int)$r['Number'] ?: '?' ?></span>
                            <div class="song-info flex-grow-1">
                                <span class="song-title"><?= htmlspecialchars($r['Title']) ?></span>
                                <?php if (!empty($r['Language'])): ?>
                                    <small class="text-muted ms-2">
                                        <i class="fa-solid fa-language me-1" aria-hidden="true"></i><?= htmlspecialchars($r['Language']) ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                            <i class="fa-solid fa-chevron-right text-muted" aria-hidden="true"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php
        /* External-links panel (#1741 P4c §3.3.7) — the shared partial
           P4b extracted (§0.4); this is its second consumer. No local
           category-label map here — that would be the exact third-copy
           regression the partial's own doc-block was written to prevent. */
        $panelLinks     = $tuneLinks;
        $panelHeading   = 'Find this tune elsewhere';
        $panelAriaLabel = 'Find this tune elsewhere';
        require __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR
              . 'partials' . DIRECTORY_SEPARATOR . 'external-links-panel.php';
    ?>
<?php endif; ?>
