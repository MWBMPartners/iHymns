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
 *   $exportMenuSurface = 'song';       // or 'songbook'
 *   require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'export-menu.php';
 *
 * $exportMenuSurface selects which CSS class the SPA-router-driven wiring
 * hooks on: `.song-export-menu` (export-ui.js `initSongExport()`) or
 * `.songbook-export-menu` (`initSongbookExport()`) — see router.js
 * afterPageLoad() (#1565) for where those two functions are actually called
 * from, now that the fragment can no longer self-wire via an inline
 * <script> (enforcing nonce CSP #117 + shared-cache rule #6).
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

if (!isset($exportMenuSurface) || !in_array($exportMenuSurface, ['song', 'songbook'], true)) {
    /* Fail closed rather than guess — an unset/unknown surface would wire
       neither export-ui.js hook (initSongExport / initSongbookExport look
       for one specific class each), so rendering an unmarked menu would
       silently ship a dead dropdown. Callers must set the contract var. */
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

$exportMenuClass = $exportMenuSurface === 'songbook' ? 'songbook-export-menu' : 'song-export-menu';
?>
<ul class="dropdown-menu dropdown-menu-end <?= $exportMenuClass ?>">
    <?php foreach ($EXPORT_MENU_FORMATS as $fmtKey => $fmtLabel): ?>
    <li><button type="button" class="dropdown-item" data-export-format="<?= htmlspecialchars($fmtKey) ?>"><?= htmlspecialchars($fmtLabel) ?></button></li>
    <?php endforeach; ?>
</ul>
