<?php

declare(strict_types=1);

/**
 * iHymns — Reconcile HasAudio / HasSheetMusic (#1862, epic #1863)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * A one-time reconcile pass over every `tblSongs` row, recomputing
 * `HasAudio` / `HasSheetMusic` as the UNION of (a) a hosted `tblSongMedia`
 * row of the right kind and (b) the legacy static files
 * (`/data/audio/<id>.mid|.mp3`, `/data/music/<id>.pdf`) — the SAME
 * derivation `includes/song_media_flags.php`'s `songMediaRecomputeFlags()`
 * runs incrementally from every media upload/delete hook. This script
 * exists because the *static-file* half of that union is only trustworthy
 * when run from a docroot that actually holds the corpus.
 *
 * WHY THIS IS SEPARATE FROM THE INCREMENTAL HOOKS, AND WHY IT IS MANUAL:
 * alpha/beta/production SHARE ONE DATABASE, but each channel's docroot has
 * its OWN `/data/audio` + `/data/music` filesystem — only the production
 * docroot is guaranteed to hold the full historical corpus (dev/beta may
 * hold a partial mirror, or none). Running a reconcile from a docroot
 * MISSING those files would wrongly CLEAR flags for songs whose
 * audio/sheet-music genuinely lives only as a static file — the exact
 * "silently kills the public Audio/Sheet buttons for the scrape-era corpus"
 * failure `song_media_flags.php`'s header warns about, just triggered by
 * running the reconcile in the wrong place instead of by dropping the
 * union. So this card is registered `'manual' => true` (excluded from
 * "Apply all" and the pending counter, the `backfill-canonical-songids`
 * precedent) and must be run BY HAND, from the production docroot, after
 * verifying `/data/audio` + `/data/music` are actually populated there.
 *
 * SAFETY GATES (defense in depth, mirroring backfill-canonical-songids.php):
 *   - Registry 'manual' => true + 'dryRunnable' => true.
 *   - DRY-RUN BY DEFAULT. A web run without ?confirm=1 (or a CLI run
 *     without --confirm) only REPORTS — counts + a capped sample of songs
 *     whose flags would flip, each direction. It NEVER mutates.
 *   - IDEMPOTENT: re-running finds fewer (or zero) songs needing a flip and
 *     is a no-op for the rest; the underlying write
 *     (`songMediaRecomputeFlags()`) is itself write-if-changed.
 *   - Data-only — NO schema.sql change (rule #19 imposes nothing here).
 *   - On a CONFIRMED apply, writes a `media_flags_reconciled_at` sentinel
 *     row into `tblAppSettings` — the registry probe's completion signal
 *     (a data-only pass has no schema signal of its own, the
 *     'publishers-entity' backfill idiom).
 *
 * Rule #41 — resolves the shared includes/ directory (and, from it, the
 * docroot the corpus check below inspects) via `IHYMNS_INCLUDES_DIR` when
 * running inside the setup-database runner; the literal
 * `dirname(__DIR__) . '/public_html'` is the standalone/CLI repo fallback
 * ONLY — never an unconditional `/public_html/` literal require.
 *
 * USAGE:
 *   Web (dry-run):  /manage/setup-database -> "Reconcile HasAudio/HasSheetMusic (#1862)"
 *   Web (apply):    add &confirm=1 to the run URL
 *   CLI (dry-run):  php appWeb/.sql/migrate-reconcile-media-flags.php
 *   CLI (apply):    php appWeb/.sql/migrate-reconcile-media-flags.php --confirm
 *
 * @requires PHP 8.1+ with mysqli
 * @see appWeb/public_html/includes/song_media_flags.php          the ONE kind-map + recompute core this reuses
 * @see appWeb/.sql/migrate-backfill-canonical-songids.php        the dry-run/confirm gate shape this mirrors
 * @see appWeb/public_html/manage/includes/migration-registry.php 'reconcile-media-flags' entry + probe
 * @see #1862, epic #1863
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migRecMedia_out(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) { @flush(); } }

/* CONFIRM resolution: CLI uses --confirm; web uses ?confirm=1. Anything else
   is a DRY RUN (report only) — the default-safe stance. */
