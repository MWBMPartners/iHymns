<?php

declare(strict_types=1);

/**
 * iHymns — Admin: SQL Diagnostics (#1004) — global_admin only
 *
 * A read-only SQL query console for the operator who owns the
 * deployment. Answers the "what does the DB actually look like right
 * now?" forensics questions the per-entity admin grids don't surface —
 * the kind that drove the 2026-05 alpha/main data-isolation hunt.
 *
 * This surface is permanently READ-ONLY. The guard is server-enforced
 * and layered (defence in depth), because the connection user
 * (getDbMysqli()) holds full app privileges:
 *
 *   1. Entitlement gate — `view_diagnostics` (default: global_admin).
 *   2. Statement allow-list — the (comment-stripped) statement MUST
 *      begin with SELECT / SHOW / EXPLAIN / DESCRIBE|DESC (a leading
 *      `(` for a parenthesised SELECT/UNION is allowed). This is a
 *      structural first-token check, NOT a keyword-substring scan, so
 *      it constrains the statement *type* with no false positives.
 *   3. Engine-enforced read-only — the statement runs inside
 *      START TRANSACTION READ ONLY, so the server itself rejects any
 *      DML/DDL write (ER_CANT_EXECUTE_IN_READ_ONLY_TRANSACTION). This
 *      is the real write guard and is false-positive-free (it doesn't
 *      care what words appear in a string literal), which is why the
 *      old broad keyword blocklist — which tripped on legitimate
 *      queries like `SHOW CREATE TABLE` or `WHERE Action='song.delete'`
 *      — was dropped.
 *   4. No stacked statements — any `;` beyond a single trailing one is
 *      rejected. Execution goes through mysqli::query(), which runs a
 *      single statement only (multi_query() is never used), so a
 *      smuggled second statement can't run even if the regex missed it.
 *   5. No executable comments — MySQL's conditional-comment form (the
 *      slash-star-bang marker), which the server EXECUTES unlike a
 *      plain inert comment, is rejected outright; this closes the
 *      comment-stripping bypass of the allow-list check.
 *   6. No file I/O — OUTFILE / DUMPFILE / LOAD_FILE are blocked
 *      textually (word-boundary). These are filesystem reads/writes,
 *      not transactional data changes, so the read-only transaction
 *      does NOT stop them — the textual block does.
 *   7. Sensitive-schema blocklist — mysql.* / performance_schema.* /
 *      sys.* are rejected (credentials, engine internals). NOTE:
 *      INFORMATION_SCHEMA stays allowed on purpose — schema
 *      introspection is the whole point of the console.
 *   8. Row cap — results are truncated at MAX_ROWS (1000) with a
 *      visible notice; we cap on the fetch side rather than rewriting
 *      the SQL (rewriting an arbitrary statement's LIMIT is fragile).
 *   9. Statement timeout — SET SESSION max_execution_time caps a
 *      runaway / SLEEP() SELECT so the console can't wedge a worker.
 *  10. CSRF — the run-query POST verifies the session token.
 *  11. Audit — every run (and every rejection) writes a
 *      `diagnostics.query` row to tblActivityLog (SQL text + row count
 *      + result/error). Result rows themselves are never logged.
 *
 * Note this surface does NOT escalate privilege: a global_admin can
 * already drop tables / restore backups via /manage/setup-database.
 * The read-only guard exists to prevent ACCIDENTAL writes and to keep
 * the surface from being an easy file-I/O primitive if a session is
 * hijacked (CSRF is covered by the token in layer 10).
 *
 * The page renders results inline on POST (no redirect), keeping the
 * submitted SQL in the textarea so the operator can tweak and re-run.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';

/* Auth gate — mirror /manage/configuration.php: authenticate, then
   require the entitlement (rather than hardcoding requireGlobalAdmin())
   so a future entitlement-map override via /manage/entitlements is
   honoured. The default map keeps `view_diagnostics` at global_admin. */
if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('view_diagnostics', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="en"><body><h1>403 — view_diagnostics required</h1></body></html>';
    exit;
}
$activePage = 'diagnostics';
$csrf       = csrfToken();

