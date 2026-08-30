<?php

declare(strict_types=1);

/**
 * iHymns — Data Health "disconnect legacy fallbacks" write core
 * (API-coverage Batch 5, A15, `.claude/api-coverage-2026-08-28.md` §4.3/§9).
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `/manage/data-health` has one write action: "the old backup files aren't
 * needed any more — rename them to `.disabled` so nothing reads them by
 * accident." This file is that one rename-loop, pulled out of the page so
 * `api.php`'s new `admin_data_health_fix` action can offer the SAME
 * operation to a native admin client instead of re-typing the loop.
 *
 * DETAILED
 * --------
 * `dataHealthLegacyPaths()` resolves the SAME three legacy paths the page
 * always inspected (`APP_DATA_FILE`, `APP_SETLIST_SHARE_DIR`, and the
 * fixed legacy SQLite path under `data_share/SQLite/ihymns.db`) so both
 * callers agree on what "legacy" means without re-deriving it.
 * `dataHealthDisconnectFallbacks()` is a byte-identical extraction of the
 * page's former inline rename loop (`case 'disconnect_fallbacks'`) — same
 * per-path skip/rename/fail bucketing, same `.disabled` suffix, same
 * "reversible — rename back by hand" posture. It takes an op name so the
 * API's own allow-list gate (rule #20 — a growable admin op vocabulary is
 * an app-level allow-list, never a bare boolean) has something to switch
 * on if a second fix op is ever added; today `disconnect_fallbacks` is the
 * only recognised value and an unknown op is refused with a 400.
 *
 * Direct access is blocked (the same guard every other includes/*.php
 * helper carries).
 *
 * @see appWeb/public_html/manage/data-health.php   the page this was extracted from
 * @see appWeb/public_html/api.php                   admin_data_health_fix
 * @see .claude/api-coverage-2026-08-28.md §4.3/§9 A15 the plan this implements
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * iHymns — the three legacy on-disk sources `/manage/data-health` inspects
 * + can disconnect. Keyed exactly as the page's report + the disconnect
 * loop always keyed them (`songs_json` / `setlist_dir` / `sqlite_db`), so a
 * caller's "renamed"/"skipped" messages read identically to the page's own.
 *
 * @return array<string,string> key => absolute path ('' when not configured).
 */
function dataHealthLegacyPaths(): array
{
    return [
        'songs_json'  => defined('APP_DATA_FILE')         ? APP_DATA_FILE         : '',
        'setlist_dir' => defined('APP_SETLIST_SHARE_DIR')  ? APP_SETLIST_SHARE_DIR : '',
        'sqlite_db'   => defined('APP_ROOT')
            ? dirname(APP_ROOT) . DIRECTORY_SEPARATOR . 'data_share' . DIRECTORY_SEPARATOR . 'SQLite'
              . DIRECTORY_SEPARATOR . 'ihymns.db'
            : '',
    ];
}

/**
 * iHymns — rename every present legacy path to `<path>.disabled`. Byte-
 * identical extraction of `manage/data-health.php`'s former
 * `case 'disconnect_fallbacks'` body: not present -> skipped; already
 * `.disabled` -> skipped; rename failure -> failed (permissions); anything
 * else -> renamed. Reversible by hand (rename the `.disabled` suffix away).
 *
 * @param array<string,string> $paths key => absolute path, e.g. the
 *        `dataHealthLegacyPaths()` shape.
 * @return array{renamed:list<string>,skipped:list<string>,failed:list<string>}
 *   Each entry is a human-readable "`key` → …" / "`key` (reason)" line,
 *   exactly as the page always rendered them.
 */
function dataHealthDisconnectFallbacks(array $paths): array
{
    $renamed = [];
    $skipped = [];
    $failed  = [];
    foreach ($paths as $k => $path) {
        if ($path === '') { $skipped[] = "{$k} (no path configured)"; continue; }
        if (!file_exists($path)) { $skipped[] = "{$k} (not present)"; continue; }
        $target = $path . '.disabled';
        if (file_exists($target)) { $skipped[] = "{$k} (already disabled)"; continue; }
        if (@rename($path, $target)) {
            $renamed[] = "{$k} → " . basename($target);
        } else {
            $failed[] = "{$k} (rename failed — check permissions)";
        }
    }
    return ['renamed' => $renamed, 'skipped' => $skipped, 'failed' => $failed];
}

/**
 * iHymns — the ONE op allow-list `admin_data_health_fix` (api.php) switches
 * on (rule #20 — a growable admin-op vocabulary is an app-level allow-list,
 * never a bare boolean flag). Today only `disconnect_fallbacks` exists;
 * an unrecognised op is refused with a 400 rather than silently no-op'd.
 *
 * @return array{ok:bool,status?:int,error?:string,renamed?:list<string>,skipped?:list<string>,failed?:list<string>}
 */
function dataHealthFixApply(string $op): array
{
    if ($op !== 'disconnect_fallbacks') {
        return ['ok' => false, 'status' => 400, 'error' => "Unknown fix op. Use: disconnect_fallbacks."];
    }
    $result = dataHealthDisconnectFallbacks(dataHealthLegacyPaths());
    return ['ok' => true] + $result;
}
