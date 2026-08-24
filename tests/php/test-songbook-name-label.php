<?php

declare(strict_types=1);

/**
 * iHymns — ihymns_songbook_name_label() unit + PHP↔JS↔CSS lockstep (#1531)
 *
 * PURPOSE
 * -------
 * #1531 shows the full songbook NAME (not the "SDAH" code) under a song title
 * in list rows. On the client that markup comes from js/constants.js
 * `songbookLabel(abbr, fullName)`; server-side (musician.php discography) it
 * comes from the NEW shared helper `ihymns_songbook_name_label($abbr, $name)`
 * in includes/songbook_display.php. The two MUST emit the same two-span
 * structure or a PHP-rendered row and a JS-rendered row look different and the
 * responsive width-swap CSS (`.songbook-name-full` / `.songbook-name-abbr` in
 * app.css) only styles one of them.
 *
 * This guards:
 *   1. the helper's branch logic (no name / name === abbr → bare abbr; a
 *      distinct name → both spans);
 *   2. HTML-escaping (the helper self-escapes, callers pass raw);
 *   3. the CROSS-FILE class-name agreement (rule #35): the exact class tokens
 *      the PHP helper emits ALSO appear in js/constants.js `songbookLabel()`
 *      and in css/app.css — so a rename in one file that isn't mirrored in the
 *      others goes red instead of silently un-styling half the app.
 *
 *   php tests/php/test-songbook-name-label.php
 *
 * PROVE-FAIL: rename `songbook-name-full` in the helper → assertions (1)/(3)
 * fail; drop the strcasecmp name===abbr branch → the "same word" assertion
 * fails; remove htmlspecialchars → the escaping assertion fails. (Verified.)
 */

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/songbook_display.php';

$passed = 0;
$failed = 0;
function ok(string $label, bool $cond): void
{
    global $passed, $failed;
    if ($cond) { echo "  PASS  $label\n"; $passed++; }
    else       { echo "  FAIL  $label\n"; $failed++; }
}

$PUB = dirname(__DIR__, 2) . '/appWeb/public_html';

/* ---- 1. branch logic ---------------------------------------------------- */

$distinct = ihymns_songbook_name_label('SDAH', 'Seventh-day Adventist Hymnal');
ok('a distinct name emits the full-name span', str_contains($distinct, '<span class="songbook-name-full">Seventh-day Adventist Hymnal</span>'));
ok('a distinct name emits the abbr span', str_contains($distinct, '<span class="songbook-name-abbr">SDAH</span>'));

ok('an empty name returns the bare abbr (no spans)',
    ihymns_songbook_name_label('MP', '') === 'MP');
ok('a NULL name returns the bare abbr (no spans)',
    ihymns_songbook_name_label('MP', null) === 'MP');
ok('name identical to abbr returns the bare abbr (no duplicate word)',
    ihymns_songbook_name_label('Psalty', 'Psalty') === 'Psalty');
ok('name equal to abbr case-insensitively still collapses to the bare abbr',
    ihymns_songbook_name_label('MP', 'mp') === 'MP');
ok('surrounding whitespace on the name is trimmed before the equality check',
    ihymns_songbook_name_label('MP', '  MP  ') === 'MP');

/* ---- 2. escaping (XSS-safe; caller passes raw) --------------------------- */

$xss = ihymns_songbook_name_label('A<b>', 'B & <script>alert(1)</script>');
ok('the full name is HTML-escaped', str_contains($xss, 'B &amp; &lt;script&gt;alert(1)&lt;/script&gt;'));
ok('the abbr is HTML-escaped', str_contains($xss, 'A&lt;b&gt;'));
ok('no raw <script> survives in the output', !str_contains($xss, '<script>'));

/* ---- 3. PHP↔JS↔CSS class-name lockstep (rule #35) ------------------------ */

/* The two class tokens the PHP helper commits to — derived from the helper's
   OWN output (not a typed list), so if the helper renames one, EITHER these
   assertions or the mirror checks below go red. */
$fullClass = 'songbook-name-full';
$abbrClass = 'songbook-name-abbr';
ok('the helper output uses the .songbook-name-full class token', str_contains($distinct, 'class="' . $fullClass . '"'));
ok('the helper output uses the .songbook-name-abbr class token', str_contains($distinct, 'class="' . $abbrClass . '"'));

$constantsJs = file_get_contents($PUB . '/js/constants.js');
ok('js constants.js songbookLabel() emits the SAME .songbook-name-full token',
    $constantsJs !== false && str_contains($constantsJs, 'songbook-name-full'));
ok('js constants.js songbookLabel() emits the SAME .songbook-name-abbr token',
    $constantsJs !== false && str_contains($constantsJs, 'songbook-name-abbr'));

$appCss = file_get_contents($PUB . '/css/app.css');
ok('css app.css styles the .songbook-name-full class (else the full name is unstyled)',
    $appCss !== false && str_contains($appCss, '.' . $fullClass));
ok('css app.css styles the .songbook-name-abbr class (else the responsive swap is dead)',
    $appCss !== false && str_contains($appCss, '.' . $abbrClass));

/* ---- summary ------------------------------------------------------------ */

if ($failed === 0) {
    echo "\nAll $passed songbook-name-label assertions passed.\n";
    exit(0);
}
fwrite(STDERR, "\n$failed of " . ($passed + $failed) . " assertion(s) failed.\n");
exit(1);