/* =========================================================================
 * READ-ONLY GUARD
 * ========================================================================= */

/** Hard cap on rows fetched back into the page. */
const DIAGNOSTICS_MAX_ROWS = 1000;
/** Hard cap on the SQL the form will accept (chars). */
const DIAGNOSTICS_MAX_SQL_LEN = 20000;
/** SELECT timeout (ms) applied via SET SESSION max_execution_time. */
const DIAGNOSTICS_TIMEOUT_MS = 8000;

/**
 * Strip SQL comments + collapse whitespace, lowercased, for static
 * analysis only. Comments are inert at execution time, so validating a
 * comment-stripped copy while running the original is safe: the two
 * are semantically identical. Stripping them stops a leading block
 * comment or a line comment from hiding the real first keyword from
 * the allow-list check below.
 *
 * Handles: block comments, double-dash line comments, hash comments.
 */
function diagnosticsNormaliseSql(string $sql): string
{
    /* Block comments (non-greedy, across newlines). */
    $s = preg_replace('#/\*.*?\*/#s', ' ', $sql) ?? $sql;
    /* `-- ` to end-of-line (MySQL requires the trailing space/control). */
    $s = preg_replace('/--[ \t\r\n].*$/m', ' ', $s) ?? $s;
    /* `#` to end-of-line. */
    $s = preg_replace('/#.*$/m', ' ', $s) ?? $s;
    /* Collapse whitespace + lowercase. */
    $s = strtolower(trim((string)preg_replace('/\s+/', ' ', $s)));
    return $s;
}

/**
 * Validate that $sql is a single read-only statement we're willing to
 * run. Returns null when OK, or a human-readable rejection reason.
 *
 * The rules are documented at the top of this file; this is the one
 * place that enforces them.
 */
function diagnosticsRejectReason(string $rawSql): ?string
{
    $trimmed = trim($rawSql);
    if ($trimmed === '') {
        return 'Enter a query to run.';
    }
    if (strlen($trimmed) > DIAGNOSTICS_MAX_SQL_LEN) {
        return 'Query too long (max ' . number_format(DIAGNOSTICS_MAX_SQL_LEN) . ' characters).';
    }

    /* Reject MySQL "executable comments" — the conditional-comment
       form whose marker is slash-star-bang (optionally version-gated,
       e.g. the 50000 form). MySQL EXECUTES the body of these, unlike a
       plain inert block comment. Our normaliser strips ALL block
       comments before the allow-list check, so an executable comment
       could otherwise smuggle SQL the static analysis never sees while
       the server happily runs it. There is no legitimate use in a
       diagnostics console, so reject the marker outright (the search
       string is a single-quoted literal, not a real comment). */
    if (stripos($trimmed, '/*!') !== false) {
        return 'MySQL executable comments are not allowed.';
    }

    /* Stacked-statement guard. Drop a single trailing `;` (a tidy
       habit), then reject if any semicolon remains anywhere. */
    $noTrailing = rtrim($trimmed);
    $noTrailing = preg_replace('/;\s*$/', '', $noTrailing) ?? $noTrailing;

    $norm = diagnosticsNormaliseSql($noTrailing);
    if ($norm === '') {
        /* Whole thing was comments. */
        return 'Enter a query to run (the input was only comments).';
    }
    if (str_contains($norm, ';')) {
        return 'Only a single statement is allowed — remove the “;”.';
    }

    /* Statement allow-list — first token of the comment-stripped SQL.
       A leading `(` is allowed so a parenthesised SELECT / UNION
       (e.g. `(SELECT …) UNION (SELECT …)`) still passes. This single
       structural check — not a keyword-substring scan — is what
       guarantees the statement *type* is read-only; it has no false
       positives because it only inspects the first token. */
    if (!preg_match('/^[(\s]*(select|show|explain|describe|desc)\b/', $norm)) {
        return 'Read-only console: only SELECT, SHOW, EXPLAIN and DESCRIBE statements are allowed.';
    }

    /* File read/write primitives. The engine-enforced READ ONLY
       transaction in the runner blocks every DML/DDL write, but it
       does NOT block `SELECT … INTO OUTFILE/DUMPFILE` (a filesystem
       write, not a data modification) nor `LOAD_FILE()` (reads an
       arbitrary server file the mysql user can see). The connection
       user may hold FILE, so block these textually. Word-boundary
       matched so they only trip on the actual constructs, not on a
       column/string that merely contains the letters. */
    foreach (['outfile', 'dumpfile', 'load_file'] as $kw) {
        if (preg_match('/\b' . $kw . '\b/', $norm)) {
            return 'File access (“' . strtoupper($kw) . '”) is not allowed on this console.';
        }
    }

    /* Sensitive-schema blocklist. INFORMATION_SCHEMA is intentionally
       NOT here — introspecting it is the console's main job. */
    foreach (['mysql', 'performance_schema', 'sys'] as $schema) {
        if (preg_match('/\b' . $schema . '\s*\./', $norm)) {
            return 'Access to the “' . $schema . '” schema is blocked.';
        }
    }

    return null;
}

