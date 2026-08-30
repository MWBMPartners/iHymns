<?php

declare(strict_types=1);

/**
 * iHymns — Web-Accessible Database Setup & Migration Dashboard
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Web-accessible entry point for database setup and migration.
 * Calls the migration processor scripts in appWeb/.sql/ via PHP require.
 * Located in /manage/ so it's protected by admin authentication.
 *
 * USAGE:
 *   Navigate to: /manage/setup-database.php
 *   Actions:
 *     ?action=install   — Create database tables from schema.sql
 *     ?action=users     — Migrate users/setlists from SQLite/JSON
 *     ?action=cleanup   — Clean up expired tokens
 *
 *   (#1614 — the legacy songs.json bootstrap importer's ?action=migrate was retired;
 *   a fresh install now uses Install Tables (schema.sql) → "Apply all pending" registry
 *   cards → content via the editor's bulk importers, or restore.php from a real backup.)
 *
 *   POST action=save-credentials — Write .auth/db_credentials.php from form
 *
 * SECURITY:
 *   Protected by /manage/.htaccess (session auth required).
 *   Requires global_admin role, OR initial setup (no users exist).
 */

/* =========================================================================
 * AUTHENTICATION
 * ========================================================================= */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';

$isInitialSetup = needsSetup();

if (!$isInitialSetup) {
    if (!isAuthenticated()) {
        header('Location: /manage/login');
        exit;
    }
    $currentUser = getCurrentUser();
    /* Gate on the SAME entitlement the nav advertises (#1648 item 1).
       ELI5: the menu says this page needs the "run_db_install" permission, so
       the page must check that permission, not a hardcoded role.
       Detail: this compared role !== 'global_admin' while admin-links.php:107
       advertises `run_db_install`, which is admin-editable at runtime. The
       default map for run_db_install is exactly ['global_admin'], so this is a
       no-op today and only diverges once someone overrides it — which is the
       whole point of having an override UI. Rule #1587's red flag.
       Note the $isInitialSetup branch above deliberately bypasses this: on a
       virgin install there is no user table to have a role in yet. */
    if (!$currentUser || !userHasEntitlement('run_db_install', $currentUser['role'] ?? null)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="en"><body><h1>403 — the run_db_install entitlement is required</h1></body></html>';
        exit;
    }
}

$activePage = 'setup-database';

/* =========================================================================
 * LOAD DATABASE CREDENTIALS
 * ========================================================================= */

$credDir  = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.auth';
$credFile = $credDir . DIRECTORY_SEPARATOR . 'db_credentials.php';
$hasCredentials = file_exists($credFile);

if ($hasCredentials && !defined('DB_HOST')) {
    require_once $credFile;
}

/* =========================================================================
 * SECRET ENCRYPTION-AT-REST — admin/migration layer (#1466)
 * ============================================================================
 * ELI5: this loads the "toolbox" that knows how to check + change the
 * lock-and-key system protecting secrets (email passwords, API keys, the
 * Sign-in-with-Apple .p8) stored in the database. The Global-Admin panel
 * further down this file (search "Secret encryption (#1466)") and the two
 * POST actions a little further below (`secret_generate_key` /
 * `secret_rotate`) both call INTO this module — nothing here re-implements
 * crypto or DB access.
 *
 * DETAILED / WHY:
 * `secret_crypto_admin.php` transitively requires the pure crypto engine
 * (`includes/secret_crypto.php`) and adds the DB-touching status/mutation
 * helpers this page needs (`secretSentinelStatus()`, `secretInventory()`,
 * `secretRotateReencrypt()`, …). It is required here — once, near the top —
 * rather than lazily where first used, so BOTH the early POST dispatcher
 * (which must run before this page's normal HTML render) and the later
 * dashboard render (which reads the status) share the SAME single load, per
 * the repo's modularity rule (never duplicate an include). Full design:
 * `.claude/secret-encryption-strategy.md` §8 (fingerprint canary), §10
 * (hardening the rotate card), §10b (this exact panel's spec).
 *
 * DEFENSIVE LOAD (#1466 review finding 11): unlike most of this page's
 * includes, this one used to be an UNCONDITIONAL `require_once` — meaning a
 * docroot that received a lagging deploy WITHOUT this file yet (the three
 * docroots roll out independently) would fatal on the very FIRST line of
 * THIS page, before anything else ran. That is exactly backwards: this is
 * the migration dashboard, the tool an operator opens to DIAGNOSE a broken
 * or partial deploy, so it must be the LAST thing to go dark, not the
 * first. `is_file()`-gated exactly like `includes/maintenance.php` gates its
 * own load of `secret_crypto.php` (search that file for "DEFENSIVELY") —
 * when the module is missing, every helper function below is simply
 * undefined, so the POST dispatcher and panel-render blocks further down
 * both re-check `function_exists('secretCryptoReady')` before touching any
 * of them, and the rest of the dashboard (Install Tables, Migrate Users,
 * Backup, …) renders completely unaffected.
 * ========================================================================= */
$secretAdminFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'secret_crypto_admin.php';
if (is_file($secretAdminFile)) {
    require_once $secretAdminFile;
}

/* #2005 — the "Guided setup" empty-state launcher (below) reuses the SHARED
   #1999 partial rather than hand-rolling its own "get started" card
   (CLAUDE.md's modularity rule — reuse, don't fork). Same defensive
   `is_file()` load as the secret-admin block just above: this dashboard
   must still render on a docroot that received a lagging deploy without
   this file yet, and `ihymns_wizard_empty_state` is only ever CALLED
   further down behind its own `function_exists()` check. */
$wizardEmptyStateFile = __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'wizard-empty-state.php';
if (is_file($wizardEmptyStateFile)) {
    require_once $wizardEmptyStateFile;
}

/* =========================================================================
 * SAVE CREDENTIALS (POST) — writes appWeb/.auth/db_credentials.php
 * ========================================================================= */

$credFormValues = [
    'host'    => defined('DB_HOST') ? DB_HOST : '127.0.0.1',
    'port'    => defined('DB_PORT') ? (string)DB_PORT : '3306',
    'name'    => defined('DB_NAME') ? DB_NAME : 'ihymns',
    'user'    => defined('DB_USER') ? DB_USER : 'ihymns_user',
    'pass'    => '',
    'prefix'  => defined('DB_PREFIX') ? DB_PREFIX : '',
];
$credError   = '';
$credSuccess = '';

/* =========================================================================
 * SECRET ENCRYPTION-AT-REST — POST actions (#1466, strategy §10 / §10b)
 * ============================================================================
 * ELI5: these are the only two buttons on the "Secret encryption" panel
 * (further down this page) that actually change something: "make me a new
 * key" and "swap every secret over to a new key." Because rotate touches
 * every secret's plaintext in one pass (strategy §10 calls it the single
 * highest-value endpoint this whole feature introduces), it gets its OWN
 * small, early gate here — checked BEFORE this page's normal `?action=` GET
 * dispatch and BEFORE the blanket form-CSRF gate below, so the panel's
 * fetch()-based JSON POST never has to touch either of those.
 *
 * DETAILED / WHY:
 * Mirrors manage/api-keys.php's dispatcher shape (JSON in / JSON out, a
 * small $_POST['action'] allow-list, validateCsrfRequest() instead of the
 * stale-prone baked-token validateCsrf()) rather than this page's `?action=`
 * GET convention, because §10 explicitly calls for RE-AUTH + a robust CSRF
 * check on the rotate endpoint — a GET link can't safely carry a password.
 * `secret_generate_key` NEVER writes anything, not even the .auth/ file — it
 * is deliberately "a generator, not a writer" so it can't reintroduce the
 * web-writable-.auth/ primitive the strategy's threat model rejected (see
 * strategy §1, "why not the rejected options"). `secret_rotate`
 * re-authenticates the CALLER's password (not merely their live session)
 * before touching any plaintext, requires a literal "ROTATE" type-to-confirm
 * (mirrors the drop-legacy / destructive-migration type-to-confirm
 * convention used elsewhere on this page), and refuses to run without a
 * loaded master key. Every branch answers in JSON and `exit`s immediately,
 * so none of this page's HTML ever renders for these two actions.
 * Refs: manage/api-keys.php (the pattern mirrored); auth.php::validateCsrfRequest().
 * ========================================================================= */
/* function_exists() guard (#1466 review finding 11): only dispatch these two
   actions when secret_crypto_admin.php actually loaded above (is_file()-gated
   now, since a lagging-deploy docroot may not have the file yet). Without
   this guard, a docroot missing the module would fatal on the very first
   secretCryptoReady() call inside the branch instead of falling through to
   this page's normal ?action= GET handling / 404-ish no-op. */
if (function_exists('secretCryptoReady')
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($_POST['action'] ?? '', ['secret_generate_key', 'secret_rotate'], true)) {
    header('Content-Type: application/json; charset=UTF-8');

    /* Same-origin-robust CSRF check (never the stale-prone baked session
       token alone) — see auth.php::validateCsrfRequest() doc-block. */
    if (!validateCsrfRequest((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF check failed — please retry.']);
        exit;
    }

    /* Re-confirm Global Admin. The top-of-file gate (lines ~38-49) already
       enforces this for the page LOAD, but a state-changing endpoint checks
       again independently — defence in depth, and it also future-proofs the
       (currently impossible) case of this dispatcher running during
       $isInitialSetup, when $currentUser was never assigned at all. */
    if ($isInitialSetup || !isset($currentUser) || !$currentUser
        || ($currentUser['role'] ?? null) !== 'global_admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Global Admin access required.']);
        exit;
    }

    $secretPostAction = (string)$_POST['action'];

    try {
        if ($secretPostAction === 'secret_generate_key') {
            /* CSPRNG 32 bytes = 64 hex chars — exactly what
               secrets_master_key.php's 'keys' map expects (see
               secret_crypto.php's _secretCryptoNormalise()
               /^[0-9a-fA-F]{64}$/ check). Returned ONCE, never persisted
               anywhere server-side — the operator copies it into the
               SECRETS_MASTER_KEY GitHub Secret and/or SFTPs it in by hand. */
            $newKey = bin2hex(random_bytes(32));
            if (function_exists('logActivity')) {
                try {
                    logActivity('secret.generate_key', 'app_setting', '', [
                        'note' => 'master key generated for display — not stored',
                    ]);
                } catch (\Throwable $_e) { /* audit is best-effort */ }
            }
            echo json_encode(['success' => true, 'key' => $newKey]);
            exit;
        }

        if ($secretPostAction === 'secret_rotate') {
            /* (a) RE-AUTH BRUTE-FORCE LOCKOUT (#1466 review findings 2/5) —
               a PER-ADMIN lockout (5 wrong Rotate passwords in 15 minutes),
               keyed on a namespaced 'secret_rotate:<username>' sentinel in the
               Username column. CRITICAL: the failure rows written below use an
               EMPTY IpAddress on purpose. tblLoginAttempts is shared with the
               normal login-lockout counters (manage attemptLogin + several in
               api.php), and EVERY one of those counts by the real client IP
               (some without any Username filter) — so writing a REAL IP here
               would let failed rotate re-auths inflate those IP-wide LOGIN
               lockouts and lock a whole shared NAT out of /manage/login (the
               #1466 review's confirmed self-DoS finding). An empty IpAddress
               makes these rows invisible to every IP-keyed login counter (they
               all bind a non-empty REMOTE_ADDR and guard `!== ''`), while the
               ':'-bearing sentinel Username — usernames are [A-Za-z0-9_.-], so
               they can never contain ':' — drives THIS per-admin lockout via a
               Username-only count.
               ELI5: "how many times has THIS admin fumbled the Rotate password
               in 15 minutes?" — counted entirely on its own, never mixed into
               the normal login lockout in either direction. Existence-tolerant:
               a missing tblLoginAttempts (older install) degrades to no-lockout
               (never a 500), like every other optional-table read on this page. */
            $reauthUsername = (string)($currentUser['username'] ?? '');
            $reauthSentinel = 'secret_rotate:' . $reauthUsername;
            $reauthLocked   = false;
            if ($reauthUsername !== '') {
                try {
                    $lockStmt = getDbMysqli()->prepare(
                        'SELECT COUNT(*) FROM tblLoginAttempts
                         WHERE Username = ? AND Success = 0
                           AND AttemptedAt > DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
                    );
                    $lockStmt->bind_param('s', $reauthSentinel);
                    $lockStmt->execute();
                    $reauthFailures = (int)($lockStmt->get_result()->fetch_row()[0] ?? 0);
                    $lockStmt->close();
                    $reauthLocked = $reauthFailures >= 5;
                } catch (\Throwable $_e) {
                    $reauthLocked = false; // missing table / transient DB hiccup → no lockout
                }
            }
            if ($reauthLocked) {
                http_response_code(429);
                echo json_encode(['error' => 'Too many failed attempts — wait 15 minutes.']);
                exit;
            }

            /* (b) RE-AUTH — the admin's CURRENT password, not just a live
               session, must be supplied. Mirrors api.php's username-change
               re-auth (SELECT PasswordHash … ; password_verify()). */
            $reauthPw     = (string)($_POST['reauth_password'] ?? '');
            $reauthUserId = (int)($currentUser['id'] ?? 0);
            $reauthOk     = false;
            if ($reauthPw !== '' && $reauthUserId > 0) {
                $pwStmt = getDbMysqli()->prepare('SELECT PasswordHash FROM tblUsers WHERE Id = ?');
                $pwStmt->bind_param('i', $reauthUserId);
                $pwStmt->execute();
                $pwRow = $pwStmt->get_result()->fetch_assoc();
                $pwStmt->close();
                $reauthOk = (bool)$pwRow && password_verify($reauthPw, (string)$pwRow['PasswordHash']);
            }
            if (!$reauthOk) {
                /* Record the failed attempt against the NAMESPACED sentinel
                   (never the real username) so the lockout counter above can
                   see it — mirrors attemptLogin()'s own failure-recording,
                   existence-tolerant for the same reason as the read above. */
                if ($reauthUsername !== '') {
                    try {
                        /* EMPTY IpAddress on purpose (see the lockout comment
                           above): counts toward THIS per-admin rotate lockout via
                           the sentinel Username, but stays invisible to every
                           IP-keyed LOGIN counter, so a failed rotate re-auth can
                           never inflate the shared login lockout. */
                        $failStmt = getDbMysqli()->prepare(
                            'INSERT INTO tblLoginAttempts (IpAddress, Username, Success) VALUES (?, ?, 0)'
                        );
                        $emptyIp = '';
                        $failStmt->bind_param('ss', $emptyIp, $reauthSentinel);
                        $failStmt->execute();
                        $failStmt->close();
                    } catch (\Throwable $_e) { /* existence-tolerant — see lockout read above */ }
                }
                if (function_exists('logActivity')) {
                    try {
                        logActivity('secret.rotate_reauth_fail', 'app_setting', '', [], 'failure');
                    } catch (\Throwable $_e) { /* audit is best-effort */ }
                }
                http_response_code(403);
                echo json_encode(['error' => 'Re-authentication failed — incorrect password.']);
                exit;
            }

            /* (c) Type-to-confirm — same speed-bump convention as the
               drop-legacy / destructive-migration cards elsewhere on this
               page, enforced SERVER-side (the client-side JS check below is
               a UX nicety only, never the real gate). */
            if (($_POST['confirm_phrase'] ?? '') !== 'ROTATE') {
                http_response_code(400);
                echo json_encode(['error' => 'Type ROTATE to confirm.']);
                exit;
            }

            /* (d) A master key must actually be loaded on THIS docroot —
               secretRotateReencrypt() would throw on this anyway, but
               checking first gives a clearer, non-500 message. */
            if (!secretCryptoReady()) {
                http_response_code(400);
                echo json_encode(['error' => 'No master key is loaded on this docroot — cannot rotate.']);
                exit;
            }

            /* (e) CROSS-DOCROOT PARITY GATE (#1466 review findings 1/4/7 —
               the HIGH finding; strategy §10b) — the code enforcement of
               "Rotate stays disabled until all 3 docroots show the new
               keyid." Without this, this panel's own re-auth + type-to-
               confirm gates only prove the OPERATOR meant to rotate; they
               prove nothing about whether beta/production actually hold the
               new key yet. secretRotationParity() reads the SAME
               tblAppSettings beacon row every docroot's panel render writes
               (secretKeyBeaconWrite(), called below on the render path) and
               fails closed — `ok=false` — unless EVERY env reports a fresh
               beacon that already lists the keyid rotation is about to move
               everything TO. Skipping straight to secretRotateReencrypt()
               without this check is exactly the "we THOUGHT all three were
               caught up" gap that let the pre-DB-direct SongbookName code
               run stale against the shared DB unnoticed
               (`.claude/project_three_docroots_prod_stale_songbookname`). */
            $rotateTargetKeyid = secretActiveKeyId();
            $parity = secretRotationParity(getDbMysqli(), (string)$rotateTargetKeyid);
            if (!$parity['ok']) {
                http_response_code(409);
                echo json_encode([
                    'error' => 'Cross-docroot parity not met. These docroots have NOT confirmed they hold the '
                        . 'target keyid "' . $rotateTargetKeyid . '": ' . implode(', ', $parity['missing'])
                        . '. Add the new key on each, open its /manage/setup-database Secret-encryption panel '
                        . '(that records its beacon), and retry — rotating now would strand them (they could not '
                        . 'decrypt the re-wrapped secrets).',
                    'parity' => $parity,
                ]);
                exit;
            }

            /* (f) The audit closure — key NAMES + keyids only, NEVER a
               plaintext/ciphertext value (strategy §10's hard requirement).
               @-suppressed exactly like the rest of this page's best-effort
               audit calls; logActivity() itself never throws. */
            $audit = static fn(string $a, string $k, array $d): mixed =>
                (function_exists('logActivity') ? @logActivity($a, 'app_setting', $k, $d) : null);

            $rotateResult = secretRotateReencrypt(getDbMysqli(), $audit);

            if (function_exists('logActivity')) {
                try {
                    logActivity('secret.rotate', 'app_setting', '', [
                        'rewrapped'     => count($rotateResult['rewrapped']),
                        'skipped'       => count($rotateResult['skipped']),
                        'undecryptable' => count($rotateResult['undecryptable']),
                    ], 'success', $reauthUserId);
                } catch (\Throwable $_e) { /* audit is best-effort */ }
            }

            echo json_encode(['success' => true, 'result' => $rotateResult]);
            exit;
        }
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
        exit;
    }
}

/* -------------------------------------------------------------------------
 * DELETE A BACKUP (#1509) — permanently remove ONE selected
 * ihymns-backup-*.sql(.gz) file from data_share/backups/.
 *
 * ELI5: the "Restore from Backup" card (further down) lists every backup
 * file in a dropdown. This lets a Global Admin pick ONE of those files
 * and throw it away for good — e.g. to reclaim disk space between the
 * automatic 7-day prune (backup.php), or to clear out a backup that's
 * known-bad. There's no "undo" once unlink() runs — short of a copy the
 * admin already made off-site with the "Download a backup" button above.
 *
 * DETAILED / WHY:
 * Positioned here — BEFORE the page's blanket `validateCsrf()` gate a
 * few lines down (search "CSRF gate for every POST on this page") — for
 * the SAME reason the two secret_* actions above are: this is a NEW
 * (#1509) state-changing endpoint, so per rule #29 (.claude/CLAUDE.md
 * #29 / project-rules.md) it authenticates the request with
 * `validateCsrfRequest()` — which accepts EITHER a still-valid session
 * token OR a genuine same-origin request signal — rather than depending
 * solely on the page's older baked-token-only `validateCsrf()` blanket
 * check. That blanket check is exactly the "long-lived admin dashboard
 * tab, session token rotates/GCs underneath it" staleness trap rule #29
 * documents. If this block ran AFTER the blanket gate instead, a stale
 * token would already have 403'd the whole request before ever reaching
 * the more-robust check here — defeating the point of using
 * validateCsrfRequest() at all. (In practice the form below still POSTs
 * a session csrf_token field too, so path (a) of validateCsrfRequest()
 * — "a valid session token was supplied" — is what actually authorises
 * a normal same-tab click; the same-origin-header path (b) only matters
 * if this is ever driven from a fetch()-based UI later.)
 * Docs: manage/includes/auth.php::validateCsrfRequest() doc-block;
 * OWASP CSRF cheat sheet linked there.
 *
 * PATH CONFINEMENT (#1509 brief: "REUSE that exact guard"): identical to
 * the download-backup handler directly below — basename() the requested
 * name, match the SAME strict `ihymns-backup-<timestamp>.sql(.gz)`
 * pattern, then require realpath() to resolve INSIDE realpath($backupDir)
 * via the strncmp() prefix check. Belt-and-braces against any traversal
 * that survives basename() (e.g. a crafted absolute path or symlink).
 *
 * SAFETY GUARD (#1509 brief: "check whether the existing code treats any
 * backup specially and preserve that"): it does NOT. restore.php's
 * pre-restore auto-snapshot (see its "Pre-restore auto-snapshot"
 * section) is created by calling this SAME backup.php script and lands
 * in the SAME directory with the SAME `ihymns-backup-<timestamp>.sql.gz`
 * name as every other backup — there is no sentinel/flag anywhere in the
 * schema or filename that marks one file as "the" recovery snapshot, so
 * it can never be singled out and exempted individually. Rather than
 * silently accept that gap, this refuses to delete the LAST remaining
 * backup file: the directory can be thinned but never emptied via this
 * control, so there is always at least one recovery point on disk —
 * preserving the restore-safety model's intent even without per-file
 * metadata to target more precisely.
 * ------------------------------------------------------------------------- */
