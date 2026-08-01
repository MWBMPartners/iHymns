<?php

declare(strict_types=1);

/**
 * iHymns — Credit People slug column migration (#588)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Adds a `Slug` column to tblCreditPeople and backfills it from each
 * row's Name. The slug is the URL component for the new public
 * /people/<slug> page (#588): a lower-case, hyphen-separated form of
 * the name with non-letter/digit characters stripped.
 *
 * Schema:
 *   tblCreditPeople.Slug VARCHAR(255) NOT NULL DEFAULT ''
 *   UNIQUE KEY uk_Slug (Slug)
 *
 * @migration-adds tblCreditPeople.Slug
 *
 * NOT DESTRUCTIVE, but it is the only migration in this family that
 * WRITES DATA as well as changing shape. No column is dropped and no
 * existing value is overwritten — the backfill's WHERE clause restricts
 * it to rows whose Slug is still empty, so a curator who has hand-edited
 * a slug keeps it. Rolling back means DROP INDEX uk_Slug + DROP COLUMN
 * Slug, which loses the slugs but nothing else; re-running regenerates
 * them from Name.
 *
 * IDEMPOTENT — re-running is safe, via THREE independent guards rather
 * than one: an INFORMATION_SCHEMA.COLUMNS probe skips the ALTER, the
 * backfill's `WHERE Slug = '' OR Slug IS NULL` selects nothing on a
 * second pass, and an INFORMATION_SCHEMA.STATISTICS probe skips the
 * unique key. Each step is separately resumable, so a run interrupted
 * between them picks up where it left off.
 *
 * STEP ORDER IS LOAD-BEARING. Slug arrives as `NOT NULL DEFAULT ''`,
 * which means the ALTER instantly gives EVERY existing row the same
 * value. Adding the UNIQUE key at that point would fail on the second
 * row. Hence: add the column (step 2), fill it (step 3), and only then
 * constrain it (step 4). Reordering these breaks the migration on any
 * install with more than one credit person.
 *
 * OPERATOR VIEW. Card "Credit People Slug + public page (#588)" on
 * /manage/setup-database; the registry probe keys on the presence of
 * tblCreditPeople.Slug — i.e. on step 2 only, so a run that added the
 * column but died during the backfill still shows the card as applied.
 *
 * SCHEMA MIRROR. tblCreditPeople.Slug and uk_Slug are declared in
 * appWeb/.sql/schema.sql, which is what a FRESH install reads; per rule
 * #19 the two declarations must stay byte-identical so a migrated
 * install and a fresh one are the same shape.
 *
 * USAGE:
 *   Web:  /manage/setup-database → Apply all pending migrations
 *   CLI:  php appWeb/.sql/migrate-credit-people-slug.php
 */

if (PHP_SAPI === 'cli') {
    /* Guarded require — see #652. The dashboard has already loaded
       db_mysql.php via auth.php's bootstrap, so the function already
       exists at this point in dashboard mode; the guard skips the
       re-open that some hosts block from outside public_html/. */

    if (!function_exists('getDbMysqli')) {

        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';

    }
    $isCli = true;
} else {
    /* Standalone web invocation (someone hitting this .php directly rather
       than going through the dashboard) has no auth behind it, so the file
       gates itself: global_admin only. IHYMNS_SETUP_DASHBOARD means the
       caller is setup-database.php, which has already run a stricter check
       (the run_db_install entitlement) — re-running it here would be the
       duplicated-gate the modularity rule forbids. */
    if (!defined('IHYMNS_SETUP_DASHBOARD')) {
        /* Guarded: dashboard mode pre-loads auth.php transitively. The
           guard also avoids re-opening the file from outside public_html/,
           which some hosts (open_basedir / php-fpm chroot) refuse even
           though the file is otherwise reachable (#652). */

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
    /* Guarded require — see #652. The dashboard has already loaded
       db_mysql.php via auth.php's bootstrap, so the function already
       exists at this point in dashboard mode; the guard skips the
       re-open that some hosts block from outside public_html/. */

    if (!function_exists('getDbMysqli')) {

        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';

    }
    $isCli = false;
}

function _migCpSlug_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    /* CLI only — see migrate-credit-people-flags.php for rationale (#661). */
    if ($isCli) {
        flush();
    }
}

