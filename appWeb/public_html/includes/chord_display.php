<?php
/**
 * iHymns — inline chord-line renderer for the song page (#299).
 *
 * ELI5: a song's chords come as a "chord line" that sits above a lyric line,
 * e.g. "G        G7       C   G" over "Amazing grace! how sweet the sound".
 * This turns that chord line into HTML where every chord (G, G7, C) is its own
 * little tag the transpose buttons can rewrite, while the spaces between them
 * are kept exactly so the chords stay roughly over the right words.
 *
 * WHY per-chord <span data-chord>: js/modules/transpose.js (already built +
 * router-wired) transposes by walking every `[data-chord]` element and
 * replacing its text with the transposed chord (`renderChords()`, composing
 * the transpose offset with the #1271 capo overlay via composeChordDisplay()).
 * The chord DATA, though, is a whole space-positioned line — so this splits
 * the line into chord tokens (wrapped) and whitespace runs (preserved
 * verbatim), which a `white-space: pre` monospace container then lays out.
 * Same fidelity as the print path (js/modules/print.js `renderBlock` — a
 * `<div class="print-chord">` above each line), just made transpose-aware.
 *
 * The chord value may arrive as a positioned STRING or as an ARRAY of tokens
 * (both shapes exist in the component payload — print.js handles both the same
 * way); an array is space-joined, matching print.js.
 *
 * @see appWeb/public_html/js/modules/transpose.js  renderChords(), composeChordDisplay()
 * @see appWeb/public_html/js/modules/print.js       renderBlock() chord path
 * @see https://developer.mozilla.org/docs/Web/CSS/white-space  (pre)
 * @link https://github.com/MWBMPartners/iHymns/issues/299
 */

if (!function_exists('ihymns_chord_line_to_string')) {
    /**
     * Normalise a component chord value (string | array | null) to a string.
     * An array of tokens is space-joined (positioning is already lost in that
     * shape — matches print.js). A string is returned verbatim so its internal
     * spacing (the positioning) survives.
     *
     * @param mixed $chord The `chords[i]` value from a component.
     * @return string
     */
    function ihymns_chord_line_to_string($chord): string
    {
        if (is_array($chord)) {
            return implode(' ', array_map(static fn($t) => (string)$t, $chord));
        }
        return (string)$chord;
    }
}

if (!function_exists('ihymns_chord_line_has_content')) {
    /**
     * True when a chord value carries at least one non-space character.
     *
     * @param mixed $chord The `chords[i]` value from a component.
     * @return bool
     */
    function ihymns_chord_line_has_content($chord): bool
    {
        return trim(ihymns_chord_line_to_string($chord)) !== '';
    }
}

if (!function_exists('ihymns_render_chord_line_html')) {
    /**
     * Render a chord line as HTML: each chord token wrapped in
     * `<span data-chord="…">`, each whitespace run preserved verbatim so a
     * `white-space: pre` monospace container keeps the chords positioned over
     * their words. Returns '' for an empty/whitespace-only chord line (the
     * caller then emits no chord row at all).
     *
     * @param mixed $chord The `chords[i]` value (string | array | null).
     * @return string HTML-escaped chord line, or '' when there is nothing to show.
     */
    function ihymns_render_chord_line_html($chord): string
    {
        $line = ihymns_chord_line_to_string($chord);
        if (trim($line) === '') {
            return '';
        }
        /* Split into alternating whitespace runs and non-space tokens, keeping
           BOTH (PREG_SPLIT_DELIM_CAPTURE). NO_EMPTY drops the empty leading/
           trailing pieces preg_split would otherwise emit. */
        $parts = preg_split(
            '/(\s+)/u',
            $line,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );
        if ($parts === false) {
            return '';
        }
        $out = '';
        foreach ($parts as $part) {
            if (trim($part) === '') {
                /* whitespace run — preserve verbatim (monospace container keeps
                   the positioning). htmlspecialchars leaves ordinary spaces as-is. */
                $out .= htmlspecialchars($part, ENT_QUOTES);
            } else {
                /* a chord token — the unit transpose.js rewrites. */
                $esc = htmlspecialchars($part, ENT_QUOTES);
                $out .= '<span data-chord="' . $esc . '">' . $esc . '</span>';
            }
        }
        return $out;
    }
}
