/**
 * Colour picker (#715)
 *
 * Two-way bind between a native <input type="color"> swatch and the
 * sibling <input type="text"> hex field. Used wherever a curator
 * historically had to type "#1a73e8" by hand — songbooks editor today,
 * and any future field that adopts the colour-picker partial.
 *
 * Markup contract — caller renders something like:
 *
 *   <div class="colour-picker" data-colour-picker-id="edit">
 *     <input type="color" class="colour-picker-swatch" value="#1a73e8">
 *     <input type="text"  class="colour-picker-text"   value="#1a73e8">
 *   </div>
 *
 * Boot every instance on the page in one call:
 *
 *   import { bootColourPickers } from '/js/modules/colour-picker.js';
 *   bootColourPickers();   // walks the document
 *
 * Or boot a single instance:
 *
 *   bootColourPicker(document.querySelector('.colour-picker'));
 */

/* Empty-or-valid hex check — what the form's `pattern=` attribute
   already enforces, mirrored here so we don't push garbage into the
   swatch (the native <input type="color"> silently snaps invalid
   values to #000000, which is a confusing user experience). */
const HEX_RE = /^#[0-9A-Fa-f]{6}$/;

/**
 * Boot one colour-picker instance. Idempotent — safe to call twice on
 * the same root.
 */
export function bootColourPicker(rootEl) {
    if (!rootEl || rootEl.dataset.colourPickerBooted === '1') return;
    rootEl.dataset.colourPickerBooted = '1';

    const swatch = rootEl.querySelector('.colour-picker-swatch');
    const text   = rootEl.querySelector('.colour-picker-text');
    if (!swatch || !text) return;

    /* Swatch → text. The native picker emits #RRGGBB lowercase already;
       we still uppercase the alpha-hex characters to match the codebase
       convention used elsewhere (config.php palette constants etc.). */
    swatch.addEventListener('input', () => {
        text.value = swatch.value.toUpperCase().replace(/^#([0-9A-F]{6})$/i,
            (_m, hex) => '#' + hex.toLowerCase());
        text.dispatchEvent(new Event('input', { bubbles: true }));
    });

    /* Text → swatch. Only sync when the typed value is a complete
       6-char hex; partial input ("#1a7") leaves the swatch untouched
       so the user doesn't see it flicker between colours mid-keystroke. */
    text.addEventListener('input', () => {
        const v = text.value.trim();
        if (HEX_RE.test(v)) {
            swatch.value = v.toLowerCase();
        }
    });
}

/**
 * Boot every .colour-picker instance currently in the document.
 * Use this on page-load; for dynamically-inserted markup (e.g. a
 * modal whose content arrives after init), call bootColourPicker(el)
 * on the specific root once it's in the DOM.
 */
export function bootColourPickers(root = document) {
    const els = root.querySelectorAll('.colour-picker[data-colour-picker-id]');
    els.forEach(bootColourPicker);
}

/* Convenience for classic-script callers that can't import. */
if (typeof window !== 'undefined') {
    window.colourPicker = { bootColourPicker, bootColourPickers };
}
