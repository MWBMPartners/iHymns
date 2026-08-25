<?php

declare(strict_types=1);

/**
 * iHymns — Unknown/junk language-tag curator audit (BCP 47 registry plan
 * §5, M4)
 *
 * ELI5: finds every language tag actually STORED in the song catalogue
 * (not the registry list — the tags songs/songbooks/lines/translations
 * actually carry) that doesn't look like a real IETF BCP 47 tag, or looks
 * like one but isn't in the registry yet, or IS in the registry but has
 * been retired. Shows a curator a short worklist instead of them stumbling
 * onto a typo'd language tag by accident months later.
 *
 * DETAIL:
 * -------
 * Everything here is DERIVED LIVE, never a new table (rule #44 — a
 * derivable value gets no storage of its own). `languageTagSources()`
 * derives which columns in the CURRENT schema hold a language tag by
 * reading INFORMATION_SCHEMA (never a hand-typed list, rule #34) minus a
 * documented exclusion map; `languageTagAuditScan()` runs one `GROUP BY`
 * per source, batch-resolves every distinct subtag against its registry
 * table in ONE `WHERE Code IN (…)` query per kind (never per-tag), then
 * classifies each distinct tag via `bcp47ClassifyTag()`.
 *
 * Backing `/manage/languages`'s `?view=unknown` panel — see that file for
 * the render + the `remap_tag` POST action (rule #22: the audit CORE lives
 * here; the page is presentation + the write path only).
 *
 * @see appWeb/public_html/manage/languages.php       the ?view=unknown panel + remap_tag action
 * @see appWeb/public_html/includes/language_names.php  bcp47ResolveTable() — the shared table-existence probe this reuses
 * @see appWeb/public_html/includes/song_importers.php  _ietfBcp47Validate() — the ONE grammar check (rule #21)
 * @see appWeb/public_html/includes/lyric_lines_sync.php  lyricLinesWriteComponents() — the ONE line-path write (rule #25)
 * @see .claude/bcp47-language-registry-plan.md §5     the plan this implements
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'language_names.php'; /* bcp47ResolveTable(), IHYMNS_BCP47_SUBTAG_KINDS */

/**
 * Tables/columns that legitimately hold something OTHER than a song-facing
 * language tag, even though their column name matches the derivation
 * pattern (`Language` / `LanguageCode` / `TargetLanguage`) — excluded so
 * `languageTagSources()` doesn't flag the REGISTRY's own columns, or an
 * unrelated column that happens to share a name. Documented, not guessed:
 * each entry names WHY it is excluded. `tblLanguages.Code`/`Name` etc.
 * don't match the derivation pattern at all (wrong column names) so
 * aren't even candidates; the ones below DO match the pattern and must be
 * excluded explicitly.
 *
 * EMPTY TODAY, deliberately kept rather than deleted: every column in the
 * live schema literally named `Language`/`LanguageCode`/`TargetLanguage`
 * (2026-08-25 derivation, `tests/php/test-language-tag-audit.php` check A)
 * genuinely IS a song-facing reference column — even the registry tables
 * themselves don't collide, since `tblLanguages`'s own columns are named
 * `Code`/`Name`, not `Language`. This map exists for the NEXT column that
 * needs excluding (e.g. a future denormalised mirror column that legitimately
 * isn't a curation target) — `test-language-tag-audit.php` check C fails
 * the build the moment an entry here stops matching a real derived column,
 * so a stale exclusion can never sit here unnoticed (the self-cleaning
 * property the orphan-allowlist pattern already established elsewhere in
 * this codebase).
 */
const IHYMNS_LANGUAGE_TAG_SOURCE_EXCLUSIONS = [];

