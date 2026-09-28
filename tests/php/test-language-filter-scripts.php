<?php

declare(strict_types=1);

/**
 * iHymns — the language filter respects scripts (#2137 review, MATCH-040)
 *
 * ELI5: a reader who chose "Chinese (Simplified)" should see Chinese songs,
 * but not the ones written in Traditional characters — a reader of one
 * script may not be able to read the other. The filter used to compare only
 * the first part of the tag (`zh`), so it kept both. This test proves the
 * in-memory filter and the SQL filter now both drop a different script, keep
 * every form with no script or the same script, and agree with each other,
 * row for row, against a real database.
 *
 * CHECKS
 *  Part A (no database): makeLanguageFilterPredicate() — which uses the
 *    shared Policy::matchTags() — on a truth table of 22 stored tags (one of
 *    them malformed) and nine preference lists, including `zh-Hans`,
 *    `sr-Latn`, a pair of scripts, a preference with no script, a
 *    private-use tag, a list holding only a malformed value (no filter at
 *    all, core revision 4) and one malformed value among good ones (it
 *    matches nothing).
 *  Part B (a real database; skipped, loudly, without one):
 *    applyLanguageFilterSql() over the same 22 rows keeps exactly the rows
 *    the predicate keeps, for every preference list.
 *  #2137 review round 4, both parts: values the column's collation used to
 *    bend (`én`, full-width `ｅｎ`, `ünd`, no-break / zero-width spaces, a
 *    byte-order mark, `İW`, the Kelvin sign, tabs, line breaks, a vertical
 *    tab, NUL, a space inside the first part) — the SQL keeps exactly what
 *    the in-memory filter keeps; the only remaining difference (malformed
 *    values that begin like a matching one) is pinned so a new one turns it
 *    red; `zh-yue-HK` and `zh-hak` (the review's F2 and F4); and a
 *    2,000-entry preference list is cut to its first 32, in order.
 *
 * Mutation-proven: dropping the per-script clause from the SQL turned every
 * script case in Part B red; comparing base languages only in the predicate
 * (the old rule) turned the script cases in Part A red. Round 4, on MariaDB
 * 11.8 and MySQL 8.4: comparing by collation instead of bytes, dropping the
 * ASCII conversion, going back to TRIM(), deciding "untagged" by the
 * collation, an anchored "clean" test, no exact path at all, PHP's bare
 * trim() in the predicate, no extlang exclusion (F2), extlang forms exact
 * only (F4), and removing either half of the 32 cap (or keeping the last 32)
 * each turned checks red.
 *
 * Database: IHYMNS_TEST_DSN="host=127.0.0.1;port=3306;user=root;pass=".
 *
 *   php tests/php/test-language-filter-scripts.php
 */

$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/appWeb/public_html/includes/media_language.php';
require_once $repoRoot . '/appWeb/public_html/includes/language_filter.php';

$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    if ($ok) { $passed++; echo "  PASS  {$label}\n"; }
    else     { $failed++; echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
};
echo "tests/php/test-language-filter-scripts.php — a script preference drops songs in another script\n";
$check('the shared language rules loaded', mediaLanguageReady());

$rows = ['', 'und', 'mul', 'zxx', 'zh', 'zh-Hans', 'zh-Hant', 'zh-Hans-CN', 'zh-Hant-TW', 'zh-TW',
         'sr', 'sr-Latn', 'sr-Cyrl', 'sr-Cyrl-RS', 'en', 'en-GB', 'pt', 'pt-BR', 'pt-PT', 'x-hymnal', 'yue-Hant',
         'English'];   // a malformed stored value: matches nothing (MATCH-010)
