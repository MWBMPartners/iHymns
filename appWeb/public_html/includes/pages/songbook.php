<?php

/**
 * iHymns — Single Songbook Page Template
 *
 * PURPOSE:
 * Displays the list of songs within a specific songbook.
 * Shows the songbook name, song count, and a scrollable list
 * of songs with number, title, and quick-action buttons.
 *
 * Loaded via AJAX: api.php?page=songbook&id=CP
 *
 * Expects $bookId to be set by api.php before inclusion.
 */

declare(strict_types=1);

/* #1328 — hide the abbreviation badge when it just repeats the title. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'songbook_display.php';
/* #1786 — ihymns_title_sort_key() for the song list's Title sort key. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sort_helpers.php';

/* #1860 Phase 4 — dual-addressing pre-step: an IL internal id ('ILB…')
   resolves to the songbook's real Abbreviation, and $bookId is replaced
   with it, so getSongbook() below (and the canonical-URL rendering that
   follows) behaves exactly as if the curator had typed the real
   abbreviation. No ambiguity with a real abbreviation: Abbreviation is
   <=10 chars (rule #27) while an ILB id is always 13. A miss (not an IL
   id, the column doesn't exist yet, or no row carries it) leaves $bookId
   UNCHANGED. try/catch-swallowed + column-probe-gated so this fragment can
   never white-screen on an un-migrated install (the #1228 lesson). The
   canonical URL stays the Abbreviation form — this pre-step only resolves
   the LOOKUP, it does not change what getSongbook() returns or renders. */
try {
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ilyrics_id.php';
    $_ilParsed = ilidParse((string)($bookId ?? ''));
    if ($_ilParsed !== null && $_ilParsed['entityType'] === 'songbook') {
        $_ilDb = getDbMysqli();
        $_ilColProbe = $_ilDb->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongbooks' AND COLUMN_NAME = 'IlId' LIMIT 1"
        );
        $_ilColExists = $_ilColProbe && $_ilColProbe->fetch_row() !== null;
        if ($_ilColProbe) { $_ilColProbe->free(); }
        if ($_ilColExists) {
            $_ilStmt = $_ilDb->prepare('SELECT Abbreviation FROM tblSongbooks WHERE IlId = ? LIMIT 1');
            $_ilStmt->bind_param('s', $_ilParsed['canonical']);
            $_ilStmt->execute();
            $_ilRow = $_ilStmt->get_result()->fetch_assoc();
            $_ilStmt->close();
            if ($_ilRow !== null && (string)($_ilRow['Abbreviation'] ?? '') !== '') {
                $bookId = (string)$_ilRow['Abbreviation'];
            }
        }
    }
} catch (\Throwable $_ilE) {
    // dormant-by-design — fall through with $bookId unchanged
}

/* Fetch songbook. */
$book = $songData->getSongbook($bookId);

/* Handle invalid songbook ID — themed error card (unified renderer). */
if ($book === null) {
    http_response_code(404);
    if (function_exists('renderErrorFragment')) {
        echo renderErrorFragment(404, [
            'title'   => 'Songbook not found',
            'message' => 'We couldn\'t find a songbook matching "' . $bookId . '". It may have been renamed or removed.',
            'fa'      => 'fa-book-open',
            'actions' => [
                ['label' => 'All Songbooks', 'href' => '/songbooks', 'navigate' => 'songbooks', 'primary' => true, 'fa' => 'fa-book-open'],
                ['label' => 'Search',        'href' => '/search',     'navigate' => 'search',    'fa' => 'fa-magnifying-glass'],
            ],
        ]);
    } else {
        echo '<div class="alert alert-warning" role="alert">Songbook not found: <strong>'
           . htmlspecialchars($bookId) . '</strong></div>';
    }
    return;
}

