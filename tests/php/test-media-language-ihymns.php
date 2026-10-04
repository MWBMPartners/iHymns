<?php

declare(strict_types=1);

/**
 * iHymns — how iHymns USES the shared language policy (#2137)
 *
 * ELI5: the policy's own 290 examples are checked by
 * test-media-language-conformance.php. THIS file checks iHymns' side: that
 * every place iHymns saves, imports or reads a language code goes through the
 * shared rules — letter case fixed rather than refused, old three-letter codes
 * read, unreadable values reported rather than guessed — and that nobody has
 * quietly added a second, hand-written language-code pattern somewhere.
 *
 * NO DATABASE is needed: every function exercised here either takes plain
 * values or refuses before touching the database.
 *
 * CHECKS
 * ------
 * (A) The loader finds the shared code and data in
 *     appWeb/public_html/includes/vendor/media-language/ and loads them.
 * (B) mediaLanguageTagForStorage() — the ONE storage rule — on a truth table,
 *     and _ietfBcp47Validate() gives the same answers (it now delegates).
 * (C) normaliseSongbookLanguage() / validateSongbookBcp47() — the songbook
 *     form's rule is the same rule (it used to refuse variants and case slips).
 * (D) mediaLanguageReadExternal() — the FILE reader (old codes, unreadable).
 * (E) _bulkImport_normaliseLanguages() — every language in an imported song.
 * (F) lyricsIngest_parseTtml() — TTML xml:lang on the document and on lines.
 * (G) mediaLanguageFirstRefusalInComponents() — the editors' refusal message.
 * (H) The MARC helpers read and write through the shared ISO 639-2 data.
 * (J) #2132 — an unknown language is `und`, never English: the schema
 *     default and the migration agree byte for byte; the migration is
 *     registered with a real probe; the language filter lets und / mul / zxx
 *     through; the song page's search-engine data no longer falls back to the
 *     page's own language; and a GUARD derived from the tree fails if any
 *     language value falls back to 'en' again (a line marked #2134, the song
 *     request feature filed separately, is reported, not failed). Round 4
 *     of the #2137 review added JavaScript object keys, var/let/const
 *     declarations and a PHP `match` (one line or several) giving 'en', and
 *     a list of deliberate exceptions by file AND line content (only
 *     print.js's sample English hymn), each of which must still be found.
 *     Mutation-proven: `language: 'en'` in editor.js's staged link (the
 *     reviewer's mutation), `var language='en'`, `{'language': 'en'}`, a
 *     several-line `match`, a second `language: 'en'` in print.js, and the
 *     excepted print.js line changing each turn it red. Round 5 of the
 *     review (the fourth review's G02–G05, which all went unnoticed): the
 *     exception must equal the WHOLE trimmed line (a fallback appended to
 *     print.js's sample line is no longer excused); names containing
 *     language/lang (`languageCode: 'en'`, `targetLang`, `'languageCode' =>`);
 *     assignments with no spaces at a statement or argument start
 *     (`if(!lang)lang='en'`); logical assignments (`lang||='en'`); any
 *     regional English (`|| 'en-GB'`); and `setLanguage('en')`. Each of G01–
 *     G06, and removing each new pattern, the regional English or the wider
 *     names, or going back to "contains" for the exception, turns it red.
 *     What it still cannot see is listed in DEV_NOTES ("Unknown is und").
 * (K) #2131 — whole-song translations may use regional and script tags:
 *     schema.sql has no fk_Trans_Lang (uq_Translation stays), the migration
 *     drops the link and its leftover index only if present, it is
 *     registered with an OR-probe, and the remap tool asks the live schema.
 * (L) Stored order (policy Part A, TEXT-010): the helpers on a truth
 *     table, and the translation readers no longer JOIN the registry for
 *     identity, mark the original, and return stored order.
 * (M) Presentation (policy Part B): composed names ("Portuguese (Brazil)"),
 *     text direction from the script, the reader's order, preferences kept
 *     whole and in order (with the old API shape still available), songbook
 *     tiles, and source checks for the song page and the API.
 * (I) GUARD, derived from the tree (rule #34): no PHP file under
 *     appWeb/public_html/ contains a hand-written language-tag pattern of the
 *     shapes the five retired checkers used. Mutation-proven: re-adding one
 *     such pattern to a file turned this red.
 *
 *   php tests/php/test-media-language-ihymns.php
 *
 * Exit status 0 = everything holds, 1 = anything failed.
 *
 * @see appWeb/public_html/includes/media_language.php
 * @see docs/standards/media-language-bcp47-policy.md
 */

$repoRoot = dirname(__DIR__, 2);
$inc      = $repoRoot . '/appWeb/public_html/includes';

require_once $inc . '/media_language.php';
require_once $inc . '/song_importers.php';
require_once $inc . '/songbook_validation.php';
require_once $inc . '/lyrics_ingest.php';
require_once $inc . '/marcxml.php';

