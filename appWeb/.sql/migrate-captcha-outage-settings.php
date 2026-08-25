<?php

declare(strict_types=1);

/**
 * iHymns — CAPTCHA outage-fallback settings (#947/#340 outage fallback)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Seeds the TWO tblAppSettings rows the CAPTCHA provider-outage grace window
 * uses (includes/captcha.php):
 *   1. `captcha_outage_strict_forms` — CSV of forms that must keep failing
 *      CLOSED even while this server has itself confirmed the provider is
 *      unreachable. Seeded EMPTY, which is owner decision D-F1 = A: every
 *      ticked form degrades open to the pre-CAPTCHA defence floor (per-IP /
 *      per-account budgets, honeypot, daily caps — none of which are switched
 *      off) rather than locking a congregation out during an outage.
 *   2. `captcha_health_state` — the small JSON blob recording what this server
 *      last observed about the provider. Machine state; seeded empty.
 *
 * ELI5: two new saved settings. One says "which forms should stay strict even
 * if the human-check company's service goes down" (empty = none — everything
 * falls back to the ordinary rate limits instead of locking people out). The
 * other is a scratchpad where the site writes down what it last saw when it
 * checked on that company.
 *
 * ⚠️ THIS MIGRATION IS FOR HYGIENE, NOT CORRECTNESS. The code reads both rows
 * through getAppSetting($key, '') — i.e. an install that has NEVER run this
 * behaves identically to one that has: the strict list is empty, the health
 * state is cold (which ENFORCES, the fail-safe direction), and the first real
 * observation writes the row through setAppSetting()'s INSERT … ON DUPLICATE
 * KEY UPDATE. What this migration buys is the DESCRIPTION text (so the settings
 * table explains itself, and an admin browsing tblAppSettings does not find two
 * undocumented keys) and parity with schema.sql, which the Schema Audit page
 * and tests/php/test-schema-coverage.php both compare against.
 *
 * ZERO DDL. Both are settings ROWS, never columns and never an ENUM — rule #20:
 * `captcha_outage_strict_forms` is a growable vocabulary (a CSV app-validated
 * against captchaFormKeys()), and adding a form to it must never be an ALTER.
 *
 * SAFE TO RUN: purely additive, idempotent (INSERT IGNORE — a second run
 * inserts nothing and never touches an operator-set value), non-destructive,
 * safe inside "Apply all pending".
 *
 * @migration-modifies tblAppSettings (adds captcha_outage_strict_forms,
 *                      captcha_health_state)
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-captcha-outage-settings.php
 *   Web:  /manage/setup-database -> "CAPTCHA outage fallback settings"
 *
 * @see appWeb/public_html/includes/captcha.php (the ONE reader/writer)
 * @see .claude/captcha-native-and-outage-plan.md §3.5
 * @requires PHP 8.1+ with mysqli extension
 * @link https://dev.mysql.com/doc/refman/8.0/en/insert.html
 */

if (PHP_SAPI === 'cli') {
    if (!function_exists('getDbMysqli')) {
        require_once dirname(__DIR__) . '/public_html/includes/db_mysql.php';
    }
    $isCli = true;
} else {
    /* Rule #41: every require below is GUARDED so it never runs inside the
       /manage/setup-database runner (which has already loaded these, and which
       lives in a docroot whose folder name differs per channel — public_html /
       public_html_dev / public_html_beta). The literal '/public_html/' path is
       therefore only ever taken by a standalone CLI/test run from the repo. */
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

function _migCaptchaOutage_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) { flush(); }
}

_migCaptchaOutage_out('CAPTCHA outage-fallback settings migration starting (#947/#340)…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

/* The seed text is byte-identical to schema.sql's INSERT IGNORE block for these
   two keys (rule #19) — a fresh install and a migrated one must not differ by a
   single character, or the Schema Audit page reports a phantom divergence. */
$rows = [
    'captcha_outage_strict_forms' => 'CSV of CAPTCHA-gated forms that stay fail-CLOSED during a server-verified provider outage (#947/#340). Empty = each gated form degrades to the pre-CAPTCHA floor (rate limits, honeypot, caps) while unreachable. Validated vs captchaFormKeys().',
    'captcha_health_state'        => 'MACHINE STATE, do not hand-edit — JSON record of what this server last observed about the CAPTCHA provider (status up|down|misconfig, checkedAt, downSince, counters), written by includes/captcha.php. Empty/malformed = never checked, which ENFORCES.',
];

$stmt = $mysqli->prepare(
    'INSERT IGNORE INTO tblAppSettings (SettingKey, SettingValue, Description) VALUES (?, ?, ?)'
);
$inserted = 0;
$value    = '';   /* both seed empty — dormant + cold */
foreach ($rows as $key => $desc) {
    /* Bound parameters throughout — never string-interpolated (rule #5). */
    $stmt->bind_param('sss', $key, $value, $desc);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new \RuntimeException("INSERT IGNORE tblAppSettings.{$key} failed: " . $mysqli->error);
    }
    if ($stmt->affected_rows > 0) {
        _migCaptchaOutage_out("[ins ] tblAppSettings.{$key} seeded (empty).");
        $inserted++;
    } else {
        _migCaptchaOutage_out("[skip] tblAppSettings.{$key} already present.");
    }
}
$stmt->close();

_migCaptchaOutage_out("CAPTCHA outage-fallback settings migration finished — {$inserted} row(s) inserted.");
