<?php

declare(strict_types=1);

/**
 * iHymns — IETF BCP 47 language picker partial (#681, live-search rework:
 * BCP 47 registry plan §4, M2)
 *
 * Renders four composable inputs (Language, Script, Region, Variant) plus
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
 *     $outputId = 'edit-language';         // optional id="" on the hidden output
 *     require __DIR__ . '/includes/partials/ietf-language-picker.php';
 *   ?>
 *
 * The picker is JS-driven (js/modules/ietf-language-picker.js). The PHP
 * side just emits the markup + the saved tag; the JS module decomposes it
 * on boot, wires each subtag input's live-search typeahead
 * (`window.iHymnsPlaceSearch.attach()`, rule #43), and writes the composed
 * tag back into the hidden field on every input change. NO `<datalist>`
 * elements any more (removed with #1907's live-search rework) — each
 * subtag input's own `-code` hidden sibling carries the picked canonical
 * code instead; see the module's own doc-block for the full markup
 * contract this partial emits.
 *
 * $outputId (optional): legacy callers — the song editor uses a
 * non-form save flow that reads the composed tag via getElementById,
 * so the hidden output needs a stable id. Form-POST callers leave
 * this blank and lookup-by-name suffices.
 */

/* Defensive defaults — if the caller forgot to set any of these,
   render a blank picker rather than crashing. */
$idPrefix = isset($idPrefix) ? (string)$idPrefix : 'ietf';
$name     = isset($name)     ? (string)$name     : 'language';
$tag      = isset($tag)      ? (string)$tag      : '';
$label    = isset($label)    ? (string)$label    : 'Language (IETF BCP 47)';
$help     = isset($help)     ? (string)$help     : 'Optional. Pick a language; add a script (Latin / Cyrillic / …) or region (United Kingdom / Brazil / …) only if it differs from the default.';
$outputId = isset($outputId) ? (string)$outputId : '';

/* Sanitise the output id with the same rule as the prefix so a
   typo can't produce broken HTML. Empty stays empty. */
$outputIdSafe = $outputId !== '' ? preg_replace('/[^A-Za-z0-9_-]/', '-', $outputId) : '';

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
        <div class="col-md-4">
            <label class="form-label small text-muted"
                   for="<?= htmlspecialchars($idSafe, ENT_QUOTES, 'UTF-8') ?>-lang">
                Language
            </label>
            <input type="text"
                   class="form-control form-control-sm ietf-picker-language"
                   id="<?= htmlspecialchars($idSafe, ENT_QUOTES, 'UTF-8') ?>-lang"
                   autocomplete="off"
                   placeholder="English">
            <input type="hidden" class="ietf-picker-language-code">
        </div>
        <div class="col-md-2">
            <label class="form-label small text-muted"
                   for="<?= htmlspecialchars($idSafe, ENT_QUOTES, 'UTF-8') ?>-script">
                Script
            </label>
            <input type="text"
                   class="form-control form-control-sm ietf-picker-script"
                   id="<?= htmlspecialchars($idSafe, ENT_QUOTES, 'UTF-8') ?>-script"
                   autocomplete="off"
                   placeholder="e.g. Latin">
            <input type="hidden" class="ietf-picker-script-code">
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted"
                   for="<?= htmlspecialchars($idSafe, ENT_QUOTES, 'UTF-8') ?>-region">
                Region
            </label>
            <input type="text"
                   class="form-control form-control-sm ietf-picker-region"
                   id="<?= htmlspecialchars($idSafe, ENT_QUOTES, 'UTF-8') ?>-region"
                   autocomplete="off"
                   placeholder="e.g. United Kingdom">
            <input type="hidden" class="ietf-picker-region-code">
        </div>
        <!-- Variant subtag — IANA tblLanguageVariants (e.g. 1996,
             fonipa, valencia). Optional; rare in worship metadata
             but the registry has ~140 valid entries and the picker
             grew its IETF surface to support them. -->
        <div class="col-md-3">
            <label class="form-label small text-muted"
                   for="<?= htmlspecialchars($idSafe, ENT_QUOTES, 'UTF-8') ?>-variant">
                Variant
            </label>
            <input type="text"
                   class="form-control form-control-sm ietf-picker-variant"
                   id="<?= htmlspecialchars($idSafe, ENT_QUOTES, 'UTF-8') ?>-variant"
                   autocomplete="off"
                   placeholder="e.g. 1996, fonipa">
            <input type="hidden" class="ietf-picker-variant-code">
        </div>
    </div>

    <div class="form-text small mt-1">
        IETF tag: <code class="ietf-tag-preview"><?= htmlspecialchars($tag !== '' ? $tag : '—', ENT_QUOTES, 'UTF-8') ?></code>
        <span class="ietf-tag-display ms-2 fst-italic"></span>
        <span class="text-muted ms-2"><?= htmlspecialchars($help, ENT_QUOTES, 'UTF-8') ?></span>
    </div>

    <!-- M3 (BCP 47 registry plan §4.4) — inline "not a recognised subtag"
         warning. Hidden by default; the JS module toggles it live as the
         curator types. Free text always stays SAVEABLE (rule #21) — this
         is feedback, never a blocking validator. -->
    <div class="ietf-picker-unknown-warning form-text text-warning-emphasis d-none" role="status"></div>

    <input type="hidden"
           class="ietf-tag-output"
           <?php if ($outputIdSafe !== ''): ?>id="<?= htmlspecialchars($outputIdSafe, ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?>
           name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
           value="<?= htmlspecialchars($tag, ENT_QUOTES, 'UTF-8') ?>">
</div>
