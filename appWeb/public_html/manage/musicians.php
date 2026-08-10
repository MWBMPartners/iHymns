<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Musicians (formerly "Credit People", #545; display renamed #1741 P2-B / #1784)
 *
 * Catalogue-wide CRUD for the people credited on songs, unioned
 * across tblSongWriters / tblSongComposers / tblSongArrangers /
 * tblSongAdaptors / tblSongTranslators, plus the registry rows in
 * tblMusicians (which may exist without any current song citing
 * them — pre-registered names for upcoming songs, or names whose
 * usage was cleaned up but the metadata is being kept).
 *
 * Surfaces:
 *   - List view: every distinct name with per-role usage, lifespan,
 *     link / IPI counts. Filterable + searchable client-side.
 *   - Person detail drawer: full edit form for biographical
 *     metadata (notes, birth/death place + date), repeating
 *     external link sub-form, repeating IPI Name Number sub-form.
 *     Drives both Add and Update.
 *   - Rename modal: changes the canonical name, cascading the
 *     update across the five song-credit tables AND the registry
 *     row, atomically inside a transaction.
 *   - Merge modal: collapses two registry entries into one,
 *     re-pointing every song-credit row from source name → target
 *     name, with the source registry row removed and the
 *     ON DELETE CASCADE FK cleaning up its child rows. Admin
 *     chooses which links / IPI rows to keep on the surviving row.
 *   - Delete: removes a registry row, refusing by default if any
 *     song still cites the name; a force flag overrides.
 *   - View Songs: read-only modal listing every song that cites the
 *     person, grouped by role.
 *
 * Database access uses mysqli prepared statements throughout
 * (project policy, set 2026-04-27). All mutating actions run inside
 * $db->begin_transaction() so a partial failure rolls back cleanly.
 * Every action emits a tblActivityLog row via logActivity() with
 * EntityType='musician'.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_soft_delete.php';   /* #1694 */
/* Shared musicians helpers (#719 PR 2d) — link-type catalogue +
   normalisers + flag-columns-exist probe. Same helpers used by the
   admin_musician_* API endpoints in /api.php so a tweak to the
   link-type set or the row-shape rules lands on both surfaces. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'musician_helpers.php';
/* #1785 §7/§8 — the registry-duplicate scan helper (the cheap Bucket-A-
   only CTA-badge count below) + the shared disambiguation payload
   builder the merge-target typeahead/Merge-modal preview consume
   (#1785 C8). require_once is idempotent. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'musician_duplicates.php';
/* Places registry helper — exposes placesUpsertFromPayload() +
   musicianPlaceIdColumnsExist(), used by the add / update_person
   handlers to persist BirthPlaceId / DeathPlaceId alongside the
   legacy BirthPlace / DeathPlace display strings. Schema-tolerant —
   no-ops on installs that haven't run migrate-places.php yet. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'places.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_musicians', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_musicians required</h1></body></html>';
    exit;
}
$activePage = 'musicians';

$error   = '';
$success = '';
$csrf    = csrfToken();

/* ----------------------------------------------------------------------
 * Activity-log helper
 *
 * Every mutating action on this page emits a tblActivityLog row via
 * logActivity() (which still lives in /includes/activity_log.php
 * and is PDO-backed until the umbrella migration #554 Batch 4 lands;
 * mixing the two helpers in the same request is fine — they manage
 * independent connections).
 *
 * The closure bakes in the EntityType so per-action call sites stay
 * tight and any future rename of the type string is a one-line
 * change here. Silent no-op if the activity-log table is missing
 * (matches the pattern in songbooks.php / organisations.php).
 * ---------------------------------------------------------------------- */
$logMusician = static function (string $action, string $entityId, array $details): void {
    if (function_exists('logActivity')) {
        try {
            /* #1741 P2-B — page-side log keys are `admin.musicians.*`
               (the API-side sibling is `api.admin.musician.*`, api.php);
               was bare `credit_person.<action>` before the rename. */
            logActivity('admin.musicians.' . $action, 'musician', $entityId, $details);
        } catch (\Throwable $_e) { /* audit is best-effort */ }
    }
};

/* ----------------------------------------------------------------------
 * Helpers — link / IPI sub-form normalisation (#719 PR 2d)
 *
 * The link-type catalogue and the two normalisers live in
 * /includes/musician_helpers.php. The closures kept here are
 * thin wrappers so the existing call sites below ($normaliseLinks,
 * $normaliseIpi) keep working unchanged. (The flag-columns-exist probe
 * this comment used to mention its own page-local wrapper for,
 * `_musFlagsColumnsExist()`, was removed in #1741 P4a — the add/
 * update_person handlers no longer branch on it directly, since
 * IsSpecialCase/IsGroup are now written ONLY by
 * `musicianTypeApply()` in musician_helpers.php.) */
$ALIAS_TYPES         = MUSICIAN_ALIAS_TYPES;
$normaliseLinks      = static fn(\mysqli $db, mixed $raw): array => normaliseMusicianLinks($db, $raw);
$normaliseIpi        = static fn(mixed $raw): array => normaliseMusicianIpi($raw);
$normaliseIsni       = static fn(mixed $raw): array => normaliseMusicianIsni($raw);
/* #1348 — generic "Other identifiers" sub-form (VIAF / Wikidata / ORCID).
   Unlike IPI/ISNI (each its own dedicated section) these share ONE
   repeatable section with a per-row type picker, so the normaliser
   validates each row's type against a fixed allow-list (rule #20 — never
   insert an arbitrary IdentifierType) and returns rows in the exact shape
   the INSERT loops below bind: ['type','value','name_used','notes']. */
$normaliseOtherId = static function (mixed $raw): array {
    if (!is_array($raw)) return [];
    /* #1367 — the allow-list is now DERIVED from the central authority-control
       identifier registry (creditIdentifierPickable()), not three hard-coded
       slugs. Adding a provider there flows through here automatically, with no
       schema change (IdentifierType is VARCHAR; rule #20). */
    $allowed = array_keys(creditIdentifierPickable());
    $out = [];
    foreach ($raw as $row) {
        if (!is_array($row)) continue;
        /* Lowercase + trim (#trim, musTrimmed() — shared helper, see
           includes/musician_helpers.php) the type, then gate on the
           allow-list so a hand-crafted POST can't smuggle an unrecognised
           IdentifierType. */
        $type = strtolower(musTrimmed($row['type'] ?? ''));
        if (!in_array($type, $allowed, true)) continue;
        /* Skip rows with no identifier value — an empty row is just an
           unfilled template the curator left behind (rejected silently,
           never validated). */
        $value = musTrimmed($row['value'] ?? ''); // #trim
        if ($value === '') continue;
        /* #1367 — the curator may have pasted the full authority URL into the
           value box; fold it back to the bare id BEFORE validation so both
           paste shapes (bare id OR URL) round-trip identically. */
        $value = creditIdentifierNormalise($type, $value);
        /* Reject a malformed value (would render a dead chip link) rather than
           storing it. Lenient: the empty case already short-circuited above,
           so this only fires on a genuinely non-empty-but-wrong value. */
        if (!creditIdentifierValidate($type, $value)) continue;
        $out[] = [
            'type'      => $type,
            'value'     => $value,
            'name_used' => musTrimmed($row['name_used'] ?? '') ?: null, // #trim
            'notes'     => musTrimmed($row['notes']     ?? '') ?: null, // #trim
        ];
    }
    return $out;
};
$normaliseAliases    = static fn(mixed $raw): array => normaliseMusicianAliases($raw);

/* External-link type registry — pulled inside each action handler
   below from $linkTypesForPerson, populated near the page-render
   section once $db is in scope. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes'
    . DIRECTORY_SEPARATOR . 'external_link_helpers.php';

/* ----------------------------------------------------------------------
 * GET endpoint — view_songs JSON
 *
 * Returns the list of songs that cite the given person, grouped by
 * role. Used by the View Songs modal. Standalone — short-circuits
 * the rest of the page (returns JSON, then exit).
 *
 * GET ?action=view_songs&id=<registry-id>
 *
 * Looks up the person's name from the registry row, then queries the
 * five song-credit tables joined to tblSongs for the human-friendly
 * Title + SongbookAbbr + Number used in the modal listing.
 * ---------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string)($_GET['action'] ?? '') === 'view_songs') {
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    $personId = (int)($_GET['id'] ?? 0);
    if ($personId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'id is required']);
        exit;
    }

    try {
        $db = getDbMysqli();
        $stmt = $db->prepare('SELECT Name FROM tblMusicians WHERE Id = ?');
        $stmt->bind_param('i', $personId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        $name = (string)($row[0] ?? '');
        if ($name === '') {
            http_response_code(404);
            echo json_encode(['error' => 'Person not found.']);
            exit;
        }

        /* For each role table, fetch the songs that cite this name.
           Joining to tblSongs on SongId gives us the human-friendly
           Title + SongbookAbbr + Number for the modal. #1785 C5 —
           MUSICIAN_CREDIT_ROLE_TABLES (was a hand-typed 5-role map
           missing 'artist', so a person credited only as a performing
           artist never showed any songs in this modal). */
        $byRole = [];
        foreach (MUSICIAN_CREDIT_ROLE_TABLES as $role => $tbl) {
            /* #1694 — visible songs only, matching the public person page.
               (Person merges/renames rewrite tblSong* rows BY NAME, unfiltered,
               so hidden rows are still correctly rewritten either way.)
               @disabled-visible: admin surface (#1765) — disabled songbooks
               stay fully visible/editable in /manage (owner decision); a
               song in a disabled book still needs to show here so a
               merge/rename touches it. */
            $sql = "SELECT s.SongId, s.Title, s.SongbookAbbr, s.Number
                      FROM {$tbl} c
                      JOIN tblSongs s ON s.SongId = c.SongId
                     WHERE c.Name = ? AND " . songVisibleSql($db, 's') . "
                     ORDER BY s.SongbookAbbr ASC, s.Number ASC, s.Title ASC";
            $stmt = $db->prepare($sql);
            $stmt->bind_param('s', $name);
            $stmt->execute();
            $byRole[$role] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }

        echo json_encode([
            'name'    => $name,
            'by_role' => $byRole,
            'total'   => array_sum(array_map('count', $byRole)),
        ]);
        exit;
    } catch (\Throwable $e) {
        error_log('[manage/musicians.php] view_songs failed: ' . $e->getMessage());
        logActivityError('admin.musicians.view_songs', 'musician', '', $e);
        http_response_code(500);
        echo json_encode(['error' => 'Could not load songs.']);
        exit;
    }
}

/* ----------------------------------------------------------------------
 * GET endpoint — merge_target_search JSON typeahead (#1500)
 *
 * Replaces the old "every person in one giant <select>" Merge-modal
 * Target picker, which stopped being usable once the registry grew past
 * a few hundred rows (a long native <select> is slow to scroll and
 * offers no real type-to-filter). Mirrors the <input> + <datalist> +
 * debounced-GET pattern already proven on /manage/songbooks
 * (parent_search / compiler_search): the client debounces keystrokes
 * into this endpoint, rebuilds a <datalist>, and the curator either
 * picks a suggestion or keeps typing something with no match — rejected
 * at submit time (the Merge button stays disabled until a real pick
 * resolves a key).
 *
 * GET ?action=merge_target_search&q=<text>&exclude_id=<id>&exclude_name=<name>&limit=<n>
 *
 * The query logic (registry rows + in-use-only names) lives in the
 * shared searchMusicianMergeTargets() helper — see
 * includes/musician_helpers.php — so it's testable independent of
 * this dispatch block and reusable if another surface ever needs the
 * same "pick a credit person" search.
 *
 * #1785 C8 — ADDITIVE disambiguation fields (plan §8.1). A candidate
 * whose "name" collides visually with another (two byte-variants, or
 * just two different people who share a spelling) used to render as two
 * identical-looking <option> labels — "which is merging into which?"
 * (problem 2 of the #1785 issue body). Every REGISTRY candidate (never
 * the in-use-only bucket — hydrating that would need a second, non-id
 * lookup path musicianDisambiguationPayloadBulk() doesn't offer) now
 * carries disambiguation/born/died/type/useCount from the SAME shared
 * payload builder the review page uses (rule #22 — one builder, never
 * re-forked per surface), plus `variant`: the byte-variant classification
 * of this candidate AGAINST THE SOURCE name (musicianNameVariantClass(),
 * only computed when `exclude_name` is present — i.e. an open merge
 * always supplies it). All additive — the pre-existing
 * {key,id,name,total} shape is untouched, so the member-picker consumer
 * (which filters on `c.id` alone, musicians.php's memberLabelToKey/
 * portrayedByLabelToKey blocks below) is unaffected. -------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string)($_GET['action'] ?? '') === 'merge_target_search') {
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    $q           = trim((string)($_GET['q'] ?? ''));
    $excludeId   = (int)($_GET['exclude_id'] ?? 0);
    $excludeName = trim((string)($_GET['exclude_name'] ?? ''));
    $limit       = max(1, min(30, (int)($_GET['limit'] ?? 20)));

    try {
        $db = getDbMysqli();
        $candidates = searchMusicianMergeTargets($db, $q, $excludeId, $excludeName, $limit);

        /* #1785 C8 — hydrate the registry-bucket candidates (those with
           a real `id`) with the shared disambiguation payload, in ONE
           bulk round-trip rather than per-row. */
        $registryIds = array_values(array_filter(array_map(
            static fn(array $c) => $c['id'],
            $candidates
        )));
        $payloads = $registryIds ? musicianDisambiguationPayloadBulk($db, $registryIds) : [];
        foreach ($candidates as &$c) {
            if ($c['id'] === null || !isset($payloads[$c['id']])) { continue; }
            $p = $payloads[$c['id']];
            $c['disambiguation'] = $p['disambiguation'];
            $c['born']           = $p['born'];
            $c['died']           = $p['died'];
            $c['type']           = $p['type'];
            $c['useCount']       = $p['useCount'];
            $c['variant']        = $excludeName !== '' ? musicianNameVariantClass($excludeName, $c['name']) : null;
        }
        unset($c);

        echo json_encode(['candidates' => $candidates], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (\Throwable $e) {
        error_log('[manage/musicians.php] merge_target_search failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Search failed.']);
        exit;
    }
}

/* ----------------------------------------------------------------------
 * POST endpoints — add_member / remove_member (#1502)
 *
 * ELI5: these are the "save" buttons for the Members card-list inside
 * the Edit-person drawer, for a person flagged as a Group. Each add /
 * remove persists immediately (not tied to the drawer's main Save
 * button) so a curator building up a member list sees each pick land
 * right away and can correct a mistake with one click, the same way
 * the merge_target_search typeahead above resolves picks live.
 *
 * DETAILED: dispatched here — BEFORE the classic "POST dispatch" switch
 * below — so they can use validateCsrfRequest() (#1352 rule #29) rather
 * than the classic validateCsrf() the rest of this page's single big
 * switch still uses. validateCsrfRequest() accepts EITHER a still-valid
 * session token OR a same-origin AJAX request (X-Requested-With header +
 * matching Origin/Referer host, if present) — the client below sends
 * both, so it never goes stale even if the drawer stays open a long
 * time. Both actions return JSON and `exit` immediately; on any
 * non-match they fall through to the rest of the file unchanged.
 *
 * POST action=add_member    group_id=<int> member_id=<int> [relation_type] [date_from] [date_to] [note]
 * POST action=remove_member group_id=<int> member_id=<int>
 *
 * #1741 P4a — `add_member` now grows the optional relation_type/date_from/
 * date_to/note params and delegates straight to the generalised
 * addMusicianRelation() (relation_type defaults to 'member' when absent,
 * so the pre-P4a two-param POST shape still works unchanged). The actual
 * validation (self-membership guard, IsGroup-subject guard, idempotent
 * dupe handling, table-existence gate) lives in that shared helper — see
 * includes/musician_helpers.php — so it's testable independent of this
 * dispatch block. `remove_member` stays on removeMusicianGroupMember()
 * (a 'member'-scoped delete by the (group,member) pair); the general
 * by-Id `remove_relation` endpoint below is what a Portrayed-by/Portrays
 * row (which has no natural group/member pair) uses instead.
 * ---------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'add_member') {
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    if (!validateCsrfRequest((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF check failed — please retry.']);
        exit;
    }

    $groupId  = (int)($_POST['group_id']  ?? 0);
    $memberId = (int)($_POST['member_id'] ?? 0);
    /* #1741 P4a — optional relation dates/note. relation_type is pinned to
       'member' here regardless of what's posted — this endpoint is
       specifically the Members card-list; a 'portrays' add always goes
       through action=add_relation below. */
    $memPb = partialDateParse((string)($_POST['date_from'] ?? ''));
    $memPd = partialDateParse((string)($_POST['date_to']   ?? ''));
    $memDateFrom = $memPb['ok'] ? $memPb['date'] : null;
    $memDateFromPrec = $memPb['ok'] ? $memPb['precision'] : null;
    $memDateTo = $memPd['ok'] ? $memPd['date'] : null;
    $memDateToPrec = $memPd['ok'] ? $memPd['precision'] : null;
    $memNote = musTrimmed($_POST['note'] ?? '') ?: null; // #trim

    try {
        $db     = getDbMysqli();
        $result = addMusicianRelation(
            $db, $groupId, $memberId, 'member',
            $memDateFrom, $memDateFromPrec, $memDateTo, $memDateToPrec, $memNote
        );
        if (!$result['ok']) {
            http_response_code(400);
            echo json_encode($result);
            exit;
        }
        $logMusician('member_add', (string)$groupId, [
            'group_id'  => $groupId,
            'member_id' => $memberId,
            'date_from' => $memDateFrom,
            'date_to'   => $memDateTo,
        ]);
        echo json_encode($result);
        exit;
    } catch (\Throwable $e) {
        error_log('[manage/musicians.php] add_member failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not add member.']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'remove_member') {
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    if (!validateCsrfRequest((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF check failed — please retry.']);
        exit;
    }

    $groupId  = (int)($_POST['group_id']  ?? 0);
    $memberId = (int)($_POST['member_id'] ?? 0);

    try {
        $db     = getDbMysqli();
        $result = removeMusicianGroupMember($db, $groupId, $memberId);
        if (!$result['ok']) {
            http_response_code(400);
            echo json_encode($result);
            exit;
        }
        $logMusician('member_remove', (string)$groupId, [
            'group_id'  => $groupId,
            'member_id' => $memberId,
        ]);
        echo json_encode($result);
        exit;
    } catch (\Throwable $e) {
        error_log('[manage/musicians.php] remove_member failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not remove member.']);
        exit;
    }
}

/* ----------------------------------------------------------------------
 * POST endpoints — add_relation / remove_relation (#1741 P4a)
 *
 * ELI5: the general-purpose write pair for tblMusicianRelations, covering
 * BOTH relation types. Both are what the "Portrayed by" sub-panel on a
 * character/other-type row's Edit drawer (§1.2.10) POSTs to — `add_relation`
 * on pick, `remove_relation` (by the relation row's OWN Id, carried in the
 * rendered row as `relationId` — see loadMusicianPortrayedBy()'s
 * `m.Id AS RelationId` select + musicianRelationRowShape()'s doc-block) on
 * the row's Remove button. Members stays on its own add_member/
 * remove_member pair above (a 'member'-typed thin wrapper deleting by the
 * (group,member) pair — no relation-row-Id tracking needed there). Same
 * live-persist-on-pick shape and the same validateCsrfRequest() same-origin
 * CSRF check as add_member/remove_member.
 *
 * POST action=add_relation    subject_id=<int> object_id=<int> relation_type=<string> [date_from] [date_to] [note]
 * POST action=remove_relation id=<int>   (the tblMusicianRelations row's own Id)
 *
 * Validation (self-relation guard, column-gating, idempotent dedupe)
 * lives in addMusicianRelation() / removeMusicianRelation() — see
 * includes/musician_helpers.php.
 * ---------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'add_relation') {
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    if (!validateCsrfRequest((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF check failed — please retry.']);
        exit;
    }

    $subjectId    = (int)($_POST['subject_id'] ?? 0);
    $objectId     = (int)($_POST['object_id']  ?? 0);
    $relationType = musTrimmed($_POST['relation_type'] ?? 'portrays'); // #trim
    $relPb = partialDateParse((string)($_POST['date_from'] ?? ''));
    $relPd = partialDateParse((string)($_POST['date_to']   ?? ''));
    $relDateFrom = $relPb['ok'] ? $relPb['date'] : null;
    $relDateFromPrec = $relPb['ok'] ? $relPb['precision'] : null;
    $relDateTo = $relPd['ok'] ? $relPd['date'] : null;
    $relDateToPrec = $relPd['ok'] ? $relPd['precision'] : null;
    $relNote = musTrimmed($_POST['note'] ?? '') ?: null; // #trim

    try {
        $db     = getDbMysqli();
        $result = addMusicianRelation(
            $db, $subjectId, $objectId, $relationType,
            $relDateFrom, $relDateFromPrec, $relDateTo, $relDateToPrec, $relNote
        );
        if (!$result['ok']) {
            http_response_code(400);
            echo json_encode($result);
            exit;
        }
        $logMusician('relation_add', (string)$subjectId, [
            'subject_id'    => $subjectId,
            'object_id'     => $objectId,
            'relation_type' => $relationType,
            'date_from'     => $relDateFrom,
            'date_to'       => $relDateTo,
        ]);
        echo json_encode($result);
        exit;
    } catch (\Throwable $e) {
        error_log('[manage/musicians.php] add_relation failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not add relation.']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'remove_relation') {
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    if (!validateCsrfRequest((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF check failed — please retry.']);
        exit;
    }

    $relationId = (int)($_POST['id'] ?? 0);

    try {
        $db     = getDbMysqli();
        $result = removeMusicianRelation($db, $relationId);
        if (!$result['ok']) {
            http_response_code(400);
            echo json_encode($result);
            exit;
        }
        $logMusician('relation_remove', (string)$relationId, ['id' => $relationId]);
        echo json_encode($result);
        exit;
    } catch (\Throwable $e) {
        error_log('[manage/musicians.php] remove_relation failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not remove relation.']);
        exit;
    }
}

/* ----------------------------------------------------------------------
 * POST endpoint — bulk_register_unregistered (#1503)
 *
 * ELI5: the "Bulk promote with fuzzy-match" flow on
 * /manage/musicians-bulk-promote (#846) is built to catch NEAR
 * DUPLICATES before creating a registry row — a curator has to open
 * that page, review every candidate's suggested match (or lack of
 * one), and submit. That review is exactly right when a fuzzy match
 * exists, but it's unnecessary friction for the names that have NO
 * match at all — this one-click action registers every one of those
 * directly from the parent page's "N names cited ... aren't in the
 * registry yet" banner, no navigation required.
 *
 * DETAILED: reuses the SAME idempotent insertion point every other
 * registration path in this codebase uses — registerMusicianByName()
 * (includes/musician_helpers.php) — never a duplicated `INSERT INTO
 * tblMusicians` here. Idempotency is layered two ways: (1) the
 * candidate list itself only contains names with NOT EXISTS a matching
 * registry row at query time (musicianCitedUnregisteredNames()), and
 * (2) each iteration re-checks Name existence immediately before calling
 * registerMusicianByName() (mirroring musicians-bulk-promote.php's
 * own 'register' branch) so a name registered by a concurrent editor
 * between the query and this loop is skipped, not double-inserted or
 * erroring on the UNIQUE constraint. CSRF via validateCsrfRequest() (rule
 * #29) even though this is a classic (non-AJAX) form POST — the client
 * still submits the page's session-baked csrf_token, so this simply
 * accepts that same token via the more robust check rather than
 * validateCsrf() directly. Reports via the same $success banner +
 * bulk_run_id + affected-count shape the fuzzy-promote page's own
 * 'bulk_promote' action uses, so the two flows read consistently in the
 * activity log.
 *
 * POST action=bulk_register_unregistered
 * ---------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'bulk_register_unregistered') {
    if (!validateCsrfRequest((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }

    try {
        $db    = getDbMysqli();
        $names = musicianCitedUnregisteredNames($db);

        $bulkRunId  = bin2hex(random_bytes(6));
        $registered = 0;
        $skipped    = 0;

        $db->begin_transaction();
        try {
            foreach ($names as $name) {
                $stmt = $db->prepare('SELECT Id FROM tblMusicians WHERE Name = ? LIMIT 1');
                $stmt->bind_param('s', $name);
                $stmt->execute();
                $existing = $stmt->get_result()->fetch_row();
                $stmt->close();
                if ($existing) { $skipped++; continue; }

                $newId = registerMusicianByName($db, $name);
                if ($newId > 0) { $registered++; } else { $skipped++; }
            }

            /* #1784 — self-heal the byte-variant names the register loop above
               "skips". A candidate like "Eddie James " (trailing space) already
               has a collation-matching registry row ("Eddie James"), so the loop
               finds it and skips — but the credit rows keep their stray bytes and
               the counter never clears. Reconciliation rewrites those credit rows
               to the registry's exact spelling (and auto-registers any name with
               no registry row at all), so pressing "Add all now" actually drives
               the counter to zero. Same shared core the migration runs (rule #22);
               runs inside this same transaction. */
            $reconcile = musicianReconcileCreditNameBytes($db);

            $db->commit();

            $logMusician('bulk_register_remaining', '', [
                'bulk_run_id' => $bulkRunId,
                'registered'  => $registered,
                'skipped'     => $skipped,
                'candidates'  => count($names),
                'reconciled_rewritten' => $reconcile['rewritten'],
                'reconciled_adopted'   => $reconcile['adopted'],
                'reconciled_new'       => $reconcile['registered'],
                'reconciled_ambiguous' => $reconcile['ambiguous'],
            ]);
            $reconFixed = $reconcile['rewritten'] + $reconcile['registered'];
            $success = "Bulk run {$bulkRunId} done: {$registered} name" . ($registered === 1 ? '' : 's')
                     . ' registered'
                     . ($reconFixed > 0 ? ", {$reconcile['adopted']} matched an existing registry spelling and {$reconcile['rewritten']} credit row(s) tidied" : '')
                     . ($reconcile['ambiguous'] > 0 ? ", {$reconcile['ambiguous']} left for duplicate review (registry has two matching rows)" : '')
                     . '.';
        } catch (\Throwable $tx) {
            $db->rollback();
            throw $tx;
        }
    } catch (\Throwable $e) {
        error_log('[manage/musicians.php] bulk_register_unregistered failed: ' . $e->getMessage());
        $error = 'Bulk register failed: ' . $e->getMessage();
    }
}

