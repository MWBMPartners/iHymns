<?php

declare(strict_types=1);

/**
 * iHymns — Unified external-identifier public page (#1741 P3)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * One page that answers "what's behind this code?" for SIX kinds of external
 * identifier — ISWC, CCLI, BOWI, ISRC (all reached at `/iswc/<code>`,
 * `/ccli/<code>`, `/bowi/<code>`, `/isrc/<code>`) and IPI, ISNI (reached at
 * `/ipi/<code>`, `/isni/<code>`). Two songs (or a song and a Work record)
 * that share an ISWC/CCLI/ISRC are, by definition, the same composition or
 * recording even when the title/songbook/arrangement differs; two musician
 * credits that share an IPI/ISNI are, by definition, the same person or
 * ensemble even when the display name on file differs. This page is the
 * fastest cross-link available for any of the six, because the identifier is
 * canonical (unlike titles, which drift across hymnals and imports).
 *
 * DETAILED
 * --------
 * This file ABSORBS the old `includes/pages/iswc.php` (#940), which is
 * DELETED in this same commit — `page=iswc` now repoints here rather than to
 * its own file (api.php's `case 'iswc':` falls through into the same block
 * as the five new schemes; see the P3 build-scoping doc). The markup below
 * reuses `iswc.php`'s exact idioms (breadcrumb shape, `song-list-item` /
 * `song-number-badge` classes, `data-navigate="song"`/`"work"`/`"musician"`)
 * so nothing about how a song/work list LOOKS changes for the pre-existing
 * `/iswc/<code>` route — only the SOURCE of the data (the shared resolver)
 * and the fact that five siblings now share this file with it.
 *
 * Loaded via `api.php?page=<scheme>&code=<code>` where `<scheme>` is one of
 * `iswc|ccli|bowi|isrc|ipi|isni` — api.php sets `$idScheme` (the page name,
 * always one of the six) and `$idCode` (the raw, still-unnormalised `?code=`
 * value) before requiring this file. All normalisation and every DB query
 * live in `includes/identifier_resolve.php`; this file is markup only.
 *
 * WHAT RENDERS, PER SCHEME'S DECLARED `entity` (`IHYMNS_ID_SCHEMES` in
 * `includes/identifier_normalize.php`):
 *   - iswc / ccli (entity='work', multi-song): an optional "Linked Work"
 *     header (when a `tblWorks` row carries this exact code) PLUS the full
 *     song list, grouped by songbook — a hymn text can be set by many
 *     arrangements across many books, all sharing one ISWC/CCLI.
 *   - bowi (entity='work', NOT multi-song): the Work header only — Luminate's
 *     Bank of Work Identifiers has no song-list concept in this catalogue.
 *   - isrc (entity='song'): the song list only — ISRC identifies a specific
 *     RECORDING, which has no Work-record concept here.
 *   - ipi / isni (entity='musician'): a list of matching `tblMusicians`
 *     entries, reusing the exact query shape `api.php`'s dormant
 *     `person_by_identifier`/`musician_by_identifier` JSON action already
 *     runs (this is that query's first real page consumer).
 *
 * Deliberately UNCACHED (no addition to api.php's `$_cacheablePages`) — same
 * precedent as the pre-existing `iswc`/`tune` routes it now shares a block
 * with: a cheap, indexed, anonymous read, so the caching optimisation isn't
 * needed for the feature to ship (rule #6 — this is an explicit choice, not
 * an oversight; see the comment at api.php's `case 'iswc':` block).
 *
 * NO EXECUTABLE INLINE SCRIPT TAG (rule #30) — this fragment is pure markup,
 * exactly like the `iswc.php` it replaces; the enforcing nonce CSP would
 * silently refuse an inline script here anyway (this fragment can also be
 * served through the `$_cacheablePages` machinery on other pages in this
 * same switch group, so — like every `includes/pages/*.php` fragment — it
 * must assume it may end up in a shared cache with no per-request nonce).
 *
 * @link .claude/catalogue-expansion-1741-plan.md §4        the P3 build-scoping ground truth this file implements
 * @link appWeb/public_html/includes/identifier_resolve.php  the resolver this page calls (all DB access lives there)
 * @link appWeb/public_html/includes/identifier_normalize.php the IHYMNS_ID_SCHEMES registry (label/entity per scheme)
 * @see #1741
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'identifier_resolve.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sort_helpers.php';   /* #1786 — ihymns_title_sort_key() */