$passed   = 0;
$failures = [];
function mliCheck(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failures;
    if ($ok) {
        $passed++;
        echo "  PASS  {$name}\n";
    } else {
        $failures[] = $name . ($detail !== '' ? " — {$detail}" : '');
        echo "  FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}
function mliShow(mixed $v): string
{
    return var_export($v, true);
}

/* ---------------------------------------------------------------- (A) --- */
echo "(A) loader\n";
mliCheck('the shared code folder is includes/vendor/media-language/, under the loader (the deploy uploads it; see test-media-language-deploy-layout.php)',
    str_ends_with(str_replace('\\', '/', mediaLanguageLibraryDir()), 'appWeb/public_html/includes/vendor/media-language'),
    mediaLanguageLibraryDir());
mliCheck('mediaLanguageReady() loads the shared code and its data', mediaLanguageReady() === true);

/* ---------------------------------------------------------------- (B) --- */
echo "(B) the one storage rule\n";
$storage = [
    [''             , null,         'empty is "not given", not an error'],
    ['   '          , null,         'spaces only is "not given"'],
    ['en'           , 'en',         'a plain tag is kept'],
    ['EN-gb'        , 'en-GB',      'letter case is FIXED, not refused (LANG-001)'],
    ['zh-hant-tw'   , 'zh-Hant-TW', 'script title case, region upper case'],
    ['iw'           , 'he',         'a retired code is replaced (iw -> he)'],
    ['de-1996'      , 'de-1996',    'a variant is accepted (the songbook form used to refuse it)'],
    ['es-419'       , 'es-419',     'a numeric area code is kept'],
    ['x-hymnal'     , 'x-hymnal',   'a private-use tag is a tag'],
    ['i-klingon'    , 'tlh',        'an old grandfathered tag with a replacement is replaced'],
    ['English'      , false,        'a language NAME is not a tag'],
    ['pt_BR'        , false,        'an underscore is not a separator in a tag'],
    ['en-x-aaaaaaaa-bbbbbbbb-cccccccc-dddddddd', false, 'longer than the 35-character column: refused, never cut short'],
];
foreach ($storage as [$in, $want, $why]) {
    $got = mediaLanguageTagForStorage($in);
    mliCheck("mediaLanguageTagForStorage(" . mliShow($in) . ") = " . mliShow($want) . " — {$why}", $got === $want, 'got ' . mliShow($got));
    $old = _ietfBcp47Validate($in);
    mliCheck("_ietfBcp47Validate(" . mliShow($in) . ") gives the same answer (it delegates)", $old === $want, 'got ' . mliShow($old));
}

/* ---------------------------------------------------------------- (C) --- */
echo "(C) the songbook form uses the same rule\n";
mliCheck('songbook language: empty -> [null, null]', normaliseSongbookLanguage('') === [null, null]);
mliCheck('songbook language: pt-br -> saved as pt-BR', normaliseSongbookLanguage('pt-br') === ['pt-BR', null]);
mliCheck('songbook language: de-1996 accepted', normaliseSongbookLanguage('de-1996') === ['de-1996', null]);
[$sbTag, $sbErr] = normaliseSongbookLanguage('Portuguese');
mliCheck('songbook language: "Portuguese" refused with a plain message naming it',
    $sbTag === null && is_string($sbErr) && str_contains($sbErr, 'Portuguese') && str_contains($sbErr, 'pt-BR'), mliShow($sbErr));
mliCheck('validateSongbookBcp47() agrees (null = acceptable)',
    validateSongbookBcp47('pt-br') === null && is_string(validateSongbookBcp47('Portuguese')));

/* ---------------------------------------------------------------- (D) --- */
echo "(D) the file reader\n";
$reads = [
    [''        , ['tag' => null,  'unrecognised' => null]],
    ['eng'     , ['tag' => 'en',  'unrecognised' => null]],
    ['GER'     , ['tag' => 'de',  'unrecognised' => null]],
    ['XXX'     , ['tag' => 'und', 'unrecognised' => null]],
    ['fre-ca'  , ['tag' => 'fr-CA', 'unrecognised' => null]],
    ["eng\0\0" , ['tag' => 'en',  'unrecognised' => null]],
    ['en-us'   , ['tag' => 'en-US', 'unrecognised' => null]],
    ['Englsh'  , ['tag' => null,  'unrecognised' => 'Englsh']],
    ['xyz'     , ['tag' => null,  'unrecognised' => 'xyz']],
];
foreach ($reads as [$in, $want]) {
    $got = mediaLanguageReadExternal($in);
    mliCheck('mediaLanguageReadExternal(' . mliShow($in) . ')', $got === $want, 'got ' . mliShow($got));
}

/* ---------------------------------------------------------------- (E) --- */
echo "(E) every language in an imported song\n";
[$song, $notes] = _bulkImport_normaliseLanguages([
    'language'   => 'eng',
    'components' => [
        ['type' => 'verse', 'language' => 'GER', 'languages' => ['fre', 'zzzzz', '', null]],
        ['type' => 'chorus', 'language' => 'Deutsch'],
    ],
    'altTitles'  => [['title' => 'X', 'language' => 'spa'], ['title' => 'Y', 'language' => 'Espanol']],
]);
mliCheck('song language eng -> en', $song['language'] === 'en', mliShow($song['language']));
mliCheck('section language GER -> de', $song['components'][0]['language'] === 'de');
mliCheck('per-line languages: fre -> fr, unreadable -> null (inherit), empty stays null',
    $song['components'][0]['languages'] === ['fr', null, null, null], mliShow($song['components'][0]['languages']));
mliCheck('an unreadable section language becomes no language', $song['components'][1]['language'] === null);
mliCheck('alternative title spa -> es', $song['altTitles'][0]['language'] === 'es');
mliCheck('an unreadable alternative-title language becomes empty', $song['altTitles'][1]['language'] === '');
$noteFields = array_map(static fn(array $n): string => $n[0] . '=' . $n[1], $notes);
mliCheck('every unreadable value is listed for reporting, with where it was and the original text',
    $noteFields === [
        'line 2 of section 1 language=zzzzz',
        'section 2 language=Deutsch',
        'alternative title 2 language=Espanol',
    ], mliShow($noteFields));
[$song2, $notes2] = _bulkImport_normaliseLanguages(['language' => 'Englsh']);
mliCheck('an unreadable SONG language becomes und (never a guess) and is listed',
    $song2['language'] === 'und' && $notes2 === [['language', 'Englsh']], mliShow([$song2['language'], $notes2]));
[$song3, $notes3] = _bulkImport_normaliseLanguages(['language' => '']);
mliCheck('an empty song language stays empty (the saver applies its default)', $song3['language'] === '' && $notes3 === []);

/* ---------------------------------------------------------------- (F) --- */
echo "(F) TTML xml:lang\n";
$ttml = static fn(string $rootLang, string $lineLang): string =>
    '<?xml version="1.0" encoding="UTF-8"?>'
    . '<tt xmlns="http://www.w3.org/ns/ttml"' . ($rootLang !== '' ? ' xml:lang="' . $rootLang . '"' : '') . '>'
    . '<body><div><p begin="0s" end="1s"' . ($lineLang !== '' ? ' xml:lang="' . $lineLang . '"' : '') . '>Hello</p></div></body></tt>';
$p1 = lyricsIngest_parseTtml($ttml('EN-us', 'deu'));
mliCheck('document xml:lang EN-us -> en-US', $p1['language'] === 'en-US', mliShow($p1['language']));
mliCheck('document language readable -> no unrecognised text', $p1['languageUnrecognised'] === null);
mliCheck('line xml:lang deu (old three-letter form) -> de', $p1['lines'][0]['languageCode'] === 'de', mliShow($p1['lines'][0]['languageCode']));
$p2 = lyricsIngest_parseTtml($ttml('Englsh', 'Klingonish'));
mliCheck('an unreadable document xml:lang is not guessed (null) and is returned for reporting',
    $p2['language'] === null && $p2['languageUnrecognised'] === 'Englsh', mliShow([$p2['language'], $p2['languageUnrecognised']]));
mliCheck('an unreadable line xml:lang becomes null (the line inherits)', $p2['lines'][0]['languageCode'] === null);
mliCheck("…and the line's original xml:lang text is kept in its meta",
    ($p2['lines'][0]['meta']['xml:lang'] ?? null) === 'Klingonish', mliShow($p2['lines'][0]['meta'] ?? null));

/* ---------------------------------------------------------------- (G) --- */
echo "(G) the editors' refusal message\n";
mliCheck('valid, tidy-able and empty section/line languages pass',
    mediaLanguageFirstRefusalInComponents([
        ['type' => 'verse', 'number' => 1, 'language' => 'pt-br', 'languages' => ['', 'EN', null]],
    ]) === null);
$msg = mediaLanguageFirstRefusalInComponents([
    ['type' => 'verse', 'number' => 1, 'language' => 'en'],
    ['type' => 'verse', 'number' => 2, 'language' => 'English'],
]);
mliCheck('a bad section language is refused, naming the section and the value',
    is_string($msg) && str_contains($msg, 'Verse 2') && str_contains($msg, 'English'), mliShow($msg));
$msg = mediaLanguageFirstRefusalInComponents([
    ['type' => 'chorus', 'number' => 0, 'language' => 'en', 'languages' => ['', '', 'pt_BR']],
]);
mliCheck('a bad per-line language is refused, naming the line',
    is_string($msg) && str_contains($msg, 'line 3 of Chorus') && str_contains($msg, 'pt_BR'), mliShow($msg));

/* ---------------------------------------------------------------- (H) --- */
echo "(H) MARC language codes use the shared ISO 639-2 data\n";
mliCheck('MARC ger -> de', marcxmlLanguageCodeToBcp47('ger') === 'de');
mliCheck('MARC chi -> zh', marcxmlLanguageCodeToBcp47('chi') === 'zh');
mliCheck('MARC xyz -> "" (unreadable: left blank, never stored as if it were a language)', marcxmlLanguageCodeToBcp47('xyz') === '');
mliCheck('export de -> ger (bibliographic form, as MARC uses)', marcxmlBcp47ToLanguageCode('de') === 'ger');
mliCheck('export zh-Hant-TW -> chi (MARC 041 records the language only)', marcxmlBcp47ToLanguageCode('zh-Hant-TW') === 'chi');
mliCheck('export yue -> "" (no ISO 639-2 code: 041 is left out rather than claim "undetermined")', marcxmlBcp47ToLanguageCode('yue') === '');
mliCheck('export und -> und', marcxmlBcp47ToLanguageCode('und') === 'und');

/* ---------------------------------------------------------------- (I) --- */
echo "(I) guard: no hand-written language-tag pattern in the site's PHP\n";
/* The five retired checkers each had a pattern of this shape: a 2-3 letter
   class followed by an optional-subtag group, e.g.
     /^[a-z]{2,3}(-[A-Z][a-z]{3})?…/   /^[A-Za-z]{2,3}([-_][A-Za-z0-9]{1,8})*$/
     /^[a-z]{2,3}(-[A-Za-z0-9]+)*$/i
   A primary-subtag-only pattern (`/^[a-z]{2,3}$/`) is not matched: reading
   the first part of a tag is not validating one. Derived from the tree — every
   .php file under appWeb/public_html — never a typed list (rule #34). */
$pattern = '/\[(?:a-z|A-Za-z|a-zA-Z)\]\{2,3\}\((?:-|\[-_\]|\\\\-)/';
$scanned = 0;
$offenders = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repoRoot . '/appWeb/public_html', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $scanned++;
    foreach (file($file->getPathname()) ?: [] as $i => $line) {
        if (preg_match($pattern, $line) === 1) {
            $offenders[] = substr($file->getPathname(), strlen($repoRoot) + 1) . ':' . ($i + 1) . ': ' . trim($line);
        }
    }
}
mliCheck("the guard actually scanned the site's PHP (found {$scanned} files)", $scanned > 200);
mliCheck('no hand-written language-tag pattern remains (use mediaLanguageTagForStorage() / mediaLanguageReadExternal())',
    $offenders === [], implode("\n        ", $offenders));

/* ---------------------------------------------------------------- (J) --- */
echo "(J) an unknown language is und, never English (#2132)\n";
require_once $inc . '/language_filter.php';
$schemaSql = (string)file_get_contents($repoRoot . '/appWeb/.sql/schema.sql');
$migSrc    = (string)file_get_contents($repoRoot . '/appWeb/.sql/migrate-song-language-default-und.php');
$schemaDef = preg_match("/\\n\\s+Language\\s+(VARCHAR\\(35\\)\\s+NOT NULL DEFAULT 'und' COMMENT '[^']*')/", $schemaSql, $sm) ? preg_replace('/\\s+/', ' ', $sm[1]) : null;
$migDef    = preg_match("/MODIFY COLUMN Language (VARCHAR\\(35\\) NOT NULL DEFAULT 'und' COMMENT '[^']*')/", $migSrc, $mm) ? $mm[1] : null;
mliCheck("schema.sql: tblSongs.Language defaults to 'und'", $schemaDef !== null);
mliCheck('the migration restates the column byte-identically to schema.sql (rule #19)', $schemaDef !== null && $schemaDef === $migDef,
    mliShow([$schemaDef, $migDef]));
mliCheck("schema.sql no longer defaults tblSongs.Language to 'en'",
    preg_match("/CREATE TABLE IF NOT EXISTS tblSongs \\([^;]*?Language\\s+VARCHAR\\(35\\)\\s+NOT NULL DEFAULT 'en'/s", $schemaSql) !== 1);
$registrySrc = (string)file_get_contents($repoRoot . '/appWeb/public_html/manage/includes/migration-registry.php');
mliCheck('the migration is registered, with a probe that reads the live column default',
    str_contains($registrySrc, "'script' => 'migrate-song-language-default-und.php'")
    && str_contains($registrySrc, "_migProbe_columnDefaultValue(\$db, 'tblSongs', 'Language')"));
[$fWhere, $fTypes, $fVals] = applyLanguageFilterSql('s.Language', ['en']);
/* #2137 review round 4 — the rule is written twice (a fast path for a clean
   value, an exact path for the rest; language_filter.php says why), so each
   value is bound once per path. */
mliCheck('the SQL language filter lets und / mul / zxx through, all bound',
    $fVals === ['und', 'mul', 'zxx', 'en', 'und', 'mul', 'zxx', 'en'] && $fTypes === str_repeat('s', 8)
    && substr_count($fWhere, '?') === 8, mliShow([$fWhere, $fVals]));
$pred = makeLanguageFilterPredicate(['en']);
mliCheck('the in-memory filter keeps und, zxx and mul-Latn rows, and still drops fr',
    $pred(['language' => 'und']) && $pred(['language' => 'zxx']) && $pred(['Language' => 'mul-Latn']) && !$pred(['language' => 'fr']));
$indexSrc = (string)file_get_contents($repoRoot . '/appWeb/public_html/index.php');
mliCheck("JSON-LD inLanguage no longer falls back to the page's own interface language",
    !str_contains($indexSrc, '$jsonLdLanguages[] = $locale') && !str_contains($indexSrc, '?? $locale'));
/* GUARD — derived from the tree: every .php and .js file under
   appWeb/public_html (excluding third-party vendor folders). A language value
   must never fall back to English. Interface-locale settings (`locale`,
   `accept-language`) are about the SITE's language, not a song's, and do not
   match these patterns. */
/* #2137 review round 5 — "English" is any quoted `en` tag, not only `'en'`: a
   REGIONAL English fallback (`|| 'en-GB'`, `'en-US'`, `'en-x-…'`) is the same
   fault, and the fourth review's planted `|| 'en-GB'` went unnoticed. Every
   pattern below is built on this one piece. */
/* #2137 review round 6 (the fifth review's finding 4) — and English spelled
   other ways: a tag written with an underscore (`'en_GB'`, the way some
   systems write locales), the three-letter code (`'eng'`), the NAME
   (`'English'`), and a list holding it (`['en']`, `[ 'en-GB', … ]`). None of
   these is a song's language when the language is not known, any more than
   `'en'` is. */
$EN = '(?:\[\s*)?[\'"](?:en(?:[-_][A-Za-z0-9]{1,8})*|eng|english)[\'"]';
/* …except inside a `match`: there an arm giving 'English' or 'eng' is
   normally a LOOKUP (`'en' => 'English'`, `'en' => 'eng'`), not a fallback,
   so the two `match` patterns keep to the tag itself (`'en'`, `'en-GB'`,
   `'en_GB'`). */
$ENTAG = '[\'"]en(?:[-_][A-Za-z0-9]{1,8})*[\'"]';
/* …and "a language name" is any identifier CONTAINING language or lang
   (`languageCode`, `targetLang`, `songLanguage`), not only the bare words —
   the fourth review's `languageCode: 'en'` went unnoticed. */
$LANGWORD = '\w*(?:language|lang)\w*';
$fallbackPatterns = [
    /* #2137 review round 6 (finding 4) — this pattern and the two ternary
       ones below take any NAME containing lang/language ($LANGWORD), as the
       others already did: they used to require the word to END there
       (`(?:language|lang)\b`), so `song.languageCode || 'en'`,
       `$row['langCode'] ?? 'en'`, `$row['language_code'] ?? 'en'` and
       `$song->languageCode ?: 'en'` all got through. */
    '/' . $LANGWORD . '[^\n]{0,60}(?:\?\?|\|\||\?:)\s*' . $EN . '/i',       // x.language ?? 'en', lang || 'en', languageCode ?: 'en'
    /* PHP array keys containing language (any), or lang with more letters
       (`'songLang'`, `'langCode'`). A key that is exactly 'lang' is left out
       on purpose: the geocoder in manage/places-api.php asks for English place
       names with `'lang' => 'en'`, which is not a song's language. */
    '/[\'"](?:\w*language\w*|\w+lang\w*|lang\w+)[\'"]\s*=>\s*' . $EN . '/i', // 'language' => 'en', 'languageCode' => 'en'
    /* #2137 review — the two shapes the first version missed: */
    '/' . $LANGWORD . '[^\n]{0,80}\?[^\n]{0,80}:\s*' . $EN . '/i',           // $x !== '' ? $x : 'en'  (a ternary's "else")
    '/\$' . $LANGWORD . '\s*(?:\?\?|\|\||&&)?=\s*' . $EN . '/i',              // $language = 'en', $lang ??= 'en'
    '/(?:language|lang)\w*\s+(?:\?\?|\|\||&&)?=\s*' . $EN . '/i',           // song.language = 'en' (JS; a space before "=",
                                                                                   // so the HTML attribute lang="en" is not matched)
    /* #2137 second review — shapes that still got through: */
    '/' . $LANGWORD . '[^\n]{0,80}\?\s*' . $EN . '\s*:/i',                   // $lang === '' ? 'en' : $lang  (the ternary's THEN)
    '/\[[\'"]' . $LANGWORD . '[\'"]\]\s*(?:\?\?|\|\||&&)?=\s*' . $EN . '/i',   // $song['language'] = 'en', $row['languageCode'] = 'en'
    '/->\s*' . $LANGWORD . '\s*(?:\?\?|\|\||&&)?=\s*' . $EN . '/i',            // $song->language = 'en'
    '/\.' . $LANGWORD . '\s*(?:\?\?|\|\||&&)?=\s*' . $EN . '/i',               // song.language='en' (JS, no space; a dot, so not lang="en")
    /* #2137 review round 4 — shapes the third review showed still got through: */
    '/(?<![\w$.])[\'"]?' . $LANGWORD . '[\'"]?\s*:\s*' . $EN . '/i',          // { language: 'en' }, {'languageCode': 'en'}, "targetLang": "en"
    '/\b(?:var|let|const)\s+' . $LANGWORD . '\s*=\s*' . $EN . '/i',           // var language='en', let lang = "en", const songLang = 'en'
    '/(?:language|lang)\w*[\'"]?\]?\s*(?:=>|=)\s*match\s*\([^;]*?=>\s*' . $ENTAG . '/i', // $lang = match ($x) { '' => 'en', … } on ONE line
    /* #2137 review round 5 — shapes the fourth review showed still got through: */
    /* an assignment with no spaces where a statement or an argument starts
       (line start, after ; { } ( ) or ,): `language='en'`,
       `if(!lang)lang='en'`, `{ id, language = 'en' }`, `f(lang = 'en')`. The
       HTML attribute `<html lang="en">` follows a tag name and a space, so
       it is not matched; `==` / `===` are comparisons and are not matched.
       Round 6 (finding 4): also after `else` — `else lang='en';`. */
    '/(?:^|[;{}(),]|\belse\b)\s*' . $LANGWORD . '\s*=\s*' . $EN . '/i',
    '/' . $LANGWORD . '\s*(?:\?\?|\|\||&&)=\s*' . $EN . '/i',                // lang||='en', language??='en' (logical assignment, any spacing)
    '/\bset' . $LANGWORD . '\s*\(\s*' . $EN . '\s*[,)]/i',                    // setLanguage('en'), setSongLang('en-GB', …)
];
/* A `match` spread over several lines — `$lang = match ($x) {` then `'' => 'en',`
   on a later line — cannot be seen one line at a time, so each file is also
   read whole for it: a language variable or key assigned a `match` with an arm
   giving 'en', anywhere before the statement's closing `;`. */
$fallbackMatchWhole = '/(?:language|lang)\w*[\'"]?\]?\s*(?:=>|=)\s*match\s*\([^;]*?=>\s*' . $ENTAG . '/is';
/* Deliberate exceptions, each by PATH and the WHOLE LINE (trimmed), so it
   neither follows the line to a new meaning nor breaks when lines above it
   move, and each must still be found — a stale exception fails the check
   below.
     - js/modules/print.js: the print editor's sample song for its live
       preview is "Amazing Grace", an English hymn; `language: 'en'` there is
       the sample's real language, not a fallback for an unknown one.
   #2137 review round 5 — the WHOLE trimmed line must be equal, not merely
   contain the listed text: with "contains", a real fallback appended to the
   end of this line (`…, iswc: '', lang: x.lang || 'en',`) was excused along
   with it (the fourth review's planted fault G02). */
$enExempt = [
    'appWeb/public_html/js/modules/print.js' => "language: 'en', copyright: 'Public Domain', ccli: '22025', iswc: '',",
];
/* The code on one line, as the guard reads it — comment-stripped (like this
   repo's other source guards): a doc comment may SHOW an example shape such
   as 'language' => 'en' without it being code. A comment opened and closed on
   the line (`/* … *\/`) is removed first, so code AFTER it is still read —
   round 6 (finding 4): `lang = /* default *\/ x.language || 'en'` and
   `x.language || /* fallback *\/ 'en'` used to be cut off at the comment and
   got through. Then a line that starts as a comment is skipped (null), and a
   trailing block or line comment is cut off. */
$enCodeOf = static function (string $line): ?string {
    $code = (string)preg_replace('~/\\*.*?\\*/~', ' ', $line);
    if (preg_match('~^\\s*(?:\\*|//|/\\*|#)~', $code) === 1) {
        return null;
    }
    return (string)preg_replace('~\\s(?://|/\\*).*$~', '', $code);
};
$enIsExempt = static fn(string $rel, string $line): bool => isset($enExempt[$rel]) && trim($line) === $enExempt[$rel];
$enExemptSeen = [];
$enScanned = 0;
$enOffenders = [];
$enKnown = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repoRoot . '/appWeb/public_html', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = str_replace('\\', '/', $file->getPathname());
    if (!in_array($file->getExtension(), ['php', 'js'], true) || str_contains($path, '/vendor/')) {
        continue;
    }
    $enScanned++;
    foreach (file($file->getPathname()) ?: [] as $i => $line) {
        $code = $enCodeOf($line);
        if ($code === null) {
            continue;
        }
        foreach ($fallbackPatterns as $re) {
            if (preg_match($re, $code) === 1) {
                $rel = substr($path, strlen(str_replace('\\', '/', $repoRoot)) + 1);
                $where = $rel . ':' . ($i + 1);
                if ($enIsExempt($rel, $line)) {
                    $enExemptSeen[$rel] = ($enExemptSeen[$rel] ?? 0) + 1;
                } elseif (str_contains($line, '#2134')) {
                    $enKnown[] = $where;
                } else {
                    $enOffenders[] = $where . ': ' . trim($line);
                }
                break;
            }
        }
    }
}
mliCheck("the 'en' guard actually scanned the site's PHP and JS (found {$enScanned} files)", $enScanned > 300);
/* The multi-line `match` shape, file by file (whole-line comments removed
   first, as above). */
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repoRoot . '/appWeb/public_html', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = str_replace('\\', '/', $file->getPathname());
    if ($file->getExtension() !== 'php' || str_contains($path, '/vendor/')) {
        continue;
    }
    $lines = file($file->getPathname()) ?: [];
    foreach ($lines as $k => $l) {
        if (preg_match('~^\\s*(?:\\*|//|/\\*|#)~', $l) === 1) { $lines[$k] = "\n"; }
    }
    $src = implode('', $lines);
    if (preg_match_all($fallbackMatchWhole, $src, $mm, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($mm[0] as [$_txt, $off]) {
            $enOffenders[] = substr($path, strlen(str_replace('\\', '/', $repoRoot)) + 1) . ':'
                . (substr_count(substr($src, 0, $off), "\n") + 1) . ': a language taken from a `match` with an arm giving \'en\'';
        }
    }
}
foreach ($enExempt as $rel => $content) {
    mliCheck("the deliberate exception in {$rel} is still there, exactly once (else remove it from the list)",
        ($enExemptSeen[$rel] ?? 0) === 1, (string)($enExemptSeen[$rel] ?? 0));
}
/* #2137 review round 5 — the exception covers exactly its own line: the
   fourth review's G02 appended a real fallback to it, and "contains" excused
   that too. */