/**
 * Derive every `(table, column)` pair in the LIVE schema that plausibly
 * holds a song-facing language tag, by reading INFORMATION_SCHEMA for
 * columns literally named `Language`, `LanguageCode`, or `TargetLanguage`
 * (rule #34 — derived, never a hand-typed list; a NEW column added later
 * with one of these exact names is picked up automatically, and
 * `tests/php/test-language-tag-audit.php` fails the build until it is
 * classified either as a real source or added to the exclusion map).
 *
 * Each source records how a remap should be applied (BCP 47 registry plan
 * §5.3):
 *   'direct'     — a plain bound `UPDATE <table> SET <col> = ? WHERE <col> = ?`.
 *                   `tblSongTranslations.TargetLanguage` is 'direct' too,
 *                   but the REMAP ACTION (in manage/languages.php) treats
 *                   it specially — see that file — because of its unique
 *                   key + FK; this map only says WHERE the value lives.
 *   'line-path'  — `tblLyricLines.LanguageCode` (and its gated
 *                   `tblSongComponents.LanguagesJson` shadow): NEVER a raw
 *                   UPDATE (rule #25) — remapped via
 *                   `lyricLinesEditableComponents()` /
 *                   `lyricLinesWriteComponents()`, the ONE write path.
 *   'report-only'— user-typed free text (`tblSongRequests.Language`):
 *                   shown with counts, no remap control — rewriting a
 *                   user's own submitted text is not curation.
 *
 * @return list<array{table:string,column:string,label:string,remap:string}>
 */
function languageTagSources(\mysqli $db): array
{
    $rows = [];
    try {
        $res = $db->query(
            "SELECT TABLE_NAME, COLUMN_NAME
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND COLUMN_NAME IN ('Language', 'LanguageCode', 'TargetLanguage')
              ORDER BY TABLE_NAME, COLUMN_NAME"
        );
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $table  = (string)$r['TABLE_NAME'];
                $column = (string)$r['COLUMN_NAME'];
                if (isset(IHYMNS_LANGUAGE_TAG_SOURCE_EXCLUSIONS["{$table}.{$column}"])) {
                    continue;
                }
                $rows[] = [
                    'table'  => $table,
                    'column' => $column,
                    'label'  => "{$table}.{$column}",
                    'remap'  => languageTagSourceRemapKind($table, $column),
                ];
            }
            $res->close();
        }
    } catch (\Throwable $e) {
        error_log('[languageTagSources] ' . $e->getMessage());
    }
    return $rows;
}

/**
 * Which remap KIND a given (table, column) needs — see `languageTagSources()`'s
 * doc-comment for what each kind means. A small, EXPLICIT switch (not
 * derived — "how do I safely rewrite this column" is a judgement call
 * about the schema's write discipline, not something INFORMATION_SCHEMA
 * can answer) — `tests/php/test-language-tag-audit.php` asserts every
 * source `languageTagSources()` derives has a non-default entry here, so
 * a brand-new `*.Language`/`*.LanguageCode` column added later fails the
 * build until a human decides how it should be remapped, rather than
 * silently defaulting to a raw UPDATE that might violate rule #25.
 */
function languageTagSourceRemapKind(string $table, string $column): string
{
    if ($table === 'tblLyricLines' && $column === 'LanguageCode') {
        return 'line-path';
    }
    if ($table === 'tblSongRequests' && $column === 'Language') {
        return 'report-only';
    }
    return 'direct';
}

/**
 * BCP 47 registry plan §5.2 — classify one tag against the live registry.
 * `$registry` is the PRE-RESOLVED, BATCH-fetched map this function never
 * queries the DB for itself (see `languageTagAuditScan()` — one
 * `WHERE Code IN (…)` per KIND, not per tag): `$registry[kind][lowercaseCode]`
 * is `true`/`false` (IsActive) when that code is known, absent when unknown.
 *
 *   'malformed'    — fails `_ietfBcp47Validate()` (grammar).
 *   'unregistered' — grammar OK but >=1 subtag absent from its registry table.
 *   'inactive'     — every subtag known, >=1 with IsActive = 0.
 *   'ok'           — every subtag known and active — excluded from the panel.
 *
 * @param string $tag
 * @param array{language:array<string,bool>,script:array<string,bool>,region:array<string,bool>,variant:array<string,bool>} $registry
 */