$idScheme = isset($idScheme) ? (string)$idScheme : '';
$idCode   = isset($idCode) ? trim((string)$idCode) : '';

$idRegistry = IHYMNS_ID_SCHEMES[$idScheme] ?? null;
$idLabel    = (string)($idRegistry['label'] ?? strtoupper($idScheme));

try {
    $idResult = ihymns_resolve_identifier(getDbMysqli(), $idScheme, $idCode);
} catch (\Throwable $e) {
    /* Belt-and-braces: ihymns_resolve_identifier() is designed to never
       throw (every internal lookup degrades to an empty result under its
       own try/catch — see identifier_resolve.php's doc-block), so reaching
       this branch means something failed even before a lookup could run
       (e.g. getDbMysqli() itself, on a total DB outage). Render the same
       themed error card every other public page falls back to rather than
       a bare PHP error / white screen. */
    error_log('[pages/identifier.php] resolve failed: ' . $e->getMessage());
    http_response_code(500);
    if (function_exists('renderErrorFragment')) {
        echo renderErrorFragment(500, [
            'title'   => 'Lookup failed',
            'message' => 'Something went wrong looking up this identifier. Please try again shortly.',
            'fa'      => 'fa-triangle-exclamation',
        ]);
    } else {
        echo '<div class="alert alert-danger" role="alert">Lookup failed.</div>';
    }
    return;
}

$idCanonical = (string)$idResult['canonical'];
$idEntity    = (string)$idResult['entity'];
$idWork      = $idResult['work'];
$idSongs     = $idResult['songs'];
$idMusicians = $idResult['musicians'];

?>

<nav aria-label="Breadcrumb" class="mb-3">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="/" data-navigate="home">Home</a></li>
        <li class="breadcrumb-item active" aria-current="page">
            <i class="fa-solid fa-barcode me-1" aria-hidden="true"></i>
            <?= htmlspecialchars($idLabel) ?>: <?= htmlspecialchars($idCanonical !== '' ? $idCanonical : '?') ?>
        </li>
    </ol>
</nav>

<?php if ($idCanonical === ''): ?>
    <div class="alert alert-warning" role="alert">
        <i class="fa-solid fa-circle-exclamation me-2" aria-hidden="true"></i>
        No <?= htmlspecialchars($idLabel) ?> specified.
    </div>
    <a href="/" class="btn btn-primary" data-navigate="home">
        <i class="fa-solid fa-arrow-left me-2" aria-hidden="true"></i>Back to Home
    </a>
