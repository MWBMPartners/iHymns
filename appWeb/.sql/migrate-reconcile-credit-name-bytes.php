<?php

declare(strict_types=1);

/**
 * iHymns — Reconcile legacy credit-name BYTES against the registry (#1784).
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * The Credit People / Musicians list page reports "N people are credited on a
 * song but aren't saved to the registry yet". That counter could get STUCK
 * above zero forever — the weeks-old "Eddie James" bug. The cause is invisible
 * bytes: a song-credit row (e.g. tblSongWriters.Name = "Eddie James " with a
 * trailing space, or an NBSP instead of a normal space) and its registry row
 * (tblMusicians.Name = "Eddie James") hold the SAME name spelled with different
 * bytes. The list page links a credit name to its registry row by EXACT byte
 * match, so it never links these two and the person shows as "In use" forever.
 * The "Merge into Eddie James" button couldn't fix it either — it trimmed the
 * candidate, found it now equalled the target, and skipped as "nothing to do".
 *
 * New credits do NOT hit this: every write path (editor credit_upsert / save,
 * bulk importers, lyrics ingest) trims the name before both the credit-table
 * insert and the registry upsert (creditEntryNormalise() → musicianPromote()),
 * so their bytes already agree. This is purely a LEGACY-data clean-up for rows
 * written before that trimming was enforced.
 *
 * WHAT IT DOES (delegates to the ONE shared helper, rule #22):
 *   musicianReconcileCreditNameBytes() — for every distinct cited name with no
 *   byte-exact registry row:
 *     (a) exactly one registry row collation-matches → adopt its exact spelling
 *         into the credit tables (the Eddie James case);
 *     (b) no registry row matches → normalise the bytes and auto-register;
 *     (c) two+ registry rows collation-match → left for the registry-dedup UX
 *         (#1785) and reported as 'ambiguous'.
 *   Every operation is identity-preserving (collation-equal / whitespace / NFC
 *   are "the same person") — it can NEVER merge two DISTINCT people.
 *
 * Data-only — creates no table and adds no column, so there is nothing to mirror
 * into schema.sql (rule #19 covers DDL only). Idempotent: a second run finds
 * nothing because every cited name now byte-matches a registry row (which is
 * also what the migration probe checks — rule #35, one shared definition).
 *
 * @migration-updates tblSongWriters.Name
 * @migration-updates tblSongComposers.Name
 * @migration-updates tblSongArrangers.Name
 * @migration-updates tblSongAdaptors.Name
 * @migration-updates tblSongTranslators.Name
 *
 * USAGE:
 *   Web:  /manage/setup-database → Apply all pending migrations
 *   CLI:  php appWeb/.sql/migrate-reconcile-credit-name-bytes.php
 */

if (PHP_SAPI === 'cli') {
    if (!function_exists('getDbMysqli')) {
        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    }
    $isCli = true;
} else {
    if (!defined('IHYMNS_SETUP_DASHBOARD')) {
        if (!function_exists('getDbMysqli')) {
            require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
        }
    }
    $isCli = false;
}

/* The shared reconciliation core (musicianReconcileCreditNameBytes /
   musicianCanonicalNameBytes / registerMusicianByName) — never re-forked
   here (rule #22 / #37). Resolve includes/ via the runner's real docroot: the
   deployed docroot is renamed per channel (public_html_dev/_beta), so a literal
   '/public_html/includes/…' is WRONG off main (the #1196 deploy-path trap —
   this is the require that failed on alpha as "Failed opening required
   musician_helpers.php"). Repo fallback for a standalone/CLI or test run. */
$_incDir = defined('IHYMNS_INCLUDES_DIR')
    ? IHYMNS_INCLUDES_DIR
    : dirname(__DIR__) . '/public_html/includes';
require_once $_incDir . '/musician_helpers.php';

function _migCreditBytes_out(string $line): void
{
    if (PHP_SAPI === 'cli') {
        echo $line . "\n";
    } else {
        echo htmlspecialchars($line, ENT_QUOTES) . "<br>\n";
    }
}

_migCreditBytes_out('Credit-name byte reconciliation starting…');

$db = getDbMysqli();
if (!$db) {
    _migCreditBytes_out('ERROR: could not connect to database.');
    if ($isCli) { exit(1); } else { return; }
}

/* Wrap the whole sweep in a transaction so a partial failure (e.g. a UNIQUE
   collision from a concurrent editor) rolls back cleanly rather than leaving
   the credit tables half-rewritten. The helper opens NO transaction of its own
   for exactly this reason. */
$db->begin_transaction();
try {
    $report = musicianReconcileCreditNameBytes($db);
    $db->commit();
} catch (\Throwable $e) {
    $db->rollback();
    _migCreditBytes_out('ERROR: reconciliation failed, rolled back — ' . $e->getMessage());
    if ($isCli) { exit(1); } else { return; }
}

_migCreditBytes_out(sprintf(
    '[scan] %d cited name%s had no byte-exact registry match.',
    $report['scanned'], $report['scanned'] === 1 ? '' : 's'
));
_migCreditBytes_out(sprintf(
    '[fix ] %d credit row%s rewritten to the canonical spelling; '
        . '%d name%s adopted an existing registry spelling; %d newly registered.',
    $report['rewritten'], $report['rewritten'] === 1 ? '' : 's',
    $report['adopted'],   $report['adopted']   === 1 ? '' : 's',
    $report['registered']
));

if ($report['ambiguous'] > 0) {
    _migCreditBytes_out(sprintf(
        '[warn] %d name%s left untouched — the registry itself has two rows that '
            . 'collation-match, so the canonical spelling is ambiguous. Resolve '
            . 'these on the registry-dedup review page (#1785).',
        $report['ambiguous'], $report['ambiguous'] === 1 ? '' : 's'
    ));
    foreach ($report['names'] as $n) {
        if (($n['action'] ?? '') === 'ambiguous') {
            _migCreditBytes_out('       ambiguous: "' . (string)$n['name'] . '"');
        }
    }
}

if ($report['scanned'] === 0) {
    _migCreditBytes_out('[ok  ] nothing to do — every cited name already byte-matches a registry row.');
} else {
    _migCreditBytes_out('[note] Nothing to regenerate — song reads are DB-direct (WS-J #1020); '
        . 'the Credit People / Musicians counter reflects the new bytes on next page load.');
}