function bcp47ClassifyTag(string $tag, array $registry): string
{
    $tag = trim($tag);
    if ($tag === '') {
        return 'ok'; // nothing to classify
    }

    require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_importers.php'; /* _ietfBcp47Validate() */
    if (_ietfBcp47Validate($tag) === false) {
        return 'malformed';
    }

    $d = bcp47DecomposeTag($tag);
    if ($d['lang'] === '') {
        /* Grammar passed but the decomposer couldn't even find a primary
           subtag — defensive; the two should never disagree given they
           share the same `^[a-z]{2,3}` floor, but a mismatch here is
           treated as malformed rather than silently "ok". */
        return 'malformed';
    }

    $anyInactive = false;

    $langKey = strtolower($d['lang']);
    if (!array_key_exists($langKey, $registry['language'])) {
        return 'unregistered';
    }
    if (!$registry['language'][$langKey]) {
        $anyInactive = true;
    }

    if ($d['script'] !== '') {
        $key = strtolower($d['script']);
        if (!array_key_exists($key, $registry['script'])) {
            return 'unregistered';
        }
        if (!$registry['script'][$key]) {
            $anyInactive = true;
        }
    }

    if ($d['region'] !== '') {
        $key = strtolower($d['region']);
        if (!array_key_exists($key, $registry['region'])) {
            return 'unregistered';
        }
        if (!$registry['region'][$key]) {
            $anyInactive = true;
        }
    }

    foreach ($d['variants'] as $v) {
        $key = strtolower($v);
        if (!array_key_exists($key, $registry['variant'])) {
            return 'unregistered';
        }
        if (!$registry['variant'][$key]) {
            $anyInactive = true;
        }
    }

    return $anyInactive ? 'inactive' : 'ok';
}

/**
 * PURE tokeniser — splits a BCP 47 tag into its four subtags. Deliberately
 * a SEPARATE, PHP-side re-implementation of the SAME grammar
 * `js/modules/ietf-language-picker.js`'s `decomposeTag()` implements
 * client-side (there is no PHP/JS code-sharing mechanism in this
 * codebase, rule #35's caveat for a regex/parser that genuinely cannot be
 * shared verbatim across that boundary — see that module's own
 * `isGrammaticallyValidBcp47()` doc-comment for the identical tradeoff).
 * This is a DECOMPOSER (structural parsing for classification), not a
 * second GRAMMAR VALIDATOR — `_ietfBcp47Validate()` in song_importers.php
 * remains the ONE authoritative grammar check every write path enforces;
 * this function is only ever called AFTER that check has already passed.
 *
 * @return array{lang:string,script:string,region:string,variants:list<string>}
 */
function bcp47DecomposeTag(string $tag): array
{
    $parts = preg_split('/-/', trim($tag)) ?: [];
    if (empty($parts) || !preg_match('/^[a-z]{2,3}$/i', $parts[0])) {
        return ['lang' => '', 'script' => '', 'region' => '', 'variants' => []];
    }
    $lang     = strtolower($parts[0]);
    $script   = '';
    $region   = '';
    $variants = [];
    for ($i = 1; $i < count($parts); $i++) {
        $p = $parts[$i];
        if ($script === '' && $region === '' && empty($variants) && preg_match('/^[A-Za-z]{4}$/', $p)) {
            $script = ucfirst(strtolower($p));
        } elseif ($region === '' && empty($variants) && (preg_match('/^[A-Za-z]{2}$/', $p) || preg_match('/^[0-9]{3}$/', $p))) {
            $region = ctype_digit($p) ? $p : strtoupper($p);
        } elseif (preg_match('/^[a-zA-Z0-9]{5,8}$/', $p) || preg_match('/^[0-9][a-zA-Z0-9]{3}$/', $p)) {
            $variants[] = strtolower($p);
        }
    }
    return ['lang' => $lang, 'script' => $script, 'region' => $region, 'variants' => $variants];
}

