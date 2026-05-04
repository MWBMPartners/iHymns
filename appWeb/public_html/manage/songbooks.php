<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Songbooks
 *
 * CRUD surface for `tblSongbooks`. Gated by the `manage_songbooks`
 * entitlement. Safe-guards:
 *   - Abbreviation is the natural key on tblSongs.SongbookAbbr — renaming
 *     it is opt-in and cascades via an explicit "also rename song refs"
 *     checkbox.
 *   - Delete refuses if any song still references the abbreviation.
 *   - DisplayOrder is seeded by migrate-account-sync.php; the UI writes
 *     back whole-table updates so reordering is atomic.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'songbook-palette.php';
/* Shared validators (#719 PR 2a) — same rules used by the admin_songbook_*
   API endpoints in /api.php. Single source of truth so a tweak to the
   abbrev / colour / IETF-tag grammar lands on both surfaces in one go. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'songbook_validation.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_songbooks', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_songbooks required</h1></body></html>';
    exit;
}
$activePage = 'songbooks';

$error   = '';
$success = '';
/* #782 phase E — populated by the `family_manifest` POST action below.
   When non-null the page renders the plan table at the bottom of the
   admin surface so the curator can review before a confirmed re-submit. */
$manifestPreview = null;
$db      = getDbMysqli();

/* ---- GET ?action=pick_colour&abbr=XX (#772) -----------------------------
 * JSON helper for the edit-songbook modal's "Pick distinctive colour"
 * button. Calls pickAutoSongbookColour() to choose a hex that isn't
 * already in use by any other songbook (the picker reads tblSongbooks
 * itself to compute the avoid-set, so the result is guaranteed
 * distinctive across the live catalogue). Empty / missing abbr falls
 * back to a generic seed so a brand-new songbook in the create form
 * can still get a suggestion.
 *
 * Admin role required — same gate as the bulk auto-colour actions.
 * ----------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && ($_GET['action'] ?? '') === 'pick_colour'
) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    if (!in_array(($currentUser['role'] ?? ''), ['admin', 'global_admin'], true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Admin role required.']);
        exit;
    }
    $abbr = trim((string)($_GET['abbr'] ?? ''));
    if ($abbr === '') {
        /* Use a 6-char random seed so a fresh-songbook caller still
           gets a distinctive suggestion via the hash fallback path. */
        $abbr = substr(bin2hex(random_bytes(3)), 0, 6);
    }
    try {
        require_once __DIR__ . DIRECTORY_SEPARATOR
            . 'includes' . DIRECTORY_SEPARATOR
            . 'songbook-palette.php';
        $hex = pickAutoSongbookColour($db, $abbr);
        echo json_encode(['colour' => $hex]);
    } catch (\Throwable $e) {
        error_log('[manage songbooks pick_colour] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Could not pick a colour.']);
    }
    exit;
}

/* ---- GET ?action=script_search&q=… (#681 / renamed table in #738) ------
 * JSON typeahead for the IETF BCP 47 picker's Script field. Matches
 * substring (LIKE %q%) against tblLanguageScripts.Name OR
 * tblLanguageScripts.Code so a curator can search either by friendly
 * name ("Latin") or by ISO 15924 code ("Latn"). Empty query → empty
 * list; pre-migration deployments → empty list with a `note` rather
 * than a 500. Probes BOTH the new and legacy names so a deployment
 * mid-migration (rename pending) still serves suggestions.
 * ----------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && ($_GET['action'] ?? '') === 'script_search'
) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    $q     = trim((string)($_GET['q'] ?? ''));
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
    if ($q === '') {
        echo json_encode(['suggestions' => []]);
        exit;
    }

    $tableName = '';
    try {
        /* Prefer the renamed table (#738); fall back to the legacy
           name if a deployment hasn't applied the rename yet. */
        foreach (['tblLanguageScripts', 'tblScripts'] as $candidate) {
            $probe = $db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"
            );
            $probe->bind_param('s', $candidate);
            $probe->execute();
            $found = $probe->get_result()->fetch_row() !== null;
            $probe->close();
            if ($found) { $tableName = $candidate; break; }
        }
    } catch (\Throwable $e) {
        error_log('[script_search] probe failed: ' . $e->getMessage());
    }
    if ($tableName === '') {
        echo json_encode([
            'suggestions' => [],
            'note'        => 'tblLanguageScripts not yet created — run /manage/setup-database',
        ]);
        exit;
    }

    try {
        $like = '%' . $q . '%';
        /* Identifier built from the allowlisted probe above — never
           user input, so no SQL injection surface. */
        $stmt = $db->prepare(
            "SELECT Code AS code, Name AS name, NativeName AS nativeName
               FROM {$tableName}
              WHERE IsActive = 1
                AND (Name LIKE ? OR Code LIKE ?)
              ORDER BY Name ASC
              LIMIT ?"
        );
        $stmt->bind_param('ssi', $like, $like, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $suggestions = [];
        while ($row = $res->fetch_assoc()) {
            $suggestions[] = [
                'code'       => (string)$row['code'],
                'name'       => (string)$row['name'],
                'nativeName' => (string)$row['nativeName'],
            ];
        }
        $stmt->close();
        echo json_encode(['suggestions' => $suggestions], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        error_log('[script_search] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Search failed.']);
    }
    exit;
}

/* ---- GET ?action=region_search&q=… (#681) ------------------------------
 * Same shape as script_search, against tblRegions. Codes are
 * uppercase ISO 3166-1 alpha-2 (or 3-digit M.49 numeric area codes
 * for groupings like 419 = Latin America), so the typeahead matches
 * either Name or Code as the user types.
 * ----------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && ($_GET['action'] ?? '') === 'region_search'
) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    $q     = trim((string)($_GET['q'] ?? ''));
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
    if ($q === '') {
        echo json_encode(['suggestions' => []]);
        exit;
    }

    $hasTable = false;
    try {
        $probe = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblRegions' LIMIT 1"
        );
        $probe->execute();
        $hasTable = $probe->get_result()->fetch_row() !== null;
        $probe->close();
    } catch (\Throwable $e) {
        error_log('[region_search] probe failed: ' . $e->getMessage());
    }
    if (!$hasTable) {
        echo json_encode([
            'suggestions' => [],
            'note'        => 'tblRegions not yet created — run /manage/setup-database',
        ]);
        exit;
    }

    try {
        $like = '%' . $q . '%';
        $stmt = $db->prepare(
            'SELECT Code AS code, Name AS name
               FROM tblRegions
              WHERE IsActive = 1
                AND (Name LIKE ? OR Code LIKE ?)
              ORDER BY Name ASC
              LIMIT ?'
        );
        $stmt->bind_param('ssi', $like, $like, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $suggestions = [];
        while ($row = $res->fetch_assoc()) {
            $suggestions[] = [
                'code' => (string)$row['code'],
                'name' => (string)$row['name'],
            ];
        }
        $stmt->close();
        echo json_encode(['suggestions' => $suggestions], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        error_log('[region_search] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Search failed.']);
    }
    exit;
}

/* ---- GET ?action=affiliation_search&q=… (#670) -------------------------
 * JSON typeahead endpoint for the Songbook edit modal's Affiliation
 * field. Returns up to `limit` matching rows from
 * tblSongbookAffiliations, ranked by current usage in tblSongbooks
 * (most-cited first) so a curator's recent additions surface
 * straight away. Same auth gate as the page itself
 * (`manage_songbooks` entitlement); returns an empty list rather
 * than 4xx when the query is empty so the caller's onInput handler
 * stays trivial. Exits early so no page HTML follows.
 * ----------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && ($_GET['action'] ?? '') === 'affiliation_search'
) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    $q     = trim((string)($_GET['q'] ?? ''));
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));

    /* Empty query → empty list. We don't 400 because the typeahead
       calls this on every keystroke, including the first one before
       the user has typed anything. */
    if ($q === '') {
        echo json_encode(['suggestions' => []]);
        exit;
    }

    /* Probe whether the registry table exists yet. New deployments
       that haven't run migrate-songbook-affiliations.php should not
       see a 500 — return an empty list and log a server-side note so
       the migration prompt on /manage/setup-database is the cure. */
    $hasRegistry = false;
    try {
        $probe = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'tblSongbookAffiliations' LIMIT 1"
        );
        $probe->execute();
        $hasRegistry = $probe->get_result()->fetch_row() !== null;
        $probe->close();
    } catch (\Throwable $e) {
        error_log('[affiliation_search] probe failed: ' . $e->getMessage());
    }
    if (!$hasRegistry) {
        echo json_encode([
            'suggestions' => [],
            'note'        => 'tblSongbookAffiliations not yet created — run /manage/setup-database',
        ]);
        exit;
    }

    /* Substring match anywhere in the name (LIKE %q%). Real-world
       affiliation strings are short enough that ranking by current
       usage in tblSongbooks (a LEFT JOIN + COUNT) is fine; the
       registry will be small (low hundreds at most). */
    try {
        $like = '%' . $q . '%';
        $stmt = $db->prepare(
            'SELECT a.Name AS name,
                    (SELECT COUNT(*) FROM tblSongbooks b WHERE b.Affiliation = a.Name) AS songbookCount
               FROM tblSongbookAffiliations a
              WHERE a.Name LIKE ?
              ORDER BY songbookCount DESC, a.Name ASC
              LIMIT ?'
        );
        $stmt->bind_param('si', $like, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $suggestions = [];
        while ($row = $res->fetch_assoc()) {
            $suggestions[] = [
                'name'          => (string)$row['name'],
                'songbookCount' => (int)$row['songbookCount'],
            ];
        }
        $stmt->close();
        echo json_encode(['suggestions' => $suggestions], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        error_log('[affiliation_search] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Search failed.']);
    }
    exit;
}

/* ---- GET ?action=parent_search&q=…&exclude_id=… (#782 phase B) --------
 * JSON typeahead for the edit-modal's "Parent songbook" picker. Returns
 * matching rows from tblSongbooks ranked by usage (most-cited as a
 * parent first, then alphabetical) so the Christ in Song / Mission
 * Praise heads surface above one-off siblings.
 *
 * `exclude_id` is the row currently being edited. We omit it from the
 * results AND recursively omit every descendant of it — picking a
 * descendant as the new parent would create a cycle, and showing it
 * in the dropdown only to reject on save is a worse UX than just
 * not offering it. (Phase B "light" cycle detection still runs on the
 * server-side update path as a defence-in-depth — a curator hitting the
 * endpoint directly can't smuggle a cycle in.)
 *
 * Pre-migration safe: probes for the ParentSongbookId column and
 * returns an empty list with a `note` if the schema isn't there yet,
 * so the admin page renders cleanly on a deployment that hasn't run
 * migrate-parent-songbooks.php.
 * ----------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && ($_GET['action'] ?? '') === 'parent_search'
) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    $q         = trim((string)($_GET['q'] ?? ''));
    $limit     = max(1, min(50, (int)($_GET['limit'] ?? 20)));
    $excludeId = (int)($_GET['exclude_id'] ?? 0);

    $hasParentCol = false;
    try {
        $probe = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblSongbooks'
                AND COLUMN_NAME  = 'ParentSongbookId'
              LIMIT 1"
        );
        $probe->execute();
        $hasParentCol = $probe->get_result()->fetch_row() !== null;
        $probe->close();
    } catch (\Throwable $e) {
        error_log('[parent_search] probe failed: ' . $e->getMessage());
    }
    if (!$hasParentCol) {
        echo json_encode([
            'suggestions' => [],
            'note'        => 'tblSongbooks.ParentSongbookId not yet present — run /manage/setup-database',
        ]);
        exit;
    }

    /* Build the descendant-of-exclude set so the typeahead never
       offers a child as a candidate parent. BFS against the ParentFK,
       capped at 32 levels to bound the walk in pathological data
       (real catalogues never go more than ~3 deep). */
    $descendants = [];
    if ($excludeId > 0) {
        $descendants[$excludeId] = true;
        $frontier = [$excludeId];
        $depth    = 0;
        $stmt     = $db->prepare(
            'SELECT Id FROM tblSongbooks WHERE ParentSongbookId = ?'
        );
        while ($frontier && $depth < 32) {
            $next = [];
            foreach ($frontier as $parentId) {
                $stmt->bind_param('i', $parentId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_row()) {
                    $cid = (int)$row[0];
                    if (!isset($descendants[$cid])) {
                        $descendants[$cid] = true;
                        $next[] = $cid;
                    }
                }
            }
            $frontier = $next;
            $depth++;
        }
        $stmt->close();
    }

    /* Empty query → still return the top-N most-used parents so the
       picker shows something useful as soon as the field is focused.
       This mirrors what curators expect from the affiliation typeahead. */
    try {
        $like   = '%' . $q . '%';
        $excl   = array_keys($descendants);
        /* Build the IN(...) placeholders from the descendant count.
           Always at least one placeholder so the prepared SQL stays
           constant-shape when nothing is excluded — bind a sentinel 0. */
        $exclPh = $excl ? implode(',', array_fill(0, count($excl), '?')) : '0';

        if ($q === '') {
            $sql = "SELECT b.Id, b.Abbreviation, b.Name, b.Language,
                           (SELECT COUNT(*) FROM tblSongbooks c WHERE c.ParentSongbookId = b.Id) AS childCount
                      FROM tblSongbooks b
                     WHERE b.Id NOT IN ($exclPh)
                     ORDER BY childCount DESC, b.Name ASC
                     LIMIT ?";
            $stmt = $db->prepare($sql);
            $types = str_repeat('i', count($excl) ?: 1) . 'i';
            $args  = $excl ?: [0];
            $args[] = $limit;
            $stmt->bind_param($types, ...$args);
        } else {
            $sql = "SELECT b.Id, b.Abbreviation, b.Name, b.Language,
                           (SELECT COUNT(*) FROM tblSongbooks c WHERE c.ParentSongbookId = b.Id) AS childCount
                      FROM tblSongbooks b
                     WHERE b.Id NOT IN ($exclPh)
                       AND (b.Name LIKE ? OR b.Abbreviation LIKE ?)
                     ORDER BY childCount DESC, b.Name ASC
                     LIMIT ?";
            $stmt = $db->prepare($sql);
            $types = str_repeat('i', count($excl) ?: 1) . 'ssi';
            $args  = $excl ?: [0];
            $args[] = $like;
            $args[] = $like;
            $args[] = $limit;
            $stmt->bind_param($types, ...$args);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $suggestions = [];
        while ($row = $res->fetch_assoc()) {
            $suggestions[] = [
                'id'           => (int)$row['Id'],
                'abbreviation' => (string)$row['Abbreviation'],
                'name'         => (string)$row['Name'],
                'language'     => (string)($row['Language'] ?? ''),
                'childCount'   => (int)$row['childCount'],
            ];
        }
        $stmt->close();
        echo json_encode(['suggestions' => $suggestions], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        error_log('[parent_search] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Search failed.']);
    }
    exit;
}

/* ---- GET ?action=compiler_search&q=… (#831) ---------------------------
 * JSON typeahead for the edit-modal Compilers picker. Returns rows
 * from tblCreditPeople matching the query, preferring people who are
 * already cited in the catalogue (name appears in any of the song-
 * credit tables) — keeps real contributors above bare registry rows.
 *
 * Empty `q` returns the most-cited people so the field surfaces useful
 * candidates as soon as it's focused, mirroring the affiliation +
 * parent typeaheads.
 *
 * Pre-migration safe: no schema dependency on tblSongbookCompilers
 * itself (we're searching the existing tblCreditPeople), so the
 * endpoint works as soon as the credit-people registry has rows.
 * ----------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && ($_GET['action'] ?? '') === 'compiler_search'
) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    $q     = trim((string)($_GET['q'] ?? ''));
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));

    /* tblCreditPeople is the registry; safe to assume it exists at
       this point (it's part of the core schema since #545 / install).
       Defensive fallback — if it really isn't there, return empty. */
    try {
        $probe = $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblCreditPeople' LIMIT 1"
        );
        if (!$probe || $probe->fetch_row() === null) {
            echo json_encode([
                'suggestions' => [],
                'note'        => 'tblCreditPeople not yet created — run /manage/setup-database',
            ]);
            exit;
        }
    } catch (\Throwable $e) {
        error_log('[compiler_search] probe failed: ' . $e->getMessage());
    }

    try {
        if ($q === '') {
            $sql = 'SELECT Id, Name, Slug FROM tblCreditPeople
                     WHERE COALESCE(IsSpecialCase, 0) = 0
                     ORDER BY Name ASC
                     LIMIT ?';
            $stmt = $db->prepare($sql);
            $stmt->bind_param('i', $limit);
        } else {
            $like = '%' . $q . '%';
            $sql  = 'SELECT Id, Name, Slug FROM tblCreditPeople
                      WHERE Name LIKE ?
                        AND COALESCE(IsSpecialCase, 0) = 0
                      ORDER BY Name ASC
                      LIMIT ?';
            $stmt = $db->prepare($sql);
            $stmt->bind_param('si', $like, $limit);
        }
        $stmt->execute();
        $res  = $stmt->get_result();
        $sugg = [];
        while ($row = $res->fetch_assoc()) {
            $sugg[] = [
                'id'   => (int)$row['Id'],
                'name' => (string)$row['Name'],
                'slug' => (string)($row['Slug'] ?? ''),
            ];
        }
        $stmt->close();
        echo json_encode(['suggestions' => $sugg], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        error_log('[compiler_search] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Search failed.']);
    }
    exit;
}

/* ---- Cycle-detection helper for #782 phase B --------------------------
 * Walks ParentSongbookId upward from $candidateParent and returns true
 * if the chain hits $rowId — i.e. setting that parent would create a
 * cycle. Capped at 64 hops to bound pathological data (the FK chain
 * never legitimately goes more than ~3 deep — English CIS → vernacular
 * CIS, MP1 → MP2 → MP-Combined). NULL parent terminates cleanly.
 * ----------------------------------------------------------------------- */
function _wouldCreateParentCycle(mysqli $db, int $rowId, int $candidateParent): bool
{
    if ($candidateParent <= 0 || $rowId <= 0) return false;
    if ($candidateParent === $rowId)          return true;
    $current = $candidateParent;
    $stmt    = $db->prepare('SELECT ParentSongbookId FROM tblSongbooks WHERE Id = ?');
    $hops    = 0;
    while ($current > 0 && $hops < 64) {
        $stmt->bind_param('i', $current);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        if (!$row) break;
        $next = $row[0] !== null ? (int)$row[0] : 0;
        if ($next === $rowId) { $stmt->close(); return true; }
        $current = $next;
        $hops++;
    }
    $stmt->close();
    return false;
}

/* Local closures over $db that wrap the shared helpers in
   includes/songbook_validation.php. The shared helpers are plain
   functions (no captured state); these wrappers exist solely so the
   call sites below can keep their existing `$registerAffiliation(...)`
   / `$validateAbbr(...)` syntax without re-piping $db through every
   call. (#719 PR 2a) */
$registerAffiliation = function (?string $name) use ($db): void {
    registerSongbookAffiliation($db, $name);
};
$validateAbbr   = fn(string $abbr): ?string => validateSongbookAbbr($abbr);
$validateColour = fn(string $c): ?string    => validateSongbookColour($c);
$validateBcp47  = fn(string $tag): ?string  => validateSongbookBcp47($tag);

/* Probe for the series schema BEFORE the POST handler so the
   series-membership reconciliation block inside it can gate on
   $hasSeriesSchema. Without this hoist the variable was first
   defined ~1000 lines down (in the dashboard render section)
   AFTER the POST handler had already returned, so every save
   silently skipped its series block. The data-fetch (allSeries +
   sbSeriesMap) stays down with the render where it's actually
   consumed. */
$hasSeriesSchema = false;
try {
    $probeSeries = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'tblSongbookSeries'
          LIMIT 1"
    );
    $probeSeries->execute();
    $hasSeriesSchema = $probeSeries->get_result()->fetch_row() !== null;
    $probeSeries->close();
} catch (\Throwable $_e) { /* probe failure → series UI stays hidden */ }

/* Probe for tblSongbookCompilers (#831). Same hoist rationale as
   $hasSeriesSchema — the POST handler's reconciliation block needs
   the flag, so we can't defer the probe to the render section. */
$hasCompilersSchema = false;
try {
    $probeComp = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'tblSongbookCompilers'
          LIMIT 1"
    );
    $probeComp->execute();
    $hasCompilersSchema = $probeComp->get_result()->fetch_row() !== null;
    $probeComp->close();
} catch (\Throwable $_e) { /* probe failure → compilers UI stays hidden */ }

/* Probe for tblSongbookAlternativeTitles (#832). Same hoist
   rationale as $hasSeriesSchema — POST handler needs the gate. */
$hasAltNamesSchema = false;
try {
    $probeAlt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'tblSongbookAlternativeTitles'
          LIMIT 1"
    );
    $probeAlt->execute();
    $hasAltNamesSchema = $probeAlt->get_result()->fetch_row() !== null;
    $probeAlt->close();
} catch (\Throwable $_e) { /* probe failure → alt-names UI stays hidden */ }

