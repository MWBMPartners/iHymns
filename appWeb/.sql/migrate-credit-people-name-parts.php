<?php

declare(strict_types=1);

/**
 * iHymns — Credit People structured-name columns migration (#934)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds three structured name columns to tblCreditPeople so the registry
 * can distinguish first names from surname from suffix:
 *
 *   tblCreditPeople.FirstNames VARCHAR(255) NULL  (e.g. "Cecil Frances Humphreys")
 *   tblCreditPeople.Surname    VARCHAR(255) NULL  (e.g. "Alexander")
 *   tblCreditPeople.Suffix     VARCHAR(32)  NULL  (e.g. "Jr", "III", "PhD")
 *
 * @migration-adds tblCreditPeople.FirstNames
 * @migration-adds tblCreditPeople.Surname
 * @migration-adds tblCreditPeople.Suffix
 *
 * The existing `Name` column stays — it remains the canonical display
 * field that ~30 read sites already query. On every individual-person
 * write, Name is recomposed from the three structured fields. Group
 * (IsGroup=1) and special-case (IsSpecialCase=1) rows leave the three
 * structured columns NULL and keep their Name as-is.
 *
 * One-time backfill heuristic: tokenise Name on whitespace, peel a
 * trailing suffix (Jr/Sr/II/III/IV/V/VI/PhD/MD/Esq/D.D./D.Min.) into
 * Suffix, the now-last token into Surname, and everything before into
 * FirstNames. Rows tagged IsGroup=1 / IsSpecialCase=1 are skipped.
 * Compound surnames (\"Vaughan Williams\") will be split incorrectly —
 * curators fix those manually via the Edit Person modal. Catalogue is
 * hundreds of rows, not millions; manual cleanup is tractable.
 *
 * USAGE:
 *   Web:  /manage/setup-database → Apply all pending migrations
 *   CLI:  php appWeb/.sql/migrate-credit-people-name-parts.php
 *
 * Idempotent — re-running is safe; columns that already exist are
 * skipped, and the backfill only touches rows whose three structured
 * columns are all NULL (so a curator's manual edit is never overwritten).
 */

if (PHP_SAPI === 'cli') {
    if (!function_exists('getDbMysqli')) {
        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    }
    $isCli = true;
} else {
    if (!defined('IHYMNS_SETUP_DASHBOARD')) {
        if (!function_exists('isAuthenticated')) {
            require_once dirname(__DIR__) . '/public_html/manage/includes/auth.php';
        }
        if (!isAuthenticated()) {
            http_response_code(401);
            exit('Authentication required.');
        }
        $u = getCurrentUser();
        if (!$u || $u['role'] !== 'global_admin') {
            http_response_code(403);
            exit('Global admin required.');
        }
    }
    if (!function_exists('getDbMysqli')) {
        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    }
    $isCli = false;
}

function _migCpName_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) {
        flush();
    }
}

function _migCpName_columnExists(mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?
            AND COLUMN_NAME  = ?
          LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

function _migCpName_indexExists(mysqli $db, string $table, string $index): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?
            AND INDEX_NAME   = ?
          LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

/**
 * Best-effort split of a free-text Name into [FirstNames, Surname, Suffix].
 * Mirrors the `decomposePersonName` shipped in credit_people_helpers.php so
 * a re-run of the helper on the same string yields the same parts.
 *
 * Returns three values; any may be empty string ('') for "no part".
 *
 * @return array{0:string,1:string,2:string} [firstNames, surname, suffix]
 */
function _migCpName_decompose(string $name): array
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
    if ($name === '') {
        return ['', '', ''];
    }

    /* Comma-inverted form: "Wesley, Charles" → first="Charles", last="Wesley".
       Trailing suffix in either half is handled by the suffix sweep below. */
    if (str_contains($name, ',')) {
        $parts = array_map('trim', explode(',', $name, 2));
        if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
            $name = $parts[1] . ' ' . $parts[0];
        }
    }

    $tokens = preg_split('/\s+/', $name) ?: [];
    $tokens = array_values(array_filter($tokens, static fn(string $t): bool => $t !== ''));
    if (empty($tokens)) {
        return ['', '', ''];
    }

    /* Suffix sweep — accept zero or more trailing suffix tokens.
       Pattern is intentionally narrow: only the conventionally-recognised
       generational + post-nominal markers used in hymnal credits. */
    $suffixPattern = '/^(?:Jr|Sr|II|III|IV|V|VI|VII|VIII|PhD|Ph\.D\.|MD|M\.D\.|Esq|Esq\.|D\.D\.|D\.Min\.|MA|M\.A\.|BA|B\.A\.)$/i';
    $suffixParts = [];
    while (count($tokens) > 1) {
        $tail = $tokens[count($tokens) - 1];
        $tailNorm = rtrim($tail, '.,');
        if (preg_match($suffixPattern, $tail) || preg_match($suffixPattern, $tailNorm)) {
            array_unshift($suffixParts, array_pop($tokens));
            continue;
        }
        break;
    }
    $suffix = implode(' ', $suffixParts);

    if (count($tokens) === 1) {
        /* Single name token (e.g. "Anonymous", "Madonna") — store as Surname
           so any future "ORDER BY Surname" sorts naturally. */
        return ['', $tokens[0], $suffix];
    }

    $surname    = (string)array_pop($tokens);
    $firstNames = implode(' ', $tokens);
    return [$firstNames, $surname, $suffix];
}

