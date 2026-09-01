<?php

declare(strict_types=1);

/**
 * iHymns — Login-attempts action namespace (#1929)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * One new column, `tblLoginAttempts.Action`, DEFAULT `'login'`. The table
 * already gets rows from all sorts of unrelated things (registration, email
 * verification, Sign in with Apple, account deletion, even the Service Mode
 * congregant join code) because it is the app's one generic per-IP attempt
 * counter. Until now the only place that said WHICH action a row belonged to
 * was a repurposed `Username` column, and the actual login-lockout readers
 * never looked at it — they counted every `Success = 0` row for an IP no
 * matter what put it there. So a flood of failed `service_join` codes (a
 * NAT-shared congregation guessing a join code) could lock the same IP out of
 * the LOGIN form. This column gives every writer a real, queryable action
 * name so the login-lockout readers can finally count ONLY logins.
 *
 * DETAILED / WHY (#1929, plan in the session that filed it)
 * ------------------------------------------------------------------------
 * `tblLoginAttempts` is `includes/rate_limit.php`'s generic counter table —
 * see the doc-block above its `CREATE TABLE` in schema.sql. Every
 * `recordRateLimitHit($action, …)` call (there are ~40 across the app) writes
 * the action name into `Username`, which is fine for THOSE actions' own
 * `checkRateLimit()` reads (they already filter `Username = ?`). It falls
 * apart specifically for the two LOGIN-lockout readers (`api.php`'s
 * `auth_login` case and `manage/includes/auth.php`'s `attemptLogin()`), which
 * — correctly, for a REAL login — need to count failures keyed on IP alone
 * (a login attempt's `Username` holds the submitted username, not an action
 * name) and so historically read `WHERE IpAddress = ? AND Success = 0` with
 * NO action filter at all. That query cannot distinguish "10 wrong passwords"
 * from "10 failed service_join codes from the same IP" — both trip the same
 * lockout.
 *
 * `Action` fixes this WITHOUT touching the overloaded `Username` column (that
 * would be a breaking rename touching ~40 call sites for no gain): every
 * write is stamped with a real action name (`'login'`, `'auth_register'`,
 * `'email_verify'`, `'auth_apple'`, `'account_delete'`, `'service_join'`,
 * …) via the ONE new write primitive `loginAttemptsInsert()`
 * (`includes/rate_limit.php`), and the login-lockout readers add
 * `AND Action = 'login'` so only genuine login failures count toward the
 * login lockout.
 *
 * DEFAULT 'login' (not a bare empty string) matters for BOTH directions of
 * safety here: every pre-existing row (written before this column existed)
 * reads as a login attempt, which is the conservative, never-WEAKER choice —
 * a pre-migration row stays counted by the login lockout rather than quietly
 * falling out of it and loosening brute-force protection the moment this
 * migration lands. Going forward, every writer stamps its OWN real action
 * name explicitly, so 'login' only ever describes an actual login row.
 *
 * VARCHAR(40), not ENUM (rule #20 — a growable action-name vocabulary, not a
 * fixed set) and not VARCHAR(30): the longest action string already written
 * into this table today is `service_control_token_revoke` (28 characters).
 * 40 leaves headroom for a longer future action name without a second
 * migration, and pairs with `loginAttemptsInsert()`'s own
 * `substr($action, 0, 40)` truncate-guard so a too-long action can never
 * throw under STRICT mode instead of just being (harmlessly) truncated.
 *
 * NO NEW INDEX. Every read scopes on `IpAddress` first (via the existing
 * `idx_IpTime (IpAddress, AttemptedAt)` composite) and then filters `Action`
 * in-memory over the small per-IP row set the index already narrowed to — a
 * dedicated `Action` index would only pay for itself on a query that leads
 * with `Action`, and none does.
 *
 * No shared include is required — this is a single, self-contained ALTER
 * that mints nothing and calls no shared helper, so rule #41's
 * `IHYMNS_INCLUDES_DIR` resolution is N/A here: there is no
 * `require`/`include` of any file under a docroot's `includes/` directory
 * for a future editor to "fix" by threading it through that constant.
 *
 * STRICTLY ADDITIVE + IDEMPOTENT. The single ADD COLUMN is existence-guarded
 * (`colExists()`), so re-running this script after a successful apply is a
 * no-op [SKIP], and re-running after a failed apply resumes cleanly.
 *
 * @migration-adds tblLoginAttempts.Action
 *
 * SCHEMA MIRROR: `Action` is mirrored byte-identical (incl. the full COMMENT
 * text) in `appWeb/.sql/schema.sql`, inline in the `tblLoginAttempts` CREATE
 * TABLE block, immediately after the `Success` line — rule #19.
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-add-login-attempts-action.php
 *   Web:  /manage/setup-database → "Run Login Attempts Action Migration" button
 *
 * @requires PHP 8.1+ with mysqli
 * @see appWeb/public_html/includes/rate_limit.php  loginAttemptsInsert() / authLoginIpFailureCount()
 * @see appWeb/public_html/manage/includes/migration-registry.php  'login-attempts-action' entry
 * @see #1929 #1930
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function _migLoginAttemptsAction_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migLoginAttemptsAction_colExists(\mysqli $db, string $t, string $c): bool { $r = $db->query("SHOW COLUMNS FROM {$t} LIKE '{$c}'"); return (bool)($r && $r->num_rows > 0); }

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migLoginAttemptsAction_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migLoginAttemptsAction_output("");
_migLoginAttemptsAction_output("=== iHymns — Login-attempts action namespace (#1929) ===");
_migLoginAttemptsAction_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migLoginAttemptsAction_output("ERROR: MySQL connection failed: " . $e->getMessage()); return;
}
_migLoginAttemptsAction_output("Connected to MySQL: " . DB_NAME);

try {
    _migLoginAttemptsAction_output("--- tblLoginAttempts.Action ---");
    if (_migLoginAttemptsAction_colExists($mysql, 'tblLoginAttempts', 'Action')) {
        _migLoginAttemptsAction_output("  [SKIP] tblLoginAttempts.Action already present.");
    } else {
        $mysql->query(
            "ALTER TABLE tblLoginAttempts
    ADD COLUMN Action VARCHAR(40) NOT NULL DEFAULT 'login' COMMENT 'Action namespace for this generic per-IP/per-key attempts counter (#1929) -- e.g. login, auth_register, email_verify, auth_apple, account_delete, service_join. The login-lockout readers (api.php auth_login, manage/includes/auth.php attemptLogin) count Action=login ONLY, so an unrelated action can no longer inflate the login brute-force lockout. DEFAULT login is deliberately never-weaker for pre-existing rows written before this column existed. Stamped server-side by the ONE write primitive loginAttemptsInsert() (includes/rate_limit.php) -- never client-supplied.'"
        );
        _migLoginAttemptsAction_output("  [OK] Added tblLoginAttempts.Action.");
    }

    _migLoginAttemptsAction_output("");
    _migLoginAttemptsAction_output("=== Done. Login-attempts Action column is in place. ===");
} catch (\mysqli_sql_exception $e) {
    _migLoginAttemptsAction_output("ERROR: migration failed: " . $e->getMessage());
    return;
}
