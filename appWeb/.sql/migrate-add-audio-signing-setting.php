<?php

declare(strict_types=1);

/**
 * iHymns — Audio Signing Setting Migration (#1358)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Seeds the per-app feature flag `audio_signing_enabled` into tblAppSettings,
 * DEFAULTING TO '0' (off). This is the second of the three dormancy switches
 * for signed-audio sealing (#1358) — the others are the existing
 * content_gating_enabled flag and the operator-provisioned AUDIO_SIGNING_KEY
 * constant in .auth/. With this row at '0', audioSigningEnabled() is false and
 * the whole signed-URL path is a verified no-op (bulk_audio keeps emitting the
 * legacy /data/audio/<id>.mp3 literal).
 *
 * No tables, no columns — a single INSERT IGNORE of a settings row. INSERT
 * IGNORE makes it idempotent AND non-destructive: if a prior run (or a later
 * operator) has already set the value to '1', re-running this NEVER resets it
 * back to '0' (IGNORE skips the row when the unique SettingKey already exists).
 *
 * @migration-modifies tblAppSettings (seeds audio_signing_enabled='0')
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-add-audio-signing-setting.php
 *   Web:  /manage/setup-database -> "Audio Signing Setting (#1358)" button
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

function _migAudioSigning_out(string $line): void
{
    global $isCli;
    echo $line . ($isCli ? "\n" : "<br>\n");
    if ($isCli) { flush(); }
}

_migAudioSigning_out('Audio Signing Setting migration starting (#1358)…');

$mysqli = getDbMysqli();
if (!$mysqli) {
    throw new \RuntimeException('Could not connect to database.');
}

/* INSERT IGNORE — idempotent + non-destructive. SettingKey is UNIQUE, so a
   second run (or one after an operator flipped it to '1') is a no-op and never
   clobbers the live value back to '0'. */
$key  = 'audio_signing_enabled';
$val  = '0';
$desc = 'Sign /audio MP3 URLs so gated audio streams via the gated route (#1358); '
      . '0=off (serve static /data/audio literal), 1=on (mint signed /audio URLs). '
      . 'Requires content_gating_enabled=1 AND the AUDIO_SIGNING_KEY constant.';

$stmt = $mysqli->prepare(
    'INSERT IGNORE INTO tblAppSettings (SettingKey, SettingValue, Description)
     VALUES (?, ?, ?)'
);
$stmt->bind_param('sss', $key, $val, $desc);
if (!$stmt->execute()) {
    $stmt->close();
    throw new \RuntimeException('INSERT IGNORE audio_signing_enabled failed: ' . $mysqli->error);
}
$affected = $stmt->affected_rows;
$stmt->close();

if ($affected > 0) {
    _migAudioSigning_out("[seed] tblAppSettings.audio_signing_enabled = '0' inserted.");
} else {
    _migAudioSigning_out('[skip] tblAppSettings.audio_signing_enabled already present — left untouched.');
}

_migAudioSigning_out('Audio Signing Setting migration finished (#1358).');