/* #1037 — song list: SLIM projection instead of getSongs()'s full lyric +
   credit hydration. getSongs($bookId) used to assemble every song's
   verses/chorus lines (via the tblLyricLines mirror, includes/lyric_lines_read.php)
   PLUS six credit collections (writers/composers/arrangers/adaptors/
   translators/artists) PLUS tags PLUS external links — all of it thrown
   away except id/number/title/verified/writers/hasAudio/hasSheetMusic
   below. For Mission Praise (~3,517 songs) that meant assembling the
   WHOLE hymnal's lyric text on every uncached view of a page that only
   ever renders titles — one of the two biggest public pages doing this
   on shared hosting (CLAUDE.md rule #17 / the #929 OOM class).

   getSongsSlimIndex() is the sanctioned scoped replacement (rule #17):
   ONE lightweight query, no lyrics, no components, no credits — the same
   method the Song Editor sidebar uses to list the whole catalogue
   without downloading the corpus.

   Called with the songbook abbreviation so the SCOPE happens in SQL
   (#1037). It previously fetched every song in the catalogue and filtered
   in PHP: correct, and still far cheaper than the full hydration it
   replaced, but ~14,500 rows pulled to render one book of ~3,500 — four
   times the rows and a whole-table scan, on the second-biggest public
   page, on shared hosting. The ORDER BY inside the method is identical
   either way, so the per-book order is unchanged. */
$bookAbbr = strtoupper(trim((string)$book['id']));
$songs = $songData->getSongsSlimIndex($bookAbbr);

/* `verified` (the lyrics-verified checkmark) and `writers` (the byline
   under the title) are NOT in the slim index — it's deliberately
   lyric/credit-free — but both render per row below. Rather than fall
   back to getSongs()'s full hydration (defeats the whole point of this
   change) or fetch them one song at a time (N+1 is not an improvement
   over N² — CLAUDE.md rule #17), pull both in ONE additional query
   scoped to this songbook only (never per row), keyed by SongId.
   tblSongWriters is one-to-many, so the LEFT JOIN fans out one row per
   writer (or a single NULL-writer row for a song with none) — collapsed
   back into per-song shapes in the loop below. */
$verifiedMap = [];
$writersMap  = [];
if (!empty($songs)) {
    $creditsDb = getDbMysqli();
    /* @deleted-visible: pure DECORATION of the filtered slim index (#1694) —
       these rows are keyed by SongId and only ever READ for ids in $songs,
       which getSongsSlimIndex() has already filtered; a hidden song's row is
       fetched and never consumed, so no leak is possible. Kept predicate-free
       deliberately: test-songbook-render-parity.php renders this REAL template
       against a stub getDbMysqli(), and loading the predicate helper here
       would drag the real db_mysql.php into that stubbed world (redeclare
       fatal) for zero behavioural gain.
       @disabled-visible: same reasoning, one predicate over (#1765) — a
       disabled book's songs are ALREADY absent from $songs (getSongsSlimIndex()
       delegates to SongData::_visible(), which now composes songServableSql()
       too), so this decoration query's own rows for that book are minted but
       never consumed either. Moot in practice: $book itself would already be
       null (404, above) for a disabled book's OWN page — this only matters
       for the theoretical case of a song's SongbookAbbr disagreeing with the
       page's $bookAbbr, which getSongsSlimIndex($bookAbbr) already scopes out. */
    $creditsStmt = $creditsDb->prepare(
        'SELECT s.SongId AS songId, s.Verified AS verified, w.Name AS writerName
           FROM tblSongs s
           LEFT JOIN tblSongWriters w ON w.SongId = s.SongId
          WHERE s.SongbookAbbr = ?
          ORDER BY s.SongId, w.Id'
    );
    $creditsStmt->bind_param('s', $bookAbbr);
    $creditsStmt->execute();
    $creditsResult = $creditsStmt->get_result();
    while ($creditsRow = $creditsResult->fetch_assoc()) {
        $creditSongId = (string)$creditsRow['songId'];
        if (!array_key_exists($creditSongId, $verifiedMap)) {
            $verifiedMap[$creditSongId] = (bool)$creditsRow['verified'];
        }
        if ($creditsRow['writerName'] !== null && $creditsRow['writerName'] !== '') {
            $writersMap[$creditSongId][] = (string)$creditsRow['writerName'];
        }
    }
    $creditsStmt->close();
}

?>

<!-- ================================================================
     SONGBOOK PAGE — Song list for a specific songbook
     ================================================================ -->