mliCheck('the print.js exception is the whole line, so a fallback appended to it is NOT excused',
    $enIsExempt('appWeb/public_html/js/modules/print.js', "    language: 'en', copyright: 'Public Domain', ccli: '22025', iswc: '',\n")
    && !$enIsExempt('appWeb/public_html/js/modules/print.js',
        "    language: 'en', copyright: 'Public Domain', ccli: '22025', iswc: '', lang: (window.x || {}).lang || 'en',\n"));
/* The guard's own patterns, on the shapes they must catch and the ones they
   must not (a guard that cannot fail proves nothing, rule #34). */
$enMustCatch = [
    "\$language = \$song['language'] !== '' ? \$song['language'] : 'en';",
    "\$language = 'en';",
    "\$lang ??= 'en';",
    "song.language = 'en';",
    "\$x = \$row['language'] ?? 'en';",
    "'language' => 'en',",
    /* the second review's shapes */
    "\$language = \$valid === null ? 'en' : \$valid;",
    "\$language = (\$raw === '') ? 'en' : \$raw;",
    ": (\$songPrimaryLang === '' ? 'en' : \$songPrimaryLang);",
    "\$song['language'] = 'en';",
    "\$row['Language'] = 'en';",
    "\$body['lang'] = 'en';",
    "\$song->language='en';",
    "song.language='en';",
    "'Language' => 'en',",
    /* the third review's shapes (round 4) */
    "var song = { id: id, language: 'en' };",
    "return { songId: s.id, language: 'en', title: t };",
    "const payload = {'language': 'en'};",
    '{"language": "en", "title": t}',
    "var language='en';",
    "let lang = \"en\";",
    "const songLang = 'en';",
    "\$lang = match (\$x) { '' => 'en', default => \$x };",
    "\$song['language'] = match (true) { \$raw === '' => 'en', default => \$raw };",
    /* the fourth review's shapes (round 5) */
    "song.translations[i] = { songId: targetId, language: targetLang, languageCode: 'en' };",   // G03
    "const link = { targetLang: 'en' };",
    "\$row = ['languageCode' => 'en'];",
    "\$x['languageCode'] = 'en';",
    "language='en';",
    "var targetLang = targetSong.language || ''; if(!targetLang)targetLang='en';",              // G04
    "if(!lang)lang='en';",
    "lang||='en';",
    "language??='en';",
    "song.lang ||= 'en';",
    "var targetLang = targetSong.language || 'en-GB';",                                          // G05
    "\$language = \$raw ?? 'en-US';",
    "{ id, language = 'en' } = song;",
    "function draw(lang = 'en') {",
    "setLanguage('en');",
    "picker.setLanguage(\"en-GB\");",
    /* JSON-LD's inLanguage IS a song's language — round 4 listed it as a
       shape NOT to flag; round 5 widened object keys to any name containing
       lang/language, and a song whose inLanguage falls back to 'en' is
       exactly the #2132 fault, so it is caught now. */
    "'inLanguage': 'en',",
    /* the fifth review's shapes (round 6, finding 4): longer names in every
       fallback shape, `else`, and English spelled other ways */
    "const tag = song.languageCode || 'en';",
    "\$tag = \$row['langCode'] ?? 'en';",
    "\$tag = \$row['language_code'] ?? 'en';",
    "\$code = \$song->languageCode ?: 'en';",
    "\$x = \$songLangCode !== '' ? \$songLangCode : 'en';",
    "var t = targetLangCode === '' ? 'en' : targetLangCode;",
    "if (x) lang = y; else lang='en';",
    "else language='en';",
    "var lang = song.language || 'eng';",
    "var language = song.language || 'English';",
    "var lang = song.language || 'en_GB';",
    "song.languages = song.languages || ['en'];",
    "\$langs = \$row['languages'] ?? [ 'en-GB', 'fr' ];",
];
$enMustPass = ['<html lang="en">', "if (\$lang === 'en') {", "\$language = mediaLanguageOrUnknown(\$valid);", "\$locale = 'en';",
    "'lang'  => 'en',   /* a geocoder's result language, not a song's */", "\$isEnglish = \$lang === 'en' ? 1 : 0;",
    /* round 4 */
    "var song = { language: targetLang };", "const isEnglish = lang === 'en';",
    "\$label = match (\$lang) { 'en' => 'English', default => \$lang };",
    /* round 5 — comparisons, a lookup and HTML are not fallbacks */
    "if(lang==='en'){", "if (language == 'en-GB') {", "if(lang!=='en')", "\$name = getLanguageName('en');",
    "echo '<html lang=\"en-GB\">';", "var english = 'en';", "\$locale = 'en-GB';",
    /* round 6 — a lookup inside a match, and English as a value of something that is not a language */
    "\$name = match (\$lang) { 'en' => 'English', default => \$lang };", "\$iso3 = match (\$lang) { 'en' => 'eng', default => '' };",
    "\$title = \$song['title'] ?? 'English hymns';", "if (lang === 'eng') {"];