/* ----------------------------------------------------------------------
 * POST dispatch
 *
 * All actions are CSRF-checked + entitlement-gated (the entitlement
 * gate is the page-level requireAuth + userHasEntitlement above; the
 * gate covers every action below). Each action runs inside a single
 * $db->begin_transaction() so a partial failure (e.g. a child-row
 * INSERT failing after the parent UPDATE landed) rolls back cleanly.
 *
 * `bulk_register_unregistered` (#1503) is excluded here — it's already
 * fully handled by its own dispatch block above (which falls through to
 * the page render rather than `exit`ing, so $success/$error show in the
 * normal banner). Without this exclusion the action would additionally
 * hit this block's validateCsrf() a second time for no purpose (it
 * wouldn't match any `case` below either way, but a stale-token edge
 * case there would blow up a request this file's own dispatch already
 * accepted via the more robust validateCsrfRequest()).
 * ---------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') !== 'bulk_register_unregistered') {
    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }

    $action = (string)($_POST['action'] ?? '');
    try {
        $db = getDbMysqli();

        switch ($action) {
            /* --------------------------------------------------------
             * add — create a new registry row (+ optional child rows)
             * -------------------------------------------------------- */
            case 'add': {
                /* #trim — every free-text/identifier field is read via the
                   shared musTrimmed() helper (includes/musician_helpers.php)
                   so leading/trailing whitespace (spaces, tabs, newlines —
                   a common paste artefact) never reaches validation/storage. */
                $name        = musTrimmed($_POST['name']         ?? '');
                $notesRaw    = musTrimmed($_POST['notes']        ?? '');
                $birthPlace  = musTrimmed($_POST['birth_place']  ?? '') ?: null;
                $deathPlace  = musTrimmed($_POST['death_place']  ?? '') ?: null;
                /* Partial birth/death dates — parse the flexible curator
                   input (YYYY / MM/YYYY / DD/MM/YYYY) into a normalised
                   DATE + a precision flag via the shared partial_date
                   helper. $birthDate / $deathDate stay the DATE the INSERT
                   below writes (now first-of-period for a partial); the
                   precision is persisted separately, gated on the column. */
                $pb = partialDateParse((string)($_POST['birth_date'] ?? ''));
                if (!$pb['ok']) { $error = 'Birth date: ' . $pb['error']; break; }
                $birthDate = $pb['date'];
                $birthPrec = $pb['precision'];
                $pd = partialDateParse((string)($_POST['death_date'] ?? ''));
                if (!$pd['ok']) { $error = 'Death date: ' . $pd['error']; break; }
                $deathDate = $pd['date'];
                $deathPrec = $pd['precision'];
                /* Places registry FKs — the live-autocomplete module
                   in js/modules/place-search.js fills these hidden
                   inputs with the tblPlaces.Id of the picked
                   candidate. Empty when the curator typed free text,
                   in which case we persist the display string only.
                   Schema-tolerant: the per-place UPDATE below skips
                   itself when migrate-places.php hasn't run yet. */
                $birthPlaceId = (int)($_POST['birth_place_id'] ?? 0) ?: null;
                $deathPlaceId = (int)($_POST['death_place_id'] ?? 0) ?: null;
                $notes       = $notesRaw !== '' ? $notesRaw : null;
                $links       = $normaliseLinks($db, $_POST['links']     ?? null);
                $ipi         = $normaliseIpi($_POST['ipi']         ?? null);
                $isni        = $normaliseIsni($_POST['isni']        ?? null);
                $aliases     = $normaliseAliases($_POST['aliases'] ?? null);
                $otherId     = $normaliseOtherId($_POST['otherid'] ?? null); // #1348
                /* #584 / #585 — classification flags. The two are
                   mutually exclusive in the UI; if both arrive we
                   prefer special-case (it's the more constraining
                   flag — it suppresses biographical fields). */
                $isSpecialCase = !empty($_POST['is_special_case']) ? 1 : 0;
                $isGroup       = !empty($_POST['is_group'])        ? 1 : 0;
                if ($isSpecialCase && $isGroup) { $isGroup = 0; }

                /* #1741 P4a — Type. Named $musicianType (NOT $type) because
                   this same case-block reuses the bare $type variable
                   further down as the tblMusicianIdentifiers IdentifierType
                   scratch var ('ipi' / 'isni') when writing the IPI/ISNI
                   sub-forms — reusing that name here would get silently
                   clobbered before logActivity() reads it back. When the
                   drawer's Type <select> posted a recognised MUSICIAN_TYPES
                   key (the column-exists branch, §1.2.8), it wins.
                   Otherwise (an install still on the two checkboxes)
                   synthesise $musicianType from them — group→'group',
                   special→'other', else 'person' — so EVERY install reaches
                   the same single write funnel (musicianTypeApply(), called
                   after the INSERT below) with a valid type either way.
                   $isSpecialCase/$isGroup are then re-derived FROM
                   $musicianType so the rest of this handler
                   (individual-only structured-name fields, IPI section)
                   sees a consistent classification regardless of which UI
                   shape posted. */
                $typePosted = musTrimmed($_POST['type'] ?? ''); // #trim
                if ($typePosted !== '' && array_key_exists($typePosted, MUSICIAN_TYPES)) {
                    $musicianType = $typePosted;
                } else {
                    $musicianType = $isGroup ? 'group' : ($isSpecialCase ? 'other' : 'person');
                }
                $isGroup       = (int)in_array($musicianType, ['group', 'orchestra'], true);
                $isSpecialCase = (int)in_array($musicianType, ['character', 'other'], true);

                /* #934 — structured-name parts. Only meaningful for
                   individuals; for Group / Special-case rows we leave
                   them NULL and use Name as-is. For individuals, Name
                   is recomputed from the three parts so the canonical
                   spelling stays consistent regardless of what the
                   client posted. */
                $firstNamesRaw = musTrimmed($_POST['first_names'] ?? ''); // #trim
                $surnameRaw    = musTrimmed($_POST['surname']     ?? ''); // #trim
                $suffixRaw     = musTrimmed($_POST['suffix']      ?? ''); // #trim
                $isIndividual  = (!$isSpecialCase && !$isGroup);
                if ($isIndividual && ($firstNamesRaw !== '' || $surnameRaw !== '')) {
                    $name = composePersonName($firstNamesRaw, $surnameRaw, $suffixRaw);
                }
                $firstNames = $isIndividual && $firstNamesRaw !== '' ? $firstNamesRaw : null;
                $surname    = $isIndividual && $surnameRaw    !== '' ? $surnameRaw    : null;
                $suffix     = $isIndividual && $suffixRaw     !== '' ? $suffixRaw     : null;
                /* #1501 — optional Maiden Surname. Individuals only, same
                   rule as the other structured-name parts above. */
                $maidenSurnameRaw = musTrimmed($_POST['maiden_surname'] ?? ''); // #trim
                $maidenSurname    = $isIndividual && $maidenSurnameRaw !== '' ? $maidenSurnameRaw : null;
                /* #1741 P4a — Biography (public bio, replaces Notes as the
                   public read path — musician.php's notes-as-bio-fallback)
                   + Disambiguation ("(the elder)"-style short parenthetical).
                   Always read (mirrors the maiden-surname "always render,
                   gate only the write" convention, #1501); persisted via a
                   separate column-gated UPDATE below (§0.3.3 — never added
                   to the multi-branch INSERT). */
                $biographyRaw     = musTrimmed($_POST['biography'] ?? ''); // #trim
                $biography        = $biographyRaw !== '' ? $biographyRaw : null;
                $disambiguation   = mb_substr(musTrimmed($_POST['disambiguation'] ?? ''), 0, 255); // #trim

                if ($name === '')                       { $error = 'Name is required.'; break; }
                if (mb_strlen($name) > 255)             { $error = 'Name must be 255 characters or fewer.'; break; }
                /* Date validation now lives in partialDateParse() above —
                   it rejects malformed input before this point. */

                /* Uniqueness check before opening the transaction so we
                   don't have to reason about MySQL's UNIQUE-violation
                   error code path. */
                $stmt = $db->prepare('SELECT Id FROM tblMusicians WHERE Name = ?');
                $stmt->bind_param('s', $name);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if ($exists) { $error = "A person with the name '{$name}' is already registered."; break; }

                $db->begin_transaction();
                try {
                    /* #1741 P4a — IsSpecialCase/IsGroup are no longer
                       written directly here (or anywhere outside
                       musicianTypeApply(), see below) — that dropped the
                       `hasFlagsCols` dimension this multi-branch INSERT
                       used to need, simplifying it from six shapes to four. */
                    /* #934 — name-parts columns only present after the
                       structured-name migration. Pre-migration installs
                       fall through to the legacy INSERT shape; the three
                       parts simply aren't persisted yet. */
                    $hasNameParts = musicianNamePartsColumnsExist($db);
                    /* Slug — NOT NULL DEFAULT '' with UNIQUE uk_Slug
                       per migrate-musicians-slug.php. Every INSERT
                       MUST carry a slug or it'll trip the orphan
                       empty-Slug collision documented in
                       migrate-musicians-slug-rebackfill.php.
                       Returns '' when the column doesn't exist yet so
                       a pre-migration install can still INSERT — the
                       helper omits the column conditionally below. */
                    $slug         = generateUniqueMusicianSlug($db, $name);
                    $hasSlugCol   = $slug !== '';
                    if ($hasNameParts && $hasSlugCol) {
                        $stmt = $db->prepare(
                            'INSERT INTO tblMusicians
                                (Name, Slug, Notes, BirthPlace, BirthDate, DeathPlace, DeathDate,
                                 FirstNames, Surname, Suffix)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                        );
                        $stmt->bind_param('ssssssssss',
                            $name, $slug, $notes, $birthPlace, $birthDate, $deathPlace, $deathDate,
                            $firstNames, $surname, $suffix
                        );
                    } elseif ($hasNameParts) {
                        $stmt = $db->prepare(
                            'INSERT INTO tblMusicians
                                (Name, Notes, BirthPlace, BirthDate, DeathPlace, DeathDate,
                                 FirstNames, Surname, Suffix)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                        );
                        $stmt->bind_param('sssssssss',
                            $name, $notes, $birthPlace, $birthDate, $deathPlace, $deathDate,
                            $firstNames, $surname, $suffix
                        );
                    } elseif ($hasSlugCol) {
                        $stmt = $db->prepare(
                            'INSERT INTO tblMusicians
                                (Name, Slug, Notes, BirthPlace, BirthDate, DeathPlace, DeathDate)
                             VALUES (?, ?, ?, ?, ?, ?, ?)'
                        );
                        $stmt->bind_param('sssssss',
                            $name, $slug, $notes, $birthPlace, $birthDate, $deathPlace, $deathDate
                        );
                    } else {
                        $stmt = $db->prepare(
                            'INSERT INTO tblMusicians
                                (Name, Notes, BirthPlace, BirthDate, DeathPlace, DeathDate)
                             VALUES (?, ?, ?, ?, ?, ?)'
                        );
                        $stmt->bind_param('ssssss',
                            $name, $notes, $birthPlace, $birthDate, $deathPlace, $deathDate
                        );
                    }
                    $stmt->execute();
                    $newId = (int)$db->insert_id;
                    $stmt->close();

                    /* #1741 P4a — THE ONE flags/Type write funnel (rule
                       guard: tests/php/test-musician-profile-fields.php's
                       flag-funnel-ban). Writes Type (when present) plus
                       its derived IsGroup/IsSpecialCase mirrors, or falls
                       back to the flags-only legacy shape. */
                    musicianTypeApply($db, $newId, $musicianType);

                    /* #1741 P4a — Biography + Disambiguation, a separate
                       column-gated UPDATE (§0.3.3 pattern — never folded
                       into the multi-branch INSERT above). */
                    $profileCols = musicianProfileColumnsExist($db);
                    $profSets = [];
                    $profTypes = '';
                    $profVals = [];
                    if ($profileCols['Biography']) { $profSets[] = 'Biography = ?'; $profTypes .= 's'; $profVals[] = $biography; }
                    if ($profileCols['Disambiguation']) { $profSets[] = 'Disambiguation = ?'; $profTypes .= 's'; $profVals[] = $disambiguation; }
                    if ($profSets) {
                        $profStmt = $db->prepare('UPDATE tblMusicians SET ' . implode(', ', $profSets) . ' WHERE Id = ?');
                        $profTypes .= 'i';
                        $profVals[] = $newId;
                        $profStmt->bind_param($profTypes, ...$profVals);
                        $profStmt->execute();
                        $profStmt->close();
                    }

                    /* Places FK assignment — separate UPDATE so the
                       multi-branch INSERT shapes above don't have to
                       care about the place-id columns. Skipped when
                       migrate-places.php hasn't landed yet. */
                    if (musicianPlaceIdColumnsExist($db)) {
                        $stmt = $db->prepare(
                            'UPDATE tblMusicians
                                SET BirthPlaceId = ?, DeathPlaceId = ?
                              WHERE Id = ?'
                        );
                        $stmt->bind_param('iii', $birthPlaceId, $deathPlaceId, $newId);
                        $stmt->execute();
                        $stmt->close();
                    }

                    /* Date precision flags — separate UPDATE, gated on the
                       precision columns (same partly-migrated tolerance as
                       the per-place FKs above). No-op on an un-migrated
                       install; the date itself already landed in the INSERT. */
                    musicianSaveDatePrecision($db, $newId, $birthPrec, $deathPrec);

                    /* #1501 — optional Maiden Surname, same separate-
                       statement / column-existence-gated pattern as the
                       two writes above. No-op on an un-migrated install. */
                    musicianSaveMaidenSurname($db, $newId, $maidenSurname);

                    if ($links) {
                        $linkStmt = $db->prepare(
                            'INSERT INTO tblMusicianExternalLinks
                                (MusicianId, LinkTypeId, Url, Note, SortOrder)
                             VALUES (?, ?, ?, ?, ?)'
                        );
                        foreach ($links as $l) {
                            $linkStmt->bind_param('iissi',
                                $newId, $l['type_id'], $l['url'], $l['label'], $l['sort_order']);
                            $linkStmt->execute();
                        }
                        $linkStmt->close();
                    }
                    if ($ipi || $isni || $otherId) {
                        $idStmt = $db->prepare(
                            'INSERT INTO tblMusicianIdentifiers
                                (MusicianId, IdentifierType, IdentifierValue, NameUsed, Notes)
                             VALUES (?, ?, ?, ?, ?)'
                        );
                        $type = 'ipi';
                        foreach ($ipi as $r) {
                            $idStmt->bind_param('issss',
                                $newId, $type, $r['number'], $r['name_used'], $r['notes']);
                            $idStmt->execute();
                        }
                        $type = 'isni';
                        foreach ($isni as $r) {
                            $idStmt->bind_param('issss',
                                $newId, $type, $r['number'], $r['name_used'], $r['notes']);
                            $idStmt->execute();
                        }
                        /* #1348 — generic identifiers (VIAF / Wikidata / ORCID).
                           The IdentifierType comes from each row (already
                           allow-list-validated by $normaliseOtherId), not a
                           fixed $type — every value is still bound. */
                        foreach ($otherId as $r) {
                            $idStmt->bind_param('issss',
                                $newId, $r['type'], $r['value'], $r['name_used'], $r['notes']);
                            $idStmt->execute();
                        }
                        $idStmt->close();
                    }
                    /* AKA / alias rows — schema-tolerant (helper no-ops
                       cleanly on installs that haven't run the
                       migrate-musicians-aliases.php migration yet). */
                    if ($aliases) {
                        replaceMusicianAliases($db, $newId, $aliases);
                    }
                    $db->commit();

                    $logMusician('add', (string)$newId, [
                        'name'        => $name,
                        'type'        => $musicianType, // #1741 P4a
                        'has_biography'  => $biography !== null, // #1741 P4a
                        'disambiguation' => $disambiguation !== '' ? $disambiguation : null, // #1741 P4a
                        'fields'      => array_filter([
                            'birth_place' => $birthPlace,
                            'birth_date'  => $birthDate,
                            'death_place' => $deathPlace,
                            'death_date'  => $deathDate,
                            'notes'       => $notes,
                        ], static fn($v) => $v !== null),
                        'link_count'  => count($links),
                        'ipi_count'   => count($ipi),
                        'isni_count'  => count($isni),
                    ]);
                    $success = "Person '{$name}' added to the registry.";
                } catch (\Throwable $e) {
                    $db->rollback();
                    throw $e;
                }
                break;
            }

            /* --------------------------------------------------------
             * update_person — edit metadata + replace child rows for
             *                 an existing registry row.
             *
             * The Name column is NOT changed by this action — renames
             * have their own handler because their blast radius is
             * cross-table. If the form's name field differs from the
             * stored name we reject and direct the admin to use Rename.
             * -------------------------------------------------------- */
            case 'update_person': {
                $id          = (int)($_POST['id']          ?? 0);
                /* #trim — see the shared musTrimmed() helper's docblock in
                   includes/musician_helpers.php. */
                $name        = musTrimmed($_POST['name']         ?? '');
                $notesRaw    = musTrimmed($_POST['notes']        ?? '');
                $birthPlace  = musTrimmed($_POST['birth_place']  ?? '') ?: null;
                $deathPlace  = musTrimmed($_POST['death_place']  ?? '') ?: null;
                /* Partial birth/death dates — same flexible-input parse as
                   the add handler (YYYY / MM/YYYY / DD/MM/YYYY → DATE +
                   precision). The UPDATE keeps writing $birthDate/$deathDate;
                   the precision is persisted separately, gated on the column. */
                $pb = partialDateParse((string)($_POST['birth_date'] ?? ''));
                if (!$pb['ok']) { $error = 'Birth date: ' . $pb['error']; break; }
                $birthDate = $pb['date'];
                $birthPrec = $pb['precision'];
                $pd = partialDateParse((string)($_POST['death_date'] ?? ''));
                if (!$pd['ok']) { $error = 'Death date: ' . $pd['error']; break; }
                $deathDate = $pd['date'];
                $deathPrec = $pd['precision'];
                $birthPlaceId = (int)($_POST['birth_place_id'] ?? 0) ?: null;
                $deathPlaceId = (int)($_POST['death_place_id'] ?? 0) ?: null;
                $notes       = $notesRaw !== '' ? $notesRaw : null;
                $links       = $normaliseLinks($db, $_POST['links']     ?? null);
                $ipi         = $normaliseIpi($_POST['ipi']         ?? null);
                $isni        = $normaliseIsni($_POST['isni']        ?? null);
                $aliases     = $normaliseAliases($_POST['aliases'] ?? null);
                $otherId     = $normaliseOtherId($_POST['otherid'] ?? null); // #1348
                $isSpecialCase = !empty($_POST['is_special_case']) ? 1 : 0;
                $isGroup       = !empty($_POST['is_group'])        ? 1 : 0;
                if ($isSpecialCase && $isGroup) { $isGroup = 0; }

                /* #1741 P4a — Type; see the identically-commented block in
                   the 'add' case above for why this is $musicianType, not
                   $type (this case-block also reuses bare $type as the
                   tblMusicianIdentifiers IdentifierType scratch var
                   below). */
                $typePosted = musTrimmed($_POST['type'] ?? ''); // #trim
                if ($typePosted !== '' && array_key_exists($typePosted, MUSICIAN_TYPES)) {
                    $musicianType = $typePosted;
                } else {
                    $musicianType = $isGroup ? 'group' : ($isSpecialCase ? 'other' : 'person');
                }
                $isGroup       = (int)in_array($musicianType, ['group', 'orchestra'], true);
                $isSpecialCase = (int)in_array($musicianType, ['character', 'other'], true);

                /* #934 — structured-name parts. For individuals we
                   recompose Name from the three fields and persist all
                   four columns; the rename guard below still rejects a
                   genuine Name change so curators can refine FirstNames
                   / Surname / Suffix without triggering it as long as
                   the composed Name matches the stored Name. For Group
                   / Special-case rows the three columns get NULL'd. */
                $firstNamesRaw = musTrimmed($_POST['first_names'] ?? ''); // #trim
                $surnameRaw    = musTrimmed($_POST['surname']     ?? ''); // #trim
                $suffixRaw     = musTrimmed($_POST['suffix']      ?? ''); // #trim
                $isIndividual  = (!$isSpecialCase && !$isGroup);
                if ($isIndividual && ($firstNamesRaw !== '' || $surnameRaw !== '')) {
                    $name = composePersonName($firstNamesRaw, $surnameRaw, $suffixRaw);
                }
                $firstNames = $isIndividual && $firstNamesRaw !== '' ? $firstNamesRaw : null;
                $surname    = $isIndividual && $surnameRaw    !== '' ? $surnameRaw    : null;
                $suffix     = $isIndividual && $suffixRaw     !== '' ? $suffixRaw     : null;
                /* #1501 — optional Maiden Surname. Individuals only, same
                   rule as the other structured-name parts above. */
                $maidenSurnameRaw = musTrimmed($_POST['maiden_surname'] ?? ''); // #trim
                $maidenSurname    = $isIndividual && $maidenSurnameRaw !== '' ? $maidenSurnameRaw : null;
                /* #1741 P4a — Biography + Disambiguation; see the
                   identically-commented block in the 'add' case above. */
                $biographyRaw     = musTrimmed($_POST['biography'] ?? ''); // #trim
                $biography        = $biographyRaw !== '' ? $biographyRaw : null;
                $disambiguation   = mb_substr(musTrimmed($_POST['disambiguation'] ?? ''), 0, 255); // #trim

                if ($id <= 0)                           { $error = 'Person id missing.'; break; }
                if ($name === '')                       { $error = 'Name is required.'; break; }
                /* Date validation now lives in partialDateParse() above. */

                /* Pull the before-row both as a sanity check (id valid?)
                   and to compute the audit-log diff. */
                $stmt = $db->prepare(
                    'SELECT Name, Notes, BirthPlace, BirthDate, DeathPlace, DeathDate
                       FROM tblMusicians WHERE Id = ?'
                );
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $beforeRow = $stmt->get_result()->fetch_assoc() ?: null;
                $stmt->close();
                if ($beforeRow === null) { $error = 'Person not found.'; break; }

                if ((string)$beforeRow['Name'] !== $name) {
                    $error = 'Use the Rename action to change a person\'s name — the change cascades to every song that cites them, so it has its own confirmation flow.';
                    break;
                }

                $db->begin_transaction();
                try {
                    /* Update the registry row. Name is not in the SET
                       clause — renames go through the rename action.
                       #1741 P4a — IsSpecialCase/IsGroup are no longer in
                       this SET clause (or anywhere outside
                       musicianTypeApply(), called below) — that dropped
                       the flags-columns-exist dimension this branch used
                       to need. */
                    /* #934 — name-parts columns gate. When present they
                       update alongside the existing fields; pre-migration
                       installs fall through to the legacy UPDATE shape. */
                    $hasNamePartsCols = musicianNamePartsColumnsExist($db);
                    if ($hasNamePartsCols) {
                        $stmt = $db->prepare(
                            'UPDATE tblMusicians
                                SET Notes = ?, BirthPlace = ?, BirthDate = ?,
                                    DeathPlace = ?, DeathDate = ?,
                                    FirstNames = ?, Surname = ?, Suffix = ?
                              WHERE Id = ?'
                        );
                        $stmt->bind_param('ssssssssi',
                            $notes, $birthPlace, $birthDate, $deathPlace, $deathDate,
                            $firstNames, $surname, $suffix, $id);
                    } else {
                        $stmt = $db->prepare(
                            'UPDATE tblMusicians
                                SET Notes = ?, BirthPlace = ?, BirthDate = ?,
                                    DeathPlace = ?, DeathDate = ?
                              WHERE Id = ?'
                        );
                        $stmt->bind_param('sssssi',
                            $notes, $birthPlace, $birthDate, $deathPlace, $deathDate, $id);
                    }
                    $stmt->execute();
                    $stmt->close();

                    /* #1741 P4a — THE ONE flags/Type write funnel (rule
                       guard: tests/php/test-musician-profile-fields.php's
                       flag-funnel-ban). */
                    musicianTypeApply($db, $id, $musicianType);

                    /* #1741 P4a — Biography + Disambiguation, a separate
                       column-gated UPDATE (§0.3.3 pattern), written
                       unconditionally (like the places/date-precision/
                       maiden-surname writes below) so clearing either
                       field also clears the stored value. */
                    $profileCols = musicianProfileColumnsExist($db);
                    $profSets = [];
                    $profTypes = '';
                    $profVals = [];
                    if ($profileCols['Biography']) { $profSets[] = 'Biography = ?'; $profTypes .= 's'; $profVals[] = $biography; }
                    if ($profileCols['Disambiguation']) { $profSets[] = 'Disambiguation = ?'; $profTypes .= 's'; $profVals[] = $disambiguation; }
                    if ($profSets) {
                        $profStmt = $db->prepare('UPDATE tblMusicians SET ' . implode(', ', $profSets) . ' WHERE Id = ?');
                        $profTypes .= 'i';
                        $profVals[] = $id;
                        $profStmt->bind_param($profTypes, ...$profVals);
                        $profStmt->execute();
                        $profStmt->close();
                    }

                    /* Places FK assignment — written unconditionally
                       so unsetting a previously-picked place clears
                       the FK back to NULL. Schema-tolerant — skipped
                       on installs that haven't run migrate-places.php
                       yet. */
                    if (musicianPlaceIdColumnsExist($db)) {
                        $stmt = $db->prepare(
                            'UPDATE tblMusicians
                                SET BirthPlaceId = ?, DeathPlaceId = ?
                              WHERE Id = ?'
                        );
                        $stmt->bind_param('iii', $birthPlaceId, $deathPlaceId, $id);
                        $stmt->execute();
                        $stmt->close();
                    }

                    /* Date precision flags — written unconditionally (gated
                       on the columns) so clearing a date also clears its
                       precision back to NULL. No-op on an un-migrated install. */
                    musicianSaveDatePrecision($db, $id, $birthPrec, $deathPrec);

                    /* #1501 — optional Maiden Surname, written unconditionally
                       (gated on the column) so clearing the field also clears
                       the stored value back to NULL. No-op on an un-migrated
                       install. */
                    musicianSaveMaidenSurname($db, $id, $maidenSurname);

                    /* Child rows: DELETE then INSERT — simpler than
                       diffing and the per-person row counts are small
                       (typically < 10 each). The child Ids change as a
                       side effect, but no other table references them. */
                    $del = $db->prepare('DELETE FROM tblMusicianExternalLinks WHERE MusicianId = ?');
                    $del->bind_param('i', $id);
                    $del->execute();
                    $del->close();
                    if ($links) {
                        $linkStmt = $db->prepare(
                            'INSERT INTO tblMusicianExternalLinks
                                (MusicianId, LinkTypeId, Url, Note, SortOrder)
                             VALUES (?, ?, ?, ?, ?)'
                        );
                        foreach ($links as $l) {
                            $linkStmt->bind_param('iissi',
                                $id, $l['type_id'], $l['url'], $l['label'], $l['sort_order']);
                            $linkStmt->execute();
                        }
                        $linkStmt->close();
                    }

                    $del = $db->prepare('DELETE FROM tblMusicianIdentifiers WHERE MusicianId = ?');
                    $del->bind_param('i', $id);
                    $del->execute();
                    $del->close();
                    if ($ipi || $isni || $otherId) {
                        $idStmt = $db->prepare(
                            'INSERT INTO tblMusicianIdentifiers
                                (MusicianId, IdentifierType, IdentifierValue, NameUsed, Notes)
                             VALUES (?, ?, ?, ?, ?)'
                        );
                        $type = 'ipi';
                        foreach ($ipi as $r) {
                            $idStmt->bind_param('issss',
                                $id, $type, $r['number'], $r['name_used'], $r['notes']);
                            $idStmt->execute();
                        }
                        $type = 'isni';
                        foreach ($isni as $r) {
                            $idStmt->bind_param('issss',
                                $id, $type, $r['number'], $r['name_used'], $r['notes']);
                            $idStmt->execute();
                        }
                        /* #1348 — generic identifiers (VIAF / Wikidata / ORCID).
                           Clean re-insert (the DELETE above cleared the set);
                           the IdentifierType comes from each allow-list-validated
                           row, every value bound. */
                        foreach ($otherId as $r) {
                            $idStmt->bind_param('issss',
                                $id, $r['type'], $r['value'], $r['name_used'], $r['notes']);
                            $idStmt->execute();
                        }
                        $idStmt->close();
                    }
                    /* Replace the alias set unconditionally — the curator's
                       posted list is the new truth; an empty list deletes
                       any pre-existing aliases. Schema-tolerant on installs
                       that haven't run migrate-musicians-aliases.php. */
                    replaceMusicianAliases($db, $id, $aliases);
                    $db->commit();

                    /* Compute the changed-fields list for audit. The
                       child-table replacement is captured as counts
                       only — diffing a links list is more noise than
                       signal in the activity-log timeline. */
                    $afterRow = [
                        'Notes'      => $notes,
                        'BirthPlace' => $birthPlace,
                        'BirthDate'  => $birthDate,
                        'DeathPlace' => $deathPlace,
                        'DeathDate'  => $deathDate,
                    ];
                    $changed = [];
                    foreach ($afterRow as $k => $v) {
                        if ((string)($beforeRow[$k] ?? '') !== (string)($v ?? '')) {
                            $changed[] = $k;
                        }
                    }
                    $logMusician('update_person', (string)$id, [
                        'name'       => $name,
                        'type'       => $musicianType, // #1741 P4a
                        'has_biography'  => $biography !== null, // #1741 P4a
                        'disambiguation' => $disambiguation !== '' ? $disambiguation : null, // #1741 P4a
                        'fields'     => $changed,
                        'before'     => array_intersect_key($beforeRow, array_flip($changed)),
                        'after'      => array_intersect_key($afterRow,  array_flip($changed)),
                        'link_count' => count($links),
                        'ipi_count'  => count($ipi),
                        'isni_count' => count($isni),
                    ]);
                    $success = "Person '{$name}' updated.";
                } catch (\Throwable $e) {
                    $db->rollback();
                    throw $e;
                }
                break;
            }

            /* --------------------------------------------------------
             * rename — change the canonical name, cascading the UPDATE
             *          across all six song-credit tables (#1785 C5 —
             *          MUSICIAN_CREDIT_ROLE_TABLES) AND the registry
             *          row inside one transaction.
             *
             * Refuses if the new name already belongs to a different
             * registry row (would clash with the UNIQUE Name index
             * and forces the admin to think about whether they
             * actually meant Merge instead).
             * -------------------------------------------------------- */
            case 'rename': {
                $id          = (int)($_POST['id'] ?? 0);
                $sourceName  = musTrimmed($_POST['source_name'] ?? ''); // #trim
                $newName     = musTrimmed($_POST['new_name'] ?? '');    // #trim

                if ($id <= 0 && $sourceName === '') { $error = 'Person id or source name missing.'; break; }
                if ($newName === '')           { $error = 'New name is required.'; break; }
                if (mb_strlen($newName) > 255) { $error = 'Name must be 255 characters or fewer.'; break; }

                /* In-use-only rename support (#626). When the row isn't
                   in the registry yet (Stuart Townend duplicate case),
                   the JS posts source_name instead of (or alongside)
                   id; we auto-register a row for it on the fly so the
                   rest of the rename code path doesn't have to branch. */
                if ($id <= 0 && $sourceName !== '') {
                    $reg = $db->prepare('SELECT Id FROM tblMusicians WHERE Name = ?');
                    $reg->bind_param('s', $sourceName);
                    $reg->execute();
                    $regRow = $reg->get_result()->fetch_row();
                    $reg->close();
                    if ($regRow) {
                        $id = (int)$regRow[0];
                    } else {
                        /* Route through the shared helper so the new
                           registry row carries a Slug — direct
                           `INSERT (Name)` would default Slug='' and
                           collide on uk_Slug whenever the orphan
                           empty-Slug row exists. */
                        $id = registerMusicianByName($db, $sourceName);
                    }
                }

                /* Look up the current name + check that the target
                   spelling isn't already in use by a different row. */
                $stmt = $db->prepare('SELECT Name FROM tblMusicians WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $oldName = (string)($row[0] ?? '');
                if ($oldName === '') { $error = 'Person not found.'; break; }
                if ($oldName === $newName) { $error = 'New name is the same as the current name.'; break; }

                $stmt = $db->prepare('SELECT Id FROM tblMusicians WHERE Name = ? AND Id <> ?');
                $stmt->bind_param('si', $newName, $id);
                $stmt->execute();
                $clash = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                if ($clash) {
                    $error = "Another registry row already uses '{$newName}'. Use Merge if you want to combine them.";
                    break;
                }

                $db->begin_transaction();
                try {
                    /* Cascade the rename across the six song-credit
                       tables (#1785 C5 — MUSICIAN_CREDIT_ROLE_TABLES;
                       was five, missing tblSongArtists). A song that
                       cites the old spelling under multiple roles is
                       updated in each table. */
                    $affected = [];
                    foreach (MUSICIAN_CREDIT_ROLE_TABLES as $tbl) {
                        $stmt = $db->prepare("UPDATE {$tbl} SET Name = ? WHERE Name = ?");
                        $stmt->bind_param('ss', $newName, $oldName);
                        $stmt->execute();
                        $affected[$tbl] = $stmt->affected_rows;
                        $stmt->close();
                    }

                    /* Update the registry row last — if any of the
                       cascades fail, rollback restores everything. */
                    $stmt = $db->prepare('UPDATE tblMusicians SET Name = ? WHERE Id = ?');
                    $stmt->bind_param('si', $newName, $id);
                    $stmt->execute();
                    $stmt->close();

                    $db->commit();

                    $logMusician('rename', (string)$id, [
                        'before'   => ['name' => $oldName],
                        'after'    => ['name' => $newName],
                        'affected' => [
                            'writers'     => $affected['tblSongWriters'],
                            'composers'   => $affected['tblSongComposers'],
                            'arrangers'   => $affected['tblSongArrangers'],
                            'adaptors'    => $affected['tblSongAdaptors'],
                            'translators' => $affected['tblSongTranslators'],
                            'artists'     => $affected['tblSongArtists'],
                        ],
                    ]);
                    $totalRenamed = array_sum($affected);
                    $success = "Renamed '{$oldName}' → '{$newName}' across {$totalRenamed} song-credit row(s).";
                } catch (\Throwable $e) {
                    $db->rollback();
                    throw $e;
                }
                break;
            }

            /* --------------------------------------------------------
             * merge — collapse two registry entries into one.
             *
             * Re-points every song-credit row from the source name to
             * the target name across all five tables, then deletes
             * the source registry row. ON DELETE CASCADE on the two
             * child tables removes the source's links + IPI rows.
             *
             * The admin selected which links / IPI rows to keep on
             * the surviving target via keep_links[] / keep_ipi[]
             * checkboxes — defaults to "keep all from both sides
             * with exact-match dedupe" which the JS pre-ticks.
             *
             * For the kept child rows from the source side, we
             * re-point MusicianId from source to target BEFORE
             * deleting the source row. That avoids the cascade
             * dropping rows we wanted to migrate.
             * -------------------------------------------------------- */
            case 'merge': {
                $sourceId   = (int)($_POST['source_id'] ?? 0);
                $targetId   = (int)($_POST['target_id'] ?? 0);
                $sourceName = musTrimmed($_POST['source_name'] ?? ''); // #trim
                $targetName = musTrimmed($_POST['target_name'] ?? ''); // #trim
                $keepLinks  = array_map('intval', (array)($_POST['keep_link_ids'] ?? []));
                $keepIpi    = array_map('intval', (array)($_POST['keep_ipi_ids']  ?? []));

                /* In-use-only merge support (#626) — the JS posts a
                   *_name fallback when a row isn't yet in the registry
                   (e.g. Stuart Townend duplicates). Auto-register
                   either side as needed so the rest of the merge code
                   path can assume both sides have a registry id. */
                $resolvePersonId = static function (int $id, string $name) use ($db): int {
                    if ($id > 0)       return $id;
                    /* registerMusicianByName handles the
                       lookup-or-insert with a slug — same shape this
                       closure used to have, minus the empty-slug
                       collision footgun. */
                    return registerMusicianByName($db, $name);
                };
                $sourceId = $resolvePersonId($sourceId, $sourceName);
                $targetId = $resolvePersonId($targetId, $targetName);

                /* Kept HERE (not delegated) so the page keeps its own
                   surface-appropriate wording — musicianMergeExecute()
                   (includes/musician_helpers.php, #1785 C4) re-validates
                   the same two conditions defensively for a caller that
                   skips this, but by pre-checking here only the "not
                   found" exception can ever reach the catch below. */
                if ($sourceId <= 0 || $targetId <= 0) { $error = 'Both source and target are required.'; break; }
                if ($sourceId === $targetId)          { $error = 'Source and target must be different people.'; break; }

                /* #1785 C4 — the ONE merge core, shared with api.php's
                   admin_musician_merge. Everything from the row lookup
                   through the five-table re-point, the link/IPI
                   kept-vs-dropped bookkeeping, and the source-row DELETE
                   (in its own transaction) now lives there. */
                try {
                    $report = musicianMergeExecute($db, $sourceId, $targetId, [
                        'keepLinkIds' => $keepLinks,
                        'keepIpiIds'  => $keepIpi,
                    ]);
                } catch (\InvalidArgumentException $e) {
                    // Only "Source/Target person not found." can reach here —
                    // see the pre-checks above.
                    $error = $e->getMessage();
                    break;
                }

                $logMusician('merge', (string)$targetId, [
                    'source'     => ['id' => $sourceId, 'name' => $report['sourceName']],
                    'target'     => ['id' => $targetId, 'name' => $report['targetName']],
                    'affected'   => [
                        'writers'     => $report['affected']['tblSongWriters'],
                        'composers'   => $report['affected']['tblSongComposers'],
                        'arrangers'   => $report['affected']['tblSongArrangers'],
                        'adaptors'    => $report['affected']['tblSongAdaptors'],
                        'translators' => $report['affected']['tblSongTranslators'],
                        'artists'     => $report['affected']['tblSongArtists'], // #1785 C5
                    ],
                    'child_rows' => [
                        'links_kept'        => $report['linksKept'],
                        'links_dropped'     => $report['linksDropped'],
                        'ipi_kept'          => $report['ipiKept'],
                        'ipi_dropped'       => $report['ipiDropped'],
                        // #1785 C5 — alias/relation carry-over + source-name-preserved-as-alias.
                        'aliases_moved'     => $report['aliasesMoved'],
                        'relations_moved'   => $report['relationsMoved'],
                        'source_name_aliased' => $report['sourceNameAliased'],
                    ],
                ]);
                $totalRenamed = array_sum($report['affected']);
                $success = "Merged '{$report['sourceName']}' → '{$report['targetName']}' ({$totalRenamed} song-credit row(s) re-pointed).";
                break;
            }

            /* --------------------------------------------------------
             * delete_from_registry — remove a registry row.
             *
             * Refuses by default if any song-credit table still cites
             * the name. The admin can override with force=1 to wipe
             * a registry row whose name is still in use; this is the
             * "drop a seed entry that was never adopted" use case the
             * issue body mentions.
             * -------------------------------------------------------- */
            case 'delete_from_registry': {
                $id    = (int)($_POST['id']    ?? 0);
                $force = !empty($_POST['force']);
                if ($id <= 0) { $error = 'Person id missing.'; break; }

                $stmt = $db->prepare('SELECT Name FROM tblMusicians WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                $name = (string)($row[0] ?? '');
                if ($name === '') { $error = 'Person not found.'; break; }

                /* Count how many song-credit rows still cite this name
                   across the six tables (#1785 C5 —
                   MUSICIAN_CREDIT_ROLE_TABLES; was five, missing
                   tblSongArtists — a person deletable-because-"unused"
                   could actually still be cited as a performing artist) —
                   a single query keeps the round-trip count to one. Table
                   names come from the hardcoded PHP constant (rule #5's
                   carve-out); the six repeated `?` placeholders all bind
                   the same $name value. */
                $countSql = implode(' + ', array_map(
                    static fn(string $t): string => "(SELECT COUNT(*) FROM {$t} WHERE Name = ?)",
                    MUSICIAN_CREDIT_ROLE_TABLES
                ));
                $stmt = $db->prepare("SELECT ({$countSql}) AS total");
                $nameBinds = array_fill(0, count(MUSICIAN_CREDIT_ROLE_TABLES), $name);
                $stmt->bind_param(str_repeat('s', count($nameBinds)), ...$nameBinds);
                $stmt->execute();
                $usage = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
                $stmt->close();

                if ($usage > 0 && !$force) {
                    $error = "Cannot delete '{$name}': {$usage} song-credit row(s) still cite this name. Tick the override box to delete anyway (the song credits stay — only the registry row is removed).";
                    break;
                }

                $stmt = $db->prepare('DELETE FROM tblMusicians WHERE Id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();

                $logMusician('delete_from_registry', (string)$id, [
                    'name'         => $name,
                    'had_credits'  => $usage > 0,
                    'force'        => $force,
                ]);
                $success = "Registry entry for '{$name}' removed.";
                break;
            }

            default:
                $error = 'Unknown action.';
        }
    } catch (\Throwable $e) {
        error_log('[manage/musicians.php] action=' . $action . ': ' . $e->getMessage());
        logActivityError('admin.musicians.save', 'musician', '', $e, [
            'action' => $action,
        ]);
        if ($error === '') {
            /* This page is gated to global_admin — surfacing the
               actual exception message + file:line is a UX win and
               carries no security trade-off (global admins are
               trusted with DB internals). Closes #635. */
            $where = $e->getFile() ? (' ' . basename($e->getFile()) . ':' . $e->getLine()) : '';
            $error = 'Database error: ' . $e->getMessage() . $where;
        }
    }
}