/* =========================================================================
 * PRESETS — a few genuinely useful read-only starters. Filled into the
 * textarea client-side via data-attributes (no shared module concern;
 * this is just a textarea convenience).
 * ========================================================================= */
$DIAGNOSTICS_PRESETS = [
    'Tables + row estimates' =>
        "SELECT TABLE_NAME, TABLE_ROWS, ROUND(DATA_LENGTH/1024/1024, 1) AS data_mb\n"
        . "FROM INFORMATION_SCHEMA.TABLES\n"
        . "WHERE TABLE_SCHEMA = DATABASE()\n"
        . "ORDER BY DATA_LENGTH DESC;",
    /* @deleted-visible: diagnostics console (#1694) — canned queries report
       the PHYSICAL database (soft-deleted rows are still rows); admins reading
       this page are debugging storage, not the catalogue.
       @disabled-visible: same reasoning, one predicate over (#1765) — this
       preset counts every physical tblSongbooks/tblSongs row regardless of
       IsDisabled; it is a storage diagnostic, not a catalogue view. */
    'Song / songbook counts' =>
        "SELECT\n"
        . "  (SELECT COUNT(*) FROM tblSongs)     AS songs,\n"
        . "  (SELECT COUNT(*) FROM tblSongbooks) AS songbooks,\n"
        . "  (SELECT COUNT(*) FROM tblUsers)     AS users;",
    'Recent activity (last 50)' =>
        "SELECT CreatedAt, Action, Result, EntityType, EntityId, UserId\n"
        . "FROM tblActivityLog\n"
        . "ORDER BY CreatedAt DESC\n"
        . "LIMIT 50;",
    'Current DB + version' =>
        "SELECT DATABASE() AS db, VERSION() AS mysql_version, NOW() AS server_time;",

    /* ---------------------------------------------------------------------
     * Forensics presets (2026-09-06).
     *
     * ELI5: canned answers to "is this actually true on the real server?" —
     * the questions that cannot be settled by reading the code, because the
     * code only says what SHOULD have happened.
     *
     * WHY THESE FOUR EXIST. Several pieces of work stalled on facts nobody
     * could check from a laptop: how many songs really carry group markings
     * written as lyrics, how many songs a version-resolver bug actually
     * affected, and — most importantly — which of two possible shapes the
     * organisation-licences table is really in, given that its expiry column
     * decides whether copyrighted songs may be shown and the migration and
     * the schema file disagreed about its type. Every one of those is a
     * one-line SELECT away, and every one was previously guessed at.
     *
     * All four are counts and column shapes only: no lyric text, no names,
     * no keys. Safe to run on production and safe to paste into a ticket.
     * ------------------------------------------------------------------ */
    'Forensics — corpus + known-bug counts' =>
        "SELECT\n"
        . "  (SELECT COUNT(*) FROM tblSongs)                                  AS songs_total,\n"
        . "  (SELECT COUNT(*) FROM tblLyricLines)                             AS lyric_lines_total,\n"
        . "  (SELECT COUNT(DISTINCT ly.SongId) FROM tblLyricLines l\n"
        . "     JOIN tblLyrics ly ON ly.Id = l.LyricsId\n"
        . "    WHERE l.LineText REGEXP '^[[:space:]]*(WOMEN|MEN|ALL|LADIES|GENTS|GIRLS|BOYS|CHOIR|CONGREGATION|LEADER|CANTOR|SOLO|UNISON|SOPRANO|ALTO|TENOR|BASS|DUET|ECHO|RESPONSE|DESCANT)([[:space:].:]|$)')\n"
        . "                                                                   AS songs_with_group_markers,\n"
        . "  (SELECT COUNT(*) FROM tblLyricLines\n"
        . "    WHERE LOCATE(CONVERT(UNHEX('C2A0') USING utf8mb4), LineText) > 0\n"
        . "      AND LineText REGEXP '^[[:space:]]*(WOMEN|MEN|ALL|LADIES|BOYS|GIRLS)')\n"
        . "                                                                   AS nbsp_marker_lines,\n"
        . "  (SELECT COUNT(DISTINCT a.SongId) FROM tblLyrics a\n"
        . "     JOIN tblLyrics b ON b.SongId = a.SongId\n"
        . "    WHERE a.Source = 'ihymns' AND b.Source <> 'ihymns'\n"
        . "      AND b.Status = 'approved' AND b.IsPrimary = 1)               AS songs_hit_by_resolver_bug,\n"
        . "  (SELECT COUNT(*) FROM tblLyricLines WHERE MetaJson IS NOT NULL)  AS lines_with_unread_singer_data,\n"
        . "  (SELECT COUNT(*) FROM tblLyricLines WHERE Note IS NOT NULL AND Note <> '') AS lines_with_a_note;",

    'Forensics — group markings by songbook' =>
        "SELECT s.SongbookAbbr, COUNT(DISTINCT s.SongId) AS songs\n"
        . "FROM tblLyricLines l\n"
        . "JOIN tblLyrics ly ON ly.Id = l.LyricsId\n"
        . "JOIN tblSongs  s  ON s.SongId = ly.SongId\n"
        . "WHERE l.LineText REGEXP '^[[:space:]]*(WOMEN|MEN|ALL|LADIES|CHOIR|CONGREGATION|LEADER|CANTOR|SOLO|UNISON|SOPRANO|ALTO|TENOR|BASS)([[:space:].:]|$)'\n"
        . "GROUP BY s.SongbookAbbr\n"
        . "ORDER BY songs DESC;",

    'Forensics — licences table: which shape is live?' =>
        "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA\n"
        . "FROM INFORMATION_SCHEMA.COLUMNS\n"
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblOrganisationLicences'\n"
        . "ORDER BY ORDINAL_POSITION;",

    'Forensics — lyric + vocal tables: created, and in use?' =>
        "SELECT TABLE_NAME, TABLE_ROWS\n"
        . "FROM INFORMATION_SCHEMA.TABLES\n"
        . "WHERE TABLE_SCHEMA = DATABASE()\n"
        . "  AND (TABLE_NAME LIKE 'tblLyric%' OR TABLE_NAME LIKE 'tblVocal%')\n"
        . "ORDER BY TABLE_NAME;",
];

