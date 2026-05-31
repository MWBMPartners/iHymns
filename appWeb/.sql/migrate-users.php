<?php

declare(strict_types=1);

/**
 * iHymns — Migrate Users, Setlists & Shared Setlists to MySQL
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Migrates existing data from the legacy storage mechanisms into MySQL:
 *   1. Users from SQLite (appWeb/data_share/SQLite/ihymns.db)
 *   2. User setlists from SQLite
 *   3. Shared setlists from JSON files (appWeb/data_share/setlist_json/)
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-users.php
 *   Web:  Navigate to this file in a browser (protected by .htaccess)
 *
 *   Works in both CLI and web environments (e.g., shared hosting
 *   without shell access like DreamHost).
 *
 * PREREQUISITES:
 *   1. MySQL tables must already exist (run install.php first)
 *   2. appWeb/.auth/db_credentials.php must be configured
 *
 * BEHAVIOR:
 *   - Skips users that already exist (by username)
 *   - Preserves password hashes (no re-hashing needed)
 *   - Imports shared setlists from JSON files on disk
 *   - Reports progress and counts
 *
 * @requires PHP 8.1+ with mysqli and pdo_sqlite extensions
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    /* Standalone web mode only — skip these headers when included by the
     * Setup dashboard so its HTML response isn't hijacked. */
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

/* =========================================================================
 * LOAD MYSQL CREDENTIALS
 * ========================================================================= */

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

/* =========================================================================
 * CONNECT TO MYSQL
 * ========================================================================= */

output("");
output("╔══════════════════════════════════════════════════════════╗");
output("║      iHymns — User & Setlist Migration to MySQL         ║");
output("╚══════════════════════════════════════════════════════════╝");
output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
output("Connected to MySQL: " . DB_NAME);

/* =========================================================================
 * STEP 1: MIGRATE USERS FROM SQLITE
 * ========================================================================= */

$sqliteFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data_share' . DIRECTORY_SEPARATOR . 'SQLite' . DIRECTORY_SEPARATOR . 'ihymns.db';
$migratedUsers = 0;
$skippedUsers = 0;
$migratedSetlists = 0;

