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
 *     ?action=migrate   — Import song data from songs.json
 *     ?action=users     — Migrate users/setlists from SQLite/JSON
 *     ?action=cleanup   — Clean up expired tokens
 *
 * SECURITY:
 *   Protected by /manage/.htaccess (session auth required).
 *   Requires global_admin role, OR initial setup (no users exist).
 */

/* =========================================================================
 * AUTHENTICATION
 * ========================================================================= */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes/auth.php';

$isInitialSetup = needsSetup();

if (!$isInitialSetup) {
    if (!isAuthenticated()) {
        header('Location: /manage/login');
        exit;
    }
    $currentUser = getCurrentUser();
    if (!$currentUser || $currentUser['Role'] !== 'global_admin') {
        http_response_code(403);
        echo '<!DOCTYPE html><html><body><h1>403 — Global Admin access required</h1></body></html>';
        exit;
    }
}

/* =========================================================================
 * LOAD DATABASE CREDENTIALS
 * ========================================================================= */

$credFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.auth/db_credentials.php';
$hasCredentials = file_exists($credFile);

if ($hasCredentials && !defined('DB_HOST')) {
    require_once $credFile;
}

/* =========================================================================
 * ACTION HANDLING
 * ========================================================================= */

$action = $_GET['action'] ?? '';
$actionOutput = '';
$actionSuccess = false;

if ($action !== '') {
    ob_start();

    $scriptDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.sql/';
    $scriptMap = [
        'install' => 'install.php',
        'migrate' => 'migrate-json.php',
        'users'   => 'migrate-users.php',
        'cleanup' => 'cleanup.php',
        'backup'  => 'backup.php',
    ];

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
            try {
                /* Run the script in an isolated scope via an anonymous function.
                 * The scripts detect $isCli and adapt output accordingly.
                 * We catch any exceptions; exit() calls in the scripts will
                 * terminate this page but that's acceptable — the output
                 * buffer is flushed to the browser before exit. */
                $actionSuccess = true;
                require $scriptPath;
            } catch (\Throwable $e) {
                $actionSuccess = false;
                echo "\nERROR: " . htmlspecialchars($e->getMessage()) . "\n";
                if ($e->getFile()) {
                    echo "File: " . htmlspecialchars(basename($e->getFile())) . ":" . $e->getLine() . "\n";
                }
            }
        }
    }

    $actionOutput = ob_get_clean();
}

/* =========================================================================
 * DATABASE STATUS
 * ========================================================================= */

$dbStatus = null;
$dbTables = [];

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
        $statusConn->close();
    } catch (\Throwable $e) {
        $dbStatus = 'error: ' . $e->getMessage();
    }
}

/* =========================================================================
 * RENDER PAGE
 * ========================================================================= */
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup — iHymns Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #1a1a2e; color: #e0e0e0; }
        .output-log {
            background: #0d1117; color: #c9d1d9; font-family: 'Menlo', 'Consolas', monospace;
            font-size: 12px; padding: 1rem; border-radius: 0.5rem;
            max-height: 500px; overflow-y: auto; white-space: pre-wrap; word-wrap: break-word;
            line-height: 1.5;
        }
        .btn-action { min-width: 180px; }
    </style>
