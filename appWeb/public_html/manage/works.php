<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Works (#840)
 *
 * CRUD surface for tblWorks + tblWorkSongs membership + the
 * tblWorkExternalLinks panel. Models the MusicBrainz Work entity:
 * one Work groups multiple tblSongs rows that represent the same
 * underlying composition across different songbooks / arrangements
 * / translations.
 *
 * Supports unlimited nesting via tblWorks.ParentWorkId — original
 * Work → arrangement → translation → … (cycle-prevention enforced
 * application-side at update time).
 *
 * Gated by `manage_works`; pre-migration safe — probes tblWorks on
 * every page load and renders a friendly CTA pointing at
 * /manage/setup-database when missing.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_soft_delete.php';   /* #1694 */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'external_link_helpers.php';
/* Places adoption helper — exposes placeColumnExists() so the
   create / update paths can persist OriginCityId alongside the
   legacy OriginCity display string only when the places-adoption
   migration has landed. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'places.php';
/* #1741 P3 — the canonical ISWC fold now lives in the shared
   includes/identifier_normalize.php module (ihymns_canonical_iswc()) so
   the public /iswc/<code> alias route can reuse the exact same fold this
   page uses to validate curator input. $validateIswc below delegates to
   it rather than owning the only copy (rule #22 — one fold, not two). */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'identifier_normalize.php';
/* #1741 P4b — the work-identifier vocabulary (CCLI/BOWI/MusicBrainz-Work
   shape validation, mediaIdentifierWorkValidate()) and the tune
   find-or-create funnel (tuneFindOrCreateByName()) this page's new fields
   need. Neither existed before P4b. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'media_identifiers.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'tune_helpers.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_works', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_works required</h1></body></html>';
    exit;
}
$activePage = 'works';

$error   = '';
$success = '';
$db      = getDbMysqli();
$csrf    = csrfToken();

/* ---- Helpers ---- */

/**
 * URL-safe lowercase slug from a free-text title. ASCII-only —
 * non-ASCII characters drop. Multiple separators collapse to one
 * hyphen; leading/trailing hyphens are stripped.
 */
$slugFor = static function (string $name): string {
    $ascii = (string)preg_replace('/[^A-Za-z0-9]+/u', '-', $name);
    return trim(strtolower($ascii), '-');
};

/**
 * Validate the ISWC shape: T-NNN.NNN.NNN-C (15 chars). The check
 * digit isn't recomputed (mod-10/11 schemes vary by region); we
 * trust the curator and shape-validate only. Empty string is
 * valid (ISWC is optional).
 *
 * #1741 P3 — delegates to the shared ihymns_canonical_iswc() fold
 * (includes/identifier_normalize.php) rather than owning its own copy
 * (rule #22 — one fold, not two); the closure's name/signature/return
 * contract ('' valid-empty, null malformed, canonical otherwise) is kept
 * identical so its call sites below are unchanged.
 */
$validateIswc = static function (string $raw): ?string {
    return ihymns_canonical_iswc($raw);
};

/* Schema probe — render friendly CTA when the migration hasn't run. */
$hasSchema = false;
try {
    $probe = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblWorks' LIMIT 1"
    );
    $probe->execute();
    $hasSchema = $probe->get_result()->fetch_row() !== null;
    $probe->close();
} catch (\Throwable $e) {
    error_log('[works] schema probe failed: ' . $e->getMessage());
}

/* #1741 P4b — one cached probe for the 9 P1/D5 "extra" tblWorks columns
   plus the pre-existing #1066 MusicBrainzWorkMBID rider column. Computed
   ONCE per request (§0.3.2's cached-per-column idiom, generalised from
   placeColumnExists()'s single-column shape used elsewhere on this page)
   and reused by the list SELECT below AND by both the create/update save
   paths — a request never needs to ask INFORMATION_SCHEMA about the same
   ten columns twice. Bowi is included in the SAME probe as the other
   eight but — per its own migration (migrate-work-bowi.php, #1741 D5) —
   is genuinely independent: an install can have one without the other,
   and every call site below checks `isset($worksExtraCols['Bowi'])` on
   its own merits, never assumes "P1 landed ⇒ Bowi landed". */
$worksExtraCols = [];
if ($hasSchema) {
    try {
        $extraColNames = [
            'Subtitle', 'Disambiguation', 'Ccli', 'Bowi', 'TuneName', 'TuneId',
            'FirstPublishedYear', 'CopyrightYears', 'CopyrightHolder', 'MusicBrainzWorkMBID',
        ];
        $ph    = implode(',', array_fill(0, count($extraColNames), '?'));
        $stmt  = $db->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblWorks'
                AND COLUMN_NAME IN ($ph)"
        );
        $types = str_repeat('s', count($extraColNames));
        $stmt->bind_param($types, ...$extraColNames);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $worksExtraCols[(string)$row['COLUMN_NAME']] = true;
        }
        $stmt->close();
    } catch (\Throwable $e) {
        error_log('[works] extra-cols probe failed: ' . $e->getMessage());
    }
}

/**
 * #1741 P4b — extract + validate the 9 extra tblWorks fields (+ the
 * MusicBrainzWorkMBID rider) from a $_POST-shaped array. Shared by BOTH
 * `create` and `update` so the two save paths validate every field
 * exactly once, in exactly one place (rule #35 — a second hand-typed copy
 * of this validation is the drift, not a convenience).
 *
 * CCLI validates through the shared `mediaIdentifierWorkValidate()`
 * (includes/media_identifiers.php) — NO inline `preg_match()` here
 * (rule #35: the vocabulary already exists centrally). BOWI has no
 * documented shape (`WORK_IDENTIFIER_TYPES['bowi']['validate'] === null`
 * — Luminate's exact format isn't independently confirmed, see that
 * file's doc-block), so it is length-checked only (VARCHAR(30)).
 *
 * @param array<string,mixed> $post
 * @return array{0: array<string,mixed>, 1: ?string} [$fields, $error] —
 *         $error is null on success; $fields is [] (unusable) on failure.
 */
$parseWorkExtraFields = static function (array $post): array {
    $subtitle        = mb_substr(trim((string)($post['subtitle'] ?? '')), 0, 255);
    $disambiguation  = mb_substr(trim((string)($post['disambiguation'] ?? '')), 0, 255);
    $ccliIn          = trim((string)($post['ccli'] ?? ''));
    $bowiIn          = mb_substr(trim((string)($post['bowi'] ?? '')), 0, 30);
    $tuneNameIn      = mb_substr(trim((string)($post['tune_name'] ?? '')), 0, 120);
    $firstPubIn      = trim((string)($post['first_published_year'] ?? ''));
    $copyrightYears  = mb_substr(trim((string)($post['copyright_years'] ?? '')), 0, 100);
    $copyrightHolder = mb_substr(trim((string)($post['copyright_holder'] ?? '')), 0, 255);
    $mbWorkIn        = trim((string)($post['musicbrainz_work_mbid'] ?? ''));

    if ($ccliIn !== '' && !mediaIdentifierWorkValidate('ccli', $ccliIn)) {
        return [[], 'CCLI Work Number must be numeric.'];
    }
    if ($mbWorkIn !== '' && !mediaIdentifierWorkValidate('musicbrainz-work', $mbWorkIn)) {
        return [[], 'MusicBrainz Work MBID must look like a UUID (8-4-4-4-12 hex digits).'];
    }
    $firstPublishedYear = null;
    if ($firstPubIn !== '') {
        /* SMALLINT UNSIGNED on the schema side (schema.sql:3049) —
           500..2100 keeps the same generous historical floor the column's
           own comment documents ("hymn works predate" MySQL YEAR's 1901
           floor) while still rejecting obvious typos. */
        if (!ctype_digit($firstPubIn) || (int)$firstPubIn < 500 || (int)$firstPubIn > 2100) {
            return [[], 'First published year must be a year between 500 and 2100.'];
        }
        $firstPublishedYear = (int)$firstPubIn;
    }

    return [[
        'subtitle'            => $subtitle,
        'disambiguation'      => $disambiguation,
        'ccli'                => $ccliIn,
        'bowi'                => $bowiIn,
        'tuneName'            => $tuneNameIn,
        'firstPublishedYear'  => $firstPublishedYear,
        'copyrightYears'      => $copyrightYears,
        'copyrightHolder'     => $copyrightHolder,
        'musicBrainzWorkMbid' => $mbWorkIn,
    ], null];
};

