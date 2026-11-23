<?php

declare(strict_types=1);

/**
 * account_export.php - "download everything we hold about you" (PR 2c-1, epic #2180)
 * ===================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * A signed-in person can ask for a file that holds everything the site keeps about
 * THEM: their account details, favourites, set lists, settings, history and so on.
 * This file builds that file. It never includes passwords, sign-in codes, push
 * keys or anything else that works like a key, and it never includes anybody
 * else's information.
 *
 * Detailed
 * --------
 *  - THE LIST OF WHERE TO LOOK IS DERIVED, NOT TYPED. `accountEraseFkGraph()` (the
 *    same list the erasure uses, includes/account_lifecycle.php) asks the live
 *    database "which columns point at a user?". Every answer is exported, unless it
 *    is in the reviewed `accountExportExcludedTables()` list WITH A STATED REASON. So
 *    a table somebody adds next year is exported the day it exists, and the CI guard
 *    (tests/php/test-account-export-coverage.php) fails if a table is neither exported
 *    nor excluded on purpose. Erasure and export therefore read the SAME map, which
 *    is what stops "we delete it but forgot to tell you we had it" (rule #35).
 *  - COLUMNS THAT POINT AT A USER WITHOUT A DATABASE LINK are listed by hand in
 *    `accountExportNoLinkColumns()` (the guard derives the true list from schema.sql
 *    and fails if one is missing). A column holding a person's NAME as text
 *    (the sign-in attempt log) is excluded: it may hold other people's addresses.
 *  - SECRETS NEVER LEAVE. `accountExportColumnAllowed()` refuses a column by name:
 *    the reviewed list `accountExportDenyColumns()` PLUS a pattern
 *    (`accountExportDenyPattern()`) that catches a secret column nobody has reviewed
 *    yet, so a future `ThingToken` column is withheld until somebody decides otherwise.
 *    The guard checks the reviewed list covers every secret-looking column that exists.
 *  - NAMES COME FROM THE CATALOGUE. Table and column names are read from
 *    INFORMATION_SCHEMA and must match `^[A-Za-z0-9_]+$` before being put in SQL inside
 *    backticks (rule #5 clause (b)); the person's id is always a bound parameter. The
 *    only other text put into SQL is `accountExportRowFilters()`, constants in this file.
 *  - LIMITS. At most 10,000 rows per table (one extra row is read just to know whether
 *    the table was cut short), 5 MiB of text per table, 32 MiB in all. A table that hit
 *    a limit says so (`truncated: true`), and the bulky catalogue tables are read last
 *    so a heavy curator's audit log cannot push out their favourites.
 *  - NOT GATED by the account sync switch (owner decision, 2026-11-23): it reads only
 *    the signed-in person's own data. The endpoint still needs sign-in, a same-origin
 *    request, and is limited to 5 an hour per person.
 *
 * @see includes/account_lifecycle.php  accountEraseFkGraph(): the one map of what links to a person
 * @see tests/php/test-account-export-coverage.php  the guard that derives the lists from schema.sql
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'sql_identifier.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'account_lifecycle.php';

/** Most rows taken from one table. */
const ACCOUNT_EXPORT_MAX_ROWS = 10000;
/** Most text (once written as JSON) taken from one table. */
const ACCOUNT_EXPORT_MAX_TABLE_BYTES = 5 * 1024 * 1024;
/** Most text in the whole file. */
const ACCOUNT_EXPORT_MAX_TOTAL_BYTES = 32 * 1024 * 1024;
/** The file's own format name and number, so a reader can tell what it is looking at. */
const ACCOUNT_EXPORT_FORMAT = 'ihymns-account-export';
const ACCOUNT_EXPORT_FORMAT_VERSION = 1;
/** How many downloads a person may ask for in an hour. */
const ACCOUNT_EXPORT_RATE_PER_HOUR = 5;

/**
 * Column names that are NEVER exported, whichever table they are in. Lower case.
 *
 * ELI5: passwords, sign-in codes, push keys and the like. The value says what kind
 * of secret it is, so a reviewer can see why it is here.
 *
 * @return array<string,string>
 */