function _migCpSlug_columnExists(mysqli $db, string $table, string $column): bool
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

/**
 * Slugify a person's name for the URL component. Mirrors the same
 * function shipped in the public page renderer so a name like "Cecil
 * Frances Humphreys Alexander" becomes "cecil-frances-humphreys-alexander".
 * Non-letter/digit characters become hyphens; consecutive hyphens
 * collapse; leading/trailing hyphens are trimmed.
 *
 * The mirroring is the whole point and the whole risk: /people/<slug>
 * looks the row up by the STORED slug, so if this function and the
 * renderer's copy ever diverge, the links the site generates stop
 * resolving to the rows this migration wrote. There is nothing enforcing
 * the agreement (rule #35 — a comment is not a mechanism), so treat any
 * edit here as a change to both.
 *
 * On the transformations, in order:
 *   - mb_strtolower, not strtolower: the byte-wise version mangles the
 *     accented names that are common in this table.
 *   - Normalizer::FORM_KD then stripping \p{M} decomposes "é" into "e"
 *     plus a combining acute and discards the accent, so "Fauré" yields
 *     "faure" rather than losing the character entirely to the class
 *     filter below. Guarded by class_exists because ext-intl is not
 *     universally installed — without it accented letters survive as
 *     themselves, which is still a valid \p{L} and still slugs cleanly,
 *     just to a different (percent-encoded) URL. That divergence is why
 *     the extension matters on a host that serves the public pages.
 *     https://www.php.net/manual/en/class.normalizer.php
 *   - [^\p{L}\p{N}]+ collapses each RUN of non-alphanumerics to a single
 *     hyphen, so "Smith, John (Jr.)" gives "smith-john-jr" with no
 *     doubled separators to clean up afterwards.
 *     https://www.php.net/manual/en/regexp.reference.unicode.php
 */
function _migCpSlug_slugify(string $name): string
{
    $name = mb_strtolower(trim($name));
    /* Strip Unicode combining marks via Normalizer if available. */
    if (class_exists('Normalizer')) {
        $name = Normalizer::normalize($name, Normalizer::FORM_KD);
        $name = preg_replace('/\p{M}+/u', '', $name);
    }
    $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $name);
    return trim($slug, '-');
}

_migCpSlug_out('Credit People slug migration starting…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    _migCpSlug_out('ERROR: could not connect to database.');
    exit(1);
}

/* Step 1: parent table must exist. */
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
    _migCpSlug_out('ERROR: tblCreditPeople not found. Run migrate-credit-people first.');
    exit(1);
}

/* Step 2: add Slug column. */
if (_migCpSlug_columnExists($mysqli, 'tblCreditPeople', 'Slug')) {
    _migCpSlug_out('[skip] tblCreditPeople.Slug already present.');
} else {
    $sql = "ALTER TABLE tblCreditPeople
            ADD COLUMN Slug VARCHAR(255) NOT NULL DEFAULT ''
            AFTER Name";
    if (!$mysqli->query($sql)) {
        _migCpSlug_out('ERROR: adding Slug failed: ' . $mysqli->error);
        exit(1);
    }
    _migCpSlug_out('[add ] tblCreditPeople.Slug.');
}

/* Step 3: backfill. Iterate every row whose Slug is empty, compute
   a slug from Name, append -2 / -3 etc. on collision so the column
   can later carry a UNIQUE constraint. */
$res = $mysqli->query("SELECT Id, Name FROM tblCreditPeople WHERE Slug = '' OR Slug IS NULL");
$toUpdate = [];
while ($row = $res->fetch_assoc()) {
    $toUpdate[] = ['id' => (int)$row['Id'], 'name' => (string)$row['Name']];
}
$res->close();