$deleteBackupError = '';
if (!$isInitialSetup
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'delete-backup') {

    if (!validateCsrfRequest((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }

    /* Re-confirm Global Admin — defence in depth, mirrors the secret_*
       actions above. The top-of-file gate (lines ~38-49) already enforces
       this for the page LOAD, but a destructive endpoint checks again
       independently. */
    if (!isset($currentUser) || !$currentUser || ($currentUser['role'] ?? null) !== 'global_admin') {
        http_response_code(403);
        echo 'Global Admin access required.';
        exit;
    }

    /* Server-side confirm flag — same convention as restore's `confirm=1`
       / drop-legacy's `confirm=1`. The form's typed "DELETE" JS prompt
       (see the Restore-from-Backup card markup) is a client-side speed
       bump only; this is the real gate. */
    if (($_POST['confirm'] ?? '') !== '1') {
        $deleteBackupError = 'Delete was not confirmed.';
    } else {
        $backupDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data_share' . DIRECTORY_SEPARATOR . 'backups';
        $reqName   = basename((string)($_POST['file'] ?? ''));

        if (!preg_match('/^ihymns-backup-[0-9-]+\.sql(?:\.gz)?$/', $reqName)) {
            $deleteBackupError = 'Invalid backup filename.';
        } else {
            /* Same belt-and-braces confinement check as download-backup
               below: the resolved path MUST live inside the backups dir. */
            $real    = realpath($backupDir . DIRECTORY_SEPARATOR . $reqName);
            $realDir = realpath($backupDir);
            if ($real === false || $realDir === false || !is_file($real)
                || strncmp($real, $realDir . DIRECTORY_SEPARATOR, strlen($realDir) + 1) !== 0) {
                $deleteBackupError = 'Backup not found.';
            } else {
                /* Never delete down to zero backups — see the safety-guard
                   doc-block above. */
                $siblingCount = 0;
                foreach (scandir($backupDir) ?: [] as $_sf) {
                    if (preg_match('/^ihymns-backup-[0-9-]+\.sql(?:\.gz)?$/', $_sf)) {
                        $siblingCount++;
                    }
                }
                unset($_sf);

                if ($siblingCount <= 1) {
                    $deleteBackupError = 'Refusing to delete the only remaining backup — '
                        . 'at least one must stay on disk as a recovery point.';
                } elseif (!@unlink($real)) {
                    $deleteBackupError = 'Could not delete the backup file (check filesystem permissions).';
                } else {
                    /* Audit log — filename only, not a secret, safe to log
                       (#1509 brief). logActivity() is already loaded via the
                       auth.php → activity_log.php require chain at the top
                       of this file. Best-effort: a logging hiccup must never
                       block the (already-completed) delete from being
                       reported back to the admin. */
                    if (function_exists('logActivity')) {
                        try {
                            logActivity(
                                'backup.delete',
                                'backup',
                                $reqName,
                                ['filename' => $reqName, 'by' => $currentUser['username'] ?? 'unknown'],
                                'success',
                                isset($currentUser['id']) ? (int)$currentUser['id'] : null
                            );
                        } catch (\Throwable $_e) { /* audit is best-effort */ }
                    }

                    /* PRG — redirect so a page refresh doesn't re-submit the
                       delete. Mirrors the save-credentials `?saved=1`
                       pattern further below; the filename round-trips
                       through the query string only to drive the success
                       flash message and has already been validated against
                       the strict backup-filename pattern above. */
                    header('Location: ?backup_deleted=' . rawurlencode($reqName));
                    exit;
                }
            }
        }
    }
}

/* CSRF gate for every POST on this page — the credentials form AND
   the backup-upload form go through here. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }
}

/* -------------------------------------------------------------------------
 * DOWNLOAD A BACKUP — stream a server-side backup file to a Global Admin for
 * off-site archival. Backups live OUTSIDE the web root
 * (appWeb/data_share/backups/ + a deny-all .htaccess), so there is no direct
 * URL; PHP reads + streams them here, behind this page's global_admin gate +
 * CSRF (validated above). Defence in depth: the requested name is basename()'d,
 * matched against the SAME strict backup-file pattern the list/restore/upload
 * use, and its realpath must resolve INSIDE the backups dir (no traversal). The
 * dump contains every table incl. credentials/PII, so each download is
 * audit-logged. Must run BEFORE any HTML is emitted — it streams binary + exits.
 * ------------------------------------------------------------------------- */
if (!$isInitialSetup
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'download-backup') {

    $backupDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data_share' . DIRECTORY_SEPARATOR . 'backups';
    $reqName   = basename((string)($_POST['file'] ?? ''));

    if (!preg_match('/^ihymns-backup-[0-9-]+\.sql(?:\.gz)?$/', $reqName)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Invalid backup filename.';
        exit;
    }

    /* The resolved path MUST live inside the backups dir (belt-and-braces against
       any traversal surviving basename()). */
    $real    = realpath($backupDir . DIRECTORY_SEPARATOR . $reqName);
    $realDir = realpath($backupDir);
    if ($real === false || $realDir === false || !is_file($real)
        || strncmp($real, $realDir . DIRECTORY_SEPARATOR, strlen($realDir) + 1) !== 0) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Backup not found.';
        exit;
    }

    /* Audit-log the download (sensitive). Same idiom as the upload handler;
       best-effort — never block the download on a log failure. */
    try {
        $auditDb = getDbMysqli();
        $stmt = $auditDb->prepare(
            'INSERT INTO tblActivityLog
                (UserId, Action, EntityType, EntityId, Details, IpAddress)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        if ($stmt) {
            $uid        = isset($currentUser['id']) ? (int)$currentUser['id'] : null;
            $action     = 'backup.download';
            $entityType = 'backup';
            $entityId   = $reqName;
            $details    = json_encode([
                'filename'   => $reqName,
                'size_bytes' => (int)@filesize($real),
                'by'         => $currentUser['username'] ?? 'unknown',
            ], JSON_UNESCAPED_SLASHES);
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $stmt->bind_param('isssss', $uid, $action, $entityType, $entityId, $details, $ip);
            @$stmt->execute();
            $stmt->close();
        }
    } catch (\Throwable $_e) { /* best effort */ }

    /* Stream verbatim as an attachment. Do NOT set Content-Encoding for a .gz —
       that makes the browser transparently decompress it; we want the .gz saved
       as-is. Drop any output buffering first so the binary stream is clean. */
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: ' . (str_ends_with($real, '.gz') ? 'application/gzip' : 'application/sql'));
    header('Content-Disposition: attachment; filename="' . $reqName . '"');
    header('Content-Length: ' . (string)filesize($real));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    readfile($real);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save-credentials') {
    $host   = trim((string)($_POST['host']    ?? ''));
    $port   = trim((string)($_POST['port']    ?? '3306'));
    $name   = trim((string)($_POST['name']    ?? ''));
    $user   = trim((string)($_POST['user']    ?? ''));
    $pass   = (string)($_POST['pass']         ?? '');
    $prefix = trim((string)($_POST['prefix']  ?? ''));

    $credFormValues = [
        'host' => $host, 'port' => $port, 'name' => $name,
        'user' => $user, 'pass' => '', 'prefix' => $prefix,
    ];

    if ($host === '' || $name === '' || $user === '') {
        $credError = 'Host, database name, and username are required.';
    } elseif (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) {
        $credError = 'Port must be a number between 1 and 65535.';
    } else {
        /* Sanitise prefix: alphanumeric + underscore only, trailing underscore enforced */
        if ($prefix !== '') {
            $prefix = preg_replace('/[^A-Za-z0-9_]/', '', $prefix);
            if (!str_ends_with($prefix, '_')) {
                $prefix .= '_';
            }
        }

        /* Test the connection before writing anything */
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $testConn = new mysqli($host, $user, $pass, $name, (int)$port);
            $testConn->set_charset('utf8mb4');
            $testConn->close();
        } catch (\Throwable $e) {
            $credError = 'Connection test failed: ' . $e->getMessage();
        }

        if ($credError === '') {
            if (!is_dir($credDir)) {
                @mkdir($credDir, 0755, true);
            }

            $escHost   = addslashes($host);
            $escName   = addslashes($name);
            $escUser   = addslashes($user);
            $escPass   = addslashes($pass);
            $escPrefix = addslashes($prefix);
            $intPort   = (int)$port;

            $content = <<<PHP
<?php

/**
 * iHymns — MySQL Database Credentials
 *
 * Generated by the iHymns Database Setup Dashboard.
 * This file is excluded from version control via .gitignore.
 */

define('DB_HOST',    '{$escHost}');
define('DB_PORT',    {$intPort});
define('DB_NAME',    '{$escName}');
define('DB_USER',    '{$escUser}');
define('DB_PASS',    '{$escPass}');
define('DB_CHARSET', 'utf8mb4');
define('DB_PREFIX',  '{$escPrefix}');
PHP;

            $written = @file_put_contents($credFile, $content, LOCK_EX);
            if ($written === false) {
                $credError = 'Failed to write credentials file. Check write permissions on ' . $credDir;
            } else {
                @chmod($credFile, 0600);
                /* PRG — redirect so a refresh doesn't re-submit the form */
                header('Location: ?saved=1');
                exit;
            }
        }
    }
}

if (isset($_GET['saved'])) {
    $credSuccess = 'Credentials saved and connection verified.';
}

/* =========================================================================
 * ACTION HANDLING
 * ========================================================================= */

$action = $_GET['action'] ?? '';
$actionOutput = '';
$actionSuccess = false;

/* User-friendly action titles for the status heading (#814). The
   previous heading rendered `ucfirst($action)` which left the URL
   slug visible (e.g. "Bulk-import-jobs Output"). This map mirrors
   each card's title so the operator sees the same name on the
   action page that they clicked on the dashboard. Add an entry
   when a new action is registered in $scriptMap below. Falling
   back to `ucfirst()` keeps unknown actions readable instead of
   PHP-warning. */
$friendlyTitles = [
    /* Top-level operations */
    'install'                          => 'Install Tables',
    'users'                            => 'Migrate Users & Setlists',
    'cleanup'                          => 'Cleanup Expired Tokens',
    'backup'                           => 'Backup Database',
    'restore'                          => 'Restore from Backup',
    'drop-legacy'                      => 'Drop Legacy Tables',
    'apply-all-migrations'             => 'Apply All Pending Migrations',
    'verify-cutover'                   => 'Verify Lyrics-Cutover Gate (#1235)',
    'opcache-reset'                    => 'Reset PHP OPcache (runtime code cache)',
    'deploy-forensics'                 => 'Deployment Forensics (read-only diagnostics)',
    /* Per-migration cards (label = card title minus the legacy
       alphabetic prefix; #816 standardised this on the issue
       number as the primary identifier). */
    'account-sync'                     => 'Account Sync & Shared Setlists',
    'credits'                          => 'Credit Fields (#497)',
    'songbook-meta'                    => 'Songbook Metadata (#502)',
    'user-features-catchup'            => 'User Features Catch-Up (#517)',
    'activity-log-expand'              => 'Activity Log Expansion (#535)',
    'credit-people'                    => 'Credit People Registry (#545)',
    'credit-people-flags'              => 'Credit People Flags (#584, #585)',
    'song-artists'                     => 'Songs Artist credit (#587)',
    'credit-people-slug'               => 'Credit People Slug + public page (#588)',
    'credit-people-slug-rebackfill'    => 'Credit People Slug re-backfill (audit follow-up)',
    'credit-people-name-parts'         => 'Credit People Structured Name (FirstNames / Surname / Suffix) (#934)',
    'catalogues'                       => 'Catalogues — many-to-many song grouping (#941)',
    'backfill-works-from-iswc'         => 'Backfill Works from existing ISWCs (#942)',
    'user-avatar-service'              => 'Per-user Avatar Service (#616)',
    'organisation-licences'            => 'Multiple Licence Types per Organisation (#640)',
    'songbook-affiliations'            => 'Songbook Affiliations Registry (#670)',
    'songbook-bibliographic'           => 'Songbook Bibliographic Metadata (#672)',
    'songbook-language'                => 'Songbook Language Column (#673)',
    'ietf-bcp47-language'              => 'IETF BCP 47 Language Tagging (#681)',
    'bulk-import-jobs'                 => 'Bulk Import Jobs Tracking (#676)',
    'backfill-legacy-songbook-languages' => 'Backfill Legacy Songbook Languages (#735)',
    'backfill-song-language-from-songbook' => 'Backfill Song Language from Songbook (audit follow-up)',
    'songid-prefix-fixup'                => 'Re-prefix SongIds after Songbook Rename',
    'user-preferred-languages'         => 'User Preferred Languages Column (#736)',
    'iana-language-subtag-registry'    => 'IETF BCP 47 Reference Data (#738)',
    'cldr-native-names'                => 'CLDR Native Names Overlay',
    'tag-titlecase'                    => 'Tag Title-Case Backfill (#762)',
    'tblsongs-number-nullable'         => 'tblSongs.Number Nullable (#783)',
    'multi-language-tables'            => 'Multi-language Tables (#778 phase A)',
    'parent-songbooks'                 => 'Parent Songbooks (#782 phase A)',
    'song-links'                       => 'Cross-book Song Links (#807 / #808)',
    'songcount-triggers'               => 'SongCount Triggers (#793)',
    'songbook-compilers'               => 'Songbook Compilers (#831)',
    'alternative-titles'               => 'Alternative Titles for Songs &amp; Songbooks (#832)',
    'external-links'                   => 'External Links System (#833)',
    'backfill-songbook-links'          => 'Backfill Songbook URL columns → External Links (#833)',
    'backfill-credit-person-links'     => 'Backfill Credit-Person Links → External Links (#833)',
    'works'                            => 'Works — composition grouping (#840)',
    'external-link-patterns'           => 'External-Link URL Patterns (#845)',
    'extra-streaming-platforms'        => 'Extra Streaming Platforms',
    'media-database-providers'         => 'Media Database Providers',
    'musicbrainz-style-links'          => 'MusicBrainz-Parity External Links',
    'credit-person-identifiers'        => 'Credit-Person Identifiers (IPI + ISNI)',
    'worldcat-and-secondhandsongs'     => 'WorldCat + SecondHandSongs Links',
    'canonicalise-existing-isni'       => 'Canonicalise Existing ISNI Rows',
    'song-media'                       => 'Song Media Uploads (#853)',
    'song-component-language'          => 'Song Component Language Override (#858)',
    'song-arrangement'                 => 'Song Arrangement Persistence (#892)',
    'bulk-import-per-songbook'         => 'Bulk-Import Per-Songbook Breakdown (#906)',
    'bulk-import-phase-label'          => 'Bulk-Import Phase Label (#907)',
    'activity-log-proxy-vpn'           => 'Activity Log Proxy/VPN + Per-Request',
    'email-verification-tokens'        => 'Email Verification Tokens (#898)',
    'password-reset-token-hash-width'  => 'Password Reset Token Hash Width (#898 follow-up)',
    'email-login-token-hashing'        => 'Email Login Token Hashing (#898 follow-up)',
    /* No-op file-touch (force-deploy 2026-05-09) — the previous SFTP
       deploy of #919 skipped this file under lftp `--only-newer`
       because the local file mtime didn't surpass the remote's
       prior-deploy mtime. This whitespace nudge gives the next
       deploy a fresh-mtime file to upload, getting the new
       activity-log-proxy-vpn card onto the dashboard. */
    /* `recompute-songbook-songcount` no longer exposed via the dashboard
       (#818) — the SongCount Triggers migration above includes its own
       initial recompute. The CLI script stays on disk for emergency
       manual runs. */
];

/* =========================================================================
 * MIGRATION REGISTRY (top-level — must be visible to BOTH the action-handler
 * block and the page-render block below)
 *
 * Single source of truth lives in includes/migration-registry.php as a
 * structured `$MIGRATIONS` array, one entry per migration with `script`,
 * `card`, and `probe` co-located. The four legacy arrays —
 * $scriptMap (migration entries), $migrationOrder, $migrationCards,
 * $migrationProbes — are derived from it below so existing call sites
 * (the action handler, the bulk runner, the per-migration card grid)
 * keep working unchanged.
 *
 * Previously these arrays were defined inline here in four separate
 * blocks; adding a new migration meant updating four places and getting
 * exactly one of them subtly wrong was a silent regression
 * (#claude/fix-pending-migrations-Vj4SQ). The structured source +
 * derivation pattern eliminates that drift class at the source; CI
 * guards in tests/php/test-migration-registry.php and
 * tests/php/test-schema-coverage.php enforce the discipline at PR time.
 * ========================================================================= */

/* =========================================================================
 * PROBE HELPERS (#820)
 *
 * Used by the closures inside includes/migration-registry.php — defined
 * here so the helpers exist before the registry require'd below evaluates
 * its closures. (Closures themselves are lazily-evaluated, but PHP needs
 * the function names resolvable at probe-call time.)
 * ========================================================================= */

/** Returns true when an INFORMATION_SCHEMA TABLES row exists for $table. */
function _migProbe_tableExists(\mysqli $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

/**
 * Truthful-completion check: re-evaluate a migration's registry probe AFTER its
 * script ran, on a fresh connection. Every migrate-*.php catches its OWN
 * \Throwable internally (prints "[ERROR] …" then returns normally), so the
 * dashboard's "require didn't throw" is NOT proof the schema landed — a CREATE
 * TABLE / ALTER can fail (timeout, bad FK, unsupported DDL on the host) and the
 * script swallows it. Only the probe flipping to applied is real success. This
 * is what stops the misleading "✓ completed" while the pending counter stays
 * stuck at the same number (the recurring confusion this fixes).
 *
 * @return bool|null  true = STILL pending (apply did not take), false = applied
 *                    (verified success), null = could not verify (no DB / probe)
 *                    — caller must NOT hard-fail on null.
 */
function _migVerify_stillPending(?\mysqli $conn, ?callable $probe): ?bool
{
    if (!($conn instanceof \mysqli) || !is_callable($probe)) {
        return null;
    }
    try {
        return ($probe($conn) === true);
    } catch (\Throwable) {
        return null;
    }
}

/** Returns true when an INFORMATION_SCHEMA COLUMNS row exists for $table.$column. */
function _migProbe_columnExists(\mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

/** True if $table carries an index named $index. Used by migration probes that must
 *  not flip to "done" until a UNIQUE / KEY has actually landed (#1343-B review) —
 *  e.g. a backfill+ADD-UNIQUE migration interrupted after the last row but before
 *  the index would otherwise show a green card with no constraint. */
function _migProbe_indexExists(\mysqli $db, string $table, string $index): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

/** True if tblSongs has any row still missing a PublicId (#1343-B) — drives the
 *  backfill probe so the "Song PublicId" card stays pending until every row is
 *  filled. False (done) when the column is absent (the column probe handles that). */
function _migProbe_hasNullPublicId(\mysqli $db): bool
{
    if (!_migProbe_columnExists($db, 'tblSongs', 'PublicId')) { return false; }
    /* @deleted-visible: migration probe (#1694) — backfill completeness is a
       PHYSICAL property; a hidden row with a NULL PublicId still needs the
       backfill (its permalink must work on restore). */
    /* @disabled-visible: same reasoning, one predicate over (#1765) — a song in
       a publicly-disabled book still needs its PublicId backfilled (the probe
       measures physical completeness, not public visibility). */
    $res = $db->query('SELECT 1 FROM tblSongs WHERE PublicId IS NULL LIMIT 1');
    $has = $res && $res->fetch_row() !== null;
    if ($res) { $res->free(); }
    return $has;
}

/** True if any LYRICS-BEARING tblSongs row still lacks its folded search mirror
 *  (#1039 Part A) — drives the search-synonyms backfill probe so the card stays
 *  pending until every song with lyrics has been folded. False (done) when the
 *  column is absent (the column probe handles that state). The predicate is
 *  `LyricsTextFolded IS NULL AND LyricsText <> ''` — NOT a bare NULL check: an
 *  empty-lyrics song (a freshly-created create_song row) legitimately carries a
 *  NULL fold with '' lyrics and must NOT re-pend the card, whereas a lyrics-bearing
 *  un-backfilled row must. */
function _migProbe_hasUnfoldedLyrics(\mysqli $db): bool
{
    if (!_migProbe_columnExists($db, 'tblSongs', 'LyricsTextFolded')) { return false; }
    /* @deleted-visible: migration probe (#1039) — backfill completeness is a
       PHYSICAL property; a hidden song with lyrics still needs its fold mirror so
       search reaches it once restored.
       @disabled-visible: same reasoning, one predicate over (#1765) — a song in a
       publicly-disabled book still needs its fold mirror backfilled. */
    $res = $db->query("SELECT 1 FROM tblSongs WHERE LyricsTextFolded IS NULL AND LyricsText <> '' LIMIT 1");
    $has = $res && $res->fetch_row() !== null;
    if ($res) { $res->free(); }
    return $has;
}

/** Returns true when $table.$column is currently nullable per INFORMATION_SCHEMA. */
function _migProbe_columnIsNullable(\mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row && strtoupper((string)$row['IS_NULLABLE']) === 'YES';
}

/** Returns $table.$column's current INFORMATION_SCHEMA DATA_TYPE, lowercased
 *  ('varchar', 'enum', 'set', …), or '' when the column doesn't exist. Used by
 *  the #1741 P1 ENUM/SET->VARCHAR widening probes (musician-profile,
 *  tune-enrichment) so a card only reports "applied" once the MODIFY has
 *  actually landed, not merely because the column is present (#1741 P1). */
function _migProbe_columnDataType(\mysqli $db, string $table, string $column): string
{
    $stmt = $db->prepare(
        'SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? strtolower((string)$row['DATA_TYPE']) : '';
}

/** Returns $table.$column's CHARACTER_MAXIMUM_LENGTH (the declared VARCHAR/CHAR
 *  width), or 0 when the column doesn't exist / isn't a character type. Used by
 *  widening probes that must detect a MODIFY has landed by WIDTH, not just type
 *  — a VARCHAR(16)->VARCHAR(64) widen keeps DATA_TYPE 'varchar', so
 *  _migProbe_columnDataType can't see it (#1791 tblSharedSetlists.ShareId). */
function _migProbe_columnCharLength(\mysqli $db, string $table, string $column): int
{
    $stmt = $db->prepare(
        'SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row && $row['CHARACTER_MAXIMUM_LENGTH'] !== null ? (int)$row['CHARACTER_MAXIMUM_LENGTH'] : 0;
}

/** Returns true when an INFORMATION_SCHEMA TRIGGERS row exists for $trigger. */
function _migProbe_triggerExists(\mysqli $db, string $trigger): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TRIGGERS
          WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('s', $trigger);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

/* Installer-only entries — these are NOT migrations (no card, no probe).
   Migration entries are appended below from the structured registry. */
$scriptMap = [
    'install'     => 'install.php',
    'users'       => 'migrate-users.php',
    'cleanup'     => 'cleanup.php',
    'backup'      => 'backup.php',
    'restore'     => 'restore.php',
    'drop-legacy' => 'drop-legacy-tables.php',
];

/* Load the structured migration registry — single source of truth for
   each migration's script + card + probe. Derive the four legacy arrays
   from it so the action handler, bulk runner, card grid, and pending
   partition all continue to work unchanged. */
$MIGRATIONS = require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'migration-registry.php';

$migrationOrder  = array_keys($MIGRATIONS);
$migrationCards  = [];
$migrationProbes = [];
$migrationManual = [];   /* #1235 P4/C6 — slugs flagged 'manual' (destructive, gated): excluded
                            from "Apply all" + the pending counter; single-run needs confirm=1. */
$migrationDryRunnable = []; /* #1380 FIX 4 — slugs flagged 'dryRunnable': a manual migration that
                              ALSO supports a report-only run WITHOUT &confirm=1 (e.g. the canonical
                              SongId backfill). The card renders a distinct "Dry-run (report only)"
                              link so the WEB-ONLY operator can preview before mutating. */
foreach ($MIGRATIONS as $_slug => $_entry) {
    $scriptMap[$_slug]       = $_entry['script'];
    $migrationCards[$_slug]  = $_entry['card'];
    $migrationProbes[$_slug] = $_entry['probe'];
    if (!empty($_entry['manual']))      { $migrationManual[$_slug] = true; }
    if (!empty($_entry['dryRunnable'])) { $migrationDryRunnable[$_slug] = true; }
}
unset($_slug, $_entry);
/* Captured during bulk-run so the failure can be surfaced as a visible
   banner ABOVE the (potentially long, scrollable) output panel. (#720) */
$bulkFirstFailStep    = null;
$bulkFirstFailMessage = '';
$bulkFirstFailFile    = '';
$bulkFirstFailLine    = 0;
$bulkTotalRan         = 0;
$bulkTotalFailed      = 0;
/* Set when the automatic pre-migration backup failed and the bulk run was
   aborted before any migration ran (so no schema change happened without a
   recovery point). Surfaced as its own banner below the panel. */
$bulkBackupFailed     = false;

/* Page-render sentinel + emergency chrome closer (#817).
   "Apply all" was reported as rendering "in its own raw HTML page,
   not within the same UI structure" — the cause is a child migration
   that calls `exit()` mid-bulk. exit() bypasses the try/catch in the
   bulk loop AND the outer page render below, so the admin chrome
   never closes and the user sees an unwrapped log.
   Set the flag to true at the very end of the page (just before the
   trailing exit) and a shutdown handler emits the chrome's closing
   tags if we never reached it. The migration scripts themselves are
   being audited to use `return` / `throw` instead of exit(); this is
   the defence-in-depth for any straggler. */
$pageRenderedCleanly = false;
register_shutdown_function(function () use (&$pageRenderedCleanly): void {
    if ($pageRenderedCleanly) return;

    /* #868 — pull the global $app into closure scope so admin-footer.php's
       version / copyright lines render with their real values. Without
       this, admin-footer's `$app['Application']['Version']['Number'] ?? ''`
       returns '' because require_once on infoAppVer.php is a no-op
       (already loaded at top-level) and the closure doesn't inherit
       global state by default. Symptom: footer reads "| v | Terms |
       Privacy" with no version + no copyright string. */
    global $app;

    /* Drain whatever's still buffered so it precedes the chrome tags
       we're about to emit. */
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    echo "\n<!-- emergency chrome closure (#817 / #868) — response "
       . "truncated before the page completed. -->\n";

    /* #868 — flip the status badge from "Running…" to "Interrupted"
       so the curator sees a clear failed-state rather than a frozen
       running indicator. Wrapped in `if (badge)` because the non-
       action dashboard page doesn't render the badge at all and
       this script runs in both contexts. */
    echo '<script>'
       . '(function(){'
       . 'var b=document.getElementById("action-status-badge");'
       . 'if(!b)return;'
       . 'b.textContent="Interrupted";'
       . 'b.className="badge bg-warning text-dark";'
       . 'b.title="Response was truncated before the run completed — '
       . 'likely a server-level timeout (FPM request_terminate_timeout '
       . 'or proxy timeout). Retry, or run individual migrations one '
       . 'at a time.";'
       . '})();'
       . '</script>' . "\n";

    /* Close the layers the action path opens (in order):
         <div class="output-log">    ← migration log container
         <div class="container-admin"> ← page container
       Then emit the admin footer + nav-layout closers so the page
       has its standard chrome, not a bare <body>.
       Five closes is one too many on the non-action page (which
       doesn't open the output-log); browsers tolerate stray closing
       tags so emit them all and accept the harmless extra. */
    echo "</div><!-- /.output-log (if open) -->\n";
    echo "</div><!-- /.container-admin -->\n";

    /* #868 — best-effort include of the admin footer so the page
       has its normal chrome at the bottom (version stamp, build
       metadata, copyright line, the close of <main> + admin-layout
       when admin-nav.php opened them — gated on $GLOBALS
       ['_adminLayoutOpen'] which we DON'T touch here, so the
       footer's guarded-close logic still applies correctly).

       admin-footer.php reads `$app['Application']['Version']…` —
       require_once on infoAppVer.php is a no-op when it was already
       loaded at top-level, and admin-footer would then see $app
       undefined in this closure's local scope. So if the global
       $app is empty (early-fatal path where the action handler
       never reached the line that loads infoAppVer), pull it in
       directly here so the footer renders with real values rather
       than "| v |  Terms |  Privacy". Required: the include's
       `$app = [];` populates the closure's local scope, which
       admin-footer.php inherits when require'd below.

       Wrapped in @require + try/catch so a load failure during
       shutdown doesn't compound into a fatal — better to ship a
       footer-less page than a 500. admin-footer.php does NOT
       emit </body></html> itself; we close the doc here. */
    try {
        if (!isset($app) || empty($app)) {
            $_appVerPath = dirname(__DIR__) . DIRECTORY_SEPARATOR
                         . 'includes' . DIRECTORY_SEPARATOR
                         . 'infoAppVer.php';
            if (is_file($_appVerPath)) {
                @require $_appVerPath;
            }
        }
        $_footerName = __DIR__ . DIRECTORY_SEPARATOR
                     . 'includes' . DIRECTORY_SEPARATOR
                     . 'admin-footer.php';
        if (is_file($_footerName)) {
            @require $_footerName;
        }
    } catch (\Throwable $_e) {
        /* Footer load failed — fall through to bare-body close. */
    }
    echo "</body>\n</html>\n";
});

/* =========================================================================
 * CSRF GUARD — ?action= GET dispatch (security-audit finding L-1, 2026-08-30)
 * ============================================================================
 * ELI5: every button on this page that actually DOES something — installs
 * tables, runs a migration, makes a backup, restores one, drops legacy
 * tables, resets the opcache — is a plain link (`<a href="?action=backup">`).
 * A plain link is just a GET request, and a browser attaches cookies to a
 * GET request automatically, from ANYWHERE — including a page loaded on a
 * completely different website. Without a check here, a malicious page
 * could embed `<img src="https://yoursite/manage/setup-database?action=backup">`
 * and, if a global admin merely had this site open in another browser tab,
 * silently trigger a real backup (or a migration, or worse) using THEIR
 * session. That's exactly what a CSRF (Cross-Site Request Forgery) token
 * exists to stop.
 *
 * DETAILED / WHY
 * --------------
 * This page's session cookie is already `SameSite=Strict`
 * (`manage/includes/auth.php`), which is a real and adequate mitigation on
 * modern browsers — a cross-site request never carries the cookie at all,
 * so a forged link degrades to a login redirect. But relying on ONE cookie
 * attribute as the ENTIRE CSRF defence for a battery of state-changing
 * actions (install / migrate / backup / restore / drop-legacy /
 * opcache-reset / the lyrics-cutover sentinel write) is thin defence-in-
 * depth, especially for the destructive ones — and every OTHER
 * state-changing endpoint on this very file already gates on
 * `validateCsrfRequest()` (the `secret_*` AJAX dispatcher above,
 * `delete-backup` / `download-backup` / `save-credentials` / `upload-backup`
 * below): this `?action=` dispatch was the one outlier.
 *
 * `validateCsrfRequest()` (`manage/includes/auth.php`) accepts EITHER a
 * valid `csrf_token` (query or POST field) OR a genuine same-origin AJAX
 * request (the `X-Requested-With` header — a browser cannot set it
 * cross-origin without a CORS preflight this server never grants). The JS
 * per-migration runner (`js/modules/setup-bulk-runner.js`'s `runOne()`,
 * which the guided setup wizard's `setup-wizard.js` also calls) ALREADY
 * sends that header on every request, so it passes this gate automatically
 * with NO client change needed.
 *
 * NO-JS FALLBACK (kept working, not broken): every plain `<a href="?action=…">`
 * link and GET `<form>` on this page that triggers a state-changing action
 * now carries a `csrf_token` value (rendered server-side by csrfToken()) — either appended to the href
 * or as a hidden form field — so a curator with JavaScript OFF keeps
 * working exactly as before; the token just rides along on the same link
 * or form submit they'd already use. Every such link/form on this page was
 * traced and updated (rule #33 discipline applied to a token, not just a
 * URL param): the pending-migration "Run & show output" + "Dry-run" links,
 * "Apply all", the six top-level action-card links (Install/Users/Cleanup/
 * Backup/Opcache-reset/Drop-legacy incl. its confirm=1 variant), the
 * per-migration card's run + dry-run links, the five lyrics-cutover-gate
 * phase links, and the Restore form's GET submit (a hidden `csrf_token`
 * field, since a GET `<form>` cannot carry a query string literal).
 * `?action=deploy-forensics` is the ONE action that is genuinely read-only
 * ("writes nothing — no reset, no DB mutation", per its own card copy) and
 * is exempt — a bookmark to it needs no token.
 *
 * ONE CHOKE POINT, same reasoning as the entitlement gate immediately
 * below (and the SAME "why one choke point" applies): dispatched from
 * three places (the `format=text` fast path, the bulk `apply-all-migrations`
 * runner, the HTML single-action path) — checked ONCE, here, BEFORE any of
 * them can run, so a fourth dispatcher added later can't bypass it either.
 *
 * `$isInitialSetup` is deliberately NOT exempt here (unlike the entitlement
 * gate just below it): there is no user/role to check yet on a virgin
 * install, but a PHP session — and therefore a CSRF token — already exists
 * the moment this page first renders (`csrfToken()` calls `initSession()`
 * itself), so the Install link on a fresh install can carry a valid token
 * from its very first render, the same as every other link on this page.
 *
 * @see manage/includes/auth.php::validateCsrfRequest()
 * @see tests/php/test-setup-database-csrf.php   the standing guard for this block
 * ========================================================================= */
if ($action !== '' && $action !== 'deploy-forensics') {
    $csrfSuppliedForAction = (string)($_GET['csrf_token'] ?? $_POST['csrf_token'] ?? '');
    if (!validateCsrfRequest($csrfSuppliedForAction)) {
        http_response_code(403);
        if ((string)($_GET['format'] ?? '') === 'text') {
            header('Content-Type: text/plain; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            echo "STATUS: error\n";
            echo "ACTION: {$action}\n";
            echo "ERROR: CSRF check failed — reload the page and try again.\n";
        } else {
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!DOCTYPE html><html lang="en"><body><h1>403 — CSRF check failed. Reload the page and try again.</h1></body></html>';
        }
        $pageRenderedCleanly = true;   /* suppress the shutdown chrome-closer */
        exit;
    }
}

/* =========================================================================
 * PER-ACTION ENTITLEMENTS (#1590, entitlement truth-up E1)
 *
 * ELI5: /manage/entitlements has three checkboxes — "Run database migrations",
 * "Back up database" and "Restore database from backup". Until now nothing read
 * any of them: reaching this page at all was the only check, so unticking one
 * changed nothing and the operator was never told.
 *
 * WHAT THE PLAN GOT WRONG (verified against the source, not inherited): §4.6
 * records the current gate as "setup-database.php raw `global_admin` (:191,
 * :459)". Those two lines are the `secret_*` AJAX dispatcher and the
 * delete-backup handler — separate defence-in-depth re-checks for two OTHER
 * operations. Nothing whatsoever guards `?action=backup`, `?action=restore` or a
 * migration slug beyond this file's top-of-page gate at :57, which requires the
 * `run_db_install` entitlement (default: ['global_admin']).
 *
 * EQUIVALENCE — no live behaviour change. The effective gate today is
 * `run_db_install` = global_admin. Adding an AND of `run_db_migrate` /
 * `run_db_restore` (both default ['global_admin']) leaves that set untouched.
 * `run_db_backup` defaulted to ['admin','global_admin'], which an admin could
 * never actually use because the page gate rejected them one line earlier; it
 * has been aligned to ['global_admin'] this pass so the map stops advertising a
 * capability the page denies. Either way the observable set is unchanged.
 *
 * WHY ONE CHOKE POINT, NOT THREE: the action is dispatched from three places
 * below — the `format=text` per-migration fast path, the bulk
 * `apply-all-migrations` runner, and the HTML path. A gate repeated three times
 * is three chances to miss one (CLAUDE.md rule #35: cross-file agreement needs a
 * mechanism, not a comment). Checking once, before any of them can run, also
 * cannot be bypassed by a fourth dispatcher added later.
 *
 * `$isInitialSetup` is exempt for the same reason the top-of-page gate is: on a
 * virgin install there is no user table yet to hold a role, and the installer
 * has to be able to run. Note this block sits AFTER the registry load, so
 * $migrationProbes is populated and "is this action a migration?" is DERIVED
 * from the registry rather than being a second hand-typed list that could fall
 * behind it (rule #34).
 *
 * @see appWeb/public_html/includes/entitlements.php
 * ========================================================================= */
if ($action !== '' && !$isInitialSetup) {
    $actionEntitlement = null;
    if ($action === 'backup') {
        $actionEntitlement = 'run_db_backup';
    } elseif ($action === 'restore') {
        $actionEntitlement = 'run_db_restore';
    } elseif ($action === 'apply-all-migrations' || isset($migrationProbes[$action])) {
        $actionEntitlement = 'run_db_migrate';
    }

    if ($actionEntitlement !== null
        && !userHasEntitlement($actionEntitlement, $currentUser['role'] ?? null)) {
        http_response_code(403);
        /* Match the response shape the caller asked for: the per-migration AJAX
           runner (js/modules/setup-bulk-runner.js) requests `format=text` and
           parses a STATUS line, so an HTML 403 would surface to the operator as
           an unexplained parse failure rather than "you cannot do this". */
        if ((string)($_GET['format'] ?? '') === 'text') {
            header('Content-Type: text/plain; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            echo "STATUS: error\n";
            echo "ACTION: {$action}\n";
            echo "ERROR: The {$actionEntitlement} entitlement is required.\n";
        } else {
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!DOCTYPE html><html lang="en"><body><h1>403 — the '
               . htmlspecialchars($actionEntitlement, ENT_QUOTES, 'UTF-8')
               . ' entitlement is required</h1></body></html>';
        }
        $pageRenderedCleanly = true;   /* suppress the shutdown chrome-closer */
        exit;
    }
}

if ($action !== '') {
    /* Signal to the included scripts that they're being run from the
     * dashboard, so they skip `header('Content-Type: text/plain')` which
     * would otherwise leak to the outer response and cause iOS Safari/Edge
     * to render this page as raw plaintext (the child's <br> output is
     * still fine — only the header propagates via buffered output). */
    define('IHYMNS_SETUP_DASHBOARD', true);

    /* Real includes/ directory, for any migration that needs a shared helper
       (musician_helpers.php, licence_registry.php, …) — #1695/#1784/#1769
       deploy-path hotfix.

       The deployed docroot is RENAMED per channel (public_html_dev on alpha,
       public_html_beta on beta; only main keeps public_html), while the
       migration scripts live in the un-renamed sibling .sql/. A migration that
       hardcodes `dirname(__DIR__) . '/public_html/includes/…'` therefore points
       at a directory that does NOT exist off main — the #1196 deploy-path trap,
       which surfaced as a bare "HTTP error" (Apply-all) / "Failed opening
       required" (single-run) the moment these were web-run on alpha.

       THIS runner physically lives inside the real docroot
       (<docroot>/manage/setup-database.php), so `dirname(__DIR__)` IS that
       docroot whatever it is named. Expose its includes/ so migrations resolve
       shared deps deploy-agnostically; a standalone/CLI or test run (repo
       layout, where public_html is real) leaves this undefined and the
       migrations fall back to the literal path. */
    if (!defined('IHYMNS_INCLUDES_DIR')) {
        define('IHYMNS_INCLUDES_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes');
    }

    /* #862 — lift the execution-time cap for any setup-database action.
       Shared hosts default to max_execution_time = 30s, which the bulk
       Apply-All run blows through with ~30 migrations + real backfills.
       Without this, PHP terminates mid-loop, the closing chrome never
       reaches the browser, and the JS that flips #action-status-badge
       from "Running…" to "Complete" / "Error" never runs — the badge
       stays stuck on the initial state.
       ignore_user_abort makes the run survive a curator closing the
       tab mid-stream — better to land all migrations than half. */
    @set_time_limit(0);
    @ini_set('max_execution_time', '0');
    @ignore_user_abort(true);

    /* #869 — text-format fast-path. The dashboard's per-migration
       AJAX runner (js/modules/setup-bulk-runner.js) calls into this
       same action endpoint one migration at a time. It doesn't want
       the full HTML chrome — just the migration's captured stdout
       framed by a small text header so the runner can distinguish
       success / error and surface per-migration progress live on
       the dashboard. Each request is short (one migration only),
       so the bulk run never sits in a single long PHP request that
       could hit a server-level timeout (FPM request_terminate_timeout,
       proxy timeout, CDN limit) — which is the real root cause of
       the symptom that #862 / #863 / #868 only papered over. */
    $requestedFormat = (string)($_GET['format'] ?? '');
    if ($requestedFormat === 'text' && $action !== 'apply-all-migrations') {
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');

        $scriptName = $scriptMap[$action] ?? null;
        if ($scriptName === null) {
            http_response_code(400);
            echo "STATUS: error\n";
            echo "ACTION: {$action}\n";
            echo "ERROR: Unknown action.\n";
            $pageRenderedCleanly = true;
            exit;
        }
        if (!$hasCredentials && $action !== 'install') {
            http_response_code(412);
            echo "STATUS: error\n";
            echo "ACTION: {$action}\n";
            echo "ERROR: Database credentials not configured.\n";
            $pageRenderedCleanly = true;
            exit;
        }
        $scriptDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
                   . '.sql' . DIRECTORY_SEPARATOR;
        $scriptPath = $scriptDir . $scriptName;
        if (!file_exists($scriptPath)) {
            http_response_code(404);
            echo "STATUS: error\n";
            echo "ACTION: {$action}\n";
            echo "ERROR: Script not found: {$scriptName}\n";
            $pageRenderedCleanly = true;
            exit;
        }

        /* Capture the migration's output (echo / _migXxx_out / etc.)
           so we can frame it with a status header. The migration may
           emit <br> tags expecting an HTML context — strip them so
           the AJAX runner gets readable plaintext. */
        ob_start();
        $migOk = true;
        $migErr = '';
        $migErrFile = '';
        $migErrLine = 0;
        $migStart = microtime(true);
        try {
            require $scriptPath;
        } catch (\Throwable $e) {
            $migOk = false;
            $migErr = $e->getMessage();
            $migErrFile = (string)$e->getFile();
            $migErrLine = (int)$e->getLine();
        }
        $migOutput = (string)ob_get_clean();
        /* Migration scripts use `_migXxx_out()` helpers that emit
           `<br>\n` for the dashboard context; the AJAX runner shows
           output as preformatted text so we replace those with a
           plain newline. */
        $migOutput = str_replace(['<br>', '<br/>', '<br />'], '', $migOutput);
        $elapsed = (int)round((microtime(true) - $migStart) * 1000);

        if (!$migOk) {
            http_response_code(500);
        }
        echo "STATUS: " . ($migOk ? 'ok' : 'error') . "\n";
        echo "ACTION: {$action}\n";
        echo "SCRIPT: {$scriptName}\n";
        echo "ELAPSED_MS: {$elapsed}\n";
        if (!$migOk) {
            echo "ERROR: {$migErr}\n";
            if ($migErrFile !== '') {
                echo "ERROR_AT: " . basename($migErrFile) . ":{$migErrLine}\n";
            }
        }
        echo "---\n";
        echo $migOutput;

        /* Activity Log (#1282) — per-run status for the JS per-card / bulk-runner
           path (the bulk runner runs PENDING migrations only, so low volume).
           Covers migrations + the install/migrate/users/cleanup/backup ops. */
        if (function_exists('logActivity')) {
            $logDetails = ['script' => $scriptName, 'elapsedMs' => (int)$elapsed, 'via' => 'ajax'];
            if (!$migOk) {
                $logDetails['error'] = $migErr;
                if ($migErrFile !== '') { $logDetails['at'] = basename($migErrFile) . ':' . $migErrLine; }
            }
            logActivity('setup.run', 'database', $action, $logDetails, $migOk ? 'success' : 'error', null, (int)$elapsed);
        }

        $pageRenderedCleanly = true;
        exit;
    }

    /* #817 round 2 — render the page chrome BEFORE the bulk run, so:
       (a) Content-Type: text/html is committed to the response before
           any child script's `header('Content-Type: text/plain')` could
           leak (header() is silently ignored once headers are sent — and
           we deliberately send headers + opening chrome here).
       (b) An exit() inside any included migration can no longer truncate
           the chrome — it's already on the wire. The shutdown handler
           emits the closing tags.
       The non-action path (the dashboard) keeps its own existing render
       block lower down. */
    header('Content-Type: text/html; charset=UTF-8');
    /* Lock the Content-Type so even if a child migration's earlier
       `header('Content-Type: text/plain')` had set it, the browser
       trusts ours and doesn't fall back to plaintext rendering on
       sniff. (#817 round 2 — was the suspected root cause of the
       "raw HTML page" symptom.) */
    header('X-Content-Type-Options: nosniff');
    /* Disable caching of the apply-all response so a curator never sees
       a stale "raw HTML" snapshot from a CDN / browser cache after the
       chrome-render fix lands. Each apply-all run is unique anyway. */
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    /* Drain any default output buffer so our manual flush() actually
       reaches the browser. PHP defaults to one ambient buffer; we
       want output to stream as soon as we echo. The first echo below
       triggers PHP to send the headers we just queued. */
    while (ob_get_level() > 0) {
        @ob_end_clean();   /* discard any buffered content from before
                              the action block, to be safe */
    }
    @ob_implicit_flush(true);

    /* Compute the user-friendly heading once for use in <title> + h4. */
    $headingTitle = $friendlyTitles[$action] ?? ucfirst($action);

    /* Render chrome opening — DOCTYPE through the open tag of the
       output panel. After this echo, the response is committed: every
       byte of HTML below this point is on the wire before the bulk
       runs. */
    ?>
<!DOCTYPE html>
<!-- IHYMNS_APPLY_ALL_CHROME_v3 — if you can see this comment in View Source,
     the chrome-first render is reaching the browser. (#817 round 2) -->
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($headingTitle) ?> — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <link rel="stylesheet" href="/css/app.css?v=<?= filemtime(dirname(__DIR__) . "/css/app.css") ?>">
    <link rel="stylesheet" href="/css/admin.css?v=<?= filemtime(dirname(__DIR__) . "/css/admin.css") ?>">
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>
<?php if (!$isInitialSetup): require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; endif; ?>

<div class="container-admin py-4">
    <h1 class="mb-1">
        Database Setup<?= entitlementLockChipHtml('run_db_install') ?>
    </h1>
    <p class="text-secondary mb-4">
        Install the iHymns database, apply pending updates to it, and run backups and maintenance.
        <span class="badge bg-danger text-light ms-2" style="font-size: 0.7rem; font-weight: 600;">
            <i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Global Admin only
        </span>
    </p>
    <p><a href="?" class="btn btn-outline-secondary btn-sm">&larr; Back to Dashboard</a></p>
    <h4 class="mb-2 d-flex align-items-center gap-2">
        <span><?= htmlspecialchars($headingTitle) ?> &mdash; Output</span>
        <span id="action-status-badge" class="badge bg-secondary">Running…</span>
    </h4>
    <div class="output-log" id="action-output-log">
<?php
    /* Push the chrome to the browser NOW. After this point even a
       fatal in a child migration leaves the chrome visible (the
       shutdown handler appends `</div></main>...</body></html>`). */
    @ob_flush();
    @flush();

    /* Capture the bulk run's output so the per-step framing (▶ / ✓
       / ✗) can be displayed inside the already-open <div class="output-log">
       above, AND so the bulk-failure banner has the data it needs. The
       captured buffer is echoed inline after the run completes. */
    ob_start();

    $scriptDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.sql' . DIRECTORY_SEPARATOR . '';
    /* $scriptMap and $migrationOrder are now defined at the top of the
       file (above $migrationCards) so both the action handler here AND
       the page-render path below can see them. */

    /* "Apply all pending migrations" handler (#577). Iterates
       $migrationOrder, runs each script via require, and stops on
       the first thrown exception. Per-script output is interleaved
       with framing headers so the operator can see exactly which
       migration produced which line in the log. Each migration is
       already idempotent so re-running the bulk button after some
       have applied is safe — they no-op individually. */
    if ($action === 'apply-all-migrations') {
        if (!$hasCredentials) {
            echo "ERROR: Database credentials not configured.\n";
            echo "Configure appWeb/.auth/db_credentials.php first, or run Install.\n";
        } else {
            /* AUTOMATIC PRE-MIGRATION BACKUP (#322 backup.php, on the bulk
               path). Some migrations are destructive (e.g.
               migrate-drop-songbook-name DROPs a column), and the bulk run
               is the deploy-time "push the new schema" action — so snapshot
               the whole DB to data_share/backups/ BEFORE running anything,
               giving a restore.php recovery point if a run fails or
               half-applies. Two guards keep it sane:
                 - only back up when something is actually PENDING (a no-op
                   "Apply all" must not churn a full dump every click), and
                 - ABORT the whole run if the backup didn't complete — never
                   migrate without a recovery point. */
            $proceedBulk = true;
            $_anyPending = true;   /* default-pending = safe (back up) if we can't check */
            try {
                $_probeConn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
                $_probeConn->set_charset('utf8mb4');
                $_anyPending = false;
                foreach ($migrationOrder as $_mig) {
                    $_p = $migrationProbes[$_mig] ?? null;
                    if (!is_callable($_p)) { $_anyPending = true; break; }  /* unknown → pending */
                    try {
                        if ($_p($_probeConn) === true) { $_anyPending = true; break; }
                    } catch (\Throwable $_e) {
                        $_anyPending = true; break;  /* probe threw → assume pending */
                    }
                }
                $_probeConn->close();
            } catch (\Throwable $_e) {
                $_anyPending = true;  /* couldn't connect to check → back up to be safe */
            }

            if ($_anyPending) {
                echo "═══════════════════════════════════════════════════════\n";
                echo "▶ Automatic pre-migration backup\n";
                echo "═══════════════════════════════════════════════════════\n";
                $finalFile = null;  /* backup.php sets this to the .sql.gz path on success */
                try {
                    require $scriptDir . 'backup.php';
                } catch (\Throwable $_e) {
                    echo "\n  ✗ Backup script threw: " . htmlspecialchars($_e->getMessage()) . "\n";
                }
                $_backupOk = is_string($finalFile ?? null) && $finalFile !== '' && is_file($finalFile);
                if (!$_backupOk) {
                    echo "\n  ✗ Pre-migration backup did NOT complete — ABORTING the run.\n";
                    echo "    No migrations were applied. Run a manual Backup (or fix the\n";
                    echo "    cause — disk space / DB connection), then retry.\n\n";
                    $proceedBulk      = false;
                    $bulkBackupFailed = true;  /* surfaced as a banner below */
                } else {
                    echo "\n  ✓ Backup written: " . htmlspecialchars(basename((string)$finalFile)) . " — proceeding.\n\n";
                }
            }

            $totalRan = 0;
            $totalFailed = 0;
            $startedAt = microtime(true);
            $actionSuccess = true;
            /* First-failing-step is captured so we can surface it as a
               prominent banner ABOVE the output panel — the original
               implementation wrote the FAILED line into the panel where
               it could be missed if the panel's overflow scrolled past
               it. (#720) */
            $firstFailStep    = null;
            $firstFailMessage = '';
            $firstFailFile    = '';
            $firstFailLine    = 0;

            /* Catch fatal errors mid-bulk (PHP Fatal in an included
               migration script bypasses the try/catch). The shutdown
               handler captures the last error and surfaces it the
               same way as a caught Throwable. (#720) */
            register_shutdown_function(function () {
                $err = error_get_last();
                if (!$err) return;
                if (!in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                    return;
                }
                /* Best-effort flush so the operator at least sees the
                   fatal in the output panel even if the bulk loop
                   never reached its summary footer. */
                echo "\n\n══════════════════════════════════════════════════════\n";
                echo "✗ FATAL DURING BULK RUN: " . $err['message'] . "\n";
                echo "  File: " . basename($err['file']) . ":" . $err['line'] . "\n";
                echo "══════════════════════════════════════════════════════\n";
            });

            /* One reusable connection for the post-run probe verification (see
               _migVerify_stillPending). DDL auto-commits, so a single connection
               sees each migration's new tables/columns as the loop progresses. */
            $verifyConn = null;
            try {
                $verifyConn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
                $verifyConn->set_charset('utf8mb4');
            } catch (\Throwable $_e) {
                $verifyConn = null;  /* can't verify → completions reported unverified, never falsely failed */
            }

            /* $proceedBulk is false only when the auto pre-migration backup
               above failed — in that case iterate nothing so no migration
               runs without a recovery point. */
            foreach (($proceedBulk ? $migrationOrder : []) as $migAction) {
                /* #1235 P4/C6 — never run a DESTRUCTIVE manual-only migration (e.g. the JSON-
                   column drop) from "Apply all": it is gated + run by hand with confirm=1.
                   Skipping here (and excluding it from the pending counter / bulk-runner list
                   below) is what keeps the irreversible drop OUT of routine bulk runs and stops
                   its perpetually-pending probe from hard-failing this no-JS loop. */
                if (!empty($migrationManual[$migAction])) {
                    echo "  ⏭ {$migAction} — manual/destructive (run by hand with confirm=1); skipped by Apply all.\n\n";
                    continue;
                }
                /* #862 — reset the execution-time alarm before EACH
                   migration in case the host enforces a per-script
                   limit that survives the require() boundary. Cheap
                   to call repeatedly; harmless when set_time_limit
                   is disabled by safe_mode / disable_functions. */
                @set_time_limit(0);

                $migScript = $scriptMap[$migAction] ?? null;
                if ($migScript === null) {
                    echo "  ✗ Unknown migration key: {$migAction} — skipped.\n\n";
                    continue;
                }
                $migPath = $scriptDir . $migScript;
                if (!file_exists($migPath)) {
                    echo "  ✗ Script not found: {$migScript} — skipped.\n\n";
                    continue;
                }
                $migStart = microtime(true);
                echo "═══════════════════════════════════════════════════════\n";
                echo "▶ {$migAction}  ({$migScript})\n";
                echo "═══════════════════════════════════════════════════════\n";
                try {
                    require $migPath;
                    $elapsed = round((microtime(true) - $migStart) * 1000);
                    /* TRUTHFUL completion: the script returned without throwing,
                       but it may have caught its OWN DDL error internally. Only
                       the probe flipping to applied is real success — otherwise
                       we'd print "✓ completed" while the pending counter stays
                       stuck (the confusion this fixes). */
                    $verifyPending = _migVerify_stillPending($verifyConn, $migrationProbes[$migAction] ?? null);
                    if ($verifyPending === true) {
                        $totalFailed++;
                        $actionSuccess = false;
                        if ($firstFailStep === null) {
                            $firstFailStep    = $migAction;
                            $firstFailMessage = 'ran but its probe still reports PENDING — the migration script caught an internal error (see its [ERROR] output above); nothing was applied for this step.';
                            $firstFailFile    = basename((string)$migScript);
                            $firstFailLine    = 0;
                        }
                        echo "\n  ✗ {$migAction} ran but is STILL PENDING after {$elapsed} ms — its objects were NOT created (the script swallowed an error above). Stopping so you can resolve it.\n";
                        if (function_exists('logActivity')) {
                            logActivity('setup.run', 'database', $migAction, ['script' => $migScript, 'bulk' => true, 'reason' => 'ran but probe still PENDING'], 'error', null, (int)$elapsed);
                        }
                        break;
                    }
                    $totalRan++;
                    $verifiedNote = ($verifyPending === false) ? ' + verified' : '';  /* null = unverifiable */
                    echo "\n  ✓ {$migAction} completed{$verifiedNote} in {$elapsed} ms\n\n";
                    /* Activity Log (#1282) — log EVERY migration run in the no-JS bulk
                       path, success included, so the audit trail is complete in every
                       path (the JS per-card runner already logs each). A no-JS Apply-all
                       therefore writes one row per migration in $migrationOrder. */
                    if (function_exists('logActivity')) {
                        logActivity('setup.run', 'database', $migAction, ['script' => $migScript, 'bulk' => true, 'verified' => ($verifyPending === false)], 'success', null, (int)$elapsed);
                    }
                } catch (\Throwable $e) {
                    $totalFailed++;
                    $actionSuccess = false;
                    if ($firstFailStep === null) {
                        $firstFailStep    = $migAction;
                        $firstFailMessage = $e->getMessage();
                        $firstFailFile    = basename((string)$e->getFile());
                        $firstFailLine    = (int)$e->getLine();
                    }
                    echo "\n  ✗ {$migAction} FAILED: " . htmlspecialchars($e->getMessage()) . "\n";
                    if ($e->getFile()) {
                        echo "    File: " . htmlspecialchars(basename($e->getFile())) . ":" . $e->getLine() . "\n";
                    }
                    echo "\n  Stopping the bulk run so you can resolve this before continuing.\n";
                    if (function_exists('logActivity')) {
                        logActivity('setup.run', 'database', $migAction, ['script' => $migScript, 'bulk' => true, 'error' => $e->getMessage(), 'at' => basename((string)$e->getFile()) . ':' . $e->getLine()], 'error');
                    }
                    break;
                }
            }
            if ($verifyConn instanceof \mysqli) { $verifyConn->close(); }
            /* Promote captured failure data to the outer scope so the
               render below can surface a visible "Failed at" banner. */
            $bulkFirstFailStep    = $firstFailStep;
            $bulkFirstFailMessage = $firstFailMessage;
            $bulkFirstFailFile    = $firstFailFile;
            $bulkFirstFailLine    = $firstFailLine;
            $bulkTotalRan         = $totalRan;
            $bulkTotalFailed      = $totalFailed;

            $totalElapsed = round((microtime(true) - $startedAt) * 1000);
            echo "═══════════════════════════════════════════════════════\n";
            echo "Bulk run finished — {$totalRan} migration"
               . ($totalRan === 1 ? '' : 's') . " ran successfully";
            if ($totalFailed > 0) {
                echo ", {$totalFailed} failed";
            }
            echo " in {$totalElapsed} ms.\n";
            /* Activity Log (#1282) — one summary row per Apply-all, on top of the
               per-migration rows (success + failure) logged inline above. */
            if (function_exists('logActivity')) {
                logActivity('setup.apply_all', 'database', '', ['ran' => $totalRan, 'failed' => $totalFailed, 'firstFailStep' => $firstFailStep], $totalFailed > 0 ? 'failure' : 'success', null, (int)$totalElapsed);
            }
        }
    } elseif ($action === 'verify-cutover') {
        /* #1235 lyrics-cutover GATE runner — the WEB path for the verification
           gate (the owner is web-only on shared DreamHost). The verify script
           (appWeb/.sql/verify-lyrics-cutover.php) reads ?phase= directly in
           dashboard mode, emits via its out() helper (captured into the panel
           below) and sets $allGreen, then returns (never exit()s in web mode).
           pre/soak are read-only except writing the green sentinel row;
           pre-drop/post-drop are the drop-day gates. */
        $vfPhase = (string)($_GET['phase'] ?? '');
        if (!in_array($vfPhase, ['pre', 'soak', 'pre-drop', 'post-drop'], true)) {
            echo "ERROR: phase must be pre | soak | pre-drop | post-drop.\n";
            $actionSuccess = false;
        } elseif (!$hasCredentials) {
            echo "ERROR: Database credentials not configured.\n";
            $actionSuccess = false;
        } else {
            $vfScript = $scriptDir . 'verify-lyrics-cutover.php';
            if (!is_file($vfScript)) {
                echo "ERROR: verify-lyrics-cutover.php not found in .sql/.\n";
                $actionSuccess = false;
            } else {
                $allGreen = false;   /* the script sets this true on an all-green run */
                try {
                    require $vfScript;
                    $actionSuccess = ($allGreen === true);
                } catch (\Throwable $e) {
                    $actionSuccess = false;
                    echo "\nERROR: " . htmlspecialchars($e->getMessage()) . "\n";
                    if ($e->getFile()) {
                        echo "File: " . htmlspecialchars(basename($e->getFile())) . ":" . $e->getLine() . "\n";
                    }
                }
            }
        }
    } elseif ($action === 'opcache-reset') {
        /* #1290 — diagnose + reset the PHP OPcache. Stale compiled bytecode is the
           leading cause of "deployed code isn't running" on shared hosting — e.g.
           the songs_json 's.SongbookName' flood, where the column is dropped + the
           repo SongData.php is correct (b.Name JOIN), but the live runtime executes
           an OLD compiled copy. Resetting forces every PHP file to recompile from
           disk on the next request. */
        if (!function_exists('opcache_get_status')) {
            echo "OPcache is NOT available on this PHP runtime (opcache_get_status missing).\n";
            echo "Nothing to reset — if stale code persists, it's a deploy-upload issue, not OPcache.\n";
            $actionSuccess = false;
        } else {
            $cfg    = function_exists('opcache_get_configuration') ? @opcache_get_configuration() : [];
            $status = @opcache_get_status(false);
            $vt     = $cfg['directives']['opcache.validate_timestamps'] ?? null;
            $freq   = $cfg['directives']['opcache.revalidate_freq']     ?? null;
            echo "=== OPcache status (before reset) ===\n";
            echo "  enabled:             " . (($status && !empty($status['opcache_enabled'])) ? 'yes' : 'no') . "\n";
            echo "  validate_timestamps: " . ($vt === null ? 'unknown'
                : ($vt ? 'on (changed files auto-reload)'
                       : 'OFF — never auto-reloads; deployed changes stay STALE until a reset')) . "\n";
            echo "  revalidate_freq:     " . ($freq === null ? 'unknown' : ((int)$freq) . 's') . "\n";
            if ($status && isset($status['opcache_statistics']['num_cached_scripts'])) {
                echo "  cached scripts:      " . (int)$status['opcache_statistics']['num_cached_scripts'] . "\n";
            }
            $reset = function_exists('opcache_reset') ? @opcache_reset() : false;
            clearstatcache(true);
            echo "\n" . ($reset
                ? "  ✓ OPcache reset — every PHP file recompiles from disk on the next request.\n"
                : "  ✗ opcache_reset() returned false (disabled or restricted on this host).\n");
            $actionSuccess = (bool)$reset;
            echo "\nNow reload the public site (or wait for the next crawler hit) and re-check the Activity\n";
            echo "Log. If the 'Unknown column s.SongbookName' errors STOP, stale OPcache was the cause\n";
            echo "(the deployed code is already correct). If they persist, the deploy didn't upload the\n";
            echo "current SongData.php — tell me and we'll fix the deploy itself.\n";
            /* #1290 — mirror opcache-bust.php's Activity-Log entry so a MANUAL
               reset from this dashboard is auditable alongside the automated
               cron busts: same action key + EntityType (trigger='manual').
               This handler already runs authenticated as a global_admin with
               the DB + app bootstrap loaded, so a direct call is safe — no
               require/try-catch gymnastics needed, and logActivity() is itself
               best-effort and never throws. Logs nothing derived from the
               secret key or raw request input — only the reset outcome +
               environment. (Rebuilt for current alpha in the 2026-07-11 branch
               audit from the never-merged fb450d32; see #1537.) */
            if (function_exists('logActivity')) {
                logActivity(
                    'ops.opcache_reset',
                    'runtime',
                    'opcache',
                    [
                        'trigger'           => 'manual',
                        'reset'             => (bool) $reset,
                        'opcache_available' => function_exists('opcache_reset'),
                        'environment'       => function_exists('ihymns_environment') ? ihymns_environment() : null,
                    ],
                    $actionSuccess ? 'success' : 'error'
                );
            }
        }
    } elseif ($action === 'deploy-forensics') {
        /* #1295 — READ-ONLY deploy forensics. The live site INTERMITTENTLY throws
           "Unknown column 's.SongbookName'" from SongData.php even though the repo
           SongData.php uses a `b.Name` JOIN (no `s.SongbookName`) and the column is
           dropped; an OPcache reset stops it, then it RETURNS. Separately, an editor
           CSRF early-mint fix was deployed but delete_song still 403s. Competing
           hypotheses: (1) the live DISK files aren't the repo versions; (2) a SECOND
           stale copy is loaded; (3) opcache.file_cache resurrects stale bytecode
           across resets; (4) multiple app servers each with independent OPcache.
           This action emits a plain-text report that definitively reveals WHICH —
           and it does so WITHOUT writing anything: no opcache_reset, no file writes,
           no DB mutations. Every section is wrapped in its own try/catch so one
           failing probe never aborts the rest, and every directory walk / loop is
           bounded (file count + depth + a wall-time budget). */

        $sec = static function (string $letter, string $title, callable $body): void {
            echo "\n=== [{$letter}] {$title} ===\n";
            try {
                $body();
            } catch (\Throwable $e) {
                /* basename() the file so we never leak the absolute server path of an
                   internal include into the panel; the message is already operator-safe. */
                echo "  (section failed — continuing) " . $e->getMessage()
                   . " @ " . basename((string)$e->getFile()) . ":" . $e->getLine() . "\n";
            }
        };

        $fmtTime = static function ($ts): string {
            $ts = (int)$ts;
            return $ts > 0 ? date('Y-m-d H:i:s', $ts) : '(none)';
        };

        echo "iHymns DEPLOYMENT FORENSICS (read-only) — generated " . date('Y-m-d H:i:s') . "\n";
        echo "Run this TWICE: if [A] hostname / SERVER_ADDR / PID differ between runs,\n";
        echo "you are hitting MULTIPLE app servers, each with its own OPcache.\n";

        /* ---- [A] SERVER IDENTITY ---------------------------------------- */
        $sec('A', 'SERVER IDENTITY', function () {
            echo "  gethostname():     " . (gethostname() ?: '?') . "\n";
            echo "  php_uname('n'):    " . (php_uname('n') ?: '?') . "\n";
            echo "  SERVER_ADDR:       " . ($_SERVER['SERVER_ADDR'] ?? '?') . "\n";
            echo "  getmypid():        " . getmypid() . "\n";
            echo "  php_sapi_name():   " . php_sapi_name() . "\n";
            echo "  PHP_VERSION:       " . PHP_VERSION . "\n";
        });

        /* ---- [B] LOADED SongData.php ------------------------------------ */
        /* Resolve the file the autoloader/require ACTUALLY loaded (not a guessed
           path) via reflection on the loaded class, then fingerprint it and print
           the exact lines + a substring tally so staleness is unambiguous. */
        $loadedSongData = null;
        $sec('B', 'LOADED SongData.php', function () use ($fmtTime, &$loadedSongData) {
            if (!class_exists('SongData')) {
                /* Force the include using the same require the app uses, so we report
                   the file that WOULD load on a real request. */
                $candidate = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes'
                           . DIRECTORY_SEPARATOR . 'SongData.php';
                if (is_file($candidate)) { require_once $candidate; }
            }
            if (!class_exists('SongData')) {
                echo "  class SongData is NOT loadable — cannot reflect its file.\n";
                return;
            }
            $ref  = new \ReflectionClass('SongData');
            $path = $ref->getFileName();
            $real = $path !== false ? (realpath($path) ?: $path) : '(unknown)';
            $loadedSongData = $real;
            echo "  ReflectionClass file: {$real}\n";
            if (is_file($real)) {
                echo "  filesize:  " . filesize($real) . " bytes\n";
                echo "  filemtime: " . $fmtTime(filemtime($real)) . "\n";
                echo "  sha1_file: " . sha1_file($real) . "\n";
                $contents = file_get_contents($real);
                if ($contents !== false) {
                    $lines = preg_split('/\r\n|\r|\n/', $contents);
                    $dump  = static function (int $from, int $to) use ($lines) {
                        $max = count($lines);
                        for ($i = $from; $i <= $to && $i <= $max; $i++) {
                            /* $lines is 0-indexed; print human (1-based) line numbers. */
                            echo "    " . str_pad((string)$i, 5, ' ', STR_PAD_LEFT)
                               . " | " . ($lines[$i - 1] ?? '') . "\n";
                        }
                    };
                    echo "  --- lines 360-372 ---\n";
                    $dump(360, 372);
                    echo "  --- lines 1628-1640 ---\n";
                    $dump(1628, 1640);
                    /* The stale-code smoking gun: the dropped column should appear ZERO
                       times in SQL string literals; the correct JOIN alias should appear. */
                    echo "  substr_count('s.SongbookName'):      "
                       . substr_count($contents, 's.SongbookName') . "\n";
                    echo "  substr_count('b.Name AS songbookName'): "
                       . substr_count($contents, 'b.Name AS songbookName') . "\n";
                    echo "  substr_count('b.Name'):              "
                       . substr_count($contents, 'b.Name') . "\n";
                    echo "  NOTE: any 's.SongbookName' hits here may be in COMMENTS; the SQL\n";
                    echo "        itself should read 'b.Name AS songbookName' / 'sb.Name AS songbookName'.\n";
                } else {
                    echo "  (could not read file contents)\n";
                }
            } else {
                echo "  reflected path is not a readable file.\n";
            }
        });

        /* ---- [C] ALL COPIES on disk ------------------------------------- */
        /* Walk the deploy root (parent of DOCUMENT_ROOT — holds public_html +
           .sql / .auth / data_share / private_html siblings) for (1) every file
           literally named SongData.php and (2) every *.php that CONTAINS the dropped
           column string. A second docroot or a #1250 sibling-tree copy shows up here.
           Bounded by file-count, depth and a wall-time budget so a pathological tree
           never hangs the page; any cap hit is printed, never silent. */
        $sec('C', 'ALL COPIES on disk (bounded scan)', function () use ($fmtTime, $loadedSongData) {
            $docRoot    = $_SERVER['DOCUMENT_ROOT'] ?? '';
            $deployRoot = $docRoot !== '' ? dirname($docRoot) : dirname(__DIR__, 3);
            $deployRoot = realpath($deployRoot) ?: $deployRoot;
            echo "  scan root: {$deployRoot}\n";

            $maxFiles = 20000;
            $maxDepth = 12;
            $budget   = 5.0;            // seconds of wall-time
            /* Skip VCS, deps, backups AND credential dirs (.auth/.env): the scan
               must never even READ a credential file into memory (#1295 safety
               review). SongData.php never lives in those dirs, so coverage of the
               real deploy tree (public_html + .sql / private_html / data_share
               siblings) is unaffected. */
            $skipDirs = ['.git', '.auth', '.env', 'node_modules', 'vendor', '_backups', 'backups'];
            $start    = microtime(true);

            $seen     = 0;
            $capped   = false;
            $reason   = '';
            $songData = [];            // files named exactly SongData.php
            $colHits  = [];            // *.php whose contents contain s.SongbookName

            /* Iterative stack walk (no recursion) so depth + budget are trivially
               enforceable; each frame is [path, depth]. */
            $stack = [[$deployRoot, 0]];
            while ($stack !== []) {
                if ((microtime(true) - $start) > $budget) { $capped = true; $reason = 'wall-time budget (~5s)'; break; }
                if ($seen >= $maxFiles)                   { $capped = true; $reason = "file cap ({$maxFiles})"; break; }
                [$dir, $depth] = array_pop($stack);
                if ($depth > $maxDepth) { $capped = true; $reason = "depth cap ({$maxDepth})"; continue; }

                $entries = @scandir($dir);
                if ($entries === false) { continue; }
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') { continue; }
                    $full = $dir . DIRECTORY_SEPARATOR . $entry;
                    if (is_dir($full)) {
                        if (in_array($entry, $skipDirs, true)) { continue; }
                        if (is_link($full)) { continue; }      // don't follow symlinks (loop/escape guard)
                        $stack[] = [$full, $depth + 1];
                        continue;
                    }
                    if (!is_file($full)) { continue; }
                    $seen++;
                    if ($seen >= $maxFiles) { $capped = true; $reason = "file cap ({$maxFiles})"; break 2; }

                    $isSongData = ($entry === 'SongData.php');
                    $isPhp      = (substr($entry, -4) === '.php');
                    if (!$isSongData && !$isPhp) { continue; }

                    /* Only read contents for .php files (the column string only lives
                       in PHP source); cap individual reads implicitly via is_file. */
                    $hasCol = false;
                    if ($isPhp) {
                        $c = @file_get_contents($full);
                        if ($c !== false && strpos($c, 's.SongbookName') !== false) { $hasCol = true; }
                    }
                    if ($isSongData) {
                        $songData[] = [
                            'path' => $full,
                            'mtime' => $fmtTime(@filemtime($full)),
                            'size' => @filesize($full),
                            'sha1' => @sha1_file($full) ?: '?',
                            'col'  => $hasCol ? 'YES' : 'no',
                        ];
                    }
                    if ($hasCol) {
                        $colHits[] = [
                            'path' => $full,
                            'mtime' => $fmtTime(@filemtime($full)),
                            'size' => @filesize($full),
                            'sha1' => @sha1_file($full) ?: '?',
                        ];
                    }
                }
            }

            echo "  files examined: {$seen}" . ($capped ? "  *** SCAN CAPPED: {$reason} (results below are PARTIAL) ***" : "") . "\n";

            echo "  -- files named 'SongData.php' (" . count($songData) . ") --\n";
            if ($songData === []) {
                echo "    (none found under scan root)\n";
            }
            foreach ($songData as $f) {
                $isLoaded = ($loadedSongData !== null && (realpath($f['path']) ?: $f['path']) === $loadedSongData) ? '  <== the LOADED one' : '';
                echo "    {$f['path']}{$isLoaded}\n";
                echo "      mtime={$f['mtime']}  size={$f['size']}  sha1={$f['sha1']}  contains-s.SongbookName={$f['col']}\n";
            }
            if (count($songData) > 1) {
                echo "  *** MORE THAN ONE SongData.php ON DISK — a second copy may be shadowing the repo file. ***\n";
            }

            echo "  -- *.php containing 's.SongbookName' (" . count($colHits) . ") --\n";
            if ($colHits === []) {
                echo "    (none — no source on disk references the dropped column)\n";
            }
            foreach ($colHits as $f) {
                echo "    {$f['path']}\n";
                echo "      mtime={$f['mtime']}  size={$f['size']}  sha1={$f['sha1']}\n";
            }
        });

        /* ---- [D] OPCACHE CONFIG + FILE_CACHE ---------------------------- */
        /* opcache.file_cache is the dangerous one: a SECONDARY on-disk bytecode
           store that survives opcache_reset() and can resurrect stale code. Flag
           it loudly and, if set, scan it for any blob that mentions SongData. */
        $sec('D', 'OPCACHE CONFIG + FILE_CACHE', function () use ($fmtTime) {
            if (!function_exists('opcache_get_configuration')) {
                echo "  opcache_get_configuration() unavailable — OPcache not loaded.\n";
                return;
            }
            $cfg = @opcache_get_configuration();
            $d   = (is_array($cfg) && isset($cfg['directives'])) ? $cfg['directives'] : [];
            $show = static function (string $k) use ($d) {
                if (!array_key_exists($k, $d)) { return '(unset)'; }
                $v = $d[$k];
                if (is_bool($v)) { return $v ? 'true' : 'false'; }
                if ($v === '' || $v === null) { return '(empty)'; }
                return (string)$v;
            };
            echo "  opcache.enable:                        " . $show('opcache.enable') . "\n";
            echo "  opcache.validate_timestamps:           " . $show('opcache.validate_timestamps') . "\n";
            echo "  opcache.revalidate_freq:               " . $show('opcache.revalidate_freq') . "\n";
            echo "  opcache.file_cache:                    " . $show('opcache.file_cache') . "\n";
            echo "  opcache.file_cache_only:               " . $show('opcache.file_cache_only') . "\n";
            echo "  opcache.file_cache_consistency_checks: " . $show('opcache.file_cache_consistency_checks') . "\n";

            $fileCache = $d['opcache.file_cache'] ?? '';
            if (is_string($fileCache) && $fileCache !== '') {
                echo "  *** opcache.file_cache IS SET ({$fileCache}) — a secondary on-disk\n";
                echo "      bytecode cache that can RESURRECT stale code ACROSS opcache_reset(). ***\n";
                if (is_dir($fileCache)) {
                    $found = 0;
                    $cnt   = 0;
                    $it = null;
                    try {
                        $it = new \RecursiveIteratorIterator(
                            new \RecursiveDirectoryIterator($fileCache, \FilesystemIterator::SKIP_DOTS),
                            \RecursiveIteratorIterator::LEAVES_ONLY
                        );
                    } catch (\Throwable) { $it = null; }
                    if ($it !== null) {
                        foreach ($it as $p) {
                            if (++$cnt > 20000) { echo "      (file_cache scan capped at 20000 entries)\n"; break; }
                            $ps = (string)$p;
                            if (stripos($ps, 'SongData') !== false) {
                                $found++;
                                echo "      cached blob mentions SongData: {$ps}  mtime=" . $fmtTime(@filemtime($ps)) . "\n";
                            }
                        }
                    }
                    if ($found === 0) {
                        echo "      (scanned {$cnt} cached entries — none mention SongData)\n";
                    }
                } else {
                    echo "      (file_cache path is not a readable directory from this process)\n";
                }
            } else {
                echo "  opcache.file_cache is empty — no secondary bytecode store to resurrect stale code.\n";
            }
        });

        /* ---- [E] OPCACHE SCRIPT TABLE ----------------------------------- */
        /* The interned script table is the ground truth for "what bytecode is the
           runtime actually executing." Two distinct SongData paths cached = two
           copies being executed (multi-docroot or shadow file confirmed). */
        $sec('E', 'OPCACHE SCRIPT TABLE', function () use ($fmtTime) {
            if (!function_exists('opcache_get_status')) {
                echo "  opcache_get_status() unavailable — OPcache not loaded.\n";
                return;
            }
            $st = @opcache_get_status(true);
            if (!is_array($st)) {
                echo "  opcache_get_status(true) returned no data (restricted or disabled).\n";
                return;
            }
            echo "  opcache_enabled:   " . (!empty($st['opcache_enabled']) ? 'yes' : 'no') . "\n";
            echo "  num_cached_scripts: " . (int)($st['opcache_statistics']['num_cached_scripts'] ?? 0) . "\n";
            $scripts = $st['scripts'] ?? [];
            if (!is_array($scripts) || $scripts === []) {
                echo "  (no per-script table available — opcache.restrict_api may hide it, or list was not requested)\n";
                return;
            }
            $songPaths = [];
            $shown = 0;
            foreach ($scripts as $key => $info) {
                if (stripos((string)$key, 'SongData') === false) { continue; }
                if ($shown++ > 200) { echo "  (script-table SongData matches capped at 200)\n"; break; }
                $fp = $info['full_path'] ?? (string)$key;
                $songPaths[$fp] = true;
                echo "  SongData script: {$fp}\n";
                echo "    compiled(timestamp)=" . $fmtTime($info['timestamp'] ?? 0)
                   . "  last_used=" . (isset($info['last_used']) ? (string)$info['last_used'] : '?') . "\n";
            }
            if ($songPaths === []) {
                echo "  (no cached script path contains 'SongData')\n";
            } elseif (count($songPaths) > 1) {
                echo "  *** " . count($songPaths) . " DISTINCT SongData paths are cached — MORE THAN ONE COPY\n";
                echo "      IS BEING EXECUTED (multi-docroot or a shadow file). ***\n";
            }
        });

        /* ---- [F] CSRF / index.php DEPLOY CHECK -------------------------- */
        /* The editor's #1294 fix is an early $editorCsrf mint near the top of
           manage/editor/index.php. If that token is ABSENT from the file on disk,
           the deploy did NOT land this file — deploy-staleness confirmed, NOT a
           CSRF-logic bug. Also surface the session config that governs whether the
           token's cookie survives the editor's cross-page POST. */
        $sec('F', 'CSRF / editor index.php DEPLOY CHECK', function () use ($fmtTime) {
            $idx  = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'manage' . DIRECTORY_SEPARATOR
                  . 'editor' . DIRECTORY_SEPARATOR . 'index.php';
            $real = realpath($idx) ?: $idx;
            echo "  editor index.php: {$real}\n";
            if (is_file($real)) {
                echo "  filemtime: " . $fmtTime(filemtime($real)) . "\n";
                echo "  sha1_file: " . sha1_file($real) . "\n";
                $c = file_get_contents($real);
                if ($c !== false) {
                    $lines = preg_split('/\r\n|\r|\n/', $c);
                    $grep  = static function (string $needle) use ($lines) {
                        $hits = [];
                        foreach ($lines as $i => $ln) {
                            if (strpos($ln, $needle) !== false) { $hits[] = $i + 1; }
                        }
                        return $hits;
                    };
                    $csrfVar  = $grep('$editorCsrf');
                    $csrfCall = $grep('csrfToken()');
                    echo "  '\$editorCsrf' lines: " . ($csrfVar === [] ? '(NONE)' : implode(', ', $csrfVar)) . "\n";
                    echo "  'csrfToken()' lines: " . ($csrfCall === [] ? '(none)' : implode(', ', $csrfCall)) . "\n";
                    if ($csrfVar === []) {
                        echo "  *** '\$editorCsrf' early-mint is ABSENT — the #1294 deploy did NOT land\n";
                        echo "      this file. DEPLOY-STALENESS CONFIRMED for editor/index.php. ***\n";
                    } else {
                        echo "  '\$editorCsrf' early-mint IS present — this file is the #1294 version.\n";
                    }
                } else {
                    echo "  (could not read editor index.php contents)\n";
                }
            } else {
                echo "  editor index.php not found on disk at the expected path.\n";
            }

            $savePath = ini_get('session.save_path');
            echo "  session.cookie_samesite: " . (ini_get('session.cookie_samesite') ?: '(unset)') . "\n";
            echo "  session.save_path:       " . ($savePath !== false && $savePath !== '' ? $savePath : '(default/unset)') . "\n";
            if (is_string($savePath) && $savePath !== '') {
                /* save_path may carry an "N;/path" prefix; take the trailing path segment. */
                $sp = $savePath;
                if (strpos($sp, ';') !== false) { $sp = substr($sp, strrpos($sp, ';') + 1); }
                echo "  is_writable(save_path):  " . (@is_writable($sp) ? 'yes' : 'NO — session writes may be failing') . "\n";
            }
            $ss = session_status();
            echo "  session_status():        "
               . ($ss === PHP_SESSION_DISABLED ? 'DISABLED'
                  : ($ss === PHP_SESSION_NONE ? 'none (not started)'
                     : 'active')) . "\n";
        });

        /* ---- [G] BUILD MARKER ------------------------------------------- */
        /* The deploy pipeline sed-injects the commit SHA + date into infoAppVer.php.
           Reading it here tells the operator which commit the LIVE bytecode claims to
           be — cross-check against [B]/[F] file mtimes + the expected SHA. */
        $sec('G', 'BUILD MARKER (infoAppVer.php)', function () {
            $verPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes'
                     . DIRECTORY_SEPARATOR . 'infoAppVer.php';
            $real = realpath($verPath) ?: $verPath;
            echo "  infoAppVer.php: {$real}\n";
            if (!is_file($real)) {
                echo "  (not found on disk)\n";
                return;
            }
            $app = [];
            /* Loaded in an isolated scope: the file just assigns into $app. */
            require $real;
            $get = static function (array $keys) use ($app) {
                $v = $app;
                foreach ($keys as $k) {
                    if (!is_array($v) || !array_key_exists($k, $v)) { return null; }
                    $v = $v[$k];
                }
                return $v;
            };
            $version  = $get(['Application', 'Version', 'Number']);
            $shaFull  = $get(['Application', 'Version', 'Repo', 'Commit', 'SHA', 'Full']);
            $shaShort = $get(['Application', 'Version', 'Repo', 'Commit', 'SHA', 'Short']);
            $date     = $get(['Application', 'Version', 'Repo', 'Commit', 'Date']);
            $url      = $get(['Application', 'Version', 'Repo', 'Commit', 'URL']);
            echo "  version:     " . ($version ?? '(unset)') . "\n";
            echo "  commit SHA:  " . ($shaFull ?? '(NULL — not injected; running from un-deployed source?)') . "\n";
            echo "  commit short:" . ($shaShort ?? '(NULL)') . "\n";
            echo "  commit date: " . ($date ?? '(NULL — deploy sed did not run)') . "\n";
            echo "  commit URL:  " . ($url ?? '(NULL)') . "\n";
        });

        echo "\n=== END OF FORENSICS REPORT ===\n";
        $actionSuccess = true;
        if (function_exists('logActivity')) {
            logActivity('setup.deploy-forensics', 'database', 'deploy-forensics', ['via' => 'web'], 'success');
        }
    } else {
        $scriptName = $scriptMap[$action] ?? null;
        if ($scriptName === null) {
            echo "Unknown action: " . htmlspecialchars($action) . "\n";
        } elseif (!$hasCredentials && $action !== 'install') {
            echo "ERROR: Database credentials not configured.\n";
            echo "Configure appWeb/.auth/db_credentials.php first, or run Install.\n";
        } else {
            $scriptPath = $scriptDir . $scriptName;
            if (!file_exists($scriptPath)) {
                echo "ERROR: Script not found: {$scriptName}\n";
            } else {
                $singleErr = null;
                try {
                    /* Run the script in an isolated scope via an anonymous function.
                     * The scripts detect $isCli and adapt output accordingly.
                     * We catch any exceptions; exit() calls in the scripts will
                     * terminate this page but that's acceptable — the output
                     * buffer is flushed to the browser before exit. */
                    $actionSuccess = true;
                    require $scriptPath;
                    /* TRUTHFUL completion (same as the bulk path): the script may
                       have caught its OWN DDL error internally and returned. For a
                       migration action, verify via its registry probe — if still
                       pending, the apply did NOT take, so don't report success.
                       (Non-migration actions — install/backup/cleanup — have no
                       probe and are unaffected.) */
                    $singleProbe = $migrationProbes[$action] ?? null;
                    if (is_callable($singleProbe)) {
                        $singleConn = null;
                        try {
                            $singleConn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
                            $singleConn->set_charset('utf8mb4');
                        } catch (\Throwable) { $singleConn = null; }
                        if (_migVerify_stillPending($singleConn, $singleProbe) === true) {
                            $actionSuccess = false;
                            echo "\n⚠ This migration ran but is STILL PENDING — its objects were not created"
                               . " (the script caught an internal error above). Nothing was applied; see the"
                               . " [ERROR] line in the output above for the cause.\n";
                        }
                        if ($singleConn instanceof \mysqli) { $singleConn->close(); }
                    }
                } catch (\Throwable $e) {
                    $actionSuccess = false;
                    $singleErr = $e->getMessage();
                    echo "\nERROR: " . htmlspecialchars($e->getMessage()) . "\n";
                    if ($e->getFile()) {
                        echo "File: " . htmlspecialchars(basename($e->getFile())) . ":" . $e->getLine() . "\n";
                    }
                }
                /* Activity Log (#1282) — per-run status for the no-JS single-action path
                   (migrations + install/migrate/users/cleanup/backup/restore). */
                if (function_exists('logActivity')) {
                    $d = ['script' => $scriptName, 'via' => 'no-js'];
                    if ($singleErr !== null) { $d['error'] = $singleErr; }
                    logActivity('setup.run', 'database', $action, $d, $actionSuccess ? 'success' : 'error');
                }
            }
        }
    }

    $actionOutput = ob_get_clean();
    /* Echo the captured bulk output INSIDE the <div class="output-log">
       we opened above. Closing tags follow so the chrome wraps around
       it cleanly. */
    echo $actionOutput;
    ?>
    </div><!-- /.output-log -->

    <?php
        /* Update the running-badge to reflect the final state. The
           badge was rendered as "Running…" before the bulk started;
           swap it once the response can finalise. */
    ?>
    <script>
        (function () {
            var badge = document.getElementById('action-status-badge');
            if (!badge) return;
            badge.textContent = <?= $actionSuccess ? "'Complete'" : "'Error'" ?>;
            badge.className   = <?= $actionSuccess ? "'badge bg-success'" : "'badge bg-danger'" ?>;
        })();
    </script>

    <?php if ($action === 'apply-all-migrations' && $bulkBackupFailed): ?>
        <!-- Auto pre-migration backup failed → the run was aborted before
             any migration executed, so the schema is untouched. -->
        <div class="alert alert-danger mt-3" role="alert">
            <h5 class="alert-heading mb-2">
                <i aria-hidden="true" class="bi bi-shield-exclamation me-1"></i>
                Aborted — automatic pre-migration backup did not complete
            </h5>
            <p class="mb-0">
                No migrations were applied; your database is unchanged. The bulk
                run refuses to alter the schema without a recovery point. Run a
                manual <strong>Backup Database</strong> (or fix the cause — disk
                space / DB connection), then click <em>Apply all pending
                migrations</em> again.
            </p>
        </div>
    <?php endif; ?>

    <?php if ($action === 'apply-all-migrations' && $bulkFirstFailStep !== null): ?>
        <!-- Failure summary BELOW the panel (#817 round 2 — moved from
             above to below because chrome now opens before the panel
             does, and a banner above the panel would be rendered before
             the run completed and the failure data was available). -->
        <div class="alert alert-danger mt-3" role="alert">
            <h5 class="alert-heading mb-2">
                <i aria-hidden="true" class="bi bi-exclamation-triangle-fill me-1"></i>
                Bulk run failed at step:
                <code><?= htmlspecialchars((string)$bulkFirstFailStep) ?></code>
            </h5>
            <p class="mb-2">
                <strong><?= (int)$bulkTotalRan ?></strong>
                migration<?= $bulkTotalRan === 1 ? '' : 's' ?>
                completed before this step;
                <strong><?= (int)$bulkTotalFailed ?></strong>
                failed; remaining steps were not attempted.
            </p>
            <p class="mb-1">
                <strong>Cause:</strong>
                <code><?= htmlspecialchars((string)$bulkFirstFailMessage) ?></code>
            </p>
            <?php if ($bulkFirstFailFile !== ''): ?>
                <p class="mb-0 small text-muted">
                    At
                    <code><?= htmlspecialchars((string)$bulkFirstFailFile) ?>:<?= (int)$bulkFirstFailLine ?></code>
                    — full per-step output above.
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div><!-- /.container-admin -->
<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
<?php
    $pageRenderedCleanly = true;
    exit;
}

/* =========================================================================
 * DATABASE STATUS
 * ========================================================================= */

$dbStatus = null;
$dbTables = [];

/* Pending vs applied partition (#820). Populated by the probes inside
   the dbStatus block below; defaults to "everything pending" so a
   missing connection / probe failure shows the full grid (safe). */
$pendingActions = $migrationOrder;
$appliedActions = [];

if ($hasCredentials && defined('DB_HOST')) {
    try {
        $statusConn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
        $statusConn->set_charset('utf8mb4');
        $dbStatus = 'connected';

        $result = $statusConn->query("SHOW TABLES");
        while ($row = $result->fetch_array()) {
            $tableName = $row[0];
            $countResult = $statusConn->query("SELECT COUNT(*) AS cnt FROM `" . $statusConn->real_escape_string($tableName) . "`");
            $count = $countResult ? (int)$countResult->fetch_assoc()['cnt'] : 0;
            $dbTables[] = ['name' => $tableName, 'count' => $count];
        }

        /* Run pending-probes against the live schema (#820). Uses the
           same connection we just opened — a single round trip per
           probe, all under 100 ms cumulatively on a typical install.
           Any throw / unknown probe falls into "pending" so the card
           still appears (over-show is safe; under-hide is not). */
        $pendingActions = [];
        $appliedActions = [];
        foreach ($migrationOrder as $_action) {
            $_probe = $migrationProbes[$_action] ?? null;
            if ($_probe === null) {
                $pendingActions[] = $_action;
                continue;
            }
            try {
                if ($_probe($statusConn)) {
                    $pendingActions[] = $_action;
                } else {
                    $appliedActions[] = $_action;
                }
            } catch (\Throwable $_pe) {
                $pendingActions[] = $_action;
            }
        }

        $statusConn->close();
    } catch (\Throwable $e) {
        $dbStatus = 'error: ' . $e->getMessage();
        /* On connection failure, leave $pendingActions = $migrationOrder
           (set to default above) so the curator still sees the cards
           and can debug. */
    }
}

/* =========================================================================
 * RENDER PAGE
 * ========================================================================= */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <!-- Shared iHymns palette + admin styles -->
    <link rel="stylesheet" href="/css/app.css?v=<?= filemtime(dirname(__DIR__) . "/css/app.css") ?>">
    <link rel="stylesheet" href="/css/admin.css?v=<?= filemtime(dirname(__DIR__) . "/css/admin.css") ?>">
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

<?php if (!$isInitialSetup): ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>
<?php endif; ?>

<div class="container-admin py-4">

    <h1 class="mb-1">
        Database Setup<?= entitlementLockChipHtml('run_db_install') ?>
    </h1>
    <p class="text-secondary mb-4">
        Install the iHymns database, apply pending updates to it, and run backups and maintenance.
        <span class="badge bg-danger text-light ms-2" style="font-size: 0.7rem; font-weight: 600;">
            <i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Global Admin only
        </span>
    </p>

    <?php /* #2005 — "Guided setup" header trigger. ELI5: a friendlier front
             door onto this same page — it opens a step-by-step walkthrough
             that presses the SAME buttons found further down, in a sensible
             order, one at a time. Detailed: `data-bs-target="#setupWizardModal"`
             is the literal Bootstrap hook the standing guard
             tests/php/test-wizard-empty-state.php checks against the REAL
             `id="setupWizardModal"` modal element rendered later on this
             same page (see that element's own comment for why the modal
             shell lives HERE and not in the required step-panes partial).
             Always rendered — the wizard's own Step 1 is precisely "get a
             working database connection", so it must work even before one
             exists (mirrors the empty-state launcher just below). */ ?>
    <div class="mb-3">
        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#setupWizardModal">
            <i class="bi bi-magic me-1" aria-hidden="true"></i>Guided setup
        </button>
    </div>

    <?php if ($credSuccess !== ''): ?>
        <div class="alert alert-success"><?= htmlspecialchars($credSuccess) ?></div>
    <?php endif; ?>

    <?php if ($credError !== ''): ?>
        <div class="alert alert-danger"><strong>Error:</strong> <?= htmlspecialchars($credError) ?></div>
    <?php endif; ?>

    <?php
        $showCredForm = !$hasCredentials
            || (isset($_GET['reconfigure']) && $_GET['reconfigure'] === '1')
            || $credError !== '';

        /* #2005 — a render-only deep link (rule #33: honour it or don't
           emit it — this page does both). `?wizard=verify` is the ONLY
           value ever read; anything else (missing, mistyped, or an old
           bookmark) leaves the wizard closed on page load exactly like
           today. This is NOT a dispatched `$action` — it never reaches the
           per-action entitlement gate above or the migration-runner
           dispatch below, so it adds no new server action (the standing
           API-coverage guard's mapping for setup-database.php stays
           unchanged; tests/php/test-setup-wizard.php confirms this). */
        $wizardOpenStep = (($_GET['wizard'] ?? '') === 'verify') ? 'verify' : '';
    ?>

    <?php if (($isInitialSetup || !$hasCredentials) && function_exists('ihymns_wizard_empty_state')): ?>
        <?php /* #2005 — first-run discoverability (#1999 pattern): a
                 prominent "Get started" card above the credentials form
                 itself, reusing the SAME shared partial the other four
                 guided wizards already use rather than a bespoke card
                 (CLAUDE.md modularity rule). It opens the identical modal
                 the header button above already opens. */ ?>
        <?= ihymns_wizard_empty_state([
            'icon'        => 'bi-magic',
            'heading'     => 'New to this install? Start here.',
            'body'        => 'A short, guided walkthrough of connecting the database, bringing it '
                            . 'up to date, and what to do next — one step at a time.',
            'modalId'     => 'setupWizardModal',
            'buttonLabel' => 'Guided setup',
            'wrap'        => 'bare',
            'hint'        => 'Or fill in the connection details yourself below.',
        ]) ?>
    <?php endif; ?>

    <?php if ($action === '' && $showCredForm): ?>
        <!-- ============================================================
             DB CREDENTIALS FORM (#272)
             ============================================================ -->
        <div class="card bg-body-tertiary border-secondary mb-4">
            <div class="card-body">
                <h5 class="card-title mb-2">
                    <?= $hasCredentials ? 'Reconfigure Database Credentials' : 'Configure Database Credentials' ?>
                </h5>
                <p class="text-secondary small mb-3">
                    <?php if ($hasCredentials): ?>
                        Update the MySQL credentials used by iHymns. The connection is tested
                        before the file is overwritten.
                    <?php else: ?>
                        Enter your MySQL connection details. The connection is tested and, if
                        successful, written to <code>appWeb/.auth/db_credentials.php</code>
                        (permissions <code>0600</code>, outside the web root).
                    <?php endif; ?>
                </p>
                <form method="post" action="" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="save-credentials">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label small" for="db-cred-host">MySQL Host</label>
                            <input type="text" name="host" id="db-cred-host" class="form-control form-control-sm"
                                   required value="<?= htmlspecialchars($credFormValues['host']) ?>"
                                   placeholder="127.0.0.1 or mysql.example.com">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small" for="db-cred-port">Port</label>
                            <input type="number" name="port" id="db-cred-port" class="form-control form-control-sm"
                                   min="1" max="65535" required
                                   value="<?= htmlspecialchars($credFormValues['port']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small" for="db-cred-name">Database Name</label>
                            <input type="text" name="name" id="db-cred-name" class="form-control form-control-sm"
                                   required value="<?= htmlspecialchars($credFormValues['name']) ?>"
                                   placeholder="ihymns">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small" for="db-cred-user">Username</label>
                            <input type="text" name="user" id="db-cred-user" class="form-control form-control-sm"
                                   required value="<?= htmlspecialchars($credFormValues['user']) ?>"
                                   placeholder="ihymns_user">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small" for="db-cred-pass">Password</label>
                            <input type="password" name="pass" id="db-cred-pass" class="form-control form-control-sm"
                                   autocomplete="new-password"
                                   placeholder="<?= $hasCredentials ? '(leave blank to keep existing)' : '' ?>">
                            <?php if ($hasCredentials): ?>
                                <div class="form-text small text-secondary">
                                    Leave blank and we'll preserve the existing password only if the test connection succeeds without one.
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small" for="db-cred-prefix">Table Prefix <span class="text-secondary">(optional)</span></label>
                            <input type="text" name="prefix" id="db-cred-prefix" class="form-control form-control-sm"
                                   value="<?= htmlspecialchars($credFormValues['prefix']) ?>"
                                   placeholder="e.g. ih_">
                        </div>
                    </div>
                    <div class="mt-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            Test &amp; Save Credentials
                        </button>
                        <?php if ($hasCredentials): ?>
                            <a href="?" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    <?php elseif (!$hasCredentials): ?>
        <div class="alert alert-danger">
            <strong>Credentials not found.</strong>
            Configure database credentials to continue.
        </div>
    <?php endif; ?>

    <?php
        /* Action path note (#817 round 2): when $action !== '' the page
           is fully self-rendered higher up — DOCTYPE through
           admin-footer.php — and `exit`s before reaching this block.
           So everything below this point only ever runs on the
           dashboard view. The legacy "if action then run, else show
           dashboard" wrapper around the dashboard cards has been
           removed accordingly.

           [CI note: this comment used to spell out the legacy
           wrapper using literal PHP open tags inside backticks, which
           tripped Step 5a of CI Lint & Validate — the guard added
           after PR #536 to catch the embedded-tag-execution bug.
           Re-worded prose-only to keep the guard a hard error.] */
    ?>
        <!-- ============================================================
             ONE-STEP MIGRATIONS RUNNER (#577)
             Runs every migration script in dependency order. Each
             script is idempotent so re-running is safe; the runner
             stops on the first hard failure and reports which one.
             Sits ABOVE the per-step cards so admins reach for this
             first, only dropping into individual cards when they
             need to debug a specific step.
             ============================================================ -->
        <?php
            /* Pending count for the Apply-all banner (#820). When the
               schema is fully up-to-date we surface that explicitly so
               an operator scanning the page knows there's nothing to do
               without scrolling the card grid. Cards from $migrationOrder
               without a $migrationCards entry don't count — they're
               skip-only entries (or not yet defined). */
            $_pendingCardCount = count(array_filter(
                $pendingActions,
                /* #1235 P4/C6 — exclude manual/destructive migrations (the JSON-column drop is
                   pending-by-design until a human runs it): so the counter still reaches zero
                   once all AUTO migrations are applied. */
                static fn(string $slug): bool => isset($migrationCards[$slug]) && empty($migrationManual[$slug])
            ));
            $_alertVariant = $_pendingCardCount > 0 ? 'alert-primary' : 'alert-success';
        ?>
        <div class="alert <?= $_alertVariant ?> border-0 mb-4 d-flex flex-column flex-md-row gap-3 align-items-md-center justify-content-between">
            <div>
                <h5 class="mb-1">
                    <?php if ($_pendingCardCount > 0): ?>
                        <i class="bi bi-collection-play me-2" aria-hidden="true"></i>
                        Apply all pending migrations
                        <span class="badge bg-warning text-dark ms-2" style="font-size: 0.75rem;">
                            <?= $_pendingCardCount ?> pending
                        </span>
                    <?php else: ?>
                        <i class="bi bi-check2-circle me-2" aria-hidden="true"></i>
                        Schema fully up-to-date
                    <?php endif; ?>
                </h5>
                <p class="mb-0 small">
                    <?php if ($_pendingCardCount > 0): ?>
                        Runs every <code>migrate-*.php</code> in dependency order,
                        skipping ones already applied (each migration is idempotent).
                        Stops on the first hard failure with a clear pointer to the
                        offending step. Use this on a fresh install, after a deploy,
                        or whenever the schema-audit page (#518) shows drift.
                    <?php else: ?>
                        Every migration's pending-probe (#820) reports its work as
                        applied. The "Apply all" button still works (a bulk re-run
                        is a safe no-op); previously-applied cards are tucked into
                        the expander below for diagnostic re-runs.
                    <?php endif; ?>
                </p>
            </div>
            <?php
                /* #869 — pending-migration list serialised onto the
                   button so the per-migration AJAX runner can pick
                   it up without a second round-trip. Comma-separated
                   to avoid HTML-escaping JSON-with-quotes inside an
                   attribute. The legacy href stays in place as a
                   no-JS fallback (with the chrome / footer / badge
                   fixes from #862 / #863 / #868 / #870 / #871 to
                   handle the timeout edge case). */
                $bulkRunnerPending = array_values(array_filter(
                    $pendingActions,
                    /* #1235 P4/C6 — manual/destructive migrations are NEVER bulk-run targets. */
                    static fn(string $slug): bool => isset($migrationCards[$slug]) && empty($migrationManual[$slug])
                ));
            ?>
            <a href="?action=apply-all-migrations&amp;csrf_token=<?= urlencode(csrfToken()) ?>"
               class="btn btn-primary btn-lg flex-shrink-0 <?= $hasCredentials ? '' : 'disabled' ?>"
               data-bulk-runner-trigger
               data-pending-migrations="<?= htmlspecialchars(implode(',', $bulkRunnerPending), ENT_QUOTES, 'UTF-8') ?>">
                <i class="bi bi-play-fill me-1" aria-hidden="true"></i>
                Apply all
            </a>
        </div>

        <?php if (!empty($pendingActions)): ?>
            <!-- ============================================================
                 PENDING-MIGRATION LIST (#1200)
                 Lists EVERY migration whose probe currently reports pending —
                 including any that have NO card (so they're invisible in the
                 grid AND skipped by the bulk runner, which both filter to
                 isset($migrationCards[...])). A migration that runs but whose
                 probe never flips, or one with no card, is exactly what keeps
                 the counter stuck above zero. Each row has a "Run & show
                 output" link: a single-migration run renders its full output
                 INLINE (no auto-refresh), so the curator can read the
                 "[warn] … manual merge / skipped" lines that explain why a
                 probe stays pending.
                 ============================================================ -->
            <details class="mb-4">
                <summary class="text-secondary small" style="cursor:pointer;">
                    Show the <?= count($pendingActions) ?> pending migration<?= count($pendingActions) === 1 ? '' : 's' ?>
                    (which, and why each is still pending)
                </summary>
                <ul class="list-group list-group-flush mt-2">
                    <?php foreach ($pendingActions as $_pa): ?>
                        <?php $_paCard = $migrationCards[$_pa] ?? null; ?>
                        <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center gap-2 flex-wrap">
                            <span>
                                <code class="text-info"><?= htmlspecialchars($_pa) ?></code>
                                <?php if ($_paCard): ?>
                                    <span class="ms-2"><?= htmlspecialchars(strip_tags((string)$_paCard['title'])) ?></span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark ms-2">
                                        no card — hidden from the grid &amp; skipped by &ldquo;Apply all&rdquo;
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($migrationManual[$_pa])): ?>
                                    <span class="badge bg-danger ms-2"><?=
                                        !empty($migrationDryRunnable[$_pa])
                                            ? 'manual data rewrite — run by hand, not by &ldquo;Apply all&rdquo;'
                                            : 'manual &amp; destructive — run by hand, not by &ldquo;Apply all&rdquo;'
                                    ?></span>
                                <?php endif; ?>
                            </span>
                            <?php
                                /* #1235 P4/C6 — a manual/destructive migration's run link carries
                                   confirm=1 (the script refuses without it) + a confirm() dialog.
                                   #1298 — plus the type-to-confirm speed-bump (data attribute carries
                                   the exact slug the operator must type). Layered on top of the
                                   server-side confirm=1 / sentinel gate, never instead of it.
                                   #1380 FIX 4 — a `dryRunnable` manual migration (the SongId backfill)
                                   tailors the confirm() copy to "applies the data rewrite" and gets a
                                   distinct report-only run link (below) without &confirm=1. */
                                $_paManual  = !empty($migrationManual[$_pa]);
                                $_paDryRun  = !empty($migrationDryRunnable[$_pa]);
                                /* L-1 security-audit finding — every state-changing ?action= link
                                   on this page now carries its own CSRF token (see the CSRF GUARD
                                   doc-block near the top of this file for the full "why"). */
                                $_paCsrf    = '&amp;csrf_token=' . urlencode(csrfToken());
                                $_paHref    = '?action=' . htmlspecialchars($_pa) . ($_paManual ? '&amp;confirm=1' : '') . $_paCsrf;
                                $_paOnclick = $_paManual
                                    ? ($_paDryRun
                                        ? ' onclick="return confirm(\'This APPLIES the data rewrite immediately (it mutates the database). Use the Dry-run link first to preview. Continue?\');"'
                                        : ' onclick="return confirm(\'This is a DESTRUCTIVE, irreversible migration. It only proceeds behind the pre-drop verification gate. Continue?\');"')
                                    : '';
                                $_paType    = $_paManual
                                    ? ' data-type-to-confirm="' . htmlspecialchars($_pa, ENT_QUOTES) . '"'
                                    : '';
                            ?>
                            <span class="d-flex gap-2 flex-shrink-0">
                                <?php if ($_paManual && $_paDryRun): ?>
                                    <a href="?action=<?= htmlspecialchars($_pa) ?><?= $_paCsrf ?>"
                                       class="btn btn-sm btn-outline-info <?= $hasCredentials ? '' : 'disabled' ?>">
                                        Dry-run (report only)
                                    </a>
                                <?php endif; ?>
                                <a href="<?= $_paHref ?>"<?= $_paOnclick ?><?= $_paType ?>
                                   class="btn btn-sm <?= $_paManual ? 'btn-danger' : 'btn-outline-info' ?> <?= $hasCredentials ? '' : 'disabled' ?>">
                                    Run &amp; show output
                                </a>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="text-muted small mt-2 mb-0">
                    A single-migration run shows its full output here without auto-refreshing, so you can read
                    exactly what it did &mdash; including any <code>[warn]</code> lines (e.g. &ldquo;manual merge needed&rdquo;
                    or &ldquo;skipped&rdquo;) that leave a probe reporting pending even though the script ran cleanly.
                </p>
            </details>
        <?php endif; ?>

        <!-- ============================================================
             ACTION CARDS
             ============================================================ -->
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card bg-body-tertiary border-secondary h-100">
                    <div class="card-body">
                        <h5 class="card-title">1. Install Tables</h5>
                        <p class="card-text text-secondary small">
                            Create all database tables from <code>schema.sql</code>.
                            Safe to re-run — existing tables are skipped.
                        </p>
                        <a href="?action=install&amp;csrf_token=<?= urlencode(csrfToken()) ?>" class="btn btn-primary btn-action <?= $hasCredentials ? '' : 'disabled' ?>">
                            Run Install
                        </a>
                    </div>
                </div>
            </div>
            <!-- #1614 — the "Migrate Song Data" card (?action=migrate, migrate-json.php)
                 was retired here. It was the LEGACY pre-#1010 songs.json bootstrap
                 importer, already dead twice over: it aborts once
                 tblSongComponents.LinesJson is gone (the #1235 P4/C6 retired-era guard),
                 AND schema.sql now ships the thin tblSongComponents with none of the four
                 JSON payload columns, so a fresh install trips that guard immediately —
                 its only stated purpose (bootstrapping a new install) was no longer
                 achievable. A fresh install now uses: Install Tables (schema.sql) below →
                 "Apply all pending" on the registry migration cards → content via the
                 Song Editor's bulk importers, or restore.php from a real backup. -->
            <div class="col-md-6">
                <div class="card bg-body-tertiary border-secondary h-100">
                    <div class="card-body">
                        <h5 class="card-title">2. Migrate Users &amp; Setlists</h5>
                        <p class="card-text text-secondary small">
                            Import users and setlists from the legacy SQLite database
                            and shared setlist JSON files. Skips existing users.
                        </p>
                        <a href="?action=users&amp;csrf_token=<?= urlencode(csrfToken()) ?>" class="btn btn-info btn-action <?= $hasCredentials ? '' : 'disabled' ?>">
                            Run User Migration
                        </a>
                    </div>
                </div>
            </div>

            <!-- #1235 lyrics-cutover verification gate — web runner (the owner is
                 web-only on shared DreamHost, so the gate that arms the drop must be
                 runnable here, not just from the CLI). -->
            <div class="col-12">
                <div class="card bg-body-tertiary border-info h-100">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="bi bi-shield-check me-1" aria-hidden="true"></i>
                            Verify Lyrics-Cutover Gate
                            <span class="badge bg-info-subtle text-info-emphasis">#1235</span>
                        </h5>
                        <p class="card-text text-secondary small mb-3">
                            Runs <code>verify-lyrics-cutover.php</code> against the live corpus — the
                            losslessness proof (10 gates incl. the byte-identical <strong>G2</strong>)
                            that ARMS the destructive JSON-column drop. <strong>pre</strong> establishes
                            the baseline and writes the green sentinel the drop requires; run
                            <strong>soak</strong> repeatedly during the soak (parity must stay 0).
                            <strong>pre-drop</strong> / <strong>post-drop</strong> are for the
                            maintenance-freeze drop day only. A full-corpus run can take a minute or two.
                        </p>
                        <p class="card-text small mb-3">
                            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                            <strong>A complete run ends with</strong> a <code>== GREEN — phase … passed ==</code>
                            (or <code>RED</code>) line, and <code>pre</code>/<code>pre-drop</code> add
                            <code>sentinel: lyrics_cutover_gate written (green)</code>. If you don't see that
                            trailer, the request was cut short (a shared-host timeout) — nothing was armed;
                            just run it again. Use <em>Smoke test</em> first to confirm the path in seconds.
                        </p>
                        <?php /* L-1 security-audit finding — one shared token for all five
                                 verify-cutover links below (same reasoning as the CSRF GUARD
                                 doc-block near the top of this file). */
                              $_vcCsrf = '&amp;csrf_token=' . urlencode(csrfToken()); ?>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="?action=verify-cutover&amp;phase=pre&amp;limit=50<?= $_vcCsrf ?>" class="btn btn-outline-info btn-action <?= $hasCredentials ? '' : 'disabled' ?>">
                                Smoke test <span class="small ms-1">(first 50 songs — no sentinel)</span>
                            </a>
                            <a href="?action=verify-cutover&amp;phase=pre<?= $_vcCsrf ?>" class="btn btn-success btn-action <?= $hasCredentials ? '' : 'disabled' ?>">
                                Run <code>--phase=pre</code> <span class="small ms-1">(baseline + sentinel)</span>
                            </a>
                            <a href="?action=verify-cutover&amp;phase=soak<?= $_vcCsrf ?>" class="btn btn-outline-success btn-action <?= $hasCredentials ? '' : 'disabled' ?>">
                                Run <code>--phase=soak</code>
                            </a>
                            <a href="?action=verify-cutover&amp;phase=pre-drop<?= $_vcCsrf ?>" class="btn btn-outline-warning btn-action <?= $hasCredentials ? '' : 'disabled' ?>"
                               onclick="return confirm('pre-drop writes the sentinel that ARMS the irreversible JSON-column drop. Only run this inside the maintenance freeze, immediately before the drop. Continue?');">
                                Run <code>--phase=pre-drop</code>
                            </a>
                            <a href="?action=verify-cutover&amp;phase=post-drop<?= $_vcCsrf ?>" class="btn btn-outline-secondary btn-action <?= $hasCredentials ? '' : 'disabled' ?>">
                                Run <code>--phase=post-drop</code>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================================
                 PER-MIGRATION CARDS (#816)

                 Data-driven render: cards iterate $migrationOrder so the
                 grid matches the deployment / dependency order the
                 "Apply all" runner uses. Each card identifies itself by
                 issue number — the legacy alphabetic-prefix scheme
                 (3a, 3b, … 3z, 3y2) was non-monotonic and rotted on
                 every addition.

                 #820 — pending vs applied partition. $pendingActions
                 (cards whose work the schema probes detect as not yet
                 applied) render normally above the fold;
                 $appliedActions roll into a <details> expander below
                 so a fully-up-to-date database shows just the few
                 cards an admin needs to act on, not all 25.

                 To add a new migration card, add an entry to
                 $migrationCards above, append its action key to
                 $migrationOrder, and add a probe to $migrationProbes
                 so the partition can detect it.
                 ============================================================ -->
            <?php
                /* Render helper — single card markup reused for both
                   the pending grid and the inside-expander grid. */
                $_renderCard = static function (string $migAction, array $card, bool $hasCreds, bool $isManual = false, bool $isDryRunnable = false): void {
                    /* #1235 P4/C6 — a manual/destructive migration's run link carries confirm=1
                       (the script REFUSES a web run without it) + a JS confirm() dialog, mirroring
                       the drop-legacy pattern; the button is danger-styled and the card flagged.
                       ADDITIONAL client speed-bump (#1298): the anchor also carries
                       data-type-to-confirm="<slug>" so the shared inline JS forces the operator to
                       type the exact migration slug before the destructive request fires. This is
                       layered ON TOP of the server-side confirm=1 / sentinel gate — never instead.

                       #1380 FIX 4 — a manual migration that is ALSO `dryRunnable` (the canonical
                       SongId backfill) is a DATA REWRITE, not a schema drop: its mutate button
                       still carries confirm=1 + type-to-confirm, but the operator needs a real
                       report-first path too. So we ALSO render a distinct "Dry-run (report only)"
                       link WITHOUT &confirm=1 (and without the type-to-confirm), and we tailor the
                       confirm() / badge wording away from the destructive-DROP defaults (which talk
                       about a "pre-drop verification gate" that doesn't apply here). */
                    /* L-1 security-audit finding — a static closure has no
                       access to an outer-scope variable without `use()`, but
                       csrfToken() is a plain global function (declared in
                       manage/includes/auth.php, already `require_once`d at
                       the top of this file), so calling it directly here
                       needs no `use()` at all. */
                    $csrfQs   = '&amp;csrf_token=' . urlencode(csrfToken());
                    $href    = '?action=' . htmlspecialchars($migAction) . ($isManual ? '&amp;confirm=1' : '') . $csrfQs;
                    $btnClass = $isManual ? 'btn-danger' : 'btn-info';
                    $onclick = $isManual
                        ? ($isDryRunnable
                            ? ' onclick="return confirm(\'This APPLIES the data rewrite immediately (it mutates the database). Run the Dry-run link first to preview. Continue?\');"'
                            : ' onclick="return confirm(\'This is a DESTRUCTIVE, irreversible migration. It only proceeds if the pre-drop verification gate is green and &lt;24h old. Continue?\');"')
                        : '';
                    /* The required phrase IS the migration slug — shown to the operator in the
                       prompt and matched case-sensitively. htmlspecialchars guards the attribute. */
                    $typeToConfirm = $isManual
                        ? ' data-type-to-confirm="' . htmlspecialchars($migAction, ENT_QUOTES) . '"'
                        : '';
                    /* #1380 FIX 4 — the report-only run link: same action, NO &confirm=1 → the
                       script stays in dry-run and only reports. No type-to-confirm / native
                       confirm() (a read-only report needs no speed-bump). */
                    $dryRunHref = '?action=' . htmlspecialchars($migAction) . $csrfQs;
                    ?>
                    <div class="col-md-6">
                        <div class="card bg-body-tertiary <?= $isManual ? 'border-danger' : 'border-secondary' ?> h-100">
                            <div class="card-body">
                                <h5 class="card-title"><?= $card['title'] ?></h5>
                                <?php if ($isManual): ?>
                                    <span class="badge bg-danger mb-2"><?=
                                        $isDryRunnable
                                            ? 'Manual data rewrite — NOT run by &ldquo;Apply all&rdquo;'
                                            : 'Manual &amp; destructive — NOT run by &ldquo;Apply all&rdquo;'
                                    ?></span>
                                <?php endif; ?>
                                <p class="card-text text-secondary small"><?= $card['body'] ?></p>
                                <?php if ($isManual && $isDryRunnable): ?>
                                    <a href="<?= $dryRunHref ?>"
                                       class="btn btn-outline-info btn-action me-2 <?= $hasCreds ? '' : 'disabled' ?>">
                                        Dry-run (report only)
                                    </a>
                                <?php endif; ?>
                                <a href="<?= $href ?>"<?= $onclick ?><?= $typeToConfirm ?>
                                   class="btn <?= $btnClass ?> btn-action <?= $hasCreds ? '' : 'disabled' ?>">
                                    <?= htmlspecialchars($card['button']) ?>
                                </a>
                                <?php if (!empty($card['extra_html'])): ?>
                                    <?= $card['extra_html'] ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php
                };
            ?>

            <?php foreach ($pendingActions as $_migAction): ?>
                <?php
                    $_card = $migrationCards[$_migAction] ?? null;
                    if (!$_card) continue;     /* slug in $migrationOrder but no card body */
                    $_renderCard($_migAction, $_card, $hasCredentials, !empty($migrationManual[$_migAction]), !empty($migrationDryRunnable[$_migAction]));
                ?>
            <?php endforeach; ?>

            <?php
                /* Filter applied list to slugs that actually have card
                   bodies (some $migrationOrder entries — like the
                   removed recompute step — have no card). The expander
                   should only count rows it can actually render. */
                $_appliedRenderable = array_values(array_filter(
                    $appliedActions,
                    static fn(string $slug): bool => isset($migrationCards[$slug])
                ));
            ?>
            <?php if (!empty($_appliedRenderable)): ?>
                <div class="col-12">
                    <details class="card bg-body-tertiary border-secondary applied-migrations-details">
                        <summary class="card-header d-flex align-items-center gap-2 text-secondary small" style="cursor: pointer;">
                            <i class="bi bi-check2-circle text-success" aria-hidden="true"></i>
                            <span>
                                <strong><?= count($_appliedRenderable) ?></strong>
                                migration<?= count($_appliedRenderable) === 1 ? '' : 's' ?>
                                already applied — click to show
                            </span>
                            <span class="ms-auto small text-muted">
                                Re-running an applied migration is a safe no-op.
                            </span>
                        </summary>
                        <div class="card-body">
                            <div class="row g-3">
                                <?php foreach ($_appliedRenderable as $_appliedAction): ?>
                                    <?php $_renderCard($_appliedAction, $migrationCards[$_appliedAction], $hasCredentials, !empty($migrationManual[$_appliedAction]), !empty($migrationDryRunnable[$_appliedAction])); ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </details>
                </div>
            <?php endif; ?>

            <div class="col-md-6">
                <div class="card bg-body-tertiary border-secondary h-100">
                    <div class="card-body">
                        <h5 class="card-title">3. Cleanup Expired Tokens</h5>
                        <p class="card-text text-secondary small">
                            Delete expired API tokens, email login codes, password reset
                            tokens, and old login attempts (30+ days).
                        </p>
                        <a href="?action=cleanup&amp;csrf_token=<?= urlencode(csrfToken()) ?>" class="btn btn-outline-secondary btn-action <?= $hasCredentials ? '' : 'disabled' ?>">
                            Run Cleanup
                        </a>
                    </div>
                </div>
            </div>

            <!-- #1290 — reset the PHP runtime code cache. The fix for "deployed code
                 isn't running" on shared hosting (stale OPcache). -->
            <div class="col-md-6">
                <div class="card bg-body-tertiary border-secondary h-100">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="bi bi-cpu me-1" aria-hidden="true"></i>
                            Reset PHP OPcache
                        </h5>
                        <p class="card-text text-secondary small">
                            Forces every PHP file to recompile from disk. Use after a deploy when the
                            live site runs <strong>stale code</strong> (e.g. errors that reference a
                            column already dropped). Reports the OPcache status — incl.
                            <code>validate_timestamps</code> — before resetting. No DB needed.
                        </p>
                        <a href="?action=opcache-reset&amp;csrf_token=<?= urlencode(csrfToken()) ?>" class="btn btn-outline-warning btn-action">
                            Reset OPcache
                        </a>
                    </div>
                </div>
            </div>

            <!-- #1295 — READ-ONLY deploy forensics. Definitively reveals WHY deployed
                 code isn't running: stale disk files, a second/shadow SongData.php,
                 an opcache.file_cache resurrecting bytecode, or multiple app servers.
                 Writes nothing — no reset, no DB mutation. Run twice to spot multi-server. -->
            <div class="col-md-6">
                <div class="card bg-body-tertiary border-secondary h-100">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="bi bi-search me-1" aria-hidden="true"></i>
                            Deployment Forensics
                        </h5>
                        <p class="card-text text-secondary small">
                            <strong>Read-only.</strong> Fingerprints the SongData.php the runtime
                            actually loaded, scans the deploy tree for shadow copies, dumps the
                            OPcache script table + <code>file_cache</code>, checks the editor
                            CSRF deploy and reports the live build SHA. Use to diagnose
                            "deployed code isn't running" — writes nothing. Run twice to detect
                            multiple app servers.
                        </p>
                        <a href="?action=deploy-forensics" class="btn btn-outline-warning btn-action">
                            Run Forensics
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card bg-body-tertiary border-secondary h-100">
                    <div class="card-body">
                        <h5 class="card-title">4. Backup Database</h5>
                        <p class="card-text text-secondary small">
                            Create a compressed SQL dump of all tables and data.
                            Keeps the last 7 backups; older ones are auto-deleted.
                        </p>
                        <a href="?action=backup&amp;csrf_token=<?= urlencode(csrfToken()) ?>" class="btn btn-outline-info btn-action <?= $hasCredentials ? '' : 'disabled' ?>">
                            Run Backup
                        </a>
                    </div>
                </div>
            </div>
            <?php
                /* List available backups for restore (#405). */
                $backupFiles = [];
                $backupDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data_share' . DIRECTORY_SEPARATOR . 'backups';
                if (is_dir($backupDir)) {
                    foreach (scandir($backupDir) ?: [] as $f) {
                        if (preg_match('/^ihymns-backup-[0-9-]+\.sql(?:\.gz)?$/', $f)) {
                            $backupFiles[] = $f;
                        }
                    }
                    rsort($backupFiles);
                }

                /* Success flash for the manual delete-a-backup control (#1509).
                   The delete handler near the top of this file (search
                   "DELETE A BACKUP (#1509)") does its work + unlink() BEFORE
                   any HTML renders, then PRG-redirects to `?backup_deleted=
                   <name>` so a page refresh can't re-submit the delete. The
                   filename was already validated against the strict backup
                   pattern before the redirect was issued; basename() here is
                   defence-in-depth only, and the value is htmlspecialchars()'d
                   at render time same as $uploadMsg below. */
                $deleteBackupMsg = '';
                if (!empty($_GET['backup_deleted'])) {
                    $deleteBackupMsg = 'Deleted backup: ' . basename((string)$_GET['backup_deleted']);
                }

                /* Handle an admin-supplied upload (#405). Accepts .sql + .sql.gz
                   files matching the backup naming pattern, drops them into
                   the server's backups directory, and logs the upload. */
                $uploadMsg = '';
                if ($_SERVER['REQUEST_METHOD'] === 'POST'
                    && ($_POST['action'] ?? '') === 'upload-backup'
                    && !empty($_FILES['backup']['name'])) {
                    $f = $_FILES['backup'];
                    $safeName = basename((string)$f['name']);
                    if ($f['error'] !== UPLOAD_ERR_OK) {
                        $uploadMsg = 'Upload failed (error ' . (int)$f['error'] . ').';
                    } elseif (!preg_match('/^ihymns-backup-[0-9-]+\.sql(?:\.gz)?$/', $safeName)) {
                        $uploadMsg = 'Filename must match ihymns-backup-YYYYMMDD-HHMMSS.sql(.gz).';
                    } elseif ((int)$f['size'] > 256 * 1024 * 1024) {
                        $uploadMsg = 'Upload rejected: file exceeds 256 MB.';
                    } else {
                        if (!is_dir($backupDir)) { @mkdir($backupDir, 0755, true); }
                        $dest = $backupDir . DIRECTORY_SEPARATOR . $safeName;
                        if (move_uploaded_file($f['tmp_name'], $dest)) {
                            @chmod($dest, 0640);
                            $uploadMsg = 'Uploaded: ' . $safeName . ' — pick it from the list below to restore.';
                            /* Audit-log entry (#405). Silent no-op if the table is
                               absent or the activity log helper isn't available. */
                            try {
                                $auditDb = getDbMysqli();
                                /* Column was previously named ActionType which
                                   never existed on the schema (#535) — the
                                   real column is `Action`, and we now also
                                   pass EntityType + EntityId so the row
                                   shows up correctly in the activity-log
                                   viewer. Details is JSON-typed so we encode
                                   the metadata structurally rather than as
                                   a free-form string. */
                                $auditUser = $currentUser['username'] ?? 'unknown';
                                $auditDetails = json_encode([
                                    'filename'   => $safeName,
                                    'size_bytes' => (int)$f['size'],
                                    'uploaded_by' => $auditUser,
                                ], JSON_UNESCAPED_SLASHES);
                                $stmt = $auditDb->prepare(
                                    'INSERT INTO tblActivityLog
                                        (UserId, Action, EntityType, EntityId, Details, IpAddress)
                                     VALUES (?, ?, ?, ?, ?, ?)'
                                );
                                if ($stmt) {
                                    $uid    = isset($currentUser['id']) ? (int)$currentUser['id'] : null;
                                    $action = 'backup.upload';
                                    $entityType = 'backup';
                                    $entityId   = $safeName;
                                    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                                    $stmt->bind_param('isssss', $uid, $action, $entityType, $entityId, $auditDetails, $ip);
                                    @$stmt->execute();
                                    $stmt->close();
                                }
                            } catch (\Throwable $_e) { /* best effort */ }
                            /* Refresh the file list so the uploaded file appears
                               in the dropdown immediately. */
                            $backupFiles = [];
                            foreach (scandir($backupDir) ?: [] as $bf) {
                                if (preg_match('/^ihymns-backup-[0-9-]+\.sql(?:\.gz)?$/', $bf)) {
                                    $backupFiles[] = $bf;
                                }
                            }
                            rsort($backupFiles);
                        } else {
                            $uploadMsg = 'Could not save the uploaded file.';
                        }
                    }
                }
            ?>
            <div class="col-md-6">
                <div class="card bg-body-tertiary border-danger h-100">
                    <div class="card-body">
                        <h5 class="card-title">5. Restore from Backup</h5>
                        <p class="card-text text-secondary small">
                            Replace every table in the database with data from a previous backup.
                            <strong>Destructive — consider running a fresh Backup first.</strong>
                        </p>
                        <?php if ($uploadMsg): ?>
                            <div class="alert alert-info py-2 small"><?= htmlspecialchars($uploadMsg) ?></div>
                        <?php endif; ?>
                        <?php /* Delete-a-backup flash + error (#1509) — success comes back
                                 via the PRG `?backup_deleted=` redirect; error is set inline
                                 (no redirect) by the delete handler near the top of this file. */ ?>
                        <?php if ($deleteBackupMsg): ?>
                            <div class="alert alert-success py-2 small"><?= htmlspecialchars($deleteBackupMsg) ?></div>
                        <?php endif; ?>
                        <?php if ($deleteBackupError): ?>
                            <div class="alert alert-danger py-2 small"><?= htmlspecialchars($deleteBackupError) ?></div>
                        <?php endif; ?>

                        <?php if ($backupFiles): ?>
                            <!-- Download a backup to keep off-site (#1255). Streams the
                                 server-side .sql.gz from data_share/backups/ (outside the
                                 web root) via the gated download-backup handler at the top
                                 of this page. No DB connection needed, so it stays enabled
                                 even when credentials are unset. -->
                            <label class="form-label small text-secondary mb-1" for="backup-download-file">
                                <i aria-hidden="true" class="bi bi-download me-1"></i>Download a backup (to archive off-site):
                            </label>
                            <form action="" method="post" class="d-flex gap-2 flex-wrap mb-3">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                                <input type="hidden" name="action" value="download-backup">
                                <select name="file" id="backup-download-file" class="form-select form-select-sm" style="flex:1 1 200px">
                                    <?php foreach ($backupFiles as $f): ?>
                                        <option value="<?= htmlspecialchars($f) ?>"><?= htmlspecialchars($f) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-sm btn-outline-success">
                                    Download
                                </button>
                            </form>

                            <label class="form-label small text-secondary mb-1" for="backup-restore-file">
                                <i aria-hidden="true" class="bi bi-arrow-counterclockwise me-1"></i>Restore the database from a backup:
                            </label>
                            <?php /* L-1 security-audit finding — the ONE GET <form> on this page
                                     that triggers a state-changing action: a hidden field is how a
                                     GET form carries a token in its query string (there's no href to
                                     append one to). */ ?>
                            <form action="" method="get" class="d-flex gap-2 flex-wrap mb-2">
                                <input type="hidden" name="action" value="restore">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <select name="file" id="backup-restore-file" class="form-select form-select-sm" style="flex:1 1 200px">
                                    <?php foreach ($backupFiles as $f): ?>
                                        <option value="<?= htmlspecialchars($f) ?>"><?= htmlspecialchars($f) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-sm btn-outline-warning <?= $hasCredentials ? '' : 'disabled' ?>">Preview</button>
                                <button type="submit" name="preflight" value="1"
                                        class="btn btn-sm btn-outline-info <?= $hasCredentials ? '' : 'disabled' ?>"
                                        title="Parse the backup and show a summary without touching the database (#405)">
                                    Pre-flight
                                </button>
                                <button type="submit" name="confirm" value="1"
                                        class="btn btn-sm btn-danger <?= $hasCredentials ? '' : 'disabled' ?>"
                                        onclick="return prompt('Type RESTORE (all caps) to confirm replacing every table with the selected backup. A snapshot of current state is saved automatically before the restore runs.') === 'RESTORE'">
                                    Restore
                                </button>
                            </form>
                            <p class="text-muted small mb-2">
                                <i aria-hidden="true" class="bi bi-info-circle me-1"></i>
                                Restore always takes a pre-restore snapshot first. Data INSERTs
                                are transactional — a failure rolls data back automatically.
                            </p>

                            <!-- Manually delete ONE selected backup (#1509). Global-Admin
                                 gated (page-wide gate at the top of this file) + CSRF via
                                 validateCsrfRequest() (see the "DELETE A BACKUP (#1509)"
                                 handler near the top of this file) + a server-side
                                 confirm=1 flag. No DB connection needed (filesystem-only),
                                 so — like Download above — it stays enabled even when
                                 credentials are unset. The typed "DELETE" prompt below is a
                                 client-side speed bump only, matching the Restore form's own
                                 onclick convention just above; the real gates are server-side. -->
                            <label class="form-label small text-secondary mb-1" for="backup-delete-file">
                                <i aria-hidden="true" class="bi bi-trash me-1"></i>Delete a backup (permanent — cannot be undone):
                            </label>
                            <form action="" method="post" class="d-flex gap-2 flex-wrap mb-2">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                                <input type="hidden" name="action" value="delete-backup">
                                <input type="hidden" name="confirm" value="1">
                                <select name="file" id="backup-delete-file" class="form-select form-select-sm" style="flex:1 1 200px">
                                    <?php foreach ($backupFiles as $f): ?>
                                        <option value="<?= htmlspecialchars($f) ?>"><?= htmlspecialchars($f) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                        onclick="return prompt('Type DELETE (all caps) to permanently remove the selected backup file. This CANNOT be undone.') === 'DELETE'">
                                    <i aria-hidden="true" class="bi bi-trash me-1"></i>Delete
                                </button>
                            </form>
                            <p class="text-muted small mb-0">
                                <i aria-hidden="true" class="bi bi-shield-check me-1"></i>
                                At least one backup always stays on disk — the last remaining
                                file can't be deleted here.
                            </p>
                        <?php else: ?>
                            <p class="text-muted small mb-2">No backups found in <code>data_share/backups/</code>.</p>
                        <?php endif; ?>

                        <hr class="my-2">
                        <p class="text-muted small mb-2">Or upload a `.sql.gz` / `.sql` from your computer:</p>
                        <form action="" method="post" enctype="multipart/form-data" class="d-flex gap-2 flex-wrap">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="action" value="upload-backup">
                            <input type="file" name="backup" accept=".sql,.sql.gz,.gz" required
                                   aria-label="Backup file to upload"
                                   class="form-control form-control-sm" style="flex:1 1 200px">
                            <button type="submit" class="btn btn-sm btn-outline-secondary <?= $hasCredentials ? '' : 'disabled' ?>">
                                Upload
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card bg-body-tertiary border-danger h-100">
                    <div class="card-body">
                        <h5 class="card-title">6. Drop Legacy Tables</h5>
                        <p class="card-text text-secondary small">
                            Drop any tables in the database that are <strong>not</strong>
                            part of the current <code>schema.sql</code>. Useful after
                            importing an existing MySQL database that still holds tables
                            from a previous iHymns incarnation.
                        </p>
                        <?php $_dropLegacyCsrf = '&amp;csrf_token=' . urlencode(csrfToken()); ?>
                        <div class="d-flex gap-2 flex-wrap">
                            <a href="?action=drop-legacy<?= $_dropLegacyCsrf ?>" class="btn btn-outline-warning btn-sm <?= $hasCredentials ? '' : 'disabled' ?>">
                                Preview
                            </a>
                            <a href="?action=drop-legacy&amp;confirm=1<?= $_dropLegacyCsrf ?>" class="btn btn-danger btn-sm <?= $hasCredentials ? '' : 'disabled' ?>"
                               data-type-to-confirm="drop-legacy"
                               onclick="return confirm('This will DROP all tables in the database that are not defined in schema.sql.\n\nThis cannot be undone. Run a Backup first.\n\nContinue?')">
                                Drop Them
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php /* function_exists() guard (#1466 review finding 11): the whole
                 panel — status render, Generate/Rotate cards, beacon table,
                 inline JS — is skipped entirely when secret_crypto_admin.php
                 didn't load (is_file()-gated near the top of this file), so a
                 docroot with a lagging deploy shows a small note instead of a
                 blank spot or a fatal. See that require's doc-block for the
                 full why. */ ?>
        <?php /* #1466 third-pass review finding 3: gate the ENTIRE secret-encryption
                 section (status render + its secretSentinelEnsure/secretKeyBeaconWrite
                 side effects, AND the lagging-deploy note) behind Global-Admin. The
                 page-level gate (lines ~38-49) is bypassed during the fresh-install
                 window (needsSetup() true), so without this an anonymous visitor could
                 see the read-only status / beacon / DB-FILE-privilege metadata (harmless
                 no-ops while dormant, but the correct posture is to hide the whole
                 section — you cannot provision keys before an admin user even exists).
                 Mirrors the POST dispatcher's own independent gate (lines ~177-178). */ ?>
        <?php if (!$isInitialSetup && isset($currentUser) && $currentUser && ($currentUser['role'] ?? null) === 'global_admin'): ?>
        <?php if (function_exists('secretCryptoReady')): ?>
        <!-- ============================================================
             SECRET ENCRYPTION-AT-REST (#1466) — Global-Admin panel
             ============================================================
             ELI5: this is the "control room" for the lock-and-key system
             that scrambles secrets (email passwords, API keys, the
             Sign-in-with-Apple .p8) before they sit in the shared
             database. It shows whether THIS docroot can currently unlock
             things (Status), lets the admin mint a brand-new key when
             none exists yet (Generate — never writes anything itself),
             and lets them swap every stored secret to a new key once
             every docroot already holds it (Rotate — the destructive,
             re-auth-gated capstone).

             WHY: strategy §10b names this exact three-card layout as the
             web-only operator's ENTIRE workflow for provisioning,
             auditing, and rotating the master key — no shell access is
             assumed (this DreamHost install has none; see
             .claude/secret-encryption-strategy.md and the project's
             "operator is web-only" memory). The two write actions
             (`secret_generate_key`, `secret_rotate`) are handled by the
             dedicated POST dispatcher near the TOP of this file (search
             "SECRET ENCRYPTION-AT-REST — POST actions"), which mirrors
             manage/api-keys.php's fetch()+JSON+CSRF+reveal-once pattern
             rather than this page's `?action=` GET convention, because
             §10 requires re-auth + a robust same-origin CSRF check on the
             rotate endpoint (a GET link can't safely carry a password).
             The status reads below call ONLY the read-only helpers in
             includes/secret_crypto_admin.php, which are documented there
             as NEVER throwing — so a DB hiccup degrades this card to
             "can't tell" rather than white-screening the whole dashboard.
             Refs: .claude/secret-encryption-strategy.md §8 (fingerprint
             canary + sentinel), §10 (hardening the rotate card), §10b
             (this panel's spec, verbatim).
             ============================================================ -->
        <?php
            /* Best-effort: mint the shared cross-docroot sentinel if this
               docroot already holds a usable key and nobody has minted it
               yet (idempotent — secretSentinelEnsure() never overwrites an
               existing sentinel). Every status read below is documented as
               NEVER throwing, but $db itself can throw (e.g. credentials
               not yet configured) — caught here so the rest of the
               dashboard still renders even mid-provisioning. */
            $secretPanelDb = null;
            try {
                $secretPanelDb = getDbMysqli();
                secretSentinelEnsure($secretPanelDb);
                /* SELF-REPORT BEACON (#1466 review findings 1/4/7, strategy
                   §10b) — best-effort; no-ops (writes nothing) when this
                   docroot has no key loaded, per secretKeyBeaconWrite()'s own
                   fail-safe contract. Every time an admin opens THIS panel,
                   this docroot records "here's what keyids I currently hold"
                   into the shared tblAppSettings row the OTHER two docroots'
                   panels (and the rotate handler's secretRotationParity()
                   gate above) read back — the mechanism that turns "the
                   operator promises they checked all 3 tabs" into a fact the
                   code can verify. */
                secretKeyBeaconWrite($secretPanelDb);
            } catch (\Throwable $_e) { /* degrade to the read-only status below */ }

            $secretEngineAlg   = secretCryptoAlgorithm();
            $secretKeyReady    = secretCryptoReady();
            $secretActiveKeyId = secretActiveKeyId();
            $secretFingerprint = secretKeyFingerprint();
            $secretAllKeyIds   = secretAllKeyIds();
            $secretEncActive   = $secretPanelDb ? secretEncryptionActive($secretPanelDb) : false;
            $secretInv         = $secretPanelDb
                ? secretInventory($secretPanelDb)
                : ['encrypted' => 0, 'legacy' => 0, 'empty' => 0, 'keys' => []];
            /* 'red' (not 'absent') when we couldn't even open a connection —
               matches secretSentinelStatus()'s own fail-closed contract, so
               the panel never reads as falsely healthy on a DB outage. */
            $secretSentinel = $secretPanelDb ? secretSentinelStatus($secretPanelDb) : 'red';
            /* Per-docroot beacon table data (#1466 review findings 1/4/7,
               strategy §10b visibility requirement) — read-only, NEVER
               throws (secretKeyBeacons() degrades a per-env read failure to
               present=false/fresh=false rather than propagating), so an
               empty array on a DB hiccup just renders every row as absent. */
            $secretKeyBeaconRows = $secretPanelDb ? secretKeyBeacons($secretPanelDb) : [];
            /* DB file-read privilege self-check (#1466 §12-A): proves a
               SQL-injection can't LOAD_FILE() the master key THROUGH the DB and
               collapse the two-primitive protection. Read-only, never throws. */
            $secretDbFilePriv = ($secretPanelDb && function_exists('secretDbFilePrivilegeStatus'))
                ? secretDbFilePrivilegeStatus($secretPanelDb)
                : ['verdict' => 'unknown', 'file_grant' => null, 'secure_file_priv' => null, 'can_read_keyfile' => null];
        ?>
        <h4 class="mb-3">Secret encryption <span class="text-secondary small">(#1466)</span></h4>
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card bg-body-tertiary <?= $secretSentinel === 'red' ? 'border-danger' : 'border-secondary' ?> h-100">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>
                            Status — this docroot
                        </h5>
                        <ul class="list-unstyled small mb-2">
                            <li class="mb-1"><strong>Engine:</strong>
                                <?= $secretEngineAlg === 'sb' ? 'libsodium secretbox' : 'AES-256-GCM (fallback)' ?>
                            </li>
                            <li class="mb-1"><strong>Master key:</strong>
                                <?php if ($secretKeyReady): ?>
                                    <span class="badge bg-success">present</span>
                                    — active keyid <code><?= htmlspecialchars((string)$secretActiveKeyId, ENT_QUOTES, 'UTF-8') ?></code>,
                                    non-secret fingerprint <code><?= htmlspecialchars((string)$secretFingerprint, ENT_QUOTES, 'UTF-8') ?></code>
                                    <span class="text-secondary">— must match on all 3 docroots</span>
                                    <?php if (count($secretAllKeyIds) > 1): ?>
                                        <br><span class="text-secondary">All loaded keyids: <code><?= htmlspecialchars(implode(', ', $secretAllKeyIds), ENT_QUOTES, 'UTF-8') ?></code> (rotation overlap)</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-secondary">absent</span>
                                    — no <code>appWeb/.auth/secrets_master_key.php</code> on this docroot yet.
                                <?php endif; ?>
                            </li>
                            <li class="mb-1"><strong>Cutover:</strong>
                                <?php if ($secretEncActive): ?>
                                    <span class="badge bg-success">encrypted-at-rest ACTIVE</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">dormant</span> — values still plaintext.
                                <?php endif; ?>
                            </li>
                            <li class="mb-1"><strong>Inventory:</strong>
                                <?= (int)$secretInv['encrypted'] ?> encrypted /
                                <?= (int)$secretInv['legacy'] ?> legacy-plaintext /
                                <?= (int)$secretInv['empty'] ?> empty
                                <?php if (!empty($secretInv['keys'])): ?>
                                    <ul class="small text-secondary mb-0 mt-1">
                                        <?php foreach ($secretInv['keys'] as $_secretKeyName => $_secretKeyState):
                                            $_secretKeyBadge = $_secretKeyState === 'enc'
                                                ? 'bg-success'
                                                : ($_secretKeyState === 'legacy' ? 'bg-warning text-dark' : 'bg-secondary');
                                        ?>
                                            <li>
                                                <code><?= htmlspecialchars((string)$_secretKeyName, ENT_QUOTES, 'UTF-8') ?></code>
                                                <span class="badge <?= $_secretKeyBadge ?>"><?= htmlspecialchars((string)$_secretKeyState, ENT_QUOTES, 'UTF-8') ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </li>
                            <li class="mb-0"><strong>Sentinel:</strong>
                                <?php if ($secretSentinel === 'green'): ?>
                                    <span class="badge bg-success">green</span> — this docroot can decrypt the shared sentinel.
                                <?php elseif ($secretSentinel === 'red'): ?>
                                    <span class="badge bg-danger">red — KEY MISMATCH / ABSENT</span> — do <strong>NOT</strong> run the migration.
                                <?php else: ?>
                                    <span class="badge bg-secondary">absent</span> — no sentinel provisioned yet.
                                <?php endif; ?>
                            </li>
                            <li class="mb-0"><strong>DB file-read access:</strong>
                                <?php if ($secretDbFilePriv['verdict'] === 'safe'): ?>
                                    <span class="badge bg-success">safe</span> — the database user cannot read the master key file (no <code>FILE</code> privilege / <code>LOAD_FILE</code> blocked), so a SQL-injection can't reach the key through the DB.
                                <?php elseif ($secretDbFilePriv['verdict'] === 'at_risk'): ?>
                                    <span class="badge bg-danger">⚠ at risk</span> — the database user CAN read files (<code>FILE</code> privilege granted / <code>LOAD_FILE</code> works). A SQL-injection could read the master key <em>through</em> the DB, weakening the two-primitive protection. Ask your host to remove the <code>FILE</code> privilege from this DB user.
                                <?php else: ?>
                                    <span class="badge bg-secondary">unknown</span> — could not determine (no key file present yet, or the grant/probe check was unavailable).
                                <?php endif; ?>
                                <?php if ($secretDbFilePriv['file_grant'] !== null || $secretDbFilePriv['secure_file_priv'] !== null): ?>
                                    <br><span class="text-secondary">FILE granted: <?= $secretDbFilePriv['file_grant'] === null ? 'unknown' : ($secretDbFilePriv['file_grant'] ? 'YES' : 'no') ?><?php if ($secretDbFilePriv['secure_file_priv'] !== null): ?> · secure_file_priv: <code><?= htmlspecialchars((string)$secretDbFilePriv['secure_file_priv'], ENT_QUOTES, 'UTF-8') ?></code><?php endif; ?></span>
                                <?php endif; ?>
                            </li>
                        </ul>
                        <p class="text-secondary small mb-0">
                            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                            All 3 docroots must show the SAME fingerprint + a green sentinel before running the encrypt-in-place migration card above.
                        </p>
                    </div>
                </div>
            </div>

            <!-- ============================================================
                 CROSS-DOCROOT KEY STATUS (#1466 review findings 1/4/7)
                 ============================================================
                 ELI5: the Status card above only knows about ITSELF — it
                 can't see whether beta or production have the same key. This
                 table reads the shared "beacon" row each docroot's OWN panel
                 render just wrote (secretKeyBeaconWrite(), a few lines up)
                 and shows all three side by side, so an operator doesn't
                 have to keep three browser tabs open and compare fingerprints
                 by eye. It's the human-readable face of the SAME data
                 secretRotationParity() checks programmatically before the
                 Rotate button is allowed to actually run.
                 WHY a table, not prose: strategy §10b specifically asks for
                 a per-docroot table here, mirroring the Status card's own
                 three-state (green/red/absent) reasoning per row instead of
                 collapsing three envs into one combined yes/no. A stale or
                 absent row is highlighted (`text-secondary` + a "stale"
                 badge) so it reads as "needs attention" at a glance, without
                 the page ever claiming to know something it can't verify.
                 ============================================================ -->
            <div class="col-12">
                <div class="card bg-body-tertiary border-secondary h-100">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="bi bi-diagram-3 me-1" aria-hidden="true"></i>
                            Cross-docroot key status
                        </h5>
                        <p class="card-text text-secondary small mb-2">
                            Rotate requires all three to show the NEW keyid + a fresh beacon
                            (open each docroot's own Secret-encryption panel after adding the key —
                            that visit is what records its beacon here).
                        </p>
                        <div class="table-responsive">
                            <table class="table table-dark table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">Docroot</th>
                                        <th scope="col">Present</th>
                                        <th scope="col">Fresh</th>
                                        <th scope="col">Active keyid</th>
                                        <th scope="col">All keyids</th>
                                        <th scope="col">Fingerprint</th>
                                        <th scope="col">Last seen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($secretKeyBeaconRows as $_beaconEnv => $_beacon):
                                    $_beaconPresent = (bool)($_beacon['present'] ?? false);
                                    $_beaconFresh   = (bool)($_beacon['fresh'] ?? false);
                                    $_beaconStale   = !$_beaconPresent || !$_beaconFresh;
                                    /* Human-friendly age — the raw value is only ever used for
                                       this display; secretRotationParity() itself compares the
                                       raw seconds against SECRET_KEY_BEACON_FRESH_SECONDS. */
                                    $_beaconAgeSecs  = (int)($_beacon['age'] ?? PHP_INT_MAX);
                                    if (!$_beaconPresent) {
                                        $_beaconAgeLabel = '—';
                                    } elseif ($_beaconAgeSecs < 120) {
                                        $_beaconAgeLabel = $_beaconAgeSecs . 's ago';
                                    } elseif ($_beaconAgeSecs < 7200) {
                                        $_beaconAgeLabel = (int)round($_beaconAgeSecs / 60) . 'm ago';
                                    } else {
                                        $_beaconAgeLabel = (int)round($_beaconAgeSecs / 3600) . 'h ago';
                                    }
                                ?>
                                    <tr class="<?= $_beaconStale ? 'text-secondary' : '' ?>">
                                        <td><code><?= htmlspecialchars((string)$_beaconEnv, ENT_QUOTES, 'UTF-8') ?></code></td>
                                        <td>
                                            <?php if ($_beaconPresent): ?>
                                                <span class="badge bg-success">yes</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">no</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($_beaconFresh): ?>
                                                <span class="badge bg-success">fresh</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">stale</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><code><?= htmlspecialchars($_beacon['active'] !== null ? (string)$_beacon['active'] : '—', ENT_QUOTES, 'UTF-8') ?></code></td>
                                        <td><code><?= htmlspecialchars(!empty($_beacon['keyids']) ? implode(', ', $_beacon['keyids']) : '—', ENT_QUOTES, 'UTF-8') ?></code></td>
                                        <td><code><?= htmlspecialchars($_beacon['fp'] !== null ? (string)$_beacon['fp'] : '—', ENT_QUOTES, 'UTF-8') ?></code></td>
                                        <td><?= htmlspecialchars($_beaconAgeLabel, ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card bg-body-tertiary border-secondary h-100">
                    <div class="card-body">
                        <h5 class="card-title"><i class="bi bi-key me-1" aria-hidden="true"></i>Generate master key</h5>
                        <p class="card-text text-secondary small">
                            Produces a CSPRNG 32-byte key (64 hex chars) server-side — solves
                            the web-only "can't run <code>openssl rand</code>" problem. Shown
                            <strong>once</strong>; this tool is a generator, not a writer — it
                            NEVER writes <code>appWeb/.auth/</code> itself. Copy it into the
                            <code>SECRETS_MASTER_KEY</code> GitHub Secret and/or SFTP it into
                            <code>appWeb/.auth/secrets_master_key.php</code> on each docroot.
                        </p>
                        <div id="secretGenResult" class="d-none mb-2">
                            <div class="alert alert-warning py-2 small mb-2">
                                <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                                Copy this key now — shown once, not stored.
                            </div>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control font-monospace" id="secretGenValue" readonly aria-label="Generated master key">
                                <button type="button" class="btn btn-outline-secondary" id="secretGenCopy" title="Copy to clipboard" aria-label="Copy generated master key to clipboard"><i class="bi bi-clipboard" aria-hidden="true"></i></button>
                            </div>
                        </div>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="secretGenBtn">
                            Generate master key
                        </button>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card bg-body-tertiary border-danger h-100">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>
                            Rotate / re-encrypt <span class="badge bg-danger">destructive</span>
                        </h5>
                        <p class="card-text text-secondary small">
                            Re-wraps every stored secret under a NEW active keyid.
                            <strong>Operator gate:</strong> this panel cannot see the other 2
                            docroots — first generate a new key above, add it as a NEW keyid
                            on <strong>all 3</strong> docroots (deploy the Secret, or SFTP),
                            and confirm each one's Status card shows the same new fingerprint.
                            <strong>Only then</strong> rotate here. A value under a forgotten
                            keyid is reported afterward, never aborts the rest of the rotation.
                        </p>
                        <?php if ($secretKeyReady): ?>
                            <form id="secretRotateForm">
                                <div class="mb-2">
                                    <label class="form-label small mb-1" for="secretRotatePw">Your password (re-auth)</label>
                                    <input type="password" class="form-control form-control-sm" id="secretRotatePw" autocomplete="current-password" required>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small mb-1" for="secretRotateConfirm">Type <code>ROTATE</code> to confirm</label>
                                    <input type="text" class="form-control form-control-sm" id="secretRotateConfirm" autocomplete="off" required>
                                </div>
                                <button type="submit" class="btn btn-danger btn-sm" id="secretRotateBtn">Rotate now</button>
                            </form>
                        <?php else: ?>
                            <p class="text-secondary small mb-0">No master key is loaded on this docroot — generate/provision one first.</p>
                        <?php endif; ?>
                        <div id="secretRotateResult" class="small mt-2"></div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        /* Secret encryption panel (#1466) — Generate + Rotate. Vanilla JS,
           no dependencies; mirrors manage/api-keys.php's fetch()+JSON+CSRF+
           reveal-once idiom (same `X-Requested-With` header so the server's
           validateCsrfRequest() same-origin check passes without relying on
           a baked, go-stale-prone session token). Posts to THIS page — the
           dedicated POST dispatcher near the top of setup-database.php
           intercepts `secret_generate_key` / `secret_rotate` before any of
           the page's normal HTML renders. */
        (function () {
            'use strict';
            var CSRF = <?= json_encode(csrfToken()) ?>;
            function secretPost(data) {
                var body = new URLSearchParams(data);
                body.append('csrf_token', CSRF);
                return fetch('', {
                    method: 'POST',
                    body: body,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); });
            }
            /* Escapes text before it is concatenated into innerHTML below.
               ELI5: every value here (error strings, docroot names) is
               server-generated, not free-typed user input, but this still
               builds HTML by string-concatenation — escaping is cheap
               insurance so a future server-side change can never turn this
               into a stored-XSS sink. */
            function escapeHtml(s) {
                return String(s).replace(/[&<>"']/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
            }

            var genBtn = document.getElementById('secretGenBtn');
            if (genBtn) {
                genBtn.addEventListener('click', function () {
                    genBtn.disabled = true;
                    secretPost({ action: 'secret_generate_key' }).then(function (res) {
                        genBtn.disabled = false;
                        if (!res.ok || !res.j.success) {
                            alert((res.j && res.j.error) || 'Failed to generate a key.');
                            return;
                        }
                        document.getElementById('secretGenValue').value = res.j.key;
                        document.getElementById('secretGenResult').classList.remove('d-none');
                    }).catch(function () { genBtn.disabled = false; alert('Network error.'); });
                });
            }
            var genCopy = document.getElementById('secretGenCopy');
            if (genCopy) {
                genCopy.addEventListener('click', function () {
                    var v = document.getElementById('secretGenValue');
                    v.select();
                    if (navigator.clipboard) { navigator.clipboard.writeText(v.value); }
                    else { document.execCommand('copy'); }
                });
            }

            var rotateForm = document.getElementById('secretRotateForm');
            if (rotateForm) {
                rotateForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var pw            = document.getElementById('secretRotatePw').value;
                    var confirmPhrase = document.getElementById('secretRotateConfirm').value;
                    var resultBox     = document.getElementById('secretRotateResult');
                    if (confirmPhrase !== 'ROTATE') {
                        resultBox.innerHTML = '<span class="text-danger">Type ROTATE (all caps) to confirm.</span>';
                        return;
                    }
                    if (!window.confirm(
                        'Rotate the master key now?\n\n' +
                        'Only proceed if ALL 3 docroots already show the NEW keyid + matching fingerprint. ' +
                        'This re-wraps every stored secret in the shared database.'
                    )) {
                        return;
                    }
                    var btn = document.getElementById('secretRotateBtn');
                    btn.disabled = true;
                    resultBox.textContent = 'Rotating…';
                    secretPost({
                        action: 'secret_rotate',
                        reauth_password: pw,
                        confirm_phrase: confirmPhrase
                    }).then(function (res) {
                        btn.disabled = false;
                        document.getElementById('secretRotatePw').value = '';
                        document.getElementById('secretRotateConfirm').value = '';
                        if (!res.ok || !res.j.success) {
                            /* #1466 review findings 1/4/7: on a 409 from the
                               cross-docroot parity gate, res.j.parity.missing
                               names exactly which docroots haven't confirmed
                               the target keyid yet — surface it explicitly
                               rather than making the operator re-read the
                               (already fairly long) error sentence. */
                            var errHtml = '<span class="text-danger">' +
                                escapeHtml((res.j && res.j.error) || 'Rotation failed.') + '</span>';
                            var missing = res.j && res.j.parity && Array.isArray(res.j.parity.missing)
                                ? res.j.parity.missing
                                : null;
                            if (missing && missing.length) {
                                errHtml += '<br><span class="text-warning">Missing beacons: ' +
                                    escapeHtml(missing.join(', ')) + '</span>';
                            }
                            resultBox.innerHTML = errHtml;
                            return;
                        }
                        var r = res.j.result || {};
                        var rewrapped     = r.rewrapped     ? r.rewrapped.length     : 0;
                        var skipped       = r.skipped       ? r.skipped.length       : 0;
                        var undecryptable = r.undecryptable || [];
                        var html = '<span class="text-success">Rotated.</span> ' +
                            rewrapped + ' rewrapped, ' + skipped + ' skipped';
                        if (undecryptable.length) {
                            /* #1466 finding 2: escape the (server-generated) key
                               names for consistency with the missing-beacon list
                               above — cheap insurance so a future dynamic source
                               can never turn this into a stored-XSS sink. */
                            html += '. <span class="text-danger">' + undecryptable.length +
                                ' UNDECRYPTABLE (under a forgotten keyid — needs manual attention): ' +
                                escapeHtml(undecryptable.join(', ')) + '</span>';
                        }
                        /* No auto-reload — the operator needs to actually READ
                           the rewrapped/skipped/undecryptable counts (esp. any
                           undecryptable keys, which need manual follow-up)
                           before the Status card above re-renders on next load. */
                        resultBox.innerHTML = html;
                    }).catch(function () {
                        btn.disabled = false;
                        resultBox.innerHTML = '<span class="text-danger">Network error.</span>';
                    });
                });
            }
        })();
        </script>
        <?php else: ?>
        <!-- #1466 review finding 11: secret_crypto_admin.php isn't present
             on this docroot yet (lagging deploy) — degrade gracefully
             instead of skipping this section silently or fataling the whole
             dashboard. -->
        <div class="alert alert-secondary py-2 small mb-4">
            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
            Secret-encryption module not deployed on this docroot yet
            (<code>includes/secret_crypto_admin.php</code> is missing) — the
            rest of this dashboard is unaffected. Deploy the file and reload
            this page to see the Secret encryption panel.
        </div>
        <?php endif; ?>
        <?php endif; /* #1466 finding 3: end Global-Admin gate on the whole secret-encryption section */ ?>

        <!-- ============================================================
             DATABASE STATUS
             ============================================================ -->
        <h4 class="mb-3">Database Status</h4>

        <?php if ($dbStatus === 'connected'): ?>
            <div class="alert alert-success py-2 d-flex justify-content-between align-items-center">
                <span>
                    Connected to <strong><?= htmlspecialchars(DB_NAME) ?></strong>
                    @ <?= htmlspecialchars(DB_HOST) ?>:<?= DB_PORT ?>
                </span>
                <a href="?reconfigure=1" class="btn btn-sm btn-outline-light">Reconfigure</a>
            </div>

            <?php if (empty($dbTables)): ?>
                <div class="alert alert-warning py-2">No tables found. Run <strong>Install Tables</strong> first.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-dark table-sm table-striped table-hover">
                        <thead><tr><th scope="col">Table</th><th scope="col" class="text-end">Rows</th></tr></thead>
                        <tbody>
                        <?php foreach ($dbTables as $t): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($t['name']) ?></code></td>
                                <td class="text-end"><?= number_format($t['count']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-secondary">
                                <td><strong><?= count($dbTables) ?> tables</strong></td>
                                <td class="text-end"><strong><?= number_format(array_sum(array_column($dbTables, 'count'))) ?> rows</strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>

        <?php elseif ($dbStatus !== null): ?>
            <div class="alert alert-danger py-2">
                Connection error: <?= htmlspecialchars($dbStatus) ?>
            </div>
        <?php elseif (!$hasCredentials): ?>
            <div class="alert alert-secondary py-2">Configure credentials first.</div>
        <?php endif; ?>

    <hr class="my-4">
    <p class="text-secondary text-center small">
        iHymns Database Administration &middot; v0.10.0
        <?php
            /* Footer name (#650) — match the header's first-word
               derivation in admin-nav.php so 'Lance Manasse' reads as
               'Lance' on both surfaces. Falls back to username when
               DisplayName is empty. */
            if (!$isInitialSetup && isset($currentUser)) {
                $_displayName = (string)($currentUser['display_name'] ?? $currentUser['username'] ?? '');
                $_footerName  = preg_split('/\s+/', trim($_displayName), 2)[0] ?: $_displayName;
            }
        ?>
        <?php if (!$isInitialSetup && isset($currentUser)): ?>
            &middot; Logged in as <strong><?= htmlspecialchars($_footerName) ?></strong>
        <?php endif; ?>
    </p>
</div>

<script>
/* Type-to-confirm speed-bump for DESTRUCTIVE / irreversible actions (#1298).

   Any anchor tagged data-type-to-confirm="<phrase>" (the migration slug, e.g.
   "retire-component-lines-json", or the "drop-legacy" action) forces the operator
   to type that EXACT phrase before the request is allowed to fire. An exact,
   case-sensitive match navigates; anything else cancels the click.

   This is an ADDITIONAL client speed-bump only — it does NOT replace any
   server-side guard. The destructive request still carries confirm=1 (the
   migration / drop script refuses without it), the existing native confirm()
   dialog still runs, the verifier-sentinel / parity preconditions still apply,
   and the whole page is still behind the global_admin gate. We bind in the
   CAPTURE phase so this runs BEFORE the anchor's inline onclick confirm(): on a
   mismatch we preventDefault() + stopImmediatePropagation() so neither the inline
   confirm nor the navigation proceeds. Vanilla JS, no dependencies. */
(() => {
    document.addEventListener('click', (ev) => {
        const link = ev.target.closest('a[data-type-to-confirm]');
        if (!link) return;
        /* Respect the page's own disabled-state convention (no credentials). */
        if (link.classList.contains('disabled')) return;

        const phrase = link.getAttribute('data-type-to-confirm') || '';
        if (phrase === '') return;

        const typed = window.prompt(
            'DESTRUCTIVE, irreversible action.\n\n' +
            'To confirm, type the following EXACTLY (case-sensitive):\n\n' +
            phrase
        );

        /* Exact, case-sensitive match required. Cancel / mismatch aborts the
           click entirely — stopImmediatePropagation() prevents the inline
           onclick confirm() from also running, preventDefault() blocks nav. */
        if (typed !== phrase) {
            ev.preventDefault();
            ev.stopImmediatePropagation();
        }
        /* On an exact match we fall through: the inline onclick confirm()
           (if any) runs next, then the browser follows the href. */
    }, true /* capture */);
})();
</script>

<script>
/* IANA + CLDR live refresh button (#738).
   Posts to /api?action=admin_refresh_iana_cldr, swaps the status
   line for a live progress / result message, and on success
   reloads the page so the operator can re-run the per-card
   import button to verify counts. Global-admin-only endpoint;
   the button is disabled when DB credentials aren't configured. */
(() => {
    const btn = document.querySelector('button[data-action="refresh-iana-cldr"]');
    const status = document.querySelector('[data-iana-refresh-status]');
    if (!btn || !status) return;

    btn.addEventListener('click', async () => {
        if (btn.classList.contains('disabled') || btn.disabled) return;
        if (!confirm(
            'Fetch the latest IANA Language Subtag Registry and CLDR English ' +
            'JSON from the live URLs? This will overwrite the bundled snapshots ' +
            'in appWeb/.sql/data/ and re-run the import migration.'
        )) return;

        btn.disabled = true;
        const original = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Refreshing…';
        status.textContent = 'Fetching IANA + CLDR snapshots from upstream and re-running the import…';
        status.classList.remove('text-danger', 'text-success');

        let token = null;
        try { token = localStorage.getItem('ihymns_auth_token'); } catch (_e) {}

        try {
            const resp = await fetch('/api?action=admin_refresh_iana_cldr', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(token ? { 'Authorization': 'Bearer ' + token } : {}),
                },
                body: '{}',
            });
            const j = await resp.json();
            if (!resp.ok) {
                status.classList.add('text-danger');
                status.textContent = 'Refresh failed: ' + (j.error || resp.status) +
                    (j.failed ? ' (failed: ' + j.failed.join(', ') + ')' : '');
                btn.disabled = false;
                btn.innerHTML = original;
                return;
            }
            status.classList.add('text-success');
            status.textContent = 'Refreshed: ' + (j.fetched || []).join(', ') +
                '. Migration re-applied. Reloading…';
            setTimeout(() => location.reload(), 1500);
        } catch (err) {
            status.classList.add('text-danger');
            status.textContent = 'Network error: ' + err.message;
            btn.disabled = false;
            btn.innerHTML = original;
        }
    });
})();
</script>

<?php
    /* #2005 — Guided environment setup wizard: the modal SHELL.
       ELI5: this is the pop-up window frame the "Guided setup" button (and
       the "Get started" card) opens — the actual step-by-step content
       inside it lives in a separate, smaller file we `require` below, once
       we've computed everything that content needs to display.
       Detailed: rendered here, DELIBERATELY, rather than inside the
       required `includes/setup-wizard-modal.php` partial — this is the ONE
       reason the partial exists at all: `tests/php/test-wizard-empty-state.php`
       (the standing #1999 guard) checks, PER FILE, that a call to
       `ihymns_wizard_empty_state` (with `modalId` set to `setupWizardModal`)
       and the real `<div class="modal fade" id="setupWizardModal">` it
       points at live in the SAME source file — it reads raw file text, so
       it cannot "see" what a `require`d file contributes. Both the header
       trigger button and the empty-state launcher call above are in THIS
       file, so the modal shell has to be too. Bootstrap doesn't care where
       in the page a modal element physically sits (it's a fixed overlay
       shown/hidden by JS, @see
       https://getbootstrap.com/docs/5.3/components/modal/), so placing it
       here — after every dashboard status variable it needs is already
       computed — costs nothing.
       Every value written onto `data-*` below is escaped and is a plain
       snapshot of page-load state; `js/modules/setup-wizard.js` reads them
       once at boot. */
    $_wizDbStatusToken = 'none';
    if ($dbStatus === 'connected') {
        $_wizDbStatusToken = 'connected';
    } elseif ($dbStatus !== null) {
        $_wizDbStatusToken = 'error';
    }
    $_wizDbErrorTail = '';
    if (is_string($dbStatus) && strncmp($dbStatus, 'error: ', 7) === 0) {
        $_wizDbErrorTail = substr($dbStatus, 7);
    }
    $_wizHasTablesFlag = (count($dbTables) > 0) ? '1' : '0';

    /* Cache-busted import path for the shared stepper (#1992) + this
       wizard's own wiring module — same filemtime-as-version-query
       pattern the bulk-runner boot just below already uses (#1196). */
    $_setupWizardJsPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'setup-wizard.js';
    $_setupWizardJsVer  = is_file($_setupWizardJsPath) ? (string)filemtime($_setupWizardJsPath) : '1';
?>
<div class="modal fade" id="setupWizardModal" tabindex="-1" aria-hidden="true" aria-labelledby="setupWizardModalLabel" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <?php /* The wizard's server-seeded state lives on THIS element
                 (#setupWizardRoot, the .modal-content), not on the outer
                 .modal — createWizard(rootEl, …) in setup-wizard.js is
                 called with THIS element as rootEl, and it is that same
                 rootEl.dataset the JS reads for db-status/has-tables/
                 open-step. Every value is a plain, HTML-escaped snapshot
                 of page-load state (never re-read live by the client). */ ?>
        <div class="modal-content" id="setupWizardRoot"
             data-db-status="<?= htmlspecialchars($_wizDbStatusToken, ENT_QUOTES) ?>"
             data-db-error="<?= htmlspecialchars($_wizDbErrorTail, ENT_QUOTES) ?>"
             data-has-credentials="<?= $hasCredentials ? '1' : '0' ?>"
             data-initial-setup="<?= $isInitialSetup ? '1' : '0' ?>"
             data-has-tables="<?= $_wizHasTablesFlag ?>"
             data-pending-auto="<?= (int)$_pendingCardCount ?>"
             data-open-step="<?= htmlspecialchars($wizardOpenStep, ENT_QUOTES) ?>">
            <div class="modal-header">
                <h2 class="modal-title h5 mb-0" id="setupWizardModalLabel">Guided environment setup</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'setup-wizard-modal.php'; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-wiz-back hidden>Back</button>
                <button type="button" class="btn btn-primary" data-wiz-next>Next</button>
            </div>
        </div>
    </div>
</div>

<!-- #869 — Per-migration AJAX bulk runner. Replaces the legacy
     full-page "Apply All" redirect with a sequential fetch loop on
     the dashboard, so no single request hits a server-level
     timeout. Falls through to the legacy <a href> on no-JS. -->
<?php
    /* From manage/, public_html is dirname(__DIR__, 1); dirname(__DIR__, 2) was
       appWeb (one level too high), so is_file() always failed and the version
       fell back to '1', defeating per-file cache-busting (#1196). */
    $_bulkRunnerPath    = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'setup-bulk-runner.js';
    $_bulkRunnerVersion = is_file($_bulkRunnerPath) ? (string)filemtime($_bulkRunnerPath) : '1';
?>
<script type="module">
    import { bootSetupBulkRunner }
        from '/js/modules/setup-bulk-runner.js?v=<?= htmlspecialchars($_bulkRunnerVersion, ENT_QUOTES) ?>';
    bootSetupBulkRunner();
</script>

<?php /* #2005 — Guided setup wizard boot, deliberately AFTER
         bootSetupBulkRunner() just above: `setup-wizard.js` reads the
         `[data-bulk-runner-trigger]` element's `data-pending-migrations`
         list at RUN time (each button click), not at import time, so the
         order here isn't load-bearing either way — kept after purely so
         the two boots read top-to-bottom in the same order the page uses
         them (bulk grid first, guided wizard second). */ ?>
<script type="module">
    import { bootSetupWizard }
        from '/js/modules/setup-wizard.js?v=<?= htmlspecialchars($_setupWizardJsVer, ENT_QUOTES) ?>';
    bootSetupWizard();
</script>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
<?php
/* Prevent any included script's exit() from showing raw output after our page */
$pageRenderedCleanly = true;
exit;
