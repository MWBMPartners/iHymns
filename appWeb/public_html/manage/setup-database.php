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
 *   POST action=save-credentials — Write .auth/db_credentials.php from form
 *
 * SECURITY:
 *   Protected by /manage/.htaccess (session auth required).
 *   Requires global_admin role, OR initial setup (no users exist).
 */

/* =========================================================================
 * AUTHENTICATION
 * ========================================================================= */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';

$isInitialSetup = needsSetup();

if (!$isInitialSetup) {
    if (!isAuthenticated()) {
        header('Location: /manage/login');
        exit;
    }
    $currentUser = getCurrentUser();
    if (!$currentUser || $currentUser['role'] !== 'global_admin') {
        http_response_code(403);
        echo '<!DOCTYPE html><html><body><h1>403 — Global Admin access required</h1></body></html>';
        exit;
    }
}

$activePage = 'setup-database';

/* =========================================================================
 * LOAD DATABASE CREDENTIALS
 * ========================================================================= */

$credDir  = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.auth';
$credFile = $credDir . DIRECTORY_SEPARATOR . 'db_credentials.php';
$hasCredentials = file_exists($credFile);

if ($hasCredentials && !defined('DB_HOST')) {
    require_once $credFile;
}

/* =========================================================================
 * SAVE CREDENTIALS (POST) — writes appWeb/.auth/db_credentials.php
 * ========================================================================= */

$credFormValues = [
    'host'    => defined('DB_HOST') ? DB_HOST : '127.0.0.1',
    'port'    => defined('DB_PORT') ? (string)DB_PORT : '3306',
    'name'    => defined('DB_NAME') ? DB_NAME : 'ihymns',
    'user'    => defined('DB_USER') ? DB_USER : 'ihymns_user',
    'pass'    => '',
    'prefix'  => defined('DB_PREFIX') ? DB_PREFIX : '',
];
$credError   = '';
$credSuccess = '';

/* CSRF gate for every POST on this page — the credentials form AND
   the backup-upload form go through here. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save-credentials') {
    $host   = trim((string)($_POST['host']    ?? ''));
    $port   = trim((string)($_POST['port']    ?? '3306'));
    $name   = trim((string)($_POST['name']    ?? ''));
    $user   = trim((string)($_POST['user']    ?? ''));
    $pass   = (string)($_POST['pass']         ?? '');
    $prefix = trim((string)($_POST['prefix']  ?? ''));

    $credFormValues = [
        'host' => $host, 'port' => $port, 'name' => $name,
        'user' => $user, 'pass' => '', 'prefix' => $prefix,
    ];

    if ($host === '' || $name === '' || $user === '') {
        $credError = 'Host, database name, and username are required.';
    } elseif (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) {
        $credError = 'Port must be a number between 1 and 65535.';
    } else {
        /* Sanitise prefix: alphanumeric + underscore only, trailing underscore enforced */
        if ($prefix !== '') {
            $prefix = preg_replace('/[^A-Za-z0-9_]/', '', $prefix);
            if (!str_ends_with($prefix, '_')) {
                $prefix .= '_';
            }
        }

        /* Test the connection before writing anything */
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $testConn = new mysqli($host, $user, $pass, $name, (int)$port);
            $testConn->set_charset('utf8mb4');
            $testConn->close();
        } catch (\Throwable $e) {
            $credError = 'Connection test failed: ' . $e->getMessage();
        }

        if ($credError === '') {
            if (!is_dir($credDir)) {
                @mkdir($credDir, 0755, true);
            }

            $escHost   = addslashes($host);
            $escName   = addslashes($name);
            $escUser   = addslashes($user);
            $escPass   = addslashes($pass);
            $escPrefix = addslashes($prefix);
            $intPort   = (int)$port;

            $content = <<<PHP
<?php

/**
 * iHymns — MySQL Database Credentials
 *
 * Generated by the iHymns Database Setup Dashboard.
 * This file is excluded from version control via .gitignore.
 */

define('DB_HOST',    '{$escHost}');
define('DB_PORT',    {$intPort});
define('DB_NAME',    '{$escName}');
define('DB_USER',    '{$escUser}');
define('DB_PASS',    '{$escPass}');
define('DB_CHARSET', 'utf8mb4');
define('DB_PREFIX',  '{$escPrefix}');
PHP;

            $written = @file_put_contents($credFile, $content, LOCK_EX);
            if ($written === false) {
                $credError = 'Failed to write credentials file. Check write permissions on ' . $credDir;
            } else {
                @chmod($credFile, 0600);
                /* PRG — redirect so a refresh doesn't re-submit the form */
                header('Location: ?saved=1');
                exit;
            }
        }
    }
}

if (isset($_GET['saved'])) {
    $credSuccess = 'Credentials saved and connection verified.';
}

/* =========================================================================
 * ACTION HANDLING
 * ========================================================================= */

$action = $_GET['action'] ?? '';
$actionOutput = '';
$actionSuccess = false;

/* User-friendly action titles for the status heading (#814). The
   previous heading rendered `ucfirst($action)` which left the URL
   slug visible (e.g. "Bulk-import-jobs Output"). This map mirrors
   each card's title so the operator sees the same name on the
   action page that they clicked on the dashboard. Add an entry
   when a new action is registered in $scriptMap below. Falling
   back to `ucfirst()` keeps unknown actions readable instead of
   PHP-warning. */