/**
 * #1741 P4b — Ccli/Bowi/MusicBrainzWorkMBID uniqueness, gated per-column
 * (an install that hasn't migrated a given column can't collide on it —
 * there's nothing to query). Mirrors the pre-existing ISWC uniqueness
 * check's shape (:309-316 today) generalised to loop over the three new
 * unique-keyed columns. `$excludeId` is null on create, the row's own Id
 * on update (same "AND Id <> ?" self-exclusion the ISWC check uses).
 *
 * @param array<string,true>  $worksExtraCols
 * @param array<string,mixed> $fields
 * @return string|null a user-facing error, or null when clear.
 */
$checkWorkExtraUniqueness = static function (\mysqli $db, array $worksExtraCols, array $fields, ?int $excludeId): ?string {
    /* Hardcoded (col,val,label) triples from fixed PHP source — the
       ONLY interpolated identifier below (`{$col}`) is drawn from this
       literal array, never from request input (rule #5's carve-out). */
    $checks = [
        ['col' => 'Ccli', 'val' => (string)$fields['ccli'], 'label' => 'CCLI Work Number'],
        ['col' => 'Bowi', 'val' => (string)$fields['bowi'], 'label' => 'BOWI'],
        ['col' => 'MusicBrainzWorkMBID', 'val' => (string)$fields['musicBrainzWorkMbid'], 'label' => 'MusicBrainz Work MBID'],
    ];
    foreach ($checks as $check) {
        $col = $check['col'];
        $val = $check['val'];
        if ($val === '' || !isset($worksExtraCols[$col])) continue;
        if ($excludeId !== null) {
            $stmt = $db->prepare("SELECT Id FROM tblWorks WHERE {$col} = ? AND Id <> ?");
            $stmt->bind_param('si', $val, $excludeId);
        } else {
            $stmt = $db->prepare("SELECT Id FROM tblWorks WHERE {$col} = ?");
            $stmt->bind_param('s', $val);
        }
        $stmt->execute();
        $used = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        if ($used) {
            return "{$check['label']} '{$val}' is already on another Work.";
        }
    }
    return null;
};

/**
 * #1741 P4b — ONE separate column-gated UPDATE persisting the extra
 * fields (the OriginCityId pattern used elsewhere on this page, §0.3.3)
 * — NEVER touches the main INSERT/UPDATE bind above/below. Each field
 * drops out of the SET list independently when its own column is absent
 * — there is no all-or-nothing branch, so a partially-applied P1/D5
 * install still gets whichever subset it has.
 *
 * TuneName + TuneId are ALWAYS written TOGETHER in this same statement
 * via `tuneFindOrCreateByName()` (includes/tune_helpers.php) — never
 * TuneName alone. That is the exact drift §2.4.3's "lockstep" requirement
 * exists to prevent: a stale TuneId sitting next to a freshly-edited
 * TuneName would silently link the Work's tune page to the WRONG tune.
 * `tuneFindOrCreateByName()` itself degrades to `null` when `tblTunes` is
 * absent (a nullable column, safe to write) — see that function's
 * doc-block for the full asymmetry.
 *
 * Nullable columns (Subtitle/Ccli/Bowi/TuneName/MusicBrainzWorkMBID)
 * store NULL rather than '' on an empty field — the UNIQUE-key
 * NULL-coexistence design documented on schema.sql's uq_ccli/uq_bowi/
 * uq_mbwork (every NULL is distinct, so many Works can share "no CCLI").
 * Disambiguation/CopyrightYears/CopyrightHolder are `NOT NULL DEFAULT
 * ''` on the schema side, so they stay plain strings, never NULL.
 *
 * @param array<string,true>  $worksExtraCols
 * @param array<string,mixed> $fields
 */
$persistWorkExtraFields = static function (\mysqli $db, array $worksExtraCols, int $workId, array $fields): void {
    $sets  = [];
    $types = '';
    $vals  = [];

    if (isset($worksExtraCols['Subtitle'])) {
        $sets[] = 'Subtitle = ?'; $types .= 's';
        $vals[] = $fields['subtitle'] !== '' ? $fields['subtitle'] : null;
    }
    if (isset($worksExtraCols['Disambiguation'])) {
        $sets[] = 'Disambiguation = ?'; $types .= 's';
        $vals[] = $fields['disambiguation'];
    }
    if (isset($worksExtraCols['Ccli'])) {
        $sets[] = 'Ccli = ?'; $types .= 's';
        $vals[] = $fields['ccli'] !== '' ? $fields['ccli'] : null;
    }
    if (isset($worksExtraCols['Bowi'])) {
        $sets[] = 'Bowi = ?'; $types .= 's';
        $vals[] = $fields['bowi'] !== '' ? $fields['bowi'] : null;
    }
    if (isset($worksExtraCols['FirstPublishedYear'])) {
        $sets[] = 'FirstPublishedYear = ?'; $types .= 'i';
        $vals[] = $fields['firstPublishedYear'];
    }
    if (isset($worksExtraCols['CopyrightYears'])) {
        $sets[] = 'CopyrightYears = ?'; $types .= 's';
        $vals[] = $fields['copyrightYears'];
    }
    if (isset($worksExtraCols['CopyrightHolder'])) {
        $sets[] = 'CopyrightHolder = ?'; $types .= 's';
        $vals[] = $fields['copyrightHolder'];
    }
    if (isset($worksExtraCols['MusicBrainzWorkMBID'])) {
        $sets[] = 'MusicBrainzWorkMBID = ?'; $types .= 's';
        $vals[] = $fields['musicBrainzWorkMbid'] !== '' ? $fields['musicBrainzWorkMbid'] : null;
    }
    if (isset($worksExtraCols['TuneName'])) {
        $sets[] = 'TuneName = ?'; $types .= 's';
        $vals[] = $fields['tuneName'] !== '' ? $fields['tuneName'] : null;
        if (isset($worksExtraCols['TuneId'])) {
            $sets[] = 'TuneId = ?'; $types .= 'i';
            $vals[] = tuneFindOrCreateByName($db, $fields['tuneName']);
        }
    }

    if (!$sets) return;
    $stmt = $db->prepare('UPDATE tblWorks SET ' . implode(', ', $sets) . ' WHERE Id = ?');
    $types .= 'i';
    $vals[] = $workId;
    $stmt->bind_param($types, ...$vals);
    $stmt->execute();
    $stmt->close();
};

/* External-links registry (#833) — probe + load applicable types. */
$hasExtLinksSchema = false;
$linkTypesForWork  = [];
try {
    $r = $db->query(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'tblWorkExternalLinks' LIMIT 1"
    );
    $hasExtLinksSchema = $r && $r->fetch_row() !== null;
    if ($r) $r->close();
    if ($hasExtLinksSchema) {
        /* #841/#845 — shared loader returns the registry with URL →
           provider patterns already attached. */
        $linkTypesForWork = loadExternalLinkTypesFor($db, 'work');
    }
} catch (\Throwable $_e) { /* probe failure → external-links UI silently absent */ }

/* ---- GET ?action=song_search&q= — typeahead for membership picker. */
if ($hasSchema
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && ($_GET['action'] ?? '') === 'song_search'
) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    $q     = trim((string)($_GET['q'] ?? ''));
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
    try {
        $like = '%' . $q . '%';
        if ($q === '') {
            $sql = "SELECT SongId, Title, SongbookAbbr, Number
                      FROM tblSongs
                     WHERE " . songVisibleSql($db, '') . "
                     ORDER BY SongbookAbbr ASC, Number ASC
                     LIMIT ?";   /* #1694 — hidden songs are not offered for membership */
            $stmt = $db->prepare($sql);
            $stmt->bind_param('i', $limit);
        } else {
            /* #1694 — the LIKE trio is parenthesised so the visibility
               predicate applies to every branch (AND binds tighter than OR). */
            $sql = "SELECT SongId, Title, SongbookAbbr, Number
                      FROM tblSongs
                     WHERE (Title LIKE ?
                        OR SongId LIKE ?
                        OR SongbookAbbr LIKE ?)
                       AND " . songVisibleSql($db, '') . "
                     ORDER BY SongbookAbbr ASC, Number ASC
                     LIMIT ?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param('sssi', $like, $like, $like, $limit);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = [
                'songId'   => (string)$row['SongId'],
                'title'    => (string)$row['Title'],
                'songbook' => (string)$row['SongbookAbbr'],
                'number'   => $row['Number'] !== null ? (int)$row['Number'] : null,
            ];
        }
        $stmt->close();
        echo json_encode(['suggestions' => $out], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        error_log('[works song_search] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Search failed.']);
    }
    exit;
}

