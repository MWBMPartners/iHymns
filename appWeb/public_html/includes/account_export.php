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
 *  - NOBODY ELSE'S INFORMATION (PR 2c review). Three things used to leak other people's data into a person's file, and
 *    each now has its own mechanism (all guarded by tests/php/test-account-export-coverage.php, rule #35):
 *      1. "Reviewer" links. A column such as `ReviewedBy`, `ApprovedBy` or `VerifiedBy` points at the person who CHECKED
 *         somebody else's work, so reading the table by that column hands over the other person's rows. Such a link is
 *         left out and the file says why. It is found by a name pattern (`accountExportReviewerColumnPattern()`) PLUS an
 *         explicit reviewed list (`accountExportReviewerColumns()`); the guard derives the real columns from schema.sql
 *         and fails if one is in neither.
 *      2. Network details of somebody else. A failed sign-in to your account is logged against your account but carries
 *         the ATTACKER's address, browser and country. On the activity log those columns are blanked for every row that
 *         was not a success (`accountExportColumnMasks()`), and `tblApiKeys.LastUsedIp` is left out
 *         (`accountExportOmitColumns()`), as is the server's temporary file path on a bulk import job.
 *      3. The same row twice. A table with two columns that can both be you (the creator and the owner of a shared set
 *         list) is read once: a later column skips rows an earlier column of the same table already gave.
 *  - NAMES COME FROM THE CATALOGUE. Table and column names are read from
 *    INFORMATION_SCHEMA and must match `^[A-Za-z0-9_]+$` before being put in SQL inside
 *    backticks (rule #5 clause (b)); the person's id is always a bound parameter. The
 *    only other text put into SQL is `accountExportRowFilters()`, constants in this file.
 *  - LIMITS. At most 10,000 rows per table (fewer for tables whose rows are large text:
 *    `accountExportRowCaps()`), 5 MiB of text per table, 32 MiB in all. One extra row is read
 *    just to know whether a table was cut short. A table that hit a limit says so
 *    (`truncated: true`), and the bulky catalogue tables are read last so a heavy curator's
 *    audit log cannot push out their favourites.
 *  - MEMORY STAYS SMALL (PR 2c review). The file is WRITTEN AS IT IS READ: rows come off the
 *    database one at a time (an unbuffered statement, not `get_result()`, which loads every row
 *    first), the limits are checked while reading, each row is written out compactly and
 *    forgotten, and the whole thing goes to a temporary file that the endpoint then sends. The
 *    earlier version built the full array, then a pretty-printed copy, and on a 128 MB host
 *    that ran out of memory, which PHP reports as a fatal error that no `catch` can catch. The
 *    file is compact JSON and has the same shape as before, except that inside a table the
 *    `rows` come before `truncated` (a table's cut-short mark is only known at its end).
 *  - ONE MOMENT IN TIME. All the reads happen inside a read-only transaction with a consistent snapshot, so a table read last
 *    shows the same moment as the one read first, even while the person's other devices keep syncing.
 *  - A failed attempt still counts towards the 5 downloads an hour. The count is taken before
 *    the file is built (that is what protects the database from a flood); it is not given back.
 *    A person who sees the "try again" message has the other four.
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
        'tblmusicianduplicatesdismissed.dismissedby' => [
            'table' => 'tblMusicianDuplicatesDismissed', 'column' => 'DismissedBy', 'export' => false,
            'reason' => 'Work done by site curators on the musician catalogue (marking two entries as not the same person). It is attributed to an account number, not stored as personal information.',
        ],
        'tblsonglinksuggestionsdismissed.dismissedby' => [
            'table' => 'tblSongLinkSuggestionsDismissed', 'column' => 'DismissedBy', 'export' => false,
            'reason' => 'Work done by site curators on the song catalogue (setting aside a suggested link between songs). It is attributed to an account number, not stored as personal information.',
        ],
        'tblcataloguesongs.addedby' => [
            'table' => 'tblCatalogueSongs', 'column' => 'AddedBy', 'export' => false,
            'reason' => 'Work done by site curators on the song catalogue (adding a song to a collection). It is attributed to an account number, not stored as personal information.',
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

/**
 * The name pattern (no delimiters, case-insensitive, matched against the WHOLE column name) of a column that points at
 * the person who reviewed, approved, verified or resolved SOMEBODY ELSE'S work.
 */
function accountExportReviewerColumnPattern(): string
{
    return '(reviewed|approved|verified|resolved)by|assignedto|(reviewer|approver|verifier)(id)?';
}

/**
 * The reviewer-role links reviewed by hand (lower-case 'table.column' => the role in plain words). The pattern above
 * catches the names; this list is the reviewed record AND covers a role whose column name the pattern would miss. The
 * guard checks that every link the live schema has that matches the pattern is listed here.
 *
 * @return array<string,string>
 */
function accountExportReviewerColumns(): array
{
    return [
        'tblapikeyrequests.reviewedby'         => 'the administrator who decided an API key request',
        'tblsongrevisions.reviewedby'          => 'the curator who reviewed somebody else\'s song change',
        'tbllyrics.approvedby'                 => 'the curator who approved somebody else\'s lyrics',
        'tbllyriclinetranslations.approvedby'  => 'the curator who approved somebody else\'s translation',
        'tbllyriclineannotations.approvedby'   => 'the curator who approved somebody else\'s annotation',
        'tbllyriclineannotations.verifiedby'   => 'the curator who verified somebody else\'s annotation',
        'tbllyricsreviewqueue.reviewedby'      => 'the curator who reviewed somebody else\'s lyrics',
        'tbllyricsreviewqueue.assignedto'      => 'the curator a lyrics review was handed to',
        'tbllyricsconflicts.resolvedby'        => 'the curator who settled a lyrics clash',
        'tblsongidentitymap.verifiedby'        => 'the curator who verified a song match',
        'tblsongqualityfindings.assignedto'    => 'the curator a catalogue problem was handed to',
        'tblsongqualityfindings.resolvedby'    => 'the curator who resolved a catalogue problem',
        'tblvocalpartsuggestions.reviewedby'   => 'the curator who reviewed somebody else\'s suggestion',
        'tbliaimportcandidates.reviewedby'     => 'the curator who reviewed an import candidate',
    ];
}

/** PURE: is this (table, column) a reviewer-role link (by the pattern or the reviewed list)? */
function accountExportIsReviewerColumn(string $table, string $column): bool
{
    return isset(accountExportReviewerColumns()[strtolower($table) . '.' . strtolower($column)])
        || preg_match('/^(' . accountExportReviewerColumnPattern() . ')$/i', $column) === 1;
}

/** The plain reason shown in the file for a reviewer-role link that is left out. */
const ACCOUNT_EXPORT_REVIEWER_REASON = 'You are recorded here only as the person who reviewed, approved or checked somebody else\'s work, so these rows are that other person\'s information, not yours. (Rows you made yourself are listed under their own column.)';

/**
 * Columns left out of ONE table (lower-case table => lower-case column => reason). These are not secrets (the deny list
 * covers those) but they are other people's details or the server's own.
 *
 * @return array<string,array<string,string>>
 */
function accountExportOmitColumns(): array
{
    return [
        'tblapikeys'       => ['lastusedip' => 'the address the key was last used from: it can be somebody else\'s'],
        'tblbulkimportjobs' => ['temppath'   => 'a file path on the server'],
    ];
}

/**
 * Network-detail columns (an address, a browser, a country) that are exported AS THEY ARE because they describe the
 * person's own request (lower-case 'table.column' => why). Every other such column of an exported table must be masked
 * or omitted above: the guard derives the columns from schema.sql and fails on one that is in none of the three places.
 *
 * @return array<string,string>
 */
function accountExportOwnNetworkColumns(): array
{
    return [
        'tblsongrequests.ipaddress' => 'the address the person themselves sent a song request from',
    ];
}

/**
 * Columns whose value is BLANKED (shown as null) on the rows where a stated condition does not hold. The condition is a
 * constant of this file (rule #5 clause (a)). Lower-case table => column (as in the schema) => the condition under which
 * the real value is kept. The activity log keeps the network details only for a success: a failed sign-in is logged against
 * the account it was aimed at, with the attacker's address, browser and country.
 *
 * @return array<string,array<string,string>>
 */
function accountExportColumnMasks(): array
{
    $keepOnSuccess = "`Result` = 'success'";
    return [
        'tblactivitylog' => [
            'IpAddress' => $keepOnSuccess, 'IpProxyChain' => $keepOnSuccess, 'ProxyVpnIndicator' => $keepOnSuccess,
            'ProxyVpnDetail' => $keepOnSuccess, 'UserAgent' => $keepOnSuccess, 'Country' => $keepOnSuccess,
            'Referrer' => $keepOnSuccess,
        ],
    ];
}

/**
 * Lower row limits for tables whose rows are large text (a song revision holds a whole song, an audit row holds
 * a details document). The general limit stays ACCOUNT_EXPORT_MAX_ROWS. Lower-case keys.
 *
 * @return array<string,int>
 */
function accountExportRowCaps(): array
{
    return [
        'tblsongrevisions'         => 1000,
        'tbllyrics'                => 2000,
        'tbllyriclinetranslations' => 5000,
        'tbllyriclineannotations'  => 5000,
        'tblactivitylog'           => 5000,
    ];
}

/** PURE: the row limit for one table. */
function accountExportRowCap(string $table): int
{
    return min(ACCOUNT_EXPORT_MAX_ROWS, accountExportRowCaps()[strtolower($table)] ?? ACCOUNT_EXPORT_MAX_ROWS);
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
        if ($reason === null && accountExportIsReviewerColumn($l['table'], $l['column'])) {
            $reason = ACCOUNT_EXPORT_REVIEWER_REASON;
        }
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

/** JSON flags for every piece of the file: invalid text is replaced rather than failing the whole download. */
const ACCOUNT_EXPORT_JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

/** Write text to the output stream; a write that fails (disk full) stops the export instead of silently losing text. */
function accountExportWrite($out, string $text): void
{
    if ($text !== '' && @fwrite($out, $text) === false) {
        throw new \RuntimeException('The export could not be written.');
    }
}

/**
 * Read one person's rows from one (table, column) pair, within the limits, and WRITE them to the output as they
 * are read. Nothing is kept: one row is in memory at a time.
 *
 * ELI5: pour the rows straight into the file instead of collecting them in a bucket first.
 *
 * What is written (only if the table holds something, or was cut short before its first row):
 *   <$prefix>"Table.Column":{"column":..,"omittedColumns":[..],"rows":[ {..},{..} ],"truncated":false}
 *
 * @param resource $out        the stream the text goes to
 * @param string   $prefix     text written just before the table's key (the comma between tables)
 * @param int      $budgetBytes how many more bytes the whole file may take
 * @param list<string> $earlierColumns columns of THIS table that already gave their rows (a row that matches one is skipped, so it is listed once)
 * @return array{written:bool,bytes:int}|null null when the table or column does not exist on this install (not migrated yet)
 */
function accountExportStreamTable(\mysqli $db, $out, string $prefix, string $table, string $column, int $userId, int $budgetBytes, array $earlierColumns = []): ?array
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
    $omitHere = accountExportOmitColumns()[strtolower($table)] ?? [];
    $masks    = accountExportColumnMasks()[strtolower($table)] ?? [];
    foreach ($cols as $c) {
        if (!accountExportNameSafe($c['name'])) { $omitted[] = $c['name']; continue; }
        if (!accountExportColumnAllowed($c['name']) || in_array($c['type'], accountExportBinaryTypes(), true) || isset($omitHere[strtolower($c['name'])])) {
            $omitted[] = $c['name'];
            continue;
        }
        if (isset($masks[$c['name']])) {
            /* The condition is a constant of this file; the column name is a checked catalogue name. */
            $select[] = 'IF(' . $masks[$c['name']] . ', `' . $c['name'] . '`, NULL) AS `' . $c['name'] . '`';
        } else {
            $select[] = '`' . $c['name'] . '`';
        }
    }
    $key    = json_encode($table . '.' . $column, ACCOUNT_EXPORT_JSON_FLAGS);
    $header = $prefix . $key . ':{"column":' . json_encode($column, ACCOUNT_EXPORT_JSON_FLAGS)
            . ',"omittedColumns":' . json_encode($omitted, ACCOUNT_EXPORT_JSON_FLAGS) . ',"rows":[';
    if ($select === []) {
        return ['written' => false, 'bytes' => 0];   /* every column withheld: nothing to list */
    }
    $order = [];
    foreach (accountExportPrimaryKey($db, $table) as $pk) {
        if (accountExportNameSafe($pk)) { $order[] = '`' . $pk . '`'; }
    }
    $filter = accountExportRowFilters()[strtolower($table)] ?? '';
    $cap    = accountExportRowCap($table);
    /* Identifiers are catalogue names checked above; the person's id is bound; $filter is a constant of this file. */
    $skip = [];
    foreach ($earlierColumns as $ec) {
        if (accountExportNameSafe($ec) && strcasecmp($ec, $column) !== 0) { $skip[] = $ec; }
    }
    $sql = 'SELECT ' . implode(', ', $select) . ' FROM `' . $table . '` WHERE `' . $column . '` = ?'
         . implode('', array_map(static fn(string $ec): string => ' AND (`' . $ec . '` IS NULL OR `' . $ec . '` <> ?)', $skip))
         . ($filter !== '' ? ' AND ' . $filter : '')
         . ($order !== [] ? ' ORDER BY ' . implode(', ', $order) : '')
         . ' LIMIT ' . ($cap + 1);
    $stmt = $db->prepare($sql);
    $ids  = array_fill(0, 1 + count($skip), $userId);
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    /* UNBUFFERED: bind_result() + fetch() hand over one row at a time. (get_result() would load every row into
       memory before the first limit check, which is how the first version ran a small host out of memory.) */
    $meta = $stmt->result_metadata();
    $row  = [];
    $refs = [];
    foreach ($meta->fetch_fields() as $f) { $row[$f->name] = null; $refs[] = &$row[$f->name]; }
    $meta->free();
    $stmt->bind_result(...$refs);

    $count      = 0;
    $bytes      = 0;
    $truncated  = false;
    $started    = false;
    $tableLimit = min(ACCOUNT_EXPORT_MAX_TABLE_BYTES, $budgetBytes);
    while ($stmt->fetch()) {
        if ($count >= $cap) { $truncated = true; break; }
        $copy = [];
        foreach ($row as $k => $v) { $copy[$k] = $v; }   /* bind_result reuses the same variables: take a copy */
        $enc  = json_encode($copy, ACCOUNT_EXPORT_JSON_FLAGS);
        $enc  = is_string($enc) ? $enc : 'null';
        $size = strlen($enc);
        if ($bytes + $size > $tableLimit) { $truncated = true; break; }
        accountExportWrite($out, ($started ? ',' : $header) . $enc);
        $started = true;
        $bytes  += $size;
        $count++;
    }
    $stmt->close();
    if (!$started && !$truncated) {
        return ['written' => false, 'bytes' => 0];   /* nothing held there: keep the file readable */
    }
    accountExportWrite($out, ($started ? '' : $header) . '],"truncated":' . ($truncated ? 'true' : 'false') . '}');
    return ['written' => true, 'bytes' => $bytes];
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
 * Write the whole export for one person to a stream, as compact JSON, without ever holding it in memory.
 *
 * @param resource $out a writable stream (the endpoint passes a temporary file)
 */
function accountExportStream(\mysqli $db, int $userId, $out): void
{
    if ($userId <= 0) {
        throw new \InvalidArgumentException('accountExportStream() needs a positive user id.');
    }
    /* ONE consistent picture (PR 2c review): the tables are read one after another, possibly over several seconds, while the person's
       other devices keep syncing. A read-only transaction with a consistent snapshot makes every table show the same moment, so the
       file never holds, say, a favourite's removal in one table and not in another. If the snapshot cannot be taken the export still runs. */
    $inSnapshot = false;
    try {
        $db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
        $inSnapshot = true;
    } catch (\Throwable $_e) {
        error_log('[account_export] no consistent snapshot: ' . $_e->getMessage());
    }
    try {
        accountExportStreamBody($db, $userId, $out);
    } finally {
        if ($inSnapshot) {
            try { $db->rollback(); } catch (\Throwable $_e) { /* a read-only transaction holds nothing to undo */ }
        }
    }
}

/** The body of accountExportStream(), run inside its snapshot. @param resource $out */
function accountExportStreamBody(\mysqli $db, int $userId, $out): void
{
    $plan = accountExportPlan(array_map(
        static fn(array $fk): array => ['table' => $fk['table'], 'column' => $fk['column']],
        accountEraseFkGraph($db)
    ));

    $f = ACCOUNT_EXPORT_JSON_FLAGS;
    accountExportWrite($out, '{"format":' . json_encode(ACCOUNT_EXPORT_FORMAT, $f)
        . ',"formatVersion":' . ACCOUNT_EXPORT_FORMAT_VERSION
        . ',"exportedAt":' . json_encode(gmdate('Y-m-d\TH:i:s\Z'), $f)
        . ',"about":' . json_encode('Everything this site holds about your account. Passwords, sign-in codes, push keys and other secrets are never included. '
            . 'A table marked "truncated" held more than the file could take. Only tables that hold something for you are listed.', $f)
        . ',"account":' . json_encode((object)accountExportAccountRow($db, $userId), $f)
        . ',"tables":{');

    $spent  = 0;
    $prefix = '';
    $done   = [];   /* table (lower case) => the columns already read, so a row that matches two of them is written once */
    foreach ($plan['export'] as $p) {
        $left = ACCOUNT_EXPORT_MAX_TOTAL_BYTES - $spent;
        if ($left <= 0) {
            /* The file is full: say so for this table instead of leaving it out silently. */
            accountExportWrite($out, $prefix . json_encode($p['table'] . '.' . $p['column'], $f)
                . ':{"column":' . json_encode($p['column'], $f) . ',"omittedColumns":[],"rows":[],"truncated":true}');
            $prefix = ',';
            continue;
        }
        $t = accountExportStreamTable($db, $out, $prefix, $p['table'], $p['column'], $userId, $left, $done[strtolower($p['table'])] ?? []);
        if ($t === null) { continue; }   /* not on this install yet */
        $done[strtolower($p['table'])][] = $p['column'];
        $spent += $t['bytes'];
        if ($t['written']) { $prefix = ','; }
    }

    $excluded = [];
    foreach ($plan['excluded'] as $e) {
        $excluded[] = ['table' => $e['table'], 'column' => $e['column'], 'reason' => $e['reason']];
    }
    accountExportWrite($out, '},"notIncluded":' . json_encode($excluded, $f) . '}');
}

/**
 * The whole export as text. For tests and tiny accounts only: the endpoint uses accountExportStream() with a
 * temporary file so that it never holds the file in memory.
 */
function accountExportText(\mysqli $db, int $userId): string
{
    $m = fopen('php://temp', 'w+b');
    accountExportStream($db, $userId, $m);
    rewind($m);
    $text = (string)stream_get_contents($m);
    fclose($m);
    return $text;
}

/**
 * The whole export as an array (the text, read back). Tests only; see accountExportText().
 *
 * @return array<string,mixed>
 */
function accountExportBuild(\mysqli $db, int $userId): array
{
    return json_decode(accountExportText($db, $userId), true, 512, JSON_THROW_ON_ERROR);
}