$friendlyTitles = [
    /* Top-level operations */
    'install'                          => 'Install Tables',
    'migrate'                          => 'Migrate Song Data',
    'users'                            => 'Migrate Users & Setlists',
    'cleanup'                          => 'Cleanup Expired Tokens',
    'backup'                           => 'Backup Database',
    'restore'                          => 'Restore from Backup',
    'drop-legacy'                      => 'Drop Legacy Tables',
    'apply-all-migrations'             => 'Apply All Pending Migrations',
    /* Per-migration cards (label = card title minus the legacy
       alphabetic prefix; #816 standardised this on the issue
       number as the primary identifier). */
    'account-sync'                     => 'Account Sync & Shared Setlists',
    'credits'                          => 'Credit Fields (#497)',
    'songbook-meta'                    => 'Songbook Metadata (#502)',
    'user-features-catchup'            => 'User Features Catch-Up (#517)',
    'activity-log-expand'              => 'Activity Log Expansion (#535)',
    'credit-people'                    => 'Credit People Registry (#545)',
    'credit-people-flags'              => 'Credit People Flags (#584, #585)',
    'song-artists'                     => 'Songs Artist credit (#587)',
    'credit-people-slug'               => 'Credit People Slug + public page (#588)',
    'credit-people-slug-rebackfill'    => 'Credit People Slug re-backfill (audit follow-up)',
    'credit-people-name-parts'         => 'Credit People Structured Name (FirstNames / Surname / Suffix) (#934)',
    'catalogues'                       => 'Catalogues — many-to-many song grouping (#941)',
    'backfill-works-from-iswc'         => 'Backfill Works from existing ISWCs (#942)',
    'user-avatar-service'              => 'Per-user Avatar Service (#616)',
    'organisation-licences'            => 'Multiple Licence Types per Organisation (#640)',
    'songbook-affiliations'            => 'Songbook Affiliations Registry (#670)',
    'songbook-bibliographic'           => 'Songbook Bibliographic Metadata (#672)',
    'songbook-language'                => 'Songbook Language Column (#673)',
    'ietf-bcp47-language'              => 'IETF BCP 47 Language Tagging (#681)',
    'bulk-import-jobs'                 => 'Bulk Import Jobs Tracking (#676)',
    'backfill-legacy-songbook-languages' => 'Backfill Legacy Songbook Languages (#735)',
    'backfill-song-language-from-songbook' => 'Backfill Song Language from Songbook (audit follow-up)',
    'user-preferred-languages'         => 'User Preferred Languages Column (#736)',
    'iana-language-subtag-registry'    => 'IETF BCP 47 Reference Data (#738)',
    'cldr-native-names'                => 'CLDR Native Names Overlay',
    'tag-titlecase'                    => 'Tag Title-Case Backfill (#762)',
    'tblsongs-number-nullable'         => 'tblSongs.Number Nullable (#783)',
    'multi-language-tables'            => 'Multi-language Tables (#778 phase A)',
    'parent-songbooks'                 => 'Parent Songbooks (#782 phase A)',
    'song-links'                       => 'Cross-book Song Links (#807 / #808)',
    'songcount-triggers'               => 'SongCount Triggers (#793)',
    'songbook-compilers'               => 'Songbook Compilers (#831)',
    'alternative-titles'               => 'Alternative Titles for Songs &amp; Songbooks (#832)',
    'external-links'                   => 'External Links System (#833)',
    'backfill-songbook-links'          => 'Backfill Songbook URL columns → External Links (#833)',
    'backfill-credit-person-links'     => 'Backfill Credit-Person Links → External Links (#833)',
    'works'                            => 'Works — composition grouping (#840)',
    'external-link-patterns'           => 'External-Link URL Patterns (#845)',
    'extra-streaming-platforms'        => 'Extra Streaming Platforms',
    'media-database-providers'         => 'Media Database Providers',
    'musicbrainz-style-links'          => 'MusicBrainz-Parity External Links',
    'credit-person-identifiers'        => 'Credit-Person Identifiers (IPI + ISNI)',
    'canonicalise-existing-isni'       => 'Canonicalise Existing ISNI Rows',
    'song-media'                       => 'Song Media Uploads (#853)',
    'song-component-language'          => 'Song Component Language Override (#858)',
    'song-arrangement'                 => 'Song Arrangement Persistence (#892)',
    'bulk-import-per-songbook'         => 'Bulk-Import Per-Songbook Breakdown (#906)',
    'bulk-import-phase-label'          => 'Bulk-Import Phase Label (#907)',
    'activity-log-proxy-vpn'           => 'Activity Log Proxy/VPN + Per-Request',
    'email-verification-tokens'        => 'Email Verification Tokens (#898)',
    'password-reset-token-hash-width'  => 'Password Reset Token Hash Width (#898 follow-up)',
    'email-login-token-hashing'        => 'Email Login Token Hashing (#898 follow-up)',
    /* No-op file-touch (force-deploy 2026-05-09) — the previous SFTP
       deploy of #919 skipped this file under lftp `--only-newer`
       because the local file mtime didn't surpass the remote's
       prior-deploy mtime. This whitespace nudge gives the next
       deploy a fresh-mtime file to upload, getting the new
       activity-log-proxy-vpn card onto the dashboard. */
    /* `recompute-songbook-songcount` no longer exposed via the dashboard
       (#818) — the SongCount Triggers migration above includes its own
       initial recompute. The CLI script stays on disk for emergency
       manual runs. */
];

/* =========================================================================
 * MIGRATION REGISTRY (top-level — must be visible to BOTH the action-handler
 * block and the page-render block below)
 *
 * Single source of truth lives in includes/migration-registry.php as a
 * structured `$MIGRATIONS` array, one entry per migration with `script`,
 * `card`, and `probe` co-located. The four legacy arrays —
 * $scriptMap (migration entries), $migrationOrder, $migrationCards,
 * $migrationProbes — are derived from it below so existing call sites
 * (the action handler, the bulk runner, the per-migration card grid)
 * keep working unchanged.
 *
 * Previously these arrays were defined inline here in four separate
 * blocks; adding a new migration meant updating four places and getting
 * exactly one of them subtly wrong was a silent regression
 * (#claude/fix-pending-migrations-Vj4SQ). The structured source +
 * derivation pattern eliminates that drift class at the source; CI
 * guards in tests/php/test-migration-registry.php and
 * tests/php/test-schema-coverage.php enforce the discipline at PR time.
 * ========================================================================= */

/* =========================================================================
 * PROBE HELPERS (#820)
 *
 * Used by the closures inside includes/migration-registry.php — defined
 * here so the helpers exist before the registry require'd below evaluates
 * its closures. (Closures themselves are lazily-evaluated, but PHP needs
 * the function names resolvable at probe-call time.)
 * ========================================================================= */

