<?php

declare(strict_types=1);

/**
 * iHymns — Wire the CAPTCHA settings (un-reserve + add captcha_enabled_forms, #947/#340)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * The account-security pack (#947/#340) turned the three long-dormant
 * `captcha_*` seed rows into a REAL, server-verified control
 * (includes/captcha.php). This migration:
 *   1. INSERT IGNOREs the new `captcha_enabled_forms` row (CSV of gated form
 *      keys, seed '' = nothing enabled — a growable vocabulary as a CSV,
 *      never an ENUM/SET column, so this needs ZERO DDL — rule #20).
 *   2. UPDATEs the `Description` of `captcha_provider` / `captcha_site_key` /
 *      `captcha_secret_key` from the old "RESERVED — not wired yet (#1685)"
 *      text to describe the now-real wiring.
 *
 * ELI5: three settings that used to say "reserved, does nothing" now DO
 * something — an admin can pick Turnstile / hCaptcha / reCAPTCHA v2 and switch
 * a human-check onto sign-up / sign-in / etc. This relabels them to match, and
 * adds the fourth setting that says WHICH forms the check guards.
 *
 * WHY A MIGRATION, NOT JUST A schema.sql EDIT: `INSERT IGNORE` (schema.sql's
 * seed block) is a no-op against a row that already exists — so a fresh install
 * picks up the new row + corrected text automatically, but every EXISTING
 * install keeps the old row/text forever. Only this migration reaches them.
 *
 * SAFE TO RUN: purely additive + a description reword. The new row seeds ''
 * (dormant — no form is gated until an admin ticks one AND configures a
 * provider). Never changes an operator-set SettingValue. Not
 * `manual`/destructive; safe inside "Apply all pending". Idempotent — a second
 * run inserts nothing new (INSERT IGNORE) and updates the same rows to the same
 * text (affected_rows 0).
 *
 * NOTE ON #1685: the sibling `migrate-fix-captcha-ads-descriptions.php` was
 * narrowed in the SAME change to cover only the `ads_*` rows (still genuinely
 * reserved); the three `captcha_*` rows are now owned by THIS migration, so the
 * two never fight over the same rows.
 *
 * @migration-modifies tblAppSettings (adds captcha_enabled_forms; rewords
 *                      captcha_provider, captcha_site_key, captcha_secret_key)
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-wire-captcha-settings.php
 *   Web:  /manage/setup-database -> "Wire CAPTCHA settings (#947/#340)"
 *
 * @requires PHP 8.1+ with mysqli extension
 * @link https://dev.mysql.com/doc/refman/8.0/en/insert.html
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

function _migWireCaptcha_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) { flush(); }
}

_migWireCaptcha_out('Wire CAPTCHA settings migration starting (#947/#340)…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

/* --- 1. The new CSV row (seed '' = nothing gated). Byte-identical to the
       schema.sql seed for this key (rule #19). ------------------------------ */
$newFormsDesc = 'CSV of forms the CAPTCHA challenge is enforced on (#947/#340): registration, login, password_reset, email_login, song_request, manage_login. Empty = none. App-validated against captchaFormKeys(); unknown keys ignored.';
$insFormsSql  = 'INSERT IGNORE INTO tblAppSettings (SettingKey, SettingValue, Description) VALUES (?, ?, ?)';
$insForms     = $mysqli->prepare($insFormsSql);
$formsKey     = 'captcha_enabled_forms';
$formsVal     = '';
$insForms->bind_param('sss', $formsKey, $formsVal, $newFormsDesc);
if (!$insForms->execute()) {
    $insForms->close();
    throw new \RuntimeException('INSERT IGNORE tblAppSettings.captcha_enabled_forms failed: ' . $mysqli->error);
}
if ($insForms->affected_rows > 0) {
    _migWireCaptcha_out('[ins ] tblAppSettings.captcha_enabled_forms seeded (empty = no form gated).');
} else {
    _migWireCaptcha_out('[skip] tblAppSettings.captcha_enabled_forms already present.');
}
$insForms->close();

/* --- 2. Un-reserve the three captcha_* descriptions. Byte-identical to the
       schema.sql copy of these rows (rule #19) — this map IS the single source
       of truth for the new text, and schema.sql's INSERT IGNORE seed must read
       the same strings verbatim. --------------------------------------------- */
$descriptions = [
    'captcha_provider'   => 'CAPTCHA provider: none, turnstile, hcaptcha, recaptcha_v2 (#947/#340). Verified server-side by includes/captcha.php — dormant until a provider and both keys are configured on /manage/configuration and at least one form is ticked.',
    'captcha_site_key'   => 'CAPTCHA provider public site key (#947/#340) — safe to send to browsers; used to draw the widget. Dormant until a provider is configured.',
    'captcha_secret_key' => 'CAPTCHA provider server-side secret key (#947/#340) — encrypted at rest (secretSettingKeys()), server-proxied only, never sent to a browser. Dormant until a provider is configured.',
];

/* Bound parameters throughout — never string-interpolated (rule #5). */
$stmt = $mysqli->prepare('UPDATE tblAppSettings SET Description = ? WHERE SettingKey = ?');
$touched = 0;
foreach ($descriptions as $key => $desc) {
    $stmt->bind_param('ss', $desc, $key);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new \RuntimeException("UPDATE tblAppSettings.Description for {$key} failed: " . $mysqli->error);
    }
    if ($stmt->affected_rows > 0) {
        _migWireCaptcha_out("[upd ] tblAppSettings.{$key} description un-reserved.");
        $touched++;
    } else {
        /* affected_rows 0 = row absent (very old/partial install) or text
           already matches (a re-run). Nothing more to do either way. */
        _migWireCaptcha_out("[skip] tblAppSettings.{$key} already wired, or row not present.");
    }
}
$stmt->close();

_migWireCaptcha_out("Wire CAPTCHA settings migration finished (#947/#340) — {$touched} description(s) updated.");