/* ----------------------------------------------------------------------
 * Data load
 *
 * Two queries, merged in PHP. Cleaner than a hand-rolled FULL OUTER
 * JOIN simulation, and the row count on these tables is small enough
 * that the merge cost is negligible.
 *
 *   Q1 — every distinct name across the five song-credit tables,
 *        with per-role counts and a total. Names not currently in
 *        any registry row also surface here.
 *   Q2 — every registry row in tblMusicians plus its biographical
 *        metadata + child-table counts. Registry rows that no song
 *        currently cites also surface here.
 * ---------------------------------------------------------------------- */

$people = [];
$countryOptions = [];

try {
    $db = getDbMysqli();

    /* Q1 — usage aggregate across the six song-credit tables (#1785 C5 —
       MUSICIAN_CREDIT_ROLE_TABLES; was five, missing tblSongArtists, so
       an artist-only credited name silently undercounted as "0 uses"
       and could never show up as "cited"). Table names + role labels
       come from the hardcoded PHP constant (rule #5's carve-out). */
    $creditUnion = implode("\n              UNION ALL\n              ", array_map(
        static fn(string $role, string $tbl): string =>
            "SELECT Name, '{$role}' AS kindLabel, COUNT(*) AS cnt FROM {$tbl} GROUP BY Name",
        array_keys(MUSICIAN_CREDIT_ROLE_TABLES),
        array_values(MUSICIAN_CREDIT_ROLE_TABLES)
    ));
    $usageSql = "
        SELECT Name,
               SUM(IF(kindLabel = 'writer',     cnt, 0)) AS WriterCount,
               SUM(IF(kindLabel = 'composer',   cnt, 0)) AS ComposerCount,
               SUM(IF(kindLabel = 'arranger',   cnt, 0)) AS ArrangerCount,
               SUM(IF(kindLabel = 'adaptor',    cnt, 0)) AS AdaptorCount,
               SUM(IF(kindLabel = 'translator', cnt, 0)) AS TranslatorCount,
               SUM(IF(kindLabel = 'artist',     cnt, 0)) AS ArtistCount,
               SUM(cnt) AS TotalUsage
          FROM (
              {$creditUnion}
          ) u
         GROUP BY Name
    ";
    $stmt = $db->prepare($usageSql);
    $stmt->execute();
    $usageRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    /* Q2 — registry rows + child-table counts (links, IPI). The
       sub-selects keep the query a single round-trip; if either child
       table grows large enough that the per-row sub-select cost shows
       up, swap to a GROUP BY join. Not now — current row counts make
       this trivially fast. */
    /* Build the SELECT with the classification flags from #584 / #585.
       The flags are loaded as 0/1 ints so the PHP-side merge below
       can pass them straight to JSON without further casting; the
       table renderer guards against missing columns when running on
       a schema that hasn't yet had migrate-musicians-flags
       applied. */
    $hasFlags = false;
    $colCheck = $db->query(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'tblMusicians'
            AND COLUMN_NAME  = 'IsSpecialCase'"
    );
    if ($colCheck && $colCheck->fetch_row() !== null) { $hasFlags = true; }
    if ($colCheck) { $colCheck->close(); }

    $flagCols = $hasFlags
        ? ', p.IsSpecialCase, p.IsGroup'
        : ', 0 AS IsSpecialCase, 0 AS IsGroup';

    /* #934 — pull the structured-name columns when present so the Edit
       drawer can pre-fill the three split fields. On a partly-migrated
       install (column missing) we surface NULLs and the form falls back
       to its single-Name behaviour. */
    $hasNameParts = musicianNamePartsColumnsExist($db);
    $namePartCols = $hasNameParts
        ? ', p.FirstNames, p.Surname, p.Suffix'
        : ', NULL AS FirstNames, NULL AS Surname, NULL AS Suffix';

    /* #1501 — optional Maiden Surname. Same column-existence-gated
       fallback as the name-parts trio above: a partly-migrated install
       (migrate-add-creditperson-maiden-surname.php not yet run) surfaces
       NULL and the field simply stays empty — dormant-safe. */
    $hasMaidenSurname = musicianMaidenSurnameColumnExists($db);
    $maidenSurnameCol = $hasMaidenSurname
        ? ', p.MaidenSurname'
        : ', NULL AS MaidenSurname';

    /* Places registry FKs — present after migrate-places.php has
       landed. Surfaced to JS so the Edit drawer can pre-fill the
       hidden place-id inputs alongside the visible display string
       (the place-search module re-uses these to mark the input as
       "already picked" without re-fetching). */
    $hasPlaceIds = musicianPlaceIdColumnsExist($db);
    $placeIdCols = $hasPlaceIds
        ? ', p.BirthPlaceId, p.DeathPlaceId'
        : ', NULL AS BirthPlaceId, NULL AS DeathPlaceId';

    /* Partial-date precision flags (migrate-add-creditpeople-date-
       precision.php) — pulled when present so the Edit drawer can render
       a year-only date as "1823" rather than "1823-01-01". On a partly-
       migrated install (column missing) we surface NULLs and the drawer
       falls back to treating every date as a full date via
       musicianDateInput(). The column-list strings are hardcoded
       constants, not user input — safe to interpolate (rule #5). */
    $hasDatePrecision = musicianDatePrecisionColumnsExist($db);
    $datePrecisionCols = $hasDatePrecision
        ? ', p.BirthDatePrecision, p.DeathDatePrecision'
        : ', NULL AS BirthDatePrecision, NULL AS DeathDatePrecision';

    /* Country / region surface (places sweep follow-up) — LEFT JOIN
       to tblPlaces on the birth-place FK so the list page can filter
       by country. The JOIN is only added when both the FK columns
       on tblMusicians AND tblPlaces itself are present. A row
       without a picked place (or on a pre-migration install) gets
       NULL country and falls under the "All" filter only. */
    $hasPlacesTable = placesTableExists($db);
    if ($hasPlaceIds && $hasPlacesTable) {
        $countryCols = ', bp.Country AS BirthCountry, bp.CountryCode AS BirthCountryCode';
        $countryJoin = ' LEFT JOIN tblPlaces bp ON bp.Id = p.BirthPlaceId';
    } else {
        $countryCols = ', NULL AS BirthCountry, NULL AS BirthCountryCode';
        $countryJoin = '';
    }

    /* #1741 P4a — Type / Biography / Disambiguation. Per-column gated
       (musicianProfileColumnsExist(), §0.3.4) — same "conditional select
       fragment" idiom as every other block above; each column drops out
       to a NULL-shaped default independently when absent. */
    $profileColsExist = musicianProfileColumnsExist($db);
    /* #1741 P4a — dated-relations columns (RelationType/DateFrom/…/Note),
       used further down to decide whether the Members add-row's optional
       date/note inputs, and the Portrayed-by sub-panel, render at all. */
    $relationColsExist = musicianRelationColumnsExist($db);
    $profileSelectCols =
        ($profileColsExist['Type'] ? ', p.Type' : ", 'person' AS Type")
      . ($profileColsExist['Biography'] ? ', p.Biography' : ', NULL AS Biography')
      . ($profileColsExist['Disambiguation'] ? ', p.Disambiguation' : ", '' AS Disambiguation");

    $registrySql = "
        SELECT p.Id,
               p.Name,
               p.Notes,
               p.BirthPlace,
               p.BirthDate,
               p.DeathPlace,
               p.DeathDate,
               p.UpdatedAt,
               (SELECT COUNT(*) FROM tblMusicianExternalLinks l WHERE l.MusicianId = p.Id) AS LinkCount,
               (SELECT COUNT(*) FROM tblMusicianIdentifiers i WHERE i.MusicianId = p.Id AND i.IdentifierType = 'ipi')  AS IPICount,
               (SELECT COUNT(*) FROM tblMusicianIdentifiers i WHERE i.MusicianId = p.Id AND i.IdentifierType = 'isni') AS ISNICount
               {$flagCols}
               {$namePartCols}
               {$maidenSurnameCol}
               {$placeIdCols}
               {$datePrecisionCols}
               {$countryCols}
               {$profileSelectCols}
          FROM tblMusicians p
          {$countryJoin}
    ";
    $stmt = $db->prepare($registrySql);
    $stmt->execute();
    $registryRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    /* Q3 + Q4 — pull every link / IPI row across all people so the
       Edit drawer can pre-fill without an extra round-trip. The two
       child tables are small (a handful of rows per person, low
       hundreds total), so loading them all is cheaper than fetching
       per-person on demand from JS. */
    $linksByPerson   = [];
    $ipiByPerson     = [];
    $isniByPerson    = [];
    $aliasesByPerson = [];
    $stmt = $db->prepare(
        'SELECT Id, MusicianId, LinkTypeId, Url, Note, SortOrder, Verified
           FROM tblMusicianExternalLinks
          ORDER BY MusicianId ASC, SortOrder ASC, Id ASC'
    );
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $l) {
        $linksByPerson[(int)$l['MusicianId']][] = [
            'id'         => (int)$l['Id'],
            'type_id'    => (int)$l['LinkTypeId'],
            'url'        => (string)$l['Url'],
            'label'      => $l['Note'],
            'sort_order' => (int)$l['SortOrder'],
            'verified'   => (int)$l['Verified'],
        ];
    }
    $stmt->close();

    /* #833 — load the registry for the edit drawer's link-type dropdown,
       once per page. Powers window._iHymnsLinkTypes for the shared
       editor module, and the inline <option> seed below. */
    $linkTypesForPerson = loadExternalLinkTypesFor($db, 'person');

    $stmt = $db->prepare(
        'SELECT Id, MusicianId, IdentifierType, IdentifierValue, NameUsed, Notes
           FROM tblMusicianIdentifiers
          ORDER BY MusicianId ASC, IdentifierType ASC, Id ASC'
    );
    /* #1348 — bucket for the generic "Other identifiers" rows (VIAF /
       Wikidata / ORCID), populated alongside isni/ipi from the same query. */
    $otherIdByPerson = [];
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $row = [
            'id'        => (int)$r['Id'],
            'number'    => (string)$r['IdentifierValue'],
            'name_used' => $r['NameUsed'],
            'notes'     => $r['Notes'],
        ];
        /* #1348 — three-way bucketing. The old else-branch swept EVERY
           non-isni type into the ipi bucket, mis-filing VIAF/Wikidata/ORCID
           rows; split them so each surfaces in its own sub-form. The generic
           bucket carries the row's type so the drawer can pre-select it. */
        if ($r['IdentifierType'] === 'isni') {
            $isniByPerson[(int)$r['MusicianId']][] = $row;
        } elseif ($r['IdentifierType'] === 'ipi') {
            $ipiByPerson[(int)$r['MusicianId']][] = $row;
        } else {
            $row['type'] = (string)$r['IdentifierType'];
            $otherIdByPerson[(int)$r['MusicianId']][] = $row;
        }
    }
    $stmt->close();

    /* AKA / aliases — bulk load alongside links + IPI so the Edit
       drawer's pre-fill can populate the chip-list without an extra
       round-trip. Schema-tolerant: pre-migration installs return an
       empty array (the helper checks INFORMATION_SCHEMA first). */
    $personIds = [];
    foreach ($registryRows as $r) { $personIds[] = (int)$r['Id']; }
    $aliasesByPerson = loadMusicianAliasesBulk($db, $personIds);

    /* Group membership (#1502) — bulk load alongside aliases so the
       Edit drawer's Members card-list can pre-fill without an extra
       round-trip. Schema-tolerant: pre-migration installs return an
       empty array (loadMusicianGroupMembersBulk() checks
       INFORMATION_SCHEMA first, same convention as aliases above). Only
       Group-flagged rows will ever have entries here in practice, but
       the bulk-load itself doesn't need to filter by IsGroup — a plain
       individual simply never appears as a key. */
    $membersByPerson = loadMusicianGroupMembersBulk($db, $personIds);

    /* #1741 P4a — "Portrayed by" bulk load, same convention as the
       Members bulk load above: only character/other-type rows will ever
       have entries here in practice, but the bulk-load itself doesn't
       filter by Type — the Edit drawer only RENDERS the sub-panel for
       those types (§1.2.10), it can pre-fill from this map regardless. */
    $portrayedByPerson = loadMusicianPortrayedByBulk($db, $personIds);

    /* Merge — keyed by Name. A name may appear in usage only,
       registry only, or both. */
    $byName = [];
    foreach ($usageRows as $u) {
        $name = (string)$u['Name'];
        $byName[$name] = [
            'name'        => $name,
            'writers'     => (int)$u['WriterCount'],
            'composers'   => (int)$u['ComposerCount'],
            'arrangers'   => (int)$u['ArrangerCount'],
            'adaptors'    => (int)$u['AdaptorCount'],
            'translators' => (int)$u['TranslatorCount'],
            'artists'     => (int)$u['ArtistCount'], // #1785 C5
            'total'       => (int)$u['TotalUsage'],
            'registry_id' => null,
            'notes'       => null,
            'birth_place' => null,
            'birth_place_id' => null,
            'birth_date'  => null,
            'birth_date_input' => '',
            'death_place' => null,
            'death_place_id' => null,
            'death_date'  => null,
            'death_date_input' => '',
            'updated_at'  => null,
            'link_count'  => 0,
            'ipi_count'   => 0,
            'is_special_case' => 0,   /* #584 */
            'is_group'        => 0,   /* #585 */
            'type'            => 'person', // #1741 P4a
            'biography'       => null,     // #1741 P4a
            'disambiguation'  => '',       // #1741 P4a
            'links'       => [],
            'ipi'         => [],
            'otherid'     => [],   // #1348
            'aliases'     => [],
            'members'     => [],   // #1502
            'portrayed_by' => [],  // #1741 P4a
        ];
    }
    foreach ($registryRows as $r) {
        $name = (string)$r['Name'];
        if (!isset($byName[$name])) {
            /* Registry-only — no song currently cites this person. */
            $byName[$name] = [
                'name'        => $name,
                'writers'     => 0,
                'composers'   => 0,
                'arrangers'   => 0,
                'adaptors'    => 0,
                'translators' => 0,
                'artists'     => 0, // #1785 C5
                'total'       => 0,
                'registry_id' => null,
                'notes'       => null,
                'birth_place' => null,
                'birth_place_id' => null,
                'birth_date'  => null,
                'birth_date_input' => '',
                'death_place' => null,
                'death_place_id' => null,
                'death_date'  => null,
                'death_date_input' => '',
                'updated_at'  => null,
                'link_count'  => 0,
                'ipi_count'   => 0,
                'is_special_case' => 0,
                'is_group'        => 0,
                'type'            => 'person', // #1741 P4a
                'biography'       => null,     // #1741 P4a
                'disambiguation'  => '',       // #1741 P4a
            ];
        }
        $byName[$name]['registry_id'] = (int)$r['Id'];
        $byName[$name]['notes']       = $r['Notes'];
        $byName[$name]['birth_place']    = $r['BirthPlace'];
        $byName[$name]['birth_place_id'] = isset($r['BirthPlaceId']) ? (int)$r['BirthPlaceId'] : null;
        $byName[$name]['birth_date']     = $r['BirthDate'];
        $byName[$name]['death_place']    = $r['DeathPlace'];
        $byName[$name]['death_place_id'] = isset($r['DeathPlaceId']) ? (int)$r['DeathPlaceId'] : null;
        $byName[$name]['death_date']     = $r['DeathDate'];
        /* Editor-input forms of the dates — a partial date round-trips as
           "1823" / "03/1823" rather than the normalised "1823-01-01".
           musicianDateInput() is column-gated: on an un-migrated install
           it falls back to formatting the stored DATE as a full date. The
           raw birth_date/death_date above stay the sortable ISO value the
           list-table data-sort-value + the lifespan render still use. */
        $byName[$name]['birth_date_input'] = musicianDateInput($db, $r['BirthDate'], $r['BirthDatePrecision'] ?? null);
        $byName[$name]['death_date_input'] = musicianDateInput($db, $r['DeathDate'], $r['DeathDatePrecision'] ?? null);
        /* Birth-country surface for the list-page filter (places
           follow-up). NULL on rows that have no picked place. */
        $byName[$name]['birth_country']      = $r['BirthCountry']     ?? null;
        $byName[$name]['birth_country_code'] = $r['BirthCountryCode'] ?? null;
        $byName[$name]['updated_at']  = $r['UpdatedAt'];
        $byName[$name]['link_count']  = (int)$r['LinkCount'];
        $byName[$name]['ipi_count']   = (int)$r['IPICount'];
        $byName[$name]['is_special_case'] = (int)($r['IsSpecialCase'] ?? 0);
        $byName[$name]['is_group']        = (int)($r['IsGroup']        ?? 0);
        /* #1741 P4a — Type / Biography / Disambiguation. The SELECT above
           already shapes absent-column defaults ('person' / NULL / ''),
           so no extra `?? ` fallback is needed here. */
        $byName[$name]['type']           = (string)$r['Type'];
        $byName[$name]['biography']      = $r['Biography'];
        $byName[$name]['disambiguation'] = (string)$r['Disambiguation'];
        /* #934 — structured-name parts surface to JS so the Edit drawer
           can pre-fill the three split fields. NULL on partly-migrated
           installs; the drawer's default-to-Name fallback handles that. */
        $byName[$name]['first_names'] = $r['FirstNames'] ?? null;
        $byName[$name]['surname']     = $r['Surname']    ?? null;
        $byName[$name]['suffix']      = $r['Suffix']     ?? null;
        /* #1501 — optional Maiden Surname, same NULL-on-partly-migrated
           fallback as the trio above. */
        $byName[$name]['maiden_surname'] = $r['MaidenSurname'] ?? null;
        /* Full child rows for the Edit drawer's pre-fill. Empty arrays
           default for registry rows with no children — the drawer's JS
           handles the empty case as "no rows yet". */
        $byName[$name]['links']   = $linksByPerson[(int)$r['Id']]   ?? [];
        $byName[$name]['ipi']     = $ipiByPerson[(int)$r['Id']]     ?? [];
        $byName[$name]['isni']    = $isniByPerson[(int)$r['Id']]    ?? [];
        $byName[$name]['otherid'] = $otherIdByPerson[(int)$r['Id']] ?? []; // #1348
        $byName[$name]['aliases'] = $aliasesByPerson[(int)$r['Id']] ?? [];
        /* #1502 — Group members. Only ever populated for IsGroup=1 rows
           in practice (the admin UI only lets you add members to a
           Group), but no filter is needed here — a plain individual
           simply never has a key in $membersByPerson. */
        $byName[$name]['members'] = $membersByPerson[(int)$r['Id']] ?? [];
        /* #1741 P4a — "Portrayed by" pre-fill for the character/other-type
           sub-panel. */
        $byName[$name]['portrayed_by'] = $portrayedByPerson[(int)$r['Id']] ?? [];
    }

    /* Sort: highest-usage first, then alphabetical. Registry-only
       entries (total = 0) bubble to the bottom inside their alpha
       block — easy for the curator to spot which pre-registered
       names are still waiting on a song to cite them. */
    uasort($byName, static function (array $a, array $b): int {
        if ($a['total'] !== $b['total']) {
            return $b['total'] <=> $a['total'];
        }
        return strcasecmp($a['name'], $b['name']);
    });

    $people = array_values($byName);

    /* Build the country-filter option list — deduplicated set of
       birth-country (code, label) pairs that actually appear on a
       registered person, sorted by label. NULL codes drop out so
       the select only offers what's filterable. */
    $countryOptions = [];
    foreach ($people as $_p) {
        $cc = (string)($_p['birth_country_code'] ?? '');
        $cn = (string)($_p['birth_country']      ?? '');
        if ($cc === '' || isset($countryOptions[$cc])) continue;
        $countryOptions[$cc] = $cn !== '' ? $cn : strtoupper($cc);
    }
    uasort($countryOptions, 'strcasecmp');
} catch (\Throwable $e) {
    error_log('[manage/musicians.php] load failed: ' . $e->getMessage());
    logActivityError('admin.musicians.list', 'musician', '', $e);
    $where = $e->getFile() ? (' (' . basename($e->getFile()) . ':' . $e->getLine() . ')') : '';
    $error = 'Could not load musicians: ' . $e->getMessage() . $where;
}