$special = ['', 'und', 'mul', 'zxx'];
$cases = [
    'zh-Hans'          => array_merge($special, ['zh', 'zh-Hans', 'zh-Hans-CN', 'zh-TW']),
    'sr-Latn'          => array_merge($special, ['sr', 'sr-Latn']),
    'zh-Hans, zh-Hant' => array_merge($special, ['zh', 'zh-Hans', 'zh-Hant', 'zh-Hans-CN', 'zh-Hant-TW', 'zh-TW']),
    'zh-Hans, zh'      => array_merge($special, ['zh', 'zh-Hans', 'zh-Hant', 'zh-Hans-CN', 'zh-Hant-TW', 'zh-TW']),
    'zh-TW'            => array_merge($special, ['zh', 'zh-Hans', 'zh-Hant', 'zh-Hans-CN', 'zh-Hant-TW', 'zh-TW']),
    'pt-BR'            => array_merge($special, ['pt', 'pt-BR', 'pt-PT']),
    'x-hymnal'         => array_merge($special, ['x-hymnal']),
];
/* Core revision 4 (aaaa585): a malformed preference matches nothing — not
   even the identical malformed value — and a list holding ONLY malformed
   preferences counts as none, so nothing is filtered. These go straight to
   the builders (the request path drops malformed values earlier, in
   parsePreferredLanguageSubtags()), because the builders must honour the
   rule for any caller. */
$rawCases = [
    'English (only a malformed preference)' => [['English'], $rows],
    'pt, English (one malformed)'           => [['pt', 'English'], array_merge($special, ['pt', 'pt-BR', 'pt-PT'])],
];

echo "\nPart A — the in-memory filter\n";
$predicateKeeps = [];
foreach ($cases as $csv => $expected) {
    $prefs = parsePreferredLanguageSubtags($csv);
    $pred = makeLanguageFilterPredicate($prefs);
    $kept = array_values(array_filter($rows, static fn(string $t): bool => $pred(['language' => $t])));
    $predicateKeeps[$csv] = $kept;
    $check("preferences \"{$csv}\" keep exactly: " . implode(', ', array_map(static fn($t) => $t === '' ? '(none)' : $t, $expected)),
        $kept === $expected, 'kept ' . implode(', ', $kept));
}

foreach ($rawCases as $label => [$prefs, $expected]) {
    $pred = makeLanguageFilterPredicate($prefs);
    $kept = array_values(array_filter($rows, static fn(string $t): bool => $pred(['language' => $t])));
    $predicateKeeps[$label] = $kept;
    $check("preferences {$label}: " . ($expected === $rows ? 'nothing is filtered' : 'the malformed one matches nothing, not even "English"'),
        $kept === $expected, 'kept ' . implode(', ', $kept));
}
[$noneWhere] = applyLanguageFilterSql('Language', ['English']);
$check('the SQL builder, given only a malformed preference, applies no filter at all', $noneWhere === ' AND 1=1', $noneWhere);
$check('the request path drops malformed preferences before either builder sees them',
    parsePreferredLanguageSubtags('English, pt_BR, !!') === [] && parsePreferredLanguageSubtags('English, pt') === ['pt']);

/* #2137 review round 4 — a long preference list is cut to its first 32, in
   order: 2,000 real language codes from the IANA registry file. */
preg_match_all('/Type: language\nSubtag: ([a-z]{2,3})\n/',
    (string)file_get_contents($repoRoot . '/appWeb/.sql/data/iana-language-subtag-registry.txt'), $regMatch);
/* Only codes already in standard form (a retired code such as `aam` is
   tidied to its replacement, which would muddy "the first 32, in order"). */
$codes2000 = array_slice(array_values(array_filter(array_unique($regMatch[1]),
    static fn(string $c): bool => mediaLanguageTagForStorage($c) === $c)), 0, 2000);
$first32 = array_slice($codes2000, 0, 32);
$check('the test has 2,000 distinct real language codes to work with', count($codes2000) === 2000);
$check('the parser keeps the first 32 of 2,000 preferences, in order',
    parsePreferredLanguageSubtags(implode(',', $codes2000)) === $first32);
$mixed = [];
foreach ($codes2000 as $i => $c) { $mixed[] = $c; if ($i % 3 === 0) { $mixed[] = 'English'; $mixed[] = strtoupper($c); } }
$check('…counting only usable, distinct ones (malformed and repeated entries in between do not use up the 32)',
    parsePreferredLanguageSubtags(implode(',', $mixed)) === $first32);
$_SERVER['HTTP_X_PREFERRED_LANGUAGES'] = implode(',', $codes2000);
unset($_GET['lang']);
$check('a 2,000-entry X-Preferred-Languages header resolves to the same first 32',
    resolvePreferredLanguagesForRequest(null) === $first32);
