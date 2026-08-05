<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Content-Gating No-Op Verifier (#1357 / #1358) — global_admin only
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * The #1 requirement of the content-gating completion is that EVERY change is a
 * BYTE-IDENTICAL NO-OP while content_gating_enabled='0' (the live state on all
 * three shared-DB docroots). This page lets an operator PROVE that on a real env
 * — the operator is web-only (no CLI/SSH), so this is a web harness:
 *
 *   1. "Capture baseline" — for a fixed sample of ~25 SongIds (a mix of
 *      copyrighted / public-domain / has-audio / has-sheet), it renders each
 *      song HTML through the SAME include path bulk_songs uses (buffer
 *      includes/pages/song.php) and sha256s the output, AND builds the
 *      song_detail payload (getSongById → contentGatingApply) and sha256s its
 *      JSON. The map is stored in a tblAppSettings sentinel.
 *
 *   2. "Verify" — re-renders + re-hashes and diffs against the baseline. With
 *      gating off, every row MUST be byte-identical → one green "safe to
 *      proceed". Any drift is shown red.
 *
 *   3. "Audio routes" — HEAD-fetches a few /data/audio/<id>.mp3 and
 *      /audio/<id>.mp3 to confirm the static path still 200s and the deny seal
 *      is NOT active yet.
 *
 * SAFETY: refuses to run when content_gating_enabled='1' (the baseline is only
 * meaningful as the OFF reference). Every DB read is wrapped → themed error card
 * (rule #9). Uses the shared admin partials (modularity rule).
 *
 * @see includes/content_gating.php   contentGatingApply (the no-op under test)
 * @see includes/pages/song.php       the HTML render path under test
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'maintenance.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php'; // appCanonicalHost() for the same-origin probe (security audit)
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'SongData.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_soft_delete.php';   /* #1694 */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'songbook_visibility.php';   /* #1765 */

/* #1769 P4 Commit E (D9) — gate on the manage_configuration entitlement, the
   SAME check its new nav entry advertises (admin-links.php), rather than a raw
   requireGlobalAdmin(). Admittance-identical at the default entitlement map
   (manage_configuration defaults to global_admin only), but now a page and its
   nav link can never drift (#1587) and an operator's override is honoured. */
requireAuth();
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_configuration', $currentUser['role'] ?? null)) {
    http_response_code(403);
    exit('Access denied. The manage_configuration entitlement is required.');
}
$activePage  = 'gating-noop-verify';

const GATING_NOOP_SENTINEL = 'gating_noop_baseline';
const GATING_NOOP_SAMPLE   = 25;

/* ----------------------------------------------------------------------- *
 * Helpers — all DB reads wrapped; the page never throws out to a fatal.
 * ----------------------------------------------------------------------- */

/**
 * Pick a fixed sample of SongIds with a deliberate mix of copyrighted / PD /
 * has-audio / has-sheet so the no-op proof exercises every gated affordance.
 * Deterministic ORDER so "capture" and "verify" hash the SAME songs.
 *
 * @return string[] up to GATING_NOOP_SAMPLE SongIds.
 */
function gatingNoop_sampleIds(\mysqli $db): array
{
    $ids = [];
    /* Four buckets, ~6 each, unioned. Each bucket is a hardcoded WHERE — no
       user input enters the SQL (rule #5). */
    $buckets = [
        'LyricsPublicDomain = 0',                       /* copyrighted */
        'LyricsPublicDomain = 1',                       /* public domain */
        'HasAudio = 1',                                 /* has audio */
        'HasSheetMusic = 1',                            /* has sheet music */
    ];
    $per = (int)ceil(GATING_NOOP_SAMPLE / count($buckets));
    foreach ($buckets as $where) {
        /* #1694 — sample only VISIBLE songs: the harness fetches each sample
           id via the public song_detail path, and a hidden pick would 410 and
           read as a false gating diff. #1765 — same for a song whose
           songbook is disabled: the harness's SongData instances are
           deliberately PUBLIC (see the two `new SongData()` markers below),
           so a disabled-book pick would read as the identical false diff. */
        $sql = "SELECT SongId FROM tblSongs WHERE {$where} AND " . songVisibleSql($db, '') . " AND " . songServableSql($db, '') . " ORDER BY SongId ASC LIMIT {$per}";
        $res = $db->query($sql);
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_row()) {
                $ids[(string)$row[0]] = true;   /* dedupe across buckets */
            }
            $res->free();
        }
    }
    $ids = array_keys($ids);
    sort($ids);                                  /* stable order for hashing */
    return array_slice($ids, 0, GATING_NOOP_SAMPLE);
}

