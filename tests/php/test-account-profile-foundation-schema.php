<?php

declare(strict_types=1);

/**
 * iHymns - the account/profile foundation migration matches schema.sql exactly (B1, epic #2180)
 *
 * ELI5
 * ----
 * A fresh install reads schema.sql; a long-running install is changed by the
 * migration. They must end up identical, down to the wording of every column
 * comment. The existing schema-coverage guard only checks that the NAMES are
 * present. This one compares the actual definitions, and also checks that the
 * migration card's "is it still pending?" check looks at every single object
 * the migration creates, so a half-finished run can never show as done.
 *
 * Detailed (rule #34): nothing is typed here. Every step is read out of the
 * migration file's own heredocs (CREATE TABLE / ALTER ... ADD COLUMN / ADD KEY),
 * then matched against the table's block in schema.sql after whitespace is
 * collapsed. The registry check derives the same object list and requires each
 * to be named in the registry entry's probe. Mutation proofs are recorded in the
 * B1 commit: changing one word of a COMMENT in schema.sql, deleting a column
 * from schema.sql, or dropping one object from the probe each turns this red.
 *
 *   php tests/php/test-account-profile-foundation-schema.php
 */

$root = dirname(__DIR__, 2) . '/appWeb';
$fail = 0;
function chk(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . ($cond || $detail === '' ? '' : " - $detail") . "\n";
    if (!$cond) { $fail++; }
}
/** Collapse every run of whitespace to one space so alignment never matters. */
function norm(string $s): string { return trim((string)preg_replace('/\s+/', ' ', $s)); }

$mig = (string)file_get_contents($root . '/.sql/migrate-account-profile-foundation.php');
$schema = (string)file_get_contents($root . '/.sql/schema.sql');
$registry = (string)file_get_contents($root . '/public_html/manage/includes/migration-registry.php');

/* ---- derive every step from the migration's own heredocs ---- */
preg_match_all("/\\[\\s*'(table|column|index)',\\s*'(tbl\\w+)',\\s*(null|'[^']+'),\\s*<<<'SQL'\\n(.*?)\\nSQL\\s*\\]/s", $mig, $m, PREG_SET_ORDER);
$steps = [];
foreach ($m as $x) {
    $steps[] = ['kind' => $x[1], 'table' => $x[2], 'name' => $x[3] === 'null' ? null : trim($x[3], "'"), 'sql' => $x[4]];
}
chk('the migration has steps to check (' . count($steps) . ')', count($steps) >= 10);
chk('the migration declares three tables', count(array_filter($steps, fn($s) => $s['kind'] === 'table')) === 3);

/** The schema.sql block for a table, whitespace-collapsed. */
function schemaBlock(string $schema, string $table): ?string
{
    if (preg_match('/CREATE TABLE IF NOT EXISTS ' . preg_quote($table, '/') . ' \((.*?)\n\) ENGINE[^;]*;/s', $schema, $b)) {
        return norm($b[0]);
    }
    return null;
}

foreach ($steps as $s) {
    $label = $s['kind'] === 'table' ? $s['table'] : $s['table'] . '.' . $s['name'];
    $block = schemaBlock($schema, $s['table']);
    if ($block === null) { chk("$label: the table exists in schema.sql", false); continue; }
    if ($s['kind'] === 'table') {
        $want = norm(str_replace('CREATE TABLE ', 'CREATE TABLE IF NOT EXISTS ', $s['sql'])) . ';';
        chk("$label: the CREATE TABLE in schema.sql is identical to the migration's (comments included)", $block === $want);
    } elseif ($s['kind'] === 'column') {
        $def = norm((string)preg_replace('/^ALTER TABLE \w+ ADD COLUMN /', '', $s['sql']));
        chk("$label: the column definition appears in schema.sql exactly as the migration adds it (comment included)", str_contains($block, $def . ','));
    } else {
        $def = norm((string)preg_replace('/^ALTER TABLE \w+ ADD KEY /', 'INDEX ', $s['sql']));
        chk("$label: the index appears in schema.sql exactly as the migration adds it", str_contains($block, $def . ','));
    }
}

/* ---- the card's probe looks at EVERY object ---- */
$entryAt = strpos($registry, "'account-profile-foundation' => [");
chk('the registry has the one entry', $entryAt !== false && substr_count($registry, "migrate-account-profile-foundation.php") === 1);
$entry = $entryAt === false ? '' : substr($registry, $entryAt);
$probeAt = strpos($entry, "'probe'");
$probe = $probeAt === false ? '' : substr($entry, $probeAt);
chk("the probe is not `=> true`", $probe !== '' && !preg_match('/static\s+fn\s*\([^)]*\)[^=]*=>\s*true\b/', $probe));
foreach ($steps as $s) {
    $label = $s['kind'] === 'table' ? $s['table'] : $s['table'] . '.' . $s['name'];
    $call = match ($s['kind']) {
        'table'  => "_migProbe_tableExists(\$db, '{$s['table']}')",
        'column' => "_migProbe_columnExists(\$db, '{$s['table']}', '{$s['name']}')",
        'index'  => "_migProbe_indexExists(\$db, '{$s['table']}', '{$s['name']}')",
    };
    chk("$label: the probe checks it (so a partial run shows pending)", str_contains($probe, $call));
}
chk('the migration is NOT manual (it is additive and safe in "Apply all")', !preg_match("/'manual'\\s*=>\\s*true/", substr($entry, 0, (int)$probeAt)));

/* ---- every added column carries its doctag ---- */
foreach ($steps as $s) {
    if ($s['kind'] !== 'column') { continue; }
    chk("{$s['table']}.{$s['name']}: has an @migration-adds tag", str_contains($mig, "@migration-adds {$s['table']}.{$s['name']}"));
}

/* ---- rule #41: no hard-coded docroot name in an include ---- */
$code = '';
foreach (token_get_all($mig) as $t) {
    if (is_array($t)) { $code .= in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]; } else { $code .= $t; }
}
chk("no literal '/public_html/' include path in the migration code", !preg_match("#(require|include)(_once)?[^;]*public_html#", $code));

exit($fail === 0 ? 0 : 1);