/* Probe for the external-links schema (#833). Same hoist rationale —
   POST handler's reconciliation block needs the gate. */
$hasExtLinksSchema = false;
try {
    $probeEx = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'tblSongbookExternalLinks'
          LIMIT 1"
    );
    $probeEx->execute();
    $hasExtLinksSchema = $probeEx->get_result()->fetch_row() !== null;
    $probeEx->close();
} catch (\Throwable $_e) { /* probe failure → external-links UI stays hidden */ }

/* ----- POST actions ----- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    try {
        switch ($action) {
            case 'create': {
                $abbr    = trim((string)($_POST['abbreviation']    ?? ''));
                $name    = trim((string)($_POST['name']            ?? ''));
                $colour  = trim((string)($_POST['colour']          ?? ''));
                $order   = (int)($_POST['display_order']           ?? 0);
                /* #502 — new metadata columns. All nullable; empty
                   input normalises to null so the UNIQUE/null-group
                   semantics work as expected. */
                $isOfficial = !empty($_POST['is_official']) ? 1 : 0;
                $publisher  = trim((string)($_POST['publisher']        ?? '')) ?: null;
                $pubYear    = trim((string)($_POST['publication_year'] ?? '')) ?: null;
                $copyright  = trim((string)($_POST['copyright']        ?? '')) ?: null;
                $affiliation= trim((string)($_POST['affiliation']      ?? '')) ?: null;
                /* #673 / #681 — optional language. Empty selection saves
                   as NULL. Now widened to 35 chars to fit a full IETF
                   BCP 47 tag (lang[-Script][-Region]) and validated
                   against the v1 grammar. */
                $language   = trim((string)($_POST['language']         ?? '')) ?: null;
                if ($language !== null) {
                    $language = mb_substr($language, 0, 35);
                    if ($e = $validateBcp47($language)) { $error = $e; break; }
                }

                /* #672 — bibliographic + authority-control identifiers.
                   All nullable, all VARCHAR. trim()→null normalises
                   blank inputs so the column actually stores NULL
                   rather than '' (avoids the typical "is it really
                   missing" ambiguity in downstream queries). */
                $websiteUrl   = trim((string)($_POST['website_url']         ?? '')) ?: null;
                $iaUrl        = trim((string)($_POST['internet_archive_url']?? '')) ?: null;
                $wikipediaUrl = trim((string)($_POST['wikipedia_url']       ?? '')) ?: null;
                $wikidataId   = trim((string)($_POST['wikidata_id']         ?? '')) ?: null;
                $oclcNumber   = trim((string)($_POST['oclc_number']         ?? '')) ?: null;
                $ocnNumber    = trim((string)($_POST['ocn_number']          ?? '')) ?: null;
                $lcpNumber    = trim((string)($_POST['lcp_number']          ?? '')) ?: null;
                $isbn         = trim((string)($_POST['isbn']                ?? '')) ?: null;
                $arkId        = trim((string)($_POST['ark_id']              ?? '')) ?: null;
                $isniId       = trim((string)($_POST['isni_id']             ?? '')) ?: null;
                $viafId       = trim((string)($_POST['viaf_id']             ?? '')) ?: null;
                $lccn         = trim((string)($_POST['lccn']                ?? '')) ?: null;
                $lcClass      = trim((string)($_POST['lc_class']            ?? '')) ?: null;

                if ($e = $validateAbbr($abbr))   { $error = $e; break; }
                if ($name === '')                { $error = 'Name is required.'; break; }
                if ($e = $validateColour($colour)) { $error = $e; break; }

                /* Auto-colour fallback (#677). When a curator leaves
                   the Colour field blank, pick a palette colour the
                   catalogue isn't already using so the new badge is
                   visually distinct from neighbouring books. An
                   explicit colour types into the field still wins —
                   this only fires when $colour is empty after
                   validation. */
                if ($colour === '') {
                    $colour = pickAutoSongbookColour($db, $abbr);
                }

                $stmt = $db->prepare('SELECT Id FROM tblSongbooks WHERE Abbreviation = ?');
                $stmt->bind_param('s', $abbr);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if ($exists) { $error = 'Abbreviation already exists.'; break; }

                $stmt = $db->prepare(
                    'INSERT INTO tblSongbooks
                        (Abbreviation, Name, DisplayOrder, Colour,
                         IsOfficial, Publisher, PublicationYear, Copyright, Affiliation,
                         Language,
                         WebsiteUrl, InternetArchiveUrl, WikipediaUrl, WikidataId,
                         OclcNumber, OcnNumber, LcpNumber, Isbn, ArkId, IsniId,
                         ViafId, Lccn, LcClass)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?,
                             ?,
                             ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                /* Types breakdown:
                     Abbr(s), Name(s), DisplayOrder(i), Colour(s),               4
                     IsOfficial(i), Publisher(s), PubYear(s), Copyright(s),
                       Affiliation(s),                                            5
                     Language(s) — #673                                            1
                     Website(s), IA(s), Wikipedia(s), Wikidata(s),
                       OCLC(s), OCN(s), LCP(s), ISBN(s), ARK(s), ISNI(s),
                       VIAF(s), LCCN(s), LcClass(s)                              13
                                                                            ----
                                                                              23
                   mysqli passes NULL correctly when a bound variable is null
                   even with type 's'. */
                $orderInt = (int)($order ?: 0);
                /* Type string is exactly 23 chars to match the 23 bound
                   values: ssis (Abbr,Name,Order,Colour) + isssss
                   (IsOfficial,Publisher,Year,Copyright,Affiliation) +
                   s (Language) + 13 × s (bibliographic). The earlier
                   24-char form had one stray trailing 's' which made
                   PHP 8.5 mysqli throw "Number of variables doesn't
                   match number of parameters" on every create —
                   surfaced to the curator as the generic "Database
                   error — check server logs" banner. (#694) */
                $stmt->bind_param(
                    'ssisissssssssssssssssss',
                    $abbr, $name, $orderInt, $colour,
                    $isOfficial, $publisher, $pubYear, $copyright, $affiliation,
                    $language,
                    $websiteUrl, $iaUrl, $wikipediaUrl, $wikidataId,
                    $oclcNumber, $ocnNumber, $lcpNumber, $isbn, $arkId, $isniId,
                    $viafId, $lccn, $lcClass
                );
                $stmt->execute();
                $newId = (int)$db->insert_id;
                $stmt->close();
                logActivity('songbook.create', 'songbook', (string)$newId, [
                    'abbreviation'    => $abbr,
                    'name'            => $name,
                    'display_order'   => $order ?: 0,
                    'colour'          => $colour,
                    'is_official'     => (bool)$isOfficial,
                    'publisher'       => $publisher,
                    'publication_year'=> $pubYear,
                    'copyright'       => $copyright,
                    'affiliation'     => $affiliation,
                    'language'        => $language,
                    /* #672 — log only the keys that have a value, so
                       empty bibliographic blocks don't bloat the
                       activity-log row. */
                    'bibliographic'   => array_filter([
                        'website_url'           => $websiteUrl,
                        'internet_archive_url'  => $iaUrl,
                        'wikipedia_url'         => $wikipediaUrl,
                        'wikidata_id'           => $wikidataId,
                        'oclc_number'           => $oclcNumber,
                        'ocn_number'            => $ocnNumber,
                        'lcp_number'            => $lcpNumber,
                        'isbn'                  => $isbn,
                        'ark_id'                => $arkId,
                        'isni_id'               => $isniId,
                        'viaf_id'               => $viafId,
                        'lccn'                  => $lccn,
                        'lc_class'              => $lcClass,
                    ], fn($v) => $v !== null && $v !== ''),
                ]);
                /* Self-populate the affiliation registry so the next
                   open of the typeahead surfaces this value (#670). */
                $registerAffiliation($affiliation);
                $success = "Songbook '{$abbr}' created.";
                break;
            }

            case 'update': {
                $id          = (int)($_POST['id'] ?? 0);
                $name        = trim((string)($_POST['name']         ?? ''));
                $colour      = trim((string)($_POST['colour']       ?? ''));
                $order       = (int)($_POST['display_order']        ?? 0);
                $newAbbr     = trim((string)($_POST['new_abbreviation'] ?? ''));
                $alsoRename  = !empty($_POST['rename_song_refs']);
                /* #502 — basic metadata columns. */
                $isOfficial  = !empty($_POST['is_official']) ? 1 : 0;
                $publisher   = trim((string)($_POST['publisher']        ?? '')) ?: null;
                $pubYear     = trim((string)($_POST['publication_year'] ?? '')) ?: null;
                $copyright   = trim((string)($_POST['copyright']        ?? '')) ?: null;
                $affiliation = trim((string)($_POST['affiliation']      ?? '')) ?: null;
                /* #673 / #681 — optional language; full IETF BCP 47 tag. */
                $language    = trim((string)($_POST['language']         ?? '')) ?: null;
                if ($language !== null) {
                    $language = mb_substr($language, 0, 35);
                    if ($e = $validateBcp47($language)) { $error = $e; break; }
                }

                /* #672 — bibliographic + authority-control identifiers. */
                $websiteUrl   = trim((string)($_POST['website_url']         ?? '')) ?: null;
                $iaUrl        = trim((string)($_POST['internet_archive_url']?? '')) ?: null;
                $wikipediaUrl = trim((string)($_POST['wikipedia_url']       ?? '')) ?: null;
                $wikidataId   = trim((string)($_POST['wikidata_id']         ?? '')) ?: null;
                $oclcNumber   = trim((string)($_POST['oclc_number']         ?? '')) ?: null;
                $ocnNumber    = trim((string)($_POST['ocn_number']          ?? '')) ?: null;
                $lcpNumber    = trim((string)($_POST['lcp_number']          ?? '')) ?: null;
                $isbn         = trim((string)($_POST['isbn']                ?? '')) ?: null;
                $arkId        = trim((string)($_POST['ark_id']              ?? '')) ?: null;
                $isniId       = trim((string)($_POST['isni_id']             ?? '')) ?: null;
                $viafId       = trim((string)($_POST['viaf_id']             ?? '')) ?: null;
                $lccn         = trim((string)($_POST['lccn']                ?? '')) ?: null;
                $lcClass      = trim((string)($_POST['lc_class']            ?? '')) ?: null;

                /* #782 phase B — Parent songbook fields. Curator picks
                   a parent via the typeahead which fills `parent_songbook_id`
                   (hidden int) plus `parent_relationship` (enum dropdown).
                   Both fields are optional: a NULL parent means "standalone"
                   (or peer-grouped via the series tables, which is phase C
                   territory). Posted blank → null on both. The relationship
                   is force-cleared when the parent is, so we can't leave a
                   stranded "translation" label without a target. */
                $parentIdRaw   = trim((string)($_POST['parent_songbook_id']  ?? ''));
                $parentRelRaw  = trim((string)($_POST['parent_relationship'] ?? ''));
                $parentId      = ($parentIdRaw === '' || $parentIdRaw === '0') ? null : (int)$parentIdRaw;
                $parentRel     = $parentRelRaw === '' ? null : $parentRelRaw;
                if ($parentId === null) {
                    $parentRel = null;
                }
                if ($parentRel !== null
                    && !in_array($parentRel, ['translation', 'edition', 'abridgement'], true)
                ) {
                    $error = 'Parent relationship must be translation, edition, or abridgement.';
                    break;
                }

                /* Probe whether the #782 phase A columns are live. A
                   deployment that hasn't yet run migrate-parent-songbooks.php
                   should still let curators edit songbooks — the parent
                   fields just stay invisible to the audit diff + the
                   secondary UPDATE below skips. */
                $hasParentCols = false;
                try {
                    $probe = $db->prepare(
                        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE()
                            AND TABLE_NAME   = 'tblSongbooks'
                            AND COLUMN_NAME  = 'ParentSongbookId'
                          LIMIT 1"
                    );
                    $probe->execute();
                    $hasParentCols = $probe->get_result()->fetch_row() !== null;
                    $probe->close();
                } catch (\Throwable $_e) { /* probe failure → fall through */ }

                /* Fetch the full before-row so the audit log carries
                   a complete diff of which fields actually changed
                   (#535) — otherwise the timeline reader has to
                   guess. SELECT extended for #672 metadata so the
                   diff covers the new identifier columns too. The
                   #782 parent fields are appended only when the
                   schema probe above said they exist. */
                $parentSelect = $hasParentCols
                    ? ', ParentSongbookId, ParentRelationship'
                    : '';
                $existing = $db->prepare(
                    'SELECT Abbreviation, Name, DisplayOrder, Colour, IsOfficial,
                            Publisher, PublicationYear, Copyright, Affiliation,
                            Language,
                            WebsiteUrl, InternetArchiveUrl, WikipediaUrl, WikidataId,
                            OclcNumber, OcnNumber, LcpNumber, Isbn, ArkId, IsniId,
                            ViafId, Lccn, LcClass' . $parentSelect . '
                       FROM tblSongbooks WHERE Id = ?'
                );
                $existing->bind_param('i', $id);
                $existing->execute();
                $beforeRow = $existing->get_result()->fetch_assoc() ?: null;
                $existing->close();
                $oldAbbr = $beforeRow ? (string)$beforeRow['Abbreviation'] : '';
                if ($oldAbbr === '') { $error = 'Songbook not found.'; break; }

                if ($name === '')                  { $error = 'Name is required.'; break; }
                if ($e = $validateColour($colour)) { $error = $e; break; }

                /* Handle optional abbreviation change */
                $abbrChanged = $newAbbr !== '' && $newAbbr !== $oldAbbr;
                if ($abbrChanged) {
                    if ($e = $validateAbbr($newAbbr)) { $error = $e; break; }
                    $dup = $db->prepare('SELECT Id FROM tblSongbooks WHERE Abbreviation = ? AND Id <> ?');
                    $dup->bind_param('si', $newAbbr, $id);
                    $dup->execute();
                    $dupExists = $dup->get_result()->fetch_row() !== null;
                    $dup->close();
                    if ($dupExists) { $error = 'That abbreviation is already taken.'; break; }
                }

                /* #782 phase B — cycle guard. The typeahead already
                   excludes self + descendants from the suggestions, but
                   a curator hitting POST directly could still try to
                   smuggle one in. Walk upward from the candidate; abort
                   if the chain hits this row. Self-as-parent and any
                   loop both surface as the same friendly error. */
                if ($hasParentCols && $parentId !== null) {
                    if ($parentId === $id) {
                        $error = 'A songbook cannot be its own parent.';
                        break;
                    }
                    if (_wouldCreateParentCycle($db, $id, $parentId)) {
                        $error = 'That parent would create a loop in the songbook hierarchy.';
                        break;
                    }
                }

                $db->begin_transaction();
                try {
                    $stmt = $db->prepare(
                        'UPDATE tblSongbooks
                            SET Name = ?, Colour = ?, DisplayOrder = ?,
                                IsOfficial = ?, Publisher = ?,
                                PublicationYear = ?, Copyright = ?, Affiliation = ?,
                                Language = ?,
                                WebsiteUrl = ?, InternetArchiveUrl = ?,
                                WikipediaUrl = ?, WikidataId = ?,
                                OclcNumber = ?, OcnNumber = ?, LcpNumber = ?,
                                Isbn = ?, ArkId = ?, IsniId = ?,
                                ViafId = ?, Lccn = ?, LcClass = ?
                          WHERE Id = ?'
                    );
                    /* Types breakdown:
                         Name(s), Colour(s), Order(i),                   3
                         IsOfficial(i), Publisher(s), Year(s), Copy(s),
                           Affiliation(s),                               5
                         Language(s) — #673                              1
                         13 × bibliographic-identifier strings           13
                         Id(i)                                            1
                                                                        ----
                                                                         23 */
                    $orderInt = (int)($order ?: 0);
                    /* Type string is exactly 23 chars to match the 23
                       bound values: ssi (Name,Colour,Order) + issss
                       (IsOfficial,Publisher,Year,Copyright,Affiliation) +
                       s (Language) + 13 × s (bibliographic) + i (Id).
                       The earlier 24-char form had one stray trailing
                       's' so every PHP 8.5 mysqli execute() threw
                       "Number of variables doesn't match number of
                       parameters" — surfaced to the curator as the
                       generic "Database error — check server logs"
                       banner whenever they touched the new
                       bibliographic / Language fields. (#694) */
                    $stmt->bind_param(
                        'ssiissssssssssssssssssi',
                        $name, $colour, $orderInt,
                        $isOfficial, $publisher, $pubYear, $copyright, $affiliation,
                        $language,
                        $websiteUrl, $iaUrl, $wikipediaUrl, $wikidataId,
                        $oclcNumber, $ocnNumber, $lcpNumber, $isbn, $arkId, $isniId,
                        $viafId, $lccn, $lcClass,
                        $id
                    );
                    $stmt->execute();
                    $stmt->close();

                    if ($abbrChanged) {
                        $stmt = $db->prepare('UPDATE tblSongbooks SET Abbreviation = ? WHERE Id = ?');
                        $stmt->bind_param('si', $newAbbr, $id);
                        $stmt->execute();
                        $stmt->close();
                        if ($alsoRename) {
                            $stmt = $db->prepare('UPDATE tblSongs SET SongbookAbbr = ? WHERE SongbookAbbr = ?');
                            $stmt->bind_param('ss', $newAbbr, $oldAbbr);
                            $stmt->execute();
                            $stmt->close();
                        }
                    }

                    /* #782 phase B — write the parent-songbook fields in
                       a separate small UPDATE so the existing 23-param
                       bind on the big UPDATE above stays untouched (one
                       fewer place to mis-count types — see #694). Skipped
                       silently when the schema columns aren't live yet. */
                    if ($hasParentCols) {
                        $stmt = $db->prepare(
                            'UPDATE tblSongbooks
                                SET ParentSongbookId = ?, ParentRelationship = ?
                              WHERE Id = ?'
                        );
                        /* Bind NULLs explicitly via mysqli_stmt::send_long_data?
                           No — for INT/string params, binding a PHP null with
                           type 'i' / 's' sends SQL NULL on PHP 8+, which is
                           exactly what we want for the optional FK / enum. */
                        $stmt->bind_param('isi', $parentId, $parentRel, $id);
                        $stmt->execute();
                        $stmt->close();
                    }

                    /* #782 phase C — reconcile series memberships for this
                       songbook. The edit modal posts a checkbox group
                       `series_membership_ids[]` containing the series ids
                       this songbook should belong to after the save. We
                       (1) delete any existing membership not in the post
                       and (2) insert any post id not already on disk. We
                       deliberately leave SortOrder + Note untouched on
                       rows that were already present — those are managed
                       on /manage/songbook-series, not from here.

                       Schema-gated by $hasSeriesSchema (probed at page
                       load) so a deployment that hasn't run
                       migrate-parent-songbooks.php (#782 phase A) sees
                       no UI block + skips this code path entirely. */
                    if ($hasSeriesSchema) {
                        $postedSeriesIds = $_POST['series_membership_ids'] ?? [];
                        if (!is_array($postedSeriesIds)) $postedSeriesIds = [];
                        $postedSeriesIds = array_values(array_unique(array_map('intval', $postedSeriesIds)));
                        $postedSeriesIds = array_values(array_filter(
                            $postedSeriesIds,
                            static fn(int $v): bool => $v > 0
                        ));

                        if ($postedSeriesIds) {
                            /* DELETE rows whose SeriesId is not in the posted set. */
                            $ph    = implode(',', array_fill(0, count($postedSeriesIds), '?'));
                            $sql   = "DELETE FROM tblSongbookSeriesMembership
                                       WHERE SongbookId = ?
                                         AND SeriesId NOT IN ($ph)";
                            $stmt  = $db->prepare($sql);
                            $types = 'i' . str_repeat('i', count($postedSeriesIds));
                            $args  = array_merge([$id], $postedSeriesIds);
                            $stmt->bind_param($types, ...$args);
                            $stmt->execute();
                            $stmt->close();

                            /* INSERT IGNORE for the upsert side — composite
                               PK (SeriesId, SongbookId) makes a duplicate
                               insert a no-op, which is what we want for
                               toggles that were already on. SortOrder
                               defaults to 0 for fresh rows; the series
                               page is the canonical place to tweak it. */
                            $stmt = $db->prepare(
                                'INSERT IGNORE INTO tblSongbookSeriesMembership
                                     (SeriesId, SongbookId, SortOrder)
                                  VALUES (?, ?, 0)'
                            );
                            foreach ($postedSeriesIds as $sid) {
                                $stmt->bind_param('ii', $sid, $id);
                                $stmt->execute();
                            }
                            $stmt->close();
                        } else {
                            /* No checkboxes ticked → strip every membership
                               for this songbook. */
                            $stmt = $db->prepare(
                                'DELETE FROM tblSongbookSeriesMembership WHERE SongbookId = ?'
                            );
                            $stmt->bind_param('i', $id);
                            $stmt->execute();
                            $stmt->close();
                        }
                    }

                    /* #831 — reconcile compiler credits for this songbook.
                       The edit modal posts three parallel arrays:
                         compiler_person_ids[]   — credit-people FKs
                         compiler_notes[]        — optional per-row note
                       Position N in each array is the same compiler row;
                       array index also drives SortOrder so a curator's
                       drag-reorder persists.
                       Reconciliation: replace the row's full compiler
                       set on every save (DELETE-then-INSERT in one
                       transaction). Cheaper than diff-then-update and
                       avoids edge cases when the same person appears
                       twice with different SortOrder.
                       Schema-gated by $hasCompilersSchema so a
                       pre-migration deployment skips silently. */
                    $postedCompilerSet = [];
                    if ($hasCompilersSchema) {
                        $rawIds   = $_POST['compiler_person_ids'] ?? [];
                        $rawNotes = $_POST['compiler_notes']      ?? [];
                        if (!is_array($rawIds))   $rawIds   = [];
                        if (!is_array($rawNotes)) $rawNotes = [];
                        $compilerRows = [];
                        $seenIds      = [];
                        foreach ($rawIds as $idx => $rawPid) {
                            $pid = (int)$rawPid;
                            if ($pid <= 0)              continue;
                            if (isset($seenIds[$pid]))  continue;   /* de-dup */
                            $seenIds[$pid] = true;
                            $note = trim((string)($rawNotes[$idx] ?? ''));
                            $compilerRows[] = [
                                'pid'   => $pid,
                                'note'  => ($note !== '' ? mb_substr($note, 0, 255) : null),
                                'order' => count($compilerRows),  /* sequential */
                            ];
                        }

                        $stmt = $db->prepare('DELETE FROM tblSongbookCompilers WHERE SongbookId = ?');
                        $stmt->bind_param('i', $id);
                        $stmt->execute();
                        $stmt->close();

                        if ($compilerRows) {
                            $stmt = $db->prepare(
                                'INSERT INTO tblSongbookCompilers
                                     (SongbookId, CreditPersonId, SortOrder, Note)
                                  VALUES (?, ?, ?, ?)'
                            );
                            foreach ($compilerRows as $cr) {
                                $stmt->bind_param('iiis', $id, $cr['pid'], $cr['order'], $cr['note']);
                                $stmt->execute();
                                $postedCompilerSet[] = $cr['pid'];
                            }
                            $stmt->close();
                        }
                    }

                    /* #832 — reconcile songbook alternative names. The edit
                       modal posts parallel arrays:
                         alt_name_titles[]  — the alt name string
                         alt_name_notes[]   — optional per-row note
                       Position N in each array is the same alt-name row;
                       array index drives SortOrder. Empty / blank titles
                       are dropped before insert.
                       Schema-gated by $hasAltNamesSchema; pre-migration
                       deployments skip this block silently. */
                    $postedAltNames = [];
                    if ($hasAltNamesSchema) {
                        $rawT = $_POST['alt_name_titles'] ?? [];
                        $rawN = $_POST['alt_name_notes']  ?? [];
                        if (!is_array($rawT)) $rawT = [];
                        if (!is_array($rawN)) $rawN = [];
                        $altRows = [];
                        $seen    = [];
                        foreach ($rawT as $idx => $rawTitle) {
                            $title = trim((string)$rawTitle);
                            if ($title === '')              continue;
                            $title = mb_substr($title, 0, 255);
                            $key   = mb_strtolower($title);
                            if (isset($seen[$key]))         continue;  /* de-dup case-insensitive */
                            $seen[$key] = true;
                            $note = trim((string)($rawN[$idx] ?? ''));
                            $altRows[] = [
                                'title' => $title,
                                'note'  => ($note !== '' ? mb_substr($note, 0, 255) : null),
                                'order' => count($altRows),
                            ];
                        }

                        $stmt = $db->prepare('DELETE FROM tblSongbookAlternativeTitles WHERE SongbookId = ?');
                        $stmt->bind_param('i', $id);
                        $stmt->execute();
                        $stmt->close();

                        if ($altRows) {
                            $stmt = $db->prepare(
                                'INSERT INTO tblSongbookAlternativeTitles
                                     (SongbookId, Title, SortOrder, Note)
                                  VALUES (?, ?, ?, ?)'
                            );
                            foreach ($altRows as $a) {
                                $stmt->bind_param('isis', $id, $a['title'], $a['order'], $a['note']);
                                $stmt->execute();
                                $postedAltNames[] = $a['title'];
                            }
                            $stmt->close();
                        }
                    }

                    /* #833 — reconcile external links. The edit modal posts
                       four parallel arrays:
                         ext_link_type_ids[]   tblExternalLinkTypes.Id
                         ext_link_urls[]       URL
                         ext_link_notes[]      optional Note
                         ext_link_verified[]   '1'/''  per-row checkbox
                       Position N in each array is the same link row;
                       array index drives SortOrder. Empty / blank URLs
                       are dropped before insert. DELETE-then-INSERT in
                       the existing transaction.
                       Schema-gated by $hasExtLinksSchema. */
                    $postedLinkCount = 0;
                    if ($hasExtLinksSchema) {
                        $rawTypes    = $_POST['ext_link_type_ids']  ?? [];
                        $rawUrls     = $_POST['ext_link_urls']      ?? [];
                        $rawNotes    = $_POST['ext_link_notes']     ?? [];
                        $rawVerified = $_POST['ext_link_verified']  ?? [];
                        if (!is_array($rawTypes))    $rawTypes    = [];
                        if (!is_array($rawUrls))     $rawUrls     = [];
                        if (!is_array($rawNotes))    $rawNotes    = [];
                        if (!is_array($rawVerified)) $rawVerified = [];

                        $linkRows = [];
                        foreach ($rawTypes as $idx => $rawTypeId) {
                            $typeId = (int)$rawTypeId;
                            $url    = trim((string)($rawUrls[$idx] ?? ''));
                            if ($typeId <= 0 || $url === '')                  continue;
                            if (!preg_match('#^https?://#i', $url))           continue;  /* must be http(s) */
                            if (mb_strlen($url) > 2048)                       continue;
                            $note = trim((string)($rawNotes[$idx] ?? ''));
                            $verified = !empty($rawVerified[$idx]) ? 1 : 0;
                            $linkRows[] = [
                                'type_id'  => $typeId,
                                'url'      => $url,
                                'note'     => ($note !== '' ? mb_substr($note, 0, 255) : null),
                                'verified' => $verified,
                                'order'    => count($linkRows),
                            ];
                        }

                        $stmt = $db->prepare('DELETE FROM tblSongbookExternalLinks WHERE SongbookId = ?');
                        $stmt->bind_param('i', $id);
                        $stmt->execute();
                        $stmt->close();

                        if ($linkRows) {
                            $stmt = $db->prepare(
                                'INSERT INTO tblSongbookExternalLinks
                                     (SongbookId, LinkTypeId, Url, Note, SortOrder, Verified)
                                  VALUES (?, ?, ?, ?, ?, ?)'
                            );
                            foreach ($linkRows as $r) {
                                $stmt->bind_param(
                                    'iissii',
                                    $id, $r['type_id'], $r['url'], $r['note'], $r['order'], $r['verified']
                                );
                                $stmt->execute();
                                $postedLinkCount++;
                            }
                            $stmt->close();
                        }
                    }

                    $db->commit();

                    /* Audit (#535) — compute the changed-fields list
                       explicitly so the row stays small (the full
                       before-row is on $beforeRow and we don't need
                       to dump every key). Extended for #672 to cover
                       the 13 new identifier columns. */
                    $afterRow = [
                        'Abbreviation'      => $abbrChanged ? $newAbbr : $oldAbbr,
                        'Name'              => $name,
                        'DisplayOrder'      => $order ?: 0,
                        'Colour'            => $colour,
                        'IsOfficial'        => $isOfficial,
                        'Publisher'         => $publisher,
                        'PublicationYear'   => $pubYear,
                        'Copyright'         => $copyright,
                        'Affiliation'       => $affiliation,
                        'Language'          => $language,
                        'WebsiteUrl'        => $websiteUrl,
                        'InternetArchiveUrl'=> $iaUrl,
                        'WikipediaUrl'      => $wikipediaUrl,
                        'WikidataId'        => $wikidataId,
                        'OclcNumber'        => $oclcNumber,
                        'OcnNumber'         => $ocnNumber,
                        'LcpNumber'         => $lcpNumber,
                        'Isbn'              => $isbn,
                        'ArkId'             => $arkId,
                        'IsniId'            => $isniId,
                        'ViafId'            => $viafId,
                        'Lccn'              => $lccn,
                        'LcClass'           => $lcClass,
                    ];
                    if ($hasParentCols) {
                        $afterRow['ParentSongbookId']   = $parentId;
                        $afterRow['ParentRelationship'] = $parentRel;
                    }
                    $changed = [];
                    foreach ($afterRow as $k => $v) {
                        if (!array_key_exists($k, $beforeRow ?? [])) continue;
                        if ((string)$beforeRow[$k] !== (string)$v) $changed[] = $k;
                    }
                    /* #782 phase C — also note the post-save series
                       membership ids on the activity row so the audit
                       trail captures membership churn even though the
                       membership table doesn't appear in $beforeRow /
                       $afterRow. Cheap (small int array); kept inside
                       the optional schema gate. */
                    $auditExtras = [];
                    if ($hasSeriesSchema) {
                        $auditExtras['series_membership_ids'] = $postedSeriesIds ?? [];
                    }
                    if ($hasCompilersSchema) {
                        $auditExtras['compiler_person_ids'] = $postedCompilerSet;
                    }
                    if ($hasAltNamesSchema) {
                        $auditExtras['alternative_names'] = $postedAltNames;
                    }
                    if ($hasExtLinksSchema) {
                        $auditExtras['external_link_count'] = $postedLinkCount;
                    }
                    logActivity('songbook.edit', 'songbook', (string)$id, array_merge([
                        'fields'             => $changed,
                        'before'             => array_intersect_key($beforeRow, array_flip($changed)),
                        'after'              => array_intersect_key($afterRow,  array_flip($changed)),
                        'songs_renamed_too'  => $alsoRename && $abbrChanged,
                    ], $auditExtras));
                    /* Keep the affiliation registry in sync — only when the
                       value actually changed and is non-empty (#670). */
                    if (in_array('Affiliation', $changed, true)) {
                        $registerAffiliation($affiliation);
                    }

                    $success = $abbrChanged
                        ? "Songbook '{$oldAbbr}' → '{$newAbbr}'" . ($alsoRename ? ' (song references updated).' : ' (song references kept — resolve manually).')
                        : "Songbook '{$oldAbbr}' updated.";
                } catch (\Throwable $e) {
                    $db->rollback();
                    throw $e;
                }
                break;
            }

            case 'reorder': {
                /* Posted as display_order[id] = integer */
                $orders = $_POST['display_order'] ?? [];
                if (!is_array($orders)) { $error = 'Invalid reorder payload.'; break; }

                $db->begin_transaction();
                try {
                    $stmt = $db->prepare('UPDATE tblSongbooks SET DisplayOrder = ? WHERE Id = ?');
                    foreach ($orders as $id => $value) {
                        $valueInt = (int)$value;
                        $idInt    = (int)$id;
                        $stmt->bind_param('ii', $valueInt, $idInt);
                        $stmt->execute();
                    }
                    $stmt->close();
                    $db->commit();

                    /* Single audit row for the bulk reorder rather
                       than one per row — the activity-log viewer
                       wants the high-level operation, not 6
                       near-identical entries. (#535) */
                    logActivity('songbook.reorder', 'songbook', '', [
                        'count' => count($orders),
                        'order' => array_map(fn($v) => (int)$v, (array)$orders),
                    ]);

                    $success = 'Display order saved.';
                } catch (\Throwable $e) {
                    $db->rollback();
                    throw $e;
                }
                break;
            }

            case 'delete': {
                $id = (int)($_POST['id'] ?? 0);

                $stmt = $db->prepare('SELECT Abbreviation FROM tblSongbooks WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $abbr = (string)($row[0] ?? '');
                if ($abbr === '') { $error = 'Songbook not found.'; break; }

                $stmt = $db->prepare('SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = ?');
                $stmt->bind_param('s', $abbr);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $songCount = (int)($row[0] ?? 0);
                if ($songCount > 0) {
                    $error = "Cannot delete '{$abbr}': {$songCount} song(s) still reference it. Reassign them first, OR use the cascade-delete option (admin/global_admin only).";
                    break;
                }

                $stmt = $db->prepare('DELETE FROM tblSongbooks WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();

                /* Audit (#535) — capturing abbreviation in Details
                   means the row remains useful even after the FK
                   nulls out / the songbook is gone. */
                logActivity('songbook.delete', 'songbook', (string)$id, [
                    'abbreviation' => $abbr,
                ]);

                $success = "Songbook '{$abbr}' deleted.";
                break;
            }

            case 'delete_cascade': {
                /* Cascade delete: removes a songbook AND every song in
                   it AND every credit / chord / tag / translation / etc.
                   that referenced those songs. Admin / global_admin only.
                   Two-step typed-confirmation gated. (#706)

                   Why this works without manually deleting from every
                   child table: every FK to tblSongs.SongId is
                   `ON DELETE CASCADE` (verified against schema.sql at
                   commit time). MySQL handles the cascade automatically
                   when we DELETE FROM tblSongs. The FK from
                   tblSongs.SongbookAbbr → tblSongbooks.Abbreviation is
                   `ON DELETE RESTRICT`, so we MUST delete the songs
                   first; then the songbook row goes cleanly. */
                if (!in_array(($currentUser['role'] ?? ''), ['admin', 'global_admin'], true)) {
                    $error = 'Admin role required for cascade delete.';
                    break;
                }
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $db->prepare('SELECT Abbreviation FROM tblSongbooks WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $abbr = (string)($row[0] ?? '');
                if ($abbr === '') { $error = 'Songbook not found.'; break; }

                /* Server-side typed-confirmation gate. The client side
                   modal has the same check, but defence in depth. */
                $typed = trim((string)($_POST['confirm_abbr'] ?? ''));
                if ($typed !== $abbr) {
                    $error = "Cascade delete cancelled — the typed confirmation must match the songbook abbreviation '{$abbr}' exactly.";
                    break;
                }

                /* Count how many songs we're about to delete so we can
                   report it in the success banner + Activity Log. */
                $stmt = $db->prepare('SELECT COUNT(*) FROM tblSongs WHERE SongbookAbbr = ?');
                $stmt->bind_param('s', $abbr);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $songCount = (int)($row[0] ?? 0);

                $db->begin_transaction();
                try {
                    /* DELETE the songs — cascades to every credit / tag /
                       chord / translation / artist / request-resolved
                       reference / etc. via the FK ON DELETE CASCADE rules. */
                    $stmt = $db->prepare('DELETE FROM tblSongs WHERE SongbookAbbr = ?');
                    $stmt->bind_param('s', $abbr);
                    $stmt->execute();
                    $stmt->close();

                    /* Then the songbook row itself. */
                    $stmt = $db->prepare('DELETE FROM tblSongbooks WHERE Id = ?');
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $stmt->close();

                    $db->commit();

                    logActivity('songbook.delete_cascade', 'songbook', (string)$id, [
                        'abbreviation' => $abbr,
                        'song_count'   => $songCount,
                    ]);

                    $success = "Songbook '{$abbr}' deleted along with {$songCount} song"
                             . ($songCount === 1 ? '' : 's')
                             . ' and every credit / tag / chord / translation that referenced them.';
                } catch (\Throwable $e) {
                    $db->rollback();
                    throw $e;
                }
                break;
            }

            case 'auto_colour_fill':
            case 'auto_colour_reassign': {
                /* Bulk auto-colour action (#716). Two modes:
                     fill      — only rows where Colour IS NULL or '' get a
                                 newly-picked palette colour. Existing values
                                 left alone.
                     reassign  — every row gets a fresh colour. Destructive,
                                 hence the confirm-by-typing-REASSIGN-ALL gate
                                 enforced both client-side AND server-side.
                   Admin / global_admin only. */
                if (!in_array(($currentUser['role'] ?? ''), ['admin', 'global_admin'], true)) {
                    $error = 'Admin role required for the auto-colour bulk action.';
                    break;
                }
                $mode = $action === 'auto_colour_reassign' ? 'reassign' : 'fill';
                if ($mode === 'reassign') {
                    /* Server-side typed-confirmation gate — even if the
                       client-side disable was bypassed, the action only
                       runs when the curator typed the literal phrase. */
                    $typed = trim((string)($_POST['confirm_phrase'] ?? ''));
                    if ($typed !== 'REASSIGN ALL') {
                        $error = 'Reassign-all needs the phrase REASSIGN ALL typed exactly.';
                        break;
                    }
                }
                /* Walk every songbook abbreviation, pick a colour, write back.
                   Uses pickAutoSongbookColour() which reads the in-use set
                   from tblSongbooks AS WE WRITE — so each successive pick
                   factors in the colours the loop has just assigned. */
                $stmt = $db->prepare('SELECT Id, Abbreviation, Colour FROM tblSongbooks ORDER BY Id');
                $stmt->execute();
                $books = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                $db->begin_transaction();
                $changed = 0;
                try {
                    $up = $db->prepare('UPDATE tblSongbooks SET Colour = ? WHERE Id = ?');
                    foreach ($books as $b) {
                        $existing = trim((string)($b['Colour'] ?? ''));
                        $needsAssign = $mode === 'reassign'
                            ? true
                            : !preg_match('/^#[0-9A-Fa-f]{6}$/', $existing);
                        if (!$needsAssign) continue;
                        $newColour = pickAutoSongbookColour($db, (string)$b['Abbreviation']);
                        $bookId    = (int)$b['Id'];
                        $up->bind_param('si', $newColour, $bookId);
                        $up->execute();
                        $changed++;
                    }
                    $up->close();
                    $db->commit();

                    logActivity(
                        $mode === 'reassign'
                            ? 'songbook.auto_colour_reassign'
                            : 'songbook.auto_colour_fill',
                        'songbook', '',
                        ['count' => $changed, 'mode' => $mode]
                    );
                    $success = $mode === 'reassign'
                        ? "Reassigned colours on {$changed} songbook"
                          . ($changed === 1 ? '' : 's') . '.'
                        : "Auto-coloured {$changed} songbook"
                          . ($changed === 1 ? '' : 's') . ' that had no colour set.';
                } catch (\Throwable $e) {
                    $db->rollback();
                    throw $e;
                }
                break;
            }

            case 'family_manifest': {
                /* #782 phase E — apply / preview a family-manifest JSON
                   file (the one ChristInSong.app.py emits as
                   `_family-manifest.json` after a scrape). Two modes
                   gated by `confirm`:
                     - confirm absent → preview-only; build the plan
                       and stash it on $manifestPreview for the page
                       render to display the table. No DB writes.
                     - confirm = '1'  → preview AND apply: walk the
                       same plan and run the parent-link UPDATE for
                       each row tagged 'will_link' / 'will_relink'.
                   Re-uploadable: skipped rows ("already correct",
                   "child missing", etc.) are surfaced in the result
                   so a curator can fix the catalogue and re-upload. */
                if (!in_array(($currentUser['role'] ?? ''), ['admin', 'global_admin'], true)) {
                    $error = 'Admin role required to apply a family manifest.';
                    break;
                }
                /* Local schema probe — the `update` case has its own
                   $hasParentCols scoped to that case, and we don't want
                   to lift it out just for one extra consumer. Same
                   INFORMATION_SCHEMA query, instance-cached costs are
                   negligible on the rare admin-only manifest path. */
                $hasParentCols = false;
                try {
                    $probe = $db->prepare(
                        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE()
                            AND TABLE_NAME   = 'tblSongbooks'
                            AND COLUMN_NAME  = 'ParentSongbookId'
                          LIMIT 1"
                    );
                    $probe->execute();
                    $hasParentCols = $probe->get_result()->fetch_row() !== null;
                    $probe->close();
                } catch (\Throwable $_e) { /* fall through to false */ }
                if (!$hasParentCols) {
                    $error = 'Parent-songbook schema (#782 phase A) is not installed yet — run /manage/setup-database first.';
                    break;
                }
                $upload = $_FILES['manifest'] ?? null;
                if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    $error = 'No manifest file received (or upload failed). Pick a JSON file and try again.';
                    break;
                }
                if (($upload['size'] ?? 0) > 1024 * 1024) {
                    /* Real manifests are tiny (a few KB). 1 MB is a
                       generous cap that stops a runaway file from
                       chewing memory in json_decode. */
                    $error = 'Manifest file is too large (>1 MB). Are you sure this is a family manifest?';
                    break;
                }
                $raw = file_get_contents($upload['tmp_name']);
                if ($raw === false || $raw === '') {
                    $error = 'Could not read the uploaded manifest file.';
                    break;
                }
                $manifest = json_decode($raw, true);
                if (!is_array($manifest)) {
                    $error = 'Manifest is not valid JSON.';
                    break;
                }
                if ((int)($manifest['schema_version'] ?? 0) !== 1) {
                    $error = 'Unsupported manifest schema_version (this admin only knows version 1).';
                    break;
                }
                $families = $manifest['families'] ?? [];
                if (!is_array($families) || !$families) {
                    $error = 'Manifest has no `families` entries to process.';
                    break;
                }

                /* Pull every (Id, Abbreviation, ParentSongbookId) once so
                   the plan loop is in-memory only. Catalogues are small
                   enough that this beats one round-trip per child. */
                $books = [];
                $stmt  = $db->prepare(
                    'SELECT Id, Abbreviation, ParentSongbookId, ParentRelationship
                       FROM tblSongbooks'
                );
                $stmt->execute();
                $res = $stmt->get_result();
                while ($brow = $res->fetch_assoc()) {
                    $books[strtoupper((string)$brow['Abbreviation'])] = [
                        'id'           => (int)$brow['Id'],
                        'parentId'     => isset($brow['ParentSongbookId']) ? (int)$brow['ParentSongbookId'] : 0,
                        'relationship' => (string)($brow['ParentRelationship'] ?? ''),
                    ];
                }
                $stmt->close();

                /* Build the plan: one row per child across every family.
                   Action codes:
                     'will_link'    — child has no parent yet → set parent
                     'will_relink'  — child has a parent that doesn't match the manifest → flagged but ONLY applied with `relink_existing=1`
                     'already_ok'   — child already points at the right parent with the right relationship → skipped
                     'parent_miss'  — manifest's parent.abbreviation isn't in the catalogue → cannot link, log + skip
                     'child_miss'   — manifest's child isn't in the catalogue → log + skip
                     'self'         — manifest claims child === parent → log + skip (defensive) */
                $plan = [];
                $relinkExisting = !empty($_POST['relink_existing']);
                foreach ($families as $fam) {
                    $parent = $fam['parent']  ?? null;
                    $kids   = $fam['children'] ?? [];
                    if (!is_array($parent) || !is_array($kids)) continue;
                    $parentAbbr = strtoupper(trim((string)($parent['abbreviation'] ?? '')));
                    if ($parentAbbr === '' || !isset($books[$parentAbbr])) {
                        foreach ((array)$kids as $kid) {
                            if (!is_array($kid)) continue;
                            $plan[] = [
                                'child'        => strtoupper((string)($kid['abbreviation'] ?? '')),
                                'parent'       => $parentAbbr,
                                'relationship' => (string)($kid['relationship'] ?? ''),
                                'action'       => 'parent_miss',
                                'note'         => "Parent `{$parentAbbr}` is not in the catalogue.",
                            ];
                        }
                        continue;
                    }
                    $parentId = $books[$parentAbbr]['id'];
                    foreach ($kids as $kid) {
                        if (!is_array($kid)) continue;
                        $childAbbr = strtoupper(trim((string)($kid['abbreviation'] ?? '')));
                        $rel       = (string)($kid['relationship'] ?? 'translation');
                        if (!in_array($rel, ['translation','edition','abridgement'], true)) {
                            $rel = 'translation';
                        }
                        if ($childAbbr === '') continue;
                        if ($childAbbr === $parentAbbr) {
                            $plan[] = ['child' => $childAbbr, 'parent' => $parentAbbr,
                                       'relationship' => $rel, 'action' => 'self',
                                       'note' => 'Manifest lists the parent as its own child — skipped.'];
                            continue;
                        }
                        if (!isset($books[$childAbbr])) {
                            $plan[] = ['child' => $childAbbr, 'parent' => $parentAbbr,
                                       'relationship' => $rel, 'action' => 'child_miss',
                                       'note' => "Child `{$childAbbr}` is not in the catalogue (was it imported?)."];
                            continue;
                        }
                        $current = $books[$childAbbr];
                        if ($current['parentId'] === 0) {
                            $plan[] = ['child' => $childAbbr, 'parent' => $parentAbbr,
                                       'relationship' => $rel, 'action' => 'will_link',
                                       'note' => ''];
                        } elseif ($current['parentId'] === $parentId
                                  && $current['relationship'] === $rel) {
                            $plan[] = ['child' => $childAbbr, 'parent' => $parentAbbr,
                                       'relationship' => $rel, 'action' => 'already_ok',
                                       'note' => 'Already linked to this parent with this relationship.'];
                        } else {
                            $plan[] = ['child' => $childAbbr, 'parent' => $parentAbbr,
                                       'relationship' => $rel, 'action' => 'will_relink',
                                       'note' => 'Currently links to a different parent / relationship. '
                                                . 'Tick "Relink existing" to overwrite.'];
                        }
                    }
                }

                /* Apply step (only when confirm=1). For will_relink rows
                   we additionally require relink_existing — keeps the
                   destructive overwrite behind a second tick so a hasty
                   double-click doesn't silently rewire half the catalogue. */
                $applied = 0;
                $skipped = 0;
                $confirm = !empty($_POST['confirm']);
                if ($confirm) {
                    $db->begin_transaction();
                    try {
                        $up = $db->prepare(
                            'UPDATE tblSongbooks
                                SET ParentSongbookId = ?, ParentRelationship = ?
                              WHERE Abbreviation = ?'
                        );
                        foreach ($plan as &$p) {
                            $shouldApply = ($p['action'] === 'will_link')
                                || ($p['action'] === 'will_relink' && $relinkExisting);
                            if (!$shouldApply) {
                                if (in_array($p['action'], ['will_link', 'will_relink'], true)) {
                                    $skipped++;
                                }
                                continue;
                            }
                            $parentAbbr  = $p['parent'];
                            $parentId    = $books[$parentAbbr]['id'];
                            $rel         = $p['relationship'];
                            $childAbbr   = $p['child'];
                            /* The UPDATE filters on Abbreviation rather
                               than Id so the SQL stays readable; types
                               are 'i' (parent id), 's' (relationship enum),
                               's' (child abbreviation). */
                            $up->bind_param('iss', $parentId, $rel, $childAbbr);
                            $up->execute();
                            $applied++;
                            $p['action'] = $p['action'] === 'will_relink' ? 'relinked' : 'linked';
                        }
                        unset($p);
                        $up->close();
                        $db->commit();
                        if (function_exists('logActivity')) {
                            logActivity('songbook.family_manifest_apply', 'songbook', '', [
                                'scraper'        => (string)($manifest['scraper'] ?? ''),
                                'applied'        => $applied,
                                'skipped'        => $skipped,
                                'plan_size'      => count($plan),
                                'relink_existing'=> $relinkExisting,
                            ]);
                        }
                        $success = "Applied {$applied} parent link(s) from the manifest"
                                 . ($skipped > 0 ? "; {$skipped} link(s) skipped (see table for why)." : '.');
                    } catch (\Throwable $e) {
                        $db->rollback();
                        throw $e;
                    }
                } else {
                    $countByAction = array_count_values(array_column($plan, 'action'));
                    $success = 'Manifest parsed — preview below. Tick "Confirm" + re-submit to apply '
                             . (int)($countByAction['will_link'] ?? 0) . ' new link(s)'
                             . (isset($countByAction['will_relink']) ? ' (or also tick "Relink existing" to overwrite '
                                . (int)$countByAction['will_relink'] . ' diverging row(s))' : '')
                             . '.';
                }

                /* Stash for the page-template render block lower down. */
                $manifestPreview = [
                    'plan'            => $plan,
                    'applied'         => $applied,
                    'skipped'         => $skipped,
                    'confirmed'       => $confirm,
                    'relink_existing' => $relinkExisting,
                    'meta'            => [
                        'scraper'         => (string)($manifest['scraper']         ?? ''),
                        'scraper_version' => (string)($manifest['scraper_version'] ?? ''),
                        'generated_at'    => (string)($manifest['generated_at']    ?? ''),
                    ],
                ];
                break;
            }

            default:
                $error = 'Unknown action.';
        }
    } catch (\Throwable $e) {
        error_log('[manage/songbooks.php] ' . $e->getMessage());
        /* Surface the failure in the in-app Activity Log too, so a
           curator who hits this banner can see what actually went
           wrong without SSH'ing the host (#695). The action ties
           every failed admin save under one searchable verb so the
           viewer's "show errors" filter is one click. */
        logActivityError('admin.songbooks.save', 'songbook',
            (string)($_POST['id'] ?? ''), $e, [
                'action' => $_POST['action'] ?? null,
            ]);
        $error = $error ?: 'Database error — check server logs for details.';
    }
}