function accountExportDenyColumns(): array
{
    return [
        'passwordhash'            => 'password hash',
        'token'                   => 'sign-in or reset token',
        'tokenhash'               => 'sign-in token hash',
        'devicecodehash'          => 'device pairing code hash',
        'keyhash'                 => 'key hash',
        'refreshtoken'            => 'refresh token of a linked sign-in',
        'p256dhkey'               => 'push encryption key',
        'authkey'                 => 'push authentication key',
        'endpoint'                => 'push delivery address (works like a key)',
        'pushtoken'               => 'device push token',
        'apitokenhash'            => 'sign-in token hash',
        'secret'                  => 'webhook secret',
        'secretprevious'          => 'previous webhook secret',
        'secretpreviousexpiresat' => 'belongs to the previous webhook secret',
        'verifytoken'             => 'webhook verification token',
        'licencekey'              => 'licence key',
        'localhash'               => 'internal hash of a synced record',
    ];
}

/**
 * A pattern that catches a secret-looking column nobody has reviewed yet. No
 * delimiters are added here; the caller wraps it. Case-insensitive.
 */
function accountExportDenyPattern(): string
{
    return 'token|hash|secret|password|refresh|p256dh|pushtoken|keyhash|authkey|licencekey|enrolkey';
}

/** PURE: may this column be written into the file? */
function accountExportColumnAllowed(string $column): bool
{
    if (isset(accountExportDenyColumns()[strtolower($column)])) {
        return false;
    }
    return preg_match('/' . accountExportDenyPattern() . '/i', $column) !== 1;
}

/**
 * Tables that point at a user but are deliberately NOT exported, with the reason in
 * plain words (it is shown to the person in the file). Keys are lower case.
 *
 * @return array<string,string>
 */
function accountExportExcludedTables(): array
{
    $codes   = 'Short-lived sign-in codes. They hold only secrets and expiry times, nothing about you.';
    $orgs    = 'Organisation administration (settings an organisation owner or admin made). Not about you personally.';
    $curate  = 'Work done by site curators on the song catalogue. It is attributed to an account number, not stored as personal information.';
    $config  = 'Site settings an administrator changed. Only the account number is recorded.';
    return [
        'tblemaillogintokens'                 => $codes,
        'tblemailverificationtokens'          => $codes,
        'tblpasswordresettokens'              => $codes,
        'tblauthdevicecodes'                  => $codes,
        'tblcontentlicences'                  => 'Organisation licence records. They hold licence keys, which are never exported.',
        'tblgatingcapabilities'               => $config,
        'tblgatingrules'                      => $config,
        'tbllicencetypes'                     => $config,
        'tblorgservicescheduleexternalrefs'   => $orgs,
        'tblorgvenueexternalrefs'             => $orgs,
        'tblorganisationexternalrefs'         => $orgs,
        'tblorganisationlogos'                => $orgs . ' (Logo image files are not exported.)',
        'tblprinttemplatecustomlayout'        => $orgs,
        'tblservicedriverkeys'                => $orgs . ' (They hold keys, which are never exported.)',
        'tblwebhooksubscriptions'             => $orgs . ' (They hold secrets, which are never exported.)',
        'tblsongs'                            => 'Songs in the catalogue. Only the account number of the curator who removed a song is recorded on it.',
        'tblsongidentitymap'                  => $curate,
        'tblsongqualityfindings'              => $curate,
        'tbllyricsconflicts'                  => $curate,
        'tbllyricsreviewqueue'                => $curate,
        'tblsetlisttemplates'                 => 'Public set-list templates you made. They now belong to the site, so they are not personal data.',
    ];
}

/**
 * Columns that hold a person's id with NO database link, so the linked-tables map
 * cannot see them. `export` false = deliberately left out, with the reason. Lower-case
 * keys 'table.column'. The guard derives the true list from schema.sql.
 *
 * @return array<string,array{table:string,column:string,export:bool,reason:string}>
 */
