<?php

declare(strict_types=1);

/**
 * iHymns — Sign in with Apple: provider links + nonce ledger (#1402)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Creates the two tables the `?action=auth_apple` and `?action=account_delete`
 * endpoints (appWeb/public_html/api.php) depend on:
 *
 *   - tblUserAuthProviders — external identity-provider links. Identity is
 *     ALWAYS (Provider, Subject) — Apple's JWT `sub` claim — NEVER an email
 *     address, because Apple private-relay addresses are per-app aliases the
 *     user can disable or rotate at any time (an email-keyed link would break
 *     silently the day the user flips "Hide My Email" off). Provider is
 *     VARCHAR (not ENUM) so a future provider (Google, …) needs no ALTER
 *     (rule #20). RefreshToken is the Apple OAuth refresh token captured at
 *     first sign-in — account_delete needs it to call Apple's `/auth/revoke`
 *     endpoint (required by App Review's account-deletion policy).
 *
 *   - tblAuthNonces — single-use anti-replay ledger for provider sign-in
 *     assertions. A nonce is "consumed" by INSERTing its sha256 hash; the
 *     UNIQUE (Purpose, NonceHash) key makes a second consumption attempt a
 *     duplicate-key error, which the caller treats as a replay and rejects.
 *     A table (not a tblAppSettings sentinel) because consumption must be an
 *     atomic INSERT — a read-modify-write on a settings row would have a
 *     TOCTOU race between two concurrent submissions of the same stolen
 *     token. Purpose is VARCHAR so future flows (e.g. `siwa_reauth`, used by
 *     account_delete's fresh-SIWA-assertion re-auth rung) share the table
 *     without a second migration.
 *
 * Both tables are ADDITIVE and DORMANT: nothing reads or writes them until
 * this card is run AND the auth_apple / account_delete endpoints are called.
 * api.php existence-probes tblUserAuthProviders before touching it and
 * degrades to a 503 ("Sign in with Apple is not yet available.") when the
 * card hasn't been run yet on a given docroot — the #1228 STRICT-mysqli
 * lesson (a bare SELECT against a missing table THROWS under
 * MYSQLI_REPORT_STRICT, which would white-screen the endpoint instead of
 * degrading gracefully).
 *
 * DDL below MUST stay byte-identical (COMMENT text included) to the mirror
 * in appWeb/.sql/schema.sql's "AUTH PROVIDERS (Sign in with Apple) — #1402"
 * block — rule #19, enforced by tests/php/test-schema-coverage.php.
 *
 * @migration-adds tblUserAuthProviders.UserId
 * @migration-adds tblAuthNonces.NonceHash
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-user-auth-providers.php
 *   Web:  /manage/setup-database → "Sign in with Apple — provider links +
 *         nonce ledger (#1402)" button
 *
 * @requires PHP 8.1+ with mysqli
 * @see appWeb/public_html/includes/apple_siwa.php   the crypto/verify helper that consumes these tables
 * @see appWeb/public_html/manage/includes/migration-registry.php   'user-auth-providers' entry
 * @link https://developer.apple.com/documentation/sign_in_with_apple  Apple SIWA docs
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}
function _migUAP_output(string $m): void { global $isCli; echo $m . ($isCli ? "\n" : "<br>\n"); if (!$isCli) flush(); }
function _migUAP_tableExists(\mysqli $db, string $t): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('s', $t);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) { _migUAP_output("ERROR: MySQL credentials not found. Run install.php first."); return; }
require_once $credFile;

_migUAP_output("");
_migUAP_output("=== iHymns — Sign in with Apple: provider links + nonce ledger (#1402) ===");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $mysql->set_charset('utf8mb4');
} catch (\mysqli_sql_exception $e) { _migUAP_output("ERROR: MySQL connection failed: " . $e->getMessage()); return; }
_migUAP_output("Connected to MySQL: " . DB_NAME);

/* Each table is independently existence-guarded so a partial prior apply
   (e.g. the process died between the two CREATEs) heals cleanly on re-run
   instead of erroring on the table that already exists. */
try {
    if (_migUAP_tableExists($mysql, 'tblUserAuthProviders')) {
        _migUAP_output("  [SKIP] tblUserAuthProviders already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblUserAuthProviders (
                Id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                UserId          INT UNSIGNED    NOT NULL COMMENT 'FK to tblUsers — the iHymns account this provider identity signs in as',
                Provider        VARCHAR(30)     NOT NULL COMMENT 'apple | (future: google, …) — app-validated vocabulary, VARCHAR not ENUM (rule #20)',
                Subject         VARCHAR(128)    NOT NULL COMMENT 'Provider-stable subject claim (Apple JWT `sub`, e.g. 001234.<32hex>.5678) — the ONE durable identity key',
                Email           VARCHAR(255)    NOT NULL DEFAULT '' COMMENT 'Provider-asserted email at link time (may be @privaterelay.appleid.com); INFORMATIONAL ONLY — never an identity key',
                EmailIsPrivateRelay TINYINT(1)  NOT NULL DEFAULT 0 COMMENT '1 = Email is an Apple private-relay alias (host privaterelay.appleid.com)',
                RefreshToken    TEXT            NULL COMMENT 'Provider refresh token (Apple: minted by /auth/token code exchange at first sign-in) — required by account_delete to call Apple /auth/revoke. Useless without the owner-provisioned .p8 client secret; NEVER logged, NEVER echoed by any API',
                IdentityJson    JSON            NULL COMMENT 'Forward-looking provider extras captured at link time: fullName snapshot (Apple sends it ONLY on first authorization), real_user_status, email_verified — additive without a second migration (rule #20)',
                LastLoginAt     TIMESTAMP       NULL DEFAULT NULL COMMENT 'Last successful sign-in through this provider link',
                CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                UNIQUE KEY uq_provider_subject (Provider, Subject),
                UNIQUE KEY uq_user_provider    (UserId, Provider),
                INDEX      idx_User            (UserId),

                CONSTRAINT fk_AuthProviders_User
                    FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        _migUAP_output("  [OK] Created tblUserAuthProviders.");
    }

    if (_migUAP_tableExists($mysql, 'tblAuthNonces')) {
        _migUAP_output("  [SKIP] tblAuthNonces already present.");
    } else {
        $mysql->query(
            "CREATE TABLE tblAuthNonces (
                Id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                Purpose         VARCHAR(30)     NOT NULL COMMENT 'siwa_login | siwa_reauth | … — app-validated vocabulary, VARCHAR not ENUM (rule #20)',
                NonceHash       CHAR(64)        NOT NULL COMMENT 'sha256 hex of the RAW client nonce (raw nonce never stored)',
                UsedAt          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ExpiresAt       DATETIME        NOT NULL COMMENT 'Prune horizon (UsedAt + 15 min — longer than any Apple identity-token exp window)',

                UNIQUE KEY uq_purpose_nonce (Purpose, NonceHash),
                INDEX      idx_Expires      (ExpiresAt)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        _migUAP_output("  [OK] Created tblAuthNonces.");
    }

    _migUAP_output("Migration complete.");
} catch (\mysqli_sql_exception $e) {
    _migUAP_output("ERROR: " . $e->getMessage());
}