unset($_SERVER['HTTP_X_PREFERRED_LANGUAGES']);
$check('the SQL filter for 2,000 preferences is exactly the SQL for the first 32 (parsed)',
    applyLanguageFilterSql('Language', parsePreferredLanguageSubtags(implode(',', $codes2000)))
        === applyLanguageFilterSql('Language', $first32));
$check('…and for 2,000 preferences handed straight to the builder, skipping the parser',
    applyLanguageFilterSql('Language', $codes2000) === applyLanguageFilterSql('Language', $first32));
$pred2000 = makeLanguageFilterPredicate($codes2000);
$pred32 = makeLanguageFilterPredicate($first32);
$probeRows = array_merge($rows, array_slice($codes2000, 28, 8));   /* four codes inside the 32, four after it */
$check('the in-memory filter for 2,000 preferences keeps exactly what it keeps for the first 32',
    array_map(static fn(string $t): bool => $pred2000(['language' => $t]), $probeRows)
        === array_map(static fn(string $t): bool => $pred32(['language' => $t]), $probeRows)
    && $pred2000(['language' => $codes2000[31]]) && !$pred2000(['language' => $codes2000[32]]));

/* #2137 second review — stored values NOT in standard form. The in-memory
   filter reads each with the shared rule (`iw` means `he`, `zh-yue` means
   `yue`, not `zh`); the SQL filter must keep the same rows (checked in Part
   B). These are the reviewer's rows and preference lists. */
$nsRows = ['', 'und', 'mul', 'zxx', 'iw', 'he', 'in', 'id', 'zh', 'zh-yue', 'yue', 'zh-cmn-Hans', 'cmn-Hans', 'zh-Hans', 'zh-Hant',
    'ZH-HANT', 'zh-hant-tw', 'i-klingon', 'tlh', 'sgn-BR', 'bzs', 'mis', 'mis-Latn', 'und-Latn', 'zxx-Latn', 'mul-Latn', 'qaa', 'qaa-GB',
    'x-hymnal', 'X-HYMNAL', 'en-x-hymnal', 'en-u-ca-gregory', 'sr-Latn-RS', 'sr-Cyrl', 'sr', 'de-1996', 'de-Latn-1996', 'de',
    'en-GB-oed', 'i-default', 'art-lojban', 'jbo', 'zh-min-nan', 'nan', 'English', 'en_GB', ' en', 'en ', 'pt-BR-x-foo', 'en', 'en-GB',
    'sr-Latn-x-cyrl', 'zh-Hans-x-hant', 'ar-ajp', 'apc',
    /* round 4 (items F2/F4 of the third review): an extlang form FOLLOWED by
       more parts (`zh-yue-HK` is Cantonese in Hong Kong, not Chinese `zh`),
       in odd letter case, and a second extlang (`zh-hak` is Hakka) */
    'zh-yue-HK', 'ZH-Yue-hk', 'zh-hak', 'hak'];
$nsPrefs = ['he', 'iw', 'id', 'zh', 'zh-Hans', 'zh-Hant', 'yue', 'tlh', 'mis', 'qaa', 'sr-Latn', 'en', 'en-GB', 'x-hymnal', 'i-default',
    'jbo', 'nan', 'de-Latn', 'bzs', 'apc', 'zh-Hans, sr-Cyrl', 'und', 'en-x-hymnal', 'zh-Hant-TW', 'hak', 'yue-HK'];
$nsKeeps = [];
foreach ($nsPrefs as $csv) {
    $pred = makeLanguageFilterPredicate(parsePreferredLanguageSubtags($csv));
    $nsKeeps[$csv] = array_values(array_filter($nsRows, static fn(string $t): bool => $pred(['language' => $t])));
}
$has = static fn(string $csv, string $row): bool => in_array($row, $nsKeeps[$csv], true);
$check('in memory: a `he` preference keeps a row stored as the retired `iw`, and `id` keeps `in`',
    $has('he', 'iw') && $has('id', 'in'));
$check('in memory: a `zh` preference does NOT keep `zh-yue`, `zh-cmn-Hans` or `zh-min-nan` (they are Cantonese, Mandarin, Min Nan)',
    !$has('zh', 'zh-yue') && !$has('zh', 'zh-cmn-Hans') && !$has('zh', 'zh-min-nan') && $has('zh', 'zh-Hant'));