/* The multi-line `match` shape, on its own. */
mliCheck("the 'en' guard catches a `match` over several lines giving 'en' for a language",
    preg_match($fallbackMatchWhole, "\$lang = match (\$raw) {\n    '' => 'en',\n    default => \$raw,\n};") === 1
    && preg_match($fallbackMatchWhole, "\$lang = match (\$raw) {\n    'en' => 'English',\n};\n\$x = ['a' => 'en'];") !== 1);
$enMatches = static function (string $code) use ($fallbackPatterns): bool {
    foreach ($fallbackPatterns as $re) { if (preg_match($re, $code) === 1) { return true; } }
    return false;
};
foreach ($enMustCatch as $code) { mliCheck("the 'en' guard catches: {$code}", $enMatches($code)); }
foreach ($enMustPass as $code) { mliCheck("the 'en' guard does not flag: {$code}", !$enMatches($code)); }
/* …and through $enCodeOf(), as the tree scan reads a line (round 6, finding 4):
   a comment opened and closed on the line no longer hides the code after it;
   a comment still is not code. */
foreach (["var lang = /* default */ song.language || 'en';", "var lang = song.language || /* fallback */ 'en';",
          "/* note */ lang = 'en';"] as $line) {
    $code = $enCodeOf($line);
    mliCheck("the 'en' guard, reading the line as the scan does, catches: {$line}", $code !== null && $enMatches($code), (string)$code);
}
foreach (["/* a doc comment showing lang || 'en' */", " * lang || 'en' inside a block comment", "// lang || 'en'",
          "var lang = song.language || ''; // never lang || 'en'"] as $line) {
    $code = $enCodeOf($line);
    mliCheck("the 'en' guard, reading the line as the scan does, does not flag: {$line}", $code === null || !$enMatches($code), (string)$code);
}