/**
 * Batch-resolve a set of codes against ONE registry table in a SINGLE
 * `WHERE LOWER(Code) IN (…)` query — the plan's §5.2 "one batched query
 * per table" instruction, never per-tag. Returns lowercase-code =>
 * IsActive; a code absent from the returned array was not found at all.
 *
 * @param list<string> $codes Already-lowercased or mixed-case; compared case-insensitively.
 * @return array<string,bool>
 */
function bcp47BatchResolve(\mysqli $db, string $table, array $codes): array
{
    $codes = array_values(array_unique(array_filter($codes, static fn($c) => $c !== '')));
    if (empty($codes)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    /* $table is sourced from bcp47ResolveTable()'s INFORMATION_SCHEMA-
       verified return (or a caller's hardcoded literal) — never user
       input (rule #5). Every VALUE is bound. */
    $sql = "SELECT Code, IsActive FROM {$table} WHERE LOWER(Code) IN ({$placeholders})";
    $stmt = $db->prepare($sql);
    $types = str_repeat('s', count($codes));
    $lowered = array_map('mb_strtolower', $codes);
    $stmt->bind_param($types, ...$lowered);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[mb_strtolower((string)$row['Code'])] = ((int)$row['IsActive']) === 1;
    }
    $stmt->close();
    return $out;
}

/**
 * BCP 47 registry plan §5.2 — the full scan. Runs the derived sources'
 * `GROUP BY` queries, decomposes every distinct tag ONCE, batch-resolves
 * every distinct subtag per kind in ONE query each, then classifies.
 * Called only on the `?view=unknown` request (a few GROUP-BY scans;
 * acceptable on demand — never cached, never a new table, rule #44).
 *
 * @return list<array{tag:string,class:string,total:int,bySource:array<string,int>}>
 *         Sorted malformed -> unregistered -> inactive, each group by
 *         total usage descending. 'ok' tags are never included.
 */
function languageTagAuditScan(\mysqli $db): array
{
    $sources = languageTagSources($db);
    if (empty($sources)) {
        return [];
    }

    /* 1. Per-source GROUP BY — one distinct-tag+count list per column,
       merged into a single per-tag usage map. */
    $tagUsage = []; // tag => ['total' => int, 'bySource' => [label => int]]
    foreach ($sources as $src) {
        $table = $src['table'];
        $col   = $src['column'];
        try {
            /* Identifiers ($table/$col) come from the derived
               languageTagSources() map only — never user input (rule #5). */
            $sql = "SELECT `{$col}` AS tag, COUNT(*) AS c
                      FROM `{$table}`
                     WHERE `{$col}` IS NOT NULL AND `{$col}` <> ''
                     GROUP BY `{$col}`";
            $res = $db->query($sql);
            if (!$res) {
                continue;
            }
            while ($row = $res->fetch_assoc()) {
                $tag = (string)$row['tag'];
                $c   = (int)$row['c'];
                $tagUsage[$tag]['total'] = ($tagUsage[$tag]['total'] ?? 0) + $c;
                $tagUsage[$tag]['bySource'][$src['label']] = $c;
            }
            $res->close();
        } catch (\Throwable $e) {
            /* A source table/column can vanish mid-refactor on an
               un-migrated install; skip that source rather than fail the
               whole scan (matches every other schema-probed reader in
               this codebase). */
            error_log("[languageTagAuditScan:{$src['label']}] " . $e->getMessage());
        }
    }
    if (empty($tagUsage)) {
        return [];
    }

    /* 2. Decompose every distinct tag once; collect the distinct subtag
       codes needed per kind. */
    $decomposed = [];
    $codesByKind = ['language' => [], 'script' => [], 'region' => [], 'variant' => []];
    foreach (array_keys($tagUsage) as $tag) {
        $d = bcp47DecomposeTag($tag);
        $decomposed[$tag] = $d;
        if ($d['lang'] !== '')   { $codesByKind['language'][$d['lang']] = true; }
        if ($d['script'] !== '') { $codesByKind['script'][$d['script']] = true; }
        if ($d['region'] !== '') { $codesByKind['region'][$d['region']] = true; }
        foreach ($d['variants'] as $v) { $codesByKind['variant'][$v] = true; }
    }

    /* 3. ONE batched WHERE Code IN (…) per kind (plan §5.2). */
    $registry = [];
    foreach (['language', 'script', 'region', 'variant'] as $kind) {
        $table = bcp47ResolveTable($db, $kind);
        $registry[$kind] = $table !== ''
            ? bcp47BatchResolve($db, $table, array_keys($codesByKind[$kind]))
            : [];
    }

    /* 4. Classify from the in-memory maps — zero further DB calls. */
    $rows = [];
    foreach ($tagUsage as $tag => $usage) {
        $class = bcp47ClassifyTag($tag, $registry);
        if ($class === 'ok') {
            continue;
        }
        $rows[] = [
            'tag'      => $tag,
            'class'    => $class,
            'total'    => (int)($usage['total'] ?? 0),
            'bySource' => $usage['bySource'] ?? [],
        ];
    }

    $classRank = ['malformed' => 0, 'unregistered' => 1, 'inactive' => 2];
    usort($rows, static function (array $a, array $b) use ($classRank): int {
        return ($classRank[$a['class']] <=> $classRank[$b['class']])
            ?: ($b['total'] <=> $a['total'])
            ?: strcmp($a['tag'], $b['tag']);
    });

    return $rows;
}

