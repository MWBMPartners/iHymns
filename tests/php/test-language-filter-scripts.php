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
 *
 * Mutation-proven: dropping the per-script clause from the SQL turned every
 * script case in Part B red; comparing base languages only in the predicate
 * (the old rule) turned the script cases in Part A red.
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

/* #2137 second review — stored values NOT in standard form. The in-memory
   filter reads each with the shared rule (`iw` means `he`, `zh-yue` means
   `yue`, not `zh`); the SQL filter must keep the same rows (checked in Part
   B). These are the reviewer's rows and preference lists. */
$nsRows = ['', 'und', 'mul', 'zxx', 'iw', 'he', 'in', 'id', 'zh', 'zh-yue', 'yue', 'zh-cmn-Hans', 'cmn-Hans', 'zh-Hans', 'zh-Hant',
    'ZH-HANT', 'zh-hant-tw', 'i-klingon', 'tlh', 'sgn-BR', 'bzs', 'mis', 'mis-Latn', 'und-Latn', 'zxx-Latn', 'mul-Latn', 'qaa', 'qaa-GB',
    'x-hymnal', 'X-HYMNAL', 'en-x-hymnal', 'en-u-ca-gregory', 'sr-Latn-RS', 'sr-Cyrl', 'sr', 'de-1996', 'de-Latn-1996', 'de',
    'en-GB-oed', 'i-default', 'art-lojban', 'jbo', 'zh-min-nan', 'nan', 'English', 'en_GB', ' en', 'en ', 'pt-BR-x-foo', 'en', 'en-GB',
    'sr-Latn-x-cyrl', 'zh-Hans-x-hant', 'ar-ajp', 'apc'];
$nsPrefs = ['he', 'iw', 'id', 'zh', 'zh-Hans', 'zh-Hant', 'yue', 'tlh', 'mis', 'qaa', 'sr-Latn', 'en', 'en-GB', 'x-hymnal', 'i-default',
    'jbo', 'nan', 'de-Latn', 'bzs', 'apc', 'zh-Hans, sr-Cyrl', 'und', 'en-x-hymnal', 'zh-Hant-TW'];
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
    } finally {
        $db->query("DROP DATABASE IF EXISTS `{$name}`");
    }
}

echo "\n  {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