/* ---- POST actions ---- */
if ($hasSchema && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }
    $action = (string)($_POST['action'] ?? '');

    /**
     * Verify $candidateParent isn't a descendant of $workId — prevents
     * cycle creation when re-parenting (a → b → c, then re-parenting
     * a under c would loop). Walks the parent chain until null,
     * giving up after MAX_DEPTH iterations as a hard stop in case the
     * table has somehow already become inconsistent.
     */
    $cycleSafe = static function (int $workId, ?int $candidateParent) use ($db): bool {
        if ($candidateParent === null) return true;
        if ($candidateParent === $workId) return false;
        $cur = $candidateParent;
        $maxDepth = 64;
        while ($cur !== null && $maxDepth-- > 0) {
            if ($cur === $workId) return false;
            $stmt = $db->prepare('SELECT ParentWorkId FROM tblWorks WHERE Id = ?');
            $stmt->bind_param('i', $cur);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) return true;
            $cur = $row['ParentWorkId'] !== null ? (int)$row['ParentWorkId'] : null;
        }
        return false;
    };

    try {
        switch ($action) {
            case 'create': {
                $title  = trim((string)($_POST['title']  ?? ''));
                $slugIn = trim((string)($_POST['slug']   ?? ''));
                $iswcIn = trim((string)($_POST['iswc']   ?? ''));
                $notes  = trim((string)($_POST['notes']  ?? ''));
                $parent = (int)($_POST['parent_id'] ?? 0);
                /* Places adoption — composition origin. */
                $originCity   = trim((string)($_POST['origin_city']    ?? '')) ?: null;
                $originCityId = (int)($_POST['origin_city_id'] ?? 0) ?: null;
                if ($title === '') { $error = 'Title is required.'; break; }
                $slug = $slugIn !== '' ? $slugIn : $slugFor($title);
                if ($slug === '') { $error = 'Title has no usable slug characters — provide one explicitly.'; break; }
                $iswc = $validateIswc($iswcIn);
                if ($iswc === null) { $error = 'ISWC must look like T-345.246.800-1 (10 digits).'; break; }
                $title = mb_substr($title, 0, 255);
                $slug  = mb_substr($slug,  0, 80);
                $notes = mb_substr($notes, 0, 65000);

                /* #1741 P4b — extra fields, parsed + shape-validated
                   BEFORE any write (same "fail before insert" posture the
                   slug/ISWC checks above already use). */
                [$extraFields, $extraError] = $parseWorkExtraFields($_POST);
                if ($extraError !== null) { $error = $extraError; break; }

                /* Slug uniqueness */
                $stmt = $db->prepare('SELECT Id FROM tblWorks WHERE Slug = ?');
                $stmt->bind_param('s', $slug);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if ($exists) { $error = "Slug '{$slug}' already taken."; break; }

                /* ISWC uniqueness when supplied */
                if ($iswc !== '') {
                    $stmt = $db->prepare('SELECT Id FROM tblWorks WHERE Iswc = ?');
                    $stmt->bind_param('s', $iswc);
                    $stmt->execute();
                    $iswcUsed = $stmt->get_result()->fetch_row() !== null;
                    $stmt->close();
                    if ($iswcUsed) { $error = "ISWC '{$iswc}' is already on another Work."; break; }
                }

                /* #1741 P4b — Ccli/Bowi/MusicBrainzWorkMBID uniqueness. */
                $extraUniqError = $checkWorkExtraUniqueness($db, $worksExtraCols, $extraFields, null);
                if ($extraUniqError !== null) { $error = $extraUniqError; break; }

                $stmt = $db->prepare(
                    'INSERT INTO tblWorks (ParentWorkId, Iswc, Title, Slug, Notes)
                     VALUES (?, NULLIF(?, ""), ?, ?, NULLIF(?, ""))'
                );
                $parentBind = $parent > 0 ? $parent : null;
                $stmt->bind_param('issss', $parentBind, $iswc, $title, $slug, $notes);
                $stmt->execute();
                $newId = (int)$db->insert_id;
                $stmt->close();

                /* Place columns — schema-tolerant separate UPDATE. */
                if (placeColumnExists($db, 'tblWorks', 'OriginCityId')) {
                    $stmt = $db->prepare(
                        'UPDATE tblWorks
                            SET OriginCity = ?, OriginCityId = ?
                          WHERE Id = ?'
                    );
                    $stmt->bind_param('sii', $originCity, $originCityId, $newId);
                    $stmt->execute();
                    $stmt->close();
                }

                /* #1741 P4b — extra fields, ONE separate column-gated
                   UPDATE (§0.3.3 pattern). */
                $persistWorkExtraFields($db, $worksExtraCols, $newId, $extraFields);

                if (function_exists('logActivity')) {
                    logActivity('work.create', 'work', (string)$newId, [
                        'title' => $title, 'slug' => $slug,
                        'iswc'  => $iswc, 'parent_id' => $parent > 0 ? $parent : null,
                        'origin_city' => $originCity,
                        'subtitle'  => $extraFields['subtitle'],
                        'ccli'      => $extraFields['ccli'],
                        'bowi'      => $extraFields['bowi'],
                        'tune_name' => $extraFields['tuneName'],
                    ]);
                }
                $success = "Work '{$title}' created.";
                break;
            }

            case 'update': {
                $id     = (int)($_POST['id']    ?? 0);
                $title  = trim((string)($_POST['title']  ?? ''));
                $slugIn = trim((string)($_POST['slug']   ?? ''));
                $iswcIn = trim((string)($_POST['iswc']   ?? ''));
                $notes  = trim((string)($_POST['notes']  ?? ''));
                $parent = (int)($_POST['parent_id'] ?? 0);
                $originCity   = trim((string)($_POST['origin_city']    ?? '')) ?: null;
                $originCityId = (int)($_POST['origin_city_id'] ?? 0) ?: null;
                if ($id <= 0)     { $error = 'Work id is required.'; break; }
                if ($title === '') { $error = 'Title is required.'; break; }
                $slug = $slugIn !== '' ? $slugIn : $slugFor($title);
                if ($slug === '') { $error = 'Title has no usable slug characters.'; break; }
                $iswc = $validateIswc($iswcIn);
                if ($iswc === null) { $error = 'ISWC must look like T-345.246.800-1.'; break; }
                $title = mb_substr($title, 0, 255);
                $slug  = mb_substr($slug,  0, 80);
                $notes = mb_substr($notes, 0, 65000);

                /* #1741 P4b — extra fields, parsed + shape-validated
                   BEFORE any write. */
                [$extraFields, $extraError] = $parseWorkExtraFields($_POST);
                if ($extraError !== null) { $error = $extraError; break; }

                /* Cycle check */
                $parentBind = $parent > 0 ? $parent : null;
                if (!$cycleSafe($id, $parentBind)) {
                    $error = 'Cannot set that parent — it would create a cycle.';
                    break;
                }

                /* Slug + ISWC uniqueness (excluding self) */
                $stmt = $db->prepare('SELECT Id FROM tblWorks WHERE Slug = ? AND Id <> ?');
                $stmt->bind_param('si', $slug, $id);
                $stmt->execute();
                $dup = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if ($dup) { $error = "Slug '{$slug}' already taken by another Work."; break; }

                if ($iswc !== '') {
                    $stmt = $db->prepare('SELECT Id FROM tblWorks WHERE Iswc = ? AND Id <> ?');
                    $stmt->bind_param('si', $iswc, $id);
                    $stmt->execute();
                    $iswcDup = $stmt->get_result()->fetch_row() !== null;
                    $stmt->close();
                    if ($iswcDup) { $error = "ISWC '{$iswc}' already on another Work."; break; }
                }

                /* #1741 P4b — Ccli/Bowi/MusicBrainzWorkMBID uniqueness
                   (excluding self). */
                $extraUniqError = $checkWorkExtraUniqueness($db, $worksExtraCols, $extraFields, $id);
                if ($extraUniqError !== null) { $error = $extraUniqError; break; }

                /* Membership reconciliation */
                $postedSongs    = $_POST['member_song_ids']  ?? [];
                $postedCanon    = $_POST['member_canonical'] ?? [];
                $postedSort     = $_POST['member_sort']      ?? [];
                $postedNote     = $_POST['member_note']      ?? [];
                if (!is_array($postedSongs)) $postedSongs = [];
                if (!is_array($postedCanon)) $postedCanon = [];
                if (!is_array($postedSort))  $postedSort  = [];
                if (!is_array($postedNote))  $postedNote  = [];
                $cleanSongs = array_values(array_unique(array_filter(array_map(
                    static fn($s) => mb_substr(trim((string)$s), 0, 20),
                    $postedSongs
                ))));

                $db->begin_transaction();
                try {
                    $stmt = $db->prepare(
                        'UPDATE tblWorks
                            SET ParentWorkId = ?, Iswc = NULLIF(?, ""),
                                Title = ?, Slug = ?, Notes = NULLIF(?, "")
                          WHERE Id = ?'
                    );
                    $stmt->bind_param('issssi', $parentBind, $iswc, $title, $slug, $notes, $id);
                    $stmt->execute();
                    $stmt->close();

                    /* Place columns — separate UPDATE so the main bind
                       stays untouched. Skipped on pre-adoption installs. */
                    if (placeColumnExists($db, 'tblWorks', 'OriginCityId')) {
                        $stmt = $db->prepare(
                            'UPDATE tblWorks
                                SET OriginCity = ?, OriginCityId = ?
                              WHERE Id = ?'
                        );
                        $stmt->bind_param('sii', $originCity, $originCityId, $id);
                        $stmt->execute();
                        $stmt->close();
                    }

                    /* #1741 P4b — extra fields, ONE separate column-gated
                       UPDATE (§0.3.3 pattern), inside the same transaction
                       as the rest of this save so a mid-way failure rolls
                       everything back together. */
                    $persistWorkExtraFields($db, $worksExtraCols, $id, $extraFields);

                    /* Membership: delete-then-insert so SortOrder /
                       IsCanonical / Note all reset cleanly. Cheap on
                       small membership lists. */
                    $stmt = $db->prepare('DELETE FROM tblWorkSongs WHERE WorkId = ?');
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $stmt->close();

                    if ($cleanSongs) {
                        $stmt = $db->prepare(
                            'INSERT INTO tblWorkSongs
                                (WorkId, SongId, IsCanonical, SortOrder, Note)
                             VALUES (?, ?, ?, ?, NULLIF(?, ""))'
                        );
                        foreach ($cleanSongs as $idx => $sid) {
                            $isCanon = in_array($sid, (array)$postedCanon, true) ? 1 : 0;
                            $sort    = isset($postedSort[$sid]) ? max(0, min(65535, (int)$postedSort[$sid])) : ($idx * 10);
                            $note    = mb_substr((string)($postedNote[$sid] ?? ''), 0, 255);
                            $stmt->bind_param('isiis', $id, $sid, $isCanon, $sort, $note);
                            $stmt->execute();
                        }
                        $stmt->close();
                    }

                    /* External links — delegated to the shared saver. */
                    $insertedLinks = 0;
                    if ($hasExtLinksSchema) {
                        $insertedLinks = saveExternalLinksForRow(
                            $db,
                            'tblWorkExternalLinks',
                            'WorkId',
                            $id,
                            $_POST['ext_link_type_ids'] ?? [],
                            $_POST['ext_link_urls']     ?? [],
                            $_POST['ext_link_notes']    ?? [],
                            $_POST['ext_link_verified'] ?? []
                        );
                    }

                    $db->commit();
                } catch (\Throwable $tx) {
                    $db->rollback();
                    throw $tx;
                }

                if (function_exists('logActivity')) {
                    logActivity('work.edit', 'work', (string)$id, [
                        'title'                => $title,
                        'slug'                 => $slug,
                        'iswc'                 => $iswc,
                        'parent_id'            => $parentBind,
                        'member_count'         => count($cleanSongs),
                        'external_link_count'  => $hasExtLinksSchema ? ($insertedLinks ?? 0) : null,
                        'subtitle'             => $extraFields['subtitle'],
                        'ccli'                 => $extraFields['ccli'],
                        'bowi'                 => $extraFields['bowi'],
                        'tune_name'            => $extraFields['tuneName'],
                    ]);
                }
                $success = "Work '{$title}' updated.";
                break;
            }

            case 'delete': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { $error = 'Work id is required.'; break; }
                $stmt = $db->prepare('SELECT Title FROM tblWorks WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $before = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$before) { $error = 'Work not found.'; break; }

                /* Cascade rules:
                   - tblWorkSongs FK has ON DELETE CASCADE → memberships dropped.
                   - tblWorkExternalLinks FK has ON DELETE CASCADE → links dropped.
                   - tblWorks.ParentWorkId self-FK has ON DELETE SET NULL → child
                     works orphan rather than cascade-delete (curator decides
                     what to do with them). */
                $stmt = $db->prepare('DELETE FROM tblWorks WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();

                if (function_exists('logActivity')) {
                    logActivity('work.delete', 'work', (string)$id, [
                        'title' => (string)$before['Title'],
                    ]);
                }
                $success = "Work '" . (string)$before['Title'] . "' deleted.";
                break;
            }
        }
    } catch (\Throwable $e) {
        error_log('[works POST] ' . $e->getMessage());
        $error = 'Could not save changes: ' . $e->getMessage();
    }
}

