<?php

declare(strict_types=1);

/**
 * iHymns — IETF BCP 47 language picker partial (#681)
 *
 * Renders three composable inputs (Language, Script, Region) plus
 * a live "IETF tag:" preview and a hidden <input> that holds the
 * composed tag. Used by:
 *   - /manage/songbooks (create form + edit modal)
 *   - /manage/editor    (per-song Metadata tab)
 *
 * Caller contract:
 *
 *   <?php
 *     $idPrefix = 'edit';                  // unique per instance on a page
 *     $name     = 'language';              // POST field name for the composed tag
 *     $tag      = 'pt-BR';                 // saved BCP 47 tag (or empty)
 *     $label    = 'Language (IETF BCP 47)'; // optional override
 *     $help     = '';                      // optional sub-label hint
 *     require __DIR__ . '/includes/partials/ietf-language-picker.php';
 *   ?>
 *
 * The picker is JS-driven (js/modules/ietf-language-picker.js).
 * The PHP side just emits the markup + the saved tag; the JS module
 * decomposes it on boot, queries the typeaheads, and writes the
 * composed tag back into the hidden field on every input change.
 */

/* Defensive defaults — if the caller forgot to set any of these,
   render a blank picker rather than crashing. */
$idPrefix = isset($idPrefix) ? (string)$idPrefix : 'ietf';
$name     = isset($name)     ? (string)$name     : 'language';
$tag      = isset($tag)      ? (string)$tag      : '';
$label    = isset($label)    ? (string)$label    : 'Language (IETF BCP 47)';
$help     = isset($help)     ? (string)$help     : 'Optional. Pick a language; add a script (Latin / Cyrillic / …) or region (United Kingdom / Brazil / …) only if it differs from the default.';

/* Sanitise the id so a future caller passing "edit modal" doesn't
   produce broken HTML. Strip everything but [a-z0-9-]. */
$idSafe = preg_replace('/[^a-z0-9-]/i', '-', $idPrefix);
?>
<div class="ietf-picker mb-3"
     data-ietf-picker-id="<?= htmlspecialchars($idSafe, ENT_QUOTES, 'UTF-8') ?>"
     data-initial-tag="<?= htmlspecialchars($tag, ENT_QUOTES, 'UTF-8') ?>">

    <label class="form-label">
        <i class="bi bi-translate me-1" aria-hidden="true"></i><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
    </label>

    <div class="row g-2">
        <div class="col-md-5">
            <label class="form-label small text-muted"
                   for="<?= htmlspecialchars($idSafe, ENT_QUOTES, 'UTF-8') ?>-lang">
                Language
            </label>
            <input type="text"
                   class="form-control form-control-sm ietf-picker-language"
                   id="<?= htmlspecialchars($idSafe, ENT_QUOTES, 'UTF-8') ?>-lang"
                   list="ietf-lang-list-<?= htmlspecialchars($idSafe, ENT_QUOTES, 'UTF-8') ?>"
                   autocomplete="off"
                   placeholder="English">
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted"
                   for="<?= htmlspecialchars($idSafe, ENT_QUOTES, 'UTF-8') ?>-script">
                Script
            </label>
            <input type="text"
                   class="form-control form-control-sm ietf-picker-script"
                   id="<?= htmlspecialchars($idSafe, ENT_QUOTES, 'UTF-8') ?>-script"
                   list="ietf-script-list-<?= htmlspecialchars($idSafe, ENT_QUOTES, 'UTF-8') ?>"
                   autocomplete="off"
                   placeholder="e.g. Latin">
        </div>
        <div class="col-md-4">
            <label class="form-label small text-muted"
                   for="<?= htmlspecialchars($idSafe, ENT_QUOTES, 'UTF-8') ?>-region">
                Region
            </label>
            <input type="text"
                   class="form-control form-control-sm ietf-picker-region"
                   id="<?= htmlspecialchars($idSafe, ENT_QUOTES, 'UTF-8') ?>-region"
                   list="ietf-region-list-<?= htmlspecialchars($idSafe, ENT_QUOTES, 'UTF-8') ?>"
                   autocomplete="off"
                   placeholder="e.g. United Kingdom">
        </div>
    </div>

    <div class="form-text small mt-1">
        IETF tag: <code class="ietf-tag-preview"><?= htmlspecialchars($tag !== '' ? $tag : '—', ENT_QUOTES, 'UTF-8') ?></code>
        <span class="text-muted ms-2"><?= htmlspecialchars($help, ENT_QUOTES, 'UTF-8') ?></span>
    </div>

    <input type="hidden"
           class="ietf-tag-output"
           name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
           value="<?= htmlspecialchars($tag, ENT_QUOTES, 'UTF-8') ?>">

    <!-- One <datalist> per input, scoped by the picker's idPrefix so
         multiple instances on the same page (create form + edit
         modal) don't share state. -->
    <datalist id="ietf-lang-list-<?= htmlspecialchars($idSafe, ENT_QUOTES, 'UTF-8') ?>"></datalist>
    <datalist id="ietf-script-list-<?= htmlspecialchars($idSafe, ENT_QUOTES, 'UTF-8') ?>"></datalist>
    <datalist id="ietf-region-list-<?= htmlspecialchars($idSafe, ENT_QUOTES, 'UTF-8') ?>"></datalist>
</div>