/* =========================================================================
 * RUN THE QUERY (POST)
 * ========================================================================= */
$submittedSql = '';
$queryError   = null;   /* human-readable rejection / DB error */
$columns      = [];     /* string[] column names */
$rows         = [];     /* array<int, array<int, ?string>> */
$rowCount     = 0;
$truncated    = false;
$elapsedMs    = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $submittedSql = (string)($_POST['sql'] ?? '');

    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        $queryError = 'CSRF token invalid — refresh the page and try again.';
    } else {
        $reason = diagnosticsRejectReason($submittedSql);
        if ($reason !== null) {
            $queryError = $reason;
            logActivity(
                'diagnostics.query',
                'diagnostics',
                '',
                ['sql' => substr($submittedSql, 0, 2000), 'rejected' => $reason],
                'failure'
            );
        } else {
            /* Run the (validated) statement. We send the original text,
               not the normalised copy, so the operator's formatting,
               casing and string literals are preserved. */
            $runSql    = preg_replace('/;\s*$/', '', trim($submittedSql)) ?? trim($submittedSql);
            $startedAt = microtime(true);
            $db        = getDbMysqli();
            $inTxn     = false;

            try {
                /* Cap SELECT wall-clock so SLEEP() / a cartesian join
                   can't wedge a php-fpm worker. Best-effort: pre-5.7.8
                   MySQL lacks the variable — ignore the failure. SET
                   SESSION only affects read-only SELECTs, so it can't
                   interfere with the logActivity() INSERT later. */
                try {
                    $db->query('SET SESSION max_execution_time = ' . (int)DIAGNOSTICS_TIMEOUT_MS);
                } catch (\Throwable $_ignore) {
                    /* older MySQL / MariaDB naming — non-fatal */
                }

                /* Engine-enforced read-only. START TRANSACTION READ ONLY
                   makes the server itself reject any DML/DDL with
                   ER_CANT_EXECUTE_IN_READ_ONLY_TRANSACTION — a guarantee
                   with zero false positives, unlike a keyword scan. It's
                   the real write guard; the textual allow-list is the
                   first line that also gives a friendly message. (File
                   I/O — OUTFILE / LOAD_FILE — isn't a transactional write
                   so it's blocked textually in diagnosticsRejectReason().)
                   Best-effort: a server too old to support it falls back
                   to the textual guard alone. */
                try {
                    $db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);
                    $inTxn = true;
                } catch (\Throwable $_ignore) {
                    /* pre-5.6 server — textual guard still applies */
                }

                /* mysqli_report is STRICT (db_mysql.php), so a bad
                   statement throws mysqli_sql_exception rather than
                   returning false. query() runs ONE statement only
                   (multi_query is never used), so a smuggled second
                   statement cannot execute even if the regex missed it. */
                $result = $db->query($runSql);

                if ($result instanceof mysqli_result) {
                    foreach ($result->fetch_fields() as $f) {
                        $columns[] = $f->name;
                    }
                    while (($row = $result->fetch_row()) !== null) {
                        if ($rowCount >= DIAGNOSTICS_MAX_ROWS) {
                            /* One more row exists beyond the cap. */
                            $truncated = true;
                            break;
                        }
                        $rows[] = $row;
                        $rowCount++;
                    }
                    $result->free();
                } else {
                    /* A statement with no result set (shouldn't happen
                       given the allow-list, but be explicit). */
                    $columns  = ['result'];
                    $rows[]   = ['(statement executed; no result set)'];
                    $rowCount = 1;
                }
            } catch (\Throwable $e) {
                $queryError = $e->getMessage();
            } finally {
                /* Close the read-only transaction BEFORE we log, so the
                   logActivity() INSERT below isn't itself rejected by
                   the read-only mode still being in effect. ROLLBACK is
                   right (we never intend to commit a read), and restores
                   autocommit on the shared singleton for the log write. */
                if ($inTxn) {
                    try { $db->rollback(); } catch (\Throwable $_ignore) {}
                }
            }

            $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);

            /* Audit AFTER the transaction is closed (see finally). */
            if ($queryError === null) {
                logActivity(
                    'diagnostics.query',
                    'diagnostics',
                    '',
                    [
                        'sql'        => substr($runSql, 0, 2000),
                        'rows'       => $rowCount,
                        'truncated'  => $truncated,
                        'elapsed_ms' => $elapsedMs,
                    ],
                    'success',
                    null,
                    $elapsedMs
                );
            } else {
                logActivity(
                    'diagnostics.query',
                    'diagnostics',
                    '',
                    ['sql' => substr($runSql, 0, 2000), 'error' => $queryError],
                    'error',
                    null,
                    $elapsedMs
                );
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Query Tool — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">

        <h1 class="h4 mb-3"><i aria-hidden="true" class="bi bi-terminal me-2"></i>Database Query Tool</h1>
        <p class="text-secondary small mb-3">
            Run read-only look-ups against the live database to investigate a
            problem — a way to see exactly what is stored right now. Nothing
            here can change or delete anything; results are capped at
            <?= number_format(DIAGNOSTICS_MAX_ROWS) ?> rows and every run is recorded in the
            <a href="/manage/activity-log">Activity Log</a>.
        </p>

        <div class="alert alert-warning d-flex align-items-start gap-2 py-2 small">
            <i class="bi bi-shield-exclamation mt-1" aria-hidden="true"></i>
            <div>
                This console runs against the <strong>production</strong> connection. It is
                read-only and write statements are blocked, but a heavy query can still load the
                server — prefer adding a <code>LIMIT</code> and a tight <code>WHERE</code>.
            </div>
        </div>

        <form method="post" action="/manage/diagnostics" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

            <!-- Presets — fill the textarea client-side. -->
            <div class="mb-2 d-flex flex-wrap gap-2">
                <?php foreach ($DIAGNOSTICS_PRESETS as $label => $sql): ?>
                    <button type="button"
                            class="btn btn-sm btn-outline-secondary js-diag-preset"
                            data-sql="<?= htmlspecialchars($sql, ENT_QUOTES, 'UTF-8') ?>">
                        <i class="bi bi-lightning-charge me-1" aria-hidden="true"></i><?= htmlspecialchars($label) ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="mb-2">
                <label for="diag-sql" class="form-label small fw-semibold">SQL query</label>
                <textarea id="diag-sql"
                          name="sql"
                          class="form-control font-monospace"
                          rows="6"
                          spellcheck="false"
                          maxlength="<?= (int)DIAGNOSTICS_MAX_SQL_LEN ?>"
                          placeholder="SELECT * FROM tblSongs LIMIT 100;"><?= htmlspecialchars($submittedSql) ?></textarea>
            </div>

            <div class="d-flex align-items-center gap-2 mb-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-play-fill me-1" aria-hidden="true"></i>Run query
                </button>
                <?php if ($elapsedMs !== null && $queryError === null): ?>
                    <span class="text-secondary small">
                        <?= (int)$rowCount ?> row<?= $rowCount === 1 ? '' : 's' ?>
                        in <?= (int)$elapsedMs ?> ms<?= $truncated ? ' (truncated)' : '' ?>
                    </span>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($queryError !== null): ?>
            <div class="alert alert-danger py-2">
                <i class="bi bi-exclamation-octagon me-1" aria-hidden="true"></i>
                <strong>Query rejected:</strong> <?= htmlspecialchars($queryError) ?>
            </div>
        <?php endif; ?>

        <?php if ($queryError === null && $elapsedMs !== null): ?>
            <?php if ($truncated): ?>
                <div class="alert alert-info py-2 small">
                    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                    Showing the first <?= number_format(DIAGNOSTICS_MAX_ROWS) ?> rows — add a
                    <code>LIMIT</code> to see a focused slice.
                </div>
            <?php endif; ?>

            <div class="card-admin p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0">
                        <thead>
                            <tr class="small text-muted">
                                <th scope="col" class="text-end" style="width: 3rem;">#</th>
                                <?php foreach ($columns as $col): ?>
                                    <th scope="col"><?= htmlspecialchars((string)$col) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td class="text-secondary" colspan="<?= count($columns) + 1 ?>">
                                        No rows returned.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rows as $i => $row): ?>
                                    <tr>
                                        <td class="text-end text-muted small"><?= $i + 1 ?></td>
                                        <?php foreach ($row as $cell): ?>
                                            <td class="font-monospace small">
                                                <?php if ($cell === null): ?>
                                                    <span class="text-muted fst-italic">NULL</span>
                                                <?php else: ?>
                                                    <?= htmlspecialchars((string)$cell) ?>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <script>
    /* Preset buttons → fill the textarea. Pure textarea convenience;
       no shared module owns this behaviour. */
    (function () {
        var ta = document.getElementById('diag-sql');
        if (!ta) return;
        document.querySelectorAll('.js-diag-preset').forEach(function (btn) {
            btn.addEventListener('click', function () {
                ta.value = btn.getAttribute('data-sql') || '';
                ta.focus();
            });
        });
    })();
    </script>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