$check('in memory: `yue` keeps `zh-yue`, `tlh` keeps `i-klingon`, `jbo` keeps `art-lojban`, `bzs` keeps `sgn-BR`, `apc` keeps `ar-ajp`',
    $has('yue', 'zh-yue') && $has('tlh', 'i-klingon') && $has('jbo', 'art-lojban') && $has('bzs', 'sgn-BR') && $has('apc', 'ar-ajp'));
$check('in memory: `mis` as a preference matches `mis` exactly, not `mis-Latn`', $has('mis', 'mis') && !$has('mis', 'mis-Latn'));
$check('in memory: a stored value with a leading or trailing space still matches (` en`, `en `)', $has('en', ' en') && $has('en', 'en '));
$check('in memory: `zh` does NOT keep `zh-yue-HK` or `zh-hak`; `yue` keeps `zh-yue-HK` in any letter case; `hak` keeps `zh-hak`',
    !$has('zh', 'zh-yue-HK') && !$has('zh', 'zh-hak') && $has('yue', 'zh-yue-HK') && $has('yue', 'ZH-Yue-hk') && $has('hak', 'zh-hak'));

/* #2137 review round 4 — values the database's collation used to bend. The
   column's collation (utf8mb4_unicode_ci) ignores accents, letter width and
   some characters entirely, and MySQL's TRIM() removes spaces only; the
   shared rule reads each of these as it is, trimming exactly space, tab, CR
   and LF. Part B checks the SQL keeps exactly what this keeps. */
$r4Rows = ['en', 'EN', 'en-GB', 'und', 'he', 'iw', 'zh', 'zh-Hans', 'zh-Hant', 'yue', 'zh-yue', 'sr-Latn', 'sr-Cyrl', 'x-hymnal',
    'én', 'ｅｎ', "en\u{00A0}", "en\u{200B}", "e\u{0301}n", 'ünd', 'ÜND', "\u{00A0}", "\u{200B}", "\u{FEFF}en",
    "en\t", "en\n", "\ten", "\nen", "\r\nen\r\n", " \t en \n ", "\t", "\n", " \t\r\n ", "zh\t", "zh-Hant\t", "\tzh-Hant",
    "en\x0B", "\x0Ben", "en\x00", 'İW', "\u{212A}O", 'und -x', "und\t-x", "und\n-x", "en\tGB", 'und-é', 'UND-x'];
$r4Prefs = ['en', 'en-GB', 'und', 'he', 'iw', 'zh', 'zh-Hans', 'yue', 'sr-Latn', 'x-hymnal', 'ko', 'mul'];
$r4Keeps = [];
foreach ($r4Prefs as $csv) {
    $pred = makeLanguageFilterPredicate(parsePreferredLanguageSubtags($csv));
    $r4Keeps[$csv] = array_keys(array_filter($r4Rows, static fn(string $t): bool => $pred(['language' => $t])));
}
$r4Has = static fn(string $csv, string $row): bool => in_array(array_search($row, $r4Rows, true), $r4Keeps[$csv], true);
$check('in memory: the rule\'s four trim characters are ignored at either end (`en` + tab or line feed, tab + `en`, CR LF around `en`)',
    $r4Has('en', "en\t") && $r4Has('en', "en\n") && $r4Has('en', "\ten") && $r4Has('en', "\r\nen\r\n") && $r4Has('en', " \t en \n "));
$check('in memory: a value of only tabs or line breaks is untagged (always shown)', $r4Has('ko', "\t") && $r4Has('ko', "\n") && $r4Has('ko', " \t\r\n "));
$check('in memory: nothing else is ignored — `én`, full-width `ｅｎ`, a no-break / zero-width space, a vertical tab or NUL next to `en` match nothing',
    !$r4Has('en', 'én') && !$r4Has('en', 'ｅｎ') && !$r4Has('en', "en\u{00A0}") && !$r4Has('en', "en\u{200B}")
    && !$r4Has('en', "en\x0B") && !$r4Has('en', "\x0Ben") && !$r4Has('en', "en\x00"));
$check('in memory: `ünd` and a value of only a no-break or zero-width space are NOT shown to everyone',
    !$r4Has('ko', 'ünd') && !$r4Has('ko', "\u{00A0}") && !$r4Has('ko', "\u{200B}"));
