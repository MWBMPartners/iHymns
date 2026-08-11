<?php

declare(strict_types=1);

/**
 * iHymns — Shared public "Sort ▾" control (#1786 Option B)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * One little "Sort ▾" button, reused on every public songs list — songbooks,
 * a songbook's song list, a theme page, a musician's discography, favourites,
 * search results, and more. Tapping it opens a small panel where you can pick
 * up to three things to sort by, in order ("first by Title, then by Number").
 * The button and panel look the SAME everywhere; this one file draws them so
 * every page's sort control behaves identically instead of five slightly
 * different copies drifting apart (the modularity rule, .claude/CLAUDE.md).
 *
 * DETAILED
 * --------
 * Mirrors `includes/partials/export-menu.php`'s caller contract exactly (a
 * few PHP variables set, then `require` this file):
 *
 *   $listSortSurface  = 'songbook-songs';        // [a-z0-9-]{1,40}, unique per surface
 *   $listSortDefault  = 'Number';                 // label of the page's SERVER default order
 *   $listSortOptions  = [
 *       'number'  => ['label' => 'Number', 'type' => 'number', 'dir' => 'asc'],
 *       'title'   => ['label' => 'Title',  'type' => 'text',   'dir' => 'asc'],
 *       'writers' => ['label' => 'Writer', 'type' => 'text',   'dir' => 'asc'],
 *   ];
 *   require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'list-sort-control.php';
 *
 * WHY THIS IS SAFE ON A SHARED-CACHE FRAGMENT (rule #6 / rule #30)
 * ------------------------------------------------------------------
 * Every one of `includes/pages/*.php`'s callers can be served through
 * `$_cacheablePages` (api.php) — a response cached ONCE and handed to every
 * visitor. This partial therefore emits markup that is byte-identical for
 * every viewer: the control's SHAPE (which keys exist, their labels/types,
 * the default label) is the same for everybody, and carries NO inline
 * `<script>` (the enforcing nonce CSP would silently refuse one anyway,
 * #1565). The viewer's PERSONAL saved sort is applied entirely client-side,
 * after this markup lands, by `js/modules/list-sort.js` — imported from
 * `router.js`'s `afterPageLoad()`, never injected by this file. This is the
 * exact card-layout split (`includes/card_layout.php`'s two-hydration-modes
 * doc): server emits the canonical default, the client personalises.
 *
 * THE LEVEL ROWS ARE BUILT BY JS, NOT HERE
 * ------------------------------------------
 * `.list-sort-levels` is emitted EMPTY. A dynamic "add a level / remove a
 * level / change its key or direction" UI cannot be static markup — the
 * card-layout edit-controls precedent (`card-layout.js`'s `enterEditMode()`)
 * builds its per-card buttons the same way, via DOM APIs, for the same
 * reason. `list-sort.js` reads `data-list-sort-options` (below) to know what
 * `<option>`s each level's `<select>` may offer.
 *
 * VALIDATION / FAIL-CLOSED
 * -------------------------
 * An unset/malformed `$listSortSurface` or empty `$listSortOptions` renders
 * NOTHING (mirrors export-menu.php's `return` on an unrecognised surface) —
 * fail closed rather than guess, since a stray control with no matching JS
 * wiring is worse than no control. Surface + option-key charset
 * (`[a-z0-9-]{1,40}` / `[a-z0-9_-]{1,40}`) mirrors `cardLayoutSanitiseIds()`'s
 * allow-list posture (`includes/card_layout.php`) — both become
 * `data-sort-<key>` attribute lookups downstream, so a rogue key must never
 * reach page markup un-validated.
 *
 * @see appWeb/public_html/js/modules/list-sort.js        the client module that wires this markup
 * @see appWeb/public_html/includes/partials/export-menu.php  the sibling this mirrors the contract of
 * @see appWeb/public_html/includes/card_layout.php        the shared-cache-fragment split this follows (rule #6)
 * @see .claude/public-list-sort-1786-plan.md  §3            full control design
 * @link https://github.com/MWBMPartners/iHymns/issues/1786
 */

if (!isset($listSortSurface) || !is_string($listSortSurface) || !preg_match('/^[a-z0-9-]{1,40}$/', $listSortSurface)) {
    return; // fail closed — an unrecognised/missing surface renders no control
}
if (!isset($listSortOptions) || !is_array($listSortOptions) || $listSortOptions === []) {
    return; // no keys to offer — nothing sortable, nothing to render
}

$listSortDefaultLabel = isset($listSortDefault) && is_string($listSortDefault) && $listSortDefault !== ''
    ? $listSortDefault
    : 'Default';

/* Sanitise the options map down to the exact shape the client reads —
   key charset validated (see doc-block), label/type/dir coerced to safe
   defaults rather than trusting the caller array verbatim. A malformed
   entry is dropped rather than rendered half-broken. */
$listSortOptsOut = [];
foreach ($listSortOptions as $optKey => $optDef) {
    if (!is_string($optKey) || !preg_match('/^[a-z0-9_-]{1,40}$/', $optKey)) {
        continue;
    }
    $optLabel = isset($optDef['label']) && is_string($optDef['label']) && $optDef['label'] !== ''
        ? $optDef['label']
        : $optKey;
    $optType = isset($optDef['type']) && in_array($optDef['type'], ['text', 'number', 'date'], true)
        ? $optDef['type']
        : 'text';
    $optDir = isset($optDef['dir']) && $optDef['dir'] === 'desc' ? 'desc' : 'asc';
    $listSortOptsOut[$optKey] = ['label' => $optLabel, 'type' => $optType, 'dir' => $optDir];
}
if ($listSortOptsOut === []) {
    return; // every supplied option failed validation — nothing left to render
}

$listSortLabelId = 'list-sort-label-' . $listSortSurface;
?>
<div class="list-sort-control dropdown"
     data-list-sort-surface="<?= htmlspecialchars($listSortSurface) ?>"
     data-list-sort-default-label="<?= htmlspecialchars($listSortDefaultLabel) ?>"
     data-list-sort-options="<?= htmlspecialchars(json_encode($listSortOptsOut, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}') ?>">
    <button type="button"
            class="btn btn-sm btn-outline-secondary dropdown-toggle list-sort-toggle"
            data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
        <i class="fa-solid fa-arrow-down-wide-short me-1" aria-hidden="true"></i>
        <span class="list-sort-summary">Sort: <?= htmlspecialchars($listSortDefaultLabel) ?></span>
    </button>
    <div class="dropdown-menu p-3 list-sort-panel">
        <div class="fw-semibold small mb-2" id="<?= htmlspecialchars($listSortLabelId) ?>">Sort by</div>
        <!-- Level rows are DOM-built by list-sort.js — see doc-block. -->
        <div class="list-sort-levels" role="group" aria-labelledby="<?= htmlspecialchars($listSortLabelId) ?>"></div>
        <div class="d-flex gap-2 mt-2">
            <button type="button" class="btn btn-sm btn-outline-secondary list-sort-add">+ then by&hellip;</button>
            <button type="button" class="btn btn-sm btn-outline-secondary list-sort-reset d-none">Reset to default</button>
        </div>
    </div>
</div>
