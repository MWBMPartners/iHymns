<?php
/**
 * Guard: inline chord-line renderer (#299).
 *
 * ihymns_render_chord_line_html() (includes/chord_display.php) turns a
 * component chord line into per-chord `<span data-chord>` tokens (the unit
 * transpose.js rewrites) while preserving the whitespace that positions each
 * chord over its word. DB-free behavioural unit test (CI globs tests/php/*.php).
 *
 * Mutation check: change the span emit to drop the `data-chord` attribute, or
 * make the whitespace branch collapse spaces — either goes RED here.
 */

require __DIR__ . '/../../appWeb/public_html/includes/chord_display.php';

$fails = [];
$eq = function (string $label, $got, $want) use (&$fails): void {
    if ($got !== $want) {
        $fails[] = "$label\n      got:  " . var_export($got, true) . "\n      want: " . var_export($want, true);
    }
};

/* ---- has-content detection ---- */
$eq('has: positioned',  ihymns_chord_line_has_content('G   C'), true);
$eq('has: empty',       ihymns_chord_line_has_content(''), false);
$eq('has: spaces only', ihymns_chord_line_has_content('    '), false);
$eq('has: array',       ihymns_chord_line_has_content(['G', 'C']), true);
$eq('has: empty array', ihymns_chord_line_has_content([]), false);
$eq('has: null',        ihymns_chord_line_has_content(null), false);

/* ---- string → per-token spans, spacing preserved verbatim ---- */
$eq(
    'positioned line',
    ihymns_render_chord_line_html('G   C'),
    '<span data-chord="G">G</span>   <span data-chord="C">C</span>'
);
$eq(
    'leading spaces kept',
    ihymns_render_chord_line_html('  Am7'),
    '  <span data-chord="Am7">Am7</span>'
);

/* ---- array form is space-joined (matches print.js) ---- */
$eq(
    'array form',
    ihymns_render_chord_line_html(['G', 'C', 'D']),
    '<span data-chord="G">G</span> <span data-chord="C">C</span> <span data-chord="D">D</span>'
);

/* ---- empty → '' so the caller emits NO chord row ---- */
$eq('empty → nothing',  ihymns_render_chord_line_html(''), '');
$eq('spaces → nothing', ihymns_render_chord_line_html('     '), '');
$eq('null → nothing',   ihymns_render_chord_line_html(null), '');

/* ---- HTML-escaping: a slash chord / a chord with special chars ---- */
$eq(
    'slash chord escaped',
    ihymns_render_chord_line_html('G/B'),
    '<span data-chord="G/B">G/B</span>'
);

/* ---- every non-space token becomes exactly one data-chord span ---- */
$html  = ihymns_render_chord_line_html('G  G7  C   G');
$spans = substr_count($html, 'data-chord=');
$eq('token count', $spans, 4);

if ($fails) {
    fwrite(STDERR, "FAIL: chord-display (#299):\n  - " . implode("\n  - ", $fails) . "\n");
    exit(1);
}
echo "PASS: inline chord renderer — 15 assertions, per-token data-chord spans, spacing preserved, empty→''.\n";