/* The ONE unknown-language fallback (#2137 review): its behaviour, and the
   four paths that save a song's language all calling it. */
mliCheck('mediaLanguageOrUnknown(null) = und', mediaLanguageOrUnknown(null) === 'und');
mliCheck("mediaLanguageOrUnknown('') and a blank value = und", mediaLanguageOrUnknown('') === 'und' && mediaLanguageOrUnknown(" \t") === 'und');
mliCheck('mediaLanguageOrUnknown() leaves a given tag alone (pt-BR stays pt-BR)', mediaLanguageOrUnknown(' pt-BR ') === 'pt-BR');
mliCheck('the fallback is und, never en', IHYMNS_LANGUAGE_UNKNOWN === 'und' && mediaLanguageOrUnknown(null) !== 'en');
foreach ([
    'appWeb/public_html/manage/editor/save_song_core.php',   // the whole-song save (both editors)
    'appWeb/public_html/manage/editor/api2.php',             // the v2 editor's field save and create
    'appWeb/public_html/includes/song_importers.php',        // the bulk importers
    'appWeb/public_html/includes/lyrics_ingest.php',         // the lyrics-ingest API
] as $savePath) {
    mliCheck("{$savePath} gets a missing language from mediaLanguageOrUnknown()",
        str_contains((string)file_get_contents($repoRoot . '/' . $savePath), 'mediaLanguageOrUnknown('));
}
mliCheck("no language value falls back to 'en' (store und, or leave the value out)", $enOffenders === [],
    implode("\n        ", $enOffenders));
