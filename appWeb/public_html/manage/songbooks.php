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
$db      = getDbMysqli();

/* ---- GET ?action=script_search&q=… (#681) ------------------------------
 * JSON typeahead for the IETF BCP 47 picker's Script field. Matches
 * substring (LIKE %q%) against tblScripts.Name OR tblScripts.Code so
 * a curator can search either by friendly name ("Latin") or by ISO
 * 15924 code ("Latn"). Empty query → empty list; pre-migration
 * deployments → empty list with a `note` rather than a 500.
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

    $hasTable = false;
    try {
        $probe = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblScripts' LIMIT 1"
        );
        $probe->execute();
        $hasTable = $probe->get_result()->fetch_row() !== null;
        $probe->close();
    } catch (\Throwable $e) {
        error_log('[script_search] probe failed: ' . $e->getMessage());
    }
    if (!$hasTable) {
        echo json_encode([
            'suggestions' => [],
            'note'        => 'tblScripts not yet created — run /manage/setup-database',
        ]);
        exit;
    }

    try {
        $like = '%' . $q . '%';
        $stmt = $db->prepare(
            'SELECT Code AS code, Name AS name, NativeName AS nativeName
               FROM tblScripts
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

/**
 * INSERT IGNORE the supplied affiliation name into the registry so
 * the typeahead surfaces it on the next save (#670). Silent no-op
 * when the table doesn't exist (pre-migration deployments) so the
 * songbook save path can't be broken by a half-applied schema. The
 * tblCreditPeople sync in the editor's save_song path uses the same
 * "best-effort registry sync" pattern (#545).
 *
 * @param ?string $name Affiliation name as typed; null/empty = no-op.
 */
$registerAffiliation = function (?string $name) use ($db): void {
    if ($name === null) return;
    $trimmed = trim($name);
    if ($trimmed === '') return;
    /* Cap to the column width so a crafted form post can't crash
       the INSERT. mb_substr is unicode-safe for languages whose
       glyphs are multi-byte (e.g. denomination names in Cyrillic). */
    $trimmed = mb_substr($trimmed, 0, 120);
    try {
        $stmt = $db->prepare('INSERT IGNORE INTO tblSongbookAffiliations (Name) VALUES (?)');
        $stmt->bind_param('s', $trimmed);
        $stmt->execute();
        $stmt->close();
    } catch (\Throwable $e) {
        /* Most likely cause: the migration hasn't been run yet so the
           table doesn't exist. The save itself is unaffected — this is
           best-effort registry sync. */
        error_log('[songbooks] registry sync skipped: ' . $e->getMessage());
    }
};

/* Helpers */
$validateAbbr = function (string $abbr): ?string {
    $abbr = trim($abbr);
    if ($abbr === '') return 'Abbreviation is required.';
    if (strlen($abbr) > 10) return 'Abbreviation must be 10 characters or fewer.';
    if (!preg_match('/^[A-Za-z0-9]+$/', $abbr)) return 'Abbreviation must be letters/numbers only (no spaces or punctuation).';
    return null;
};
$validateColour = function (string $c): ?string {
    if ($c === '') return null;
    return preg_match('/^#[0-9A-Fa-f]{6}$/', $c) ? null : 'Colour must be a #RRGGBB hex value (or blank).';
};

/**
 * Validate an IETF BCP 47 language tag (#681). Empty is fine
 * (NULL = "not specified" for songbooks). Otherwise must match
 * the v1 grammar: lowercase 2-3 letter language, optional 4-letter
 * Title Case script, optional 2-letter UPPER region or 3-digit
 * numeric area code. Variants / extensions / private-use are out
 * of scope for v1 per the issue brief.
 *
 * Returns null if valid (empty or matching), an error message
 * string otherwise. Caller is responsible for calling mb_substr
 * to cap to the column width regardless — the regex doesn't bound
 * length on its own.
 */