/** Returns true when an INFORMATION_SCHEMA TABLES row exists for $table. */
function _migProbe_tableExists(\mysqli $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

/** Returns true when an INFORMATION_SCHEMA COLUMNS row exists for $table.$column. */
function _migProbe_columnExists(\mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

/** Returns true when $table.$column is currently nullable per INFORMATION_SCHEMA. */
function _migProbe_columnIsNullable(\mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row && strtoupper((string)$row['IS_NULLABLE']) === 'YES';
}

/** Returns true when an INFORMATION_SCHEMA TRIGGERS row exists for $trigger. */
function _migProbe_triggerExists(\mysqli $db, string $trigger): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TRIGGERS
          WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ? LIMIT 1'
    );
    $stmt->bind_param('s', $trigger);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

/* Installer-only entries — these are NOT migrations (no card, no probe).
   Migration entries are appended below from the structured registry. */
$scriptMap = [
    'install'     => 'install.php',
    'migrate'     => 'migrate-json.php',
    'users'       => 'migrate-users.php',
    'cleanup'     => 'cleanup.php',
    'backup'      => 'backup.php',
    'restore'     => 'restore.php',
    'drop-legacy' => 'drop-legacy-tables.php',
];

/* Load the structured migration registry — single source of truth for
   each migration's script + card + probe. Derive the four legacy arrays
   from it so the action handler, bulk runner, card grid, and pending
   partition all continue to work unchanged. */
$MIGRATIONS = require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'migration-registry.php';

$migrationOrder  = array_keys($MIGRATIONS);
$migrationCards  = [];
$migrationProbes = [];
foreach ($MIGRATIONS as $_slug => $_entry) {
    $scriptMap[$_slug]       = $_entry['script'];
    $migrationCards[$_slug]  = $_entry['card'];
    $migrationProbes[$_slug] = $_entry['probe'];
}
unset($_slug, $_entry);
/* Captured during bulk-run so the failure can be surfaced as a visible
   banner ABOVE the (potentially long, scrollable) output panel. (#720) */
$bulkFirstFailStep    = null;
$bulkFirstFailMessage = '';
$bulkFirstFailFile    = '';
$bulkFirstFailLine    = 0;
$bulkTotalRan         = 0;
$bulkTotalFailed      = 0;

/* Page-render sentinel + emergency chrome closer (#817).
   "Apply all" was reported as rendering "in its own raw HTML page,
   not within the same UI structure" — the cause is a child migration
   that calls `exit()` mid-bulk. exit() bypasses the try/catch in the
   bulk loop AND the outer page render below, so the admin chrome
   never closes and the user sees an unwrapped log.
   Set the flag to true at the very end of the page (just before the
   trailing exit) and a shutdown handler emits the chrome's closing
   tags if we never reached it. The migration scripts themselves are
   being audited to use `return` / `throw` instead of exit(); this is
   the defence-in-depth for any straggler. */
$pageRenderedCleanly = false;
register_shutdown_function(function () use (&$pageRenderedCleanly): void {
    if ($pageRenderedCleanly) return;

    /* #868 — pull the global $app into closure scope so admin-footer.php's
       version / copyright lines render with their real values. Without
       this, admin-footer's `$app['Application']['Version']['Number'] ?? ''`
       returns '' because require_once on infoAppVer.php is a no-op
       (already loaded at top-level) and the closure doesn't inherit
       global state by default. Symptom: footer reads "| v | Terms |
       Privacy" with no version + no copyright string. */
    global $app;

    /* Drain whatever's still buffered so it precedes the chrome tags
       we're about to emit. */
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    echo "\n<!-- emergency chrome closure (#817 / #868) — response "
       . "truncated before the page completed. -->\n";

    /* #868 — flip the status badge from "Running…" to "Interrupted"
       so the curator sees a clear failed-state rather than a frozen
       running indicator. Wrapped in `if (badge)` because the non-
       action dashboard page doesn't render the badge at all and
       this script runs in both contexts. */
    echo '<script>'
       . '(function(){'
       . 'var b=document.getElementById("action-status-badge");'
       . 'if(!b)return;'
       . 'b.textContent="Interrupted";'
       . 'b.className="badge bg-warning text-dark";'
       . 'b.title="Response was truncated before the run completed — '
       . 'likely a server-level timeout (FPM request_terminate_timeout '
       . 'or proxy timeout). Retry, or run individual migrations one '
       . 'at a time.";'
       . '})();'
       . '</script>' . "\n";

    /* Close the layers the action path opens (in order):
         <div class="output-log">    ← migration log container
         <div class="container-admin"> ← page container
       Then emit the admin footer + nav-layout closers so the page
       has its standard chrome, not a bare <body>.
       Five closes is one too many on the non-action page (which
       doesn't open the output-log); browsers tolerate stray closing
       tags so emit them all and accept the harmless extra. */
    echo "</div><!-- /.output-log (if open) -->\n";
    echo "</div><!-- /.container-admin -->\n";

    /* #868 — best-effort include of the admin footer so the page
       has its normal chrome at the bottom (version stamp, build
       metadata, copyright line, the close of <main> + admin-layout
       when admin-nav.php opened them — gated on $GLOBALS
       ['_adminLayoutOpen'] which we DON'T touch here, so the
       footer's guarded-close logic still applies correctly).

       admin-footer.php reads `$app['Application']['Version']…` —
       require_once on infoAppVer.php is a no-op when it was already
       loaded at top-level, and admin-footer would then see $app
       undefined in this closure's local scope. So if the global
       $app is empty (early-fatal path where the action handler
       never reached the line that loads infoAppVer), pull it in
       directly here so the footer renders with real values rather
       than "| v |  Terms |  Privacy". Required: the include's
       `$app = [];` populates the closure's local scope, which
       admin-footer.php inherits when require'd below.

       Wrapped in @require + try/catch so a load failure during
       shutdown doesn't compound into a fatal — better to ship a
       footer-less page than a 500. admin-footer.php does NOT
       emit </body></html> itself; we close the doc here. */
    try {
        if (!isset($app) || empty($app)) {
            $_appVerPath = dirname(__DIR__) . DIRECTORY_SEPARATOR
                         . 'includes' . DIRECTORY_SEPARATOR
                         . 'infoAppVer.php';
            if (is_file($_appVerPath)) {
                @require $_appVerPath;
            }
        }
        $_footerName = __DIR__ . DIRECTORY_SEPARATOR
                     . 'includes' . DIRECTORY_SEPARATOR
                     . 'admin-footer.php';
        if (is_file($_footerName)) {
            @require $_footerName;
        }
    } catch (\Throwable $_e) {
        /* Footer load failed — fall through to bare-body close. */
    }
    echo "</body>\n</html>\n";
});

if ($action !== '') {
    /* Signal to the included scripts that they're being run from the
     * dashboard, so they skip `header('Content-Type: text/plain')` which
     * would otherwise leak to the outer response and cause iOS Safari/Edge
     * to render this page as raw plaintext (the child's <br> output is
     * still fine — only the header propagates via buffered output). */
    define('IHYMNS_SETUP_DASHBOARD', true);

    /* #862 — lift the execution-time cap for any setup-database action.
       Shared hosts default to max_execution_time = 30s, which the bulk
       Apply-All run blows through with ~30 migrations + real backfills.
       Without this, PHP terminates mid-loop, the closing chrome never
       reaches the browser, and the JS that flips #action-status-badge
       from "Running…" to "Complete" / "Error" never runs — the badge
       stays stuck on the initial state.
       ignore_user_abort makes the run survive a curator closing the
       tab mid-stream — better to land all migrations than half. */
    @set_time_limit(0);
    @ini_set('max_execution_time', '0');
    @ignore_user_abort(true);

    /* #869 — text-format fast-path. The dashboard's per-migration
       AJAX runner (js/modules/setup-bulk-runner.js) calls into this
       same action endpoint one migration at a time. It doesn't want
       the full HTML chrome — just the migration's captured stdout
       framed by a small text header so the runner can distinguish
       success / error and surface per-migration progress live on
       the dashboard. Each request is short (one migration only),
       so the bulk run never sits in a single long PHP request that
       could hit a server-level timeout (FPM request_terminate_timeout,
       proxy timeout, CDN limit) — which is the real root cause of
       the symptom that #862 / #863 / #868 only papered over. */
    $requestedFormat = (string)($_GET['format'] ?? '');
    if ($requestedFormat === 'text' && $action !== 'apply-all-migrations') {
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');

        $scriptName = $scriptMap[$action] ?? null;
        if ($scriptName === null) {
            http_response_code(400);
            echo "STATUS: error\n";
            echo "ACTION: {$action}\n";
            echo "ERROR: Unknown action.\n";
            $pageRenderedCleanly = true;
            exit;
        }
        if (!$hasCredentials && $action !== 'install') {
            http_response_code(412);
            echo "STATUS: error\n";
            echo "ACTION: {$action}\n";
            echo "ERROR: Database credentials not configured.\n";
            $pageRenderedCleanly = true;
            exit;
        }
        $scriptDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
                   . '.sql' . DIRECTORY_SEPARATOR;
        $scriptPath = $scriptDir . $scriptName;
        if (!file_exists($scriptPath)) {
            http_response_code(404);
            echo "STATUS: error\n";
            echo "ACTION: {$action}\n";
            echo "ERROR: Script not found: {$scriptName}\n";
            $pageRenderedCleanly = true;
            exit;
        }

        /* Capture the migration's output (echo / _migXxx_out / etc.)
           so we can frame it with a status header. The migration may
           emit <br> tags expecting an HTML context — strip them so
           the AJAX runner gets readable plaintext. */
        ob_start();
        $migOk = true;
        $migErr = '';
        $migErrFile = '';
        $migErrLine = 0;
        $migStart = microtime(true);
        try {
            require $scriptPath;
        } catch (\Throwable $e) {
            $migOk = false;
            $migErr = $e->getMessage();
            $migErrFile = (string)$e->getFile();
            $migErrLine = (int)$e->getLine();
        }
        $migOutput = (string)ob_get_clean();
        /* Migration scripts use `_migXxx_out()` helpers that emit
           `<br>\n` for the dashboard context; the AJAX runner shows
           output as preformatted text so we replace those with a
           plain newline. */
        $migOutput = str_replace(['<br>', '<br/>', '<br />'], '', $migOutput);
        $elapsed = (int)round((microtime(true) - $migStart) * 1000);

        if (!$migOk) {
            http_response_code(500);
        }
        echo "STATUS: " . ($migOk ? 'ok' : 'error') . "\n";
        echo "ACTION: {$action}\n";
        echo "SCRIPT: {$scriptName}\n";
        echo "ELAPSED_MS: {$elapsed}\n";
        if (!$migOk) {
            echo "ERROR: {$migErr}\n";
            if ($migErrFile !== '') {
                echo "ERROR_AT: " . basename($migErrFile) . ":{$migErrLine}\n";
            }
        }
        echo "---\n";
        echo $migOutput;

        $pageRenderedCleanly = true;
        exit;
    }

    /* #817 round 2 — render the page chrome BEFORE the bulk run, so:
       (a) Content-Type: text/html is committed to the response before
           any child script's `header('Content-Type: text/plain')` could
           leak (header() is silently ignored once headers are sent — and
           we deliberately send headers + opening chrome here).
       (b) An exit() inside any included migration can no longer truncate
           the chrome — it's already on the wire. The shutdown handler
           emits the closing tags.
       The non-action path (the dashboard) keeps its own existing render
       block lower down. */
    header('Content-Type: text/html; charset=UTF-8');
    /* Lock the Content-Type so even if a child migration's earlier
       `header('Content-Type: text/plain')` had set it, the browser
       trusts ours and doesn't fall back to plaintext rendering on
       sniff. (#817 round 2 — was the suspected root cause of the
       "raw HTML page" symptom.) */
    header('X-Content-Type-Options: nosniff');
    /* Disable caching of the apply-all response so a curator never sees
       a stale "raw HTML" snapshot from a CDN / browser cache after the
       chrome-render fix lands. Each apply-all run is unique anyway. */
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    /* Drain any default output buffer so our manual flush() actually
       reaches the browser. PHP defaults to one ambient buffer; we
       want output to stream as soon as we echo. The first echo below
       triggers PHP to send the headers we just queued. */
    while (ob_get_level() > 0) {
        @ob_end_clean();   /* discard any buffered content from before
                              the action block, to be safe */
    }
    @ob_implicit_flush(true);

    /* Compute the user-friendly heading once for use in <title> + h4. */
    $headingTitle = $friendlyTitles[$action] ?? ucfirst($action);

    /* Render chrome opening — DOCTYPE through the open tag of the
       output panel. After this echo, the response is committed: every
       byte of HTML below this point is on the wire before the bulk
       runs. */
    ?>
<!DOCTYPE html>
<!-- IHYMNS_APPLY_ALL_CHROME_v3 — if you can see this comment in View Source,
     the chrome-first render is reaching the browser. (#817 round 2) -->
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($headingTitle) ?> — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <link rel="stylesheet" href="/css/app.css?v=<?= filemtime(dirname(__DIR__) . "/css/app.css") ?>">
    <link rel="stylesheet" href="/css/admin.css?v=<?= filemtime(dirname(__DIR__) . "/css/admin.css") ?>">
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>
<?php if (!$isInitialSetup): require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; endif; ?>

<div class="container-admin py-4">
    <h1 class="mb-1">
        Database Setup<?= entitlementLockChipHtml('run_db_install') ?>
    </h1>
    <p class="text-secondary mb-4">
        iHymns Admin &mdash; Installation, migration, and maintenance.
        <span class="badge bg-danger text-light ms-2" style="font-size: 0.7rem; font-weight: 600;">
            <i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Global Admin only
        </span>
    </p>
    <p><a href="?" class="btn btn-outline-secondary btn-sm">&larr; Back to Dashboard</a></p>
    <h4 class="mb-2 d-flex align-items-center gap-2">
        <span><?= htmlspecialchars($headingTitle) ?> &mdash; Output</span>
        <span id="action-status-badge" class="badge bg-secondary">Running…</span>
    </h4>
    <div class="output-log" id="action-output-log">
<?php
    /* Push the chrome to the browser NOW. After this point even a
       fatal in a child migration leaves the chrome visible (the
       shutdown handler appends `</div></main>...</body></html>`). */
    @ob_flush();
    @flush();

    /* Capture the bulk run's output so the per-step framing (▶ / ✓
       / ✗) can be displayed inside the already-open <div class="output-log">
       above, AND so the bulk-failure banner has the data it needs. The
       captured buffer is echoed inline after the run completes. */
    ob_start();

    $scriptDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.sql' . DIRECTORY_SEPARATOR . '';
    /* $scriptMap and $migrationOrder are now defined at the top of the
       file (above $migrationCards) so both the action handler here AND
       the page-render path below can see them. */

    /* "Apply all pending migrations" handler (#577). Iterates
       $migrationOrder, runs each script via require, and stops on
       the first thrown exception. Per-script output is interleaved
       with framing headers so the operator can see exactly which
       migration produced which line in the log. Each migration is
       already idempotent so re-running the bulk button after some
       have applied is safe — they no-op individually. */
    if ($action === 'apply-all-migrations') {
        if (!$hasCredentials) {
            echo "ERROR: Database credentials not configured.\n";
            echo "Configure appWeb/.auth/db_credentials.php first, or run Install.\n";
        } else {
            $totalRan = 0;
            $totalFailed = 0;
            $startedAt = microtime(true);
            $actionSuccess = true;
            /* First-failing-step is captured so we can surface it as a
               prominent banner ABOVE the output panel — the original
               implementation wrote the FAILED line into the panel where
               it could be missed if the panel's overflow scrolled past
               it. (#720) */
            $firstFailStep    = null;
            $firstFailMessage = '';
            $firstFailFile    = '';
            $firstFailLine    = 0;

            /* Catch fatal errors mid-bulk (PHP Fatal in an included
               migration script bypasses the try/catch). The shutdown
               handler captures the last error and surfaces it the
               same way as a caught Throwable. (#720) */
            register_shutdown_function(function () {
                $err = error_get_last();
                if (!$err) return;
                if (!in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                    return;
                }
                /* Best-effort flush so the operator at least sees the
                   fatal in the output panel even if the bulk loop
                   never reached its summary footer. */
                echo "\n\n══════════════════════════════════════════════════════\n";
                echo "✗ FATAL DURING BULK RUN: " . $err['message'] . "\n";
                echo "  File: " . basename($err['file']) . ":" . $err['line'] . "\n";
                echo "══════════════════════════════════════════════════════\n";
            });

            foreach ($migrationOrder as $migAction) {
                /* #862 — reset the execution-time alarm before EACH
                   migration in case the host enforces a per-script
                   limit that survives the require() boundary. Cheap
                   to call repeatedly; harmless when set_time_limit
                   is disabled by safe_mode / disable_functions. */
                @set_time_limit(0);

                $migScript = $scriptMap[$migAction] ?? null;
                if ($migScript === null) {
                    echo "  ✗ Unknown migration key: {$migAction} — skipped.\n\n";
                    continue;
                }
                $migPath = $scriptDir . $migScript;
                if (!file_exists($migPath)) {
                    echo "  ✗ Script not found: {$migScript} — skipped.\n\n";
                    continue;
                }
                $migStart = microtime(true);
                echo "═══════════════════════════════════════════════════════\n";
                echo "▶ {$migAction}  ({$migScript})\n";
                echo "═══════════════════════════════════════════════════════\n";
                try {
                    require $migPath;
                    $totalRan++;
                    $elapsed = round((microtime(true) - $migStart) * 1000);
                    echo "\n  ✓ {$migAction} completed in {$elapsed} ms\n\n";
                } catch (\Throwable $e) {
                    $totalFailed++;
                    $actionSuccess = false;
                    if ($firstFailStep === null) {
                        $firstFailStep    = $migAction;
                        $firstFailMessage = $e->getMessage();
                        $firstFailFile    = basename((string)$e->getFile());
                        $firstFailLine    = (int)$e->getLine();
                    }
                    echo "\n  ✗ {$migAction} FAILED: " . htmlspecialchars($e->getMessage()) . "\n";
                    if ($e->getFile()) {
                        echo "    File: " . htmlspecialchars(basename($e->getFile())) . ":" . $e->getLine() . "\n";
                    }
                    echo "\n  Stopping the bulk run so you can resolve this before continuing.\n";
                    break;
                }
            }
            /* Promote captured failure data to the outer scope so the
               render below can surface a visible "Failed at" banner. */
            $bulkFirstFailStep    = $firstFailStep;
            $bulkFirstFailMessage = $firstFailMessage;
            $bulkFirstFailFile    = $firstFailFile;
            $bulkFirstFailLine    = $firstFailLine;
            $bulkTotalRan         = $totalRan;
            $bulkTotalFailed      = $totalFailed;

            $totalElapsed = round((microtime(true) - $startedAt) * 1000);
            echo "═══════════════════════════════════════════════════════\n";
            echo "Bulk run finished — {$totalRan} migration"
               . ($totalRan === 1 ? '' : 's') . " ran successfully";
            if ($totalFailed > 0) {
                echo ", {$totalFailed} failed";
            }
            echo " in {$totalElapsed} ms.\n";
        }
    } else {
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
    }

    $actionOutput = ob_get_clean();
    /* Echo the captured bulk output INSIDE the <div class="output-log">
       we opened above. Closing tags follow so the chrome wraps around
       it cleanly. */
    echo $actionOutput;
    ?>
    </div><!-- /.output-log -->

    <?php
        /* Update the running-badge to reflect the final state. The
           badge was rendered as "Running…" before the bulk started;
           swap it once the response can finalise. */
    ?>
    <script>
        (function () {
            var badge = document.getElementById('action-status-badge');
            if (!badge) return;
            badge.textContent = <?= $actionSuccess ? "'Complete'" : "'Error'" ?>;
            badge.className   = <?= $actionSuccess ? "'badge bg-success'" : "'badge bg-danger'" ?>;
        })();
    </script>

    <?php if ($action === 'apply-all-migrations' && $bulkFirstFailStep !== null): ?>
        <!-- Failure summary BELOW the panel (#817 round 2 — moved from
             above to below because chrome now opens before the panel
             does, and a banner above the panel would be rendered before
             the run completed and the failure data was available). -->
        <div class="alert alert-danger mt-3" role="alert">
            <h5 class="alert-heading mb-2">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                Bulk run failed at step:
                <code><?= htmlspecialchars((string)$bulkFirstFailStep) ?></code>
            </h5>
            <p class="mb-2">
                <strong><?= (int)$bulkTotalRan ?></strong>
                migration<?= $bulkTotalRan === 1 ? '' : 's' ?>
                completed before this step;
                <strong><?= (int)$bulkTotalFailed ?></strong>
                failed; remaining steps were not attempted.
            </p>
            <p class="mb-1">
                <strong>Cause:</strong>
                <code><?= htmlspecialchars((string)$bulkFirstFailMessage) ?></code>
            </p>
            <?php if ($bulkFirstFailFile !== ''): ?>
                <p class="mb-0 small text-muted">
                    At
                    <code><?= htmlspecialchars((string)$bulkFirstFailFile) ?>:<?= (int)$bulkFirstFailLine ?></code>
                    — full per-step output above.
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div><!-- /.container-admin -->
<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
<?php
    $pageRenderedCleanly = true;
    exit;
}

/* =========================================================================
 * DATABASE STATUS
 * ========================================================================= */

$dbStatus = null;
$dbTables = [];

/* Pending vs applied partition (#820). Populated by the probes inside
   the dbStatus block below; defaults to "everything pending" so a
   missing connection / probe failure shows the full grid (safe). */
$pendingActions = $migrationOrder;
$appliedActions = [];

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

        /* Run pending-probes against the live schema (#820). Uses the
           same connection we just opened — a single round trip per
           probe, all under 100 ms cumulatively on a typical install.
           Any throw / unknown probe falls into "pending" so the card
           still appears (over-show is safe; under-hide is not). */
        $pendingActions = [];
        $appliedActions = [];
        foreach ($migrationOrder as $_action) {
            $_probe = $migrationProbes[$_action] ?? null;
            if ($_probe === null) {
                $pendingActions[] = $_action;
                continue;
            }
            try {
                if ($_probe($statusConn)) {
                    $pendingActions[] = $_action;
                } else {
                    $appliedActions[] = $_action;
                }
            } catch (\Throwable $_pe) {
                $pendingActions[] = $_action;
            }
        }

        $statusConn->close();
    } catch (\Throwable $e) {
        $dbStatus = 'error: ' . $e->getMessage();
        /* On connection failure, leave $pendingActions = $migrationOrder
           (set to default above) so the curator still sees the cards
           and can debug. */
    }
}