echo '  NOTE  known and filed separately (#2134, song requests): ' . ($enKnown === [] ? 'none left' : implode(', ', $enKnown)) . "\n";

/* ---------------------------------------------------------------- (K) --- */
echo "(K) whole-song translations may use regional and script languages (#2131)\n";
mliCheck('schema.sql: no fk_Trans_Lang, no link from TargetLanguage to tblLanguages',
    !str_contains($schemaSql, 'CONSTRAINT fk_Trans_Lang') && !str_contains($schemaSql, 'REFERENCES tblLanguages(Code)'));
mliCheck('schema.sql: the one-translation-per-language rule (uq_Translation) stays',
    str_contains($schemaSql, 'UNIQUE KEY uq_Translation (SourceSongId, TargetLanguage)'));
$fkMigSrc = (string)file_get_contents($repoRoot . '/appWeb/.sql/migrate-drop-song-translations-language-fk.php');
mliCheck('the migration drops the link and its leftover index, each only if present (running it twice is harmless)',
    str_contains($fkMigSrc, 'if (_migDropTransLangFk_fkExists($db))')
    && str_contains($fkMigSrc, "ALTER TABLE tblSongTranslations DROP FOREIGN KEY fk_Trans_Lang")
    && str_contains($fkMigSrc, 'if (_migDropTransLangFk_indexExists($db))')
    && str_contains($fkMigSrc, "ALTER TABLE tblSongTranslations DROP INDEX fk_Trans_Lang"));
mliCheck('the migration is registered, pending while the link OR its index exists',
    str_contains($registrySrc, "'script' => 'migrate-drop-song-translations-language-fk.php'")
    && str_contains($registrySrc, "_migProbe_constraintExists(\$db, 'tblSongTranslations', 'fk_Trans_Lang')")
    && str_contains($registrySrc, "_migProbe_indexExists(\$db, 'tblSongTranslations', 'fk_Trans_Lang')"));