/* ---- Read ---- */
$rows           = [];
$memberCounts   = [];
$linkCounts     = [];
$childCounts    = [];
$parentMap      = [];
$worksByIdShort = [];
$workMembersMap = [];
$workLinksMap   = [];

if ($hasSchema) {
    try {
        /* Schema-tolerant SELECT — pull OriginCity / OriginCityId
           only when the places-adoption migration has landed.
           Pre-migration installs surface NULLs and the modal's
           pre-fill defaults render the field empty. */
        $hasWorkPlace = placeColumnExists($db, 'tblWorks', 'OriginCityId');
        $placeSelect  = $hasWorkPlace
            ? ', w.OriginCity, w.OriginCityId'
            : ', NULL AS OriginCity, NULL AS OriginCityId';

        /* #1741 P4b — same gated-fragment idiom as $placeSelect immediately
           above, extended to the 9 extra columns + the MusicBrainzWorkMBID
           rider: `NULL AS <Col>` when the column is absent, so the query
           shape never depends on migration state (rule #5's carve-out —
           the column list is a fixed PHP-source constant, never built
           from request input). */
        $extraColNames = [
            'Subtitle', 'Disambiguation', 'Ccli', 'Bowi', 'TuneName', 'TuneId',
            'FirstPublishedYear', 'CopyrightYears', 'CopyrightHolder', 'MusicBrainzWorkMBID',
        ];
        $extraSelectParts = [];
        foreach ($extraColNames as $extraCol) {
            $extraSelectParts[] = isset($worksExtraCols[$extraCol]) ? "w.{$extraCol}" : "NULL AS {$extraCol}";
        }
        $extraListSelect = ', ' . implode(', ', $extraSelectParts);

        $res = $db->query(
            'SELECT w.Id, w.ParentWorkId, w.Title, w.Slug, w.Iswc, w.Notes' . $placeSelect . $extraListSelect . ',
                    (SELECT COUNT(*) FROM tblWorkSongs ws WHERE ws.WorkId = w.Id) AS MemberCount,
                    (SELECT COUNT(*) FROM tblWorks c   WHERE c.ParentWorkId = w.Id) AS ChildCount
               FROM tblWorks w
              ORDER BY w.Title ASC'
        );
        while ($row = $res->fetch_assoc()) {
            $row['Id']           = (int)$row['Id'];
            $row['ParentWorkId'] = $row['ParentWorkId'] !== null ? (int)$row['ParentWorkId'] : null;
            $row['MemberCount']  = (int)$row['MemberCount'];
            $row['ChildCount']   = (int)$row['ChildCount'];
            $rows[] = $row;
            $worksByIdShort[$row['Id']] = [
                'id'    => $row['Id'],
                'title' => (string)$row['Title'],
            ];
            $parentMap[$row['Id']] = $row['ParentWorkId'];
        }
        $res->close();

        if ($rows) {
            /* Members per work */
            $stmt = $db->query(
                'SELECT ws.WorkId, ws.SongId, ws.IsCanonical, ws.SortOrder, ws.Note,
                        s.Title AS SongTitle, s.SongbookAbbr, s.Number
                   FROM tblWorkSongs ws
                   JOIN tblSongs s ON s.SongId = ws.SongId
                  WHERE ' . songVisibleSql($db, 's') . '
                  ORDER BY ws.WorkId, ws.SortOrder ASC, s.SongbookAbbr ASC, s.Number ASC'
            );   /* #1694 — membership rows survive; the admin panel hides them
                    while deleted, same as the public Work page */
            while ($row = $stmt->fetch_assoc()) {
                $wid = (int)$row['WorkId'];
                $workMembersMap[$wid][] = [
                    'songId'      => (string)$row['SongId'],
                    'title'       => (string)$row['SongTitle'],
                    'songbook'    => (string)$row['SongbookAbbr'],
                    'number'      => $row['Number'] !== null ? (int)$row['Number'] : null,
                    'isCanonical' => (bool)$row['IsCanonical'],
                    'sortOrder'   => (int)$row['SortOrder'],
                    'note'        => (string)($row['Note'] ?? ''),
                ];
            }
            $stmt->close();

            /* Links per work */
            if ($hasExtLinksSchema) {
                $stmt = $db->query(
                    'SELECT el.WorkId, el.Id, el.LinkTypeId, el.Url, el.Note,
                            el.SortOrder, el.Verified,
                            t.Slug, t.Name, t.Category, t.IconClass
                       FROM tblWorkExternalLinks el
                       JOIN tblExternalLinkTypes t ON t.Id = el.LinkTypeId
                      ORDER BY el.WorkId, t.Category, el.SortOrder ASC,
                               t.DisplayOrder ASC, t.Name ASC'
                );
                while ($row = $stmt->fetch_assoc()) {
                    $wid = (int)$row['WorkId'];
                    $workLinksMap[$wid][] = [
                        'typeId'    => (int)$row['LinkTypeId'],
                        'url'       => (string)$row['Url'],
                        'note'      => (string)($row['Note'] ?? ''),
                        'verified'  => (bool)$row['Verified'],
                        'slug'      => (string)$row['Slug'],
                        'name'      => (string)$row['Name'],
                        'category'  => (string)$row['Category'],
                        'iconClass' => (string)($row['IconClass'] ?? ''),
                    ];
                }
                $stmt->close();
            }
        }
    } catch (\Throwable $e) {
        error_log('[works read] ' . $e->getMessage());
    }
}