/* ============================================================================
 * BCP 47 registry plan §5.3 — the remap write core. ONE function, called by
 * manage/languages.php's `remap_tag` POST action; the page itself owns only
 * CSRF/entitlement/the type-the-count confirm UI, never a second copy of
 * this logic (rule #22).
 * ========================================================================== */

/** Per-request cap on how many songs a single `line-path` remap touches —
 *  junk tags are low-usage in practice (the plan's own framing), so this is
 *  a safety valve, not a normal-case limiter. The action reports
 *  "N of M done, run again" when capped rather than silently truncating. */
const IHYMNS_LANGUAGE_TAG_REMAP_LINE_PATH_BATCH = 200;

/**
 * Remap every occurrence of `$fromTag` to `$toTag` across every derived
 * source, per its remap kind (BCP 47 registry plan §5.3):
 *   - 'direct'      — a plain bound `UPDATE <table> SET <col> = ? WHERE <col> = ?`,
 *                       row-by-row for `tblSongTranslations.TargetLanguage`
 *                       ONLY (its unique key + hard FK to tblLanguages need
 *                       per-row skip-on-conflict; every other 'direct'
 *                       source is a single set-based UPDATE).
 *   - 'line-path'   — NEVER a raw UPDATE (rule #25): per affected song,
 *                       `lyricLinesEditableComponents()` -> substitute the
 *                       tag in each component's language/languages ->
 *                       `lyricLinesWriteComponents()`, the ONE write path.
 *                       Capped at IHYMNS_LANGUAGE_TAG_REMAP_LINE_PATH_BATCH
 *                       songs per call.
 *   - 'report-only' — skipped entirely (no write) — rewriting a user's own
 *                       submitted request text is not curation.
 *
 * Never called with an unvalidated $toTag — the caller (manage/languages.php)
 * is responsible for the grammar check (`_ietfBcp47Validate()`) and the
 * type-the-count confirm BEFORE calling this. This function still refuses
 * outright on an empty/identical from/to pair as a defensive floor.
 *
 * @return array{ok:bool,error:?string,perSource:array<string,array{updated:int,skipped:int,note:?string}>,songsTouched:int,songsRemaining:int}
 */