if (file_exists($sqliteFile)) {
    output("");
    output("--- Step 1: Migrate Users from SQLite ---");
    output("Source: " . realpath($sqliteFile));

    try {
        $sqlite = new PDO('sqlite:' . $sqliteFile);
        $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        /* Check if users table exists in SQLite */
        $tables = $sqlite->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);

        if (in_array('users', $tables)) {
            $users = $sqlite->query('SELECT * FROM users')->fetchAll(PDO::FETCH_ASSOC);
            output("Found " . count($users) . " users in SQLite");

            $stmtCheck  = $mysql->prepare("SELECT COUNT(*) AS cnt FROM tblUsers WHERE Username = ?");
            $stmtInsert = $mysql->prepare(
                "INSERT INTO tblUsers (Username, Email, PasswordHash, DisplayName, Role, IsActive, CreatedAt, UpdatedAt)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())"
            );

            foreach ($users as $user) {
                $username = $user['username'] ?? '';
                if ($username === '') continue;

                /* Check if user already exists in MySQL */
                $stmtCheck->bind_param('s', $username);
                $stmtCheck->execute();
                $result = $stmtCheck->get_result();
                $exists = (int)$result->fetch_assoc()['cnt'] > 0;

                if ($exists) {
                    output("  [SKIP] {$username} (already exists)");
                    $skippedUsers++;
                    continue;
                }

                $email       = $user['email']         ?? '';
                $passHash    = $user['password_hash'] ?? '';
                $displayName = $user['display_name']  ?? $username;
                $role        = $user['role']          ?? 'user';
                $isActive    = (int)($user['is_active'] ?? 1);

                /* 5 strings + 1 int — CreatedAt/UpdatedAt are set via NOW() in SQL */
                $stmtInsert->bind_param(
                    'sssssi',
                    $username, $email, $passHash, $displayName, $role, $isActive
                );
                $stmtInsert->execute();

                output("  [OK]   {$username} ({$role})");
                $migratedUsers++;
            }

            $stmtCheck->close();
            $stmtInsert->close();

            /* Migrate user setlists. Diagnostic-rich output (#709) so a
               curator who runs this and sees tblUserSetlists still
               empty can tell *why* — the most common cause is
               username mapping failures (legacy SQLite username != the
               username on the new tblUsers row), which used to scroll
               past silently. */
            output("");
            output("--- Step 2: Migrate User Setlists from SQLite ---");
            if (!in_array('user_setlists', $tables)) {
                output("  [SKIP] No user_setlists table in legacy SQLite — nothing to migrate.");
                output("  Available SQLite tables: " . implode(', ', $tables));
            } else {
                $setlists = $sqlite->query('SELECT * FROM user_setlists')->fetchAll(PDO::FETCH_ASSOC);
                output("Found " . count($setlists) . " user setlists in SQLite");
                $skippedNoSetlistId = 0;
                $skippedNoUserId    = 0;
                $skippedNoLegacyUsername = 0;
                $skippedNoMysqlUser = 0;

                foreach ($setlists as $sl) {
                    $oldUserId = (int)($sl['user_id'] ?? 0);
                    $setlistId = $sl['setlist_id'] ?? '';
                    $name      = $sl['name'] ?? 'Untitled';
                    $songsJson = $sl['songs_json'] ?? '[]';

                    if ($setlistId === '') { $skippedNoSetlistId++; continue; }
                    if ($oldUserId <= 0)   { $skippedNoUserId++;    continue; }

                    /* Find the old username to map to new MySQL user ID */
                    $oldUser = $sqlite->prepare('SELECT username FROM users WHERE id = ?');
                    $oldUser->execute([$oldUserId]);
                    $oldUsername = $oldUser->fetchColumn();
                    if (!$oldUsername) { $skippedNoLegacyUsername++; continue; }

                    /* Find new MySQL user ID */
                    $stmtFind = $mysql->prepare("SELECT Id FROM tblUsers WHERE Username = ?");
                    $stmtFind->bind_param('s', $oldUsername);
                    $stmtFind->execute();
                    $newUserId = $stmtFind->get_result()->fetch_assoc()['Id'] ?? null;
                    $stmtFind->close();
                    if (!$newUserId) {
                        $skippedNoMysqlUser++;
                        output("  [SKIP] setlist {$setlistId} — legacy username '{$oldUsername}' has no matching tblUsers row");
                        continue;
                    }

                    /* Prepared statement (#525). Was real_escape_string +
                       string interpolation, which is functional but the
                       only place in the migration scripts not using the
                       codebase's prepared-statement convention. JSON
                       values like SongsJson are particularly easy to get
                       wrong with hand-rolled escaping. */
                    $insStmt = $mysql->prepare(
                        'INSERT IGNORE INTO tblUserSetlists
                            (UserId, SetlistId, Name, SongsJson)
                         VALUES (?, ?, ?, ?)'
                    );
                    $insStmt->bind_param('isss', $newUserId, $setlistId, $name, $songsJson);
                    $insStmt->execute();
                    $insStmt->close();
                    $migratedSetlists++;
                }
                output("  Migrated {$migratedSetlists} setlists");
                if ($skippedNoSetlistId + $skippedNoUserId + $skippedNoLegacyUsername + $skippedNoMysqlUser > 0) {
                    output("  Skipped breakdown:");
                    if ($skippedNoSetlistId)        output("    - {$skippedNoSetlistId} with empty setlist_id");
                    if ($skippedNoUserId)           output("    - {$skippedNoUserId} with no user_id");
                    if ($skippedNoLegacyUsername)   output("    - {$skippedNoLegacyUsername} where the legacy SQLite users row was missing");
                    if ($skippedNoMysqlUser)        output("    - {$skippedNoMysqlUser} where the legacy username didn't match any tblUsers.Username (the most common cause of an 'empty tblUserSetlists despite running the migration' report — see #709)");
                }
            }
        } else {
            output("  No users table found in SQLite — skipping");
        }
    } catch (\Exception $e) {
        output("  WARNING: SQLite migration error: " . $e->getMessage());
        output("  Continuing with remaining steps...");
    }
} else {
    output("");
    output("--- Step 1: No SQLite database found — skipping user migration ---");
    output("  (Expected at: {$sqliteFile})");
}

/* =========================================================================
 * STEP 3: MIGRATE SHARED SETLISTS FROM JSON FILES
 * ========================================================================= */

output("");
output("--- Step 3: Migrate Shared Setlists from JSON files ---");

$setlistDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data_share' . DIRECTORY_SEPARATOR . 'setlist_json';
$migratedShared = 0;

if (is_dir($setlistDir)) {
    $files = glob($setlistDir . '/*.json');
    output("Found " . count($files) . " shared setlist files");

    foreach ($files as $file) {
        $content = file_get_contents($file);
        $data = json_decode($content, true);
        if (!is_array($data) || empty($data['id'])) continue;

        $shareId = basename($file, '.json');

        /* These are already stored as JSON files — they continue to work as-is.
         * But we can optionally copy key metadata to tblAppSettings or a future
         * shared setlists table if needed. For now, just verify they're valid. */
        $songCount = count($data['songs'] ?? []);
        $name = $data['name'] ?? 'Untitled';
        output("  [OK] {$shareId} — \"{$name}\" ({$songCount} songs)");
        $migratedShared++;
    }
} else {
    output("  No shared setlist directory found — skipping");
}

/* =========================================================================
 * SUMMARY
 * ========================================================================= */

output("");
output("─── Migration Summary ───");
output("  Users migrated:    {$migratedUsers}");
output("  Users skipped:     {$skippedUsers} (already in MySQL)");
output("  Setlists migrated: {$migratedSetlists}");
output("  Shared setlists:   {$migratedShared} verified");
output("");
output("Migration complete.");

$mysql->close();
return;