/* ----- Songbook series catalogue (#782 phase C) —————————————————————
 *       Pulled once per page-load + handed into the edit modal as a
 *       checkbox list so curators can attach a songbook to one or more
 *       series (Songs of Fellowship volumes, themed compilations) without
 *       having to bounce over to /manage/songbook-series.
 *
 *       The series CRUD itself lives on /manage/songbook-series — this
 *       page only exposes membership toggles. Sort-order + Note edits
 *       on a per-membership basis stay on the series page (asymmetry
 *       on purpose: from the songbook side, only "is it in?" matters).
 *
 *       Schema-conditional: if tblSongbookSeries hasn't been migrated
 *       yet the section is silently absent and $allSeries / $sbSeriesMap
 *       stay empty.
 *
 *       $hasSeriesSchema is probed earlier in the file (just before
 *       the POST handler) so the membership-reconciliation block
 *       inside the POST path can gate on it. Don't re-probe here.
 * ----- */
$allSeries       = [];
$sbSeriesMap     = []; /* SongbookId => [SeriesId, ...] */
if ($hasSeriesSchema) {
    try {
        $res = $db->query('SELECT Id, Name, Slug FROM tblSongbookSeries ORDER BY Name ASC');
        if ($res) {
            while ($srow = $res->fetch_assoc()) {
                $allSeries[] = [
                    'id'   => (int)$srow['Id'],
                    'name' => (string)$srow['Name'],
                    'slug' => (string)$srow['Slug'],
                ];
            }
            $res->close();
        }
        $res = $db->query('SELECT SeriesId, SongbookId FROM tblSongbookSeriesMembership');
        if ($res) {
            while ($mrow = $res->fetch_assoc()) {
                $sb = (int)$mrow['SongbookId'];
                if (!isset($sbSeriesMap[$sb])) $sbSeriesMap[$sb] = [];
                $sbSeriesMap[$sb][] = (int)$mrow['SeriesId'];
            }
            $res->close();
        }
    } catch (\Throwable $e) {
        error_log('[manage/songbooks.php series fetch] ' . $e->getMessage());
        /* Reset to safe defaults so the modal still opens. */
        $allSeries   = [];
        $sbSeriesMap = [];
    }
}