</head>
<body>
<div class="container py-4" style="max-width: 900px;">

    <h1 class="mb-1">Database Setup</h1>
    <p class="text-secondary mb-4">iHymns Admin &mdash; Installation, migration, and maintenance</p>

    <?php if (!$hasCredentials): ?>
        <div class="alert alert-danger">
            <strong>Credentials not found.</strong><br>
            Copy <code>appWeb/.auth/db_credentials.example.php</code> to
            <code>appWeb/.auth/db_credentials.php</code> and fill in your MySQL details.
        </div>
    <?php endif; ?>

    <?php if ($action !== ''): ?>
        <!-- ============================================================
             ACTION OUTPUT
             ============================================================ -->
        <p><a href="?" class="btn btn-outline-secondary btn-sm">&larr; Back to Dashboard</a></p>
        <h4 class="mb-2">
            <?= htmlspecialchars(ucfirst($action)) ?> Output
            <?php if ($actionSuccess): ?>
                <span class="badge bg-success ms-2">Complete</span>
            <?php else: ?>
                <span class="badge bg-danger ms-2">Error</span>
            <?php endif; ?>
        </h4>
        <div class="output-log"><?= $actionOutput ?></div>

    <?php else: ?>
        <!-- ============================================================
             ACTION CARDS
             ============================================================ -->
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card bg-dark border-secondary h-100">
                    <div class="card-body">
                        <h5 class="card-title">1. Install Tables</h5>
                        <p class="card-text text-secondary small">
                            Create all database tables from <code>schema.sql</code>.
                            Safe to re-run — existing tables are skipped.
                        </p>
                        <a href="?action=install" class="btn btn-primary btn-action <?= $hasCredentials ? '' : 'disabled' ?>">
                            Run Install
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card bg-dark border-secondary h-100">
                    <div class="card-body">
                        <h5 class="card-title">2. Migrate Song Data</h5>
                        <p class="card-text text-secondary small">
                            Import all songs from <code>data/songs.json</code> into MySQL.
                            Clears existing song data and re-imports.
                        </p>
                        <a href="?action=migrate" class="btn btn-warning btn-action <?= $hasCredentials ? '' : 'disabled' ?>"
                           onclick="return confirm('This will replace ALL song data in the database. Continue?')">
                            Run Song Migration
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card bg-dark border-secondary h-100">
                    <div class="card-body">
                        <h5 class="card-title">3. Migrate Users &amp; Setlists</h5>
                        <p class="card-text text-secondary small">
                            Import users and setlists from the legacy SQLite database
                            and shared setlist JSON files. Skips existing users.
                        </p>
                        <a href="?action=users" class="btn btn-info btn-action <?= $hasCredentials ? '' : 'disabled' ?>">
                            Run User Migration
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card bg-dark border-secondary h-100">
                    <div class="card-body">
                        <h5 class="card-title">4. Cleanup Expired Tokens</h5>
                        <p class="card-text text-secondary small">
                            Delete expired API tokens, email login codes, password reset
                            tokens, and old login attempts (30+ days).
                        </p>
                        <a href="?action=cleanup" class="btn btn-outline-secondary btn-action <?= $hasCredentials ? '' : 'disabled' ?>">
                            Run Cleanup
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card bg-dark border-secondary h-100">
                    <div class="card-body">
                        <h5 class="card-title">5. Backup Database</h5>
                        <p class="card-text text-secondary small">
                            Create a compressed SQL dump of all tables and data.
                            Keeps the last 7 backups; older ones are auto-deleted.
                        </p>
                        <a href="?action=backup" class="btn btn-outline-info btn-action <?= $hasCredentials ? '' : 'disabled' ?>">
                            Run Backup
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
             DATABASE STATUS
             ============================================================ -->
        <h4 class="mb-3">Database Status</h4>

        <?php if ($dbStatus === 'connected'): ?>
            <div class="alert alert-success py-2">
                Connected to <strong><?= htmlspecialchars(DB_NAME) ?></strong>
                @ <?= htmlspecialchars(DB_HOST) ?>:<?= DB_PORT ?>
            </div>

            <?php if (empty($dbTables)): ?>
                <div class="alert alert-warning py-2">No tables found. Run <strong>Install Tables</strong> first.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-dark table-sm table-striped table-hover">
                        <thead><tr><th>Table</th><th class="text-end">Rows</th></tr></thead>
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

    <?php endif; ?>

    <hr class="my-4">
    <p class="text-secondary text-center small">
        iHymns Database Administration &middot; v0.10.0
        <?php if (!$isInitialSetup && isset($currentUser)): ?>
            &middot; Logged in as <strong><?= htmlspecialchars($currentUser['Username'] ?? '') ?></strong>
        <?php endif; ?>
    </p>
</div>
</body>
</html>
<?php
/* Prevent any included script's exit() from showing raw output after our page */
exit;
