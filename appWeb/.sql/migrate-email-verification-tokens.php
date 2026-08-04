<?php

declare(strict_types=1);

/**
 * iHymns - Email Verification Tokens Migration (#898)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Creates tblEmailVerificationTokens to back the verification email
 * fired on password-based registration (auth_register). Stores the
 * SHA-256 hash of the raw token (raw token only ever lives in the
 * outbound email body); 24-hour expiry; single-use.
 *
 * NOT DESTRUCTIVE. One CREATE TABLE, guarded, and nothing else — no
 * ALTER, no DELETE, no touch on tblUsers. The rollback is DROP TABLE,
 * whose only cost is that verification links already emailed out stop
 * resolving; affected users request a fresh one.
 *
 * IDEMPOTENT — the INFORMATION_SCHEMA.TABLES probe below returns early
 * with a [skip] line when the table is present, so a second run issues
 * no SQL at all. (Deliberately a probe rather than CREATE TABLE IF NOT
 * EXISTS, so the operator sees "already exists" rather than a silent
 * success that could equally mean "created just now".)
 *
 * OPERATOR VIEW. Card "Email Verification Tokens (#898)" on
 * /manage/setup-database; the registry probe is
 * !tableExists('tblEmailVerificationTokens') — a single-object migration,
 * so the probe and the migration agree exactly.
 *
 * SCHEMA MIRROR. Mirrored in appWeb/.sql/schema.sql under
 * "tblEmailVerificationTokens (#898)", which is what a FRESH install
 * reads. Rule #19 requires the two to stay byte-identical (COMMENT text
 * included) so migrated and fresh installs are the same shape; CI checks
 * only that the columns exist in both, so wording drift is caught later
 * by /manage/schema-audit (#518) rather than at build time.
 *
 * Note on the doctag: the schema-coverage scanner
 * (includes/schema_audit.php) recognises only the "adds" and "drops"
 * doctags, and each must name a table AND a column (see rule #19);
 * "creates" is not a form it looks for, so the line below is inert
 * documentation. Coverage for this table comes from the scanner's
 * "Signal 2" instead, which parses the literal CREATE TABLE block
 * further down. Do not write a specimen doctag in prose anywhere in an
 * appWeb/.sql/migrate-*.php file — the scanner reads the whole file as
 * text and cannot tell an example from a declaration, so the example
 * registers as a real column and fails test-schema-coverage.php.
 *
 * @migration-creates tblEmailVerificationTokens
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-email-verification-tokens.php
 *   Web:  /manage/setup-database -> "Email Verification Tokens (#898)" button
 *
 * @requires PHP 8.1+ with mysqli extension
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

function _migEmailVerify_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) flush();
}

function _migEmailVerify_tableExists(\mysqli $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

_migEmailVerify_out('Email Verification Tokens migration starting (#898)…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

if (_migEmailVerify_tableExists($mysqli, 'tblEmailVerificationTokens')) {
    _migEmailVerify_out('[skip] tblEmailVerificationTokens already exists.');
    _migEmailVerify_out('Email Verification Tokens migration finished (#898).');
    return;
}

/* The shape below encodes the security model, which is worth spelling out
   because the table looks unremarkable:

   - TokenHash IS the primary key. The raw token exists only in the
     outbound email; what is stored is its SHA-256, and verification
     hashes the incoming token and looks THAT up. So a dump of this table
     yields nothing an attacker can put in a URL. CHAR(64) is exactly the
     64 hex characters of a SHA-256 digest — fixed width, so CHAR rather
     than VARCHAR. Making the hash the PK rather than adding a surrogate
     Id also means there is no second index to keep in step, and a
     (vanishingly unlikely) collision is a key violation rather than a
     silent overwrite of somebody else's pending verification.

   - Email is a SNAPSHOT of the address at the moment the token was
     issued, not a pointer to the live tblUsers.Email. If the user
     changes their address after requesting verification, the old link
     must not confirm the new address — comparing against this frozen
     copy is what makes that check possible.

   - ExpiresAt has NO default. The app always supplies it (issue time +
     24h), and under MySQL strict mode an INSERT that omits a NOT NULL
     column with no default is rejected — so a caller that forgets the
     expiry fails loudly instead of minting a token that never ages out.
     idx_Expires exists for the sweeper that deletes lapsed rows.

   - Used is the single-use flag: consumption flips tblUsers.EmailVerified
     0 -> 1 and sets this to 1, so replaying a captured link is inert
     even inside the 24-hour window.

   - The FK cascades on delete, so removing a user reaps their pending
     tokens rather than leaving rows pointing at a vanished Id. */
$sql = <<<'SQL'
CREATE TABLE tblEmailVerificationTokens (
    TokenHash       CHAR(64)        NOT NULL PRIMARY KEY COMMENT 'sha256 of raw token',
    UserId          INT UNSIGNED    NOT NULL,
    Email           VARCHAR(255)    NOT NULL COMMENT 'Email at the moment the token was issued',
    CreatedAt       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ExpiresAt       TIMESTAMP       NOT NULL,
    Used            TINYINT(1)      NOT NULL DEFAULT 0,

    INDEX idx_User      (UserId),
    INDEX idx_Expires   (ExpiresAt),

    CONSTRAINT fk_VerifyTokens_User
        FOREIGN KEY (UserId) REFERENCES tblUsers(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

if (!$mysqli->query($sql)) {
    throw new \RuntimeException('CREATE TABLE tblEmailVerificationTokens failed: ' . $mysqli->error);
}

_migEmailVerify_out('[add ] tblEmailVerificationTokens (CHAR(64) PK, FK to tblUsers).');
_migEmailVerify_out('Email Verification Tokens migration finished (#898).');
