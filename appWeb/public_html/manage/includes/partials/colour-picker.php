<?php

declare(strict_types=1);

/**
 * iHymns — Colour Picker partial (#715)
 *
 * A composite control: a native <input type="color"> swatch alongside
 * the existing <input type="text"> for the hex value. Both are bound
 * via js/modules/colour-picker.js — typing a hex updates the swatch,
 * picking from the swatch fills the text input. Empty text → swatch
 * shows a neutral default but the saved value remains "" so the
 * server-side auto-pick (#677) can choose a palette tone at render.
 *
 * Caller contract:
 *
 *   <?php
 *     $name        = 'colour';      // POST field name on the text input
 *     $value       = $colour;       // current hex (or '' if not set)
 *     $idPrefix    = 'edit';        // unique per instance on a page
 *     $label       = 'Colour (hex)';// optional override
 *     $placeholder = '#1a73e8';     // optional placeholder
 *     require __DIR__ . '/includes/partials/colour-picker.php';
 *   ?>
 *
 * Used by: /manage/songbooks (create form + edit modal). New consumers
 * should pull this partial rather than rolling their own.
 */

/* Prevent direct access */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* Defensive defaults so a forgetful caller still renders a usable
   widget rather than crashing. */
$name        = isset($name)        ? (string)$name        : 'colour';
$value       = isset($value)       ? (string)$value       : '';
$idPrefix    = isset($idPrefix)    ? (string)$idPrefix    : 'colour';
$label       = isset($label)       ? (string)$label       : 'Colour (hex)';
$placeholder = isset($placeholder) ? (string)$placeholder : '#1a73e8';
$required    = isset($required)    ? (bool)$required      : false;

/* Sanitise the id prefix the same way the IETF picker partial does. */
$idSafe = preg_replace('/[^A-Za-z0-9_-]/', '-', $idPrefix);

/* The native <input type="color"> requires a 6-char hex; if the saved
   value is shorter (e.g. "#abc") we still echo it on the text input
   but seed the swatch with a sensible default so the colour picker
   widget renders in a usable state. */
$swatchSeed = preg_match('/^#[0-9A-Fa-f]{6}$/', $value) ? $value : '#cccccc';
?>
<div class="colour-picker d-flex align-items-center gap-2"
     data-colour-picker-id="<?= htmlspecialchars($idSafe, ENT_QUOTES, 'UTF-8') ?>">
    <input type="color"
           class="form-control form-control-color colour-picker-swatch"
           value="<?= htmlspecialchars($swatchSeed, ENT_QUOTES, 'UTF-8') ?>"
           aria-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?> swatch"
           style="width: 2.5rem; flex: 0 0 auto; padding: 0.125rem;">
    <input type="text"
           name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
           id="<?= htmlspecialchars($idSafe, ENT_QUOTES, 'UTF-8') ?>-text"
           class="form-control form-control-sm colour-picker-text"
           value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"
           pattern="#[0-9A-Fa-f]{6}"
           placeholder="<?= htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') ?>"
           autocomplete="off"
           <?= $required ? 'required' : '' ?>>
</div>