$check('in memory: `İW` is not the Hebrew alias `iw`', !$r4Has('he', 'İW'));

echo "\nPart B — the SQL filter, against a real database\n";
$dsn = getenv('IHYMNS_TEST_DSN') ?: '';
$host = '127.0.0.1'; $port = 3306; $user = 'root'; $pass = '';
foreach (explode(';', $dsn) as $kv) {
    [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
    if ($k === 'host') { $host = $v; }
    if ($k === 'port') { $port = (int)$v; }
    if ($k === 'user') { $user = $v; }
    if ($k === 'pass') { $pass = $v; }
}
$db = null;
if ($dsn !== '') {
    try {
        mysqli_report(MYSQLI_REPORT_OFF);
        $db = @new mysqli($host, $user, $pass, '', $port);
        if ($db->connect_errno) { $db = null; }
    } catch (\Throwable $e) {
        $db = null;
    }
}
if ($db === null) {
    echo "  SKIP  no database — Part B did NOT run. Set IHYMNS_TEST_DSN; this is a gap, not a pass.\n";
} else {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $name = 'ihymns_t2137_filter';
    $db->query("DROP DATABASE IF EXISTS `{$name}`");
    $db->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->select_db($name);
    try {
        $db->query('CREATE TABLE t (Id INT NOT NULL PRIMARY KEY, Language VARCHAR(35) NULL)');
        $ins = $db->prepare('INSERT INTO t (Id, Language) VALUES (?, ?)');
        foreach ($rows as $i => $tag) { $ins->bind_param('is', $i, $tag); $ins->execute(); }
        $ins->close();
        $sqlCases = [];
        foreach ($cases as $csv => $_expected) { $sqlCases[$csv] = parsePreferredLanguageSubtags($csv); }
        foreach ($rawCases as $label => [$prefs, $_expected]) { $sqlCases[$label] = $prefs; }
        foreach ($sqlCases as $csv => $prefs) {
            [$where, $types, $values] = applyLanguageFilterSql('Language', $prefs);
            $stmt = $db->prepare('SELECT Language FROM t WHERE 1=1' . $where . ' ORDER BY Id');
            if ($values !== []) { $stmt->bind_param($types, ...$values); }
            $stmt->execute();
            $got = array_map(static fn(array $r): string => (string)$r[0], $stmt->get_result()->fetch_all());
            $stmt->close();
            $check("SQL with \"{$csv}\" keeps the same rows as the in-memory filter",
                $got === $predicateKeeps[$csv], 'SQL kept ' . implode(', ', $got));
        }
        /* The non-standard rows: SQL keeps exactly what the shared rule keeps. */
        $db->query('CREATE TABLE ns (Id INT NOT NULL PRIMARY KEY, Language VARCHAR(35) NULL) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $ins = $db->prepare('INSERT INTO ns (Id, Language) VALUES (?, ?)');
        foreach ($nsRows as $i => $tag) { $ins->bind_param('is', $i, $tag); $ins->execute(); }
        $ins->close();
        $nsDiffs = [];
        foreach ($nsPrefs as $csv) {
            [$where, $types, $values] = applyLanguageFilterSql('Language', parsePreferredLanguageSubtags($csv));
            $stmt = $db->prepare('SELECT Language FROM ns WHERE 1=1' . $where . ' ORDER BY Id');
            if ($values !== []) { $stmt->bind_param($types, ...$values); }
            $stmt->execute();
            $got = array_map(static fn(array $r): string => (string)$r[0], $stmt->get_result()->fetch_all());
            $stmt->close();
            if ($got !== $nsKeeps[$csv]) {
                $nsDiffs[] = "'{$csv}': SQL-only [" . implode(', ', array_diff($got, $nsKeeps[$csv])) . '] memory-only ['
                    . implode(', ', array_diff($nsKeeps[$csv], $got)) . ']';
            }
        }
        $check('SQL keeps the same rows as the in-memory filter for all ' . count($nsPrefs) . ' preference lists over '
            . count($nsRows) . ' stored values not in standard form (retired, extlang, grandfathered, redundant, cased, spaced)',
            $nsDiffs === [], implode('; ', $nsDiffs));

        /* #2137 review round 4 — the values the collation used to bend, on a
           column with the same collation as the live tables. */
        $db->query('CREATE TABLE r4 (Id INT NOT NULL PRIMARY KEY, Language VARCHAR(35) NULL) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $ins = $db->prepare('INSERT INTO r4 (Id, Language) VALUES (?, ?)');
        foreach ($r4Rows as $i => $tag) { $ins->bind_param('is', $i, $tag); $ins->execute(); }
        $ins->close();
        $sqlKeeps = static function (string $table, array $prefs) use ($db): array {
            [$where, $types, $values] = applyLanguageFilterSql('Language', $prefs);
            $stmt = $db->prepare("SELECT Id FROM {$table} WHERE 1=1" . $where . ' ORDER BY Id');
            if ($values !== []) { $stmt->bind_param($types, ...$values); }
            $stmt->execute();
            $ids = array_map(static fn(array $r): int => (int)$r[0], $stmt->get_result()->fetch_all());
            $stmt->close();
            return $ids;
        };
        $r4Diffs = [];
        foreach ($r4Prefs as $csv) {
            $got = $sqlKeeps('r4', parsePreferredLanguageSubtags($csv));
            if ($got !== $r4Keeps[$csv]) {
                $show = static fn(array $ids): string => implode(', ', array_map(
                    static fn(int $i): string => json_encode($r4Rows[$i], JSON_UNESCAPED_UNICODE), $ids));
                $r4Diffs[] = "'{$csv}': SQL-only [" . $show(array_values(array_diff($got, $r4Keeps[$csv]))) . '] memory-only ['
                    . $show(array_values(array_diff($r4Keeps[$csv], $got))) . ']';
            }
        }
        $check('SQL keeps exactly what the in-memory filter keeps for ' . count($r4Prefs) . ' preference lists over ' . count($r4Rows)
            . ' values the collation used to bend (accents, full width, no-break and zero-width spaces, a byte-order mark, tabs,'
            . ' line breaks, vertical tab, NUL, `İ`, the Kelvin sign, a space inside the first part)',
            $r4Diffs === [], implode('; ', $r4Diffs));

        /* What still differs, pinned so the notes in language_filter.php and
           DEV_NOTES stay true: a MALFORMED value that begins the way a
           matching value begins. Any other difference turns this red. */
        $docRows = ['en-', 'en--GB', 'en-toolongsubtag', 'en-é', 'zh-yue-', 'zh-yue-%', "zh-hant\nx", 'en', 'yue', 'zh'];
        $db->query('CREATE TABLE doc (Id INT NOT NULL PRIMARY KEY, Language VARCHAR(35) NULL) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $ins = $db->prepare('INSERT INTO doc (Id, Language) VALUES (?, ?)');
        foreach ($docRows as $i => $tag) { $ins->bind_param('is', $i, $tag); $ins->execute(); }
        $ins->close();
        $docExpected = ['en' => ['en-', 'en--GB', 'en-toolongsubtag', 'en-é'], 'yue' => ['zh-yue-', 'zh-yue-%'],
            'zh' => ["zh-hant\nx"], 'zh-Hans' => ["zh-hant\nx"], 'he' => []];
        $docGot = [];
        foreach (array_keys($docExpected) as $csv) {
            $pred = makeLanguageFilterPredicate([$csv]);
            $mem = array_keys(array_filter($docRows, static fn(string $t): bool => $pred(['language' => $t])));
            $sqlOnly = array_diff($sqlKeeps('doc', [$csv]), $mem);
            $memOnly = array_diff($mem, $sqlKeeps('doc', [$csv]));
            $docGot[$csv] = $memOnly === [] ? array_values(array_map(static fn(int $i): string => $docRows[$i], $sqlOnly)) : ['memory-only!'];
        }
        $check('the only remaining difference is the documented one: malformed `en-`, `en--GB`, `en-toolongsubtag`, `en-é` for `en`,'
            . ' `zh-yue-`, `zh-yue-%` for `yue`, and `zh-hant` + line feed + `x` for `zh` / `zh-Hans`, kept by SQL only',
            $docGot === $docExpected, json_encode($docGot, JSON_UNESCAPED_UNICODE));
    } finally {
        $db->query("DROP DATABASE IF EXISTS `{$name}`");
    }
}

echo "\n  {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