<?php else: ?>
    <header class="mb-4">
        <h1 class="h3 d-flex align-items-baseline gap-2">
            <i class="fa-solid fa-barcode text-muted" aria-hidden="true"></i>
            <span><?= htmlspecialchars($idLabel) ?>: <?= htmlspecialchars($idCanonical) ?></span>
        </h1>

        <?php if ($idWork !== null): ?>
            <p class="small mb-0">
                <i class="fa-solid fa-diagram-project me-1 text-muted" aria-hidden="true"></i>
                Linked Work:
                <a href="/work/<?= htmlspecialchars($idWork['slug']) ?>" data-navigate="work">
                    <?= htmlspecialchars($idWork['title']) ?>
                </a>
            </p>
        <?php endif; ?>
    </header>

    <?php if ($idEntity === 'musician'): ?>

        <!-- IPI / ISNI — matching musicians/composers -->
        <?php if (empty($idMusicians)): ?>
            <div class="alert alert-warning" role="alert">
                <i class="fa-solid fa-circle-exclamation me-2" aria-hidden="true"></i>
                No musicians in the catalogue carry the <?= htmlspecialchars($idLabel) ?>
                <strong><?= htmlspecialchars($idCanonical) ?></strong>.
            </div>
        <?php else: ?>
            <div class="list-group list-group-flush" role="list">
                <?php foreach ($idMusicians as $m): ?>
                    <a class="list-group-item list-group-item-action"
                       href="/musician/<?= htmlspecialchars((string)$m['slug']) ?>"
                       data-navigate="musician"
                       role="listitem">
                        <?= htmlspecialchars((string)$m['name']) ?>
                        <i class="fa-solid fa-chevron-right text-muted float-end" aria-hidden="true"></i>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php elseif ($idScheme === 'bowi'): ?>

        <!-- BOWI — Work header above is the whole result; no song list. -->
        <?php if ($idWork === null): ?>
            <div class="alert alert-warning" role="alert">
                <i class="fa-solid fa-circle-exclamation me-2" aria-hidden="true"></i>
                No work in the catalogue carries the BOWI <strong><?= htmlspecialchars($idCanonical) ?></strong>.
            </div>
            <p class="text-muted small">
                BOWI values are populated by curators and bulk imports. Even if a work uses this code,
                it may not yet be tagged.
            </p>
        <?php endif; ?>

    <?php else: ?>

        <!-- iswc / ccli / isrc — song list, grouped by songbook -->
        <?php if (empty($idSongs)): ?>
            <div class="alert alert-warning" role="alert">
                <i class="fa-solid fa-circle-exclamation me-2" aria-hidden="true"></i>
                No songs in the catalogue carry the <?= htmlspecialchars($idLabel) ?>
                <strong><?= htmlspecialchars($idCanonical) ?></strong>.
            </div>
            <p class="text-muted small">
                <?= htmlspecialchars($idLabel) ?> values are populated by curators and bulk imports. Even if a
                song uses this <?= htmlspecialchars($idLabel) ?>, it may not yet be tagged.
            </p>
            <a href="/" class="btn btn-primary" data-navigate="home">
                <i class="fa-solid fa-arrow-left me-2" aria-hidden="true"></i>Back to Home
            </a>
        <?php else: ?>
            <?php
            $idSongsByBook = [];
            foreach ($idSongs as $r) {
                $abbr = (string)$r['SongbookAbbr'];
                if (!isset($idSongsByBook[$abbr])) {
                    $idSongsByBook[$abbr] = [
                        'name' => (string)$r['SongbookName'],
                        'rows' => [],
                    ];
                }
                $idSongsByBook[$abbr]['rows'][] = $r;
            }
            ?>
            <!-- Sort control (#1786) — Number / Title / Songbook. One
                 control governs every per-songbook group below
                 (multi-container). -->
            <?php
                $listSortSurface = 'identifier-songs';
                $listSortDefault = 'Songbook & number';
                $listSortOptions = [
                    'number' => ['label' => 'Number',   'type' => 'number', 'dir' => 'asc'],
                    'title'  => ['label' => 'Title',    'type' => 'text',   'dir' => 'asc'],
                    'book'   => ['label' => 'Songbook', 'type' => 'text',   'dir' => 'asc'],
                ];
                require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'list-sort-control.php';
            ?>
            <?php foreach ($idSongsByBook as $abbr => $book): ?>
                <section class="mb-4">
                    <h2 class="h6 text-muted">
                        <span class="badge bg-body-secondary text-body-emphasis me-2"><?= htmlspecialchars($abbr) ?></span>
                        <?= htmlspecialchars($book['name']) ?>
                    </h2>
                    <div class="list-group list-group-flush song-list" role="list" data-list-sort-list="identifier-songs">
                        <?php foreach ($book['rows'] as $r): ?>
                            <a class="list-group-item list-group-item-action song-list-item"
                               href="/song/<?= htmlspecialchars((string)$r['SongId']) ?>"
                               data-navigate="song"
                               data-song-id="<?= htmlspecialchars((string)$r['SongId']) ?>"
                               role="listitem"
                               <?php if ((int)$r['Number'] > 0): ?>data-sort-number="<?= (int)$r['Number'] ?>"<?php endif; ?>
                               data-sort-title="<?= htmlspecialchars(ihymns_title_sort_key((string)$r['Title'])) ?>"
                               data-sort-book="<?= htmlspecialchars(mb_strtolower((string)$abbr, 'UTF-8')) ?>">
                                <span class="song-number-badge"><?= (int)$r['Number'] ?: '?' ?></span>
                                <div class="song-info flex-grow-1">
                                    <span class="song-title"><?= htmlspecialchars((string)$r['Title']) ?></span>
                                    <?php if (!empty($r['Language'])): ?>
                                        <small class="text-muted ms-2">
                                            <i class="fa-solid fa-language me-1" aria-hidden="true"></i><?= htmlspecialchars((string)$r['Language']) ?>
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

    <?php endif; ?>
<?php endif; ?>