/* =========================================================================
 * RENDER PAGE
 * ========================================================================= */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <!-- Shared iHymns palette + admin styles -->
    <link rel="stylesheet" href="/css/app.css?v=<?= filemtime(dirname(__DIR__) . "/css/app.css") ?>">
    <link rel="stylesheet" href="/css/admin.css?v=<?= filemtime(dirname(__DIR__) . "/css/admin.css") ?>">
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

<?php if (!$isInitialSetup): ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>
<?php endif; ?>

<div class="container-admin py-4">

    <h1 class="mb-1">
        Database Setup<?= entitlementLockChipHtml('run_db_install') ?>
    </h1>
    <p class="text-secondary mb-4">
        iHymns Admin &mdash; Installation, migration, and maintenance.
        <span class="badge bg-danger text-light ms-2" style="font-size: 0.7rem; font-weight: 600;">
            <i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Global Admin only
        </span>
    </p>

    <?php if ($credSuccess !== ''): ?>
        <div class="alert alert-success"><?= htmlspecialchars($credSuccess) ?></div>
    <?php endif; ?>

    <?php if ($credError !== ''): ?>
        <div class="alert alert-danger"><strong>Error:</strong> <?= htmlspecialchars($credError) ?></div>
    <?php endif; ?>

    <?php
        $showCredForm = !$hasCredentials
            || (isset($_GET['reconfigure']) && $_GET['reconfigure'] === '1')
            || $credError !== '';
    ?>

    <?php if ($action === '' && $showCredForm): ?>
        <!-- ============================================================
             DB CREDENTIALS FORM (#272)
             ============================================================ -->
        <div class="card bg-dark border-secondary mb-4">
            <div class="card-body">
                <h5 class="card-title mb-2">
                    <?= $hasCredentials ? 'Reconfigure Database Credentials' : 'Configure Database Credentials' ?>
                </h5>
                <p class="text-secondary small mb-3">
                    <?php if ($hasCredentials): ?>
                        Update the MySQL credentials used by iHymns. The connection is tested
                        before the file is overwritten.
                    <?php else: ?>
                        Enter your MySQL connection details. The connection is tested and, if
                        successful, written to <code>appWeb/.auth/db_credentials.php</code>
                        (permissions <code>0600</code>, outside the web root).
                    <?php endif; ?>
                </p>
                <form method="post" action="" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="action" value="save-credentials">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label small">MySQL Host</label>
                            <input type="text" name="host" class="form-control form-control-sm"
                                   required value="<?= htmlspecialchars($credFormValues['host']) ?>"
                                   placeholder="127.0.0.1 or mysql.example.com">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Port</label>
                            <input type="number" name="port" class="form-control form-control-sm"
                                   min="1" max="65535" required
                                   value="<?= htmlspecialchars($credFormValues['port']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Database Name</label>
                            <input type="text" name="name" class="form-control form-control-sm"
                                   required value="<?= htmlspecialchars($credFormValues['name']) ?>"
                                   placeholder="ihymns">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Username</label>
                            <input type="text" name="user" class="form-control form-control-sm"
                                   required value="<?= htmlspecialchars($credFormValues['user']) ?>"
                                   placeholder="ihymns_user">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Password</label>
                            <input type="password" name="pass" class="form-control form-control-sm"
                                   autocomplete="new-password"
                                   placeholder="<?= $hasCredentials ? '(leave blank to keep existing)' : '' ?>">
                            <?php if ($hasCredentials): ?>
                                <div class="form-text small text-secondary">
                                    Leave blank and we'll preserve the existing password only if the test connection succeeds without one.
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Table Prefix <span class="text-secondary">(optional)</span></label>
                            <input type="text" name="prefix" class="form-control form-control-sm"
                                   value="<?= htmlspecialchars($credFormValues['prefix']) ?>"
                                   placeholder="e.g. ih_">
                        </div>
                    </div>
                    <div class="mt-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            Test &amp; Save Credentials
                        </button>
                        <?php if ($hasCredentials): ?>
                            <a href="?" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    <?php elseif (!$hasCredentials): ?>
        <div class="alert alert-danger">
            <strong>Credentials not found.</strong>
            Configure database credentials to continue.
        </div>
    <?php endif; ?>

    <?php
        /* Action path note (#817 round 2): when $action !== '' the page
           is fully self-rendered higher up — DOCTYPE through
           admin-footer.php — and `exit`s before reaching this block.
           So everything below this point only ever runs on the
           dashboard view. The legacy "if action then run, else show
           dashboard" wrapper around the dashboard cards has been
           removed accordingly.

           [CI note: this comment used to spell out the legacy
           wrapper using literal PHP open tags inside backticks, which
           tripped Step 5a of CI Lint & Validate — the guard added
           after PR #536 to catch the embedded-tag-execution bug.
           Re-worded prose-only to keep the guard a hard error.] */
    ?>
        <!-- ============================================================
             ONE-STEP MIGRATIONS RUNNER (#577)
             Runs every migration script in dependency order. Each
             script is idempotent so re-running is safe; the runner
             stops on the first hard failure and reports which one.
             Sits ABOVE the per-step cards so admins reach for this
             first, only dropping into individual cards when they
             need to debug a specific step.
             ============================================================ -->
        <?php
            /* Pending count for the Apply-all banner (#820). When the
               schema is fully up-to-date we surface that explicitly so
               an operator scanning the page knows there's nothing to do
               without scrolling the card grid. Cards from $migrationOrder
               without a $migrationCards entry don't count — they're
               skip-only entries (or not yet defined). */
            $_pendingCardCount = count(array_filter(
                $pendingActions,
                static fn(string $slug): bool => isset($migrationCards[$slug])
            ));
            $_alertVariant = $_pendingCardCount > 0 ? 'alert-primary' : 'alert-success';
        ?>
        <div class="alert <?= $_alertVariant ?> border-0 mb-4 d-flex flex-column flex-md-row gap-3 align-items-md-center justify-content-between">
            <div>
                <h5 class="mb-1">
                    <?php if ($_pendingCardCount > 0): ?>
                        <i class="bi bi-collection-play me-2" aria-hidden="true"></i>
                        Apply all pending migrations
                        <span class="badge bg-warning text-dark ms-2" style="font-size: 0.75rem;">
                            <?= $_pendingCardCount ?> pending
                        </span>
                    <?php else: ?>
                        <i class="bi bi-check2-circle me-2" aria-hidden="true"></i>
                        Schema fully up-to-date
                    <?php endif; ?>
                </h5>
                <p class="mb-0 small">
                    <?php if ($_pendingCardCount > 0): ?>
                        Runs every <code>migrate-*.php</code> in dependency order,
                        skipping ones already applied (each migration is idempotent).
                        Stops on the first hard failure with a clear pointer to the
                        offending step. Use this on a fresh install, after a deploy,
                        or whenever the schema-audit page (#518) shows drift.
                    <?php else: ?>
                        Every migration's pending-probe (#820) reports its work as
                        applied. The "Apply all" button still works (a bulk re-run
                        is a safe no-op); previously-applied cards are tucked into
                        the expander below for diagnostic re-runs.
                    <?php endif; ?>
                </p>
            </div>
            <?php
                /* #869 — pending-migration list serialised onto the
                   button so the per-migration AJAX runner can pick
                   it up without a second round-trip. Comma-separated
                   to avoid HTML-escaping JSON-with-quotes inside an
                   attribute. The legacy href stays in place as a
                   no-JS fallback (with the chrome / footer / badge
                   fixes from #862 / #863 / #868 / #870 / #871 to
                   handle the timeout edge case). */
                $bulkRunnerPending = array_values(array_filter(
                    $pendingActions,
                    static fn(string $slug): bool => isset($migrationCards[$slug])
                ));
            ?>
            <a href="?action=apply-all-migrations"
               class="btn btn-primary btn-lg flex-shrink-0 <?= $hasCredentials ? '' : 'disabled' ?>"
               data-bulk-runner-trigger
               data-pending-migrations="<?= htmlspecialchars(implode(',', $bulkRunnerPending), ENT_QUOTES, 'UTF-8') ?>">
                <i class="bi bi-play-fill me-1" aria-hidden="true"></i>
                Apply all
            </a>
        </div>

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

            <!-- ============================================================
                 PER-MIGRATION CARDS (#816)

                 Data-driven render: cards iterate $migrationOrder so the
                 grid matches the deployment / dependency order the
                 "Apply all" runner uses. Each card identifies itself by
                 issue number — the legacy alphabetic-prefix scheme
                 (3a, 3b, … 3z, 3y2) was non-monotonic and rotted on
                 every addition.

                 #820 — pending vs applied partition. $pendingActions
                 (cards whose work the schema probes detect as not yet
                 applied) render normally above the fold;
                 $appliedActions roll into a <details> expander below
                 so a fully-up-to-date database shows just the few
                 cards an admin needs to act on, not all 25.

                 To add a new migration card, add an entry to
                 $migrationCards above, append its action key to
                 $migrationOrder, and add a probe to $migrationProbes
                 so the partition can detect it.
                 ============================================================ -->
            <?php
                /* Render helper — single card markup reused for both
                   the pending grid and the inside-expander grid. */
                $_renderCard = static function (string $migAction, array $card, bool $hasCreds): void {
                    ?>
                    <div class="col-md-6">
                        <div class="card bg-dark border-secondary h-100">
                            <div class="card-body">
                                <h5 class="card-title"><?= $card['title'] ?></h5>
                                <p class="card-text text-secondary small"><?= $card['body'] ?></p>
                                <a href="?action=<?= htmlspecialchars($migAction) ?>"
                                   class="btn btn-info btn-action <?= $hasCreds ? '' : 'disabled' ?>">
                                    <?= htmlspecialchars($card['button']) ?>
                                </a>
                                <?php if (!empty($card['extra_html'])): ?>
                                    <?= $card['extra_html'] ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php
                };
            ?>

            <?php foreach ($pendingActions as $_migAction): ?>
                <?php
                    $_card = $migrationCards[$_migAction] ?? null;
                    if (!$_card) continue;     /* slug in $migrationOrder but no card body */
                    $_renderCard($_migAction, $_card, $hasCredentials);
                ?>
            <?php endforeach; ?>

            <?php
                /* Filter applied list to slugs that actually have card
                   bodies (some $migrationOrder entries — like the
                   removed recompute step — have no card). The expander
                   should only count rows it can actually render. */
                $_appliedRenderable = array_values(array_filter(
                    $appliedActions,
                    static fn(string $slug): bool => isset($migrationCards[$slug])
                ));
            ?>
            <?php if (!empty($_appliedRenderable)): ?>
                <div class="col-12">
                    <details class="card bg-dark border-secondary applied-migrations-details">
                        <summary class="card-header d-flex align-items-center gap-2 text-secondary small" style="cursor: pointer;">
                            <i class="bi bi-check2-circle text-success" aria-hidden="true"></i>
                            <span>
                                <strong><?= count($_appliedRenderable) ?></strong>
                                migration<?= count($_appliedRenderable) === 1 ? '' : 's' ?>
                                already applied — click to show
                            </span>
                            <span class="ms-auto small text-muted">
                                Re-running an applied migration is a safe no-op.
                            </span>
                        </summary>
                        <div class="card-body">
                            <div class="row g-3">
                                <?php foreach ($_appliedRenderable as $_appliedAction): ?>
                                    <?php $_renderCard($_appliedAction, $migrationCards[$_appliedAction], $hasCredentials); ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </details>
                </div>
            <?php endif; ?>

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
            <?php
                /* List available backups for restore (#405). */
                $backupFiles = [];
                $backupDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data_share' . DIRECTORY_SEPARATOR . 'backups';
                if (is_dir($backupDir)) {
                    foreach (scandir($backupDir) ?: [] as $f) {
                        if (preg_match('/^ihymns-backup-[0-9-]+\.sql(?:\.gz)?$/', $f)) {
                            $backupFiles[] = $f;
                        }
                    }
                    rsort($backupFiles);
                }

                /* Handle an admin-supplied upload (#405). Accepts .sql + .sql.gz
                   files matching the backup naming pattern, drops them into
                   the server's backups directory, and logs the upload. */
                $uploadMsg = '';
                if ($_SERVER['REQUEST_METHOD'] === 'POST'
                    && ($_POST['action'] ?? '') === 'upload-backup'
                    && !empty($_FILES['backup']['name'])) {
                    $f = $_FILES['backup'];
                    $safeName = basename((string)$f['name']);
                    if ($f['error'] !== UPLOAD_ERR_OK) {
                        $uploadMsg = 'Upload failed (error ' . (int)$f['error'] . ').';
                    } elseif (!preg_match('/^ihymns-backup-[0-9-]+\.sql(?:\.gz)?$/', $safeName)) {
                        $uploadMsg = 'Filename must match ihymns-backup-YYYYMMDD-HHMMSS.sql(.gz).';
                    } elseif ((int)$f['size'] > 256 * 1024 * 1024) {
                        $uploadMsg = 'Upload rejected: file exceeds 256 MB.';
                    } else {
                        if (!is_dir($backupDir)) { @mkdir($backupDir, 0755, true); }
                        $dest = $backupDir . DIRECTORY_SEPARATOR . $safeName;
                        if (move_uploaded_file($f['tmp_name'], $dest)) {
                            @chmod($dest, 0640);
                            $uploadMsg = 'Uploaded: ' . $safeName . ' — pick it from the list below to restore.';
                            /* Audit-log entry (#405). Silent no-op if the table is
                               absent or the activity log helper isn't available. */
                            try {
                                $auditDb = getDbMysqli();
                                /* Column was previously named ActionType which
                                   never existed on the schema (#535) — the
                                   real column is `Action`, and we now also
                                   pass EntityType + EntityId so the row
                                   shows up correctly in the activity-log
                                   viewer. Details is JSON-typed so we encode
                                   the metadata structurally rather than as
                                   a free-form string. */
                                $auditUser = $currentUser['username'] ?? 'unknown';
                                $auditDetails = json_encode([
                                    'filename'   => $safeName,
                                    'size_bytes' => (int)$f['size'],
                                    'uploaded_by' => $auditUser,
                                ], JSON_UNESCAPED_SLASHES);
                                $stmt = $auditDb->prepare(
                                    'INSERT INTO tblActivityLog
                                        (UserId, Action, EntityType, EntityId, Details, IpAddress)
                                     VALUES (?, ?, ?, ?, ?, ?)'
                                );
                                if ($stmt) {
                                    $uid    = isset($currentUser['id']) ? (int)$currentUser['id'] : null;
                                    $action = 'backup.upload';
                                    $entityType = 'backup';
                                    $entityId   = $safeName;
                                    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                                    $stmt->bind_param('isssss', $uid, $action, $entityType, $entityId, $auditDetails, $ip);
                                    @$stmt->execute();
                                    $stmt->close();
                                }
                            } catch (\Throwable $_e) { /* best effort */ }
                            /* Refresh the file list so the uploaded file appears
                               in the dropdown immediately. */
                            $backupFiles = [];
                            foreach (scandir($backupDir) ?: [] as $bf) {
                                if (preg_match('/^ihymns-backup-[0-9-]+\.sql(?:\.gz)?$/', $bf)) {
                                    $backupFiles[] = $bf;
                                }
                            }
                            rsort($backupFiles);
                        } else {
                            $uploadMsg = 'Could not save the uploaded file.';
                        }
                    }
                }
            ?>
            <div class="col-md-6">
                <div class="card bg-dark border-danger h-100">
                    <div class="card-body">
                        <h5 class="card-title">6. Restore from Backup</h5>
                        <p class="card-text text-secondary small">
                            Replace every table in the database with data from a previous backup.
                            <strong>Destructive — consider running a fresh Backup first.</strong>
                        </p>
                        <?php if ($uploadMsg): ?>
                            <div class="alert alert-info py-2 small"><?= htmlspecialchars($uploadMsg) ?></div>
                        <?php endif; ?>

                        <?php if ($backupFiles): ?>
                            <form action="" method="get" class="d-flex gap-2 flex-wrap mb-2">
                                <input type="hidden" name="action" value="restore">
                                <select name="file" class="form-select form-select-sm" style="flex:1 1 200px">
                                    <?php foreach ($backupFiles as $f): ?>
                                        <option value="<?= htmlspecialchars($f) ?>"><?= htmlspecialchars($f) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-sm btn-outline-warning <?= $hasCredentials ? '' : 'disabled' ?>">Preview</button>
                                <button type="submit" name="preflight" value="1"
                                        class="btn btn-sm btn-outline-info <?= $hasCredentials ? '' : 'disabled' ?>"
                                        title="Parse the backup and show a summary without touching the database (#405)">
                                    Pre-flight
                                </button>
                                <button type="submit" name="confirm" value="1"
                                        class="btn btn-sm btn-danger <?= $hasCredentials ? '' : 'disabled' ?>"
                                        onclick="return prompt('Type RESTORE (all caps) to confirm replacing every table with the selected backup. A snapshot of current state is saved automatically before the restore runs.') === 'RESTORE'">
                                    Restore
                                </button>
                            </form>
                            <p class="text-muted small mb-0">
                                <i class="bi bi-info-circle me-1"></i>
                                Restore always takes a pre-restore snapshot first. Data INSERTs
                                are transactional — a failure rolls data back automatically.
                            </p>
                        <?php else: ?>
                            <p class="text-muted small mb-2">No backups found in <code>data_share/backups/</code>.</p>
                        <?php endif; ?>

                        <hr class="my-2">
                        <p class="text-muted small mb-2">Or upload a `.sql.gz` / `.sql` from your computer:</p>
                        <form action="" method="post" enctype="multipart/form-data" class="d-flex gap-2 flex-wrap">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="action" value="upload-backup">
                            <input type="file" name="backup" accept=".sql,.sql.gz,.gz" required
                                   class="form-control form-control-sm" style="flex:1 1 200px">
                            <button type="submit" class="btn btn-sm btn-outline-secondary <?= $hasCredentials ? '' : 'disabled' ?>">
                                Upload
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card bg-dark border-danger h-100">
                    <div class="card-body">
                        <h5 class="card-title">7. Drop Legacy Tables</h5>
                        <p class="card-text text-secondary small">
                            Drop any tables in the database that are <strong>not</strong>
                            part of the current <code>schema.sql</code>. Useful after
                            importing an existing MySQL database that still holds tables
                            from a previous iHymns incarnation.
                        </p>
                        <div class="d-flex gap-2 flex-wrap">
                            <a href="?action=drop-legacy" class="btn btn-outline-warning btn-sm <?= $hasCredentials ? '' : 'disabled' ?>">
                                Preview
                            </a>
                            <a href="?action=drop-legacy&amp;confirm=1" class="btn btn-danger btn-sm <?= $hasCredentials ? '' : 'disabled' ?>"
                               onclick="return confirm('This will DROP all tables in the database that are not defined in schema.sql.\n\nThis cannot be undone. Run a Backup first.\n\nContinue?')">
                                Drop Them
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
             DATABASE STATUS
             ============================================================ -->
        <h4 class="mb-3">Database Status</h4>

        <?php if ($dbStatus === 'connected'): ?>
            <div class="alert alert-success py-2 d-flex justify-content-between align-items-center">
                <span>
                    Connected to <strong><?= htmlspecialchars(DB_NAME) ?></strong>
                    @ <?= htmlspecialchars(DB_HOST) ?>:<?= DB_PORT ?>
                </span>
                <a href="?reconfigure=1" class="btn btn-sm btn-outline-light">Reconfigure</a>
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

    <hr class="my-4">
    <p class="text-secondary text-center small">
        iHymns Database Administration &middot; v0.10.0
        <?php
            /* Footer name (#650) — match the header's first-word
               derivation in admin-nav.php so 'Lance Manasse' reads as
               'Lance' on both surfaces. Falls back to username when
               DisplayName is empty. */
            if (!$isInitialSetup && isset($currentUser)) {
                $_displayName = (string)($currentUser['display_name'] ?? $currentUser['username'] ?? '');
                $_footerName  = preg_split('/\s+/', trim($_displayName), 2)[0] ?: $_displayName;
            }
        ?>
        <?php if (!$isInitialSetup && isset($currentUser)): ?>
            &middot; Logged in as <strong><?= htmlspecialchars($_footerName) ?></strong>
        <?php endif; ?>
    </p>
</div>

<script>
/* IANA + CLDR live refresh button (#738).
   Posts to /api?action=admin_refresh_iana_cldr, swaps the status
   line for a live progress / result message, and on success
   reloads the page so the operator can re-run the per-card
   import button to verify counts. Global-admin-only endpoint;
   the button is disabled when DB credentials aren't configured. */
(() => {
    const btn = document.querySelector('button[data-action="refresh-iana-cldr"]');
    const status = document.querySelector('[data-iana-refresh-status]');
    if (!btn || !status) return;

    btn.addEventListener('click', async () => {
        if (btn.classList.contains('disabled') || btn.disabled) return;
        if (!confirm(
            'Fetch the latest IANA Language Subtag Registry and CLDR English ' +
            'JSON from the live URLs? This will overwrite the bundled snapshots ' +
            'in appWeb/.sql/data/ and re-run the import migration.'
        )) return;

        btn.disabled = true;
        const original = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Refreshing…';
        status.textContent = 'Fetching IANA + CLDR snapshots from upstream and re-running the import…';
        status.classList.remove('text-danger', 'text-success');

        let token = null;
        try { token = localStorage.getItem('ihymns_auth_token'); } catch (_e) {}

        try {
            const resp = await fetch('/api?action=admin_refresh_iana_cldr', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(token ? { 'Authorization': 'Bearer ' + token } : {}),
                },
                body: '{}',
            });
            const j = await resp.json();
            if (!resp.ok) {
                status.classList.add('text-danger');
                status.textContent = 'Refresh failed: ' + (j.error || resp.status) +
                    (j.failed ? ' (failed: ' + j.failed.join(', ') + ')' : '');
                btn.disabled = false;
                btn.innerHTML = original;
                return;
            }
            status.classList.add('text-success');
            status.textContent = 'Refreshed: ' + (j.fetched || []).join(', ') +
                '. Migration re-applied. Reloading…';
            setTimeout(() => location.reload(), 1500);
        } catch (err) {
            status.classList.add('text-danger');
            status.textContent = 'Network error: ' + err.message;
            btn.disabled = false;
            btn.innerHTML = original;
        }
    });
})();
</script>

<!-- #869 — Per-migration AJAX bulk runner. Replaces the legacy
     full-page "Apply All" redirect with a sequential fetch loop on
     the dashboard, so no single request hits a server-level
     timeout. Falls through to the legacy <a href> on no-JS. -->
<?php
    $_bulkRunnerPath    = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'setup-bulk-runner.js';
    $_bulkRunnerVersion = is_file($_bulkRunnerPath) ? (string)filemtime($_bulkRunnerPath) : '1';
?>
<script type="module">
    import { bootSetupBulkRunner }
        from '/js/modules/setup-bulk-runner.js?v=<?= htmlspecialchars($_bulkRunnerVersion, ENT_QUOTES) ?>';
    bootSetupBulkRunner();
</script>

<?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
<?php
/* Prevent any included script's exit() from showing raw output after our page */
$pageRenderedCleanly = true;
exit;
