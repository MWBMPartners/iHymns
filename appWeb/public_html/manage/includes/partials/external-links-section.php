<?php

declare(strict_types=1);

/**
 * iHymns — External Links section partial
 *
 * Renders the heading + Add button + rows container that every admin
 * edit modal uses to attach external links (tblExternalLinkTypes-backed)
 * to its parent row. The caller is responsible for:
 *
 *   1. Calling `loadExternalLinkTypesFor($db, '<applies-to>')` and
 *      emitting it as `window._iHymnsLinkTypes` before any consumer
 *      script runs. The convention is to do that on the same page as
 *      this partial so each admin surface ships its own slice of the
 *      registry.
 *   2. Wiring an `iHymnsExtLinksEditor.mount({ rowsEl, addBtnEl })`
 *      call in the page's JS so the Add button + row builder come
 *      alive. The container + button IDs are passed in via caller
 *      variables ($containerId / $addBtnId) so a single page can
 *      mount more than one editor without collisions.
 *
 * Caller contract:
 *
 *   <?php
 *     $containerId = 'edit-ext-links-rows';
 *     $addBtnId    = 'edit-ext-link-add-btn';
 *     $heading     = 'External links';        // optional
 *     $helpText    = '…';                     // optional
 *     $useCardHeading = false;                // optional — when true,
 *                                             //   wraps the heading in
 *                                             //   <h6> instead of <label>
 *     require __DIR__ . '/partials/external-links-section.php';
 *   ?>
 *
 * Schema-gated callers should only render this partial when
 * `tblExternalLinkTypes` exists — the partial itself doesn't probe.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

$containerId    = isset($containerId)    ? (string)$containerId    : 'ext-links-rows';
$addBtnId       = isset($addBtnId)       ? (string)$addBtnId       : 'ext-link-add-btn';
$heading        = isset($heading)        ? (string)$heading        : 'External links';
$helpText       = isset($helpText)       ? (string)$helpText       : 'Provider auto-detects from the URL — paste a URL and the dropdown selects the matching provider automatically.';
$useCardHeading = isset($useCardHeading) ? (bool)$useCardHeading   : false;

/* Sanitise the IDs for HTML attribute use. The caller controls them,
   but we still belt-and-braces in case they ever come from any
   external source. */
$safeContainerId = preg_replace('/[^A-Za-z0-9_-]/', '-', $containerId) ?? 'ext-links-rows';
$safeAddBtnId    = preg_replace('/[^A-Za-z0-9_-]/', '-', $addBtnId)    ?? 'ext-link-add-btn';
?>
<div class="ihymns-ext-links-section">
    <div class="d-flex justify-content-between align-items-baseline mb-2">
        <?php if ($useCardHeading): ?>
            <h6 class="mb-0"><i class="bi bi-link-45deg me-2" aria-hidden="true"></i><?= htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') ?></h6>
        <?php else: ?>
            <label class="form-label mb-0"><i class="bi bi-link-45deg me-2" aria-hidden="true"></i><?= htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') ?></label>
        <?php endif; ?>
        <button type="button"
                class="btn btn-sm btn-outline-info"
                id="<?= htmlspecialchars($safeAddBtnId, ENT_QUOTES, 'UTF-8') ?>">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add link
        </button>
    </div>
    <?php if ($helpText !== ''): ?>
        <p class="form-text small mt-0 mb-2"><?= htmlspecialchars($helpText, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <div id="<?= htmlspecialchars($safeContainerId, ENT_QUOTES, 'UTF-8') ?>" class="vstack gap-2"></div>
</div>
<?php
/* Reset the caller-set vars so a second include on the same page picks
   up its own values rather than the previous block's. */
unset($containerId, $addBtnId, $heading, $helpText, $useCardHeading);