/* ----- Compiler-credits map for the edit modal (#831) -----------------
 *       SongbookId => [{personId, personName, sortOrder, note}, …]
 *       Joined to tblCreditPeople so the edit-modal payload can render
 *       chips with the person's display name without a second client
 *       round-trip. Schema-conditional via $hasCompilersSchema (probed
 *       earlier alongside $hasSeriesSchema) so a pre-migration
 *       deployment loads the page with the section silently empty.
 * ----- */
$sbCompilersMap = []; /* SongbookId => [{...}, ...] in SortOrder asc */
if ($hasCompilersSchema) {
    try {
        $res = $db->query(
            'SELECT c.SongbookId, c.CreditPersonId, c.SortOrder, c.Note,
                    p.Name AS PersonName, p.Slug AS PersonSlug
               FROM tblSongbookCompilers c
               JOIN tblCreditPeople     p ON p.Id = c.CreditPersonId
              ORDER BY c.SongbookId ASC, c.SortOrder ASC, p.Name ASC'
        );
        if ($res) {
            while ($crow = $res->fetch_assoc()) {
                $sb = (int)$crow['SongbookId'];
                if (!isset($sbCompilersMap[$sb])) $sbCompilersMap[$sb] = [];
                $sbCompilersMap[$sb][] = [
                    'person_id'   => (int)$crow['CreditPersonId'],
                    'person_name' => (string)$crow['PersonName'],
                    'person_slug' => (string)($crow['PersonSlug'] ?? ''),
                    'sort_order'  => (int)$crow['SortOrder'],
                    'note'        => (string)($crow['Note'] ?? ''),
                ];
            }
            $res->close();
        }
    } catch (\Throwable $e) {
        error_log('[manage/songbooks.php compilers fetch] ' . $e->getMessage());
        $sbCompilersMap = [];
    }
}

/* ----- Alternative-names map (#832) -----------------------------------
 *       SongbookId => [{title, sortOrder, note}, …] in SortOrder asc.
 *       Same caching strategy as $sbSeriesMap — single batch fetch
 *       per page-load. Schema-conditional via $hasAltNamesSchema.
 * ----- */
$sbAltNamesMap = [];
if ($hasAltNamesSchema) {
    try {
        $res = $db->query(
            'SELECT SongbookId, Title, SortOrder, Note
               FROM tblSongbookAlternativeTitles
              ORDER BY SongbookId ASC, SortOrder ASC, Title ASC'
        );
        if ($res) {
            while ($arow = $res->fetch_assoc()) {
                $sb = (int)$arow['SongbookId'];
                if (!isset($sbAltNamesMap[$sb])) $sbAltNamesMap[$sb] = [];
                $sbAltNamesMap[$sb][] = [
                    'title'      => (string)$arow['Title'],
                    'sort_order' => (int)$arow['SortOrder'],
                    'note'       => (string)($arow['Note'] ?? ''),
                ];
            }
            $res->close();
        }
    } catch (\Throwable $e) {
        error_log('[manage/songbooks.php alt-names fetch] ' . $e->getMessage());
        $sbAltNamesMap = [];
    }
}

/* ----- External-links registry + per-songbook map (#833) -----
 *       $linkTypesForSongbook — every active link type whose
 *       AppliesTo set includes 'songbook'. Drives the type dropdown
 *       in the edit-modal card-list.
 *       $sbLinksMap — SongbookId => [{type_id, slug, name, url,
 *       note, verified, sort_order}, …]. Same caching strategy as
 *       the series map.
 *       Schema-conditional via $hasExtLinksSchema. */
$linkTypesForSongbook = [];
$sbLinksMap           = [];
if ($hasExtLinksSchema) {
    try {
        $res = $db->query(
            "SELECT Id, Slug, Name, Category, IconClass, AllowMultiple, DisplayOrder
               FROM tblExternalLinkTypes
              WHERE COALESCE(IsActive, 1) = 1
                AND FIND_IN_SET('songbook', AppliesTo) > 0
              ORDER BY Category, DisplayOrder ASC, Name ASC"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $linkTypesForSongbook[] = [
                    'id'             => (int)$row['Id'],
                    'slug'           => (string)$row['Slug'],
                    'name'           => (string)$row['Name'],
                    'category'       => (string)$row['Category'],
                    'icon_class'     => (string)($row['IconClass'] ?? ''),
                    'allow_multiple' => (int)$row['AllowMultiple'],
                ];
            }
            $res->close();
        }

        $res = $db->query(
            'SELECT el.SongbookId, el.LinkTypeId, el.Url, el.Note, el.SortOrder, el.Verified,
                    t.Slug, t.Name, t.Category, t.IconClass
               FROM tblSongbookExternalLinks el
               JOIN tblExternalLinkTypes t ON t.Id = el.LinkTypeId
              ORDER BY el.SongbookId ASC, t.Category, el.SortOrder ASC, t.Name ASC'
        );
        if ($res) {
            while ($lrow = $res->fetch_assoc()) {
                $sb = (int)$lrow['SongbookId'];
                if (!isset($sbLinksMap[$sb])) $sbLinksMap[$sb] = [];
                $sbLinksMap[$sb][] = [
                    'type_id'    => (int)$lrow['LinkTypeId'],
                    'slug'       => (string)$lrow['Slug'],
                    'name'       => (string)$lrow['Name'],
                    'category'   => (string)$lrow['Category'],
                    'icon_class' => (string)($lrow['IconClass'] ?? ''),
                    'url'        => (string)$lrow['Url'],
                    'note'       => (string)($lrow['Note'] ?? ''),
                    'verified'   => (int)$lrow['Verified'],
                    'sort_order' => (int)$lrow['SortOrder'],
                ];
            }
            $res->close();
        }
    } catch (\Throwable $e) {
        error_log('[manage/songbooks.php external-links fetch] ' . $e->getMessage());
        $linkTypesForSongbook = [];
        $sbLinksMap           = [];
    }
}

/* ----- Active languages for the songbook editor's optional
 *       Language dropdown (#673). Sourced from tblLanguages so the
 *       admin doesn't have to hard-code ISO codes. We pull this
 *       once per page-load and pass it through to both the create
 *       form and the edit modal. Best-effort — if tblLanguages is
 *       missing (very old install) we fall back to a minimal English
 *       option so the dropdown at least has something selectable.
 * ----- */