$auditSrc = (string)file_get_contents($inc . '/language_tag_audit.php');
mliCheck('the curator remap tool asks the live schema before insisting on the registry',
    str_contains($auditSrc, 'if (songTranslationsLanguageFkPresent($db)) {'));

/* ---------------------------------------------------------------- (L) --- */
echo "(L) stored order: original first, then the policy's fixed order (#2137)\n";
$rows = array_map(static fn(array $r): array => ['id' => $r[0], 'tag' => $r[1], 'orig' => $r[2] ?? false], [
    ['a', 'fr'], ['b', 'ja', true], ['c', 'en'], ['d', 'es-419'], ['e', 'es-MX'], ['f', 'es'],
    ['g', 'zh-Hant'], ['h', 'zh'], ['i', 'und'], ['j', 'English'], ['k', 'en'], ['l', 'ja-Latn'],
]);
$ids = array_column(mediaLanguageSortStored($rows, 'tag', 'orig'), 'id');
mliCheck('stored order: the original\'s whole group first (ja, then ja-Latn), then by code, general before specific, '
    . 'countries before areas (es-MX before es-419), und after real languages, a malformed value last, ties kept',
    $ids === ['b', 'l', 'c', 'k', 'f', 'e', 'd', 'a', 'h', 'g', 'i', 'j'], implode(',', $ids));
$within = mediaLanguageSortStoredWithin([
    ['lineId' => 7, 'targetLanguage' => 'fr'], ['lineId' => 7, 'targetLanguage' => 'de'],
    ['lineId' => 3, 'targetLanguage' => 'es'], ['lineId' => 3, 'targetLanguage' => 'en'],
], 'lineId', 'targetLanguage');
mliCheck('under each line, translations are in stored order; the lines keep their own order',
    array_map(static fn(array $r): string => $r['lineId'] . ':' . $r['targetLanguage'], $within) === ['7:de', '7:fr', '3:en', '3:es']);
$songDataSrc = (string)file_get_contents($inc . '/SongData.php');
$gstStart = strpos($songDataSrc, 'public function getSongTranslations(');
$gstBody  = $gstStart === false ? '' : substr($songDataSrc, $gstStart, (int)strpos($songDataSrc, "\n    }\n", $gstStart) - $gstStart);
mliCheck('the translation cluster never JOINs tblLanguages for identity (a pt-BR original used to vanish)',
    $gstBody !== '' && !str_contains($gstBody, 'JOIN tblLanguages'));
mliCheck('the translation cluster marks the source as the original and returns stored order',
    str_contains($gstBody, '1 AS is_original') && str_contains($gstBody, "mediaLanguageSortStored(\$rows, 'target_language', 'is_original')"));
mliCheck("a song's translation links are no longer ordered by plain text (ORDER BY TargetLanguage)",
    preg_match('/ORDER BY TargetLanguage\\s*"/', $songDataSrc) !== 1 && str_contains($songDataSrc, "mediaLanguageSortStored(\$translations, 'language')"));
mliCheck('line translations are put in stored order under each line (public read and editor load)',
    str_contains($songDataSrc, "mediaLanguageSortStoredWithin(\$rows, 'lineId', 'targetLanguage')")
    && str_contains((string)file_get_contents($inc . '/line_enrichment.php'), "mediaLanguageSortStoredWithin(\$out['translations'], 'lineId', 'targetLanguage')"));

/* ---------------------------------------------------------------- (M) --- */
echo "(M) names, text direction and the reader's order (#2137)\n";
require_once $inc . '/language_names.php';
$langN   = ['pt' => 'Portuguese', 'zh' => 'Chinese', 'es' => 'Spanish', 'en' => 'English', 'he' => 'Hebrew', 'ca' => 'Catalan', 'und' => 'Unknown language'];
$scriptN = ['hant' => 'Traditional', 'hans' => 'Simplified', 'arab' => 'Arabic', 'latn' => 'Latin'];
$regionN = ['br' => 'Brazil', 'tw' => 'Taiwan', '419' => 'Latin America', 'es' => 'Spain', 'gb' => 'United Kingdom'];
$variantN = ['valencia' => 'Valencian'];
$names = [
    ['pt-BR',          'Portuguese (Brazil)'],
    ['zh-Hant',        'Chinese (Traditional)'],
    ['zh-hans',        'Chinese (Simplified)'],
    ['zh-Hant-TW',     'Chinese (Traditional, Taiwan)'],
    ['es-419',         'Spanish (Latin America)'],
    ['en',             'English'],
    ['iw',             'Hebrew'],
    ['ca-ES-valencia', 'Catalan (Spain, Valencian)'],
    ['xq-GB',          'xq-GB'],
    ['English',        'English'],
    ['x-hymnal',       'x-hymnal'],
];
foreach ($names as [$tag, $want]) {
    $got = languageComposeDisplayName($tag, $langN, $scriptN, $regionN, $variantN);
    mliCheck("name of {$tag} = \"{$want}\"", $got === $want, 'got ' . mliShow($got));
}
mliCheck('pt-BR and pt-PT get DIFFERENT names (they used to both be "Portuguese")',
    languageComposeDisplayName('pt-BR', $langN, $scriptN, $regionN, $variantN)
    !== languageComposeDisplayName('pt-PT', $langN, $scriptN, $regionN, $variantN));
$meta = ['he' => ['name' => 'Hebrew', 'nativeName' => '', 'dir' => 'rtl'], 'ar' => ['name' => 'Arabic', 'nativeName' => '', 'dir' => 'rtl'],
         'pa' => ['name' => 'Punjabi', 'nativeName' => '', 'dir' => 'ltr'], 'az' => ['name' => 'Azerbaijani', 'nativeName' => '', 'dir' => 'ltr']];