/* ----------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/** Render a compact "b. 1725 · d. 1807" lifespan, or '' if neither known. */
$lifespan = static function (?string $birth, ?string $death): string {
    $bYear = $birth ? substr($birth, 0, 4) : '';
    $dYear = $death ? substr($death, 0, 4) : '';
    if ($bYear === '' && $dYear === '') return '';
    if ($bYear !== '' && $dYear === '') return 'b. ' . htmlspecialchars($bYear);
    if ($bYear === '' && $dYear !== '') return 'd. ' . htmlspecialchars($dYear);
    return 'b. ' . htmlspecialchars($bYear) . ' · d. ' . htmlspecialchars($dYear);
};

/** Source badge: registry-only, in-use only, or both. */
$sourceBadge = static function (array $p): string {
    $inRegistry = $p['registry_id'] !== null;
    $inUse      = $p['total'] > 0;
    if ($inRegistry && $inUse) return '<span class="badge bg-success-subtle text-success-emphasis">Both</span>';
    if ($inRegistry)           return '<span class="badge bg-info-subtle text-info-emphasis">Registry</span>';
    return '<span class="badge bg-secondary-subtle text-secondary-emphasis">In use</span>';
};

$totalNames           = count($people);
$totalInRegistry      = count(array_filter($people, static fn($p) => $p['registry_id'] !== null));
$totalInUse           = count(array_filter($people, static fn($p) => $p['total'] > 0));
$totalRegistryOnly    = $totalNames - $totalInUse;
/* #846 — count of names that are in-use but not in the registry, so
   the parent page can flash a "promote in bulk" CTA when there's any
   meaningful work to do. */
$totalInUseUnregistered = count(array_filter($people, static fn($p) =>
    $p['total'] > 0 && $p['registry_id'] === null
));

/* #1785 §7 — cheap CTA badge for the duplicates-review page: Bucket A
   (byte-variant) group count ONLY, no fuzzy scoring (musicianDuplicates
   CountBucketA()'s own doc-block). Wrapped defensively — a failure here
   (e.g. an un-migrated dependency) must never break the parent Musicians
   page over a nicety badge; it just hides the CTA. */