/* ----- Page render ----- */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
    <title>Works — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">
        <h1 class="h4 mb-3"><i class="bi bi-diagram-3 me-2"></i>Works</h1>
        <p class="text-secondary small mb-4">
            A <strong>Work</strong> groups multiple songs that represent the same composition
            across different songbooks, arrangements or translations — mirrors the
            <a href="https://musicbrainz.org/doc/Work" target="_blank" rel="noopener noreferrer">MusicBrainz Work</a>
            ↔ Recording relationship. Works can be nested without limit (an original work
            can have arrangement / translation children, each with their own children, etc.).
            ISWC is optional — supply it for compositions registered with a CISAC society.
        </p>

        <?php if ($success): ?>
            <div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!$hasSchema): ?>
            <div class="card-admin p-4 text-center">
                <p class="mb-2">
                    <i class="bi bi-database-exclamation text-warning fs-1" aria-hidden="true"></i>
                </p>
                <h2 class="h6 mb-2">Schema not yet installed</h2>
                <p class="text-muted small mb-3">
                    The <code>tblWorks</code> + <code>tblWorkSongs</code> +
                    <code>tblWorkExternalLinks</code> tables haven't been created on this database yet (#840).
                </p>
                <a href="/manage/setup-database" class="btn btn-amber btn-sm">
                    <i class="bi bi-database-gear me-1"></i>Run /manage/setup-database
                </a>
            </div>
        <?php else: ?>

        <!-- Works list -->
        <div class="card-admin p-3 mb-4">
            <h2 class="h6 mb-3"><i class="bi bi-list-ul me-2"></i>Existing works</h2>
            <table class="table table-sm align-middle cp-sortable mb-0 admin-table-responsive">
                <thead>
                    <tr class="text-muted small">
                        <th data-col-priority="primary"   data-sort-key="title"     data-sort-type="text">Title</th>
                        <th data-col-priority="secondary" data-sort-key="iswc"      data-sort-type="text">ISWC</th>
                        <th data-col-priority="primary"   data-sort-key="members"   data-sort-type="number" class="text-center">Members</th>
                        <th data-col-priority="tertiary"  data-sort-key="children"  data-sort-type="number" class="text-center">Children</th>
                        <th data-col-priority="tertiary"  data-sort-key="parent"    data-sort-type="text">Parent</th>
                        <th data-col-priority="primary"   class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php
                            $wid = (int)$r['Id'];
                            $rowPayload = [
                                'id'         => $wid,
                                'parent_id'  => $r['ParentWorkId'],
                                'title'      => (string)$r['Title'],
                                'slug'       => (string)$r['Slug'],
                                'iswc'       => (string)($r['Iswc'] ?? ''),
                                'notes'      => (string)($r['Notes'] ?? ''),
                                'origin_city'    => (string)($r['OriginCity'] ?? ''),
                                'origin_city_id' => isset($r['OriginCityId']) ? (int)$r['OriginCityId'] : null,
                                /* #1741 P4b — extra identity/enrichment
                                   fields; NULL AS <Col> in the gated SELECT
                                   above already makes every key present
                                   regardless of migration state. */
                                'subtitle'              => (string)($r['Subtitle'] ?? ''),
                                'disambiguation'        => (string)($r['Disambiguation'] ?? ''),
                                'ccli'                  => (string)($r['Ccli'] ?? ''),
                                'bowi'                  => (string)($r['Bowi'] ?? ''),
                                'tune_name'             => (string)($r['TuneName'] ?? ''),
                                'first_published_year'  => isset($r['FirstPublishedYear']) && $r['FirstPublishedYear'] !== null ? (int)$r['FirstPublishedYear'] : null,
                                'copyright_years'       => (string)($r['CopyrightYears'] ?? ''),
                                'copyright_holder'      => (string)($r['CopyrightHolder'] ?? ''),
                                'musicbrainz_work_mbid' => (string)($r['MusicBrainzWorkMBID'] ?? ''),
                                'members'    => $workMembersMap[$wid] ?? [],
                                'links'      => $workLinksMap[$wid]   ?? [],
                            ];
                            $rowJson = json_encode($rowPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $deleteJson = json_encode(['id' => $wid, 'title' => (string)$r['Title']],
                                                      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $parentTitle = ($r['ParentWorkId'] !== null && isset($worksByIdShort[$r['ParentWorkId']]))
                                ? $worksByIdShort[$r['ParentWorkId']]['title']
                                : '';
                        ?>
                        <tr>
                            <td data-col-priority="primary">
                                <a href="/work/<?= htmlspecialchars((string)$r['Slug']) ?>"
                                   target="_blank" rel="noopener noreferrer"
                                   class="text-decoration-none">
                                    <?= htmlspecialchars((string)$r['Title']) ?>
                                </a>
                                <?php if (!empty($r['Iswc'])): ?>
                                    <span class="d-md-none ms-2 text-muted small"><code><?= htmlspecialchars((string)$r['Iswc']) ?></code></span>
                                <?php endif; ?>
                                <?php if ($parentTitle !== ''): ?>
                                    <small class="d-md-none d-block text-muted">↳ child of <?= htmlspecialchars($parentTitle) ?></small>
                                <?php endif; ?>
                            </td>
                            <td data-col-priority="secondary">
                                <?php if (!empty($r['Iswc'])): ?>
                                    <code><?= htmlspecialchars((string)$r['Iswc']) ?></code>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td data-col-priority="primary" class="text-center"><?= (int)$r['MemberCount'] ?></td>
                            <td data-col-priority="tertiary" class="text-center">
                                <?= (int)$r['ChildCount'] > 0 ? (int)$r['ChildCount'] : '<span class="text-muted">—</span>' ?>
                            </td>
                            <td data-col-priority="tertiary">
                                <?php if ($parentTitle !== ''): ?>
                                    <small class="text-muted"><?= htmlspecialchars($parentTitle) ?></small>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td data-col-priority="primary" class="text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-info"
                                        onclick="openWorkEditModal(<?= htmlspecialchars((string)$rowJson, ENT_QUOTES, 'UTF-8') ?>)"
                                        title="Edit work + members + links">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        onclick="openWorkDeleteModal(<?= htmlspecialchars((string)$deleteJson, ENT_QUOTES, 'UTF-8') ?>)"
                                        title="Delete work (memberships + links cascade; child works orphan)">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr><td colspan="6" class="text-muted text-center py-4">No works yet. Add one below.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Create -->
        <form method="POST" class="card-admin p-3 mb-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="create">
            <h2 class="h6 mb-3"><i class="bi bi-plus-circle me-2"></i>Add a work</h2>
            <div class="row g-2">
                <div class="col-sm-5">
                    <label class="form-label small">Title</label>
                    <input type="text" name="title" id="create-title"
                           class="form-control form-control-sm"
                           maxlength="255" required
                           placeholder="e.g. Amazing Grace">
                </div>
                <div class="col-sm-3">
                    <label class="form-label small">Slug
                        <small class="text-muted">(auto)</small>
                    </label>
                    <input type="text" name="slug" id="create-slug"
                           class="form-control form-control-sm"
                           maxlength="80" pattern="[a-z0-9-]+"
                           placeholder="amazing-grace">
                </div>
                <div class="col-sm-4">
                    <label class="form-label small">ISWC <small class="text-muted">(optional)</small></label>
                    <input type="text" name="iswc"
                           class="form-control form-control-sm"
                           maxlength="15"
                           placeholder="T-345.246.800-1">
                </div>
            </div>
            <div class="row g-2 mt-2">
                <div class="col-sm-6">
                    <label class="form-label small">Parent work <small class="text-muted">(optional — for nesting)</small></label>
                    <select name="parent_id" class="form-select form-select-sm">
                        <option value="">— top-level work —</option>
                        <?php foreach ($rows as $r): ?>
                            <option value="<?= (int)$r['Id'] ?>"><?= htmlspecialchars((string)$r['Title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6">
                    <label class="form-label small">Notes <small class="text-muted">(optional)</small></label>
                    <input type="text" name="notes"
                           class="form-control form-control-sm"
                           maxlength="500"
                           placeholder="Brief context for curators">
                </div>
            </div>
            <div class="row g-2 mt-2">
                <div class="col-sm-6">
                    <label class="form-label small">Composition origin <small class="text-muted">(optional)</small></label>
                    <input type="text" id="create-work-origin-city" name="origin_city"
                           class="form-control form-control-sm js-place-search"
                           maxlength="255"
                           placeholder="Start typing — e.g. Cardiff, Wales">
                    <input type="hidden" id="create-work-origin-city-id" name="origin_city_id" value="">
                </div>
            </div>
            <!-- #1741 P4b — identity/enrichment fields. Column-gated on save
                 (an un-migrated install simply drops these silently rather
                 than erroring — see $persistWorkExtraFields above); the
                 form fields themselves render unconditionally so a curator
                 typing ahead of a migration doesn't lose the value, it's
                 just not persisted until the migration lands. -->
            <div class="row g-2 mt-2">
                <div class="col-sm-6">
                    <label class="form-label small">Subtitle <small class="text-muted">(optional)</small></label>
                    <input type="text" name="subtitle"
                           class="form-control form-control-sm"
                           maxlength="255"
                           placeholder="e.g. A Hymn for the Nativity">
                </div>
                <div class="col-sm-6">
                    <label class="form-label small">Disambiguation <small class="text-muted">(optional)</small></label>
                    <input type="text" name="disambiguation"
                           class="form-control form-control-sm"
                           maxlength="255"
                           placeholder="Short parenthetical distinguishing same-named works">
                </div>
            </div>
            <div class="row g-2 mt-2">
                <div class="col-sm-3">
                    <label class="form-label small">CCLI Work # <small class="text-muted">(optional)</small></label>
                    <input type="text" name="ccli" inputmode="numeric"
                           class="form-control form-control-sm"
                           maxlength="50"
                           placeholder="1234567">
                </div>
                <div class="col-sm-3">
                    <label class="form-label small">BOWI <small class="text-muted">(optional)</small></label>
                    <input type="text" name="bowi"
                           class="form-control form-control-sm"
                           maxlength="30">
                </div>
                <div class="col-sm-3">
                    <label class="form-label small">Tune name <small class="text-muted">(optional)</small></label>
                    <input type="text" name="tune_name"
                           class="form-control form-control-sm"
                           maxlength="120"
                           placeholder="e.g. HYFRYDOL">
                </div>
                <div class="col-sm-3">
                    <label class="form-label small">First published <small class="text-muted">(year, optional)</small></label>
                    <input type="number" name="first_published_year"
                           class="form-control form-control-sm"
                           min="500" max="2100"
                           placeholder="1978">
                </div>
            </div>
            <div class="row g-2 mt-2">
                <div class="col-sm-6">
                    <label class="form-label small">Copyright years <small class="text-muted">(as printed, optional)</small></label>
                    <input type="text" name="copyright_years"
                           class="form-control form-control-sm"
                           maxlength="100"
                           placeholder="e.g. 1978, 1987, 2011">
                </div>
                <div class="col-sm-6">
                    <label class="form-label small">Copyright holder <small class="text-muted">(optional)</small></label>
                    <input type="text" name="copyright_holder"
                           class="form-control form-control-sm"
                           maxlength="255">
                </div>
            </div>
            <div class="row g-2 mt-2">
                <div class="col-sm-6">
                    <!-- #1741 P4b §2.4.5 rider — the pre-existing #1066
                         MusicBrainzWorkMBID column has never had an editor
                         field before this. -->
                    <label class="form-label small">MusicBrainz Work MBID <small class="text-muted">(optional)</small></label>
                    <input type="text" name="musicbrainz_work_mbid"
                           class="form-control form-control-sm"
                           maxlength="50"
                           placeholder="e.g. 0a1b2c3d-...">
                </div>
            </div>
            <button type="submit" class="btn btn-amber btn-sm mt-3">
                <i class="bi bi-plus me-1"></i>Create work
            </button>
            <p class="form-text small mt-2 mb-0">
                Add member songs + external links via the <em>Edit</em> button after creating.
            </p>
        </form>

        <!-- Edit Modal -->
        <div class="modal fade" id="workEditModal" tabindex="-1">
            <div class="modal-dialog modal-xl">
                <div class="modal-content" style="background: var(--ih-surface); color: var(--ih-text); border-color: var(--ih-border);">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" id="edit-work-id">
                        <div class="modal-header" style="border-color: var(--ih-border);">
                            <h5 class="modal-title">
                                <i class="bi bi-pencil me-2"></i>Edit work — <span id="edit-work-title-label"></span>
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Title</label>
                                    <input type="text" name="title" id="edit-work-title"
                                           class="form-control" maxlength="255" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Slug</label>
                                    <input type="text" name="slug" id="edit-work-slug"
                                           class="form-control" maxlength="80"
                                           pattern="[a-z0-9-]+">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">ISWC <small class="text-muted">(opt.)</small></label>
                                    <input type="text" name="iswc" id="edit-work-iswc"
                                           class="form-control" maxlength="15"
                                           placeholder="T-345.246.800-1">
                                </div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Parent work</label>
                                    <select name="parent_id" id="edit-work-parent" class="form-select">
                                        <option value="">— top-level work —</option>
                                        <?php foreach ($rows as $r): ?>
                                            <option value="<?= (int)$r['Id'] ?>"><?= htmlspecialchars((string)$r['Title']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text small">Cycles are blocked server-side.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Notes</label>
                                    <input type="text" name="notes" id="edit-work-notes"
                                           class="form-control" maxlength="500">
                                </div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Composition origin</label>
                                    <input type="text" name="origin_city" id="edit-work-origin-city"
                                           class="form-control js-place-search" maxlength="255"
                                           placeholder="Start typing — e.g. Cardiff, Wales">
                                    <input type="hidden" name="origin_city_id" id="edit-work-origin-city-id" value="">
                                </div>
                            </div>

                            <!-- #1741 P4b — identity/enrichment fields, same
                                 column-gated-on-save posture as the create
                                 form above. -->
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Subtitle <small class="text-muted">(opt.)</small></label>
                                    <input type="text" name="subtitle" id="edit-work-subtitle"
                                           class="form-control" maxlength="255">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Disambiguation <small class="text-muted">(opt.)</small></label>
                                    <input type="text" name="disambiguation" id="edit-work-disambiguation"
                                           class="form-control" maxlength="255">
                                </div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-md-3">
                                    <label class="form-label">CCLI Work # <small class="text-muted">(opt.)</small></label>
                                    <input type="text" name="ccli" id="edit-work-ccli" inputmode="numeric"
                                           class="form-control" maxlength="50">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">BOWI <small class="text-muted">(opt.)</small></label>
                                    <input type="text" name="bowi" id="edit-work-bowi"
                                           class="form-control" maxlength="30">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Tune name <small class="text-muted">(opt.)</small></label>
                                    <input type="text" name="tune_name" id="edit-work-tune-name"
                                           class="form-control" maxlength="120" placeholder="e.g. HYFRYDOL">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">First published <small class="text-muted">(year, opt.)</small></label>
                                    <input type="number" name="first_published_year" id="edit-work-first-published-year"
                                           class="form-control" min="500" max="2100">
                                </div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Copyright years <small class="text-muted">(as printed, opt.)</small></label>
                                    <input type="text" name="copyright_years" id="edit-work-copyright-years"
                                           class="form-control" maxlength="100" placeholder="e.g. 1978, 1987, 2011">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Copyright holder <small class="text-muted">(opt.)</small></label>
                                    <input type="text" name="copyright_holder" id="edit-work-copyright-holder"
                                           class="form-control" maxlength="255">
                                </div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <!-- #1741 P4b §2.4.5 rider. -->
                                    <label class="form-label">MusicBrainz Work MBID <small class="text-muted">(opt.)</small></label>
                                    <input type="text" name="musicbrainz_work_mbid" id="edit-work-musicbrainz-work-mbid"
                                           class="form-control" maxlength="50" placeholder="e.g. 0a1b2c3d-...">
                                </div>
                            </div>

                            <hr>

                            <h6 class="mb-2"><i class="bi bi-music-note-list me-2"></i>Member songs</h6>
                            <p class="form-text small mt-0 mb-2">
                                Each row is a song that's a version of this work. Mark one as
                                <em>canonical</em> (typically the most-cited / earliest published).
                                Members in different songbooks across the catalogue are fine —
                                that's the whole point.
                            </p>

                            <table class="table table-sm align-middle mb-2">
                                <thead>
                                    <tr class="text-muted small">
                                        <th style="width:6rem">Sort</th>
                                        <th style="width:5rem" class="text-center">Canon</th>
                                        <th>Song</th>
                                        <th>Note</th>
                                        <th class="text-end" style="width:3rem"></th>
                                    </tr>
                                </thead>
                                <tbody id="edit-work-members-tbody">
                                    <!-- populated by openWorkEditModal -->
                                </tbody>
                            </table>

                            <div class="d-flex gap-2 align-items-end mb-3">
                                <div class="flex-grow-1">
                                    <label class="form-label small mb-1">Add a song to this work</label>
                                    <input type="text" id="edit-work-add-search"
                                           class="form-control form-control-sm"
                                           autocomplete="off"
                                           placeholder="Type to search by title, song id, or songbook…">
                                    <div id="edit-work-add-suggestions" class="list-group small mt-1" style="display:none; max-height: 220px; overflow-y: auto;"></div>
                                </div>
                            </div>

                            <?php if ($hasExtLinksSchema): ?>
                            <hr>
                            <?php
                                $containerId    = 'edit-work-ext-links-rows';
                                $addBtnId       = 'edit-work-ext-link-add-btn';
                                $heading        = 'External links';
                                $helpText       = 'Provider auto-detects from the URL — paste e.g. a YouTube link and the dropdown selects YouTube automatically.';
                                $useCardHeading = true;
                                require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'external-links-section.php';
                            ?>
                            <?php endif; ?>
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
        <div class="modal fade" id="workDeleteModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content" style="background: var(--ih-surface); color: var(--ih-text); border-color: var(--ih-border);">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" id="delete-work-id">
                        <div class="modal-header" style="border-color: var(--ih-border);">
                            <h5 class="modal-title">
                                <i class="bi bi-trash me-2"></i>Delete work — <span id="delete-work-name-label"></span>
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-warning mb-2">
                                Memberships and external links cascade away with the Work.
                            </p>
                            <p class="text-muted small">
                                Child works <strong>orphan</strong> (their <code>ParentWorkId</code>
                                becomes <code>NULL</code>) — you can re-parent them or leave them top-level.
                            </p>
                        </div>
                        <div class="modal-footer" style="border-color: var(--ih-border);">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">Delete</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        /* Seeded link-type registry for the work-ext-links row builder. */
        window._iHymnsLinkTypes = <?= json_encode($linkTypesForWork, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        </script>
        <?php endif; /* hasSchema */ ?>
    </div>

    <script>
    /* The inline page script runs BEFORE admin-footer.php loads
       Bootstrap JS (#974 follow-up — symptom: Edit / Delete pencil/
       trash buttons "do nothing"). Eagerly calling
       bootstrap.Modal.getOrCreateInstance() threw ReferenceError
       on Bootstrap-not-loaded-yet and aborted the IIFE, which meant
       window.openWorkEditModal never got assigned and clicking the
       inline-onclick handler silently failed.

       Two fixes layered for defence-in-depth:
       1. Wrap the IIFE in DOMContentLoaded so it runs after the
          rest of the page (including admin-footer's <script src>)
          is parsed.
       2. Lazy-instantiate the modals via getOrCreateInstance INSIDE
          openWorkEditModal / openWorkDeleteModal so even if Bootstrap
          loads async / defers, the call happens at click-time
          (guaranteed to be after page-load). */
    document.addEventListener('DOMContentLoaded', function () {
        const modalEl   = document.getElementById('workEditModal');
        const delModal  = document.getElementById('workDeleteModal');
        if (!modalEl) return;
        let editModal = null;
        let deleteModal = null;
        function ensureModal(el, slot) {
            if (slot === 'edit'   && editModal)   return editModal;
            if (slot === 'delete' && deleteModal) return deleteModal;
            if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
                console.error('[works] Bootstrap JS not loaded yet; modal cannot open.');
                return null;
            }
            const inst = bootstrap.Modal.getOrCreateInstance(el);
            if (slot === 'edit')   editModal   = inst;
            if (slot === 'delete') deleteModal = inst;
            return inst;
        }
        const tbody = document.getElementById('edit-work-members-tbody');
        const linkRowsEl = document.getElementById('edit-work-ext-links-rows');
        const addLinkBtn = document.getElementById('edit-work-ext-link-add-btn');
        const search = document.getElementById('edit-work-add-search');
        const sugBox = document.getElementById('edit-work-add-suggestions');

        function escapeHtml(s) {
            return String(s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        function memberRowHtml(m) {
            const sid  = String(m.songId || '');
            const sort = (m.sortOrder !== undefined && m.sortOrder !== null) ? m.sortOrder : 0;
            const note = String(m.note || '');
            const can  = m.isCanonical ? 'checked' : '';
            const songLabel = escapeHtml((m.songbook || '') + (m.number ? (' #' + m.number) : '') + ' — ' + (m.title || sid));
            /* #1150/#1151 — the "Sort"/"Canon" <th> headers label the COLUMN
               visually, but a <th> is not an accessible NAME source for the
               <input>/<checkbox> in each row (WCAG 4.1.2/3.3.2) — without an
               explicit aria-label a screen-reader user tabbing through hears
               only "spin button" / "checkbox, not checked" with no
               indication of which of the work's several songs it is for. */
            const rowNote = escapeHtml((m.songbook || '') + (m.number ? (' #' + m.number) : '') + ' — ' + (m.title || sid));
            return '' +
              '<tr data-song-id="' + escapeHtml(sid) + '">' +
                '<td><input type="number" class="form-control form-control-sm" name="member_sort[' + escapeHtml(sid) + ']" value="' + Number(sort) + '" min="0" max="65535" style="width:6rem" aria-label="Sort order for ' + rowNote + '"></td>' +
                '<td class="text-center"><div class="form-check form-check-inline ms-1"><input class="form-check-input" type="checkbox" name="member_canonical[]" value="' + escapeHtml(sid) + '" ' + can + ' aria-label="Mark ' + rowNote + ' as the canonical recording"></div></td>' +
                '<td><input type="hidden" name="member_song_ids[]" value="' + escapeHtml(sid) + '">' + songLabel + '</td>' +
                '<td><input type="text" class="form-control form-control-sm" name="member_note[' + escapeHtml(sid) + ']" maxlength="255" value="' + escapeHtml(note) + '" placeholder="e.g. \'1779 original\'" aria-label="Note for ' + rowNote + '"></td>' +
                '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-action="remove-member" title="Remove" aria-label="Remove ' + rowNote + ' from this work"><i class="bi bi-x-lg" aria-hidden="true"></i></button></td>' +
              '</tr>';
        }

        function rebindRemoveButtons() {
            tbody.querySelectorAll('[data-action="remove-member"]').forEach(btn => {
                btn.onclick = () => btn.closest('tr')?.remove();
            });
        }

        /* External-links editor — driven by the shared module
           (#833 / #841). The row builder lives in
           /js/modules/external-links-editor.js so songbooks, works
           and the song editor never diverge again. */
        let workLinksEditor = null;
        if (linkRowsEl && window.iHymnsExtLinksEditor) {
            workLinksEditor = window.iHymnsExtLinksEditor.mount({
                rowsEl:   linkRowsEl,
                addBtnEl: addLinkBtn,
            });
        }

        /* === Song typeahead ===
           #1594 part 2 — was previously mouse-only (no keydown handler at
           all: arrows, Home/End, Enter and Tab all did nothing but move
           the text cursor / blur the field). Now a full ARIA 1.2 combobox
           via the shared window.iHymnsComboboxA11y helper (loaded
           globally by manage/includes/head-libs.php) — see that module's
           doc-comment and appWeb/public_html/js/modules/place-search.js
           (the reference implementation) for the pattern this follows. */
        let searchAbortController = null;
        let suggestionItems = []; // last-fetched raw {songId,title,songbook,number}[], indexed to match the rendered rows
        let suggestActiveIndex = -1;
        async function runSongSearch(q) {
            try {
                if (searchAbortController) searchAbortController.abort();
                searchAbortController = new AbortController();
                const res = await fetch('/manage/works?action=song_search&q=' + encodeURIComponent(q),
                    { signal: searchAbortController.signal });
                if (!res.ok) return;
                const data = await res.json();
                const items = (data && data.suggestions) || [];
                /* NEW result set — reset the highlight to the first row
                   (matches place-search.js's runSearch()). Arrow-key
                   re-renders below call renderSuggestionRows() directly
                   instead, which does NOT touch suggestActiveIndex, so
                   navigating the SAME list doesn't keep snapping back
                   to row 0. */
                suggestionItems = items;
                suggestActiveIndex = items.length ? 0 : -1;
                renderSuggestionRows();
            } catch (e) { /* aborted */ }
        }
        function isSuggestPanelOpen() { return sugBox.style.display !== 'none' && sugBox.style.display !== ''; }
        function suggestOptionEls() { return Array.from(sugBox.querySelectorAll('a[data-payload]')); }
        function pickSuggestion(payload) {
            /* skip if already in the list */
            if (tbody.querySelector('tr[data-song-id="' + (payload.songId || '').replace(/"/g, '\\"') + '"]')) {
                sugBox.style.display = 'none';
                search.value = '';
                return;
            }
            tbody.insertAdjacentHTML('beforeend', memberRowHtml({
                songId: payload.songId, title: payload.title,
                songbook: payload.songbook, number: payload.number,
                sortOrder: tbody.children.length * 10,
                isCanonical: false, note: '',
            }));
            rebindRemoveButtons();
            sugBox.style.display = 'none';
            search.value = '';
        }
        /* Re-paint `suggestionItems` at the CURRENT `suggestActiveIndex` —
           does NOT reset the highlight, unlike runSongSearch() above.
           This is the `render` callback handleComboboxKeydown calls on
           every arrow/Home/End press (see place-search.js's renderPanel()
           for the same full-rebuild-per-keypress pattern). */
        function renderSuggestionRows() {
            const items = suggestionItems;
            if (!items.length) {
                sugBox.style.display = 'none';
                sugBox.innerHTML = '';
                if (window.iHymnsComboboxA11y) {
                    window.iHymnsComboboxA11y.applyComboboxAria({ input: search, panel: sugBox, items: [], activeIndex: -1, idPrefix: 'edit-work-add-suggestions', expanded: false });
                }
                return;
            }
            sugBox.style.display = '';
            sugBox.innerHTML = items.map(it => {
                const label = (it.songbook || '') + (it.number ? (' #' + it.number) : '') + ' — ' + (it.title || it.songId);
                const json = JSON.stringify({
                    songId: it.songId, title: it.title,
                    songbook: it.songbook, number: it.number,
                });
                return '<a href="#" class="list-group-item list-group-item-action small" data-payload=\'' + escapeHtml(json) + '\'>' +
                    escapeHtml(label) + '</a>';
            }).join('');
            const optionEls = suggestOptionEls();
            optionEls.forEach((a, i) => {
                a.classList.toggle('active', i === suggestActiveIndex);
                a.onclick = (ev) => {
                    ev.preventDefault();
                    let payload;
                    try { payload = JSON.parse(a.getAttribute('data-payload')); } catch (_e) { return; }
                    pickSuggestion(payload);
                };
            });
            if (window.iHymnsComboboxA11y) {
                window.iHymnsComboboxA11y.applyComboboxAria({
                    input: search, panel: sugBox, items: optionEls,
                    activeIndex: suggestActiveIndex, idPrefix: 'edit-work-add-suggestions',
                });
            }
        }
        if (search) {
            let t = null;
            search.addEventListener('input', () => {
                clearTimeout(t);
                const q = search.value.trim();
                t = setTimeout(() => runSongSearch(q), 180);
            });
            search.addEventListener('keydown', (e) => {
                if (!window.iHymnsComboboxA11y) return;
                window.iHymnsComboboxA11y.handleComboboxKeydown(e, {
                    isOpen: isSuggestPanelOpen,
                    getItems: suggestOptionEls,
                    getActiveIndex: () => suggestActiveIndex,
                    setActiveIndex: (i) => { suggestActiveIndex = i; },
                    render: renderSuggestionRows,
                    /* Reuse the row's own click handler rather than a second
                       copy of pickSuggestion's payload-parsing — see the
                       shared module's doc-comment on why every migrated
                       call site does this. */
                    onCommit: (i, el) => el.click(),
                    onClose: () => { sugBox.style.display = 'none'; },
                });
            });
            document.addEventListener('click', (e) => {
                if (!sugBox.contains(e.target) && e.target !== search) {
                    sugBox.style.display = 'none';
                }
            });
        }

        /* === Public openers === */
        window.openWorkEditModal = function (row) {
            document.getElementById('edit-work-id').value           = row.id;
            document.getElementById('edit-work-title').value        = row.title || '';
            document.getElementById('edit-work-title-label').textContent = row.title || '';
            document.getElementById('edit-work-slug').value         = row.slug  || '';
            document.getElementById('edit-work-iswc').value         = row.iswc  || '';
            document.getElementById('edit-work-notes').value        = row.notes || '';
            document.getElementById('edit-work-origin-city').value  = row.origin_city || '';
            document.getElementById('edit-work-origin-city-id').value = row.origin_city_id ? String(row.origin_city_id) : '';
            /* #1741 P4b — extra identity/enrichment fields. */
            document.getElementById('edit-work-subtitle').value             = row.subtitle || '';
            document.getElementById('edit-work-disambiguation').value       = row.disambiguation || '';
            document.getElementById('edit-work-ccli').value                 = row.ccli || '';
            document.getElementById('edit-work-bowi').value                 = row.bowi || '';
            document.getElementById('edit-work-tune-name').value            = row.tune_name || '';
            document.getElementById('edit-work-first-published-year').value = row.first_published_year ? String(row.first_published_year) : '';
            document.getElementById('edit-work-copyright-years').value      = row.copyright_years || '';
            document.getElementById('edit-work-copyright-holder').value     = row.copyright_holder || '';
            document.getElementById('edit-work-musicbrainz-work-mbid').value = row.musicbrainz_work_mbid || '';
            const psel = document.getElementById('edit-work-parent');
            psel.value = row.parent_id ? String(row.parent_id) : '';
            /* Hide the current work itself from the parent options to make
               accidental self-parent harder (server still rejects cycles). */
            for (const opt of psel.options) {
                opt.disabled = (opt.value && Number(opt.value) === Number(row.id));
            }

            tbody.innerHTML = (row.members || []).map(m => memberRowHtml(m)).join('');
            rebindRemoveButtons();

            if (workLinksEditor) {
                workLinksEditor.setRows((row.links || []).map(l => ({
                    typeId: l.typeId, url: l.url, note: l.note, verified: l.verified,
                })));
            }

            if (sugBox) { sugBox.style.display = 'none'; sugBox.innerHTML = ''; }
            if (search) search.value = '';

            const inst = ensureModal(modalEl, 'edit');
            if (inst) inst.show();
        };
        window.openWorkDeleteModal = function (row) {
            document.getElementById('delete-work-id').value = row.id;
            document.getElementById('delete-work-name-label').textContent = row.title || '';
            const inst = ensureModal(delModal, 'delete');
            if (inst) inst.show();
        };
    });
    </script>

    <!-- Live location autocomplete on the composition-origin inputs
         in both create and edit forms. -->
    <script src="/js/modules/place-search.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/place-search.js') ?>"></script>
    <script>
        (function () {
            if (!window.iHymnsPlaceSearch) return;
            [
                ['create-work-origin-city', 'create-work-origin-city-id'],
                ['edit-work-origin-city',   'edit-work-origin-city-id'],
            ].forEach(([inputId, hiddenId]) => {
                const visible = document.getElementById(inputId);
                const hidden  = document.getElementById(hiddenId);
                if (visible && hidden) {
                    window.iHymnsPlaceSearch.attach(visible, { hiddenIdInput: hidden });
                }
            });
        })();
    </script>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