$languages = [];
try {
    $stmt = $db->prepare(
        'SELECT Code, Name, NativeName
           FROM tblLanguages
          WHERE IsActive = 1
          ORDER BY Name ASC'
    );
    $stmt->execute();
    $languages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (\Throwable $e) {
    error_log('[manage/songbooks.php] could not load tblLanguages: ' . $e->getMessage());
    $languages = [['Code' => 'en', 'Name' => 'English', 'NativeName' => 'English']];
}

/* ----- GET: list ----- */
$rows = [];
try {
    /* Probe whether the #672 columns exist before SELECTing them.
       A deployment that hasn't run migrate-songbook-bibliographic.php
       yet should still render the songbook list — the new fields are
       just absent from the edit-modal payload until the migration
       runs. Cheaper than a try/catch + retry. */
    $hasBibCols = false;
    try {
        $probe = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblSongbooks'
                AND COLUMN_NAME  = 'WikidataId'
              LIMIT 1"
        );
        $probe->execute();
        $hasBibCols = $probe->get_result()->fetch_row() !== null;
        $probe->close();
    } catch (\Throwable $_e) { /* probe failure → fall through to base SELECT */ }

    $bibSelect = $hasBibCols
        ? ', b.WebsiteUrl, b.InternetArchiveUrl, b.WikipediaUrl, b.WikidataId,
             b.OclcNumber, b.OcnNumber, b.LcpNumber, b.Isbn, b.ArkId, b.IsniId,
             b.ViafId, b.Lccn, b.LcClass'
        : '';

    /* Same probe-then-conditional-SELECT pattern for the #673
       Language column. A deployment that hasn't run
       migrate-songbook-language.php yet renders without the
       column; the edit-modal payload defaults Language to ''. */
    $hasLangCol = false;
    try {
        $probe = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblSongbooks'
                AND COLUMN_NAME  = 'Language'
              LIMIT 1"
        );
        $probe->execute();
        $hasLangCol = $probe->get_result()->fetch_row() !== null;
        $probe->close();
    } catch (\Throwable $_e) { /* probe failure → fall through */ }
    $langSelect = $hasLangCol ? ', b.Language' : '';

    /* Same probe pattern for the #782 phase A parent columns. When
       the schema is live, also LEFT JOIN to the parent row so the
       list-page Parent column can render abbreviation + name in one
       query. The join-aliased columns come back as ParentAbbr /
       ParentName / ParentRelationship to avoid clobbering b.* keys. */
    $hasParentCol = false;
    try {
        $probe = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblSongbooks'
                AND COLUMN_NAME  = 'ParentSongbookId'
              LIMIT 1"
        );
        $probe->execute();
        $hasParentCol = $probe->get_result()->fetch_row() !== null;
        $probe->close();
    } catch (\Throwable $_e) { /* probe failure → fall through */ }
    $parentSelect = $hasParentCol
        ? ', b.ParentSongbookId, b.ParentRelationship,
             p.Abbreviation AS ParentAbbreviation, p.Name AS ParentName'
        : '';
    $parentJoin   = $hasParentCol
        ? ' LEFT JOIN tblSongbooks p ON p.Id = b.ParentSongbookId'
        : '';

    $stmt = $db->prepare(
        'SELECT b.Id, b.Abbreviation, b.Name, b.SongCount, b.DisplayOrder, b.Colour,
                b.IsOfficial, b.Publisher, b.PublicationYear,
                b.Copyright, b.Affiliation' . $langSelect . $bibSelect . $parentSelect . ',
                COUNT(s.Id) AS ActualSongCount
           FROM tblSongbooks b
           LEFT JOIN tblSongs s ON s.SongbookAbbr = b.Abbreviation' . $parentJoin . '
          GROUP BY b.Id
          ORDER BY b.DisplayOrder ASC, b.Name ASC'
    );
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (\Throwable $e) {
    error_log('[manage/songbooks.php] ' . $e->getMessage());
    logActivityError('admin.songbooks.list', 'songbook', '', $e);
    $where = $e->getFile() ? (' (' . basename($e->getFile()) . ':' . $e->getLine() . ')') : '';
    $error = $error ?: 'Could not load songbooks: ' . $e->getMessage() . $where;
}

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Songbooks — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">

        <h1 class="h4 mb-3"><i class="bi bi-book me-2"></i>Songbooks</h1>
        <p class="text-secondary small mb-4">
            Add, rename, reorder and remove the songbooks users see in filters,
            search and the Song Editor. Abbreviation is the natural key on each
            song (<code>tblSongs.SongbookAbbr</code>), so renaming is opt-in.
        </p>

        <?php if ($success): ?>
            <div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- List + reorder -->
        <form method="POST" class="card-admin p-3 mb-4" id="songbook-list-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="reorder">

            <!-- #674 — quick-sort presets. Renumber DisplayOrder in 10-spaced
                 steps based on the chosen field; the user can review the
                 new order and hit "Save display order" to persist (or
                 navigate away to back out). Leading "The "/"A "/"An "
                 are stripped for the Name sort so "The Church Hymnal"
                 sorts among the C's, not the T's. -->
            <?php if ($rows): ?>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <small class="text-muted me-1">Quick sort:</small>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-sort-preset="name:asc">
                    <i class="bi bi-sort-alpha-down me-1"></i>Name A→Z
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-sort-preset="name:desc">
                    <i class="bi bi-sort-alpha-up-alt me-1"></i>Name Z→A
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-sort-preset="abbr:asc">
                    <i class="bi bi-sort-alpha-down me-1"></i>Abbr A→Z
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-sort-preset="abbr:desc">
                    <i class="bi bi-sort-alpha-up-alt me-1"></i>Abbr Z→A
                </button>
                <small class="text-muted ms-1">— preview applied; hit <em>Save display order</em> to persist.</small>
            </div>
            <?php endif; ?>

            <table class="table table-sm mb-2 align-middle cp-sortable admin-table-responsive" id="songbook-list-table">
                <thead>
                    <tr class="text-muted small">
                        <th data-col-priority="tertiary"  style="width:1.5rem" aria-label="Drag to reorder"></th>
                        <th data-col-priority="tertiary"  style="width:6rem">Order</th>
                        <th data-col-priority="primary"   data-sort-key="abbr" data-sort-type="text">Abbr</th>
                        <th data-col-priority="primary"   data-sort-key="name" data-sort-type="text">Name</th>
                        <th data-col-priority="secondary" class="text-center" data-sort-key="official" data-sort-type="text" title="Official published hymnal (#502)">Official</th>
                        <th data-col-priority="primary"   class="text-center" data-sort-key="songs" data-sort-type="number">Songs</th>
                        <th data-col-priority="secondary" data-sort-key="language" data-sort-type="text" title="IETF BCP 47 language tag — empty means multi-lingual / not specified (#778)">Languages</th>
                        <th data-col-priority="tertiary"  data-sort-key="parent" data-sort-type="text" title="Canonical parent songbook — translations / editions point upward to their source (#782)">Parent</th>
                        <th data-col-priority="tertiary"  data-sort-key="colour" data-sort-type="text">Colour</th>
                        <th data-col-priority="primary"   class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr class="songbook-row"
                            data-row-id="<?= (int)$r['Id'] ?>"
                            data-sort-name="<?= htmlspecialchars($r['Name']) ?>"
                            data-sort-abbr="<?= htmlspecialchars($r['Abbreviation']) ?>">
                            <td data-col-priority="tertiary" class="text-center align-middle">
                                <span class="songbook-drag-handle" title="Drag to reorder" aria-hidden="true">
                                    <i class="bi bi-grip-vertical"></i>
                                </span>
                            </td>
                            <td data-col-priority="tertiary">
                                <input type="number" min="0"
                                       class="form-control form-control-sm"
                                       name="display_order[<?= (int)$r['Id'] ?>]"
                                       value="<?= (int)$r['DisplayOrder'] ?>">
                            </td>
                            <td data-col-priority="primary"><code><?= htmlspecialchars($r['Abbreviation']) ?></code></td>
                            <td data-col-priority="primary"><?= htmlspecialchars($r['Name']) ?></td>
                            <td data-col-priority="secondary" class="text-center">
                                <?php if ((int)$r['IsOfficial'] === 1): ?>
                                    <span class="badge bg-info" title="Official published hymnal">
                                        <i class="bi bi-patch-check-fill" aria-hidden="true"></i> Yes
                                    </span>
                                <?php else: ?>
                                    <small class="text-muted" title="Curated grouping / pseudo-songbook">—</small>
                                <?php endif; ?>
                            </td>
                            <td data-col-priority="primary" class="text-center"><?= number_format((int)$r['ActualSongCount']) ?></td>
                            <td data-col-priority="secondary">
                                <?php
                                    /* Languages column (#778 v1). Renders the
                                       songbook's stored Language as an uppercase
                                       primary-subtag badge — same shape as the
                                       public-site songbook-tile-language-badge.
                                       Empty Language stays an em-dash; the
                                       absence is meaningful (multi-lingual or
                                       not-yet-tagged). v2 will swap this for a
                                       chip-list when multi-language support
                                       lands. */
                                    $bookLang = trim((string)($r['Language'] ?? ''));
                                ?>
                                <?php if ($bookLang !== ''): ?>
                                    <?php
                                        /* Show the full tag (e.g. en-GB, zh-Hans-CN)
                                           but render only the primary subtag in
                                           the badge for compactness, matching the
                                           public-site badge convention. The full
                                           tag goes in the title for hover. */
                                        $primary = strtoupper(preg_replace('/-.*$/', '', $bookLang) ?: $bookLang);
                                    ?>
                                    <span class="badge bg-info text-dark"
                                          style="font-size: 0.7rem; font-weight: 600;"
                                          title="<?= htmlspecialchars($bookLang, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($primary, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php else: ?>
                                    <small class="text-muted">—</small>
                                <?php endif; ?>
                            </td>
                            <td data-col-priority="tertiary">
                                <?php
                                    /* Parent column (#782 phase B). When the row
                                       has a parent, render a chip with the parent
                                       abbreviation + an icon picked from the
                                       relationship: bi-translate (translation),
                                       bi-bookmark (edition), bi-scissors
                                       (abridgement). The full parent name + the
                                       relationship word go in the title for hover.
                                       Empty parent → em-dash (the row stands alone
                                       or is peer-grouped via series, not handled
                                       in phase B). */
                                    $parentAbbr = trim((string)($r['ParentAbbreviation'] ?? ''));
                                    $parentRel  = trim((string)($r['ParentRelationship'] ?? ''));
                                    $parentName = trim((string)($r['ParentName']         ?? ''));
                                    $relIcon    = match ($parentRel) {
                                        'translation' => 'bi-translate',
                                        'edition'     => 'bi-bookmark',
                                        'abridgement' => 'bi-scissors',
                                        default       => 'bi-link-45deg',
                                    };
                                ?>
                                <?php if ($parentAbbr !== ''): ?>
                                    <span class="badge bg-secondary"
                                          style="font-size: 0.7rem; font-weight: 600;"
                                          title="<?= htmlspecialchars($parentName . ($parentRel !== '' ? ' — ' . $parentRel : ''), ENT_QUOTES, 'UTF-8') ?>">
                                        <i class="bi <?= htmlspecialchars($relIcon, ENT_QUOTES, 'UTF-8') ?> me-1" aria-hidden="true"></i><?= htmlspecialchars($parentAbbr, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php else: ?>
                                    <small class="text-muted">—</small>
                                <?php endif; ?>
                            </td>
                            <td data-col-priority="tertiary">
                                <?php if ($r['Colour']): ?>
                                    <span class="d-inline-block me-1" style="width:1rem;height:1rem;border-radius:50%;background:<?= htmlspecialchars($r['Colour']) ?>"></span>
                                    <small class="text-muted"><?= htmlspecialchars($r['Colour']) ?></small>
                                <?php else: ?>
                                    <small class="text-muted">—</small>
                                <?php endif; ?>
                            </td>
                            <td data-col-priority="primary" class="text-end">
                                <?php
                                    /* Build the row payload once, then escape for
                                       HTML attribute embedding (#774). The earlier
                                       form embedded raw json_encode() output inside
                                       a single-quoted onclick attribute — apostrophes
                                       in a Name (e.g. "Ogotera kw'ogotogia Nyasae"
                                       for OKON; many Polynesian / French / Slavic
                                       names contain them) terminated the attribute
                                       early and the click handler became malformed
                                       silently, so the modal never opened. The
                                       htmlspecialchars(ENT_QUOTES) wrap encodes
                                       the apostrophe as &#039; — the browser
                                       unescapes on attribute read and the JSON
                                       arrives intact. */
                                    $editRowJson = json_encode([
                                        'id'                  => (int)$r['Id'],
                                        'abbreviation'        => $r['Abbreviation'],
                                        'name'                => $r['Name'],
                                        'colour'              => $r['Colour'],
                                        'display_order'       => (int)$r['DisplayOrder'],
                                        'song_count'          => (int)$r['ActualSongCount'],
                                        'is_official'         => (int)$r['IsOfficial'] === 1,
                                        'publisher'           => $r['Publisher']       ?? '',
                                        'publication_year'    => $r['PublicationYear'] ?? '',
                                        'copyright'           => $r['Copyright']       ?? '',
                                        'affiliation'         => $r['Affiliation']     ?? '',
                                        /* #673 — Language defaults to '' so the dropdown
                                           picks "— Not specified —" when absent. */
                                        'language'            => $r['Language']        ?? '',
                                        /* #672 — fields default to '' so a row from a
                                           pre-migration deployment renders cleanly
                                           (the bibSelect probe above gates the SELECT). */
                                        'website_url'         => $r['WebsiteUrl']         ?? '',
                                        'internet_archive_url'=> $r['InternetArchiveUrl'] ?? '',
                                        'wikipedia_url'       => $r['WikipediaUrl']       ?? '',
                                        'wikidata_id'         => $r['WikidataId']         ?? '',
                                        'oclc_number'         => $r['OclcNumber']         ?? '',
                                        'ocn_number'          => $r['OcnNumber']          ?? '',
                                        'lcp_number'          => $r['LcpNumber']          ?? '',
                                        'isbn'                => $r['Isbn']               ?? '',
                                        'ark_id'              => $r['ArkId']              ?? '',
                                        'isni_id'             => $r['IsniId']             ?? '',
                                        'viaf_id'             => $r['ViafId']             ?? '',
                                        'lccn'                => $r['Lccn']               ?? '',
                                        'lc_class'            => $r['LcClass']            ?? '',
                                        /* #782 phase B — parent fields. Defaults
                                           keep the modal openable on a pre-migration
                                           deployment (the LEFT JOIN above only
                                           runs when the columns exist). */
                                        'parent_songbook_id'  => isset($r['ParentSongbookId']) ? (int)$r['ParentSongbookId'] : 0,
                                        'parent_relationship' => $r['ParentRelationship']  ?? '',
                                        'parent_abbreviation' => $r['ParentAbbreviation']  ?? '',
                                        'parent_name'         => $r['ParentName']          ?? '',
                                        /* #782 phase C — series the songbook is currently a
                                           member of. Empty array on a pre-migration deploy
                                           or for songbooks not in any series. */
                                        'series_membership_ids' => $sbSeriesMap[(int)$r['Id']] ?? [],
                                        /* #831 — compiler credits attached to this songbook,
                                           in SortOrder. Each item carries person_id, person_name,
                                           person_slug, note. Empty list pre-migration or for
                                           songbooks with no compilers. */
                                        'compilers'             => $sbCompilersMap[(int)$r['Id']] ?? [],
                                        /* #832 — alternative names attached to this songbook,
                                           in SortOrder. Each item carries title + note. Empty
                                           list pre-migration or for songbooks with no alts. */
                                        'alternative_names'     => $sbAltNamesMap[(int)$r['Id']] ?? [],
                                        /* #833 — external links attached to this songbook,
                                           in the saved order. Empty list pre-migration. */
                                        'external_links'        => $sbLinksMap[(int)$r['Id']] ?? [],
                                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                ?>
                                <button type="button" class="btn btn-sm btn-outline-info"
                                        onclick="openEditModal(<?= htmlspecialchars((string)$editRowJson, ENT_QUOTES, 'UTF-8') ?>)"
                                        title="Edit songbook">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <?php
                                    /* Three states for the delete button (#706):
                                       1. No songs                          → enabled, plain delete modal
                                       2. Has songs + admin/global_admin    → enabled, cascade modal (typed-confirm)
                                       3. Has songs + editor                → disabled (current behaviour preserved) */
                                    $songCnt = (int)$r['ActualSongCount'];
                                    $isAdmin = in_array(($currentUser['role'] ?? ''), ['admin', 'global_admin'], true);
                                ?>
                                <?php
                                    /* Same htmlspecialchars wrap as the Edit button
                                       above — protects the inline JSON against
                                       apostrophes / quotes in Abbreviation. (#774) */
                                    $deleteRowJson = json_encode([
                                        'id'           => (int)$r['Id'],
                                        'abbreviation' => $r['Abbreviation'],
                                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                    $cascadeRowJson = json_encode([
                                        'id'           => (int)$r['Id'],
                                        'abbreviation' => $r['Abbreviation'],
                                        'song_count'   => $songCnt,
                                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                ?>
                                <?php if ($songCnt === 0): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        onclick="openDeleteModal(<?= htmlspecialchars((string)$deleteRowJson, ENT_QUOTES, 'UTF-8') ?>)"
                                        title="Delete songbook (no songs reference it)">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php elseif ($isAdmin): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        onclick="openCascadeDeleteModal(<?= htmlspecialchars((string)$cascadeRowJson, ENT_QUOTES, 'UTF-8') ?>)"
                                        title="Cascade-delete: songbook + <?= $songCnt ?> song(s) + every reference to them">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php else: ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary" disabled
                                        title="<?= $songCnt ?> song(s) still reference this abbreviation — admin/global_admin role required for cascade delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr><td colspan="10" class="text-muted text-center py-4">No songbooks yet. Add one below.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if ($rows): ?>
                <button type="submit" class="btn btn-sm btn-amber-solid">
                    <i class="bi bi-save me-1"></i>Save display order
                </button>
                <small class="text-muted ms-2">Lower numbers render first. Any non-negative integer is fine — gaps of 10 between rows give you room to slot in a new book later, but you can use 1, 2, 3, … or anything else (#672).</small>
            <?php endif; ?>
        </form>

        <?php if (in_array(($currentUser['role'] ?? ''), ['admin', 'global_admin'], true)): ?>
        <!-- Auto-colour bulk action panel (#716). Admin / global_admin only.
             Two modes: fill (only rows with no colour set) and reassign
             (every row, gated by typed-confirmation). -->
        <div class="card-admin p-3 mb-4">
            <h2 class="h6 mb-3"><i class="bi bi-palette me-2"></i>Auto-colour songbooks</h2>
            <p class="small text-muted mb-3">
                Pick palette colours from the active theme so the catalogue stays visually consistent. Existing curator-typed colours are preserved unless the destructive Reassign mode is used.
            </p>
            <div class="d-flex flex-wrap gap-2 align-items-end">
                <form method="POST" class="d-inline-block"
                      onsubmit="return confirm('Auto-colour every songbook that currently has no colour assigned?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="auto_colour_fill">
                    <button type="submit" class="btn btn-amber btn-sm">
                        <i class="bi bi-droplet-half me-1"></i>Fill missing colours
                    </button>
                </form>
                <form method="POST" class="d-inline-flex align-items-end gap-2"
                      onsubmit="
                        if (this.querySelector('input[name=confirm_phrase]').value !== 'REASSIGN ALL') {
                            alert('Type the phrase REASSIGN ALL to enable this destructive action.');
                            return false;
                        }
                        return confirm('REASSIGN colours on EVERY songbook? Existing curator-typed values will be overwritten. This cannot be undone.');
                      ">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="auto_colour_reassign">
                    <input type="text" name="confirm_phrase" class="form-control form-control-sm"
                           placeholder="Type: REASSIGN ALL" autocomplete="off"
                           style="max-width: 11rem;">
                    <button type="submit" class="btn btn-outline-danger btn-sm">
                        <i class="bi bi-shuffle me-1"></i>Reassign all (destructive)
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if (in_array(($currentUser['role'] ?? ''), ['admin', 'global_admin'], true)): ?>
        <!-- Family-manifest uploader (#782 phase E). Admin / global_admin
             only. Curators upload the JSON file written by a scraper
             (e.g. .importers/scrapers/ChristInSong.app.py emits
             `_family-manifest.json` at the top of .SourceSongData/) to
             bulk-link vernacular hymnals to their canonical parent
             without hand-editing 22 rows on the songbooks list above.

             Two-step flow: upload + click "Preview plan" → review the
             plan table that renders below → tick "Confirm" + re-upload
             the same file to actually apply. The destructive overwrite
             ("Relink existing") sits behind a second tick so a hasty
             double-click can't silently rewire a row that already
             pointed at a different parent. -->
        <div class="card-admin p-3 mb-4">
            <h2 class="h6 mb-2"><i class="bi bi-diagram-3 me-2"></i>Apply family manifest</h2>
            <p class="small text-muted mb-3">
                Upload a JSON file produced by a scraper (e.g. <code>_family-manifest.json</code>
                from <code>ChristInSong.app.py</code>) to bulk-link vernacular / edition / abridgement
                rows to their canonical parent songbook. Preview-only by default — tick
                <strong>Confirm</strong> below the preview to apply.
            </p>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action"     value="family_manifest">
                <div class="row g-2 align-items-end">
                    <div class="col-sm-6">
                        <label class="form-label small">Manifest file (JSON, ≤ 1 MB)</label>
                        <input type="file" name="manifest"
                               class="form-control form-control-sm"
                               accept="application/json,.json" required>
                    </div>
                    <div class="col-sm-3 d-flex align-items-end gap-2">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox"
                                   name="confirm" value="1" id="manifest-confirm">
                            <label class="form-check-label small" for="manifest-confirm">
                                Confirm — apply the plan
                            </label>
                        </div>
                    </div>
                    <div class="col-sm-3 d-flex align-items-end gap-2">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox"
                                   name="relink_existing" value="1" id="manifest-relink">
                            <label class="form-check-label small" for="manifest-relink">
                                Relink existing
                                <small class="text-muted d-block">overwrite rows that already point elsewhere</small>
                            </label>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-amber btn-sm mt-3">
                    <i class="bi bi-eye me-1"></i>Preview / Apply
                </button>
            </form>

            <?php if ($manifestPreview !== null): ?>
                <hr class="my-3">
                <?php
                    $meta = $manifestPreview['meta'];
                    $countByAction = array_count_values(array_column($manifestPreview['plan'], 'action'));
                    /* Action label + Bootstrap badge class lookup —
                       pre-computed so the table loop stays compact. */
                    $actionMeta = [
                        'will_link'   => ['Will link',          'bg-info'],
                        'will_relink' => ['Would relink',       'bg-warning text-dark'],
                        'already_ok'  => ['Already correct',    'bg-success'],
                        'parent_miss' => ['Parent missing',     'bg-secondary'],
                        'child_miss'  => ['Child missing',      'bg-secondary'],
                        'self'        => ['Self-parent',        'bg-danger'],
                        'linked'      => ['Linked',             'bg-success'],
                        'relinked'    => ['Relinked',           'bg-success'],
                    ];
                ?>
                <div class="small text-muted mb-2">
                    Manifest from
                    <code><?= htmlspecialchars($meta['scraper'] ?: 'unknown') ?></code>
                    <?php if ($meta['scraper_version']): ?>
                        v<?= htmlspecialchars($meta['scraper_version']) ?>
                    <?php endif; ?>
                    <?php if ($meta['generated_at']): ?>
                        · generated <?= htmlspecialchars($meta['generated_at']) ?>
                    <?php endif; ?>
                    · <?= count($manifestPreview['plan']) ?> row(s).
                </div>
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr class="text-muted small">
                            <th>Child</th>
                            <th>Parent</th>
                            <th>Relationship</th>
                            <th>Action</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($manifestPreview['plan'] as $p):
                            [$lbl, $cls] = $actionMeta[$p['action']] ?? [$p['action'], 'bg-secondary'];
                        ?>
                            <tr>
                                <td><code><?= htmlspecialchars($p['child']) ?></code></td>
                                <td><code><?= htmlspecialchars($p['parent']) ?></code></td>
                                <td><small><?= htmlspecialchars($p['relationship']) ?></small></td>
                                <td><span class="badge <?= htmlspecialchars($cls) ?>"><?= htmlspecialchars($lbl) ?></span></td>
                                <td><small class="text-muted"><?= htmlspecialchars($p['note']) ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (!$manifestPreview['confirmed']): ?>
                    <p class="small text-muted mt-3 mb-0">
                        Re-upload the same file with <strong>Confirm</strong> ticked to apply
                        <?= (int)($countByAction['will_link'] ?? 0) ?> new link(s)<?php
                        if (!empty($countByAction['will_relink'])):
                            ?> (or also tick <strong>Relink existing</strong> to overwrite
                            <?= (int)$countByAction['will_relink'] ?> diverging row(s))<?php
                        endif; ?>.
                    </p>
                <?php else: ?>
                    <p class="small text-muted mt-3 mb-0">
                        Applied <?= (int)$manifestPreview['applied'] ?>;
                        skipped <?= (int)$manifestPreview['skipped'] ?> writable row(s).
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Create -->
        <form method="POST" class="card-admin p-3 mb-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="create">
            <h2 class="h6 mb-3"><i class="bi bi-plus-circle me-2"></i>Add a songbook</h2>

            <div class="row g-2">
                <div class="col-sm-3">
                    <label class="form-label small">Abbreviation</label>
                    <input type="text" name="abbreviation" class="form-control form-control-sm"
                           pattern="[A-Za-z0-9]+" maxlength="10" required
                           placeholder="e.g. CP">
                </div>
                <div class="col-sm-5">
                    <label class="form-label small">Name</label>
                    <input type="text" name="name" class="form-control form-control-sm"
                           maxlength="255" required placeholder="e.g. Church Praise">
                </div>
                <div class="col-sm-2">
                    <label class="form-label small">Colour (hex)</label>
                    <?php
                        /* Shared colour picker partial — native swatch
                           bound to the hex text input (#715). */
                        $name        = 'colour';
                        $value       = '';
                        $idPrefix    = 'create-songbook-colour';
                        $placeholder = '#1a73e8';
                        require __DIR__ . DIRECTORY_SEPARATOR
                            . 'includes' . DIRECTORY_SEPARATOR
                            . 'partials' . DIRECTORY_SEPARATOR
                            . 'colour-picker.php';
                        unset($name, $value, $idPrefix, $placeholder);
                    ?>
                </div>
                <div class="col-sm-2">
                    <label class="form-label small">Display order</label>
                    <input type="number" name="display_order" class="form-control form-control-sm"
                           min="0" value="0">
                </div>
            </div>

            <!-- #502 metadata -->
            <div class="row g-2 mt-2">
                <div class="col-sm-3 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_official" id="create-is-official" value="1">
                        <label class="form-check-label small" for="create-is-official">
                            Official published hymnal
                        </label>
                        <div class="form-text small">
                            Unticked by default — tick for a published hymnal; leave unticked for a curated grouping.
                        </div>
                    </div>
                </div>
                <div class="col-sm-5">
                    <label class="form-label small">Publisher</label>
                    <input type="text" name="publisher" class="form-control form-control-sm"
                           maxlength="255" placeholder="e.g. Praise Trust">
                </div>
                <div class="col-sm-4">
                    <label class="form-label small">Publication year / edition</label>
                    <input type="text" name="publication_year" class="form-control form-control-sm"
                           maxlength="50" placeholder="e.g. 1986, 1986–2003, 2nd edition 2011">
                </div>
            </div>
            <div class="row g-2 mt-2">
                <div class="col-sm-8">
                    <label class="form-label small">Copyright</label>
                    <input type="text" name="copyright" class="form-control form-control-sm"
                           maxlength="500" placeholder="e.g. © 2012 Praise Trust, All Rights Reserved">
                </div>
                <div class="col-sm-4">
                    <label class="form-label small">Affiliation</label>
                    <input type="text" name="affiliation"
                           class="form-control form-control-sm js-affiliation-input"
                           list="affiliations-datalist"
                           autocomplete="off"
                           maxlength="120"
                           placeholder="e.g. Seventh-day Adventist, Non-denominational">
                </div>
            </div>
            <!-- #681 — IETF BCP 47 composite picker. Replaces the
                 single ISO 639-1 dropdown from #673. The shared
                 partial under manage/includes/partials/ renders three
                 inputs (Language, Script, Region) plus a hidden
                 'language' field that holds the composed tag. -->
            <?php
                $idPrefix = 'create-songbook';
                $name     = 'language';
                $tag      = '';
                $label    = 'Language (IETF BCP 47, optional)';
                $help     = 'Empty = "not specified" (multi-lingual collection).';
                require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'ietf-language-picker.php';
            ?>

            <!-- #672 — collapsed by default; the create form already has 8 visible
                 fields and most curators don't need the bibliographic block on a
                 brand-new songbook. <details> is native HTML5 — no JS needed. -->
            <details class="mt-3">
                <summary class="form-label small text-muted" style="cursor:pointer;">
                    <i class="bi bi-link-45deg me-1"></i>Online links (optional)
                </summary>
                <div class="row g-2 mt-1">
                    <div class="col-sm-4">
                        <label class="form-label small">Official website</label>
                        <input type="url" name="website_url" class="form-control form-control-sm"
                               maxlength="500" placeholder="https://www.example.com">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small">Internet Archive URL</label>
                        <input type="url" name="internet_archive_url" class="form-control form-control-sm"
                               maxlength="500" placeholder="https://archive.org/details/…">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small">Wikipedia URL</label>
                        <input type="url" name="wikipedia_url" class="form-control form-control-sm"
                               maxlength="500" placeholder="https://en.wikipedia.org/wiki/…">
                    </div>
                </div>
            </details>

            <details class="mt-2">
                <summary class="form-label small text-muted" style="cursor:pointer;">
                    <i class="bi bi-card-list me-1"></i>Authority identifiers (optional)
                </summary>
                <div class="row g-2 mt-1">
                    <div class="col-sm-3">
                        <label class="form-label small">WikiData ID</label>
                        <input type="text" name="wikidata_id" class="form-control form-control-sm"
                               maxlength="20" placeholder="Q12345">
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label small">OCLC number</label>
                        <input type="text" name="oclc_number" class="form-control form-control-sm"
                               maxlength="30" placeholder="12345678">
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label small">OCN number</label>
                        <input type="text" name="ocn_number" class="form-control form-control-sm"
                               maxlength="30" placeholder="ocn123456789">
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label small">LCP number</label>
                        <input type="text" name="lcp_number" class="form-control form-control-sm"
                               maxlength="30" placeholder="LC2018012345">
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label small">ISBN</label>
                        <input type="text" name="isbn" class="form-control form-control-sm"
                               maxlength="20" placeholder="978-0-86065-654-1">
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label small">ARK ID</label>
                        <input type="text" name="ark_id" class="form-control form-control-sm"
                               maxlength="80" placeholder="ark:/13960/t8jf3w89z">
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label small">ISNI ID</label>
                        <input type="text" name="isni_id" class="form-control form-control-sm"
                               maxlength="25" placeholder="0000 0001 2345 6789">
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label small">VIAF ID</label>
                        <input type="text" name="viaf_id" class="form-control form-control-sm"
                               maxlength="20" placeholder="123456789">
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label small">LCCN</label>
                        <input type="text" name="lccn" class="form-control form-control-sm"
                               maxlength="20" placeholder="n79123456">
                    </div>
                    <div class="col-sm-9">
                        <label class="form-label small">LC Classification</label>
                        <input type="text" name="lc_class" class="form-control form-control-sm"
                               maxlength="50" placeholder="M2117 .M5 1990">
                    </div>
                </div>
            </details>

            <button type="submit" class="btn btn-amber-solid btn-sm mt-3">
                <i class="bi bi-plus me-1"></i>Create songbook
            </button>
        </form>

    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content" style="background: var(--ih-surface); color: var(--ih-text); border-color: var(--ih-border);">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" id="edit-id">
                    <div class="modal-header" style="border-color: var(--ih-border);">
                        <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit songbook — <code id="edit-abbr-label"></code></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Name</label>
                            <input type="text" class="form-control" name="name" id="edit-name" maxlength="255" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-sm-6">
                                <label class="form-label d-flex align-items-center justify-content-between">
                                    <span>Colour (hex)</span>
                                    <!-- Per-songbook auto-colour button (#772). Calls
                                         /manage/songbooks?action=pick_colour&abbr=<row>
                                         which in turn invokes pickAutoSongbookColour()
                                         to choose a hex not already used by any other
                                         songbook. The result is written into the
                                         colour-picker text input + swatch via the
                                         shared partial's existing change handler. -->
                                    <button type="button" class="btn btn-sm btn-outline-info py-0 px-2"
                                            id="edit-pick-colour-btn"
                                            title="Pick a random distinctive colour, avoiding ones already in use"
                                            style="font-size: 0.75rem;">
                                        <i class="bi bi-magic me-1" aria-hidden="true"></i>Pick distinctive
                                    </button>
                                </label>
                                <?php
                                    /* Shared colour picker partial (#715). The text
                                       input keeps id="edit-colour" via the partial's
                                       internal scheme — the JS that opens this modal
                                       still sets the value via querySelector on
                                       the .colour-picker-text class instead of by id. */
                                    $name        = 'colour';
                                    $value       = '';
                                    $idPrefix    = 'edit-songbook-colour';
                                    $placeholder = '#1a73e8';
                                    require __DIR__ . DIRECTORY_SEPARATOR
                                        . 'includes' . DIRECTORY_SEPARATOR
                                        . 'partials' . DIRECTORY_SEPARATOR
                                        . 'colour-picker.php';
                                    unset($name, $value, $idPrefix, $placeholder);
                                ?>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label">Display order</label>
                                <input type="number" class="form-control" name="display_order" id="edit-order"
                                       min="0">
                            </div>
                        </div>

                        <!-- #502 metadata block -->
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox"
                                   name="is_official" id="edit-is-official" value="1">
                            <label class="form-check-label" for="edit-is-official">
                                Official published hymnal
                            </label>
                            <div class="form-text small">
                                Unticked means this is a curated grouping / pseudo-songbook.
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Publisher</label>
                            <input type="text" class="form-control" name="publisher" id="edit-publisher"
                                   maxlength="255" placeholder="e.g. Praise Trust">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Publication year / edition</label>
                            <input type="text" class="form-control" name="publication_year" id="edit-publication-year"
                                   maxlength="50" placeholder="e.g. 1986, 1986–2003, 2nd edition 2011">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Copyright</label>
                            <input type="text" class="form-control" name="copyright" id="edit-copyright"
                                   maxlength="500" placeholder="e.g. © 2012 Praise Trust, All Rights Reserved">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Affiliation</label>
                            <input type="text"
                                   class="form-control js-affiliation-input"
                                   name="affiliation" id="edit-affiliation"
                                   list="affiliations-datalist"
                                   autocomplete="off"
                                   maxlength="120"
                                   placeholder="e.g. Seventh-day Adventist, Non-denominational">
                            <div class="form-text small">
                                Type to search existing affiliations or enter a new one — it
                                will be added to the registry on save (#670).
                            </div>
                        </div>

                        <!-- #681 — IETF BCP 47 composite picker (edit modal).
                             Renders empty here; openEditModal() below calls
                             editIetfPicker.setTag(row.language) on click to
                             pre-fill the three inputs from the saved tag. -->
                        <?php
                            $idPrefix = 'edit-songbook';
                            $name     = 'language';
                            $tag      = '';
                            $label    = 'Language (IETF BCP 47, optional)';
                            $help     = 'Empty = "not specified" (multi-lingual collection).';
                            require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'ietf-language-picker.php';
                        ?>

                        <!-- #782 phase B — Parent songbook picker. Two visible
                             inputs (text typeahead + relationship enum) plus a
                             hidden parent_songbook_id that gets set when the
                             curator picks a row from the typeahead. The Clear
                             button wipes both fields. The typeahead boot lives
                             at the bottom of the page (search "parent-typeahead"). -->
                        <div class="row g-2 mb-3" id="edit-parent-block">
                            <div class="col-sm-7">
                                <label class="form-label">Parent songbook (optional)</label>
                                <input type="hidden" name="parent_songbook_id" id="edit-parent-id" value="">
                                <div class="d-flex gap-1">
                                    <input type="text"
                                           class="form-control"
                                           id="edit-parent-search"
                                           list="edit-parent-datalist"
                                           autocomplete="off"
                                           maxlength="120"
                                           placeholder="Type to search — leave blank if standalone">
                                    <button type="button"
                                            class="btn btn-outline-secondary"
                                            id="edit-parent-clear"
                                            title="Clear parent">
                                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                                    </button>
                                </div>
                                <datalist id="edit-parent-datalist"></datalist>
                                <div class="form-text small">
                                    Pick the canonical parent for translations
                                    (Spanish CIS → English CIS), editions
                                    (Mission Praise 2 → Mission Praise 1), or
                                    abridgements. Series/volumes use a different
                                    mechanism (phase C, #782).
                                </div>
                            </div>
                            <div class="col-sm-5">
                                <label class="form-label">Relationship</label>
                                <select class="form-select"
                                        name="parent_relationship"
                                        id="edit-parent-relationship">
                                    <option value="">— Select —</option>
                                    <option value="translation">Translation of parent</option>
                                    <option value="edition">Edition of parent</option>
                                    <option value="abridgement">Abridgement of parent</option>
                                </select>
                                <div class="form-text small">
                                    Required when a parent is set; cleared
                                    automatically when the parent is removed.
                                </div>
                            </div>
                        </div>

                        <?php if ($hasSeriesSchema): ?>
                        <!-- #782 phase C — Series memberships. A simple
                             checkbox group rather than a typeahead since
                             series counts stay small (Songs of Fellowship,
                             Mission Praise, etc. — hand-counted dozens
                             at most). For richer per-membership editing
                             (sort-order, note) curators use the dedicated
                             /manage/songbook-series page. -->
                        <div class="mb-3" id="edit-series-block">
                            <label class="form-label d-flex justify-content-between align-items-center">
                                <span>Series memberships</span>
                                <a href="/manage/songbook-series" class="small text-info"
                                   title="Manage series — names, slugs, sort order, member adds">
                                    <i class="bi bi-collection me-1" aria-hidden="true"></i>Manage series
                                </a>
                            </label>
                            <?php if (!$allSeries): ?>
                                <div class="form-text small">
                                    No series defined yet. <a href="/manage/songbook-series">Create one</a>
                                    on the series page, then return here to attach this songbook.
                                </div>
                            <?php else: ?>
                                <div class="d-flex flex-wrap gap-2"
                                     id="edit-series-checkbox-group"
                                     role="group" aria-label="Series memberships">
                                    <?php foreach ($allSeries as $s): ?>
                                        <div class="form-check">
                                            <input class="form-check-input"
                                                   type="checkbox"
                                                   name="series_membership_ids[]"
                                                   value="<?= (int)$s['id'] ?>"
                                                   id="edit-series-cb-<?= (int)$s['id'] ?>">
                                            <label class="form-check-label small"
                                                   for="edit-series-cb-<?= (int)$s['id'] ?>">
                                                <?= htmlspecialchars($s['name']) ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="form-text small">
                                    Tick to attach this songbook to one or more series. Sort
                                    order within each series is set on the series page.
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($hasCompilersSchema): ?>
                        <!-- #831 — Compiler / Editor credits. Multi-row picker
                             where each row pairs a tblCreditPeople entry with
                             an optional note (edition / co-compiler context).
                             Drag-handle reorder persists via array-index ⇒
                             SortOrder on save. The hidden inputs are named
                             compiler_person_ids[] / compiler_notes[] in
                             parallel arrays. -->
                        <div class="mb-3" id="edit-compilers-block">
                            <label class="form-label d-flex justify-content-between align-items-center">
                                <span>Compilers / Editors</span>
                                <a href="/manage/credit-people" class="small text-info"
                                   title="Manage credit people — add a person, set bio, slug, …">
                                    <i class="bi bi-person-badge me-1" aria-hidden="true"></i>Manage people
                                </a>
                            </label>
                            <div class="form-text small mb-2">
                                The person(s) who compiled / edited this hymnal —
                                e.g. <em>Mission Praise</em> by Peter Horrobin &amp; Greg Leavers.
                                Distinct from per-song writer / composer credits.
                            </div>

                            <!-- Existing rows render here (one chip card per
                                 compiler). The boot script clears + repopulates
                                 from row.compilers when the modal opens. -->
                            <div id="edit-compilers-rows" class="vstack gap-2 mb-2"></div>

                            <!-- Add-by-typeahead input. Picking a name from the
                                 datalist commits the hidden id and appends a new
                                 row to #edit-compilers-rows. -->
                            <div class="row g-2 align-items-end">
                                <div class="col-md-8">
                                    <label class="form-label small" for="edit-compiler-add-search">Add a compiler</label>
                                    <input type="text"
                                           class="form-control form-control-sm"
                                           id="edit-compiler-add-search"
                                           list="edit-compiler-datalist"
                                           placeholder="Type a name from Credit People"
                                           autocomplete="off">
                                    <datalist id="edit-compiler-datalist"></datalist>
                                </div>
                                <div class="col-md-4">
                                    <button type="button"
                                            class="btn btn-sm btn-outline-info w-100"
                                            id="edit-compiler-add-btn">
                                        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add
                                    </button>
                                </div>
                            </div>
                            <div class="form-text small text-muted mt-1">
                                Person must already exist in Credit People. Use
                                <a href="/manage/credit-people">Manage people</a>
                                to register a new compiler before adding them here.
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($hasAltNamesSchema): ?>
                        <!-- #832 — Alternative songbook names. Multi-row chip list:
                             each row pairs an alt-title with an optional Note
                             ("vernacular", "older form", "transliteration", …).
                             Reorder via drag handle persists via array index ⇒
                             SortOrder on save. -->
                        <div class="mb-3" id="edit-alt-names-block">
                            <label class="form-label">Alternative names <span class="text-muted small">(optional)</span></label>
                            <div class="form-text small mb-2">
                                "Also known as …" — vernacular names, older spellings,
                                or originals. Searched alongside the canonical name and
                                emitted as JSON-LD <code>alternateName</code> for SEO.
                            </div>
                            <div id="edit-alt-names-rows" class="vstack gap-2 mb-2"></div>
                            <div class="row g-2 align-items-end">
                                <div class="col-md-9">
                                    <input type="text"
                                           class="form-control form-control-sm"
                                           id="edit-alt-name-add-input"
                                           placeholder="Type an alternative name and press Enter"
                                           maxlength="255"
                                           autocomplete="off">
                                </div>
                                <div class="col-md-3">
                                    <button type="button"
                                            class="btn btn-sm btn-outline-info w-100"
                                            id="edit-alt-name-add-btn">
                                        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($hasExtLinksSchema): ?>
                        <!-- #833 — External-links system.
                             MusicBrainz-style typed links with multiple
                             entries supported. Each row pairs a link
                             type (FK → tblExternalLinkTypes) with a URL,
                             an optional Note, and a Verified flag. -->
                        <div class="mb-3" id="edit-ext-links-block">
                            <label class="form-label">External links <span class="text-muted small">(optional)</span></label>
                            <div class="form-text small mb-2">
                                Hymnary.org · Internet Archive scans · Wikipedia ·
                                Wikidata · YouTube performances · Spotify recordings ·
                                etc. Multiple links of the same type are allowed
                                where the type permits. Verified means a curator
                                has eyeballed the URL and confirmed it's correct.
                            </div>
                            <div id="edit-ext-links-rows" class="vstack gap-2 mb-2"></div>
                            <button type="button"
                                    class="btn btn-sm btn-outline-info"
                                    id="edit-ext-link-add-btn">
                                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add link
                            </button>
                            <!-- Curated link-type registry (#833 seed list). Read at
                                 page load + serialized inline as a JSON map so the
                                 row-builder can populate the dropdown without an
                                 extra AJAX round-trip. -->
                            <script>
                                window._iHymnsLinkTypes = <?= json_encode($linkTypesForSongbook, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
                            </script>
                        </div>
                        <?php endif; ?>

                        <!-- #672 — collapsible "Online links" + "Authority identifiers".
                             Closed by default so the modal still opens at the same height
                             curators are used to. <details> is native HTML5; no JS needed
                             to toggle. The same field IDs are populated in openEditModal()
                             below from the row.* payload. -->
                        <details class="mb-3">
                            <summary class="form-label small text-muted" style="cursor:pointer;">
                                <i class="bi bi-link-45deg me-1"></i>Online links (optional)
                            </summary>
                            <div class="mt-2">
                                <div class="mb-2">
                                    <label class="form-label small">Official website</label>
                                    <input type="url" class="form-control form-control-sm"
                                           name="website_url" id="edit-website-url"
                                           maxlength="500" placeholder="https://www.example.com">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small">Internet Archive URL</label>
                                    <input type="url" class="form-control form-control-sm"
                                           name="internet_archive_url" id="edit-internet-archive-url"
                                           maxlength="500" placeholder="https://archive.org/details/…">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small">Wikipedia URL</label>
                                    <input type="url" class="form-control form-control-sm"
                                           name="wikipedia_url" id="edit-wikipedia-url"
                                           maxlength="500" placeholder="https://en.wikipedia.org/wiki/…">
                                </div>
                            </div>
                        </details>

                        <details class="mb-3">
                            <summary class="form-label small text-muted" style="cursor:pointer;">
                                <i class="bi bi-card-list me-1"></i>Authority identifiers (optional)
                            </summary>
                            <div class="row g-2 mt-2">
                                <div class="col-sm-6">
                                    <label class="form-label small">WikiData ID</label>
                                    <input type="text" class="form-control form-control-sm"
                                           name="wikidata_id" id="edit-wikidata-id"
                                           maxlength="20" placeholder="Q12345">
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label small">OCLC number</label>
                                    <input type="text" class="form-control form-control-sm"
                                           name="oclc_number" id="edit-oclc-number"
                                           maxlength="30" placeholder="12345678">
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label small">OCN number</label>
                                    <input type="text" class="form-control form-control-sm"
                                           name="ocn_number" id="edit-ocn-number"
                                           maxlength="30" placeholder="ocn123456789">
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label small">LCP number</label>
                                    <input type="text" class="form-control form-control-sm"
                                           name="lcp_number" id="edit-lcp-number"
                                           maxlength="30" placeholder="LC2018012345">
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label small">ISBN</label>
                                    <input type="text" class="form-control form-control-sm"
                                           name="isbn" id="edit-isbn"
                                           maxlength="20" placeholder="978-0-86065-654-1">
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label small">ARK ID</label>
                                    <input type="text" class="form-control form-control-sm"
                                           name="ark_id" id="edit-ark-id"
                                           maxlength="80" placeholder="ark:/13960/t8jf3w89z">
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label small">ISNI ID</label>
                                    <input type="text" class="form-control form-control-sm"
                                           name="isni_id" id="edit-isni-id"
                                           maxlength="25" placeholder="0000 0001 2345 6789">
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label small">VIAF ID</label>
                                    <input type="text" class="form-control form-control-sm"
                                           name="viaf_id" id="edit-viaf-id"
                                           maxlength="20" placeholder="123456789">
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label small">LCCN</label>
                                    <input type="text" class="form-control form-control-sm"
                                           name="lccn" id="edit-lccn"
                                           maxlength="20" placeholder="n79123456">
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label small">LC Classification</label>
                                    <input type="text" class="form-control form-control-sm"
                                           name="lc_class" id="edit-lc-class"
                                           maxlength="50" placeholder="M2117 .M5 1990">
                                </div>
                            </div>
                        </details>

                        <hr>
                        <div class="mb-3">
                            <label class="form-label">New abbreviation (optional)</label>
                            <input type="text" class="form-control" name="new_abbreviation" id="edit-new-abbr"
                                   pattern="[A-Za-z0-9]+" maxlength="10"
                                   placeholder="Leave blank to keep current">
                            <div class="form-text">
                                Abbreviation is the natural key. Renaming will <strong>not</strong> update songs by default.
                            </div>
                        </div>
                        <div class="form-check" id="edit-rename-refs-wrap">
                            <input class="form-check-input" type="checkbox" name="rename_song_refs" id="edit-rename-refs" value="1">
                            <label class="form-check-label" for="edit-rename-refs">
                                Also update <span id="edit-song-count">0</span> song(s) that reference the old abbreviation.
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer" style="border-color: var(--ih-border);">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-amber-solid">Save changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content" style="background: var(--ih-surface); color: var(--ih-text); border-color: var(--ih-border);">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="delete-id">
                    <div class="modal-header" style="border-color: var(--ih-border);">
                        <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Delete — <code id="delete-abbr-label"></code></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Remove this songbook? This is only allowed if no songs reference the abbreviation.</p>
                    </div>
                    <div class="modal-footer" style="border-color: var(--ih-border);">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Cascade-delete modal (#706) — admin / global_admin only.
         Two-step confirmation: (1) the user reads the song count + scary
         red text, (2) types the songbook abbreviation back into a field
         that gates the Delete button. The submit button stays disabled
         until window.cascadeDeleteAbbr === the typed value. -->
    <div class="modal fade" id="cascadeDeleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content" style="background: var(--ih-surface); color: var(--ih-text); border-color: var(--ih-border);">
                <form method="POST" id="cascadeDeleteForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="delete_cascade">
                    <input type="hidden" name="id" id="cascade-delete-id">
                    <div class="modal-header" style="border-color: var(--ih-border); background: rgba(220,53,69,0.15);">
                        <h5 class="modal-title">
                            <i class="bi bi-exclamation-triangle-fill me-2 text-danger"></i>
                            Cascade delete — <code id="cascade-delete-abbr-label"></code>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-danger">
                            <strong>This is destructive and cannot be undone.</strong>
                        </p>
                        <p>
                            You're about to delete the songbook
                            <code id="cascade-delete-abbr-display"></code>
                            <strong>and every song in it</strong>
                            (<span id="cascade-delete-song-count">0</span> songs)
                            <strong>and every credit / chord / tag / translation</strong>
                            that referenced those songs.
                        </p>
                        <hr>
                        <p class="mb-2">To confirm, type the abbreviation
                            <code id="cascade-delete-abbr-echo"></code>
                            into the field below:
                        </p>
                        <input type="text" name="confirm_abbr" id="cascade-delete-confirm"
                               class="form-control" autocomplete="off" placeholder="Abbreviation"
                               oninput="window.cascadeDeleteSyncConfirm && window.cascadeDeleteSyncConfirm()">
                    </div>
                    <div class="modal-footer" style="border-color: var(--ih-border);">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger" id="cascade-delete-submit" disabled>
                            <i class="bi bi-trash me-1"></i>Delete songbook + all songs
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openEditModal(row) {
            document.getElementById('edit-id').value                = row.id;
            document.getElementById('edit-abbr-label').textContent  = row.abbreviation;
            document.getElementById('edit-name').value              = row.name;
            /* The colour field is now wrapped in the shared colour-picker
               partial (#715), which gives the text input the id
               edit-songbook-colour-text and adds a sibling swatch.
               Setting the text input's value also fires an `input` event
               so the boot-script's text→swatch sync handler updates the
               native picker preview to match. */
            (function () {
                const colourText   = document.getElementById('edit-songbook-colour-text');
                const colourSwatch = document.querySelector(
                    '[data-colour-picker-id="edit-songbook-colour"] .colour-picker-swatch'
                );
                const v = row.colour || '';
                if (colourText) {
                    colourText.value = v;
                    colourText.dispatchEvent(new Event('input', { bubbles: true }));
                }
                if (colourSwatch && /^#[0-9A-Fa-f]{6}$/.test(v)) {
                    colourSwatch.value = v.toLowerCase();
                }
            })();
            document.getElementById('edit-order').value             = row.display_order || 0;

            /* #502 metadata fields */
            document.getElementById('edit-is-official').checked     = !!row.is_official;
            document.getElementById('edit-publisher').value         = row.publisher        || '';
            document.getElementById('edit-publication-year').value  = row.publication_year || '';
            document.getElementById('edit-copyright').value         = row.copyright        || '';
            document.getElementById('edit-affiliation').value       = row.affiliation      || '';

            /* #681 — IETF BCP 47 composite picker. The picker's
               setTag() decomposes the saved tag and pre-fills the
               three inputs (with friendly names looked up from the
               typeahead endpoints). Falls through silently if the
               picker isn't booted yet — an empty saved tag opens
               the modal with all three fields blank. */
            if (typeof window.editIetfPicker?.setTag === 'function') {
                window.editIetfPicker.setTag(row.language || '');
            }

            /* #672 — bibliographic + authority-control identifiers. The
               row payload normalises every key to '' when the source
               column was NULL (or missing entirely on a pre-migration
               deployment) so each input always receives a string. */
            document.getElementById('edit-website-url').value          = row.website_url          || '';
            document.getElementById('edit-internet-archive-url').value = row.internet_archive_url || '';
            document.getElementById('edit-wikipedia-url').value        = row.wikipedia_url        || '';
            document.getElementById('edit-wikidata-id').value          = row.wikidata_id          || '';
            document.getElementById('edit-oclc-number').value          = row.oclc_number          || '';
            document.getElementById('edit-ocn-number').value           = row.ocn_number           || '';
            document.getElementById('edit-lcp-number').value           = row.lcp_number           || '';
            document.getElementById('edit-isbn').value                 = row.isbn                 || '';
            document.getElementById('edit-ark-id').value               = row.ark_id               || '';
            document.getElementById('edit-isni-id').value              = row.isni_id              || '';
            document.getElementById('edit-viaf-id').value              = row.viaf_id              || '';
            document.getElementById('edit-lccn').value                 = row.lccn                 || '';
            document.getElementById('edit-lc-class').value             = row.lc_class             || '';

            document.getElementById('edit-new-abbr').value          = '';
            document.getElementById('edit-rename-refs').checked     = false;
            document.getElementById('edit-song-count').textContent  = row.song_count;
            document.getElementById('edit-rename-refs-wrap').style.display = row.song_count > 0 ? '' : 'none';

            /* #782 phase B — pre-fill the parent picker. Hidden id +
               relationship come from the row payload; the visible search
               input shows "Name (ABBR)" so the curator sees what's bound
               in the same shape used elsewhere on the site (canonical
               full-name-then-abbr pattern). The boot script below scopes
               the typeahead to the row currently being edited
               (exclude_id) so descendants of this row never appear as
               candidates. */
            (function () {
                const idInput  = document.getElementById('edit-parent-id');
                const txtInput = document.getElementById('edit-parent-search');
                const relSel   = document.getElementById('edit-parent-relationship');
                if (idInput)  idInput.value  = row.parent_songbook_id ? String(row.parent_songbook_id) : '';
                if (txtInput) {
                    const a = (row.parent_abbreviation || '').toString();
                    const n = (row.parent_name        || '').toString();
                    txtInput.value = n ? (a ? (n + ' (' + a + ')') : n) : a;
                }
                if (relSel)   relSel.value   = row.parent_relationship || '';
                /* Stash the row id so the typeahead can pass exclude_id
                   on every fetch — the server then strips this row + all
                   descendants from suggestions. */
                document.getElementById('editModal').dataset.editId = row.id || '';
            })();

            /* #782 phase C — pre-tick series-membership checkboxes from
               the row payload. The checkbox group is only present when
               the schema is live; uncheck-all first so opening row B
               after row A doesn't carry over A's ticks. */
            (function () {
                const group = document.getElementById('edit-series-checkbox-group');
                if (!group) return;
                group.querySelectorAll('input[type=checkbox]').forEach(cb => {
                    cb.checked = false;
                });
                const ids = Array.isArray(row.series_membership_ids) ? row.series_membership_ids : [];
                ids.forEach(sid => {
                    const cb = group.querySelector('input[value="' + Number(sid) + '"]');
                    if (cb) cb.checked = true;
                });
            })();

            /* #831 — populate the Compilers list from row.compilers.
               The container is only present when $hasCompilersSchema is
               true (so a pre-migration deployment skips this entire
               block silently). Each item gets a row with hidden
               person-id, visible name+slug, optional note, drag handle
               and remove button. The row builder is shared by the
               typeahead-add handler below. */
            (function () {
                const container = document.getElementById('edit-compilers-rows');
                if (!container) return;
                container.innerHTML = '';
                const compilers = Array.isArray(row.compilers) ? row.compilers : [];
                compilers.forEach(c => {
                    container.appendChild(window._iHymnsBuildCompilerRow({
                        personId:   c.person_id || 0,
                        personName: c.person_name || '',
                        personSlug: c.person_slug || '',
                        note:       c.note || '',
                    }));
                });
                /* Clear the add-search field too so reopening the modal
                   doesn't leave a stale half-typed name behind. */
                const addInput = document.getElementById('edit-compiler-add-search');
                if (addInput) addInput.value = '';
            })();

            /* #832 — populate the alternative-names list from
               row.alternative_names. Container only exists when the
               schema probe says yes; clear + repopulate on every
               open so opening row B after row A doesn't carry A's
               alts over. */
            (function () {
                const container = document.getElementById('edit-alt-names-rows');
                if (!container) return;
                container.innerHTML = '';
                const alts = Array.isArray(row.alternative_names) ? row.alternative_names : [];
                alts.forEach(a => {
                    container.appendChild(window._iHymnsBuildAltNameRow({
                        title: a.title || '',
                        note:  a.note  || '',
                    }));
                });
                const addInput = document.getElementById('edit-alt-name-add-input');
                if (addInput) addInput.value = '';
            })();

            /* #833 — populate the external-links list from
               row.external_links. Schema-gated container; clear +
               repopulate on every modal-open. */
            (function () {
                const container = document.getElementById('edit-ext-links-rows');
                if (!container || typeof window._iHymnsBuildExtLinkRow !== 'function') return;
                container.innerHTML = '';
                const links = Array.isArray(row.external_links) ? row.external_links : [];
                links.forEach(l => {
                    container.appendChild(window._iHymnsBuildExtLinkRow({
                        typeId:   l.type_id || 0,
                        url:      l.url || '',
                        note:     l.note || '',
                        verified: !!l.verified,
                    }));
                });
            })();

            /* Stash the current row's abbreviation on the modal for the
               "Pick distinctive colour" button below — the button reads
               it and POSTs to /manage/songbooks?action=pick_colour. */
            document.getElementById('editModal').dataset.editAbbr = row.abbreviation || '';
            new bootstrap.Modal(document.getElementById('editModal')).show();
        }

        /* Per-songbook auto-colour button (#772). Wires once on DOM
           ready; reads the current edit-modal's data-edit-abbr to know
           which row it's running for, then POSTs to
           /manage/songbooks?action=pick_colour. The handler echoes a
           hex; we write it into the colour-picker text input and fire
           an `input` event so the picker partial's swatch + preview
           update via the same path manual edits use. */
        document.addEventListener('DOMContentLoaded', function () {
            var btn = document.getElementById('edit-pick-colour-btn');
            if (!btn) return;
            btn.addEventListener('click', async function () {
                var modal = document.getElementById('editModal');
                var abbr = modal?.dataset?.editAbbr || '';
                btn.disabled = true;
                var originalHtml = btn.innerHTML;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
                try {
                    var url = '/manage/songbooks?action=pick_colour'
                            + (abbr ? ('&abbr=' + encodeURIComponent(abbr)) : '');
                    var r = await fetch(url, { credentials: 'same-origin' });
                    var d = await r.json().catch(function () { return {}; });
                    if (!r.ok || !d || !d.colour) {
                        alert((d && d.error) || 'Could not pick a colour.');
                        return;
                    }
                    var colourText = document.querySelector(
                        '[data-colour-picker-id="edit-songbook-colour"] .colour-picker-text'
                    );
                    var colourSwatch = document.querySelector(
                        '[data-colour-picker-id="edit-songbook-colour"] .colour-picker-swatch'
                    );
                    if (colourText) {
                        colourText.value = d.colour;
                        colourText.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                    if (colourSwatch && /^#[0-9A-Fa-f]{6}$/.test(d.colour)) {
                        colourSwatch.value = d.colour.toLowerCase();
                    }
                } catch (err) {
                    console.error('[songbooks pick_colour]', err);
                    alert('Could not pick a colour.');
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                }
            });
        });

        function openDeleteModal(row) {
            document.getElementById('delete-id').value = row.id;
            document.getElementById('delete-abbr-label').textContent = row.abbreviation;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }

        /* Cascade-delete modal opener (#706). Stashes the expected
           abbreviation on window so the input's `oninput` handler can
           gate the submit button until the typed value matches exactly. */
        function openCascadeDeleteModal(row) {
            window.cascadeDeleteAbbr = row.abbreviation;
            document.getElementById('cascade-delete-id').value = row.id;
            document.getElementById('cascade-delete-abbr-label').textContent   = row.abbreviation;
            document.getElementById('cascade-delete-abbr-display').textContent = row.abbreviation;
            document.getElementById('cascade-delete-abbr-echo').textContent    = row.abbreviation;
            document.getElementById('cascade-delete-song-count').textContent   = row.song_count;
            document.getElementById('cascade-delete-confirm').value            = '';
            document.getElementById('cascade-delete-submit').disabled          = true;
            new bootstrap.Modal(document.getElementById('cascadeDeleteModal')).show();
        }

        /* Submit-gate sync — called by the typed-confirm input's oninput.
           The submit button only enables when the typed value matches the
           expected abbreviation EXACTLY (case-sensitive). */
        window.cascadeDeleteSyncConfirm = function () {
            var typed   = document.getElementById('cascade-delete-confirm').value;
            var expect  = window.cascadeDeleteAbbr || '';
            var submit  = document.getElementById('cascade-delete-submit');
            submit.disabled = typed !== expect;
        };
    </script>

    <!-- Sortable table headers (#644). -->
    <script type="module">
        import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';
        bootSortableTables();
    </script>

    <!-- IETF BCP 47 composite language picker (#681). Boots the
         create-form picker on page load (always blank initially)
         and the edit-modal picker on first edit click — exposing
         the latter as window.editIetfPicker so openEditModal() can
         call setTag() with the row's saved tag. -->
    <script type="module">
        import { bootIetfLanguagePicker } from '/js/modules/ietf-language-picker.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/ietf-language-picker.js') ?>';
        const createPicker = document.querySelector('[data-ietf-picker-id="create-songbook"]');
        if (createPicker) bootIetfLanguagePicker(createPicker);
        const editPicker   = document.querySelector('[data-ietf-picker-id="edit-songbook"]');
        if (editPicker)   window.editIetfPicker = bootIetfLanguagePicker(editPicker);
    </script>

    <!-- Colour picker boot (#715). Wires the native swatch ↔ hex
         text two-way binding for every .colour-picker on the page —
         currently the create form's Colour field + the edit modal's
         Colour field. Both render via the shared
         manage/includes/partials/colour-picker.php. -->
    <script type="module">
        import { bootColourPickers } from '/js/modules/colour-picker.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/colour-picker.js') ?>';
        bootColourPickers();
    </script>

    <!-- Drag-and-drop reorder + Sort by Name/Abbr presets (#674).
         Vanilla HTML5 Drag-and-Drop on the songbook list table; no
         third-party library. Touch users can still type a number into
         each row's Order input + hit Save (the existing path) — a
         touch-driven reorder UX would need significantly more code
         and isn't blocking. The four sort-preset buttons renumber
         in 10-spaced steps without saving so the curator can review
         and back out. -->
    <style>
        .songbook-drag-handle {
            cursor: grab;
            color: var(--text-muted);
            font-size: 1.05rem;
            /* Vendor-prefixed user-select to suppress text selection
               on Safari/iOS during a drag — same 4-line convention
               used elsewhere (see admin.css drag-handle, #668). */
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }
        .songbook-drag-handle:hover { color: var(--accent-solid); }
        .songbook-row.dragging { opacity: 0.4; }
        .songbook-row.drop-above td { box-shadow: 0 -2px 0 var(--accent-solid) inset; }
        .songbook-row.drop-below td { box-shadow: 0  2px 0 var(--accent-solid) inset; }
    </style>
    <script>
    (function () {
        const tbody = document.querySelector('#songbook-list-table tbody');
        if (!tbody) return;

        /* Live snapshot of the current row order. Recomputed on every
           DOM read because drag-drop reshuffles in place. */
        const rows = () => Array.from(tbody.querySelectorAll('tr.songbook-row'));

        /* Renumber the DisplayOrder <input>s in 10-spaced steps based
           on the current visual row order. The "Save display order"
           submit button picks them up via display_order[<id>]. */
        const renumber = () => {
            rows().forEach((tr, i) => {
                const input = tr.querySelector('input[name^="display_order"]');
                if (input) input.value = (i + 1) * 10;
            });
        };

        /* ----- Sort presets ----- */
        /* Strip a leading "The "/"A "/"An " (case-insensitive, with
           trailing whitespace) so "The Church Hymnal" sorts among the
           C's. Same convention as libraries / WikiData / iTunes.
           Other-language articles (Spanish "El", French "Le/La", …)
           are out of scope for v1; flag in the issue comment if a
           curator hits the limit (#674). */
        const stripArticle = (s) =>
            (s || '').replace(/^\s*(the|an|a)\s+/i, '').toLowerCase();

        /* "Miscellaneous" (abbreviation: Misc) is a catch-all for
           orphan / outside-canon songs. It must always sit at the
           bottom of every name- or abbr-sort regardless of direction
           — otherwise it ends up among the M's (asc) or at the very
           top (desc) and confuses curators. (#717) */
        const isMiscRow = (tr) =>
            (tr.dataset.sortAbbr || '').toLowerCase() === 'misc';

        const sortByKey = (keyFn, dir) => {
            const sorted = rows().sort((a, b) => {
                /* Misc-pinned-bottom rule: any Misc row always sorts
                   AFTER any non-Misc row. Two Misc rows fall back to
                   the regular key compare (rare in practice — there's
                   normally only one Misc songbook). */
                const aMisc = isMiscRow(a);
                const bMisc = isMiscRow(b);
                if (aMisc && !bMisc) return 1;
                if (!aMisc && bMisc) return -1;
                const cmp = keyFn(a).localeCompare(keyFn(b));
                return dir === 'asc' ? cmp : -cmp;
            });
            sorted.forEach(tr => tbody.appendChild(tr));
            renumber();
        };
        document.querySelectorAll('[data-sort-preset]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const [field, dir] = btn.dataset.sortPreset.split(':');
                const keyFn = field === 'name'
                    ? (tr) => stripArticle(tr.dataset.sortName)
                    : (tr) => (tr.dataset.sortAbbr || '').toLowerCase();
                sortByKey(keyFn, dir);
            });
        });

        /* ----- Drag and drop -----
           HTML5 D&D: source row gets draggable=true while the user is
           pressing the handle, source emits dragstart, every other
           row's dragover decides whether to insert above or below
           the cursor based on the row's vertical midpoint. Visual
           feedback via .drop-above / .drop-below pseudo-classes that
           paint a 2px accent bar on the relevant edge. */
        let draggedRow = null;
        const clearDropMarkers = () => {
            rows().forEach(tr => tr.classList.remove('drop-above', 'drop-below'));
        };

        rows().forEach(tr => {
            const handle = tr.querySelector('.songbook-drag-handle');
            if (!handle) return;

            /* Only enable draggable while the user is pressing the
               handle so clicking elsewhere on the row (e.g. into the
               Order input) doesn't kick off an accidental drag. */
            handle.addEventListener('mousedown', () => { tr.draggable = true; });
            tr.addEventListener('mouseup',   () => { tr.draggable = false; });

            tr.addEventListener('dragstart', (e) => {
                draggedRow = tr;
                tr.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
                /* Set a payload so Firefox actually fires drag events. */
                e.dataTransfer.setData('text/plain', tr.dataset.rowId || '');
            });

            tr.addEventListener('dragover', (e) => {
                if (!draggedRow || draggedRow === tr) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                const rect = tr.getBoundingClientRect();
                const above = e.clientY < (rect.top + rect.height / 2);
                clearDropMarkers();
                tr.classList.add(above ? 'drop-above' : 'drop-below');
            });

            tr.addEventListener('drop', (e) => {
                if (!draggedRow || draggedRow === tr) return;
                e.preventDefault();
                const rect = tr.getBoundingClientRect();
                const above = e.clientY < (rect.top + rect.height / 2);
                if (above)  tr.parentNode.insertBefore(draggedRow, tr);
                else        tr.parentNode.insertBefore(draggedRow, tr.nextSibling);
            });

            tr.addEventListener('dragend', () => {
                if (draggedRow) draggedRow.classList.remove('dragging');
                draggedRow = null;
                tr.draggable = false;
                clearDropMarkers();
                renumber();
            });
        });
    })();
    </script>

    <!-- Affiliation typeahead (#670).
         A single <datalist> shared by every input.js-affiliation-input on
         the page (the create form's affiliation field + the edit modal's
         affiliation field). Each `input` event runs the same debounced
         fetch against /manage/songbooks?action=affiliation_search and
         rebuilds the datalist <option>s. The browser handles the dropdown
         UI natively, so there's no third-party autocomplete library and
         the user can still type a brand-new value if no match exists —
         it lands in Affiliation on save and the server-side handler
         self-registers it in tblSongbookAffiliations for next time. -->
    <datalist id="affiliations-datalist"></datalist>
    <script>
    (function () {
        const inputs   = document.querySelectorAll('.js-affiliation-input');
        const datalist = document.getElementById('affiliations-datalist');
        if (!inputs.length || !datalist) return;

        let debounceTimer = null;
        let inflight      = null;
        const lookup = (query) => {
            if (inflight) inflight.abort();
            const ac = new AbortController();
            inflight = ac;
            const url = '/manage/songbooks?action=affiliation_search&q=' +
                        encodeURIComponent(query) + '&limit=20';
            fetch(url, { credentials: 'same-origin', signal: ac.signal })
                .then(r => r.ok ? r.json() : { suggestions: [] })
                .then(data => {
                    const list = Array.isArray(data.suggestions) ? data.suggestions : [];
                    /* Rebuild the datalist with one <option> per match.
                       The `value` is what the input gets when picked;
                       the `label` carries the usage count so curators
                       can see how often each affiliation is in play. */
                    datalist.innerHTML = list.map(s => {
                        const v = (s.name || '').replace(/"/g, '&quot;');
                        const c = (typeof s.songbookCount === 'number') ? s.songbookCount : 0;
                        const tag = c > 0 ? ' (' + c + ' songbook' + (c === 1 ? '' : 's') + ')' : '';
                        return '<option value="' + v + '" label="' + v + tag + '"></option>';
                    }).join('');
                })
                .catch(err => {
                    if (err.name !== 'AbortError') {
                        /* Silent — the typeahead is a nicety, not critical
                           path. Server-side errors are already in error_log
                           via affiliation_search. */
                    }
                });
        };

        inputs.forEach(input => {
            input.addEventListener('input', () => {
                const q = input.value.trim();
                /* Clear datalist when the input is empty so the dropdown
                   doesn't show stale matches from a prior word. */
                if (q === '') {
                    datalist.innerHTML = '';
                    return;
                }
                clearTimeout(debounceTimer);
                /* 200 ms is the same debounce the editor's tag-search and
                   credit-search use — feels instant but coalesces typing
                   bursts into a single request. */
                debounceTimer = setTimeout(() => lookup(q), 200);
            });
            /* Also trigger on focus when there's already a value (e.g.
               opening the edit modal on a row that has an affiliation
               populated) so the dropdown shows immediately on click. */
            input.addEventListener('focus', () => {
                if (input.value.trim() !== '') lookup(input.value.trim());
            });
        });
    })();
    </script>

    <!-- Parent-songbook typeahead (#782 phase B / parent-typeahead).
         Same shape as the affiliation typeahead above: <datalist>
         driven by a debounced fetch against
         /manage/songbooks?action=parent_search. The picker carries
         two pieces of state — the visible "ABBR — Name" string in
         #edit-parent-search and the hidden numeric id in
         #edit-parent-id. When the curator picks an option from the
         datalist (or types a value matching one), the input event
         hands the option's data-id back into the hidden field; if
         they then keep typing the parent_id resets to '' so a
         half-typed name can't masquerade as a saved selection.
         The Clear button wipes both fields + the relationship enum. -->
    <script>
    (function () {
        const txt = document.getElementById('edit-parent-search');
        const hid = document.getElementById('edit-parent-id');
        const rel = document.getElementById('edit-parent-relationship');
        const clr = document.getElementById('edit-parent-clear');
        const dl  = document.getElementById('edit-parent-datalist');
        if (!txt || !hid || !dl) return;

        /* Map of the most recent suggestion list by visible label.
           When the user picks an option, the input event fires with
           input.value === one of these labels — we look up the id
           in this map and write it into the hidden field. */
        let labelToId = new Map();
        let inflight  = null;
        let debounce  = null;

        const lookup = (query) => {
            if (inflight) inflight.abort();
            const ac = new AbortController();
            inflight = ac;
            const excludeId = document.getElementById('editModal')?.dataset?.editId || '';
            const url = '/manage/songbooks?action=parent_search'
                      + '&q=' + encodeURIComponent(query)
                      + '&exclude_id=' + encodeURIComponent(excludeId)
                      + '&limit=20';
            fetch(url, { credentials: 'same-origin', signal: ac.signal })
                .then(r => r.ok ? r.json() : { suggestions: [] })
                .then(data => {
                    const list = Array.isArray(data.suggestions) ? data.suggestions : [];
                    labelToId = new Map();
                    /* Format suggestions as "Full Name (ABBR)" — same shape used
                       elsewhere on the site for songbook references. (was the
                       reverse "ABBR — Full Name" which the user reported as
                       inconsistent with the rest of the UI.) */
                    dl.innerHTML = list.map(s => {
                        const a   = (s.abbreviation || '').replace(/"/g, '&quot;');
                        const n   = (s.name         || '').replace(/"/g, '&quot;');
                        const cc  = (typeof s.childCount === 'number') ? s.childCount : 0;
                        const lbl = n ? (a ? (n + ' (' + a + ')') : n) : a;
                        labelToId.set(lbl, String(s.id));
                        const tail = cc > 0 ? ' — ' + cc + ' child' + (cc === 1 ? '' : 'ren') : '';
                        return '<option value="' + lbl + '" label="' + lbl + tail + '"></option>';
                    }).join('');
                })
                .catch(err => { if (err.name !== 'AbortError') { /* silent */ } });
        };

        txt.addEventListener('input', () => {
            const v = txt.value.trim();
            /* Picked-from-datalist sync: an exact label match means the
               curator selected one of the suggestions, so commit its id
               to the hidden field. Anything else clears the id so we
               never save a parent_songbook_id that doesn't match what's
               visible to the curator. */
            const id = labelToId.get(v);
            hid.value = id || '';
            if (v === '') {
                dl.innerHTML = '';
                return;
            }
            clearTimeout(debounce);
            debounce = setTimeout(() => lookup(v), 200);
        });
        txt.addEventListener('focus', () => {
            /* Always run an empty-query lookup on focus — surfaces the
               top-N most-used parents as soon as the picker opens, even
               before the curator types anything. */
            lookup(txt.value.trim());
        });

        if (clr) {
            clr.addEventListener('click', () => {
                txt.value = '';
                hid.value = '';
                if (rel) rel.value = '';
                dl.innerHTML = '';
                txt.focus();
            });
        }
    })();
    </script>

    <!-- Compilers picker (#831). Reuses the parent-typeahead's pattern
         (debounced fetch → datalist) but instead of a single binding
         it appends one row per pick to #edit-compilers-rows. Each row
         carries hidden compiler_person_ids[] / compiler_notes[] inputs
         plus a per-row note input + remove button. SortOrder is
         derived from DOM index on save (the POST handler treats array
         index as the order). -->
    <script>
    (function () {
        const addInput = document.getElementById('edit-compiler-add-search');
        const addBtn   = document.getElementById('edit-compiler-add-btn');
        const dl       = document.getElementById('edit-compiler-datalist');
        const rowsEl   = document.getElementById('edit-compilers-rows');
        if (!addInput || !rowsEl) return;  /* schema not live → block absent */

        /* Live-fetch suggestions as the curator types. Map of
           visible label → person id captured per fetch so the
           "Add" button can resolve the typed value. */
        let labelToId = new Map();
        let inflight  = null;
        let debounce  = null;

        const lookup = (query) => {
            if (inflight) inflight.abort();
            const ac = new AbortController();
            inflight = ac;
            const url = '/manage/songbooks?action=compiler_search'
                      + '&q=' + encodeURIComponent(query)
                      + '&limit=20';
            fetch(url, { credentials: 'same-origin', signal: ac.signal })
                .then(r => r.ok ? r.json() : { suggestions: [] })
                .then(data => {
                    const list = Array.isArray(data.suggestions) ? data.suggestions : [];
                    labelToId = new Map();
                    dl.innerHTML = list.map(s => {
                        const n = (s.name || '').replace(/"/g, '&quot;');
                        labelToId.set(n, { id: s.id, slug: s.slug || '' });
                        return '<option value="' + n + '"></option>';
                    }).join('');
                })
                .catch(err => { if (err.name !== 'AbortError') { /* silent */ } });
        };

        addInput.addEventListener('input', () => {
            const v = addInput.value.trim();
            if (v === '') { dl.innerHTML = ''; return; }
            clearTimeout(debounce);
            debounce = setTimeout(() => lookup(v), 200);
        });
        addInput.addEventListener('focus', () => lookup(addInput.value.trim()));

        /* Add button — commits the currently-typed name (must be an
           exact match against one of the fetched suggestions; we never
           save a free-text id-less compiler since the FK requires a
           real tblCreditPeople row). */
        const commitAdd = () => {
            const v = addInput.value.trim();
            if (v === '') return;
            const hit = labelToId.get(v);
            if (!hit) {
                /* Soft warn — don't trap the curator. They can either
                   pick from the dropdown or visit Manage People to
                   register the name first. */
                addInput.classList.add('is-invalid');
                setTimeout(() => addInput.classList.remove('is-invalid'), 1500);
                return;
            }
            /* Skip if already added — UNIQUE (SongbookId, CreditPersonId)
               on the table would catch it but we may as well be friendly. */
            const existing = rowsEl.querySelector('input[name="compiler_person_ids[]"][value="' + Number(hit.id) + '"]');
            if (existing) {
                addInput.value = '';
                return;
            }
            rowsEl.appendChild(window._iHymnsBuildCompilerRow({
                personId:   hit.id,
                personName: v,
                personSlug: hit.slug,
                note:       '',
            }));
            addInput.value = '';
            dl.innerHTML = '';
        };
        if (addBtn) addBtn.addEventListener('click', commitAdd);
        /* Enter inside the input also commits (matches the shape of the
           tag chip-list in the Song Editor). Suppress the form submit
           that Enter would otherwise trigger on a modal form. */
        addInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                commitAdd();
            }
        });

        /* Row builder — exposed on window so openEditModal() above can
           use it too without re-defining the markup. Kept inside this
           IIFE's closure-free namespace by attaching to window. */
        window._iHymnsBuildCompilerRow = function (data) {
            const card = document.createElement('div');
            card.className = 'card bg-dark border-secondary';
            const pid = Number(data.personId) || 0;
            const nm  = String(data.personName || '');
            const sl  = String(data.personSlug || '');
            const nt  = String(data.note || '');
            const personLink = sl
                ? '<a href="/people/' + encodeURIComponent(sl) + '" target="_blank" rel="noopener" class="text-info text-decoration-none">'
                  + escapeHtml(nm) + ' <i class="bi bi-box-arrow-up-right small" aria-hidden="true"></i></a>'
                : escapeHtml(nm);
            card.innerHTML =
                '<div class="card-body py-2">' +
                  '<div class="d-flex align-items-center gap-2">' +
                    '<i class="bi bi-grip-vertical text-muted" aria-hidden="true"></i>' +
                    '<div class="flex-grow-1">' +
                      '<div class="small fw-semibold">' + personLink + '</div>' +
                      '<input type="hidden" name="compiler_person_ids[]" value="' + pid + '">' +
                      '<input type="text" class="form-control form-control-sm mt-1" ' +
                              'name="compiler_notes[]" placeholder="Optional note (e.g. \'5th edition\')" ' +
                              'maxlength="255" value="' + escapeHtml(nt) + '">' +
                    '</div>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger" ' +
                            'data-action="remove-compiler" title="Remove this compiler">' +
                      '<i class="bi bi-x-lg"></i>' +
                    '</button>' +
                  '</div>' +
                '</div>';
            card.querySelector('[data-action=remove-compiler]')
                .addEventListener('click', () => card.remove());
            return card;
        };

        /* Tiny shared escapeHtml — local copy to avoid pulling in a
           module dependency for this one inline script. */
        function escapeHtml(s) {
            return String(s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }
    })();
    </script>

    <!-- Alternative-names chip-list editor (#832). Pure-text picker —
         no FK, no typeahead — just type-and-Enter to add. Each row
         carries hidden alt_name_titles[] / alt_name_notes[] inputs,
         a Note input, and a remove button. -->
    <script>
    (function () {
        const addInput = document.getElementById('edit-alt-name-add-input');
        const addBtn   = document.getElementById('edit-alt-name-add-btn');
        const rowsEl   = document.getElementById('edit-alt-names-rows');
        if (!addInput || !rowsEl) return;  /* schema not live → block absent */

        const commitAdd = () => {
            const v = addInput.value.trim();
            if (v === '') return;
            /* Skip if already added (case-insensitive). UNIQUE
               (SongbookId, Title) on the table would catch it but a
               soft client-side guard is friendlier. */
            const existing = Array.from(rowsEl.querySelectorAll('input[name="alt_name_titles[]"]'))
                .map(i => (i.value || '').toLowerCase());
            if (existing.includes(v.toLowerCase())) {
                addInput.classList.add('is-invalid');
                setTimeout(() => addInput.classList.remove('is-invalid'), 1500);
                return;
            }
            rowsEl.appendChild(window._iHymnsBuildAltNameRow({
                title: v,
                note:  '',
            }));
            addInput.value = '';
        };
        if (addBtn) addBtn.addEventListener('click', commitAdd);
        addInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                commitAdd();
            }
        });

        /* Shared row-builder, also used by openEditModal() above to
           rebuild the list on modal-open. */
        window._iHymnsBuildAltNameRow = function (data) {
            const card = document.createElement('div');
            card.className = 'card bg-dark border-secondary';
            const t  = String(data.title || '');
            const nt = String(data.note  || '');
            card.innerHTML =
                '<div class="card-body py-2">' +
                  '<div class="d-flex align-items-center gap-2">' +
                    '<i class="bi bi-grip-vertical text-muted" aria-hidden="true"></i>' +
                    '<div class="flex-grow-1">' +
                      '<input type="text" class="form-control form-control-sm fw-semibold" ' +
                              'name="alt_name_titles[]" maxlength="255" required value="' + escapeHtml(t) + '">' +
                      '<input type="text" class="form-control form-control-sm mt-1" ' +
                              'name="alt_name_notes[]" placeholder="Optional note (e.g. \'older spelling\')" ' +
                              'maxlength="255" value="' + escapeHtml(nt) + '">' +
                    '</div>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger" ' +
                            'data-action="remove-alt-name" title="Remove this alt name">' +
                      '<i class="bi bi-x-lg"></i>' +
                    '</button>' +
                  '</div>' +
                '</div>';
            card.querySelector('[data-action=remove-alt-name]')
                .addEventListener('click', () => card.remove());
            return card;
        };

        function escapeHtml(s) {
            return String(s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
    })();
    </script>

    <!-- External-links card-list editor (#833). Each row carries:
         - hidden ext_link_type_ids[]   (FK to tblExternalLinkTypes.Id)
         - text   ext_link_urls[]
         - text   ext_link_notes[]
         - checkbox ext_link_verified[]
         The link-type dropdown is grouped by Category and uses the
         seeded list pre-loaded into window._iHymnsLinkTypes. -->
    <script>
    (function () {
        const addBtn = document.getElementById('edit-ext-link-add-btn');
        const rowsEl = document.getElementById('edit-ext-links-rows');
        if (!addBtn || !rowsEl) return;  /* schema not live → block absent */

        const types = Array.isArray(window._iHymnsLinkTypes) ? window._iHymnsLinkTypes : [];

        /* Group types by Category for the <optgroup> structure. */
        const byCat = {};
        types.forEach(t => {
            const cat = t.category || 'other';
            if (!byCat[cat]) byCat[cat] = [];
            byCat[cat].push(t);
        });
        const catLabels = {
            'official':    'Official',
            'information': 'Information',
            'read':        'Read',
            'sheet-music': 'Sheet music',
            'listen':      'Listen',
            'watch':       'Watch',
            'purchase':    'Purchase',
            'authority':   'Authority',
            'social':      'Social',
            'other':       'Other',
        };
        const catOrder = ['official','information','read','sheet-music','listen','watch','purchase','authority','social','other'];

        function buildSelect(selectedId) {
            let html = '<select class="form-select form-select-sm" name="ext_link_type_ids[]" required>';
            html += '<option value="">— pick a link type —</option>';
            catOrder.forEach(cat => {
                if (!byCat[cat] || byCat[cat].length === 0) return;
                html += '<optgroup label="' + escapeHtml(catLabels[cat] || cat) + '">';
                byCat[cat].forEach(t => {
                    const sel = (Number(selectedId) === Number(t.id)) ? ' selected' : '';
                    html += '<option value="' + Number(t.id) + '"' + sel + '>' + escapeHtml(t.name) + '</option>';
                });
                html += '</optgroup>';
            });
            html += '</select>';
            return html;
        }

        window._iHymnsBuildExtLinkRow = function (data) {
            const card = document.createElement('div');
            card.className = 'card bg-dark border-secondary';
            const url  = String(data.url || '');
            const note = String(data.note || '');
            const ver  = data.verified ? 'checked' : '';
            card.innerHTML =
                '<div class="card-body py-2">' +
                  '<div class="d-flex align-items-start gap-2">' +
                    '<i class="bi bi-grip-vertical text-muted mt-2" aria-hidden="true"></i>' +
                    '<div class="flex-grow-1">' +
                      '<div class="row g-2 mb-1">' +
                        '<div class="col-md-5">' + buildSelect(data.typeId || 0) + '</div>' +
                        '<div class="col-md-7">' +
                          '<input type="url" class="form-control form-control-sm" ' +
                                  'name="ext_link_urls[]" required maxlength="2048" ' +
                                  'placeholder="https://…" value="' + escapeHtml(url) + '">' +
                        '</div>' +
                      '</div>' +
                      '<div class="row g-2">' +
                        '<div class="col-md-9">' +
                          '<input type="text" class="form-control form-control-sm" ' +
                                  'name="ext_link_notes[]" maxlength="255" ' +
                                  'placeholder="Optional note (e.g. \'1900 first edition\')" ' +
                                  'value="' + escapeHtml(note) + '">' +
                        '</div>' +
                        '<div class="col-md-3 d-flex align-items-center">' +
                          '<div class="form-check small">' +
                            '<input class="form-check-input" type="checkbox" ' +
                                    'name="ext_link_verified[]" value="1" ' + ver + '>' +
                            '<label class="form-check-label">Verified</label>' +
                          '</div>' +
                        '</div>' +
                      '</div>' +
                    '</div>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger" ' +
                            'data-action="remove-ext-link" title="Remove this link">' +
                      '<i class="bi bi-x-lg"></i>' +
                    '</button>' +
                  '</div>' +
                '</div>';
            card.querySelector('[data-action=remove-ext-link]')
                .addEventListener('click', () => card.remove());
            /* #841 — global URL → provider auto-detect. The module is
               loaded by manage/includes/head-libs.php on every admin
               page, so the wiring is the same one-liner here as in
               every other edit modal. */
            if (window.iHymnsLinkDetect && typeof window.iHymnsLinkDetect.attachAutoDetect === 'function') {
                window.iHymnsLinkDetect.attachAutoDetect(card);
            }
            return card;
        };

        addBtn.addEventListener('click', () => {
            rowsEl.appendChild(window._iHymnsBuildExtLinkRow({
                typeId: 0, url: '', note: '', verified: false,
            }));
        });

        function escapeHtml(s) {
            return String(s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
    })();
    </script>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