$validateBcp47 = function (string $tag): ?string {
    if ($tag === '') return null;
    if (strlen($tag) > 35) {
        return 'Language tag must be 35 characters or fewer.';
    }
    /* The full grammar of BCP 47 is much richer; this regex covers
       the in-scope subset (#681). Anything beyond is rejected so a
       tampered POST can't smuggle private-use subtags into a column
       the rest of the system assumes is well-formed. */
    if (!preg_match('/^[a-z]{2,3}(-[A-Z][a-z]{3})?(-[A-Z]{2}|-[0-9]{3})?$/', $tag)) {
        return 'Language tag must be a valid IETF BCP 47 form (e.g. en, pt-BR, zh-Hans-CN).';
    }
    return null;
};

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

                /* Fetch the full before-row so the audit log carries
                   a complete diff of which fields actually changed
                   (#535) — otherwise the timeline reader has to
                   guess. SELECT extended for #672 metadata so the
                   diff covers the new identifier columns too. */
                $existing = $db->prepare(
                    'SELECT Abbreviation, Name, DisplayOrder, Colour, IsOfficial,
                            Publisher, PublicationYear, Copyright, Affiliation,
                            Language,
                            WebsiteUrl, InternetArchiveUrl, WikipediaUrl, WikidataId,
                            OclcNumber, OcnNumber, LcpNumber, Isbn, ArkId, IsniId,
                            ViafId, Lccn, LcClass
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
                    $changed = [];
                    foreach ($afterRow as $k => $v) {
                        if (!array_key_exists($k, $beforeRow ?? [])) continue;
                        if ((string)$beforeRow[$k] !== (string)$v) $changed[] = $k;
                    }
                    logActivity('songbook.edit', 'songbook', (string)$id, [
                        'fields'             => $changed,
                        'before'             => array_intersect_key($beforeRow, array_flip($changed)),
                        'after'              => array_intersect_key($afterRow,  array_flip($changed)),
                        'songs_renamed_too'  => $alsoRename && $abbrChanged,
                    ]);
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
                    $error = "Cannot delete '{$abbr}': {$songCount} song(s) still reference it. Reassign them first.";
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

    $stmt = $db->prepare(
        'SELECT b.Id, b.Abbreviation, b.Name, b.SongCount, b.DisplayOrder, b.Colour,
                b.IsOfficial, b.Publisher, b.PublicationYear,
                b.Copyright, b.Affiliation' . $langSelect . $bibSelect . ',
                COUNT(s.Id) AS ActualSongCount
           FROM tblSongbooks b
           LEFT JOIN tblSongs s ON s.SongbookAbbr = b.Abbreviation
          GROUP BY b.Id
          ORDER BY b.DisplayOrder ASC, b.Name ASC'
    );
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (\Throwable $e) {
    error_log('[manage/songbooks.php] ' . $e->getMessage());
    $error = $error ?: 'Could not load songbooks — check server logs for details.';
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

            <table class="table table-sm mb-2 align-middle cp-sortable" id="songbook-list-table">
                <thead>
                    <tr class="text-muted small">
                        <th style="width:1.5rem" aria-label="Drag to reorder"></th>
                        <th style="width:6rem">Order</th>
                        <th data-sort-key="abbr" data-sort-type="text">Abbr</th>
                        <th data-sort-key="name" data-sort-type="text">Name</th>
                        <th class="text-center" data-sort-key="official" data-sort-type="text" title="Official published hymnal (#502)">Official</th>
                        <th class="text-center" data-sort-key="songs" data-sort-type="number">Songs</th>
                        <th data-sort-key="colour" data-sort-type="text">Colour</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr class="songbook-row"
                            data-row-id="<?= (int)$r['Id'] ?>"
                            data-sort-name="<?= htmlspecialchars($r['Name']) ?>"
                            data-sort-abbr="<?= htmlspecialchars($r['Abbreviation']) ?>">
                            <td class="text-center align-middle">
                                <span class="songbook-drag-handle" title="Drag to reorder" aria-hidden="true">
                                    <i class="bi bi-grip-vertical"></i>
                                </span>
                            </td>
                            <td>
                                <input type="number" min="0"
                                       class="form-control form-control-sm"
                                       name="display_order[<?= (int)$r['Id'] ?>]"
                                       value="<?= (int)$r['DisplayOrder'] ?>">
                            </td>
                            <td><code><?= htmlspecialchars($r['Abbreviation']) ?></code></td>
                            <td><?= htmlspecialchars($r['Name']) ?></td>
                            <td class="text-center">
                                <?php if ((int)$r['IsOfficial'] === 1): ?>
                                    <span class="badge bg-info" title="Official published hymnal">
                                        <i class="bi bi-patch-check-fill" aria-hidden="true"></i> Yes
                                    </span>
                                <?php else: ?>
                                    <small class="text-muted" title="Curated grouping / pseudo-songbook">—</small>
                                <?php endif; ?>
                            </td>
                            <td class="text-center"><?= number_format((int)$r['ActualSongCount']) ?></td>
                            <td>
                                <?php if ($r['Colour']): ?>
                                    <span class="d-inline-block me-1" style="width:1rem;height:1rem;border-radius:50%;background:<?= htmlspecialchars($r['Colour']) ?>"></span>
                                    <small class="text-muted"><?= htmlspecialchars($r['Colour']) ?></small>
                                <?php else: ?>
                                    <small class="text-muted">—</small>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-info"
                                        onclick='openEditModal(<?= json_encode([
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
                                        ]) ?>)'
                                        title="Edit songbook">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <?php if ((int)$r['ActualSongCount'] === 0): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        onclick='openDeleteModal(<?= json_encode(['id' => (int)$r['Id'], 'abbreviation' => $r['Abbreviation']]) ?>)'
                                        title="Delete songbook (no songs reference it)">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php else: ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary" disabled
                                        title="<?= (int)$r['ActualSongCount'] ?> song(s) still reference this abbreviation — reassign them first">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr><td colspan="8" class="text-muted text-center py-4">No songbooks yet. Add one below.</td></tr>
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
                                <label class="form-label">Colour (hex)</label>
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
            new bootstrap.Modal(document.getElementById('editModal')).show();
        }
        function openDeleteModal(row) {
            document.getElementById('delete-id').value = row.id;
            document.getElementById('delete-abbr-label').textContent = row.abbreviation;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }
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

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
