<?php

declare(strict_types=1);

/**
 * iHymns — Web-Accessible Database Setup & Migration
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Web-accessible entry point for database setup and migration scripts.
 * Located in /manage/ so it's protected by admin authentication.
 *
 * USAGE:
 *   Navigate to: /manage/setup-database.php
 *   Or with action parameter:
 *     ?action=install   — Create database tables
 *     ?action=migrate   — Import song data from songs.json
 *     ?action=users     — Migrate users/setlists from SQLite
 *     ?action=cleanup   — Clean up expired tokens
 *     ?action=status    — Show database status
 *
 * SECURITY:
 *   Protected by /manage/.htaccess (admin session required).
 *   Additionally checks for global_admin role.
 */

require_once __DIR__ . '/includes/auth.php';

/* Require authentication — global_admin only */
if (!isAuthenticated()) {
    /* If no users exist at all, allow access for initial setup */
    if (!needsSetup()) {
        header('Location: /manage/login');
        exit;
    }
} else {
    $currentUser = getCurrentUser();
    if ($currentUser && $currentUser['Role'] !== 'global_admin') {
        http_response_code(403);
        echo '<!DOCTYPE html><html><body><h1>403 — Global Admin access required</h1></body></html>';
        exit;
    }
}

$action = $_GET['action'] ?? '';

/* =========================================================================
 * PAGE LAYOUT
 * ========================================================================= */

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
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
        .output-log { background: #0d1117; color: #c9d1d9; font-family: monospace; font-size: 13px;
                       padding: 1rem; border-radius: 0.5rem; max-height: 600px; overflow-y: auto;
                       white-space: pre-wrap; word-wrap: break-word; }
        .btn-action { min-width: 200px; }
    </style>
</head>
<body>
    <div class="container py-4" style="max-width: 900px;">
        <h1 class="mb-1">Database Setup</h1>
        <p class="text-secondary mb-4">iHymns Admin — Database installation, migration, and maintenance</p>

        <?php if ($action === ''): ?>
            <!-- ACTION SELECTION -->
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="card bg-dark border-secondary h-100">
                        <div class="card-body">
                            <h5 class="card-title">Install Tables</h5>
                            <p class="card-text text-secondary">Create all database tables from schema.sql. Safe to re-run — existing tables are skipped.</p>
                            <a href="?action=install" class="btn btn-primary btn-action">Run Install</a>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card bg-dark border-secondary h-100">
                        <div class="card-body">
                            <h5 class="card-title">Migrate Song Data</h5>
                            <p class="card-text text-secondary">Import all songs from data/songs.json into MySQL. Clears and re-imports.</p>
                            <a href="?action=migrate" class="btn btn-warning btn-action">Run Migration</a>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card bg-dark border-secondary h-100">
                        <div class="card-body">
                            <h5 class="card-title">Migrate Users & Setlists</h5>
                            <p class="card-text text-secondary">Import users and setlists from the legacy SQLite database and JSON files.</p>
                            <a href="?action=users" class="btn btn-info btn-action">Run User Migration</a>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card bg-dark border-secondary h-100">
                        <div class="card-body">
                            <h5 class="card-title">Cleanup Expired Tokens</h5>
                            <p class="card-text text-secondary">Delete expired API tokens, login tokens, and old login attempts.</p>
                            <a href="?action=cleanup" class="btn btn-outline-secondary btn-action">Run Cleanup</a>
                        </div>
                    </div>
                </div>
            </div>

            <hr class="my-4">

            <!-- DATABASE STATUS -->
            <h4>Database Status</h4>
            <?php
            try {
                $credFile = dirname(__DIR__, 2) . '/.auth/db_credentials.php';
                if (!file_exists($credFile)) {
                    echo '<div class="alert alert-danger">Credentials file not found. Copy <code>db_credentials.example.php</code> to <code>db_credentials.php</code> in <code>appWeb/.auth/</code></div>';
                } else {
                    require_once $credFile;
                    $testConn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
                    $testConn->set_charset('utf8mb4');

                    echo '<div class="alert alert-success">Connected to MySQL: <strong>' . htmlspecialchars(DB_NAME) . '</strong> @ ' . htmlspecialchars(DB_HOST) . ':' . DB_PORT . '</div>';

                    /* Show table counts */
                    $result = $testConn->query("SHOW TABLES");
                    $tables = [];
                    while ($row = $result->fetch_array()) {
                        $tableName = $row[0];
                        $countResult = $testConn->query("SELECT COUNT(*) AS cnt FROM `{$tableName}`");
                        $count = $countResult ? (int)$countResult->fetch_assoc()['cnt'] : '?';
                        $tables[] = ['name' => $tableName, 'count' => $count];
                    }

                    if (empty($tables)) {
                        echo '<div class="alert alert-warning">No tables found. Run <strong>Install Tables</strong> first.</div>';
                    } else {
                        echo '<table class="table table-dark table-sm table-striped"><thead><tr><th>Table</th><th>Rows</th></tr></thead><tbody>';
                        foreach ($tables as $t) {
                            echo '<tr><td><code>' . htmlspecialchars($t['name']) . '</code></td><td>' . number_format($t['count']) . '</td></tr>';
                        }
                        echo '</tbody></table>';
                    }
                    $testConn->close();
                }
            } catch (\Exception $e) {
                echo '<div class="alert alert-danger">Connection error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            ?>

        <?php else: ?>
            <!-- SCRIPT OUTPUT -->
            <p><a href="?" class="btn btn-outline-secondary btn-sm mb-3">&larr; Back to Dashboard</a></p>
            <div class="output-log"><?php
                $scriptDir = dirname(__DIR__, 2) . '/.sql/';
                $scriptMap = [
                    'install' => $scriptDir . 'install.php',
                    'migrate' => $scriptDir . 'migrate-json.php',
                    'users'   => $scriptDir . 'migrate-users.php',
                    'cleanup' => $scriptDir . 'cleanup.php',
                ];

                $scriptFile = $scriptMap[$action] ?? null;
                if ($scriptFile && file_exists($scriptFile)) {
                    /* Capture and display output */
                    ob_start();
                    try {
                        include $scriptFile;
                    } catch (\Throwable $e) {
                        echo "\nERROR: " . $e->getMessage() . "\n";
                    }
                    $output = ob_get_clean();
                    echo htmlspecialchars($output);
                } else {
                    echo "Unknown action: " . htmlspecialchars($action);
                }
            ?></div>
        <?php endif; ?>

        <hr class="my-4">
        <p class="text-secondary text-center small">iHymns Database Administration &middot; v0.10.0</p>
    </div>
</body>
</html>