/**
 * Render the song HTML the SAME way bulk_songs does (buffer song.php) and the
 * song_detail JSON (getSongById → contentGatingApply), returning the sha256 of
 * each so a baseline ↔ verify comparison is a cheap string equality.
 *
 * @return array{html:string,json:string}|null  null if the song is missing.
 */
function gatingNoop_hashSong(SongData $songData, string $songId): ?array
{
    $detail = $songData->getSongById($songId);
    if ($detail === null) {
        return null;
    }

    /* HTML — identical include path to bulk_songs (#1284).
       song.php USES an injected $song when one is already in scope (#1598):
       it now guards its own getSongById() behind `if (!isset($song))`, so the
       assignment below is what the render actually consumes.
       It used to re-fetch unconditionally, which is exactly the per-song
       re-hydration #1598 removed — the old comment here said song.php
       "overwrites this from getSongById", which stopped being true the moment
       that guard landed. Behaviour is unchanged either way, because $detail is
       itself the result of a getSongById() call a few lines up; only the
       number of queries differs. */
    $song = $detail;
    ob_start();
    try {
        require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes'
              . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'song.php';
        $html = (string)ob_get_clean();
    } catch (\Throwable $_e) {
        ob_end_clean();
        $html = '__RENDER_ERROR__:' . $_e->getMessage();
    }

    /* JSON — the API gate path (no-op while the flag is off). Anonymous viewer. */
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes'
              . DIRECTORY_SEPARATOR . 'content_gating.php';
    $gated = contentGatingApply($detail, null, 'PWA');
    $json  = (string)json_encode($gated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return [
        'html' => hash('sha256', $html),
        'json' => hash('sha256', $json),
    ];
}

/** Read the stored baseline (decoded), or null when none / unreadable. */
function gatingNoop_readBaseline(\mysqli $db): ?array
{
    try {
        $stmt = $db->prepare('SELECT SettingValue FROM tblAppSettings WHERE SettingKey = ?');
        $key  = GATING_NOOP_SENTINEL;
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        if ($row === null) {
            return null;
        }
        $decoded = json_decode((string)$row[0], true);
        return is_array($decoded) ? $decoded : null;
    } catch (\Throwable $_e) {
        return null;
    }
}

/** Persist the baseline JSON into the sentinel (upsert). */
function gatingNoop_writeBaseline(\mysqli $db, array $baseline): void
{
    $payload = (string)json_encode($baseline, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $desc    = 'Byte-identical no-op baseline for content gating (#1357/#1358 verifier).';
    $stmt = $db->prepare(
        'INSERT INTO tblAppSettings (SettingKey, SettingValue, Description)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)'
    );
    $key = GATING_NOOP_SENTINEL;
    $stmt->bind_param('sss', $key, $payload, $desc);
    $stmt->execute();
    $stmt->close();
}

/* ----------------------------------------------------------------------- *
 * Controller — capture / verify actions.
 * ----------------------------------------------------------------------- */

$pageError   = null;     /* fatal load error → themed card */
$gatingOn     = false;
$action       = isset($_GET['action']) ? (string)$_GET['action'] : '';
$captureResult = null;   /* int count captured */
$verifyRows    = null;   /* per-song diff rows */
$verifyAllSame = null;   /* bool */
$audioProbe    = null;   /* route HEAD results */

try {
    $db = getDbMysqli();
    $gatingOn = (getAppSetting('content_gating_enabled', '0') === '1');

    if (!$gatingOn && in_array($action, ['capture', 'verify'], true)) {
        /* #1765 Feature 1 — deliberately PUBLIC, not SongData::forAdmin():
           this tool verifies that content gating is a byte-identical no-op
           against the PUBLIC payload, so it must see exactly what the
           public site sees — a disabled songbook's songs included in the
           exclusion. */
        $songData = new SongData();
        $ids = gatingNoop_sampleIds($db);

        $current = [];
        foreach ($ids as $sid) {
            $h = gatingNoop_hashSong($songData, $sid);
            if ($h !== null) {
                $current[$sid] = $h;
            }
        }

        if ($action === 'capture') {
            gatingNoop_writeBaseline($db, $current);
            $captureResult = count($current);
        } else { /* verify */
            $baseline = gatingNoop_readBaseline($db);
            if ($baseline === null) {
                $pageError = 'No baseline captured yet — click "Capture baseline" first.';
            } else {
                $verifyRows    = [];
                $verifyAllSame = true;
                $allKeys = array_unique(array_merge(array_keys($baseline), array_keys($current)));
                sort($allKeys);
                foreach ($allKeys as $sid) {
                    $b = $baseline[$sid] ?? null;
                    $c = $current[$sid] ?? null;
                    $htmlSame = ($b !== null && $c !== null && $b['html'] === $c['html']);
                    $jsonSame = ($b !== null && $c !== null && $b['json'] === $c['json']);
                    if (!$htmlSame || !$jsonSame) {
                        $verifyAllSame = false;
                    }
                    $verifyRows[] = [
                        'id'       => $sid,
                        'htmlSame' => $htmlSame,
                        'jsonSame' => $jsonSame,
                        'present'  => ($b !== null && $c !== null),
                    ];
                }
            }
        }
    }

    /* Audio-route probe (always runs) — HEAD a few sample mp3s. Same-origin. */
    if ($action === 'probe-audio' || $action === '') {
        $audioProbe = [];
        /* #1765 Feature 1 — deliberately PUBLIC, same reasoning as above. */
        $songData2  = new SongData();
        $sampleIds  = array_slice(gatingNoop_sampleIds($db), 0, 3);
        $scheme     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        /* SECURITY: allow-listed host, not raw HTTP_HOST — a forged Host would
           otherwise steer this server-side HEAD probe to an arbitrary host
           (blind SSRF). The probe is meant to be same-origin anyway. */
        $host       = appCanonicalHost();
        foreach ($sampleIds as $sid) {
            foreach (['/data/audio/', '/audio/'] as $prefix) {
                $url  = $scheme . '://' . $host . $prefix . rawurlencode($sid) . '.mp3';
                $code = gatingNoop_headStatus($url);
                $audioProbe[] = ['url' => $prefix . $sid . '.mp3', 'status' => $code];
            }
        }
    }
} catch (\Throwable $e) {
    $pageError = 'Could not run the verifier: ' . $e->getMessage();
}

/**
 * HEAD a same-origin URL, returning the HTTP status (or 0 on transport error).
 * Best-effort — used only to display whether the static path still serves and
 * the deny seal isn't active. Short timeout so a missing file/route doesn't hang.
 */
function gatingNoop_headStatus(string $url): int
{
    $ctx = stream_context_create(['http' => [
        'method'        => 'HEAD',
        'timeout'       => 4,
        'ignore_errors' => true,
    ]]);
    $fp = @fopen($url, 'rb', false, $ctx);
    if ($fp === false) {
        /* $http_response_header is populated even on a 4xx/5xx when ignore_errors. */
        return gatingNoop_statusFromHeaders($http_response_header ?? []);
    }
    $meta = stream_get_meta_data($fp);
    fclose($fp);
    return gatingNoop_statusFromHeaders($meta['wrapper_data'] ?? []);
}

/** Extract the HTTP status code from a wrapper_data / response-header array. */
function gatingNoop_statusFromHeaders(array $headers): int
{
    foreach ($headers as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$h, $m)) {
            return (int)$m[1];
        }
    }
    return 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gating No-Op Verifier — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">

        <h1 class="h4 mb-3"><i class="bi bi-shield-check me-2"></i>Gating No-Op Verifier</h1>
        <p class="text-secondary small mb-4">
            Proves the content-gating completion (#1357 / #1358) is a
            <strong>byte-identical no-op</strong> while
            <code>content_gating_enabled='0'</code>. Capture a baseline now, then
            re-run "Verify" after each deploy — every sampled song must hash
            identically. The audio-route probe confirms the static
            <code>/data/audio/&lt;id&gt;.mp3</code> path still serves and the
            <code>#1358</code> deny seal is not active yet.
        </p>

        <?php if ($pageError !== null): ?>
            <div class="alert alert-danger py-2">
                <i class="bi bi-exclamation-octagon me-1"></i>
                <?= htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($gatingOn): ?>
            <div class="alert alert-warning py-2">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <strong>content_gating_enabled='1'</strong> — this verifier only runs
                as the OFF reference (the no-op baseline is meaningless once gating is
                on). Capture/Verify are disabled.
            </div>
        <?php endif; ?>

        <?php if ($captureResult !== null): ?>
            <div class="alert alert-success py-2">
                <i class="bi bi-check-circle me-1"></i>
                Baseline captured for <strong><?= (int)$captureResult ?></strong> songs.
            </div>
        <?php endif; ?>

        <?php if ($verifyAllSame === true): ?>
            <div class="alert alert-success py-2">
                <i class="bi bi-check2-all me-1"></i>
                <strong>BYTE-IDENTICAL — safe to proceed.</strong>
                All <?= count($verifyRows) ?> sampled songs hash identically to the baseline.
            </div>
        <?php elseif ($verifyAllSame === false): ?>
            <div class="alert alert-danger py-2">
                <i class="bi bi-x-octagon me-1"></i>
                <strong>DRIFT DETECTED</strong> — at least one song no longer hashes
                identically. Investigate before proceeding (a no-op was supposed to be
                guaranteed while gating is off).
            </div>
        <?php endif; ?>

        <div class="d-flex flex-wrap gap-2 mb-4">
            <a class="btn btn-sm btn-primary<?= $gatingOn ? ' disabled' : '' ?>"
               href="?action=capture">
                <i class="bi bi-camera me-1"></i>Capture baseline
            </a>
            <a class="btn btn-sm btn-outline-secondary<?= $gatingOn ? ' disabled' : '' ?>"
               href="?action=verify">
                <i class="bi bi-check2-square me-1"></i>Verify
            </a>
            <a class="btn btn-sm btn-outline-secondary" href="?action=probe-audio">
                <i class="bi bi-soundwave me-1"></i>Probe audio routes
            </a>
        </div>

        <?php if ($verifyRows !== null && $verifyRows): ?>
            <h2 class="h6 mb-2">Per-song diff (<?= count($verifyRows) ?> songs)</h2>
            <table class="table table-sm admin-table-responsive mb-4">
                <thead>
                    <tr>
                        <th data-col-priority="primary">SongId</th>
                        <th data-col-priority="secondary">HTML</th>
                        <th data-col-priority="secondary">JSON</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($verifyRows as $r): ?>
                        <tr>
                            <td data-col-priority="primary"><code><?= htmlspecialchars((string)$r['id'], ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td data-col-priority="secondary">
                                <?php if (!$r['present']): ?>
                                    <span class="badge bg-secondary">missing</span>
                                <?php elseif ($r['htmlSame']): ?>
                                    <span class="badge bg-success">identical</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">DIFF</span>
                                <?php endif; ?>
                            </td>
                            <td data-col-priority="secondary">
                                <?php if (!$r['present']): ?>
                                    <span class="badge bg-secondary">missing</span>
                                <?php elseif ($r['jsonSame']): ?>
                                    <span class="badge bg-success">identical</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">DIFF</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($audioProbe !== null && $audioProbe): ?>
            <h2 class="h6 mb-2">Audio-route probe</h2>
            <p class="text-secondary small mb-2">
                <code>/data/audio/</code> should still return 200/206 (static serve
                intact, seal not active). <code>/audio/</code> returns 404 until the
                file exists + the rewrite is deployed — that's expected while signing
                is off and nothing emits those URLs.
            </p>
            <table class="table table-sm admin-table-responsive mb-4">
                <thead>
                    <tr>
                        <th data-col-priority="primary">URL</th>
                        <th data-col-priority="secondary">HTTP status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($audioProbe as $p): ?>
                        <tr>
                            <td data-col-priority="primary"><code><?= htmlspecialchars((string)$p['url'], ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td data-col-priority="secondary">
                                <?php $st = (int)$p['status']; ?>
                                <span class="badge <?= ($st >= 200 && $st < 300) ? 'bg-success' : 'bg-secondary' ?>">
                                    <?= $st === 0 ? 'no response' : $st ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    </div>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>

</body>
</html>