function accountExportNoLinkColumns(): array
{
    return [
        'tblbulkimportjobs.userid' => [
            'table' => 'tblBulkImportJobs', 'column' => 'UserId', 'export' => true, 'reason' => '',
        ],
        'tblwebhookevents.actoruserid' => [
            'table' => 'tblWebhookEvents', 'column' => 'ActorUserId', 'export' => false,
            'reason' => 'Internal audit of organisation webhook activity. It is never sent anywhere and is not about you personally.',
        ],
        'tblsonglinks.createdby' => [
            'table' => 'tblSongLinks', 'column' => 'CreatedBy', 'export' => false,
            'reason' => 'Work done by site curators on the song catalogue. It is attributed to an account number, not stored as personal information.',
        ],
        'tblsongredirects.createdby' => [
            'table' => 'tblSongRedirects', 'column' => 'CreatedBy', 'export' => false,
            'reason' => 'Work done by site curators on the song catalogue. It is attributed to an account number, not stored as personal information.',
        ],
        'tblsongmedia.uploadedby' => [
            'table' => 'tblSongMedia', 'column' => 'UploadedBy', 'export' => false,
            'reason' => 'Work done by site curators on the song catalogue. It is attributed to an account number, not stored as personal information.',
        ],
        'tblvocalpartsuggestions.reviewedby' => [
            'table' => 'tblVocalPartSuggestions', 'column' => 'ReviewedBy', 'export' => false,
            'reason' => 'Work done by site curators on the song catalogue. It is attributed to an account number, not stored as personal information.',
        ],
        'tbliaimportcandidates.reviewedby' => [
            'table' => 'tblIaImportCandidates', 'column' => 'ReviewedBy', 'export' => false,
            'reason' => 'Work done by site curators on the song catalogue. It is attributed to an account number, not stored as personal information.',
        ],
        'tblprinttemplates.createdby' => [
            'table' => 'tblPrintTemplates', 'column' => 'CreatedBy', 'export' => false,
            'reason' => 'The print templates you own are exported through their owner column; this column only records who first created one.',
        ],
        'tblloginattempts.username' => [
            'table' => 'tblLoginAttempts', 'column' => 'Username', 'export' => false,
            'reason' => 'The sign-in attempt log is kept by username text and can hold other people\'s addresses, so it is not included. It is removed when an account is erased.',
        ],
    ];
}

/**
 * Extra WHERE text for a table, to leave out rows that are internal bookkeeping.
 * Constants written here, never built from input (rule #5 clause (a)). Lower-case keys.
 *
 * @return array<string,string>
 */
function accountExportRowFilters(): array
{
    return [
        /* The profile items table also holds the sync's own counters under a section named `_meta`. */
        'tbluserprofileitems' => "`Section` <> '_meta'",
    ];
}

/**
 * Tables read LAST, so that a very large one (a curator's audit trail, every song
 * revision) can never use up the file's size budget before the person's own items.
 *
 * @return list<string> lower case
 */
function accountExportBulkyTables(): array
{
    return ['tblactivitylog', 'tblsongrevisions', 'tbllyrics', 'tbllyriclinetranslations', 'tbllyriclineannotations', 'tblsongusageevents', 'tblsonghistory'];
}

/** PURE: is this a name that may be written inside backticks in SQL? */
function accountExportNameSafe(string $name): bool
{
    return $name !== '' && ihymnsSqlIdentifierSafe($name) && preg_match('/^[A-Za-z0-9_]+$/', $name) === 1;
}

/**
 * PURE: turn the list of linked columns (from the live database) plus the hand-listed no-link
 * columns into the plan: which (table, column) pairs are exported, which are left out and why.
 *
 * @param list<array{table:string,column:string}> $links
 * @return array{export:list<array{table:string,column:string}>, excluded:list<array{table:string,column:string,reason:string}>}
 */