function languageTagRemap(\mysqli $db, string $fromTag, string $toTag): array
{
    $fromTag = trim($fromTag);
    $toTag   = trim($toTag);
    if ($fromTag === '' || $toTag === '' || $fromTag === $toTag) {
        return ['ok' => false, 'error' => 'from/to tag must both be set and different.', 'perSource' => [], 'songsTouched' => 0, 'songsRemaining' => 0];
    }

    $sources = languageTagSources($db);
    $perSource = [];
    $songsTouched = 0;
    $songsRemaining = 0;

    foreach ($sources as $src) {
        $table = $src['table'];
        $col   = $src['column'];
        $label = $src['label'];

        if ($src['remap'] === 'report-only') {
            $perSource[$label] = ['updated' => 0, 'skipped' => 0, 'note' => 'report-only — not remapped (user-submitted text).'];
            continue;
        }

        if ($src['remap'] === 'line-path') {
            $result = languageTagRemapLinePath($db, $fromTag, $toTag);
            $perSource[$label] = ['updated' => $result['linesUpdated'], 'skipped' => 0, 'note' => $result['note']];
            $songsTouched   += $result['songsTouched'];
            $songsRemaining += $result['songsRemaining'];
            continue;
        }

        /* 'direct'. tblSongTranslations.TargetLanguage carries the schema's
           one hard FK to tblLanguages (fk_Trans_Lang) plus
           uq_Translation(SourceSongId, TargetLanguage) — remap only when
           $toTag already exists in the registry, and skip-report any row
           whose remap would collide with the unique key (row-by-row, so one
           collision doesn't abort every other row's remap). */
        if ($table === 'tblSongTranslations' && $col === 'TargetLanguage') {
            $existsStmt = $db->prepare('SELECT 1 FROM tblLanguages WHERE Code = ? LIMIT 1');
            $existsStmt->bind_param('s', $toTag);
            $existsStmt->execute();
            $toTagRegistered = $existsStmt->get_result()->fetch_row() !== null;
            $existsStmt->close();
            if (!$toTagRegistered) {
                $perSource[$label] = [
                    'updated' => 0, 'skipped' => 0,
                    'note' => "skipped — '{$toTag}' is not in the language registry yet (tblSongTranslations.TargetLanguage has a hard foreign key; add it to the registry on this page first).",
                ];
                continue;
            }

            $idsStmt = $db->prepare('SELECT Id FROM tblSongTranslations WHERE TargetLanguage = ?');
            $idsStmt->bind_param('s', $fromTag);
            $idsStmt->execute();
            $ids = array_map(static fn($r) => (int)$r['Id'], $idsStmt->get_result()->fetch_all(MYSQLI_ASSOC));
            $idsStmt->close();

            $updated = 0;
            $skipped = 0;
            $upd = $db->prepare('UPDATE tblSongTranslations SET TargetLanguage = ? WHERE Id = ?');
            foreach ($ids as $id) {
                try {
                    $upd->bind_param('si', $toTag, $id);
                    $upd->execute();
                    $updated++;
                } catch (\Throwable $e) {
                    /* Unique-key collision (a translation in $toTag already
                       exists for this source song) — skip this row,
                       continue with the rest. */
                    $skipped++;
                }
            }
            $upd->close();
            $perSource[$label] = [
                'updated' => $updated, 'skipped' => $skipped,
                'note' => $skipped > 0 ? "{$skipped} row(s) skipped — a translation in '{$toTag}' already exists for that source song." : null,
            ];
            continue;
        }

        /* Every other 'direct' source — a plain set-based UPDATE. Identifiers
           ($table/$col) are sourced from the derived languageTagSources()
           map only — never user input (rule #5). */
        try {
            $stmt = $db->prepare("UPDATE `{$table}` SET `{$col}` = ? WHERE `{$col}` = ?");
            $stmt->bind_param('ss', $toTag, $fromTag);
            $stmt->execute();
            $perSource[$label] = ['updated' => $stmt->affected_rows, 'skipped' => 0, 'note' => null];
            $stmt->close();
        } catch (\Throwable $e) {
            $perSource[$label] = ['updated' => 0, 'skipped' => 0, 'note' => 'remap failed: ' . $e->getMessage()];
        }
    }

    return [
        'ok' => true, 'error' => null, 'perSource' => $perSource,
        'songsTouched' => $songsTouched, 'songsRemaining' => $songsRemaining,
    ];
}