$dupeGroupCount = 0;
try {
    $dupeGroupCount = musicianDuplicatesCountBucketA($db);
} catch (\Throwable $_e) {
    $dupeGroupCount = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Musicians — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
    <style>
        /* Per-role badge colours. Picked to match the editor's chip
           colours so an admin who jumps between this page and the
           Song Editor has visual continuity. */
        .role-pill            { font-variant-numeric: tabular-nums; }
        .role-pill.role-w     { background-color: #1f6feb33; color: #79b8ff; }
        .role-pill.role-c     { background-color: #6f42c133; color: #b392f0; }
        .role-pill.role-ar    { background-color: #fb950033; color: #ffab40; }
        .role-pill.role-ad    { background-color: #2ea04333; color: #56d364; }
        .role-pill.role-t     { background-color: #d73a4933; color: #ff7b72; }
        .role-pill.role-ai    { background-color: #17a2b833; color: #5bc0de; } /* #1785 C5 — artist */
        .role-pill[data-zero] { opacity: 0.25; }

        /* Person-name column gets a slightly larger, slightly bolder
           treatment so the eye lands on it first. */
        td.person-name        { font-weight: 500; }
        td.person-name code   { font-size: 0.85em; opacity: 0.6; }

        /* Lifespan + link/IPI badge alignment. */
        td.meta-col           { white-space: nowrap; font-size: 0.85em; opacity: 0.85; }
        .badge-icon-count     { font-variant-numeric: tabular-nums; }

        /* Active filter button + search-empty placeholder row. */
        .filter-btn.active    { background-color: var(--bs-primary); color: #fff; border-color: var(--bs-primary); }
        tr.no-match           { display: none; }
        .empty-row td         { text-align: center; padding: 2rem; opacity: 0.6; }
    </style>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">

        <h1 class="h4 mb-2"><i class="bi bi-person-badge me-2"></i>Musicians</h1>
        <p class="text-secondary small mb-3">
            Every individual credited as a writer, composer, arranger, adaptor or
            translator across the catalogue, plus every pre-registered name in
            <code>tblMusicians</code>. Use this view to spot duplicates that
            need merging (e.g. <code>J. Newton</code> vs <code>John Newton</code>)
            or registry-only names that are waiting on a song to cite them.
        </p>

        <?php if ($success): ?>
            <div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($totalInUseUnregistered > 0): ?>
            <!-- #846 — bulk-promote CTA. Shows only when there's
                 actual unregistered-but-cited work waiting.
                 #1503 adds a second, one-click button next to the
                 fuzzy-match review page: "Promote all remaining" skips
                 the near-duplicate review entirely and registers every
                 cited-but-unregistered name directly — the confirm()
                 dialog states the exact count as the "preview" before
                 committing. Curators who want the fuzzy-match safety
                 net still have the original button/page; this is for
                 the common case where none of the remaining names are
                 actually near-duplicates and the review is pure friction. -->
            <div class="alert alert-info d-flex flex-wrap align-items-center gap-2 py-2">
                <i class="bi bi-people" aria-hidden="true"></i>
                <span>
                    <strong><?= number_format($totalInUseUnregistered) ?></strong>
                    <?= $totalInUseUnregistered === 1 ? 'person is' : 'people are' ?> credited on a song
                    but <em><?= $totalInUseUnregistered === 1 ? "isn't" : "aren't" ?></em> in your Musicians list yet.
                </span>
                <!-- #1543 — plain-English CTA labels. The action still "promotes"
                     (bulk_register_unregistered) internally; only the user-facing
                     wording changed so non-technical admins understand it. -->
                <a href="/manage/musicians-bulk-promote" class="btn btn-sm btn-amber-solid ms-auto">
                    <i class="bi bi-magic me-1"></i>Review &amp; add (checks for duplicates)
                </a>
                <form method="POST" class="d-inline"
                      onsubmit="return confirm('Add all <?= (int)$totalInUseUnregistered ?> credited <?= $totalInUseUnregistered === 1 ? 'person' : 'people' ?> to your Musicians list?\n\nThis adds them straight away, without checking for possible duplicates first. Use &quot;Review &amp; add&quot; instead if you want to check for near-duplicates.');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="bulk_register_unregistered">
                    <button type="submit" class="btn btn-sm btn-outline-info">
                        <i class="bi bi-person-plus me-1"></i>Add all <?= number_format($totalInUseUnregistered) ?> now
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($dupeGroupCount > 0): ?>
            <!-- #1785 §7 — CTA into the registry-duplicate review page,
                 beside the #846 bulk-promote CTA above. Distinct job:
                 that one finds cited-but-UNREGISTERED names; this one
                 finds registry rows that are probably the SAME person
                 spelled two ways. Count is Bucket-A-only (cheap, no
                 fuzzy scoring) — the review page itself also surfaces
                 the fuzzy "similar names" section, so the true total is
                 usually higher than this badge. -->
            <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2 py-2">
                <i class="bi bi-people-fill" aria-hidden="true"></i>
                <span>
                    <strong><?= number_format($dupeGroupCount) ?></strong>
                    probable duplicate <?= $dupeGroupCount === 1 ? 'group' : 'groups' ?> in your Musicians registry —
                    the same person spelled two different ways.
                </span>
                <a href="/manage/musician-duplicates" class="btn btn-sm btn-outline-warning ms-auto">
                    <i class="bi bi-git-compare me-1"></i>Review duplicates
                </a>
            </div>
        <?php endif; ?>

        <!-- Summary tiles -->
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-3">
                <div class="card bg-dark border-secondary p-2">
                    <div class="small text-secondary">Total distinct names</div>
                    <div class="h5 mb-0"><?= number_format($totalNames) ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card bg-dark border-secondary p-2">
                    <div class="small text-secondary">Cited by &ge; 1 song</div>
                    <div class="h5 mb-0"><?= number_format($totalInUse) ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card bg-dark border-secondary p-2">
                    <div class="small text-secondary">In registry</div>
                    <div class="h5 mb-0"><?= number_format($totalInRegistry) ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card bg-dark border-secondary p-2">
                    <div class="small text-secondary">Registry-only (not yet used)</div>
                    <div class="h5 mb-0"><?= number_format($totalRegistryOnly) ?></div>
                </div>
            </div>
        </div>

        <!-- Search + filters -->
        <div class="card bg-dark border-secondary p-3 mb-3">
            <div class="row g-2 align-items-center">
                <div class="col-md-5">
                    <label for="mus-search" class="visually-hidden">Search names</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="search" class="form-control" id="mus-search"
                               placeholder="Filter by name, lifespan, notes…" autocomplete="off">
                        <button class="btn btn-outline-secondary" type="button" id="mus-search-clear" title="Clear" aria-label="Clear search">
                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="btn-group btn-group-sm flex-wrap" role="group" aria-label="Filter by role">
                        <button type="button" class="btn btn-outline-secondary filter-btn active" data-filter="all">All</button>
                        <button type="button" class="btn btn-outline-secondary filter-btn" data-filter="writer">Writers</button>
                        <button type="button" class="btn btn-outline-secondary filter-btn" data-filter="composer">Composers</button>
                        <button type="button" class="btn btn-outline-secondary filter-btn" data-filter="arranger">Arrangers</button>
                        <button type="button" class="btn btn-outline-secondary filter-btn" data-filter="adaptor">Adaptors</button>
                        <button type="button" class="btn btn-outline-secondary filter-btn" data-filter="translator">Translators</button>
                        <button type="button" class="btn btn-outline-secondary filter-btn" data-filter="artist">Artists</button>
                        <button type="button" class="btn btn-outline-secondary filter-btn" data-filter="registry-only">Registry-only</button>
                    </div>
                </div>
                <div class="col-md-2">
                    <?php if (!empty($countryOptions)): ?>
                        <label for="mus-country-filter" class="visually-hidden">Filter by birth country</label>
                        <select id="mus-country-filter" class="form-select form-select-sm" aria-label="Filter by birth country">
                            <option value="">Any country</option>
                            <?php foreach ($countryOptions as $_cc => $_cname): ?>
                                <option value="<?= htmlspecialchars((string)$_cc) ?>"><?= htmlspecialchars((string)$_cname) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
                <div class="col-md-1 text-md-end">
                    <button type="button" class="btn btn-amber-solid btn-sm" id="mus-add-btn">
                        <i class="bi bi-plus-circle me-1"></i>Add person
                    </button>
                </div>
            </div>
        </div>

        <!-- People table -->
        <div class="card bg-dark border-secondary p-2 mb-3">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 mus-sortable admin-table-responsive">
                    <thead class="text-muted small">
                        <tr>
                            <th scope="col" data-col-priority="primary"   data-sort-key="name"   data-sort-type="text">Name</th>
                            <th scope="col" data-col-priority="primary"   class="text-center">Roles</th>
                            <th scope="col" data-col-priority="primary"   class="text-end" data-sort-key="total" data-sort-type="number">Total uses</th>
                            <th scope="col" data-col-priority="secondary" data-sort-key="source" data-sort-type="text">Source</th>
                            <th scope="col" data-col-priority="secondary" data-sort-key="lifespan" data-sort-type="text">Lifespan</th>
                            <th scope="col" data-col-priority="tertiary"  class="text-end">Meta</th>
                            <th scope="col" data-col-priority="primary"   class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="mus-tbody">
                        <?php foreach ($people as $p):
                            $writers     = $p['writers'];
                            $composers   = $p['composers'];
                            $arrangers   = $p['arrangers'];
                            $adaptors    = $p['adaptors'];
                            $translators = $p['translators'];
                            $artists     = $p['artists']; // #1785 C5
                            $rolesCsv    = implode(',', array_filter([
                                $writers     ? 'writer'     : '',
                                $composers   ? 'composer'   : '',
                                $arrangers   ? 'arranger'   : '',
                                $adaptors    ? 'adaptor'    : '',
                                $translators ? 'translator' : '',
                                $artists     ? 'artist'     : '',
                            ]));
                            $isRegistryOnly = $p['total'] === 0;
                            $haystack = strtolower(implode(' ', array_filter([
                                $p['name'],
                                $lifespan($p['birth_date'], $p['death_date']),
                                (string)$p['birth_place'],
                                (string)$p['death_place'],
                                (string)$p['notes'],
                                /* #1501 — fold the maiden surname into the
                                   search-match set (gated: naturally empty/
                                   dropped by array_filter on an un-migrated
                                   install or a row with none set) so "I
                                   remember her under her birth surname"
                                   finds the row, same spirit as the AKA/
                                   alias haystack below. */
                                (string)($p['maiden_surname'] ?? ''),
                            ])));
                            /* Embed the full person payload in a data
                               attribute so the Edit button can pre-fill
                               the drawer without an extra round-trip.
                               JSON_HEX_* flags + ENT_QUOTES on the
                               attribute container keep apostrophes /
                               quotes / angle brackets safe. Nulls in the
                               PHP array become null literals in JSON. */
                            $personJson = json_encode([
                                'registry_id' => $p['registry_id'],
                                'name'        => $p['name'],
                                'notes'       => $p['notes'],
                                'birth_place' => $p['birth_place'],
                                'birth_place_id' => $p['birth_place_id'] ?? null,
                                'birth_date'  => $p['birth_date'],
                                /* Editor-form value: "1823" / "03/1823" for
                                   partials (drawer populates the text field
                                   from this, not the raw ISO date). */
                                'birth_date_input' => $p['birth_date_input'] ?? '',
                                'death_place' => $p['death_place'],
                                'death_place_id' => $p['death_place_id'] ?? null,
                                'death_date'  => $p['death_date'],
                                'death_date_input' => $p['death_date_input'] ?? '',
                                'is_special_case' => (int)($p['is_special_case'] ?? 0),
                                'is_group'        => (int)($p['is_group']        ?? 0),
                                /* Per-role counts so the Merge modal's
                                   "Song-credit rows to re-point" preview
                                   (#583) can render without a round-trip. */
                                'writers'     => $writers,
                                'composers'   => $composers,
                                'arrangers'   => $arrangers,
                                'adaptors'    => $adaptors,
                                'translators' => $translators,
                                'artists'     => $artists, // #1785 C5 — not yet rendered by the Merge modal's preview widget (C8)
                                'total'       => $p['total'],
                                /* #1501 — optional Maiden Surname, so the Edit
                                   drawer can pre-fill it. NULL on registry-only-
                                   absent rows / partly-migrated installs (see
                                   $hasMaidenSurname above). */
                                'maiden_surname' => $p['maiden_surname'] ?? null,
                                'links'       => $p['links'],
                                'ipi'         => $p['ipi'],
                                'isni'        => $p['isni'] ?? [],
                                'aliases'     => $p['aliases'] ?? [],
                                /* #1502 — this Group person's current members
                                   (id/name/slug), so the Edit drawer's Members
                                   card-list can pre-fill without a round-trip.
                                   Empty for individuals and for pre-migration
                                   installs alike. */
                                'members'     => $p['members'] ?? [],
                            ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
                            $isSpecial = !empty($p['is_special_case']);
                            $isGroup   = !empty($p['is_group']);
                            /* AKA names flattened into the row's data-aka
                               attribute so the existing client-side filter
                               matches a typed query against aliases too —
                               critical for the "I remember Cecil under her
                               maiden name" workflow. Empty when the row
                               has no aliases (the JS treats undefined as
                               "no aliases to consider"). */
                            $akaList = array_map(static fn(array $a): string => (string)$a['Name'], $p['aliases'] ?? []);
                            $akaAttr = implode(' · ', $akaList);
                        ?>
                            <tr id="mus-person-<?= (int)$p['Id'] ?>"
                                data-roles="<?= htmlspecialchars($rolesCsv) ?>"
                                data-registry-only="<?= $isRegistryOnly ? '1' : '0' ?>"
                                data-haystack="<?= htmlspecialchars($haystack) ?>"
                                data-aka="<?= htmlspecialchars($akaAttr) ?>"
                                data-classification="<?= $isSpecial ? 'special' : ($isGroup ? 'group' : 'individual') ?>"
                                data-country-code="<?= htmlspecialchars((string)($p['birth_country_code'] ?? '')) ?>"
                                data-country="<?= htmlspecialchars((string)($p['birth_country'] ?? '')) ?>"
                                data-person='<?= htmlspecialchars($personJson, ENT_QUOTES) ?>'>
                                <td data-col-priority="primary" class="person-name <?= $isSpecial ? 'fst-italic' : '' ?>">
                                    <?php if ($isGroup): ?>
                                        <i class="bi bi-people-fill text-info me-1" title="Group / band / collective" aria-label="Group"></i>
                                    <?php elseif ($isSpecial): ?>
                                        <i class="bi bi-question-circle text-warning me-1" title="Special-case attribution" aria-label="Special case"></i>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($p['name']) ?>
                                    <?php if ($isSpecial): ?>
                                        <span class="badge bg-warning-subtle text-warning-emphasis ms-1" style="font-size: 0.6rem;">Special case</span>
                                    <?php endif; ?>
                                    <?php if ($isGroup): ?>
                                        <span class="badge bg-info-subtle text-info-emphasis ms-1" style="font-size: 0.6rem;">Group</span>
                                    <?php endif; ?>
                                    <?php if (!empty($p['aliases'])): ?>
                                        <div class="text-secondary small mt-1" title="Alternative names that also match in search">
                                            <i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>
                                            AKA: <?= htmlspecialchars(implode(', ', array_map(static fn($a) => (string)$a['Name'], $p['aliases']))) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($p['notes']): ?>
                                        <span class="text-secondary small ms-2" title="<?= htmlspecialchars($p['notes']) ?>">
                                            <i class="bi bi-sticky"></i>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td data-col-priority="primary" class="text-center">
                                    <span class="badge role-pill role-w"  <?= $writers     ? '' : 'data-zero' ?>>W&middot;<?= $writers ?></span>
                                    <span class="badge role-pill role-c"  <?= $composers   ? '' : 'data-zero' ?>>C&middot;<?= $composers ?></span>
                                    <span class="badge role-pill role-ar" <?= $arrangers   ? '' : 'data-zero' ?>>Ar&middot;<?= $arrangers ?></span>
                                    <span class="badge role-pill role-ad" <?= $adaptors    ? '' : 'data-zero' ?>>Ad&middot;<?= $adaptors ?></span>
                                    <span class="badge role-pill role-t"  <?= $translators ? '' : 'data-zero' ?>>T&middot;<?= $translators ?></span>
                                    <span class="badge role-pill role-ai" <?= $artists     ? '' : 'data-zero' ?>>Art&middot;<?= $artists ?></span>
                                </td>
                                <td data-col-priority="primary"   class="text-end" data-sort-value="<?= (int)$p['total'] ?>"><strong><?= number_format($p['total']) ?></strong></td>
                                <td data-col-priority="secondary" data-sort-value="<?= htmlspecialchars($p['registry_id'] !== null && $p['total'] > 0 ? 'Both' : ($p['registry_id'] !== null ? 'Registry' : 'In use')) ?>"><?= $sourceBadge($p) ?></td>
                                <td data-col-priority="secondary" class="meta-col" data-sort-value="<?= htmlspecialchars((string)($p['birth_date'] ?? '')) ?>">
                                    <?php $life = $lifespan($p['birth_date'], $p['death_date']);
                                          echo $life !== '' ? $life : '<span class="text-secondary">—</span>'; ?>
                                </td>
                                <td data-col-priority="tertiary" class="text-end meta-col">
                                    <?php if ($p['link_count'] > 0): ?>
                                        <span class="badge bg-secondary-subtle text-secondary-emphasis badge-icon-count"
                                              title="<?= (int)$p['link_count'] ?> external link(s)">
                                            <i class="bi bi-link-45deg"></i> <?= (int)$p['link_count'] ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($p['ipi_count'] > 0): ?>
                                        <span class="badge bg-secondary-subtle text-secondary-emphasis badge-icon-count"
                                              title="<?= (int)$p['ipi_count'] ?> IPI number(s)">
                                            IPI <?= (int)$p['ipi_count'] ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($p['link_count'] === 0 && $p['ipi_count'] === 0): ?>
                                        <span class="text-secondary">—</span>
                                    <?php endif; ?>
                                </td>
                                <td data-col-priority="primary" class="text-end action-col">
                                    <div class="btn-group btn-group-sm" role="group" aria-label="Row actions">
                                        <?php
                                            /* Merge / Rename / Delete are available for any row that
                                               either has a registry entry OR has at least one credited
                                               use (#626). The Merge handler auto-registers source/target
                                               on submit if needed, so the UI no longer hides the action
                                               from in-use-only rows like the Stuart Townend duplicates. */
                                            $hasUse      = (int)$p['total'] > 0;
                                            $inRegistry  = $p['registry_id'] !== null;
                                            $hasActions  = $inRegistry || $hasUse;
                                        ?>
                                        <?php if (!$inRegistry && $hasUse): ?>
                                            <button type="button" class="btn btn-outline-info mus-edit-btn"
                                                    title="Add to registry — opens the detail drawer pre-filled with this person"
                                                    aria-label="Add <?= htmlspecialchars($p['name'], ENT_QUOTES) ?> to the registry">
                                                <i class="bi bi-plus-circle" aria-hidden="true"></i>
                                            </button>
                                        <?php elseif ($inRegistry): ?>
                                            <button type="button" class="btn btn-outline-info mus-edit-btn"
                                                    title="Edit person details"
                                                    aria-label="Edit person <?= htmlspecialchars($p['name'], ENT_QUOTES) ?>">
                                                <i class="bi bi-pencil" aria-hidden="true"></i>
                                            </button>
                                        <?php endif; ?>

                                        <?php if ($hasActions): ?>
                                            <button type="button" class="btn btn-outline-warning mus-rename-btn"
                                                    title="Rename — cascades to every song that cites this person"
                                                    aria-label="Rename person <?= htmlspecialchars($p['name'], ENT_QUOTES) ?>">
                                                <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-warning mus-merge-btn"
                                                    title="Merge into another person — combines two names into one"
                                                    aria-label="Merge person <?= htmlspecialchars($p['name'], ENT_QUOTES) ?>">
                                                <i class="bi bi-union" aria-hidden="true"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary mus-view-songs-btn"
                                                    title="View songs that cite this person"
                                                    aria-label="View songs that cite <?= htmlspecialchars($p['name'], ENT_QUOTES) ?>">
                                                <i class="bi bi-music-note-list" aria-hidden="true"></i>
                                            </button>
                                            <?php if ($inRegistry): ?>
                                                <button type="button" class="btn btn-outline-danger mus-delete-btn"
                                                        title="Remove from registry"
                                                        aria-label="Remove <?= htmlspecialchars($p['name'], ENT_QUOTES) ?> from the registry">
                                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="empty-row" id="mus-empty-row" style="display:none;">
                            <td colspan="7">No names match the current search / filter.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- =========================================================================
         Delete-from-registry modal — removes the registry row only.
         Shows a force-anyway checkbox when the person is still cited
         by at least one song; the song credits stay either way (they
         live in the five song-credit tables, not the registry).
         ========================================================================= -->
    <div class="modal fade" id="musDeleteModal" tabindex="-1" aria-labelledby="musDeleteLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content bg-dark border-secondary">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="delete_from_registry">
                    <input type="hidden" name="id" id="mus-delete-id" value="">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title" id="musDeleteLabel">
                            <i class="bi bi-trash me-2"></i>Remove from registry
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-3">
                            Remove the registry row for
                            <strong id="mus-delete-name"></strong>?
                        </p>

                        <div id="mus-delete-in-use" class="d-none alert alert-warning py-2 small mb-3">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            This person is still cited by
                            <strong id="mus-delete-usage-count"></strong>
                            song-credit row(s). The song credits stay either way —
                            this only removes the registry entry's biographical
                            metadata, links and IPI numbers.
                            <div class="form-check mt-2">
                                <input type="checkbox" class="form-check-input" id="mus-delete-force" name="force" value="1">
                                <label class="form-check-label" for="mus-delete-force">
                                    Yes, remove the registry row anyway
                                </label>
                            </div>
                        </div>

                        <div id="mus-delete-not-in-use" class="d-none alert alert-secondary py-2 small mb-0">
                            No songs currently cite this person — safe to remove.
                            Deletes the registry row plus its links / IPI rows
                            (cascade).
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger btn-sm" id="mus-delete-submit">
                            <i class="bi bi-trash me-1"></i>Remove
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- =========================================================================
         View Songs modal — read-only listing of every song that cites
         the person, grouped by role. Lazy-loaded from
         GET ?action=view_songs&id=<id>.
         ========================================================================= -->
    <div class="modal fade" id="musViewSongsModal" tabindex="-1" aria-labelledby="musViewSongsLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content bg-dark border-secondary">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title" id="musViewSongsLabel">
                        <i class="bi bi-music-note-list me-2"></i>
                        Songs citing <strong id="mus-view-songs-name"></strong>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="mus-view-songs-loading" class="text-center py-3 text-secondary small d-none">
                        <span class="spinner-border spinner-border-sm me-2"></span>Loading…
                    </div>
                    <div id="mus-view-songs-error" class="alert alert-danger py-2 small d-none"></div>
                    <div id="mus-view-songs-body"></div>
                    <div id="mus-view-songs-empty" class="alert alert-secondary py-2 small d-none">
                        No songs currently cite this person — the registry row
                        exists in isolation (pre-registered, or all citations
                        have been re-pointed elsewhere).
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- =========================================================================
         Rename modal — changes the canonical name + cascades to the
         five song-credit tables.
         ========================================================================= -->
    <div class="modal fade" id="musRenameModal" tabindex="-1" aria-labelledby="musRenameLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content bg-dark border-secondary">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="rename">
                    <input type="hidden" name="id" id="mus-rename-id" value="">
                    <!-- #626 — fallback for in-use-only rows; the server
                         auto-registers by name when id is empty. -->
                    <input type="hidden" name="source_name" id="mus-rename-source-name" value="">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title" id="musRenameLabel">
                            <i class="bi bi-pencil-square me-2"></i>Rename person
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-2">
                            <label class="form-label small">Current name</label>
                            <input type="text" class="form-control form-control-sm" id="mus-rename-current" readonly>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small" for="mus-rename-new">New name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="mus-rename-new" name="new_name" maxlength="255" required>
                        </div>
                        <div class="alert alert-warning py-2 small mb-0" id="mus-rename-impact">
                            Renaming will update the spelling on every song that currently cites this person.
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning btn-sm">
                            <i class="bi bi-pencil-square me-1"></i>Rename and cascade
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- =========================================================================
         Merge modal — collapses two registry rows into one. The admin
         picks a target from the dropdown of all other registry rows;
         the source's links / IPI are listed with checkboxes (default
         checked) so the admin can drop duplicates before they migrate.
         ========================================================================= -->
    <div class="modal fade" id="musMergeModal" tabindex="-1" aria-labelledby="musMergeLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content bg-dark border-secondary">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="merge">
                    <input type="hidden" name="source_id" id="mus-merge-source-id" value="">
                    <!-- #626 — name fallbacks for in-use-only rows; the
                         server auto-registers them on submit. The select
                         element below carries an "id:N" / "name:X" key
                         that the submit handler routes into either
                         target_id or target_name before posting. -->
                    <input type="hidden" name="source_name" id="mus-merge-source-name-hidden" value="">
                    <input type="hidden" name="target_id"   id="mus-merge-target-id-hidden"   value="">
                    <input type="hidden" name="target_name" id="mus-merge-target-name-hidden" value="">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title" id="musMergeLabel">
                            <i class="bi bi-union me-2"></i>Merge person into another
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label small">Source (will be removed)</label>
                                <input type="text" class="form-control form-control-sm" id="mus-merge-source-name" readonly>
                            </div>
                            <div class="col-6">
                                <label class="form-label small" for="mus-merge-target">Target (survives) <span class="text-danger">*</span></label>
                                <!-- #1500 — live-search typeahead (the same
                                     <input> + <datalist> + debounced-GET
                                     pattern /manage/songbooks already uses
                                     for its parent-songbook / compiler
                                     pickers) replacing the old <select>
                                     that rendered every registry + in-use-
                                     only name as an <option> — unusable
                                     once the registry passed a few hundred
                                     rows. #mus-merge-target-key resolves to
                                     the SAME "id:N" / "name:X" key the old
                                     <select> option values carried, so the
                                     submit-time routing further down is
                                     UNCHANGED (#626). Native <datalist> is
                                     keyboard-accessible (arrow keys + Enter)
                                     and inherits the admin theme via the
                                     plain .form-control styling — no custom
                                     widget needed. -->
                                <input type="text" class="form-control form-control-sm" id="mus-merge-target"
                                       list="mus-merge-target-datalist" autocomplete="off"
                                       placeholder="Start typing a name…" required>
                                <datalist id="mus-merge-target-datalist"></datalist>
                                <input type="hidden" id="mus-merge-target-key" value="">
                            </div>
                        </div>

                        <!-- Song-credit re-point preview (#583). The Source
                             aggregate already carries per-role counts in its
                             data-person attribute, so we render them inline
                             when the modal opens — no extra round-trip.
                             #1785 C8 — the sixth "Artist" role joined the
                             other five in the underlying data back at C5
                             (MUSICIAN_CREDIT_ROLE_TABLES), but this preview
                             widget itself was never updated to show it — so
                             `total` included artist credits while the five
                             visible pills didn't sum to it. Added here. -->
                        <div id="mus-merge-credit-preview" class="alert alert-info py-2 small mb-3 d-none">
                            <div class="fw-semibold mb-1">
                                <i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>
                                Song-credit rows to re-point
                            </div>
                            <div class="d-flex flex-wrap gap-2 small">
                                <span>Writer&nbsp;<strong id="mus-merge-count-writer">0</strong></span>
                                <span>Composer&nbsp;<strong id="mus-merge-count-composer">0</strong></span>
                                <span>Arranger&nbsp;<strong id="mus-merge-count-arranger">0</strong></span>
                                <span>Adaptor&nbsp;<strong id="mus-merge-count-adaptor">0</strong></span>
                                <span>Translator&nbsp;<strong id="mus-merge-count-translator">0</strong></span>
                                <span>Artist&nbsp;<strong id="mus-merge-count-artist">0</strong></span>
                                <span class="ms-auto">Total&nbsp;<strong id="mus-merge-count-total">0</strong></span>
                            </div>
                        </div>

                        <!-- #1785 C8 — disambiguation comparison (plan §8.2):
                             the direct answer to "which is merging into
                             which?" (problem 2 of the #1785 issue body).
                             Populated once a target is actually picked from
                             the typeahead (its payload is what the
                             merge_target_search endpoint's #1785 C8
                             additive fields carry) — hidden until then, since
                             a half-typed target has nothing to compare. -->
                        <div id="mus-merge-disambig" class="row g-2 small mb-3 d-none">
                            <div class="col-6">
                                <div class="border rounded p-2">
                                    <div class="text-secondary">Source (removed)</div>
                                    <div id="mus-merge-disambig-source" class="fw-semibold"></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="border rounded p-2">
                                    <div class="text-secondary">Target (survives)</div>
                                    <div id="mus-merge-disambig-target" class="fw-semibold"></div>
                                </div>
                            </div>
                            <div class="col-12" id="mus-merge-disambig-variant"></div>
                        </div>

                        <div id="mus-merge-children" class="d-none">
                            <h6 class="small text-secondary mb-2">Source's links / IPI numbers</h6>
                            <p class="small text-secondary">
                                Untick any rows you want to drop. Anything ticked moves to the target;
                                anything unticked is removed when the source row is deleted.
                                The target's own links / IPI are unaffected.
                            </p>
                            <div id="mus-merge-children-empty" class="alert alert-secondary py-2 small mb-2 d-none">
                                Source has no links or IPI numbers — nothing to migrate.
                            </div>
                            <div id="mus-merge-children-links"  class="mb-2"></div>
                            <div id="mus-merge-children-ipi"   class="mb-2"></div>
                            <div id="mus-merge-children-isni"></div>
                        </div>

                        <div class="alert alert-warning py-2 small mb-2">
                            Merging re-points every song-credit row from the source name to the target
                            name across the five song-credit tables, then removes the source registry
                            row. Tracked in the activity log with full per-table affected counts.
                        </div>

                        <!-- Explicit irreversibility ack (#583). The submit
                             button stays disabled until both a target is
                             picked AND this checkbox is ticked. -->
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="mus-merge-confirm" required>
                            <label class="form-check-label small" for="mus-merge-confirm">
                                I understand this is irreversible — the source registry row is
                                deleted and every song-credit row is re-pointed to the target name.
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning btn-sm" id="mus-merge-submit" disabled>
                            <i class="bi bi-union me-1"></i>Merge
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- =========================================================================
         Person detail drawer — drives both Add and Update_person actions.
         Bootstrap right-side offcanvas with a form inside; the form's
         method/action are fixed (POST to this page); the action input
         and id input switch between 'add' / 'update_person' depending
         on which button opened the drawer.
         ========================================================================= -->
    <div class="offcanvas offcanvas-end" tabindex="-1" id="musDrawer" aria-labelledby="musDrawerLabel" style="width: min(560px, 95vw);">
        <div class="offcanvas-header border-bottom border-secondary">
            <h5 class="offcanvas-title" id="musDrawerLabel">
                <i class="bi bi-person-badge me-2"></i>
                <span id="mus-drawer-title">Add person</span>
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <form method="POST" id="mus-drawer-form" class="offcanvas-body d-flex flex-column gap-3">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" id="mus-drawer-action" value="add">
            <input type="hidden" name="id"     id="mus-drawer-id"     value="">

            <!-- Identity — structured name fields (#934). Hidden when
                 the row is flagged as a Group / Special case (those keep
                 the single-Name flow because "Hillsong United" or
                 "Anonymous" don't have first/last/suffix parts). For
                 individuals these three fields drive the canonical Name;
                 the Name input below is the read-only preview that the
                 server actually persists. -->
            <div data-flag-section="name-parts" class="row g-2">
                <div class="col-7">
                    <label class="form-label small mb-1" for="mus-drawer-first-names">First name(s)</label>
                    <input type="text" class="form-control form-control-sm" id="mus-drawer-first-names" name="first_names" maxlength="255" placeholder="e.g. Cecil Frances Humphreys">
                </div>
                <div class="col-5">
                    <label class="form-label small mb-1" for="mus-drawer-suffix">Suffix</label>
                    <input type="text" class="form-control form-control-sm" id="mus-drawer-suffix" name="suffix" maxlength="32" placeholder="Jr, III, PhD…">
                </div>
                <div class="col-12">
                    <label class="form-label small mb-1" for="mus-drawer-surname">Surname</label>
                    <input type="text" class="form-control form-control-sm" id="mus-drawer-surname" name="surname" maxlength="255" placeholder="e.g. Alexander">
                </div>
                <!-- #1501 — optional Maiden Surname. A structured biographical
                     fact (birth surname, when different from the current
                     Surname above), distinct from the free-text AKA/Aliases
                     'Maiden name' type further down — the two are
                     complementary, not duplicates. Column-existence-gated
                     server-side (dormant-safe pre-migration): the input
                     always renders, matching the existing First name(s) /
                     Surname / Suffix fields above, which follow the same
                     "always show, no-op save until the migration runs"
                     convention (#934). -->
                <div class="col-12">
                    <label class="form-label small mb-1" for="mus-drawer-maiden-surname">
                        Maiden surname <span class="text-muted">(optional)</span>
                    </label>
                    <input type="text" class="form-control form-control-sm" id="mus-drawer-maiden-surname" name="maiden_surname" maxlength="100" placeholder="e.g. Humphreys">
                </div>
            </div>

            <!-- Identity — canonical Name. For individuals it's
                 auto-composed from First + Surname + Suffix above and
                 rendered read-only as a preview. For Group / Special
                 case rows the three split fields are hidden and this is
                 the primary input. -->
            <div>
                <label class="form-label small mb-1" for="mus-drawer-name">Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control form-control-sm" id="mus-drawer-name" name="name" maxlength="255" required>
                <div class="form-text small" id="mus-drawer-name-help">
                    The canonical spelling. To rename, save first, then use the Rename action — renames cascade to every song that cites this person.
                </div>
            </div>

            <!-- Classification (#584 / #585, widened by #1741 P4a's Type
                 vocabulary). When the Type column exists, a single
                 <select name="type"> (MUSICIAN_TYPES) is the VISIBLE
                 control — it is the one the server prefers
                 (manage/musicians.php's add/update_person handlers read
                 `type` before falling back to the checkboxes). The two
                 checkboxes are ALWAYS rendered exactly once (never a
                 second, mutually-exclusive copy of the same id= — that
                 duplicate-id shape is what test-a11y-static-checks.php's
                 static scanner exists to catch: it reads raw PHP source
                 text, not evaluated HTML, so an `if/else` with the SAME
                 id in both branches reads as two declarations of one
                 id, unconditionally); a single PHP-computed CSS class
                 hides them (+ aria-hidden/tabindex) when the Type select
                 exists, purely as internal state — the rest of this
                 drawer's JS (applyFlagLabels(), the birth/death label
                 swap, the IPI/name-parts/Members section toggles) was
                 all written against `is_special_case`/`is_group`
                 .checked, kept driving that logic UNCHANGED rather than
                 rewriting every consumer, and kept in sync FROM the Type
                 select by syncCheckboxesFromType() below, never the
                 other way round. On an install without the Type column
                 the checkboxes render fully visible (no select at all)
                 — the server's checkbox→type synthesis (§1.2.8) covers
                 that install tier. -->
            <?php $musTypeHidesFlags = $profileColsExist['Type']; ?>
            <div class="border rounded p-2" style="border-color: var(--bs-border-color) !important;">
                <?php if ($musTypeHidesFlags): ?>
                    <label class="form-label small mb-1" for="mus-drawer-type">Type</label>
                    <select class="form-select form-select-sm" name="type" id="mus-drawer-type">
                        <?php foreach (MUSICIAN_TYPES as $typeKey => $typeLabel): ?>
                            <option value="<?= htmlspecialchars($typeKey) ?>"><?= htmlspecialchars($typeLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <div class="form-check form-check-inline mb-0<?= $musTypeHidesFlags ? ' d-none' : '' ?>">
                    <input class="form-check-input" type="checkbox" name="is_special_case" value="1" id="mus-drawer-is-special-case"<?= $musTypeHidesFlags ? ' aria-hidden="true" tabindex="-1"' : '' ?>>
                    <label class="form-check-label small" for="mus-drawer-is-special-case">
                        <i class="bi bi-question-circle me-1" aria-hidden="true"></i>
                        Special case (Anonymous, Traditional, etc.)
                    </label>
                </div>
                <div class="form-check form-check-inline mb-0<?= $musTypeHidesFlags ? ' d-none' : '' ?>">
                    <input class="form-check-input" type="checkbox" name="is_group" value="1" id="mus-drawer-is-group"<?= $musTypeHidesFlags ? ' aria-hidden="true" tabindex="-1"' : '' ?>>
                    <label class="form-check-label small" for="mus-drawer-is-group">
                        <i class="bi bi-people-fill me-1" aria-hidden="true"></i>
                        Group / band / collective
                    </label>
                </div>
            </div>

            <!-- Birth / Founded -->
            <div class="row g-2" data-flag-section="birth">
                <div class="col-7">
                    <label class="form-label small mb-1" for="mus-drawer-birth-place">
                        <span data-flag-label="individual">Birth place</span><span data-flag-label="group" class="d-none">Founded location</span>
                    </label>
                    <input type="text" class="form-control form-control-sm" id="mus-drawer-birth-place" name="birth_place" maxlength="255" placeholder="Start typing — e.g. London…">
                    <input type="hidden" id="mus-drawer-birth-place-id" name="birth_place_id" value="">
                </div>
                <div class="col-5">
                    <label class="form-label small mb-1" for="mus-drawer-birth-date">
                        <span data-flag-label="individual">Birth date</span><span data-flag-label="group" class="d-none">Founded date</span>
                    </label>
                    <input type="text" inputmode="numeric" autocomplete="off" class="form-control form-control-sm" id="mus-drawer-birth-date" name="birth_date" placeholder="YYYY, MM/YYYY or DD/MM/YYYY">
                    <div class="form-text small">Year, month + year, or full date — e.g. 1823, 03/1823, 15/03/1823.</div>
                </div>
            </div>

            <!-- Death / Disbandment -->
            <div class="row g-2" data-flag-section="death">
                <div class="col-7">
                    <label class="form-label small mb-1" for="mus-drawer-death-place">
                        <span data-flag-label="individual">Death place</span><span data-flag-label="group" class="d-none">Disbandment location</span>
                    </label>
                    <input type="text" class="form-control form-control-sm" id="mus-drawer-death-place" name="death_place" maxlength="255" placeholder="Start typing — e.g. Cardiff…">
                    <input type="hidden" id="mus-drawer-death-place-id" name="death_place_id" value="">
                </div>
                <div class="col-5">
                    <label class="form-label small mb-1" for="mus-drawer-death-date">
                        <span data-flag-label="individual">Death date</span><span data-flag-label="group" class="d-none">Disbandment date</span>
                    </label>
                    <input type="text" inputmode="numeric" autocomplete="off" class="form-control form-control-sm" id="mus-drawer-death-date" name="death_date" placeholder="YYYY, MM/YYYY or DD/MM/YYYY">
                    <div class="form-text small">Year, month + year, or full date — e.g. 1873, 06/1873, 21/06/1873.</div>
                </div>
            </div>

            <!-- External links — repeating sub-form -->
            <div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small mb-0">External links</label>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="mus-add-link-btn">
                        <i class="bi bi-plus me-1"></i>Add link
                    </button>
                </div>
                <div id="mus-links-container" class="d-flex flex-column gap-2"></div>
                <div class="form-text small">Pick a provider from the grouped list — General (Wikipedia, MusicBrainz, official site…), streaming services (Spotify, Apple Music…), social networks (Facebook, Instagram, YouTube…), or Other for anything else.</div>
            </div>

            <!-- IPI numbers — repeating sub-form -->
            <div data-flag-section="ipi">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small mb-0">IPI Name Numbers</label>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="mus-add-ipi-btn">
                        <i class="bi bi-plus me-1"></i>Add IPI
                    </button>
                </div>
                <div id="mus-ipi-container" class="d-flex flex-column gap-2"></div>
                <div class="form-text small">A single individual can hold more than one IPI Name Number when they're registered under different performing names.</div>
            </div>

            <!-- ISNI numbers — repeating sub-form. ISNI (International
                 Standard Name Identifier) is the ISO 27729 successor to
                 disparate per-domain identifier schemes — one 16-digit
                 code covering authors, composers, performers, researchers
                 across libraries / publishers / collecting societies.
                 Stored alongside IPI in tblMusicianIdentifiers with
                 IdentifierType discriminator. Most people will have one
                 ISNI, but the schema mirrors IPI's "multiple rows allowed"
                 shape so we never have to migrate again. -->
            <div data-flag-section="isni">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small mb-0">ISNI</label>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="mus-add-isni-btn">
                        <i class="bi bi-plus me-1"></i>Add ISNI
                    </button>
                </div>
                <div id="mus-isni-container" class="d-flex flex-column gap-2"></div>
                <div class="form-text small">ISNI is a 16-character ISO 27729 identifier (look it up at <a href="https://isni.org" target="_blank" rel="noopener">isni.org</a>). Paste any separator style — "0000 0001 2103 2683", "0000-0001-2103-2683", or just "0000000121032683" — it's normalised to the canonical "NNNN NNNN NNNN NNNX" form on save.</div>
            </div>

            <!-- #1348 — generic "Other identifiers" repeating sub-form.
                 VIAF / Wikidata / ORCID share ONE section with a per-row
                 type picker (the dedicated IPI + ISNI sections above stay
                 unchanged). Each row stores its IdentifierType discriminator
                 in tblMusicianIdentifiers alongside IPI/ISNI; the type
                 is allow-list-validated server-side ($normaliseOtherId). -->
            <div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small mb-0">Other identifiers</label>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="mus-add-otherid-btn">
                        <i class="bi bi-plus me-1"></i>Add identifier
                    </button>
                </div>
                <div id="mus-otherid-container" class="d-flex flex-column gap-2"></div>
                <div class="form-text small">Authority-control IDs — VIAF, Wikidata, GND, FAST, WorldCat, LoC, ORCID, IdRef, Trove and more. Paste a bare ID, or paste the full authority URL into an External Link and it'll be auto-detected here. Shown as a chip on the public people page.</div>
            </div>

            <!-- AKA / aliases — repeating sub-form. Mirrors the
                 MusicBrainz alias model in a slimmed-down shape: each
                 row carries a Name, a Type (legal / artist / pseudonym /
                 nickname / maiden / search-hint / misspelling / other)
                 and an optional Locale tag for transliterations
                 (ja, ru-Latn, …). Surfaced as JSON-LD alternateName on
                 /people/<slug> and searched alongside the canonical
                 Name in editor typeahead + the admin filter. -->
            <div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small mb-0">AKA / Aliases</label>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="mus-add-alias-btn">
                        <i class="bi bi-plus me-1"></i>Add alias
                    </button>
                </div>
                <div id="mus-aliases-container" class="d-flex flex-column gap-2"></div>
                <div class="form-text small">Alternative names that should match this person in search — legal name, stage name, common misspellings, transliterations. Each alias carries a type and optional locale tag.</div>
            </div>

            <!-- Members (#1502) — visible only when "Group / band /
                 collective" is ticked. A Group's constituent people,
                 each an EXISTING tblMusicians registry row (never a
                 free-text name — a member must already be registered,
                 unlike the AKA/Aliases section above). Add/remove
                 persist immediately via their own add_member /
                 remove_member AJAX endpoints (dispatched near the top
                 of this file, ahead of the classic POST switch) rather
                 than waiting for the drawer's main Save button — that
                 mirrors the Merge modal's live "resolve as you type"
                 feel and means a mis-add can be undone with one click
                 without a full form re-submit. Requires the person to
                 already have a real Id (i.e. Edit mode, not Add) since
                 SubjectMusicianId is a NOT NULL FK — the "unsaved" note
                 below covers that case. -->
            <div data-flag-section="group-members" class="d-none">
                <label class="form-label small mb-1">Members</label>
                <div id="mus-members-unsaved" class="alert alert-secondary py-2 small mb-2 d-none">
                    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                    Save this person first — members can only be added once the group itself has been registered.
                </div>
                <div id="mus-members-container" class="d-flex flex-column gap-1 mb-2"></div>
                <div class="input-group input-group-sm" id="mus-members-add-row">
                    <input type="text" class="form-control" id="mus-member-search" list="mus-member-datalist"
                           autocomplete="off" placeholder="Type a registered person's name…">
                    <datalist id="mus-member-datalist"></datalist>
                    <button type="button" class="btn btn-outline-secondary" id="mus-add-member-btn">
                        <i class="bi bi-plus me-1"></i>Add
                    </button>
                </div>
                <?php if ($relationColsExist['RelationType']): ?>
                    <!-- #1741 P4a — optional membership dates + note. Blank
                         From/To is the common case (a v1 catalogue almost
                         never knows exact tenure dates) — both are parsed
                         via the same partialDateParse() the Birth/Death
                         fields use, so "1998" alone is a valid entry. -->
                    <div class="row g-2 mt-1">
                        <div class="col-4">
                            <input type="text" class="form-control form-control-sm" id="mus-member-date-from"
                                   inputmode="numeric" autocomplete="off" placeholder="Member since (opt.)">
                        </div>
                        <div class="col-4">
                            <input type="text" class="form-control form-control-sm" id="mus-member-date-to"
                                   inputmode="numeric" autocomplete="off" placeholder="Until (blank = current)">
                        </div>
                        <div class="col-4">
                            <input type="text" class="form-control form-control-sm" id="mus-member-note"
                                   maxlength="255" placeholder="Note (opt.)">
                        </div>
                    </div>
                <?php endif; ?>
                <div class="form-text small">Only people already in the registry can be added — search picks from existing records, matching the Merge modal's target picker.</div>
            </div>

            <!-- #1741 P4a — "Portrayed by" sub-panel. Visible only for
                 Character/Other-type rows (a "person portrayed in a
                 dramatisation" concept doesn't apply to a real individual
                 or a Group). Same live-search + Add/Remove shape as
                 Members above, but relation_type is always 'portrays' and
                 the direction is REVERSED: this row is the OBJECT
                 (the portrayed figure), the picked person becomes the
                 SUBJECT (the portrayer) — action=add_relation posts
                 subject_id=<picked>, object_id=<this row's id> (see the
                 addMusicianRelation() doc-block in musician_helpers.php
                 for the verbatim direction rule). Only renders at all
                 when RelationType exists — a 'portrays' relation cannot
                 be represented on an un-migrated install. -->
            <?php if ($relationColsExist['RelationType']): ?>
                <div data-flag-section="portrayed-by" class="d-none">
                    <label class="form-label small mb-1">
                        <i class="bi bi-person-video2 me-1" aria-hidden="true"></i>Portrayed by
                    </label>
                    <div id="mus-portrayedby-unsaved" class="alert alert-secondary py-2 small mb-2 d-none">
                        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                        Save this person first — portrayers can only be added once this row has been registered.
                    </div>
                    <div id="mus-portrayedby-container" class="d-flex flex-column gap-1 mb-2"></div>
                    <div class="input-group input-group-sm" id="mus-portrayedby-add-row">
                        <input type="text" class="form-control" id="mus-portrayedby-search" list="mus-portrayedby-datalist"
                               autocomplete="off" placeholder="Type a registered person's name…">
                        <datalist id="mus-portrayedby-datalist"></datalist>
                        <button type="button" class="btn btn-outline-secondary" id="mus-add-portrayedby-btn">
                            <i class="bi bi-plus me-1"></i>Add
                        </button>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-4">
                            <input type="text" class="form-control form-control-sm" id="mus-portrayedby-date-from"
                                   inputmode="numeric" autocomplete="off" placeholder="From (opt.)">
                        </div>
                        <div class="col-4">
                            <input type="text" class="form-control form-control-sm" id="mus-portrayedby-date-to"
                                   inputmode="numeric" autocomplete="off" placeholder="To (opt.)">
                        </div>
                        <div class="col-4">
                            <input type="text" class="form-control form-control-sm" id="mus-portrayedby-note"
                                   maxlength="255" placeholder="Note (opt.)">
                        </div>
                    </div>
                    <div class="form-text small">Who has been credited as portraying this figure — e.g. an actor in a dramatisation. Only people already in the registry can be added.</div>
                </div>
            <?php endif; ?>

            <!-- #1741 P4a — Biography (public "About" text — musician.php
                 renders this, falling back to Notes only when Biography
                 is empty) + Disambiguation (short parenthetical shown
                 after the name, e.g. "(the elder)", when two musicians
                 share a name). Always rendered (mirrors the #1501
                 Maiden-surname "always show, gate only the write"
                 convention) — the write itself is column-gated
                 server-side. -->
            <div>
                <label class="form-label small mb-1" for="mus-drawer-biography">Biography (public)</label>
                <textarea class="form-control form-control-sm" id="mus-drawer-biography" name="biography" rows="4" placeholder="Shown on this person's public page. Leave blank to fall back to Notes below."></textarea>
            </div>
            <div>
                <label class="form-label small mb-1" for="mus-drawer-disambiguation">Disambiguation</label>
                <input type="text" class="form-control form-control-sm" id="mus-drawer-disambiguation" name="disambiguation" maxlength="255" placeholder="e.g. the elder — shown after the name when two musicians share it">
            </div>

            <!-- Notes -->
            <div>
                <label class="form-label small mb-1" for="mus-drawer-notes">Notes (internal — not shown publicly)</label>
                <textarea class="form-control form-control-sm" id="mus-drawer-notes" name="notes" rows="3" placeholder="Anything that doesn't fit the structured fields above."></textarea>
            </div>

            <!-- Footer -->
            <div class="d-flex justify-content-end gap-2 mt-auto pt-3 border-top border-secondary">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="offcanvas">Cancel</button>
                <button type="submit" class="btn btn-amber-solid btn-sm">
                    <i class="bi bi-save me-1"></i>Save
                </button>
            </div>
        </form>
    </div>

    <!-- Templates for the repeating sub-form rows. {i} placeholder gets
         replaced by the JS with the row's array index so PHP receives
         them as $_POST['links'][i][...] and $_POST['ipi'][i][...]. -->
    <?php
        /* Build the link-type dropdown from the tblExternalLinkTypes
           registry instead of the deprecated PHP catalogue (#586 →
           unified into tblExternalLinkTypes). Grouped by Category for
           the <optgroup>; the values are the numeric registry Ids so
           the auto-detect module's default slugToOptionValue() lookup
           (which cross-references window._iHymnsLinkTypes by id) does
           the right thing without the per-page translation kludge. */
        $linkOptionsByCat = [];
        foreach ($linkTypesForPerson as $lt) {
            $cat = (string)($lt['category'] ?? 'other');
            if (!isset($linkOptionsByCat[$cat])) $linkOptionsByCat[$cat] = [];
            $linkOptionsByCat[$cat][] = $lt;
        }
        $linkCatLabels = [
            'official'    => 'Official',
            'information' => 'Information',
            'read'        => 'Read',
            'sheet-music' => 'Sheet music',
            'listen'      => 'Listen',
            'watch'       => 'Watch',
            'purchase'    => 'Purchase',
            'authority'   => 'Authority',
            'social'      => 'Social',
            'other'       => 'Other',
        ];
        $linkCatOrder = ['official','information','read','sheet-music','listen','watch','purchase','authority','social','other'];
    ?>
    <template id="mus-link-row-template">
        <div class="card bg-dark border-secondary mus-link-row" data-row-kind="link">
            <div class="card-body py-2">
                <div class="d-flex align-items-start gap-2">
                    <div class="flex-grow-1">
                        <div class="row g-2 mb-1">
                            <div class="col-12 col-md-5">
                                <select class="form-select form-select-sm" name="links[{i}][type_id]">
                                    <option value="">— pick a link type —</option>
                                    <?php foreach ($linkCatOrder as $catKey): ?>
                                        <?php if (empty($linkOptionsByCat[$catKey])) continue; ?>
                                        <optgroup label="<?= htmlspecialchars($linkCatLabels[$catKey] ?? $catKey) ?>">
                                            <?php foreach ($linkOptionsByCat[$catKey] as $lt): ?>
                                                <option value="<?= (int)$lt['id'] ?>"><?= htmlspecialchars((string)$lt['name']) ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                    <?php
                                        /* Catch-all for any category the labels map doesn't cover —
                                           guarantees a new registry category shows up rather than
                                           silently disappearing. */
                                        $leftoverCats = array_diff(array_keys($linkOptionsByCat), $linkCatOrder);
                                        foreach ($leftoverCats as $cat):
                                    ?>
                                        <optgroup label="<?= htmlspecialchars($cat) ?>">
                                            <?php foreach ($linkOptionsByCat[$cat] as $lt): ?>
                                                <option value="<?= (int)$lt['id'] ?>"><?= htmlspecialchars((string)$lt['name']) ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-7">
                                <input type="url" class="form-control form-control-sm"
                                       name="links[{i}][url]" placeholder="https://…" required>
                            </div>
                        </div>
                        <div class="row g-2">
                            <div class="col-12">
                                <input type="text" class="form-control form-control-sm"
                                       name="links[{i}][label]" placeholder="Label (optional)">
                            </div>
                        </div>
                        <input type="hidden" name="links[{i}][sort_order]" value="{i}">
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-danger mus-row-remove"
                            title="Remove this link" aria-label="Remove this link">
                        <i class="bi bi-x" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        </div>
    </template>
    <!-- Registry payload for the shared iHymnsLinkDetect module so the
         default slugToOptionValue lookup can resolve detected slugs
         to the numeric option values in the template above. -->
    <script>
        window._iHymnsLinkTypes = <?= json_encode($linkTypesForPerson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        /* #1367 — the authority-control identifier registry slice the
           paste-a-URL auto-extract needs (every provider's label + raw extract
           pattern bodies + pickable flag). JSON_HEX_TAG escapes < and > in the
           PAYLOAD so it can never contain a script-closing sequence. NOTE: this
           comment must NOT write that sequence literally either — the HTML
           parser closes the <script> on the raw bytes regardless of JS comment
           syntax, which previously terminated this block early (dumping the
           config as page text AND leaving an unterminated comment that broke
           window._iHymnsLinkTypes and the paste-a-URL auto-detect). Slashes in
           the payload are LEFT ESCAPED (no JSON_UNESCAPED_SLASHES) — "\/" is
           valid JSON and harmless to new RegExp(). */
        window._musIdentifierConfig = <?= json_encode(creditIdentifierClientConfig(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <template id="mus-ipi-row-template">
        <div class="card bg-dark border-secondary mus-ipi-row" data-row-kind="ipi">
            <div class="card-body py-2">
                <div class="d-flex align-items-start gap-2">
                    <div class="flex-grow-1">
                        <div class="row g-2 mb-1">
                            <div class="col-12 col-md-4">
                                <input type="text" class="form-control form-control-sm"
                                       name="ipi[{i}][number]" placeholder="IPI number" required>
                            </div>
                            <div class="col-12 col-md-8">
                                <input type="text" class="form-control form-control-sm"
                                       name="ipi[{i}][name_used]" placeholder="Name used (optional)">
                            </div>
                        </div>
                        <div class="row g-2">
                            <div class="col-12">
                                <input type="text" class="form-control form-control-sm"
                                       name="ipi[{i}][notes]" placeholder="Notes (optional)">
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-danger mus-row-remove"
                            title="Remove this IPI" aria-label="Remove this IPI">
                        <i class="bi bi-x" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        </div>
    </template>
    <template id="mus-isni-row-template">
        <div class="card bg-dark border-secondary mus-isni-row" data-row-kind="isni">
            <div class="card-body py-2">
                <div class="d-flex align-items-start gap-2">
                    <div class="flex-grow-1">
                        <div class="row g-2 mb-1">
                            <div class="col-12 col-md-4">
                                <input type="text" class="form-control form-control-sm mus-isni-number"
                                       name="isni[{i}][number]" placeholder="0000 0000 0000 0000"
                                       inputmode="numeric"
                                       title="16 characters: 15 digits + checksum (digit or X). Spaces / hyphens accepted on input — stored canonically as four space-separated groups of four."
                                       required>
                            </div>
                            <div class="col-12 col-md-8">
                                <input type="text" class="form-control form-control-sm"
                                       name="isni[{i}][name_used]" placeholder="Name used (optional)">
                            </div>
                        </div>
                        <div class="row g-2">
                            <div class="col-12">
                                <input type="text" class="form-control form-control-sm"
                                       name="isni[{i}][notes]" placeholder="Notes (optional)">
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-danger mus-row-remove"
                            title="Remove this ISNI" aria-label="Remove this ISNI">
                        <i class="bi bi-x" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        </div>
    </template>
    <!-- #1348 — generic other-identifier row: type picker + value +
         name-used + notes, mirroring the ISNI template's markup. The type
         <select> is constrained to the same allow-list the server validates
         against ($normaliseOtherId): viaf / wikidata / orcid. -->
    <template id="mus-otherid-row-template">
        <div class="card bg-dark border-secondary mus-otherid-row" data-row-kind="otherid">
            <div class="card-body py-2">
                <div class="d-flex align-items-start gap-2">
                    <div class="flex-grow-1">
                        <div class="row g-2 mb-1">
                            <div class="col-12 col-md-4">
                                <select class="form-control form-control-sm mus-otherid-type"
                                        name="otherid[{i}][type]">
                                    <?php
                                        /* #1367 — options derived from the central
                                           authority-control registry (pickable only),
                                           bucketed into <optgroup>s by the entry's
                                           `group`. Groups emit in this fixed order;
                                           any registry group not listed falls through
                                           the catch-all below so a future group can't
                                           silently vanish. */
                                        $musIdPickable = creditIdentifierPickable();
                                        $musIdByGroup  = [];
                                        foreach ($musIdPickable as $musIdSlug => $musIdDef) {
                                            $musIdGrp = (string)($musIdDef['group'] ?? 'Other');
                                            $musIdByGroup[$musIdGrp][$musIdSlug] = $musIdDef;
                                        }
                                        $musIdGroupOrder = ['International', 'National', 'Academic', 'People'];
                                        $musIdSeenGroups = [];
                                        foreach ($musIdGroupOrder as $musIdGrp):
                                            if (empty($musIdByGroup[$musIdGrp])) continue;
                                            $musIdSeenGroups[$musIdGrp] = true;
                                    ?>
                                        <optgroup label="<?= htmlspecialchars($musIdGrp) ?>">
                                            <?php foreach ($musIdByGroup[$musIdGrp] as $musIdSlug => $musIdDef): ?>
                                                <option value="<?= htmlspecialchars($musIdSlug) ?>"><?= htmlspecialchars((string)$musIdDef['label']) ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                    <?php
                                        /* Catch-all for any pickable group the fixed
                                           order above didn't cover. */
                                        foreach ($musIdByGroup as $musIdGrp => $musIdDefs):
                                            if (!empty($musIdSeenGroups[$musIdGrp])) continue;
                                    ?>
                                        <optgroup label="<?= htmlspecialchars((string)$musIdGrp) ?>">
                                            <?php foreach ($musIdDefs as $musIdSlug => $musIdDef): ?>
                                                <option value="<?= htmlspecialchars($musIdSlug) ?>"><?= htmlspecialchars((string)$musIdDef['label']) ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-8">
                                <input type="text" class="form-control form-control-sm"
                                       name="otherid[{i}][value]" placeholder="Identifier value"
                                       required>
                            </div>
                        </div>
                        <div class="row g-2 mb-1">
                            <div class="col-12">
                                <input type="text" class="form-control form-control-sm"
                                       name="otherid[{i}][name_used]" placeholder="Name used (optional)">
                            </div>
                        </div>
                        <div class="row g-2">
                            <div class="col-12">
                                <input type="text" class="form-control form-control-sm"
                                       name="otherid[{i}][notes]" placeholder="Notes (optional)">
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-danger mus-row-remove"
                            title="Remove this identifier" aria-label="Remove this identifier">
                        <i class="bi bi-x" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        </div>
    </template>
    <template id="mus-alias-row-template">
        <div class="card bg-dark border-secondary mus-alias-row" data-row-kind="alias">
            <div class="card-body py-2">
                <div class="d-flex align-items-start gap-2">
                    <div class="flex-grow-1">
                        <div class="row g-2">
                            <div class="col-12 col-md-5">
                                <input type="text" class="form-control form-control-sm"
                                       name="aliases[{i}][name]" placeholder="Alternative name"
                                       maxlength="255" required>
                            </div>
                            <div class="col-12 col-md-4">
                                <select class="form-select form-select-sm" name="aliases[{i}][type]">
                                    <?php foreach ($ALIAS_TYPES as $key => $label): ?>
                                        <option value="<?= htmlspecialchars($key) ?>"<?= $key === 'other' ? ' selected' : '' ?>>
                                            <?= htmlspecialchars($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-3">
                                <input type="text" class="form-control form-control-sm"
                                       name="aliases[{i}][locale]" placeholder="Locale"
                                       maxlength="35"
                                       title="Optional IETF BCP 47 tag for transliterations (ja, ru-Latn, zh-Hans, …)">
                            </div>
                        </div>
                        <input type="hidden" name="aliases[{i}][sort_order]" value="{i}">
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-danger mus-row-remove"
                            title="Remove this alias" aria-label="Remove this alias">
                        <i class="bi bi-x" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        </div>
    </template>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>

    <!-- Sortable table headers (#644). Cycles asc → desc → unsorted on
         click of any <th data-sort-key>. Sort runs over visible rows
         only so it composes cleanly with the search/filter below. -->
    <script type="module">
        import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';
        bootSortableTables();
    </script>

    <!-- Live location autocomplete on the Birth / Death place inputs.
         Backed by /manage/places-api.php (Photon + Nominatim) with a
         tblPlaces upsert on pick so two curators picking "Sydney"
         resolve to the same registry row. Schema-tolerant — the API
         endpoint returns a 503 with a clear message when
         migrate-places.php hasn't run yet, which the module treats
         as "leave hidden id empty, persist display string only". -->
    <script src="/js/modules/place-search.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/place-search.js') ?>"></script>
    <script>
        (function () {
            if (!window.iHymnsPlaceSearch) return;
            const birthIn = document.getElementById('mus-drawer-birth-place');
            const deathIn = document.getElementById('mus-drawer-death-place');
            const birthIdIn = document.getElementById('mus-drawer-birth-place-id');
            const deathIdIn = document.getElementById('mus-drawer-death-place-id');
            if (birthIn && birthIdIn) {
                window.iHymnsPlaceSearch.attach(birthIn, { hiddenIdInput: birthIdIn });
            }
            if (deathIn && deathIdIn) {
                window.iHymnsPlaceSearch.attach(deathIn, { hiddenIdInput: deathIdIn });
            }
        })();
    </script>

    <script>
        /* Honour a bare ?id= deep-link (#1641 item 1).
         *
         * ELI5: the public person page has an "Edit this person" button. It
         * used to dump you on the full list with nothing selected. Now it takes
         * you to that person's row.
         *
         * Detail: includes/pages/musician.php:450 links to
         * /manage/musicians?id=<Id>, but this page read $_GET['id'] ONLY
         * inside the action=view_songs AJAX branch — a bare ?id= was ignored by
         * the server and by every script here. The admin landed on the whole
         * list, unscrolled, with nothing highlighted, and no indication that
         * the link had meant something.
         *
         * Identical shape to #1623 (a page emitting ?open= while the editor
         * read ?song=). Two instances of one class is why #1672 proposes a
         * standing check over every internal admin deep-link rather than
         * fixing them one at a time — this fix closes the instance, not the
         * class.
         *
         * Handled client-side rather than server-side deliberately: the row may
         * be filtered out of view by the page's own role/classification
         * filters, and only the client knows the current filter state. Scrolling
         * also has to happen after layout, which the server cannot do. */
        (function () {
            const wanted = new URLSearchParams(window.location.search).get('id');
            if (!wanted || !/^\d+$/.test(wanted)) { return; }

            const row = document.getElementById('mus-person-' + wanted);
            /* Silently doing nothing is what this fix exists to remove, so say
               something when the id does not resolve — a deleted or merged
               person is exactly when an admin needs to know why the deep-link
               did not land. */
            if (!row) {
                console.warn('[musicians] ?id=' + wanted + ' matched no row on this page.');
                return;
            }

            row.scrollIntoView({
                /* Respect the reduced-motion preference, as router.js:264 does
                   — an option passed here is NOT overridden by the
                   scroll-behavior CSS (#1646 item 3). */
                behavior: document.body.classList.contains('reduce-motion') ? 'auto' : 'smooth',
                block: 'center',
            });
            row.classList.add('table-active');
        })();
    </script>

    <script>
        /* SECURITY (#1386 audit): quote-safe HTML escaper for the merge-preview,
           which interpolates free-text DB fields (external-link type/label,
           IPI/ISNI number/name_used/notes) into innerHTML. Without it, a stored
           payload planted by an edit_people user executes when a global_admin
           opens the merge modal. Mirrors the 5-char regex map used elsewhere. */
        function musEsc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }
        /* Client-side search + filter. The list is small enough that
           re-applying both predicates over every row on every keystroke
           is comfortably under a frame, no debouncing needed. If the
           list grows past ~5k rows, swap to a server-side endpoint. */
        (function () {
            const input    = document.getElementById('mus-search');
            const clearBtn = document.getElementById('mus-search-clear');
            const tbody    = document.getElementById('mus-tbody');
            const empty    = document.getElementById('mus-empty-row');
            const buttons  = document.querySelectorAll('.filter-btn');
            const countrySel = document.getElementById('mus-country-filter');
            if (!input || !tbody) return;

            let activeFilter   = 'all';
            let activeCountry  = '';

            function apply() {
                const q = input.value.trim().toLowerCase();
                let visibleCount = 0;
                const rows = tbody.querySelectorAll('tr:not(.empty-row)');
                rows.forEach(row => {
                    const haystack    = row.dataset.haystack || '';
                    /* Include AKA names in the search haystack so a
                       query for the alias spelling matches the row of
                       the canonical name (e.g. typing the maiden name
                       finds the post-marriage row). Lower-cased on the
                       client because PHP wrote it as the raw display
                       form. */
                    const akaHaystack = (row.dataset.aka || '').toLowerCase();
                    const roles       = (row.dataset.roles || '').split(',').filter(Boolean);
                    const isRegOnly   = row.dataset.registryOnly === '1';
                    const country     = (row.dataset.countryCode || '').toLowerCase();
                    let matchRole = true;
                    if (activeFilter === 'registry-only') {
                        matchRole = isRegOnly;
                    } else if (activeFilter !== 'all') {
                        matchRole = roles.includes(activeFilter);
                    }
                    const matchSearch  = q === '' || haystack.includes(q) || akaHaystack.includes(q);
                    const matchCountry = activeCountry === '' || country === activeCountry;
                    const show = matchRole && matchSearch && matchCountry;
                    row.classList.toggle('no-match', !show);
                    if (show) visibleCount++;
                });
                empty.style.display = visibleCount === 0 ? '' : 'none';
            }

            input.addEventListener('input', apply);
            clearBtn.addEventListener('click', () => { input.value = ''; input.focus(); apply(); });
            buttons.forEach(b => b.addEventListener('click', () => {
                buttons.forEach(x => x.classList.remove('active'));
                b.classList.add('active');
                activeFilter = b.dataset.filter || 'all';
                apply();
            }));
            if (countrySel) {
                countrySel.addEventListener('change', () => {
                    activeCountry = (countrySel.value || '').toLowerCase();
                    apply();
                });
            }
        })();

        /* =========================================================================
           Person detail drawer — drives Add and Update_person actions.
           Open from:
             - #mus-add-btn          → empty drawer, action=add
             - .mus-edit-btn (per-row) → pre-filled drawer
                                          - action=add if row has no registry_id
                                          - action=update_person otherwise
           ========================================================================= */
        (function () {
            const drawerEl  = document.getElementById('musDrawer');
            const form      = document.getElementById('mus-drawer-form');
            const titleEl   = document.getElementById('mus-drawer-title');
            const nameHelp  = document.getElementById('mus-drawer-name-help');
            const actionIn  = document.getElementById('mus-drawer-action');
            const idIn      = document.getElementById('mus-drawer-id');
            const nameIn    = document.getElementById('mus-drawer-name');
            const linksBox  = document.getElementById('mus-links-container');
            const ipiBox    = document.getElementById('mus-ipi-container');
            const isniBox   = document.getElementById('mus-isni-container');
            const otherIdBox = document.getElementById('mus-otherid-container'); // #1348
            const aliasBox  = document.getElementById('mus-aliases-container');
            const linkTpl   = document.getElementById('mus-link-row-template');
            const ipiTpl    = document.getElementById('mus-ipi-row-template');
            const isniTpl   = document.getElementById('mus-isni-row-template');
            const otherIdTpl = document.getElementById('mus-otherid-row-template'); // #1348
            const aliasTpl  = document.getElementById('mus-alias-row-template');
            const addBtn    = document.getElementById('mus-add-btn');
            const addLinkBtn= document.getElementById('mus-add-link-btn');
            const addIpiBtn = document.getElementById('mus-add-ipi-btn');
            const addIsniBtn= document.getElementById('mus-add-isni-btn');
            const addAliasBtn = document.getElementById('mus-add-alias-btn');
            /* #1502 — Group members card-list refs. */
            const membersSection   = document.querySelector('[data-flag-section="group-members"]');
            const membersUnsavedEl = document.getElementById('mus-members-unsaved');
            const membersAddRow    = document.getElementById('mus-members-add-row');
            const membersContainer = document.getElementById('mus-members-container');
            const memberSearchIn   = document.getElementById('mus-member-search');
            const memberDatalist   = document.getElementById('mus-member-datalist');
            const addMemberBtn     = document.getElementById('mus-add-member-btn');
            const memberDateFromIn = document.getElementById('mus-member-date-from');
            const memberDateToIn   = document.getElementById('mus-member-date-to');
            const memberNoteIn     = document.getElementById('mus-member-note');
            /* #1741 P4a — Type <select> (present only when the column
               exists — see the else-branch checkboxes in the PHP above). */
            const typeSelect = document.getElementById('mus-drawer-type');
            /* #1741 P4a — "Portrayed by" sub-panel refs. All may be null
               (RelationType-absent install renders none of this markup);
               every use below is optional-chained / existence-guarded. */
            const portrayedBySection   = document.querySelector('[data-flag-section="portrayed-by"]');
            const portrayedByUnsavedEl = document.getElementById('mus-portrayedby-unsaved');
            const portrayedByAddRow    = document.getElementById('mus-portrayedby-add-row');
            const portrayedByContainer = document.getElementById('mus-portrayedby-container');
            const portrayedBySearchIn  = document.getElementById('mus-portrayedby-search');
            const portrayedByDatalist  = document.getElementById('mus-portrayedby-datalist');
            const addPortrayedByBtn    = document.getElementById('mus-add-portrayedby-btn');
            const portrayedByDateFromIn = document.getElementById('mus-portrayedby-date-from');
            const portrayedByDateToIn   = document.getElementById('mus-portrayedby-date-to');
            const portrayedByNoteIn     = document.getElementById('mus-portrayedby-note');
            if (!drawerEl || !form) return;

            const drawer = bootstrap.Offcanvas.getOrCreateInstance(drawerEl);
            let linkIndex  = 0;
            let ipiIndex   = 0;
            let isniIndex  = 0;
            let otherIdIndex = 0; // #1348
            let aliasIndex = 0;

            /* #trim — client-side UX half of the whitespace-trim fix (the
               server side is the shared musTrimmed() PHP helper). ELI5: as
               soon as a curator pastes or tabs away from a text field, any
               accidental leading/trailing whitespace is stripped so what
               they SEE matches what actually gets saved — no surprise
               later ("why doesn't this ISNI match?" turned out to be a
               trailing space only the server silently cleaned up).
               ONE delegated listener (capture phase — 'blur'/'focusout'
               don't bubble the same way `input` does) on the whole drawer
               form covers every current AND future text field/sub-form row
               (name, notes, first/sur/suffix names, birth/death place free
               text, every links[]/ipi[]/isni[]/otherid[]/aliases[] text
               input) — never re-attach a per-field listener as new rows
               are dynamically added. */
            function musTrimFieldNow(el) {
                if (!el || typeof el.matches !== 'function') return;
                if (!el.matches('input[type="text"], input[type="url"], textarea')) return;
                const trimmed = el.value.replace(/^\s+|\s+$/g, '');
                if (trimmed !== el.value) el.value = trimmed;
            }
            form.addEventListener('blur', (ev) => musTrimFieldNow(ev.target), true);
            form.addEventListener('paste', (ev) => {
                /* The pasted text hasn't landed in .value yet while the
                   'paste' event itself is handling — defer one tick. */
                const el = ev.target;
                window.setTimeout(() => musTrimFieldNow(el), 0);
            }, true);

            /* Wire each musicians link row to the shared
               iHymnsLinkDetect module. The dropdown's option values
               are now numeric tblExternalLinkTypes.Id (post-#833
               unification), so the module's default slugToOptionValue
               lookup (cross-referencing window._iHymnsLinkTypes by
               id) Just Works — no per-page translation map needed
               any more. */
            function wireCpLinkRowAutoDetect(row) {
                if (!window.iHymnsLinkDetect || typeof window.iHymnsLinkDetect.attachAutoDetect !== 'function') return;
                window.iHymnsLinkDetect.attachAutoDetect(row, {
                    selectSelector: 'select[name$="[type_id]"]',
                });
            }

            /* Append a new sub-form row, optionally pre-filled. The
               template's {i} placeholders get replaced with the
               current per-kind index so PHP receives the rows as a
               densely-keyed array. */
            function addLinkRow(prefill) {
                const html = linkTpl.innerHTML.replaceAll('{i}', String(linkIndex++));
                linksBox.insertAdjacentHTML('beforeend', html);
                const row = linksBox.lastElementChild;
                if (prefill) {
                    const sel = row.querySelector('select');
                    if (sel && prefill.type_id) sel.value = String(prefill.type_id);
                    const url = row.querySelector('input[type="url"]');
                    if (url) url.value = prefill.url || '';
                    const lbl = row.querySelector('input[name$="[label]"]');
                    if (lbl) lbl.value = prefill.label || '';
                    const ord = row.querySelector('input[name$="[sort_order]"]');
                    if (ord && prefill.sort_order !== undefined) ord.value = prefill.sort_order;
                }
                /* Auto-detect provider from the pasted URL — same
                   shared module every other admin surface uses, now
                   with no translation map since the option values
                   are numeric registry ids. */
                wireCpLinkRowAutoDetect(row);
                /* Duplicate-URL detection — same shared module the
                   songbooks / works / song-editor surfaces use. Fires
                   a toast + auto-removes this row when its URL collides
                   with another row's URL on the same person. */
                if (window.iHymnsExtLinkDupeDetect && typeof window.iHymnsExtLinkDupeDetect.attach === 'function') {
                    window.iHymnsExtLinkDupeDetect.attach(row, { container: linksBox });
                }
                return row;
            }
            function addIpiRow(prefill) {
                const html = ipiTpl.innerHTML.replaceAll('{i}', String(ipiIndex++));
                ipiBox.insertAdjacentHTML('beforeend', html);
                const row = ipiBox.lastElementChild;
                if (prefill) {
                    row.querySelector('input[name$="[number]"]').value    = prefill.number    || '';
                    row.querySelector('input[name$="[name_used]"]').value = prefill.name_used || '';
                    row.querySelector('input[name$="[notes]"]').value     = prefill.notes     || '';
                }
                /* Re-apply the flag rules (#628) — ensures rows added
                   AFTER the modal is open inherit the correct disabled
                   state when Special case is ticked. */
                if (typeof applyFlagLabels === 'function') applyFlagLabels();
                return row;
            }
            /* Mirror of PHP's canonicaliseIsni() — strips every non-[0-9X]
               character (any separator the curator paints with), upper-
               cases, and regroups a 16-char result into "NNNN NNNN NNNN NNNX".
               Non-16-char inputs stay as the cleaned string so the server-
               side normaliser produces a matching value. */
            function canonicaliseIsniJS(raw) {
                const clean = (raw || '').toUpperCase().replace(/[^0-9X]/g, '');
                if (/^\d{15}[0-9X]$/.test(clean)) {
                    return clean.slice(0, 4) + ' '
                         + clean.slice(4, 8) + ' '
                         + clean.slice(8, 12) + ' '
                         + clean.slice(12, 16);
                }
                return clean;
            }
            function addIsniRow(prefill) {
                const html = isniTpl.innerHTML.replaceAll('{i}', String(isniIndex++));
                isniBox.insertAdjacentHTML('beforeend', html);
                const row = isniBox.lastElementChild;
                const numIn = row.querySelector('input.mus-isni-number');
                if (prefill) {
                    if (numIn) numIn.value = prefill.number || '';
                    row.querySelector('input[name$="[name_used]"]').value = prefill.name_used || '';
                    row.querySelector('input[name$="[notes]"]').value     = prefill.notes     || '';
                }
                /* Reformat on blur so the curator sees the canonical
                   "NNNN NNNN NNNN NNNX" shape before submit — matches
                   what the server-side normaliser will store. Live
                   formatting (input event) would fight the cursor;
                   blur is the standard pattern for credit-card-style
                   masking. */
                if (numIn) {
                    numIn.addEventListener('blur', () => {
                        const canonical = canonicaliseIsniJS(numIn.value);
                        if (canonical && canonical !== numIn.value) {
                            numIn.value = canonical;
                        }
                    });
                }
                if (typeof applyFlagLabels === 'function') applyFlagLabels();
                return row;
            }
            /* #1348 — append a generic other-identifier row, optionally
               pre-filled. The loaded row's value arrives as prefill.number
               (the shared load shape maps IdentifierValue → 'number'), so we
               fall back through .number → .value; the type drives the
               <select> (defaults to viaf when absent). */
            function addOtherIdRow(prefill) {
                const html = otherIdTpl.innerHTML.replaceAll('{i}', String(otherIdIndex++));
                otherIdBox.insertAdjacentHTML('beforeend', html);
                const row = otherIdBox.lastElementChild;
                if (prefill) {
                    const t = row.querySelector('select[name$="[type]"]');
                    if (t) t.value = prefill.type || 'viaf';
                    row.querySelector('input[name$="[value]"]').value     = prefill.number || prefill.value || '';
                    row.querySelector('input[name$="[name_used]"]').value = prefill.name_used || '';
                    row.querySelector('input[name$="[notes]"]').value     = prefill.notes || '';
                }
                if (typeof applyFlagLabels === 'function') applyFlagLabels();
                return row;
            }

            /* =================================================================
               #1367 — paste-a-URL → auto-add an authority identifier.

               ELI5: when a curator pastes an authority-control URL (a VIAF /
               GND / WorldCat / … link) into an External Link's URL box, we
               recognise the provider, pull the bare id out, and quietly add a
               matching row to "Other identifiers" — so they don't have to
               re-type the id by hand.

               WHY a JS mirror (not a server round-trip): it's instant and
               works while the drawer is still being filled in, BEFORE save.
               The bodies in window._musIdentifierConfig are the SAME raw regex
               bodies the PHP creditIdentifierExtractFromUrl() uses (PHP wraps
               '#…#i'; here we do new RegExp(body,'i')), so client + server
               agree on what each URL extracts to.
               ================================================================= */

            /* Mirror of PHP creditIdentifierExtractFromUrl(): scan every
               provider's extract bodies; return the FIRST match's
               {type, value, label}. Only considers `pickable` providers so we
               never auto-add ISNI (it owns a dedicated section). */
            function musDetectIdentifierFromUrl(url) {
                const cfg = window._musIdentifierConfig || {};
                const u   = (url || '').trim();
                if (u === '') return null;
                for (const slug in cfg) {
                    if (!Object.prototype.hasOwnProperty.call(cfg, slug)) continue;
                    const def = cfg[slug];
                    if (!def || def.pickable !== true) continue;          /* skip non-pickable (isni) */
                    const bodies = Array.isArray(def.extract) ? def.extract : [];
                    for (let b = 0; b < bodies.length; b++) {
                        let re;
                        try { re = new RegExp(bodies[b], 'i'); }
                        catch (_) { continue; }                            /* malformed body — skip */
                        const m = re.exec(u);
                        if (m && m[1]) {
                            return { type: slug, value: m[1], label: def.label || slug };
                        }
                    }
                }
                return null;
            }

            /* True when an Other-identifiers row already holds this exact
               (type, value) — so a re-blur of the same link doesn't add a
               duplicate identifier row. Compares the row's <select> value +
               the value input, both lower-/trim-normalised. */
            function musHasOtherIdRow(type, value) {
                const wanted = (value || '').trim();
                const rows   = otherIdBox.querySelectorAll('.mus-otherid-row');
                for (let i = 0; i < rows.length; i++) {
                    const sel = rows[i].querySelector('select[name$="[type]"]');
                    const val = rows[i].querySelector('input[name$="[value]"]');
                    if (sel && val
                        && sel.value === type
                        && (val.value || '').trim() === wanted) {
                        return true;
                    }
                }
                return false;
            }

            /* Flash a brief, dismiss-itself hint under the link row that
               triggered the auto-add. Bootstrap .form-text .text-info; removed
               after a few seconds so it doesn't clutter the form. */
            function musFlashLinkHint(linkRow, message) {
                if (!linkRow) return;
                /* Don't stack hints — replace any existing one on this row. */
                const prev = linkRow.querySelector('.mus-otherid-autohint');
                if (prev) prev.remove();
                const hint = document.createElement('div');
                hint.className = 'form-text small text-info mus-otherid-autohint mt-1';
                hint.setAttribute('role', 'status');
                hint.textContent = message;
                const body = linkRow.querySelector('.card-body') || linkRow;
                body.appendChild(hint);
                window.setTimeout(() => { hint.remove(); }, 6000);
            }

            /* Delegated listener — bound ONCE on the link container (rows are
               added dynamically, so per-row binding would miss them). Fires on
               `change`/`blur` (capture, since `blur` doesn't bubble) of any
               link-row URL input; if the URL resolves to a pickable provider
               and that (type, value) isn't already present, auto-add it. */
            function musHandleLinkUrlEvent(ev) {
                const input = ev.target;
                if (!input || !input.matches || !input.matches('input[name$="[url]"]')) return;
                if (!input.closest('.mus-link-row')) return;
                const hit = musDetectIdentifierFromUrl(input.value);
                if (!hit) return;
                if (musHasOtherIdRow(hit.type, hit.value)) return;
                addOtherIdRow({ type: hit.type, value: hit.value });
                musFlashLinkHint(
                    input.closest('.mus-link-row'),
                    'Added ' + hit.label + ' ' + hit.value + ' to Other identifiers.'
                );
            }
            /* `change` bubbles; `blur` does not, so listen for it in the
               capture phase. Both are bound on linksBox exactly once. */
            linksBox.addEventListener('change', musHandleLinkUrlEvent);
            linksBox.addEventListener('blur', musHandleLinkUrlEvent, true);

            function addAliasRow(prefill) {
                if (!aliasTpl) return null;
                const html = aliasTpl.innerHTML.replaceAll('{i}', String(aliasIndex++));
                aliasBox.insertAdjacentHTML('beforeend', html);
                const row = aliasBox.lastElementChild;
                if (prefill) {
                    row.querySelector('input[name$="[name]"]').value    = prefill.Name    || prefill.name    || '';
                    const typeSel = row.querySelector('select[name$="[type]"]');
                    if (typeSel) typeSel.value = prefill.Type || prefill.type || 'other';
                    const localeIn = row.querySelector('input[name$="[locale]"]');
                    if (localeIn) localeIn.value = prefill.Locale || prefill.locale || '';
                    const ord = row.querySelector('input[name$="[sort_order]"]');
                    if (ord && (prefill.SortOrder !== undefined || prefill.sort_order !== undefined)) {
                        ord.value = prefill.SortOrder ?? prefill.sort_order;
                    }
                }
                return row;
            }

            function resetDrawer() {
                form.reset();
                linksBox.innerHTML  = '';
                ipiBox.innerHTML    = '';
                if (isniBox)  isniBox.innerHTML  = '';
                if (otherIdBox) otherIdBox.innerHTML = ''; // #1348
                if (aliasBox) aliasBox.innerHTML = '';
                linkIndex  = 0;
                ipiIndex   = 0;
                isniIndex  = 0;
                otherIdIndex = 0; // #1348
                aliasIndex = 0;
                /* form.reset() doesn't reset hidden inputs whose
                   default value is empty — explicitly clear the
                   place-id sidecars so a previous open's pick
                   doesn't leak into the next session. */
                document.getElementById('mus-drawer-birth-place-id').value = '';
                document.getElementById('mus-drawer-death-place-id').value = '';
                /* #1502 — clear the Members card-list + search state so a
                   previously-opened group's member list doesn't leak into
                   the next drawer open. */
                if (membersContainer) membersContainer.innerHTML = '';
                if (memberSearchIn)   memberSearchIn.value = '';
                if (memberDatalist)   memberDatalist.innerHTML = '';
                if (memberDateFromIn) memberDateFromIn.value = '';
                if (memberDateToIn)   memberDateToIn.value = '';
                if (memberNoteIn)     memberNoteIn.value = '';
                memberLabelToKey = new Map();
                /* #1741 P4a — same clear for the Portrayed-by sub-panel. */
                if (portrayedByContainer) portrayedByContainer.innerHTML = '';
                if (portrayedBySearchIn)  portrayedBySearchIn.value = '';
                if (portrayedByDatalist)  portrayedByDatalist.innerHTML = '';
                if (portrayedByDateFromIn) portrayedByDateFromIn.value = '';
                if (portrayedByDateToIn)   portrayedByDateToIn.value = '';
                if (portrayedByNoteIn)     portrayedByNoteIn.value = '';
                portrayedByLabelToKey = new Map();
                /* #1741 P4a — default the Type select back to 'person'
                   (form.reset() already handles this for a real <select>,
                   but it's set explicitly here too since resetDrawer() is
                   also called right before the Edit pre-fill assigns its
                   own value — no observable double-set). */
                if (typeSelect) typeSelect.value = 'person';
            }

            /* Open empty drawer for Add. */
            addBtn?.addEventListener('click', () => {
                resetDrawer();
                actionIn.value = 'add';
                idIn.value     = '';
                titleEl.textContent = 'Add person';
                nameIn.readOnly = false;
                nameHelp.textContent = 'The canonical spelling. To rename later, save first, then use the Rename action — renames cascade to every song that cites this person.';
                /* form.reset() above already cleared the flag checkboxes;
                   refresh the label-swap state so a previously-opened
                   group edit doesn't leave the labels reading "Founded". */
                applyFlagLabels();
                drawer.show();
                setTimeout(() => nameIn.focus(), 200);
            });

            /* Open pre-filled drawer for Edit. Reads the person JSON
               from the row's data-person attribute. */
            document.addEventListener('click', (ev) => {
                const btn = ev.target.closest('.mus-edit-btn');
                if (!btn) return;
                const row = btn.closest('tr');
                if (!row) return;
                const raw = row.getAttribute('data-person');
                if (!raw) return;
                let person;
                try { person = JSON.parse(raw); }
                catch (_) { return; }

                resetDrawer();
                if (person.registry_id) {
                    actionIn.value = 'update_person';
                    idIn.value     = String(person.registry_id);
                    titleEl.textContent = 'Edit person — ' + person.name;
                    nameIn.value    = person.name || '';
                    nameIn.readOnly = true;
                    nameHelp.textContent = 'Locked when editing — use the Rename action to change the canonical name and cascade across the catalogue.';
                } else {
                    /* In-use name not yet in the registry — pre-fill the
                       name and let the admin enrich it. The action stays
                       'add' (this is a new registry row); the form's
                       INSERT IGNORE-style uniqueness check on the server
                       handles the case where the name was added by a
                       concurrent editor save in the meantime. */
                    actionIn.value = 'add';
                    idIn.value     = '';
                    titleEl.textContent = 'Add to registry — ' + person.name;
                    nameIn.value    = person.name || '';
                    nameIn.readOnly = false;
                    nameHelp.textContent = 'This name is already credited on songs — adding to the registry lets you attach biographical metadata. The Name field is the canonical spelling that the song-credit tables already use; editing it here would create a mismatch, so leave it as-is and use Rename later if you need to change it.';
                }
                document.getElementById('mus-drawer-birth-place').value = person.birth_place || '';
                document.getElementById('mus-drawer-birth-place-id').value = person.birth_place_id ? String(person.birth_place_id) : '';
                /* Populate the date text field from the editor-input form
                   (birth_date_input = "1823" / "03/1823" / "15/03/1823")
                   so a partial date shows as the curator typed it, not the
                   normalised "1823-01-01". Falls back to the raw ISO date
                   if the *_input value is absent (e.g. an older cached row). */
                document.getElementById('mus-drawer-birth-date').value  = person.birth_date_input || person.birth_date || '';
                document.getElementById('mus-drawer-death-place').value = person.death_place || '';
                document.getElementById('mus-drawer-death-place-id').value = person.death_place_id ? String(person.death_place_id) : '';
                document.getElementById('mus-drawer-death-date').value  = person.death_date_input || person.death_date || '';
                document.getElementById('mus-drawer-notes').value       = person.notes       || '';
                /* #1741 P4a — Biography + Disambiguation pre-fill. */
                const bioIn = document.getElementById('mus-drawer-biography');
                if (bioIn) bioIn.value = person.biography || '';
                const disambigIn = document.getElementById('mus-drawer-disambiguation');
                if (disambigIn) disambigIn.value = person.disambiguation || '';
                /* #584 / #585 — pre-tick the classification flags. */
                document.getElementById('mus-drawer-is-special-case').checked = !!person.is_special_case;
                document.getElementById('mus-drawer-is-group').checked        = !!person.is_group;
                /* #1741 P4a — Type select pre-fill wins over the flags
                   above when it exists: person.type is the registry's
                   OWN Type value when the column is present (server
                   default 'person' otherwise), so this is strictly more
                   precise than re-deriving from is_group/is_special_case
                   (which can't distinguish group-vs-orchestra or
                   character-vs-other). syncCheckboxesFromType() re-syncs
                   the two hidden checkboxes from it so applyFlagLabels()
                   (called right below) still sees a consistent state. */
                if (typeSelect) {
                    typeSelect.value = person.type
                        || (person.is_group ? 'group' : (person.is_special_case ? 'other' : 'person'));
                    syncCheckboxesFromType();
                }
                /* #934 — pre-fill the structured-name fields. If the row
                   has them populated, use those directly. If they're
                   NULL (legacy / partly-migrated install) AND this is
                   an individual, decompose Name client-side as a
                   starting point — curators can refine on save. */
                const isIndividual = !person.is_group && !person.is_special_case;
                if (person.first_names || person.surname || person.suffix) {
                    firstNamesIn.value = person.first_names || '';
                    surnameIn.value    = person.surname     || '';
                    suffixIn.value     = person.suffix      || '';
                } else if (isIndividual && person.name) {
                    const decomposed = decomposeNameClient(person.name);
                    firstNamesIn.value = decomposed.first;
                    surnameIn.value    = decomposed.surname;
                    suffixIn.value     = decomposed.suffix;
                } else {
                    firstNamesIn.value = '';
                    surnameIn.value    = '';
                    suffixIn.value     = '';
                }
                /* #1501 — optional Maiden Surname pre-fill. No decompose
                   fallback (unlike the trio above) — there's nothing to
                   derive a birth surname FROM, it's either stored or not. */
                document.getElementById('mus-drawer-maiden-surname').value = person.maiden_surname || '';
                applyFlagLabels();
                (person.links   || []).forEach(l => addLinkRow(l));
                (person.ipi     || []).forEach(r => addIpiRow(r));
                (person.isni    || []).forEach(r => addIsniRow(r));
                (person.otherid || []).forEach(r => addOtherIdRow(r)); // #1348
                (person.aliases || []).forEach(a => addAliasRow(a));
                /* #1502 — pre-fill this Group's current members. Empty for
                   individuals / special-cases (server never populates the
                   array for them) and for pre-migration installs alike. */
                (person.members || []).forEach(m => addMemberRow(m));
                /* #1741 P4a — pre-fill this figure's current portrayers.
                   Empty for every non character/other row (server never
                   populates the array for them) and for installs without
                   RelationType alike. */
                (person.portrayed_by || []).forEach(m => addPortrayedByRow(m));
                drawer.show();
            });

            /* Mutually-exclusive checkboxes + label-swap for groups. */
            const specialCaseCb = document.getElementById('mus-drawer-is-special-case');
            const groupCb       = document.getElementById('mus-drawer-is-group');
            const birthPlaceIn  = document.getElementById('mus-drawer-birth-place');
            const birthDateIn   = document.getElementById('mus-drawer-birth-date');
            const deathPlaceIn  = document.getElementById('mus-drawer-death-place');
            const deathDateIn   = document.getElementById('mus-drawer-death-date');
            const addIpiButton  = document.getElementById('mus-add-ipi-btn');
            const ipiSection    = document.querySelector('[data-flag-section="ipi"]');
            const addIsniButton = document.getElementById('mus-add-isni-btn');
            const isniSection   = document.querySelector('[data-flag-section="isni"]');
            /* #934 — structured-name field references + helpers. */
            const firstNamesIn  = document.getElementById('mus-drawer-first-names');
            const surnameIn     = document.getElementById('mus-drawer-surname');
            const suffixIn      = document.getElementById('mus-drawer-suffix');
            const namePartsBox  = document.querySelector('[data-flag-section="name-parts"]');

            /* Compose the Name preview from the three split fields.
               Mirrors composePersonName() server-side: trim + collapse
               consecutive whitespace + drop empty parts. */
            function composeNameClient(first, surname, suffix) {
                const parts = [first, surname, suffix]
                    .map(s => (s || '').trim())
                    .filter(s => s !== '');
                return parts.join(' ').replace(/\s+/g, ' ');
            }
            /* Mirrors decomposePersonName() — used to seed the three
               fields on edit when they're NULL. Keep the regex in sync
               with the PHP-side helper if either changes. */
            function decomposeNameClient(name) {
                const trimmed = (name || '').trim().replace(/\s+/g, ' ');
                if (trimmed === '') return { first: '', surname: '', suffix: '' };
                let work = trimmed;
                if (work.includes(',')) {
                    const idx = work.indexOf(',');
                    const head = work.slice(0, idx).trim();
                    const tail = work.slice(idx + 1).trim();
                    if (head !== '' && tail !== '') work = tail + ' ' + head;
                }
                const tokens = work.split(/\s+/).filter(t => t !== '');
                if (tokens.length === 0) return { first: '', surname: '', suffix: '' };
                const SUFFIX_RE = /^(?:Jr|Sr|II|III|IV|V|VI|VII|VIII|PhD|Ph\.D\.|MD|M\.D\.|Esq|Esq\.|D\.D\.|D\.Min\.|MA|M\.A\.|BA|B\.A\.)$/i;
                const suffixParts = [];
                while (tokens.length > 1) {
                    const tail = tokens[tokens.length - 1];
                    const tailNorm = tail.replace(/[.,]+$/, '');
                    if (SUFFIX_RE.test(tail) || SUFFIX_RE.test(tailNorm)) {
                        suffixParts.unshift(tokens.pop());
                        continue;
                    }
                    break;
                }
                const suffix = suffixParts.join(' ');
                if (tokens.length === 1) return { first: '', surname: tokens[0], suffix };
                const surname = tokens.pop();
                return { first: tokens.join(' '), surname, suffix };
            }
            /* Live-update the Name field as the curator types into the
               three split inputs. Only fires for individuals — group /
               special-case flows leave Name as direct input. */
            function syncNameFromParts() {
                if (specialCaseCb?.checked || groupCb?.checked) return;
                const composed = composeNameClient(
                    firstNamesIn?.value, surnameIn?.value, suffixIn?.value
                );
                if (nameIn) nameIn.value = composed;
            }
            firstNamesIn?.addEventListener('input', syncNameFromParts);
            surnameIn?.addEventListener('input',    syncNameFromParts);
            suffixIn?.addEventListener('input',     syncNameFromParts);

            function applyFlagLabels() {
                const isGroup       = !!groupCb?.checked;
                const isSpecialCase = !!specialCaseCb?.checked;

                /* Label swap (#629) — birth/death → founded/disbandment
                   when Group is ticked. */
                document.querySelectorAll('[data-flag-label="individual"]').forEach(el => {
                    el.classList.toggle('d-none', isGroup);
                });
                document.querySelectorAll('[data-flag-label="group"]').forEach(el => {
                    el.classList.toggle('d-none', !isGroup);
                });

                /* Field disable rules (#628 / #629):
                   - Special case → bio inputs (place + date) AND the
                     entire IPI section disabled. Special-case rows
                     (Anonymous / Traditional / Public Domain / Unknown)
                     have no real bio.
                   - Group → place inputs disabled (no single physical
                     birth/death location for a band). Date inputs
                     stay editable as Founded / Disbandment dates.
                   - Default (individual) → everything editable. */
                const placesDisabled = isSpecialCase || isGroup;
                const datesDisabled  = isSpecialCase;
                const ipiDisabled    = isSpecialCase;

                if (birthPlaceIn) birthPlaceIn.disabled = placesDisabled;
                if (deathPlaceIn) deathPlaceIn.disabled = placesDisabled;
                if (birthDateIn)  birthDateIn.disabled  = datesDisabled;
                if (deathDateIn)  deathDateIn.disabled  = datesDisabled;

                /* Disable the Add IPI button + every existing IPI row's
                   inputs. The container holds the rows added via the
                   template so we walk the children. Same treatment for
                   the parallel ISNI section. */
                if (addIpiButton) addIpiButton.disabled = ipiDisabled;
                if (ipiSection) {
                    ipiSection.querySelectorAll('input').forEach(inp => {
                        inp.disabled = ipiDisabled;
                    });
                    ipiSection.classList.toggle('opacity-50', ipiDisabled);
                }
                if (addIsniButton) addIsniButton.disabled = ipiDisabled;
                if (isniSection) {
                    isniSection.querySelectorAll('input').forEach(inp => {
                        inp.disabled = ipiDisabled;
                    });
                    isniSection.classList.toggle('opacity-50', ipiDisabled);
                }

                /* #934 — hide the structured-name fields for Group /
                   Special-case rows (they don't have first/last/suffix
                   semantics) and switch the canonical Name field to
                   direct input. For individuals show the three split
                   fields and re-enable the live-compose into Name. */
                const splitHidden = isGroup || isSpecialCase;
                if (namePartsBox) namePartsBox.classList.toggle('d-none', splitHidden);
                if (firstNamesIn) firstNamesIn.disabled = splitHidden;
                if (surnameIn)    surnameIn.disabled    = splitHidden;
                if (suffixIn)     suffixIn.disabled     = splitHidden;
                if (nameIn) {
                    /* Direct Name input is allowed for groups/special
                       cases (or for individuals on the "add to registry"
                       path). On Edit-individual, the Rename-action rule
                       still applies — server rejects a Name change that
                       contradicts the existing canonical spelling. */
                    if (splitHidden) {
                        nameIn.readOnly = (idIn?.value && actionIn?.value === 'update_person');
                    } else {
                        /* Individual row: Name preview reflects the
                           three split fields, so it's never directly
                           edited. */
                        nameIn.readOnly = true;
                        syncNameFromParts();
                    }
                }

                /* #1502 — Members card-list only applies to a Group; hide
                   it entirely for individuals / special-case rows. When
                   shown, refreshMembersAvailability() further decides
                   between the "save first" note (no real Id yet) and the
                   live search + list (Id known — Edit mode). */
                if (membersSection) membersSection.classList.toggle('d-none', !isGroup);
                refreshMembersAvailability();

                /* #1741 P4a — "Portrayed by" sub-panel only applies to a
                   Character/Other-type row — both of which map to
                   IsSpecialCase=1 (musicianTypeApply()'s fixed mapping),
                   so reusing the same isSpecialCase boolean the IPI
                   section above already keys off of is correct: the ONE
                   place that distinguishes "Special case" further into
                   character-vs-other is the Type select itself, which
                   this section doesn't need to — a portrayable "Other"
                   row (e.g. an anonymised/composite figure) is just as
                   valid a target as a "Character" row. */
                if (portrayedBySection) portrayedBySection.classList.toggle('d-none', !isSpecialCase);
                refreshPortrayedByAvailability();
            }
            specialCaseCb?.addEventListener('change', () => {
                if (specialCaseCb.checked && groupCb) groupCb.checked = false;
                applyFlagLabels();
            });
            groupCb?.addEventListener('change', () => {
                if (groupCb.checked && specialCaseCb) specialCaseCb.checked = false;
                applyFlagLabels();
            });
            /* #1741 P4a — Type <select> drives the two hidden checkboxes
               above (never the other way round — see the drawer markup's
               doc-comment for why). */
            function syncCheckboxesFromType() {
                if (!typeSelect) return;
                const t = typeSelect.value;
                if (specialCaseCb) specialCaseCb.checked = (t === 'character' || t === 'other');
                if (groupCb)       groupCb.checked       = (t === 'group' || t === 'orchestra');
            }
            typeSelect?.addEventListener('change', () => {
                syncCheckboxesFromType();
                applyFlagLabels();
            });

            /* Add-row buttons inside the drawer. */
            addLinkBtn?.addEventListener('click',  () => addLinkRow());
            addIpiBtn?.addEventListener('click',   () => addIpiRow());
            addIsniBtn?.addEventListener('click',  () => addIsniRow());
            document.getElementById('mus-add-otherid-btn')?.addEventListener('click', () => addOtherIdRow()); // #1348
            addAliasBtn?.addEventListener('click', () => addAliasRow());

            /* Remove-row delegation. */
            drawerEl.addEventListener('click', (ev) => {
                const remove = ev.target.closest('.mus-row-remove');
                if (!remove) return;
                const row = remove.closest('.mus-link-row, .mus-ipi-row, .mus-isni-row, .mus-otherid-row, .mus-alias-row'); // #1348
                if (row) row.remove();
            });

            /* =====================================================================
               Group members (#1502). Unlike the AKA/Aliases + other repeating
               sub-forms above (free-text, saved together with the rest of the
               drawer on Save), Members reference an EXISTING tblMusicians
               row and persist immediately via their own add_member /
               remove_member endpoints — a member link can only be created
               once the group itself has a real Id, so there is no sane
               "queue it up, send with the next Save" behaviour to fall back
               to. This mirrors the Merge modal's live target-picker (reusing
               the SAME ?action=merge_target_search endpoint, #1500) and its
               own small, single-purpose fetch calls.
               ===================================================================== */
            let memberLabelToKey = new Map();
            let memberInflight   = null;
            let memberDebounce   = null;

            /** Escape a string for safe insertion as HTML text content. */
            function escapeMemberHtml(s) {
                return String(s || '').replace(/[&<>"']/g, (c) => ({
                    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
                }[c]));
            }

            /** Every member id currently rendered, so search + add can
                exclude people already in the list (no point suggesting a
                duplicate the server would just no-op anyway). */
            function currentMemberIds() {
                if (!membersContainer) return [];
                return Array.from(membersContainer.querySelectorAll('[data-member-id]'))
                    .map((el) => parseInt(el.getAttribute('data-member-id'), 10))
                    .filter((n) => n > 0);
            }

            function addMemberRow(m) {
                if (!membersContainer || !m) return;
                const id   = m.id ?? m.Id ?? 0;
                const name = m.name ?? m.Name ?? '';
                if (!id) return;
                const html = '<div class="d-flex align-items-center justify-content-between border rounded px-2 py-1" '
                    + 'style="border-color: var(--bs-border-color) !important;" data-member-id="' + parseInt(id, 10) + '">'
                    + '<span class="small">' + escapeMemberHtml(name) + '</span>'
                    + '<button type="button" class="btn btn-sm btn-outline-danger mus-member-remove" '
                    + 'aria-label="Remove ' + escapeMemberHtml(name) + ' from members"><i class="bi bi-x-lg" aria-hidden="true"></i></button>'
                    + '</div>';
                membersContainer.insertAdjacentHTML('beforeend', html);
            }

            /* Toggle the "save this person first" note vs the live search +
               Add button, based on whether the drawer currently has a real
               Id (Edit mode) — a brand-new, unsaved Group has no
               SubjectMusicianId yet for a member link to reference. */
            function refreshMembersAvailability() {
                const hasId = !!(idIn && idIn.value);
                if (membersUnsavedEl) membersUnsavedEl.classList.toggle('d-none', hasId);
                if (membersAddRow)    membersAddRow.classList.toggle('d-none', !hasId);
            }

            /* Live-search typeahead — reuses ?action=merge_target_search
               (#1500) exactly like the Merge modal's target picker (same
               debounced-GET + <datalist> shape), filtered CLIENT-SIDE to
               candidates that already have a registry id: ObjectMusicianId is
               a NOT NULL FK, so an in-use-only ("not yet in registry")
               candidate — which the Merge picker happily offers — can't be
               picked here. Also excludes people already in the visible
               member list. */
            function memberLookup(query) {
                if (!memberDatalist) return;
                if (memberInflight) memberInflight.abort();
                const ac = new AbortController();
                memberInflight = ac;
                const groupId = idIn && idIn.value ? parseInt(idIn.value, 10) : 0;
                const url = '/manage/musicians?action=merge_target_search'
                          + '&q=' + encodeURIComponent(query)
                          + '&exclude_id=' + encodeURIComponent(groupId)
                          + '&limit=20';
                fetch(url, { credentials: 'same-origin', signal: ac.signal })
                    .then((r) => (r.ok ? r.json() : { candidates: [] }))
                    .then((data) => {
                        const already = new Set(currentMemberIds());
                        const list = (Array.isArray(data.candidates) ? data.candidates : [])
                            .filter((c) => c.id && !already.has(c.id));
                        memberLabelToKey = new Map();
                        memberDatalist.innerHTML = list.map((c) => {
                            memberLabelToKey.set(c.name, c.id);
                            return '<option value="' + escapeMemberHtml(c.name) + '"></option>';
                        }).join('');
                    })
                    .catch((err) => { if (err.name !== 'AbortError') { /* search is a nicety, not critical path */ } });
            }
            memberSearchIn?.addEventListener('input', () => {
                const v = memberSearchIn.value;
                clearTimeout(memberDebounce);
                if (v.trim() === '') {
                    if (memberDatalist) memberDatalist.innerHTML = '';
                    return;
                }
                memberDebounce = setTimeout(() => memberLookup(v.trim()), 200);
            });
            memberSearchIn?.addEventListener('focus', () => memberLookup(memberSearchIn.value.trim()));

            /* Add — resolves the typed/picked name back to its id via
               memberLabelToKey (mirroring the Merge modal's labelToKey),
               then POSTs add_member. validateCsrfRequest() on the server
               accepts this via the still-valid session csrf_token field
               (read straight off the drawer form) — the X-Requested-With
               header is sent too so the check stays robust even if that
               token happens to have gone stale on a long-open drawer. */
            addMemberBtn?.addEventListener('click', () => {
                const groupId = idIn && idIn.value ? parseInt(idIn.value, 10) : 0;
                if (!groupId) return;
                const picked = memberLabelToKey.get(memberSearchIn ? memberSearchIn.value : '');
                if (!picked) {
                    window.alert('Pick a suggested person from the list first.');
                    return;
                }
                const csrfField = form.querySelector('input[name="csrf_token"]');
                addMemberBtn.disabled = true;
                fetch('/manage/musicians', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'add_member',
                        group_id: String(groupId),
                        member_id: String(picked),
                        /* #1741 P4a — optional dates/note; empty strings are
                           fine, the server's partialDateParse('') resolves
                           to "no date". */
                        date_from: memberDateFromIn ? memberDateFromIn.value : '',
                        date_to: memberDateToIn ? memberDateToIn.value : '',
                        note: memberNoteIn ? memberNoteIn.value : '',
                        csrf_token: csrfField ? csrfField.value : '',
                    }),
                })
                    .then((r) => r.json().then((j) => ({ ok: r.ok, body: j })))
                    .then(({ ok, body }) => {
                        addMemberBtn.disabled = false;
                        if (!ok || !body.ok) {
                            window.alert((body && body.error) || 'Could not add member.');
                            return;
                        }
                        addMemberRow(body.member);
                        if (memberSearchIn)   memberSearchIn.value = '';
                        if (memberDatalist)   memberDatalist.innerHTML = '';
                        if (memberDateFromIn) memberDateFromIn.value = '';
                        if (memberDateToIn)   memberDateToIn.value = '';
                        if (memberNoteIn)     memberNoteIn.value = '';
                    })
                    .catch(() => { addMemberBtn.disabled = false; window.alert('Could not add member.'); });
            });

            /* Remove — delegated so it works for rows added either via the
               Edit pre-fill or a just-added member, same convention as the
               other repeating sub-forms' remove buttons above. */
            membersContainer?.addEventListener('click', (ev) => {
                const btn = ev.target.closest('.mus-member-remove');
                if (!btn) return;
                const row = btn.closest('[data-member-id]');
                if (!row) return;
                const memberId = parseInt(row.getAttribute('data-member-id'), 10);
                const groupId  = idIn && idIn.value ? parseInt(idIn.value, 10) : 0;
                if (!groupId || !memberId) return;
                const csrfField = form.querySelector('input[name="csrf_token"]');
                btn.disabled = true;
                fetch('/manage/musicians', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'remove_member',
                        group_id: String(groupId),
                        member_id: String(memberId),
                        csrf_token: csrfField ? csrfField.value : '',
                    }),
                })
                    .then((r) => r.json().then((j) => ({ ok: r.ok, body: j })))
                    .then(({ ok, body }) => {
                        if (!ok || !body.ok) {
                            btn.disabled = false;
                            window.alert((body && body.error) || 'Could not remove member.');
                            return;
                        }
                        row.remove();
                    })
                    .catch(() => { btn.disabled = false; window.alert('Could not remove member.'); });
            });

            /* =====================================================================
               #1741 P4a — "Portrayed by" sub-panel. Same live-persist shape as
               Group members above (search via the shared merge_target_search
               endpoint, add/remove without waiting for the drawer's Save
               button), but relation_type is fixed to 'portrays' and the
               ADD request direction is reversed: THIS row is the object (the
               portrayed figure), the picked person becomes the subject (the
               portrayer) — action=add_relation posts subject_id=<picked>,
               object_id=<this row's id>. REMOVE posts the relation row's OWN
               Id to action=remove_relation — unlike Members (which deletes by
               the (group,member) pair), the server's
               loadMusicianPortrayedBy()/…Bulk() SELECT `m.Id AS RelationId`
               precisely so this panel can delete by primary key instead (see
               musicianRelationRowShape()'s doc-block, musician_helpers.php).
               Every DOM ref here may be null (RelationType-absent install
               renders none of this markup) — every use below is guarded.
               ===================================================================== */
            let portrayedByLabelToKey = new Map();
            let portrayedByInflight   = null;
            let portrayedByDebounce   = null;

            /** Every portrayer person id currently rendered, so a re-add of
                the same portrayer isn't offered twice in the same session
                (the server itself is idempotent regardless — this is just
                UX). */
            function currentPortrayedByPersonIds() {
                if (!portrayedByContainer) return [];
                return Array.from(portrayedByContainer.querySelectorAll('[data-person-id]'))
                    .map((el) => parseInt(el.getAttribute('data-person-id'), 10))
                    .filter((n) => n > 0);
            }

            function addPortrayedByRow(m) {
                if (!portrayedByContainer || !m) return;
                const personId   = m.id ?? m.Id ?? 0;                         // the portrayer's tblMusicians.Id (search-exclusion only)
                const relationId = m.relationId ?? m.RelationId ?? 0;         // the tblMusicianRelations row's OWN Id (used to remove)
                const name       = m.name ?? m.Name ?? '';
                if (!personId || !relationId) return;
                const html = '<div class="d-flex align-items-center justify-content-between border rounded px-2 py-1" '
                    + 'style="border-color: var(--bs-border-color) !important;" '
                    + 'data-person-id="' + parseInt(personId, 10) + '" data-relation-id="' + parseInt(relationId, 10) + '">'
                    + '<span class="small">' + escapeMemberHtml(name) + '</span>'
                    + '<button type="button" class="btn btn-sm btn-outline-danger mus-portrayedby-remove" '
                    + 'aria-label="Remove ' + escapeMemberHtml(name) + ' from portrayed-by"><i class="bi bi-x-lg" aria-hidden="true"></i></button>'
                    + '</div>';
                portrayedByContainer.insertAdjacentHTML('beforeend', html);
            }

            /* Toggle the "save this person first" note vs the live search +
               Add button — same rule as refreshMembersAvailability(): a
               brand-new, unsaved figure has no ObjectMusicianId yet for a
               relation to reference. */
            function refreshPortrayedByAvailability() {
                if (!portrayedBySection) return;
                const hasId = !!(idIn && idIn.value);
                if (portrayedByUnsavedEl) portrayedByUnsavedEl.classList.toggle('d-none', hasId);
                if (portrayedByAddRow)    portrayedByAddRow.classList.toggle('d-none', !hasId);
            }

            /* Live-search typeahead — same shared merge_target_search
               endpoint + <datalist> shape as memberLookup() above, excluding
               THIS row itself (a figure can't portray itself) and anyone
               already listed as a portrayer. */
            function portrayedByLookup(query) {
                if (!portrayedByDatalist) return;
                if (portrayedByInflight) portrayedByInflight.abort();
                const ac = new AbortController();
                portrayedByInflight = ac;
                const figureId = idIn && idIn.value ? parseInt(idIn.value, 10) : 0;
                const url = '/manage/musicians?action=merge_target_search'
                          + '&q=' + encodeURIComponent(query)
                          + '&exclude_id=' + encodeURIComponent(figureId)
                          + '&limit=20';
                fetch(url, { credentials: 'same-origin', signal: ac.signal })
                    .then((r) => (r.ok ? r.json() : { candidates: [] }))
                    .then((data) => {
                        const already = new Set(currentPortrayedByPersonIds());
                        const list = (Array.isArray(data.candidates) ? data.candidates : [])
                            .filter((c) => c.id && !already.has(c.id));
                        portrayedByLabelToKey = new Map();
                        portrayedByDatalist.innerHTML = list.map((c) => {
                            portrayedByLabelToKey.set(c.name, c.id);
                            return '<option value="' + escapeMemberHtml(c.name) + '"></option>';
                        }).join('');
                    })
                    .catch((err) => { if (err.name !== 'AbortError') { /* search is a nicety, not critical path */ } });
            }
            portrayedBySearchIn?.addEventListener('input', () => {
                const v = portrayedBySearchIn.value;
                clearTimeout(portrayedByDebounce);
                if (v.trim() === '') {
                    if (portrayedByDatalist) portrayedByDatalist.innerHTML = '';
                    return;
                }
                portrayedByDebounce = setTimeout(() => portrayedByLookup(v.trim()), 200);
            });
            portrayedBySearchIn?.addEventListener('focus', () => portrayedByLookup(portrayedBySearchIn.value.trim()));

            /* Add — resolves the typed/picked name to its id, then POSTs
               action=add_relation with subject_id=<picked performer>,
               object_id=<this figure>, relation_type=portrays (the
               direction rule from addMusicianRelation()'s doc-block). */
            addPortrayedByBtn?.addEventListener('click', () => {
                const figureId = idIn && idIn.value ? parseInt(idIn.value, 10) : 0;
                if (!figureId) return;
                const picked = portrayedByLabelToKey.get(portrayedBySearchIn ? portrayedBySearchIn.value : '');
                if (!picked) {
                    window.alert('Pick a suggested person from the list first.');
                    return;
                }
                const csrfField = form.querySelector('input[name="csrf_token"]');
                addPortrayedByBtn.disabled = true;
                fetch('/manage/musicians', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'add_relation',
                        subject_id: String(picked),
                        object_id: String(figureId),
                        relation_type: 'portrays',
                        date_from: portrayedByDateFromIn ? portrayedByDateFromIn.value : '',
                        date_to: portrayedByDateToIn ? portrayedByDateToIn.value : '',
                        note: portrayedByNoteIn ? portrayedByNoteIn.value : '',
                        csrf_token: csrfField ? csrfField.value : '',
                    }),
                })
                    .then((r) => r.json().then((j) => ({ ok: r.ok, body: j })))
                    .then(({ ok, body }) => {
                        addPortrayedByBtn.disabled = false;
                        if (!ok || !body.ok) {
                            window.alert((body && body.error) || 'Could not add portrayer.');
                            return;
                        }
                        /* NOTE: `body.subject` (NOT `body.member`) — the
                           portrayer is the SUBJECT side of this relation
                           (subject_id=<picked> was posted above); `member`
                           is add_relation's back-compat shape for the
                           Members flow, where the OBJECT is what was added.
                           `body.relationId` is the new/existing relation
                           row's own Id — addPortrayedByRow() needs it for
                           the Remove button (action=remove_relation). */
                        addPortrayedByRow({
                            id: body.subject ? body.subject.id : null,
                            name: body.subject ? body.subject.name : '',
                            relationId: body.relationId,
                        });
                        if (portrayedBySearchIn)   portrayedBySearchIn.value = '';
                        if (portrayedByDatalist)   portrayedByDatalist.innerHTML = '';
                        if (portrayedByDateFromIn) portrayedByDateFromIn.value = '';
                        if (portrayedByDateToIn)   portrayedByDateToIn.value = '';
                        if (portrayedByNoteIn)     portrayedByNoteIn.value = '';
                    })
                    .catch(() => { addPortrayedByBtn.disabled = false; window.alert('Could not add portrayer.'); });
            });

            /* Remove — posts the relation row's OWN Id (this row's
               data-relation-id, set from addPortrayedByRow()'s `relationId`
               param — see loadMusicianPortrayedBy()'s `m.Id AS RelationId`
               select for where that value originates on page-load pre-fill)
               to action=remove_relation. */
            portrayedByContainer?.addEventListener('click', (ev) => {
                const btn = ev.target.closest('.mus-portrayedby-remove');
                if (!btn) return;
                const row = btn.closest('[data-relation-id]');
                if (!row) return;
                const relationId = parseInt(row.getAttribute('data-relation-id'), 10);
                if (!relationId) return;
                const csrfField = form.querySelector('input[name="csrf_token"]');
                btn.disabled = true;
                fetch('/manage/musicians', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'remove_relation',
                        id: String(relationId),
                        csrf_token: csrfField ? csrfField.value : '',
                    }),
                })
                    .then((r) => r.json().then((j) => ({ ok: r.ok, body: j })))
                    .then(({ ok, body }) => {
                        if (!ok || !body.ok) {
                            btn.disabled = false;
                            window.alert((body && body.error) || 'Could not remove portrayer.');
                            return;
                        }
                        row.remove();
                    })
                    .catch(() => { btn.disabled = false; window.alert('Could not remove portrayer.'); });
            });
        })();

        /* =========================================================================
           Rename modal — opens with the row's current name shown
           read-only and a new-name input. Submit POSTs action=rename.
           ========================================================================= */
        (function () {
            const modalEl = document.getElementById('musRenameModal');
            if (!modalEl) return;
            const modal     = bootstrap.Modal.getOrCreateInstance(modalEl);
            const idIn      = document.getElementById('mus-rename-id');
            const currentIn = document.getElementById('mus-rename-current');
            const newIn     = document.getElementById('mus-rename-new');
            const impactEl  = document.getElementById('mus-rename-impact');

            document.addEventListener('click', (ev) => {
                const btn = ev.target.closest('.mus-rename-btn');
                if (!btn) return;
                const row = btn.closest('tr');
                if (!row) return;
                const raw = row.getAttribute('data-person');
                if (!raw) return;
                let person; try { person = JSON.parse(raw); } catch (_) { return; }
                /* In-use-only rows pass through too (#626) — the
                   server's rename handler auto-registers the row by
                   name on submit. */

                /* Build a "this will affect …" line from the role
                   counts already on the row. */
                const cells = row.querySelectorAll('.role-pill');
                const counts = {};
                cells.forEach(p => {
                    const txt = p.textContent.trim();
                    const [k, v] = txt.split('·');
                    if (k && v) counts[k] = parseInt(v, 10) || 0;
                });
                const total = Object.values(counts).reduce((a, b) => a + b, 0);
                if (total === 0) {
                    impactEl.textContent = 'This person isn\'t cited by any song yet — only the registry row will be updated.';
                } else {
                    const parts = [];
                    if (counts.W  > 0) parts.push(counts.W  + ' writer(s)');
                    if (counts.C  > 0) parts.push(counts.C  + ' composer(s)');
                    if (counts.Ar > 0) parts.push(counts.Ar + ' arranger(s)');
                    if (counts.Ad > 0) parts.push(counts.Ad + ' adaptor(s)');
                    if (counts.T  > 0) parts.push(counts.T  + ' translator(s)');
                    impactEl.textContent = 'Will update ' + total + ' song-credit row(s): '
                        + parts.join(', ') + '.';
                }

                idIn.value     = person.registry_id ? String(person.registry_id) : '';
                /* In-use-only rows post the name as a fallback so the
                   server can auto-register before renaming (#626). */
                const sourceNameIn = document.getElementById('mus-rename-source-name');
                if (sourceNameIn) sourceNameIn.value = person.registry_id ? '' : (person.name || '');
                currentIn.value= person.name || '';
                newIn.value    = person.name || '';
                modal.show();
                setTimeout(() => { newIn.focus(); newIn.select(); }, 200);
            });
        })();

        /* =========================================================================
           Merge modal — opens with the source row pre-set, wires the
           Target field to a server-driven live-search typeahead
           (#1500 — ?action=merge_target_search, replacing the old
           "every person in one <select>" picker), and shows the
           source's links / IPI for migrate-or-drop selection.
           ========================================================================= */
        (function () {
            const modalEl = document.getElementById('musMergeModal');
            if (!modalEl) return;
            const modal      = bootstrap.Modal.getOrCreateInstance(modalEl);
            const sourceIdIn = document.getElementById('mus-merge-source-id');
            const sourceNameIn = document.getElementById('mus-merge-source-name');
            /* #1500 — live-search typeahead replacing the old
               "every person in one <select>" Target picker. targetTxt is
               the visible text box; targetKeyIn is the hidden field that
               carries the resolved "id:N" / "name:X" key (same shape the
               old <select> option values carried) once a suggestion is
               picked or matched. */
            const targetTxt      = document.getElementById('mus-merge-target');
            const targetKeyIn    = document.getElementById('mus-merge-target-key');
            const targetDatalist = document.getElementById('mus-merge-target-datalist');
            const childrenWrap = document.getElementById('mus-merge-children');
            const childrenEmpty = document.getElementById('mus-merge-children-empty');
            const linksBox   = document.getElementById('mus-merge-children-links');
            const ipiBox     = document.getElementById('mus-merge-children-ipi');
            const isniBoxMerge = document.getElementById('mus-merge-children-isni');
            const submitBtn  = document.getElementById('mus-merge-submit');

            /* #1500 — server-driven target search. `currentExclude` holds
               the OPEN merge's source id/name so every lookup() call
               (debounced keystrokes + the on-open empty-query call) keeps
               excluding the right source without re-threading it through
               every call site. `labelToKey` maps each rendered <option>
               label back to its "id:N" / "name:X" key — mirrors the
               parent-songbook / compiler-picker typeahead pattern already
               used on /manage/songbooks (same <input> + <datalist> +
               debounced-GET shape), just against
               ?action=merge_target_search instead of loading every
               person into the DOM up front.
               #1785 C8 — `labelToCandidate` is the additive sibling: maps
               the SAME full label to the whole candidate object (now
               carrying disambiguation/born/died/type/useCount/variant, plan
               §8.1) so a pick can render the disambiguation comparison
               panel without a second round-trip. Keying BOTH maps on the
               full disambiguated label (not the bare name) is what fixes
               the underlying bug: two byte-variant candidates used to
               render two visually IDENTICAL <option> labels, so picking
               either one collided in labelToKey; disambiguated labels
               (#id · lifespan · credits) can no longer collide. */
            let labelToKey = new Map();
            let labelToCandidate = new Map();
            let targetInflight = null;
            let targetDebounce  = null;
            const currentExclude = { id: 0, name: '' };

            /* #1785 C8 — "Eddie James — #45 · 12 credits · b. 1961". Only
               a registry candidate (c.id truthy) carries the additive
               fields; an in-use-only name keeps its existing plain label. */
            function candidateLabel(c) {
                if (!c.id) { return c.name + ' (not yet in registry)'; }
                const bits = ['#' + c.id];
                if (c.useCount) { bits.push(c.useCount + ' credit' + (c.useCount === 1 ? '' : 's')); }
                if (c.born) { bits.push('b. ' + c.born); }
                if (c.died) { bits.push('d. ' + c.died); }
                if (c.disambiguation) { bits.push(c.disambiguation); }
                return c.name + ' — ' + bits.join(' · ');
            }

            function targetLookup(query) {
                if (targetInflight) targetInflight.abort();
                const ac = new AbortController();
                targetInflight = ac;
                const url = '/manage/musicians?action=merge_target_search'
                          + '&q=' + encodeURIComponent(query)
                          + '&exclude_id=' + encodeURIComponent(currentExclude.id)
                          + '&exclude_name=' + encodeURIComponent(currentExclude.name)
                          + '&limit=20';
                fetch(url, { credentials: 'same-origin', signal: ac.signal })
                    .then(r => r.ok ? r.json() : { candidates: [] })
                    .then(data => {
                        const list = Array.isArray(data.candidates) ? data.candidates : [];
                        labelToKey = new Map();
                        labelToCandidate = new Map();
                        targetDatalist.innerHTML = list.map(c => {
                            const label = candidateLabel(c);
                            labelToKey.set(label, c.key);
                            labelToCandidate.set(label, c);
                            return '<option value="' + label.replace(/"/g, '&quot;') + '"></option>';
                        }).join('');
                    })
                    .catch(err => { if (err.name !== 'AbortError') { /* silent — search is a nicety, not critical path */ } });
            }

            /* #1785 C8 — render the source-vs-target disambiguation panel
               (plan §8.2's direct answer to "which is merging into
               which?"). Hidden until a real target candidate is picked;
               cleared (not just hidden) on every fresh merge open below. */
            const disambigWrap    = document.getElementById('mus-merge-disambig');
            const disambigSource  = document.getElementById('mus-merge-disambig-source');
            const disambigTarget  = document.getElementById('mus-merge-disambig-target');
            const disambigVariant = document.getElementById('mus-merge-disambig-variant');
            const VARIANT_LABELS = {
                'identical': 'identical bytes',
                'unicode-normalisation': 'differs only by Unicode form (combining vs. precomposed accent)',
                'whitespace': 'differs only by invisible spacing',
                'case': 'differs only by letter case',
                'punctuation': 'differs only by punctuation/accents',
            };
            function renderDisambig(candidate) {
                if (!disambigWrap) return;
                if (!candidate || !candidate.id) { disambigWrap.classList.add('d-none'); return; }
                disambigSource.textContent = currentExclude.name || '(unregistered)';
                const tBits = [];
                if (candidate.born) { tBits.push('b. ' + candidate.born); }
                if (candidate.died) { tBits.push('d. ' + candidate.died); }
                if (candidate.disambiguation) { tBits.push(candidate.disambiguation); }
                disambigTarget.textContent = candidate.name + (tBits.length ? ' (' + tBits.join(', ') + ')' : '');
                if (candidate.variant) {
                    const label = VARIANT_LABELS[candidate.variant] || candidate.variant;
                    disambigVariant.innerHTML = '<span class="badge bg-info-subtle text-info-emphasis">'
                        + candidate.variant + '</span> <span class="text-secondary">' + label + '</span>';
                } else {
                    disambigVariant.innerHTML = '<span class="badge bg-secondary-subtle text-secondary-emphasis">different words</span>';
                }
                disambigWrap.classList.remove('d-none');
            }

            targetTxt?.addEventListener('input', () => {
                const v = targetTxt.value;
                /* Picked-from-datalist sync: an exact label match means
                   the curator selected (or typed exactly) one of the
                   suggestions, so commit its key to the hidden field —
                   mirrors the parent-songbook typeahead's "picked" sync.
                   Anything else clears the key so the Merge button can't
                   enable on a half-typed, unresolved name (#583's
                   irreversible-merge guard depends on a REAL target). */
                targetKeyIn.value = labelToKey.get(v) || '';
                renderDisambig(labelToCandidate.get(v) || null);
                refreshSubmitState();
                if (v.trim() === '') {
                    targetDatalist.innerHTML = '';
                    return;
                }
                clearTimeout(targetDebounce);
                targetDebounce = setTimeout(() => targetLookup(v.trim()), 200);
            });
            targetTxt?.addEventListener('focus', () => {
                /* Empty-query lookup on focus — surfaces the most-used
                   candidates immediately, same convention as the
                   songbooks parent-search typeahead. */
                targetLookup(targetTxt.value.trim());
            });

            document.addEventListener('click', (ev) => {
                const btn = ev.target.closest('.mus-merge-btn');
                if (!btn) return;
                const row = btn.closest('tr');
                if (!row) return;
                const raw = row.getAttribute('data-person');
                if (!raw) return;
                let person; try { person = JSON.parse(raw); } catch (_) { return; }
                /* In-use-only sources flow through too (#626) — the
                   server auto-registers by name if source_id is empty. */

                sourceIdIn.value   = person.registry_id ? String(person.registry_id) : '';
                sourceNameIn.value = person.name || '';
                /* Hidden field that tells the server to auto-register
                   the source by name when source_id is missing. */
                const sourceNameHidden = document.getElementById('mus-merge-source-name-hidden');
                if (sourceNameHidden) sourceNameHidden.value = person.registry_id ? '' : (person.name || '');

                /* Reset the irreversibility ack on every open so a prior
                   ticked state doesn't carry across to a fresh merge. */
                const confirmCb = document.getElementById('mus-merge-confirm');
                if (confirmCb) confirmCb.checked = false;

                /* Per-role re-point preview (#583). The byName aggregate
                   already carries every count we need; just display them.
                   #1785 C8 — the "Artist" pill joins the other five (see
                   the credit-preview markup's own doc-comment above). */
                const previewWrap = document.getElementById('mus-merge-credit-preview');
                if (previewWrap) {
                    document.getElementById('mus-merge-count-writer').textContent     = person.writers     || 0;
                    document.getElementById('mus-merge-count-composer').textContent   = person.composers   || 0;
                    document.getElementById('mus-merge-count-arranger').textContent   = person.arrangers   || 0;
                    document.getElementById('mus-merge-count-adaptor').textContent    = person.adaptors    || 0;
                    document.getElementById('mus-merge-count-translator').textContent = person.translators || 0;
                    document.getElementById('mus-merge-count-artist').textContent     = person.artists     || 0;
                    document.getElementById('mus-merge-count-total').textContent      = person.total       || 0;
                    previewWrap.classList.remove('d-none');
                }

                /* #1500 — reset the target typeahead for this merge and
                   remember who to exclude from its own suggestion list.
                   currentExclude.id = 0 for an in-use-only source (there's
                   no registry row to exclude by id yet) — the server-side
                   `u.Name <> ?` / registry-row Name comparison is what
                   actually keeps a not-yet-registered source out of its
                   own target list in that case. */
                currentExclude.id   = person.registry_id || 0;
                currentExclude.name = person.name || '';
                targetTxt.value      = '';
                targetKeyIn.value    = '';
                targetDatalist.innerHTML = '';
                labelToKey = new Map();
                labelToCandidate = new Map();
                renderDisambig(null); // #1785 C8 — clear any stale comparison from a prior merge open

                /* Source children — render each link + IPI + ISNI as a
                   checkbox row (default checked = keep on target). IPI
                   and ISNI both POST under keep_ipi_ids[] since they're
                   now the same `tblMusicianIdentifiers` table; the
                   wire-protocol field name is kept for back-compat. */
                linksBox.innerHTML = '';
                ipiBox.innerHTML   = '';
                if (isniBoxMerge) isniBoxMerge.innerHTML = '';
                const links = person.links || [];
                const ipi   = person.ipi   || [];
                const isni  = person.isni  || [];
                if (links.length === 0 && ipi.length === 0 && isni.length === 0) {
                    childrenEmpty.classList.remove('d-none');
                } else {
                    childrenEmpty.classList.add('d-none');
                    if (links.length) {
                        const head = document.createElement('div');
                        head.className = 'small text-secondary mb-1';
                        head.textContent = 'External links (' + links.length + ')';
                        linksBox.appendChild(head);
                        links.forEach(l => {
                            const wrap = document.createElement('div');
                            wrap.className = 'form-check small';
                            wrap.innerHTML = '<input class="form-check-input" type="checkbox" name="keep_link_ids[]" value="' + l.id + '" id="mus-merge-link-' + l.id + '" checked>'
                                           + '<label class="form-check-label" for="mus-merge-link-' + l.id + '">'
                                           + '<code class="me-1">' + musEsc(l.type || 'other') + '</code>'
                                           + (l.label ? musEsc(l.label) + ' — ' : '')
                                           + '<a href="#" class="text-info text-decoration-none mus-merge-link-href"></a>'
                                           + '</label>';
                            const a = wrap.querySelector('.mus-merge-link-href');
                            a.textContent = l.url;
                            a.setAttribute('href', l.url);
                            a.setAttribute('target', '_blank');
                            a.setAttribute('rel', 'noopener');
                            linksBox.appendChild(wrap);
                        });
                    }
                    if (ipi.length) {
                        const head = document.createElement('div');
                        head.className = 'small text-secondary mb-1 mt-2';
                        head.textContent = 'IPI Name Numbers (' + ipi.length + ')';
                        ipiBox.appendChild(head);
                        ipi.forEach(r => {
                            const wrap = document.createElement('div');
                            wrap.className = 'form-check small';
                            wrap.innerHTML = '<input class="form-check-input" type="checkbox" name="keep_ipi_ids[]" value="' + r.id + '" id="mus-merge-ipi-' + r.id + '" checked>'
                                           + '<label class="form-check-label" for="mus-merge-ipi-' + r.id + '">'
                                           + '<code class="me-1">' + musEsc(r.number) + '</code>'
                                           + (r.name_used ? '(as ' + musEsc(r.name_used) + ') ' : '')
                                           + (r.notes ? '— ' + musEsc(r.notes) : '')
                                           + '</label>';
                            ipiBox.appendChild(wrap);
                        });
                    }
                    if (isni.length && isniBoxMerge) {
                        const head = document.createElement('div');
                        head.className = 'small text-secondary mb-1 mt-2';
                        head.textContent = 'ISNI (' + isni.length + ')';
                        isniBoxMerge.appendChild(head);
                        isni.forEach(r => {
                            const wrap = document.createElement('div');
                            wrap.className = 'form-check small';
                            wrap.innerHTML = '<input class="form-check-input" type="checkbox" name="keep_ipi_ids[]" value="' + r.id + '" id="mus-merge-isni-' + r.id + '" checked>'
                                           + '<label class="form-check-label" for="mus-merge-isni-' + r.id + '">'
                                           + '<code class="me-1">' + musEsc(r.number) + '</code>'
                                           + (r.name_used ? '(as ' + musEsc(r.name_used) + ') ' : '')
                                           + (r.notes ? '— ' + musEsc(r.notes) : '')
                                           + '</label>';
                            isniBoxMerge.appendChild(wrap);
                        });
                    }
                }
                childrenWrap.classList.remove('d-none');
                submitBtn.disabled = true; /* enabled once a target is picked */
                modal.show();
            });

            /* Submit button enables only when BOTH a target is RESOLVED
               (targetKeyIn carries a real "id:N"/"name:X" key — i.e. the
               curator picked or exactly matched a suggestion, not just
               typed something) AND the irreversibility ack is ticked
               (#583). #1500 — targetKeyIn.value is set inline by the
               `input` listener above (setting .value programmatically
               doesn't fire 'input'/'change', so this is called directly
               there rather than via a listener on the hidden field). */
            const confirmCb = document.getElementById('mus-merge-confirm');
            function refreshSubmitState() {
                const targetPicked = targetKeyIn?.value !== '';
                const acknowledged = !!confirmCb?.checked;
                submitBtn.disabled = !(targetPicked && acknowledged);
            }
            confirmCb?.addEventListener('change', refreshSubmitState);

            /* On submit, translate the resolved key into the correct
               hidden field (#626 contract, unchanged by #1500). The key
               carries "id:N" for registry rows and "name:X" for in-use-
               only rows; the server handler accepts either. */
            modalEl.querySelector('form')?.addEventListener('submit', () => {
                const picked = targetKeyIn?.value || '';
                const idHidden   = document.getElementById('mus-merge-target-id-hidden');
                const nameHidden = document.getElementById('mus-merge-target-name-hidden');
                if (idHidden)   idHidden.value   = '';
                if (nameHidden) nameHidden.value = '';
                if (picked.startsWith('id:')) {
                    if (idHidden) idHidden.value = picked.slice(3);
                } else if (picked.startsWith('name:')) {
                    if (nameHidden) nameHidden.value = picked.slice(5);
                }
            });
        })();

        /* =========================================================================
           Delete-from-registry modal — read the row's role-pill counts
           to decide whether to show the force-anyway path.
           ========================================================================= */
        (function () {
            const modalEl = document.getElementById('musDeleteModal');
            if (!modalEl) return;
            const modal      = bootstrap.Modal.getOrCreateInstance(modalEl);
            const idIn       = document.getElementById('mus-delete-id');
            const nameEl     = document.getElementById('mus-delete-name');
            const inUseEl    = document.getElementById('mus-delete-in-use');
            const notInUseEl = document.getElementById('mus-delete-not-in-use');
            const usageCnt   = document.getElementById('mus-delete-usage-count');
            const forceCb    = document.getElementById('mus-delete-force');
            const submitBtn  = document.getElementById('mus-delete-submit');

            document.addEventListener('click', (ev) => {
                const btn = ev.target.closest('.mus-delete-btn');
                if (!btn) return;
                const row = btn.closest('tr');
                if (!row) return;
                const raw = row.getAttribute('data-person');
                if (!raw) return;
                let person; try { person = JSON.parse(raw); } catch (_) { return; }
                if (!person.registry_id) return;

                /* Read total usage from the row's "Total uses" cell. */
                const totalCell = row.querySelector('td.text-end strong');
                const usage = parseInt((totalCell?.textContent || '0').replace(/[,]/g, ''), 10) || 0;

                idIn.value      = String(person.registry_id);
                nameEl.textContent = person.name || '';
                forceCb.checked = false;

                if (usage > 0) {
                    inUseEl.classList.remove('d-none');
                    notInUseEl.classList.add('d-none');
                    usageCnt.textContent = usage.toLocaleString();
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="bi bi-trash me-1"></i>Force remove';
                } else {
                    inUseEl.classList.add('d-none');
                    notInUseEl.classList.remove('d-none');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="bi bi-trash me-1"></i>Remove';
                }
                modal.show();
            });

            forceCb?.addEventListener('change', () => {
                submitBtn.disabled = !forceCb.checked;
            });
        })();

        /* =========================================================================
           View Songs modal — fetches GET ?action=view_songs&id=<id>
           on open and renders the per-role groups.
           ========================================================================= */
        (function () {
            const modalEl = document.getElementById('musViewSongsModal');
            if (!modalEl) return;
            const modal     = bootstrap.Modal.getOrCreateInstance(modalEl);
            const nameEl    = document.getElementById('mus-view-songs-name');
            const loadingEl = document.getElementById('mus-view-songs-loading');
            const errorEl   = document.getElementById('mus-view-songs-error');
            const bodyEl    = document.getElementById('mus-view-songs-body');
            const emptyEl   = document.getElementById('mus-view-songs-empty');

            const ROLE_LABELS = {
                writer:     'Writers',
                composer:   'Composers',
                arranger:   'Arrangers',
                adaptor:    'Adaptors',
                translator: 'Translators',
                artist:     'Artists', // #1785 C5
            };

            function renderRoleGroup(role, songs) {
                if (!songs.length) return '';
                const items = songs.map(s => {
                    const ref = (s.SongbookAbbr || '?') + (s.Number ? '-' + s.Number : '');
                    const title = s.Title || '(untitled)';
                    return '<li class="small">'
                        + '<code class="me-2">' + ref + '</code>'
                        + title
                        + '</li>';
                }).join('');
                return '<div class="mb-3">'
                    + '<h6 class="small text-secondary mb-1">'
                    +   ROLE_LABELS[role] + ' <span class="badge bg-secondary-subtle text-secondary-emphasis ms-1">' + songs.length + '</span>'
                    + '</h6>'
                    + '<ul class="list-unstyled mb-0">' + items + '</ul>'
                    + '</div>';
            }

            document.addEventListener('click', (ev) => {
                const btn = ev.target.closest('.mus-view-songs-btn');
                if (!btn) return;
                const row = btn.closest('tr');
                if (!row) return;
                const raw = row.getAttribute('data-person');
                if (!raw) return;
                let person; try { person = JSON.parse(raw); } catch (_) { return; }
                if (!person.registry_id) return;

                nameEl.textContent = person.name || '';
                bodyEl.innerHTML = '';
                errorEl.classList.add('d-none');
                emptyEl.classList.add('d-none');
                loadingEl.classList.remove('d-none');
                modal.show();

                fetch('/manage/musicians?action=view_songs&id=' + encodeURIComponent(person.registry_id), {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                })
                    .then(r => r.json().then(j => ({ ok: r.ok, j })))
                    .then(({ ok, j }) => {
                        loadingEl.classList.add('d-none');
                        if (!ok || j.error) {
                            errorEl.textContent = j.error || ('HTTP error');
                            errorEl.classList.remove('d-none');
                            return;
                        }
                        if (!j.total) {
                            emptyEl.classList.remove('d-none');
                            return;
                        }
                        // #1785 C5 — 'artist' added (server's by_role now carries it too).
                        const html = ['writer', 'composer', 'arranger', 'adaptor', 'translator', 'artist']
                            .map(role => renderRoleGroup(role, j.by_role[role] || []))
                            .join('');
                        bodyEl.innerHTML = html;
                    })
                    .catch(err => {
                        loadingEl.classList.add('d-none');
                        errorEl.textContent = String(err.message || err);
                        errorEl.classList.remove('d-none');
                    });
            });
        })();
    </script>

</body>
</html>