if (empty($toUpdate)) {
    _migCpSlug_out('[seed] no rows needed slug backfill.');
} else {
    /* Pre-load existing slugs so we can avoid collisions.

       Two distinct collision sources have to be covered, and the second
       is the one that is easy to miss: a slug already stored on a row
       this run isn't touching (loaded here), and two rows INSIDE this
       run that slugify to the same string — two people both recorded as
       "J. Smith". $taken is therefore seeded from the database and then
       ADDED TO as each candidate is claimed, so the second J. Smith sees
       the first one's claim even though it was never committed at the
       time of the check. Doing this in PHP rather than leaning on the
       unique key is what lets step 4 succeed at all. */
    $taken = [];
    $res2 = $mysqli->query("SELECT Slug FROM tblCreditPeople WHERE Slug <> ''");
    while ($row2 = $res2->fetch_assoc()) {
        $taken[(string)$row2['Slug']] = true;
    }
    $res2->close();

    $update = $mysqli->prepare('UPDATE tblCreditPeople SET Slug = ? WHERE Id = ?');
    $filled = 0;
    foreach ($toUpdate as $entry) {
        $base = _migCpSlug_slugify($entry['name']);
        /* A name made entirely of characters the slugifier strips — CJK
           without ext-intl, or a row whose Name is punctuation — would
           otherwise produce '', which the NOT NULL DEFAULT '' column
           treats as "not yet backfilled" and every later run would try
           again. 'person' gives it a real slug; the collision loop then
           turns the second such row into person-2. */
        if ($base === '') $base = 'person';
        $candidate = $base;
        /* Starts at 1 but is PRE-incremented, so the first duplicate
           becomes "-2" rather than "-1" — the original keeps the bare
           slug and the suffix reads as "the second John Smith", which is
           how a human numbers them. */
        $suffix = 1;
        while (isset($taken[$candidate])) {
            $suffix++;
            $candidate = $base . '-' . $suffix;
        }
        $taken[$candidate] = true;
        $update->bind_param('si', $candidate, $entry['id']);
        $update->execute();
        $filled++;
    }
    $update->close();
    _migCpSlug_out("[seed] backfilled slug on {$filled} row" . ($filled === 1 ? '' : 's') . '.');
}

/* Step 4: enforce UNIQUE on Slug. Done after the backfill so we don't
   trip over duplicate empty strings.

   The key is what makes the slug a usable primary lookup for
   /people/<slug>: without it the page would have to cope with two rows
   answering one URL, and the "slug on every insert" paths added later
   would have nothing stopping them writing a duplicate.

   Caveat on the WARN branch below: `$mysqli->query()` here runs under
   MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT (set by getDbMysqli()), so
   a failing ALTER THROWS mysqli_sql_exception instead of returning
   false. The graceful "a duplicate slipped through" warning is therefore
   unreachable on a strict connection — that case surfaces as an
   uncaught exception the dashboard catches and reports instead. Left as
   found; changing it is a code change, not an annotation. */
$idxRes = $mysqli->query(
    "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'tblCreditPeople'
        AND INDEX_NAME   = 'uk_Slug' LIMIT 1"
);
$idxExists = $idxRes && $idxRes->fetch_row() !== null;
if ($idxRes) $idxRes->close();
if ($idxExists) {
    _migCpSlug_out('[skip] uk_Slug index already present.');
} else {
    if (!$mysqli->query('ALTER TABLE tblCreditPeople ADD UNIQUE KEY uk_Slug (Slug)')) {
        _migCpSlug_out('WARN: could not add uk_Slug unique key (likely a duplicate slipped through): ' . $mysqli->error);
    } else {
        _migCpSlug_out('[add ] tblCreditPeople uk_Slug.');
    }
}

_migCpSlug_out('Credit People slug migration finished.');
/* Don't close $mysqli — it's the shared singleton from getDbMysqli().
   The bulk migration runner in /manage/setup-database.php iterates many
   migrations in one PHP request; closing here would invalidate the
   handle for every subsequent migration that calls getDbMysqli(). PHP
   closes the connection on script exit anyway. */