function accountExportPlan(array $links): array
{
    $excludedTables = accountExportExcludedTables();
    $export   = [];
    $excluded = [];
    $seen     = [];
    foreach ($links as $l) {
        $key = strtolower($l['table']) . '.' . strtolower($l['column']);
        if (isset($seen[$key])) { continue; }
        $seen[$key] = true;
        $reason = $excludedTables[strtolower($l['table'])] ?? null;
        if ($reason !== null) {
            $excluded[] = ['table' => $l['table'], 'column' => $l['column'], 'reason' => $reason];
        } else {
            $export[] = ['table' => $l['table'], 'column' => $l['column']];
        }
    }
    foreach (accountExportNoLinkColumns() as $key => $n) {
        if (isset($seen[$key])) { continue; }
        $seen[$key] = true;
        if ($n['export'] === true) {
            $export[] = ['table' => $n['table'], 'column' => $n['column']];
        } else {
            $excluded[] = ['table' => $n['table'], 'column' => $n['column'], 'reason' => $n['reason']];
        }
    }
    /* Order: the person's own tables first, bulky tables last, otherwise by name. */
    $bulky = array_flip(accountExportBulkyTables());
    usort($export, static function (array $a, array $b) use ($bulky): int {
        $ra = isset($bulky[strtolower($a['table'])]) ? 1 : 0;
        $rb = isset($bulky[strtolower($b['table'])]) ? 1 : 0;
        if ($ra !== $rb) { return $ra <=> $rb; }
        return strcasecmp($a['table'] . '.' . $a['column'], $b['table'] . '.' . $b['column']);
    });
    return ['export' => $export, 'excluded' => $excluded];
}

/**
 * Does this live table exist, and what are its columns? Read from the catalogue.
 *
 * @return list<array{name:string,type:string}> empty when the table does not exist
 */
function accountExportTableColumns(\mysqli $db, string $table): array
{
    $stmt = $db->prepare(
        'SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['name' => (string)$r['COLUMN_NAME'], 'type' => strtolower((string)$r['DATA_TYPE'])];
    }
    return $out;
}

/** The primary-key columns of a live table, in order (for a steady row order). */
function accountExportPrimaryKey(\mysqli $db, string $table): array
{
    $stmt = $db->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION"
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_NUM);
    $stmt->close();
    return array_map(static fn(array $r): string => (string)$r[0], $rows);
}

/** Column types that cannot be written as text. They are left out and named. */
function accountExportBinaryTypes(): array
{
    return ['blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary'];
}

/**
 * Read one person's rows from one (table, column) pair, within the limits.
 *
 * @param int $budgetBytes how many more bytes the whole file may take
 * @return array{column:string,rows:list<array<string,mixed>>,truncated:bool,omittedColumns:list<string>,bytes:int}|null
 *         null when the table or column does not exist on this install (not migrated yet)
 */
function accountExportReadTable(\mysqli $db, string $table, string $column, int $userId, int $budgetBytes): ?array
{
    if (!accountExportNameSafe($table) || !accountExportNameSafe($column)) {
        throw new \RuntimeException('Refusing to export a table or column with an unsafe name.');
    }
    $cols = accountExportTableColumns($db, $table);
    if ($cols === []) { return null; }
    $names = array_column($cols, 'name');
    if (!in_array(strtolower($column), array_map('strtolower', $names), true)) { return null; }

    $select  = [];
    $omitted = [];
    foreach ($cols as $c) {
        if (!accountExportNameSafe($c['name'])) { $omitted[] = $c['name']; continue; }
        if (!accountExportColumnAllowed($c['name']) || in_array($c['type'], accountExportBinaryTypes(), true)) {
            $omitted[] = $c['name'];
            continue;
        }
        $select[] = '`' . $c['name'] . '`';
    }
    if ($select === []) {
        return ['column' => $column, 'rows' => [], 'truncated' => false, 'omittedColumns' => $omitted, 'bytes' => 0];
    }
    $order = [];
    foreach (accountExportPrimaryKey($db, $table) as $pk) {
        if (accountExportNameSafe($pk)) { $order[] = '`' . $pk . '`'; }
    }
    $filter = accountExportRowFilters()[strtolower($table)] ?? '';
    /* Identifiers are catalogue names checked above; the person's id is bound; $filter is a constant of this file. */
    $sql = 'SELECT ' . implode(', ', $select) . ' FROM `' . $table . '` WHERE `' . $column . '` = ?'
         . ($filter !== '' ? ' AND ' . $filter : '')
         . ($order !== [] ? ' ORDER BY ' . implode(', ', $order) : '')
         . ' LIMIT ' . (ACCOUNT_EXPORT_MAX_ROWS + 1);
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    $bytes = 0;
    $truncated = false;
    $tableLimit = min(ACCOUNT_EXPORT_MAX_TABLE_BYTES, $budgetBytes);
    while ($row = $res->fetch_assoc()) {
        if (count($rows) >= ACCOUNT_EXPORT_MAX_ROWS) { $truncated = true; break; }
        $size = strlen((string)json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE));
        if ($bytes + $size > $tableLimit) { $truncated = true; break; }
        $bytes += $size;
        $rows[] = $row;
    }
    $res->free();
    $stmt->close();
    return ['column' => $column, 'rows' => $rows, 'truncated' => $truncated, 'omittedColumns' => $omitted, 'bytes' => $bytes];
}

