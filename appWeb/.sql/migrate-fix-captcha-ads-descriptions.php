<?php

declare(strict_types=1);

/**
 * iHymns — Reword the captcha_* / ads_* seed descriptions as RESERVED (X6, #1685)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * UPDATEs the `Description` column of six `tblAppSettings` rows —
 * `captcha_provider`, `captcha_site_key`, `captcha_secret_key`, `ads_enabled`,
 * `ads_provider`, `ads_publisher_id` — to say plainly that nothing reads them
 * yet, instead of describing a feature that does not exist.
 *
 * ELI5: six settings on the admin configuration page describe things this
 * app can do (block bots, show ads) that it actually cannot do at all — there
 * is no code anywhere that looks at these values. An operator who set
 * `captcha_provider` to `recaptcha_v2` would believe bot protection was live.
 * This migration relabels the six switches "reserved — not wired yet" so the
 * description matches reality, the same fix `ccli_validation_enabled` got in
 * #1668 (that key was deleted outright because its old text actively claimed
 * to be a security control; these six describe genuinely plausible future
 * features, so the remediation plan's default here is REWORD, not delete —
 * see remediation-plan-2026-07-30.md work item X6).
 *
 * WHY A MIGRATION, NOT JUST A schema.sql EDIT:
 * `INSERT IGNORE` (used by schema.sql's seed block) is a no-op against a row
 * that already exists — so a fresh install picks up the corrected text
 * automatically, but every existing install keeps the original, misleading
 * description forever. Only an UPDATE reaches those installs.
 *
 * SAFE TO RUN: this only rewrites the human-readable `Description` column,
 * never `SettingValue` — no operator-set value changes, and (per the finding
 * this migration exists to fix) nothing reads these keys anyway, so there is
 * no behaviour to change. Not `manual`/destructive; safe inside "Apply all
 * pending". Idempotent — a second run updates the same rows to the same
 * text and reports it via `affected_rows` still being 0 (MySQL does not
 * count a row as affected when the UPDATE would not change any column).
 *
 * @migration-modifies tblAppSettings (rewords captcha_provider, captcha_site_key,
 *                      captcha_secret_key, ads_enabled, ads_provider, ads_publisher_id)
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-fix-captcha-ads-descriptions.php
 *   Web:  /manage/setup-database -> "Reword captcha/ads seed descriptions (#1685)"
 *
 * @requires PHP 8.1+ with mysqli extension
 * @link https://dev.mysql.com/doc/refman/8.0/en/update.html
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

function _migFixCaptchaAdsDesc_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) { flush(); }
}

_migFixCaptchaAdsDesc_out('Reword captcha/ads seed descriptions migration starting (#1685)…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

/* Byte-identical to the schema.sql copy of the same six rows — this map IS
   the migration's single source of truth for the new text, and schema.sql's
   INSERT IGNORE block must read the same six strings verbatim (rule #19). */
$descriptions = [
    'captcha_provider'   => 'RESERVED — not wired yet (#1685). No captcha code exists anywhere in this codebase today; setting this to anything other than none changes no behaviour. Intended bot-protection provider once built: none, recaptcha_v2, recaptcha_v3, turnstile, hcaptcha, friendly, altcha, mtcaptcha',
    'captcha_site_key'   => 'RESERVED — not wired yet (#1685), see captcha_provider. Intended CAPTCHA provider public site key once built',
    'captcha_secret_key' => 'RESERVED — not wired yet (#1685), see captcha_provider. Intended CAPTCHA provider server-side secret key once built',
    'ads_enabled'        => 'RESERVED — not wired yet (#1685). No ad code exists anywhere in this codebase today; setting this to 1 changes no behaviour. Intended toggle for advertisement display once built (0=off, 1=on)',
    'ads_provider'       => 'RESERVED — not wired yet (#1685), see ads_enabled. Intended ad provider once built: none, adsense, ezoic, mediavine, custom',
    'ads_publisher_id'   => 'RESERVED — not wired yet (#1685), see ads_enabled. Intended ad provider publisher/client ID once built',
];

/* Bound parameters throughout — never string-interpolated, even for a
   constant key (rule #5 of .claude/CLAUDE.md). */
$stmt = $mysqli->prepare('UPDATE tblAppSettings SET Description = ? WHERE SettingKey = ?');

$touched = 0;
foreach ($descriptions as $key => $desc) {
    $stmt->bind_param('ss', $desc, $key);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new \RuntimeException("UPDATE tblAppSettings.Description for {$key} failed: " . $mysqli->error);
    }
    $affected = $stmt->affected_rows;
    if ($affected > 0) {
        _migFixCaptchaAdsDesc_out("[upd ] tblAppSettings.{$key} description reworded.");
        $touched++;
    } else {
        /* affected_rows is 0 both when the row is absent (nothing to seed
           yet — a very old or partial install) and when the text already
           matches (a re-run). Either way there is nothing more to do. */
        _migFixCaptchaAdsDesc_out("[skip] tblAppSettings.{$key} already reworded, or row not present.");
    }
}
$stmt->close();

_migFixCaptchaAdsDesc_out("Reword captcha/ads seed descriptions migration finished (#1685) — {$touched} row(s) updated.");