<section class="page-songbook" aria-label="<?= htmlspecialchars($book['name']) ?>" data-songbook-abbr="<?= htmlspecialchars($book['id']) ?>">

    <!-- Breadcrumb navigation with schema.org markup (#151) -->
    <nav aria-label="Breadcrumb" class="mb-3">
        <ol class="breadcrumb" itemscope itemtype="https://schema.org/BreadcrumbList">
            <li class="breadcrumb-item" itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
                <a href="/songbooks" data-navigate="songbooks" itemprop="item">
                    <span itemprop="name">Songbooks</span>
                </a>
                <meta itemprop="position" content="1">
            </li>
            <li class="breadcrumb-item active" aria-current="page" itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
                <span itemprop="name"><?= htmlspecialchars($book['name']) ?></span>
                <meta itemprop="position" content="2">
            </li>
        </ol>
    </nav>

    <!-- Songbook header -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h4 mb-1">
                <i class="fa-solid fa-book me-2" aria-hidden="true"></i>
                <?= htmlspecialchars($book['name']) ?>
                <?php $sbAbbr = ihymns_songbook_abbr_label($book['id'] ?? '', $book['displayAbbr'] ?? null); ?>
                <?php if (ihymns_songbook_show_abbr($book['name'] ?? '', $sbAbbr)): ?>
                <span class="badge bg-body-secondary ms-1"><?= htmlspecialchars($sbAbbr) ?></span>
                <?php endif; ?>
                <?php if (empty($book['isOfficial'])): ?>
                    <!-- #1223 — "Unofficial" badge on the songbook header so the
                         distinction persists after click-through from the list / home.
                         NOT aria-hidden here: the <h1> is read normally (no
                         stretched-link folding), so "Unofficial" is part of the
                         accessible heading. -->
                    <span class="badge songbook-unofficial-badge ms-1"
                          title="Unofficial songbook">Unofficial</span>
                <?php endif; ?>
            </h1>
            <p class="text-muted mb-0"><?= number_format($book['songCount']) ?> songs</p>
            <?php
                /* Feature 2 (#1765) — informational Public Domain line for
                   the songbook itself (as a published work). Gated on the
                   key actually being present — SongData::_songbookFlagsSelect()
                   only emits `isPublicDomain` once the migration has landed,
                   so `array_key_exists` (not `!empty`) is the pre-migration
                   safety check; NEVER a content gate, matching the plan's
                   "ONE IsPublicDomain flag … never a gate" decision. */
                if (array_key_exists('isPublicDomain', $book) && !empty($book['isPublicDomain'])):
            ?>
                <p class="text-muted small mb-0 mt-1" data-credit-kind="public-domain">
                    <i class="fa-regular fa-copyright me-1" aria-hidden="true"></i>
                    Public Domain
                </p>
            <?php endif; ?>
            <?php
                /* #831 — "Compiled by …" line. Each compiler links to
                   their /musician/<slug> page when one exists; falls back
                   to a plain name span otherwise. Multiple compilers
                   joined with " · " for visual lightness. Hidden when
                   the songbook has no compilers attached (or on
                   pre-migration deployments where SongData returns an
                   empty list). */
                $compilers = $book['compilers'] ?? [];
                if (!empty($compilers)):
            ?>
                <p class="text-muted small mb-0 mt-1">
                    <i class="fa-solid fa-pen-nib me-1" aria-hidden="true"></i>
                    Compiled by
                    <?php foreach ($compilers as $i => $c): ?>
                        <?php if ($i > 0): ?> &middot; <?php endif; ?>
                        <?php if (!empty($c['slug'])): ?>
                            <a href="/musician/<?= rawurlencode($c['slug']) ?>"
                               data-navigate="musician"
                               class="text-reset text-decoration-underline"><?= htmlspecialchars($c['name']) ?></a>
                        <?php else: ?>
                            <span><?= htmlspecialchars($c['name']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($c['note'])): ?>
                            <span class="text-muted">(<?= htmlspecialchars($c['note']) ?>)</span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
            <?php
                /* #832 — "Also known as …" line. Hidden when this
                   songbook has no alt names (or pre-migration). */
                $altNames = $book['alternativeNames'] ?? [];
                if (!empty($altNames)):
            ?>
                <p class="text-muted small mb-0 mt-1">
                    <span class="me-1">Also known as:</span>
                    <?php foreach ($altNames as $i => $a): ?>
                        <?php if ($i > 0): ?>, <?php endif; ?>
                        <em><?= htmlspecialchars($a['title']) ?></em>
                        <?php if (!empty($a['note'])): ?>
                            <span class="text-muted">(<?= htmlspecialchars($a['note']) ?>)</span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
        </div>
        <div class="d-flex gap-2">
            <!-- Number pad search for this songbook -->
            <button type="button"
                    class="btn btn-outline-primary btn-sm"
                    data-action="open-numpad"
                    data-numpad-book="<?= htmlspecialchars($book['id']) ?>"
                    aria-label="Search by number in <?= htmlspecialchars($book['name']) ?>">
                <i class="fa-solid fa-hashtag me-1" aria-hidden="true"></i>
                By Number
            </button>
            <!-- Shuffle within this songbook -->
            <button type="button"
                    class="btn btn-outline-secondary btn-sm"
                    data-action="shuffle-book"
                    data-shuffle-book="<?= htmlspecialchars($book['id']) ?>"
                    aria-label="Random song from <?= htmlspecialchars($book['name']) ?>">
                <i class="fa-solid fa-shuffle me-1" aria-hidden="true"></i>
                Shuffle
            </button>
            <!-- Export this whole songbook to a worship-presentation format
                 (#1166). Wired by export-ui.js (initSongbookExport). The
                 fragment used to bootstrap that wiring itself via an inline
                 <script> below the header, but the enforcing nonce CSP
                 (#117) refuses nonce-less inline scripts and this response
                 is a shared-cache fragment that can never carry one (rule
                 #6) — the script never ran. The SPA router now imports
                 export-ui.js and calls initSongbookExport() once this
                 fragment lands (#1565); see router.js afterPageLoad(). -->
            <div class="btn-group">
                <button type="button"
                        class="btn btn-outline-secondary btn-sm dropdown-toggle btn-export-songbook"
                        data-bs-toggle="dropdown" aria-expanded="false"
                        aria-label="Export <?= htmlspecialchars($book['name']) ?> to a worship-presentation format">
                    <i class="fa-solid fa-file-export me-1" aria-hidden="true"></i>
                    Export
                </button>
                <?php
                    /* #1570 — one shared partial for both the song and songbook
                       export menus (adds ChordPro here — every format's exporter
                       already supports a songbook export, this menu had simply
                       drifted out of sync with song.php's). See the partial's
                       doc-block for the $exportMenuSurface contract. */
                    $exportMenuSurface = 'songbook';
                    require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'export-menu.php';
                ?>
            </div>
        </div>
    </div>

    <?php
        /* #833 — "Find this songbook elsewhere" panel. Reads from the
           unified tblSongbookExternalLinks table; legacy URL columns
           (WebsiteUrl / InternetArchiveUrl / WikipediaUrl) are STILL
           rendered below as a fallback when the new system has no rows
           for this book — keeps pre-backfill deployments visually
           coherent. The new system's links group by Category so curators
           can see the listing organised by purpose. */
        $links = $book['links'] ?? [];
        $linksByCat = [];
        foreach ($links as $l) {
            $cat = (string)($l['category'] ?? 'other');
            if (!isset($linksByCat[$cat])) $linksByCat[$cat] = [];
            $linksByCat[$cat][] = $l;
        }
        $catLabels = [
            'official'    => 'Official',
            'information' => 'Information',
            'read'        => 'Read',
            'sheet-music' => 'Sheet music',
            'listen'      => 'Listen',
            'watch'       => 'Watch',
            'purchase'    => 'Purchase',
            'authority'   => 'Authority',
            'social'      => 'Social',
            'other'       => 'Other',
        ];
        if (!empty($links)):
    ?>
        <div class="card bg-dark border-secondary mb-3">
            <div class="card-body">
                <h2 class="h6 mb-3 text-muted">
                    <i class="fa-solid fa-link me-1" aria-hidden="true"></i>
                    Find this songbook elsewhere
                </h2>
                <?php foreach ($catLabels as $cat => $catLabel): ?>
                    <?php if (empty($linksByCat[$cat])) continue; ?>
                    <div class="mb-2">
                        <div class="text-uppercase small text-muted mb-1"><?= htmlspecialchars($catLabel) ?></div>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($linksByCat[$cat] as $l): ?>
                                <a href="<?= htmlspecialchars($l['url']) ?>"
                                   target="_blank" rel="noopener nofollow"
                                   class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-2">
                                    <?php if (!empty($l['iconClass'])): ?>
                                        <i class="<?= htmlspecialchars($l['iconClass']) ?>" aria-hidden="true"></i>
                                    <?php endif; ?>
                                    <span><?= htmlspecialchars($l['name']) ?></span>
                                    <?php if (!empty($l['note'])): ?>
                                        <span class="text-muted small">— <?= htmlspecialchars($l['note']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($l['verified'])): ?>
                                        <i class="fa-solid fa-circle-check text-success small" aria-label="Verified" title="Verified"></i>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Sort control (#1786) — Number / Title / Writer. `number` is an
         explicit option (only an explicit level can be direction-flipped;
         the server default is ALSO number-first, so Default and "Number ↑"
         happen to render identically — the control still needs it as a
         pickable level for "Number ↓" and for combining with a second
         level). -->
    <?php
        $listSortSurface = 'songbook-songs';
        $listSortDefault = 'Number';
        $listSortOptions = [
            'number'  => ['label' => 'Number', 'type' => 'number', 'dir' => 'asc'],
            'title'   => ['label' => 'Title',  'type' => 'text',   'dir' => 'asc'],
            'writers' => ['label' => 'Writer', 'type' => 'text',   'dir' => 'asc'],
        ];
        require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'list-sort-control.php';
    ?>

    <!-- Song list -->
    <div class="list-group song-list" role="list" data-list-sort-list="songbook-songs">
        <?php foreach ($songs as $song): ?>
            <?php
                /* #1786 sort-key attributes. A missing data-sort-number
                   (unnumbered Misc/unofficial song) sorts AFTER every
                   numbered song in both directions (list-sort.js's
                   multiKeyCompareMissingLast) — matching the un-numbered
                   tail this page's server default already produces. */
                $songSortWriters = !empty($writersMap[$song['id']])
                    ? mb_strtolower(implode('; ', $writersMap[$song['id']]), 'UTF-8')
                    : '';
            ?>
            <a href="/song/<?= htmlspecialchars($song['id']) ?>"
               class="list-group-item list-group-item-action song-list-item"
               data-navigate="song"
               data-song-id="<?= htmlspecialchars($song['id']) ?>"
               role="listitem"
               <?php if (isset($song['number']) && $song['number'] !== null && (int)$song['number'] > 0): ?>data-sort-number="<?= (int)$song['number'] ?>"<?php endif; ?>
               data-sort-title="<?= htmlspecialchars(ihymns_title_sort_key((string)$song['title'])) ?>"
               <?php if ($songSortWriters !== ''): ?>data-sort-writers="<?= htmlspecialchars($songSortWriters) ?>"<?php endif; ?>
               aria-label="<?= isset($song['number']) && $song['number'] !== null ? 'Song ' . (int)$song['number'] . ': ' : '' ?><?= htmlspecialchars(toTitleCase($song['title'])) ?>">
                <!-- Song number badge — left empty when the song has no
                     songbook position (Number IS NULL, e.g. Misc or
                     unofficial-songbook songs). The CSS rule
                     `.song-number-badge:empty::before` then renders a
                     book glyph as the fallback (#392). -->
                <span class="song-number-badge" data-songbook="<?= htmlspecialchars($bookId) ?>" aria-hidden="true"><?php
                    if (isset($song['number']) && $song['number'] !== null && (int)$song['number'] > 0) {
                        echo (int)$song['number'];
                    }
                ?></span>
                <!-- Song info -->
                <div class="song-info flex-grow-1">
                    <span class="song-title"><?= htmlspecialchars(toTitleCase($song['title'])) ?><?php if (!empty($verifiedMap[$song['id']])): ?><span class="verified-badge" title="Verified lyrics" aria-label="Verified lyrics"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" fill="currentColor" opacity="0.15"/><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.5" fill="none"/><path d="M7.5 12.5L10.5 15.5L16.5 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span><?php endif; ?></span>
                    <?php if (!empty($writersMap[$song['id']])): ?>
                        <small class="song-writers text-muted d-block">
                            <?= htmlspecialchars(implode(', ', $writersMap[$song['id']])) ?>
                        </small>
                    <?php endif; ?>
                </div>
                <!-- Indicators -->
                <div class="song-indicators">
                    <?php if (!empty($song['hasAudio'])): ?>
                        <i class="fa-solid fa-headphones text-muted" aria-label="Has audio" title="Audio available"></i>
                    <?php endif; ?>
                    <?php if (!empty($song['hasSheetMusic'])): ?>
                        <i class="fa-solid fa-file-pdf text-muted" aria-label="Has sheet music" title="Sheet music available"></i>
                    <?php endif; ?>
                    <i class="fa-solid fa-chevron-right text-muted" aria-hidden="true"></i>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

</section>