/**
 * The person's own account row, without the password hash, with the two JSON columns opened up.
 *
 * @return array<string,mixed>
 */
function accountExportAccountRow(\mysqli $db, int $userId): array
{
    $cols = accountExportTableColumns($db, 'tblUsers');
    $select = [];
    foreach ($cols as $c) {
        if (accountExportNameSafe($c['name']) && accountExportColumnAllowed($c['name']) && !in_array($c['type'], accountExportBinaryTypes(), true)) {
            $select[] = '`' . $c['name'] . '`';
        }
    }
    if ($select === []) { return []; }
    $stmt = $db->prepare('SELECT ' . implode(', ', $select) . ' FROM `tblUsers` WHERE `Id` = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    foreach (['Settings', 'PreferredLanguagesJson'] as $jsonColumn) {
        if (isset($row[$jsonColumn]) && is_string($row[$jsonColumn])) {
            $decoded = json_decode($row[$jsonColumn], true);
            if ($decoded !== null || $row[$jsonColumn] === 'null') { $row[$jsonColumn] = $decoded; }
        }
    }
    return $row;
}

/**
 * Build the whole export for one person.
 *
 * @return array<string,mixed> the structure that is written as the file (see accountExportEncode())
 */
function accountExportBuild(\mysqli $db, int $userId): array
{
    if ($userId <= 0) {
        throw new \InvalidArgumentException('accountExportBuild() needs a positive user id.');
    }
    $plan = accountExportPlan(array_map(
        static fn(array $fk): array => ['table' => $fk['table'], 'column' => $fk['column']],
        accountEraseFkGraph($db)
    ));

    $tables   = [];
    $notFound = [];
    $spent    = 0;
    foreach ($plan['export'] as $p) {
        $left = ACCOUNT_EXPORT_MAX_TOTAL_BYTES - $spent;
        if ($left <= 0) {
            /* The file is full: say so for this table instead of leaving it out silently. */
            $tables[$p['table'] . '.' . $p['column']] = ['column' => $p['column'], 'rows' => [], 'truncated' => true, 'omittedColumns' => []];
            continue;
        }
        $t = accountExportReadTable($db, $p['table'], $p['column'], $userId, $left);
        if ($t === null) { $notFound[] = $p['table'] . '.' . $p['column']; continue; }
        $spent += $t['bytes'];
        unset($t['bytes']);
        if ($t['rows'] === [] && !$t['truncated']) { continue; }   /* nothing held there: keep the file readable */
        $tables[$p['table'] . '.' . $p['column']] = $t;
    }

    $excluded = [];
    foreach ($plan['excluded'] as $e) {
        $excluded[] = ['table' => $e['table'], 'column' => $e['column'], 'reason' => $e['reason']];
    }

    return [
        'format'        => ACCOUNT_EXPORT_FORMAT,
        'formatVersion' => ACCOUNT_EXPORT_FORMAT_VERSION,
        'exportedAt'    => gmdate('Y-m-d\TH:i:s\Z'),
        'about'         => 'Everything this site holds about your account. Passwords, sign-in codes, push keys and other secrets are never included. '
                         . 'A table marked "truncated" held more than the file could take. Only tables that hold something for you are listed.',
        'account'       => accountExportAccountRow($db, $userId),
        'tables'        => $tables,
        'notIncluded'   => $excluded,
    ];
}

/** The file's text. Invalid text is replaced rather than failing the whole download. */
function accountExportEncode(array $export): string
{
    $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        throw new \RuntimeException('The export could not be written as JSON.');
    }
    return $json;
}