$confirm = false;
if ($isCli) {
    $confirm = in_array('--confirm', $argv ?? [], true);
} else {
    $confirm = (($_GET['confirm'] ?? '') === '1');
}
$dryRun = !$confirm;

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migRecMedia_out("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

/* Rule #41 — see this file's header. dirname(__DIR__) (this script's own
   .sql/ sibling) is correct on every channel already; it's a literal
   '/public_html/' appended after it that would be wrong off main. */
$_incDir = defined('IHYMNS_INCLUDES_DIR')
    ? IHYMNS_INCLUDES_DIR
    : dirname(__DIR__) . '/public_html/includes';
require_once $_incDir . '/song_media_flags.php';
require_once $_incDir . '/maintenance.php';   /* setAppSetting() for the completion sentinel */

_migRecMedia_out("");
_migRecMedia_out("=== iHymns — Reconcile HasAudio/HasSheetMusic (#1862) ===");
_migRecMedia_out($dryRun ? "Mode: DRY RUN (report only — pass ?confirm=1 / --confirm to apply)" : "Mode: APPLY");
_migRecMedia_out("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migRecMedia_out("ERROR: MySQL connection failed: " . $e->getMessage()); return;
}
_migRecMedia_out("Connected to MySQL: " . DB_NAME);

/* Docroot sanity check — WARN, never block. A dry-run report from any
   docroot is harmless; a CONFIRMED apply from a docroot missing the corpus
   would wrongly clear flags for statically-hosted songs (see header). An
   operator deliberately testing against a partial mirror can still proceed. */
$_docroot     = dirname($_incDir);
$dataAudioDir = $_docroot . '/data/audio';
$dataMusicDir = $_docroot . '/data/music';
if (!is_dir($dataAudioDir) || !is_dir($dataMusicDir)) {
    _migRecMedia_out("  [WARN] '/data/audio' and/or '/data/music' not found under this docroot ({$_docroot}).");
    _migRecMedia_out("         This reconcile is meant to run from the PRODUCTION docroot, which holds the");
    _migRecMedia_out("         full media corpus. Continuing anyway — but a CONFIRMED apply from a docroot");
    _migRecMedia_out("         missing these files would wrongly clear flags for statically-hosted songs.");
    _migRecMedia_out("");
}

try {
    $mediaTableExists = false;
    try {
        $r = $mysql->query("SHOW TABLES LIKE 'tblSongMedia'");
        $mediaTableExists = (bool)($r && $r->num_rows > 0);
    } catch (\Throwable $_e) { $mediaTableExists = false; }

    $kinds    = songMediaFlagKinds();
    $allKinds = array_values(array_unique(array_merge($kinds['HasAudio'], $kinds['HasSheetMusic'])));

    $chunkSize  = 500;
    $lastId     = 0;
    $total      = 0;
    $flippedOn  = 0;
    $flippedOff = 0;
    $sampleOn   = [];
    $sampleOff  = [];
    $sampleCap  = 25;

    while (true) {
        $stmt = $mysql->prepare(
            'SELECT Id, SongId, HasAudio, HasSheetMusic FROM tblSongs WHERE Id > ? ORDER BY Id ASC LIMIT ?'
        );
        $stmt->bind_param('ii', $lastId, $chunkSize);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (!$rows) { break; }

        foreach ($rows as $row) {
            $songId = (string)$row['SongId'];
            $lastId = (int)$row['Id'];
            $total++;

            /* Reuses the ONE kind map + static-path resolver (rule #22) —
               never a re-forked "would it change" query. */
            $hasAudioMedia = false;
            $hasSheetMedia = false;
            if ($mediaTableExists && $allKinds) {
                $placeholders = implode(',', array_fill(0, count($allKinds), '?'));
                $ms = $mysql->prepare(
                    "SELECT DISTINCT Kind FROM tblSongMedia WHERE SongId = ? AND Kind IN ({$placeholders})"
                );
                $types  = 's' . str_repeat('s', count($allKinds));
                $params = array_merge([$songId], $allKinds);
                $ms->bind_param($types, ...$params);
                $ms->execute();
                $mres = $ms->get_result();
                $foundKinds = [];
                while ($mrow = $mres->fetch_row()) { $foundKinds[] = (string)$mrow[0]; }
                $ms->close();
                $hasAudioMedia = (bool)array_intersect($foundKinds, $kinds['HasAudio']);
                $hasSheetMedia = (bool)array_intersect($foundKinds, $kinds['HasSheetMusic']);
            }

            $paths          = songMediaStaticPaths($songId);
            $hasAudioStatic = is_file($paths['audioMid']) || is_file($paths['audioMp3']);
            $hasSheetStatic = is_file($paths['sheetPdf']);

            $wantAudio = ($hasAudioMedia || $hasAudioStatic) ? 1 : 0;
            $wantSheet = ($hasSheetMedia || $hasSheetStatic) ? 1 : 0;
            $curAudio  = (int)$row['HasAudio'];
            $curSheet  = (int)$row['HasSheetMusic'];

            $changed = false;
            if ($wantAudio !== $curAudio) {
                $changed = true;
                if ($wantAudio === 1) {
                    $flippedOn++;
                    if (count($sampleOn) < $sampleCap) { $sampleOn[] = "{$songId}: HasAudio {$curAudio} -> {$wantAudio}"; }
                } else {
                    $flippedOff++;
                    if (count($sampleOff) < $sampleCap) { $sampleOff[] = "{$songId}: HasAudio {$curAudio} -> {$wantAudio}"; }
                }
            }
            if ($wantSheet !== $curSheet) {
                $changed = true;
                if ($wantSheet === 1) {
                    $flippedOn++;
                    if (count($sampleOn) < $sampleCap) { $sampleOn[] = "{$songId}: HasSheetMusic {$curSheet} -> {$wantSheet}"; }
                } else {
                    $flippedOff++;
                    if (count($sampleOff) < $sampleCap) { $sampleOff[] = "{$songId}: HasSheetMusic {$curSheet} -> {$wantSheet}"; }
                }
            }
            if ($changed && !$dryRun) {
                songMediaRecomputeFlags($mysql, $songId);
            }
        }
        _migRecMedia_out("  ... scanned {$total} song(s) so far (through Id {$lastId})");
    }

    _migRecMedia_out("");
    _migRecMedia_out("--- Summary ---");
    _migRecMedia_out("  Songs scanned:        {$total}");
    _migRecMedia_out("  Flag(s) turning ON:   {$flippedOn}");
    foreach ($sampleOn as $s) { _migRecMedia_out("    + {$s}"); }
    _migRecMedia_out("  Flag(s) turning OFF:  {$flippedOff}");
    foreach ($sampleOff as $s) { _migRecMedia_out("    - {$s}"); }

    if ($dryRun) {
        _migRecMedia_out("");
        _migRecMedia_out("Dry run complete — no changes written. Pass ?confirm=1 (web) or --confirm (CLI) to apply.");
    } else {
        setAppSetting($mysql, 'media_flags_reconciled_at', gmdate('Y-m-d H:i:s') . ' UTC');
        _migRecMedia_out("");
        _migRecMedia_out("[OK] Reconcile applied; sentinel written to tblAppSettings.");
    }
} catch (\Throwable $e) {
    _migRecMedia_out("  [ERROR] " . $e->getMessage());
}
$mysql->close();
return;