/**
 * The 'line-path' half of `languageTagRemap()` — `tblLyricLines.LanguageCode`
 * only, NEVER a raw UPDATE (rule #25). Finds every song whose primary
 * lyric-line version carries `$fromTag` as either the component default or
 * a per-line override, and for each (capped at
 * IHYMNS_LANGUAGE_TAG_REMAP_LINE_PATH_BATCH): reads the editable shape,
 * substitutes the tag, writes back via the ONE write path. Songs beyond the
 * cap are left untouched and counted in `songsRemaining` so the caller can
 * report "N of M done, run again".
 *
 * @return array{linesUpdated:int,songsTouched:int,songsRemaining:int,note:?string}
 */
function languageTagRemapLinePath(\mysqli $db, string $fromTag, string $toTag): array
{
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'lyric_lines_read.php';
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'lyric_lines_sync.php';

    if (!function_exists('lyricLinesSyncReady') || !lyricLinesSyncReady($db)) {
        return ['linesUpdated' => 0, 'songsTouched' => 0, 'songsRemaining' => 0, 'note' => 'skipped — tblLyricLines mirror not ready on this install.'];
    }

    /* Every song whose primary version has at least one line carrying
       $fromTag as its LanguageCode (per-line override) OR whose component
       default is $fromTag (component Language flows through as the
       "effective" per-line language via the inherit rule, so a
       component-only match still needs the song revisited even when no
       LINE row itself stores the override). */
    $stmt = $db->prepare(
        "SELECT DISTINCT ly.SongId
           FROM tblLyricLines ll
           JOIN tblLyrics ly ON ly.Id = ll.LyricsId
          WHERE ly.Source = 'ihymns' AND ll.LanguageCode = ?
          UNION
         SELECT DISTINCT sc.SongId
           FROM tblSongComponents sc
          WHERE sc.Language = ?"
    );
    $stmt->bind_param('ss', $fromTag, $fromTag);
    $stmt->execute();
    $songIds = array_map(static fn($r) => (string)$r['SongId'], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();

    $total = count($songIds);
    $batch = array_slice($songIds, 0, IHYMNS_LANGUAGE_TAG_REMAP_LINE_PATH_BATCH);
    $remaining = max(0, $total - count($batch));

    $linesUpdated = 0;
    $touched = 0;
    foreach ($batch as $songId) {
        try {
            $components = lyricLinesEditableComponents($db, $songId);
            $changed = false;
            foreach ($components as &$c) {
                if (($c['language'] ?? null) === $fromTag) {
                    $c['language'] = $toTag;
                    $changed = true;
                }
                if (!empty($c['languages']) && is_array($c['languages'])) {
                    foreach ($c['languages'] as &$lv) {
                        if ($lv === $fromTag) {
                            $lv = $toTag;
                            $changed = true;
                        }
                    }
                    unset($lv);
                }
            }
            unset($c);
            if ($changed) {
                lyricLinesWriteComponents($db, $songId, $components);
                $touched++;
                $linesUpdated++; // per-song count; exact line count isn't surfaced — the tag-level total from the audit scan already reports usage
            }
        } catch (\Throwable $e) {
            error_log("[languageTagRemapLinePath:$songId] " . $e->getMessage());
        }
    }

    return [
        'linesUpdated'   => $linesUpdated,
        'songsTouched'   => $touched,
        'songsRemaining' => $remaining,
        'note' => $remaining > 0
            ? "{$touched} of {$total} song(s) done — run again to continue (batch cap " . IHYMNS_LANGUAGE_TAG_REMAP_LINE_PATH_BATCH . ')'
            : null,
    ];
}