_migCpName_out('Credit People name-parts migration starting…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    _migCpName_out('ERROR: could not connect to database.');
    exit(1);
}

/* Parent table must exist. */
$stmt = $mysqli->prepare(
    'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
);
$tableName = 'tblCreditPeople';
$stmt->bind_param('s', $tableName);
$stmt->execute();
$baseExists = $stmt->get_result()->fetch_row() !== null;
$stmt->close();
if (!$baseExists) {
    _migCpName_out('ERROR: tblCreditPeople not found. Run migrate-credit-people first.');
    exit(1);
}

/* Detect whether the classification flags from #584/#585 are present.
   The backfill skips IsGroup / IsSpecialCase rows when those columns
   exist; on a partly-migrated install where flags aren't there yet, the
   backfill runs over every row (and curators can re-flag groups later
   without losing structured data — the form's group toggle nullifies
   the three columns when set). */
$hasFlags = _migCpName_columnExists($mysqli, 'tblCreditPeople', 'IsSpecialCase')
         && _migCpName_columnExists($mysqli, 'tblCreditPeople', 'IsGroup');

/* ----------------------------------------------------------------------
 * Step 1-3: add the three structured-name columns
 * ---------------------------------------------------------------------- */
$columnSpecs = [
    ['FirstNames', 'VARCHAR(255) NULL DEFAULT NULL', 'Slug'],
    ['Surname',    'VARCHAR(255) NULL DEFAULT NULL', 'FirstNames'],
    ['Suffix',     'VARCHAR(32)  NULL DEFAULT NULL', 'Surname'],
];
foreach ($columnSpecs as [$col, $type, $after]) {
    if (_migCpName_columnExists($mysqli, 'tblCreditPeople', $col)) {
        _migCpName_out("[skip] tblCreditPeople.{$col} already present.");
        continue;
    }
    $sql = "ALTER TABLE tblCreditPeople ADD COLUMN {$col} {$type} AFTER {$after}";
    if (!$mysqli->query($sql)) {
        _migCpName_out("ERROR: adding {$col} failed: " . $mysqli->error);
        exit(1);
    }
    _migCpName_out("[add ] tblCreditPeople.{$col}.");
}

/* ----------------------------------------------------------------------
 * Step 4-5: indexes for surname-first sort
 * ---------------------------------------------------------------------- */
foreach ([
    ['idx_Surname',            '(Surname)'],
    ['idx_Surname_FirstNames', '(Surname, FirstNames)'],
] as [$idx, $cols]) {
    if (_migCpName_indexExists($mysqli, 'tblCreditPeople', $idx)) {
        _migCpName_out("[skip] {$idx} already present.");
        continue;
    }
    if (!$mysqli->query("ALTER TABLE tblCreditPeople ADD INDEX {$idx} {$cols}")) {
        _migCpName_out("WARN: adding {$idx} failed: " . $mysqli->error);
    } else {
        _migCpName_out("[add ] {$idx}.");
    }
}

/* ----------------------------------------------------------------------
 * Step 6: backfill structured columns from existing Name values.
 *
 * Touches rows where:
 *   - all three new columns are NULL (so a curator's manual edit is
 *     never clobbered on re-run);
 *   - AND, if the flag columns exist, IsGroup=0 AND IsSpecialCase=0
 *     (groups + special cases keep their Name unsplit).
 * ---------------------------------------------------------------------- */
$where = 'FirstNames IS NULL AND Surname IS NULL AND Suffix IS NULL';
if ($hasFlags) {
    $where .= ' AND IsGroup = 0 AND IsSpecialCase = 0';
}

$res = $mysqli->query("SELECT Id, Name FROM tblCreditPeople WHERE {$where}");
if (!$res) {
    _migCpName_out('ERROR: backfill SELECT failed: ' . $mysqli->error);
    exit(1);
}

$candidates = [];
while ($row = $res->fetch_assoc()) {
    $candidates[] = ['id' => (int)$row['Id'], 'name' => (string)$row['Name']];
}
$res->close();

if (empty($candidates)) {
    _migCpName_out('[seed] no rows needed structured-name backfill.');
} else {
    $update = $mysqli->prepare(
        'UPDATE tblCreditPeople
            SET FirstNames = ?, Surname = ?, Suffix = ?
          WHERE Id = ?
            AND FirstNames IS NULL AND Surname IS NULL AND Suffix IS NULL'
    );
    $filled  = 0;
    $skipped = 0;
    foreach ($candidates as $entry) {
        [$first, $surname, $suffix] = _migCpName_decompose($entry['name']);
        if ($first === '' && $surname === '' && $suffix === '') {
            $skipped++;
            continue;
        }
        $firstParam   = $first   !== '' ? $first   : null;
        $surnameParam = $surname !== '' ? $surname : null;
        $suffixParam  = $suffix  !== '' ? $suffix  : null;
        $update->bind_param('sssi', $firstParam, $surnameParam, $suffixParam, $entry['id']);
        $update->execute();
        if ($update->affected_rows > 0) {
            $filled++;
        }
    }
    $update->close();
    _migCpName_out(sprintf(
        '[seed] backfilled %d row%s (%d skipped — empty Name).',
        $filled, $filled === 1 ? '' : 's', $skipped
    ));
}

_migCpName_out('Credit People name-parts migration finished.');
/* Don't close $mysqli — see migrate-credit-people-slug.php for the
   shared-singleton rationale. */
