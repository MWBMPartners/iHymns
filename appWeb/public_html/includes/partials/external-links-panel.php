<?php

declare(strict_types=1);

/**
 * iHymns — Shared categorised external-links panel partial (#1741 P4b, §0.4)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * A "find this elsewhere" box — a row of buttons grouped under headings like
 * "Official" / "Listen" / "Watch" — that links out to Spotify, YouTube,
 * Wikipedia, sheet-music sites, and so on. Before this file, EVERY public
 * page that showed one (the musician page, the work page) had its own
 * copy-pasted category-label list and its own copy-pasted markup loop. This
 * file is the ONE copy: hand it a flat list of links plus a heading, and it
 * groups + renders them.
 *
 * DETAILED / WHY THIS EXTRACTION, AND WHY NOW
 * ----------------------------------------------------------------------------
 * `includes/pages/musician.php` (:564-616, its own `$pCatLabels` map at
 * :572-583) and `includes/pages/work.php` (:190-221, its own `$wCatLabels`
 * map at :70-81) rendered byte-similar-but-independently-typed versions of
 * this panel. #1741 P4c was about to add a THIRD copy for `includes/pages/
 * tune.php` — three divergent copies of the same ten-category label map is
 * exactly the regression the modularity rule (CLAUDE.md "🧱 Modularity
 * rule") exists to prevent, so P4b extracts the shared partial FIRST, before
 * the third copy can be written, rather than after.
 *
 * `work.php` (P4b, this commit) and `tune.php` (P4c) consume this partial;
 * `musician.php` converts to it in P4a — deliberately NOT touched here, so
 * this commit stays a pure extraction-plus-one-caller with no risk to the
 * musician page's existing behaviour. The category label list/order below
 * is copied verbatim from `work.php`'s pre-extraction `$wCatLabels` (not
 * `musician.php`'s slightly different-ordered `$pCatLabels`), so converting
 * `work.php` to call this partial is a byte-similar, no-behaviour-change
 * edit; `musician.php`'s ordering will shift very slightly to match this
 * one when P4a converts it — an accepted, cosmetic, one-time change noted
 * in that future commit, not this one.
 *
 * INPUT SHAPE
 * -----------
 * `$panelLinks` — a flat list of associative arrays, each with the UNIFIED
 * external-link shape both `SongData::getWork()` (:5052-5061) and
 * `musician.php`'s own query (:259-280) already build:
 *
 *   ['slug' => string, 'name' => string, 'category' => string,
 *    'url' => string, 'note' => string, 'verified' => bool,
 *    'iconClass' => string]
 *
 * Every key besides `category`/`url`/`name` is optional (rendered only
 * `!empty()`); an unrecognised `category` value falls under "Other"
 * automatically (any category not in the internal label map is dropped
 * silently by the `continue` in the render loop below — matching the two
 * pre-extraction copies' behaviour exactly: a link whose LinkTypeId points
 * at a category string outside this ten-value list simply never rendered,
 * because both original loops iterated the label map, not the link list).
 *
 * Caller contract:
 *
 *   <?php
 *     $panelLinks     = $work['links'] ?? [];
 *     $panelHeading   = 'Find this work elsewhere';
 *     $panelAriaLabel = 'Find this work elsewhere';
 *     require __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR
 *           . 'partials' . DIRECTORY_SEPARATOR . 'external-links-panel.php';
 *   ?>
 *
 * Renders NOTHING (no wrapping markup at all) when `$panelLinks` is empty —
 * callers do not need to wrap the `require` in their own `!empty()` check,
 * matching the pre-extraction callers' inline `if (!empty($workLinks))`
 * guard but moving the check inside the shared partial so a THIRD
 * caller can't forget it.
 *
 * NO EXECUTABLE INLINE `<script>` (rule #30) — pure markup, safe inside a
 * `$_cacheablePages` fragment (work/musician/tune are all cacheable per
 * plan §0.1) with no per-request nonce available.
 *
 * @link .claude/catalogue-1741-P4-plan.md §0.4               the extraction spec this file implements
 * @link appWeb/public_html/includes/pages/work.php            first consumer (P4b)
 * @link appWeb/public_html/includes/SongData.php::getWork()   builds the unified link shape for works
 * @link appWeb/public_html/includes/pages/musician.php        pre-extraction inline copy; converts in P4a
 * @see #1741
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* ELI5: read the three caller variables, defensively — a caller that
   forgets `$panelLinks` gets an empty (silently-no-op) panel rather than
   a PHP notice, matching every other partial in this codebase
   (external-links-section.php's isset()-guarded reads). */
$panelLinks     = (isset($panelLinks) && is_array($panelLinks)) ? $panelLinks : [];
$panelHeading   = isset($panelHeading) ? (string)$panelHeading : 'Find this elsewhere';
$panelAriaLabel = isset($panelAriaLabel) ? (string)$panelAriaLabel : $panelHeading;

if (!empty($panelLinks)):
    /* Group by category — same shape both pre-extraction copies built
       independently (work.php:64-69 / musician.php:566-571). */
    $panelLinksByCat = [];
    foreach ($panelLinks as $l) {
        $cat = (string)($l['category'] ?? 'other');
        if (!isset($panelLinksByCat[$cat])) $panelLinksByCat[$cat] = [];
        $panelLinksByCat[$cat][] = $l;
    }

    /* THE one category-label map (was $wCatLabels in work.php, $pCatLabels
       in musician.php) — order + wording copied verbatim from work.php's
       pre-extraction copy, see the file doc-block for why. */
    $panelCatLabels = [
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
?>
    <section class="mt-4 pt-3 border-top" aria-label="<?= htmlspecialchars($panelAriaLabel) ?>">
        <h2 class="h6 mb-3 d-flex align-items-center gap-2">
            <i class="fa-solid fa-link me-1 text-muted" aria-hidden="true"></i>
            <?= htmlspecialchars($panelHeading) ?>
        </h2>
        <?php foreach ($panelCatLabels as $cat => $catLabel): ?>
            <?php if (empty($panelLinksByCat[$cat])) continue; ?>
            <div class="mb-2">
                <div class="text-uppercase small text-muted mb-1"><?= htmlspecialchars($catLabel) ?></div>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($panelLinksByCat[$cat] as $l): ?>
                        <a href="<?= htmlspecialchars((string)$l['url']) ?>"
                           target="_blank" rel="noopener nofollow"
                           class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-2">
                            <?php if (!empty($l['iconClass'])): ?>
                                <i class="<?= htmlspecialchars((string)$l['iconClass']) ?>" aria-hidden="true"></i>
                            <?php endif; ?>
                            <span><?= htmlspecialchars((string)$l['name']) ?></span>
                            <?php if (!empty($l['note'])): ?>
                                <span class="text-muted small">— <?= htmlspecialchars((string)$l['note']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($l['verified'])): ?>
                                <i class="fa-solid fa-circle-check text-success small" role="img" aria-label="Verified" title="Verified"></i>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
