<?php

declare(strict_types=1);

/**
 * iHymns — how iHymns USES the shared language policy (#2137)
 *
 * ELI5: the policy's own 268 examples are checked by
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
 *     appWeb/private_html/lib/media-language/ and loads them.
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
 *     request feature filed separately, is reported, not failed).
 * (K) #2131 — whole-song translations may use regional and script tags:
 *     schema.sql has no fk_Trans_Lang (uq_Translation stays), the migration
 *     drops the link and its leftover index only if present, it is
 *     registered with an OR-probe, and the remap tool asks the live schema.
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
mliCheck('the shared code folder is outside the public web folder',
    str_ends_with(str_replace('\\', '/', mediaLanguageLibraryDir()), 'appWeb/private_html/lib/media-language'),
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
mliCheck('the SQL language filter lets und / mul / zxx through, all bound',
    $fVals === ['en', 'und', 'mul', 'zxx'] && $fTypes === 'ssss' && substr_count($fWhere, '?') === 4, mliShow([$fWhere, $fVals]));
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
$fallbackPatterns = [
    '/(?:language|lang)\b[^\n]{0,60}(?:\?\?|\|\||\?:)\s*[\'"]en[\'"]/i',   // x.language ?? 'en', lang || 'en'
    '/[\'"]language[\'"]\s*=>\s*[\'"]en[\'"]/',                                // 'language' => 'en'
];
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
        /* Comment-stripped (like this repo's other source guards): a doc
           comment may SHOW an example shape such as 'language' => 'en'
           without it being code. Whole-line comments are skipped; a trailing
           block or line comment is cut off before matching. */
        if (preg_match('~^\\s*(?:\\*|//|/\\*|#)~', $line) === 1) {
            continue;
        }
        $code = (string)preg_replace('~\\s(?://|/\\*).*$~', '', $line);
        foreach ($fallbackPatterns as $re) {
            if (preg_match($re, $code) === 1) {
                $where = substr($path, strlen(str_replace('\\', '/', $repoRoot)) + 1) . ':' . ($i + 1);
                if (str_contains($line, '#2134')) {
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

echo "\n  {$passed} passed, " . count($failures) . " failed\n";
if ($failures !== []) {
    fwrite(STDERR, "FAIL: iHymns' use of the shared language policy (#2137):\n  - " . implode("\n  - ", $failures) . "\n");
    exit(1);
}
exit(0);