foreach ([['pa-Arab', 'rtl'], ['az-Arab', 'rtl'], ['ar-Latn', 'ltr'], ['he', 'rtl'], ['he-IL', 'rtl'], ['en', 'ltr'], ['dv-Thaa', 'rtl'], ['ff-Adlm', 'rtl'], ['sr-Cyrl', 'ltr']] as [$tag, $want]) {
    mliCheck("text direction of {$tag} = {$want} (from the script when there is one)", languageTextDirection($tag, $meta) === $want);
}
$autoMeta = ['sr' => ['nativeName' => 'српски'], 'ur' => ['nativeName' => 'اردو'], 'pt' => ['nativeName' => 'português']];
foreach ([['pt-BR', 'português'], ['pt', 'português'], ['sr-Latn', ''], ['ur-Latn', ''], ['sr-Cyrl-RS', ''], ['x-hymnal', ''], ['', '']] as [$tag, $want]) {
    mliCheck("own-language name beside {$tag} = " . mliShow($want) . ' (none when the tag names its own script)', languageAutonymFor($tag, $autoMeta) === $want);
}
$nameOf = static fn(string $p): string => $langN[$p] ?? ['de' => 'German', 'ja' => 'Japanese', 'fr' => 'French'][$p] ?? $p;
$menu = [['t' => 'de'], ['t' => 'ja', 'o' => true], ['t' => 'fr'], ['t' => 'en'], ['t' => 'zh-Hant'], ['t' => 'zh'], ['t' => 'und'], ['t' => 'en-GB']];
mliCheck("reader's order: their languages first in their order (exact tag first), then the original, then A-Z by name, special codes last",
    array_column(mediaLanguageSortForReader($menu, 't', 'o', ['fr', 'en-GB'], $nameOf), 't') === ['fr', 'en-GB', 'en', 'ja', 'zh', 'zh-Hant', 'de', 'und'],
    implode(',', array_column(mediaLanguageSortForReader($menu, 't', 'o', ['fr', 'en-GB'], $nameOf), 't')));
mliCheck('with no preferences: the original first, then A-Z by name',
    array_column(mediaLanguageSortForReader($menu, 't', 'o', [], $nameOf), 't') === ['ja', 'zh', 'zh-Hant', 'en', 'en-GB', 'fr', 'de', 'und']);
$prefs = parsePreferredLanguageSubtags('PT-br, en, pt-BR, garbage!, x-hymnal');
mliCheck('preferences keep whole, tidied tags in the order given, no repeats (they used to be cut to base codes and sorted)',
    $prefs === ['pt-BR', 'en', 'x-hymnal'], mliShow($prefs));
mliCheck('the old API shape (sorted base codes) is still available for the subtags field',
    preferredLanguageBaseSubtags($prefs) === ['en', 'pt']);
[$pw, $pt, $pv] = applyLanguageFilterSql('s.Language', ['pt-BR', 'x-hymnal']);
mliCheck('the SQL filter matches a pt-BR preference by its group (pt), and a private-use tag as a whole tag, all bound',
    $pv === ['und', 'mul', 'zxx', 'x-hymnal', 'pt', 'und', 'mul', 'zxx', 'x-hymnal', 'pt']
    && str_contains($pw, 'CAST(LOWER(s.Language) AS BINARY) IN (?)') && strlen($pt) === 10, mliShow([$pw, $pv]));
$pred = makeLanguageFilterPredicate(['pt-BR']);
mliCheck('the in-memory filter: a pt-BR preference keeps pt-PT and pt rows, drops es', $pred(['language' => 'pt-PT']) && $pred(['language' => 'pt']) && !$pred(['language' => 'es']));
$tile = songbookTileLanguage(['language' => 'zh-hant', 'languages' => ['zh'], 'languageTags' => ['zh-Hans', 'zh-Hant']]);
mliCheck('a songbook tile shows the WHOLE tag on its badge (ZH-HANT, not ZH), and keeps the filter group',
    $tile['tag'] === 'zh-Hant' && $tile['badge'] === 'ZH-HANT' && $tile['groupsCsv'] === 'zh', mliShow($tile));
mliCheck('a songbook whose language is not known shows no badge', songbookTileLanguage(['language' => 'und'])['badge'] === '');
$songPage = (string)file_get_contents($repoRoot . '/appWeb/public_html/includes/pages/song.php');
mliCheck("the song page orders its translation picker for a reader and tags each item for the browser's reordering",
    str_contains($songPage, "mediaLanguageSortForReader(\$translations, 'target_language', 'is_original', [], 'resolveLanguageName')")
    && str_contains($songPage, 'data-language-tag="'));
mliCheck('the song page picker leads with the name, and marks the language\'s own name as secondary',
    str_contains($songPage, "\$_t['display_label'] = \$_name !== ''") && str_contains($songPage, "\$_t['secondary_label']"));
$apiSrc = (string)file_get_contents($repoRoot . '/appWeb/public_html/api.php');
$stStart = strpos($apiSrc, "case 'song_translations':");
$stBody  = $stStart === false ? '' : substr($apiSrc, $stStart, (int)strpos($apiSrc, "case 'user_access':", $stStart) - $stStart);
mliCheck('the song_translations API never JOINs tblLanguages for identity, and orders for the reader',
    $stBody !== '' && !str_contains($stBody, 'JOIN tblLanguages') && str_contains($stBody, 'mediaLanguageSortForReader('));
mliCheck('user_preferred_languages keeps `subtags` (base codes) and adds `languages` (whole tags, in order)',
    str_contains($apiSrc, "'subtags'   => preferredLanguageBaseSubtags(\$languages),") && str_contains($apiSrc, "'languages' => \$languages,"));

/* ---------------------------------------------------------------- (N) --- */
echo "(N) the #2137 review's smaller fixes\n";
foreach (['en' => true, 'pt-BR' => true, 'zh-Hant' => true, 'iw' => true, 'qua' => true,
          'und' => false, 'mul' => false, 'zxx' => false, 'mis' => false, 'qaa' => false, 'qtz' => false,
          'x-hymnal' => false, 'i-default' => false, 'English' => false, '' => false] as $tag => $want) {
    mliCheck("mediaLanguageIsOrdinaryLanguage(" . mliShow($tag) . ') = ' . mliShow($want), mediaLanguageIsOrdinaryLanguage($tag) === $want);
}
$songPage = (string)file_get_contents($repoRoot . '/appWeb/public_html/includes/pages/song.php');
mliCheck('the song page writes hreflang only for a real language (never hreflang="und")',
    str_contains($songPage, "\$_t['hreflang']        = mediaLanguageIsOrdinaryLanguage(")
    && str_contains($songPage, 'hreflang="<?= htmlspecialchars($t[\'hreflang\']) ?>"')
    && !str_contains($songPage, 'hreflang="<?= htmlspecialchars($t[\'target_language\']) ?>"'));
$indexSrc2 = (string)file_get_contents($repoRoot . '/appWeb/public_html/index.php');
mliCheck("the page head's hreflang alternates use the same test", substr_count($indexSrc2, 'mediaLanguageIsOrdinaryLanguage(') === 2);
$namesSrc = (string)file_get_contents($repoRoot . '/appWeb/public_html/includes/language_names.php');
mliCheck('getLanguageNamesMap() builds its map once per request (a static cache), not once per name shown',
    preg_match('/function getLanguageNamesMap\(\): array\s*\{\s*static \$names = null;\s*if \(\$names !== null\) \{\s*return \$names;/', $namesSrc) === 1);

echo "\n  {$passed} passed, " . count($failures) . " failed\n";
if ($failures !== []) {
    fwrite(STDERR, "FAIL: iHymns' use of the shared language policy (#2137):\n  - " . implode("\n  - ", $failures) . "\n");
    exit(1);
}
exit(0);
