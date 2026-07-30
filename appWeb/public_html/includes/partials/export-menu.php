<?php

declare(strict_types=1);

/**
 * iHymns — Shared public Export ▾ dropdown menu (#1570)
 *
 * PURPOSE:
 * THE single source for the public-facing "Export" dropdown items on both
 * the song page and the songbook page. Before this partial, song.php and
 * songbook.php each hand-wrote their own near-identical `<ul>` of format
 * buttons, which had already drifted once (the songbook menu was missing
 * ChordPro — see below) — exactly the copy-paste-divergence the modularity
 * rule (.claude/CLAUDE.md) exists to prevent.
 *
 * Caller contract:
 *
 *   $exportMenuSurface = 'song';       // or 'songbook' / 'songbook-list'
 *   require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'export-menu.php';
 *
 * $exportMenuSurface selects which CSS class the SPA-router-driven wiring
 * hooks on: `.song-export-menu` (export-ui.js `initSongExport()`, ONE menu
 * on /song/<id>), `.songbook-export-menu` (`initSongbookExport()`, ONE menu
 * on /songbook/<abbr>), or `.songbook-list-export-menu` (#1607,
 * `initSongbookListExport()` — MANY menus, one per tile, on /songbooks) —
 * see router.js afterPageLoad() for where these are actually called from,
 * now that the fragment can no longer self-wire via an inline <script>
 * (enforcing nonce CSP #117 + shared-cache rule #6).
 *
 * 'songbook-list' gets its own class rather than reusing
 * `.songbook-export-menu` because the list page renders N of them — the
 * single-menu selector export-ui.js uses for the song/songbook surfaces
 * (`document.querySelector`, first match only) would silently wire every
 * tile's menu to the FIRST tile's abbreviation. `initSongbookListExport()`
 * instead walks every `.songbook-list-export-menu` and resolves each one's
 * abbreviation from its own tile's `data-songbook-id` (#1607).
 *
 * The `data-export-format` KEYS below must match TWO registries kept in
 * sync by hand (there is no shared JS/PHP enum to import from):
 *   - window.iHymnsFormatExport in manage/editor/format-export.js (all
 *     keys except proPresenter7)
 *   - the proPresenter7 special-case branch in js/modules/export-ui.js
 *     (a separate binary-protobuf exporter, window.iHymnsProPresenter)
 * The v1 editor's own export menu (index.php) and the v2 editor's
 * export.js `ITEMS` registry are a SEPARATE surface (curator-facing, richer
 * options) — intentionally out of scope for this partial.
 *
 * All 8 formats are offered on BOTH surfaces: every format's exporter
 * object exposes both an `exportSong()` and an `exportSongbook()` method
 * (or, for ProPresenter 7+, the equivalent `exportSong()` /
 * `exportAllAsBundle()` pair) — see export-ui.js `initSongExport()` /
 * `initSongbookExport()`. ChordPro was previously song-only; the songbook
 * menu simply hadn't been kept in sync (its exporter always supported
 * exportSongbook(), so this was never a functional gap — just a rendering
 * one). Sharing ONE list here is what keeps that from happening again.
 */

$EXPORT_MENU_CLASS_BY_SURFACE = [
    'song'          => 'song-export-menu',
    'songbook'      => 'songbook-export-menu',
    'songbook-list' => 'songbook-list-export-menu',
];

if (!isset($exportMenuSurface) || !isset($EXPORT_MENU_CLASS_BY_SURFACE[$exportMenuSurface])) {
    /* Fail closed rather than guess — an unset/unknown surface would wire
       no export-ui.js hook (each one looks for one specific class), so
       rendering an unmarked menu would silently ship a dead dropdown.
       Callers must set the contract var to a recognised surface. */
    return;
}

/* Keys => visible labels, in menu display order. */
$EXPORT_MENU_FORMATS = [
    'openSong'      => 'OpenSong',
    'openLyrics'    => 'OpenLyrics / OpenLP',
    'proPresenter6' => 'ProPresenter 6',
    'proPresenter7' => 'ProPresenter 7+',
    'videoPsalm'    => 'VideoPsalm',
    'freeShow'      => 'FreeShow',
    'proclaim'      => 'Proclaim',
    'chordPro'      => 'ChordPro',
];

$exportMenuClass = $EXPORT_MENU_CLASS_BY_SURFACE[$exportMenuSurface];
?>
<ul class="dropdown-menu dropdown-menu-end <?= $exportMenuClass ?>">
    <?php foreach ($EXPORT_MENU_FORMATS as $fmtKey => $fmtLabel): ?>
    <li><button type="button" class="dropdown-item" data-export-format="<?= htmlspecialchars($fmtKey) ?>"><?= htmlspecialchars($fmtLabel) ?></button></li>
    <?php endforeach; ?>
</ul>
