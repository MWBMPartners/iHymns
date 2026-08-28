<?php

declare(strict_types=1);

/**
 * iHymns — Tag/theme admin CRUD shared cores (#770/#1152/#1222, API-coverage batch 4b-i A3)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `/manage/tags` (the web admin page) and the new `admin_tag_*` native API
 * actions both need to do the SAME things: turn a typed name into a clean
 * Title-Cased name + slug, save a tag, count how many songs use it, delete
 * one, fold a duplicate into another (merge), and suggest curator tags that
 * are probably typos of a standard CCLI/OpenLyrics theme. This file is the
 * ONE place each of those is written — both surfaces call these functions
 * instead of re-typing their own copy (rule #22/#35). Modelled on
 * includes/publisher_admin.php (#93) and includes/tune_admin.php (#1748).
 *
 * DETAILED
 * ----------------------------------------------------------------------------
 * The fuzzy-match SCORING maths itself is NOT re-implemented here — it stays
 * the shared `includes/song_similarity.php` (`ihymns_sim_normalise()` /
 * `ihymns_sim_text()`, #1216/#1222); this file only wires that scorer into
 * the tag-canonicalisation READ (`tagAdminCanonicalSuggestions()`) the same
 * way `/manage/tags` already did inline, and never re-forks the normalise /
 * levenshtein / Jaccard / blend logic (rule #22's own red-flag list).
 *
 * Every function is pure-PHP-plus-\mysqli (no superglobal reads) so a
 * form-POST caller (`manage/tags.php`) and a JSON-body caller
 * (`api.php`'s `admin_tag_*` actions) normalise into the same array shape
 * first, then call the SAME function.
 *
 * Two write-shape asymmetries preserved deliberately (byte-identical to the
 * pre-extraction `manage/tags.php`, not "fixed" here — this is a lift, not a
 * rewrite): `tagAdminDelete()` does NOT itself decide the 409-in-use
 * conflict (the caller calls `tagAdminUsageCount()` first and decides); a
 * merge's "not found" 404 is decided by the caller via
 * `tagAdminFetchNamesByIds()` BEFORE the transaction starts (mirrors
 * `publisherAdminMerge()`'s own "load both, throw if missing" shape being
 * kept OUTSIDE this file's merge writer for the same reason — a 404 must
 * never surface as the generic 500 an exception thrown mid-transaction
 * would produce).
 *
 * @link appWeb/public_html/includes/publisher_admin.php   the extraction precedent this mirrors
 * @link appWeb/public_html/includes/song_similarity.php   the ONE fuzzy scorer (#1216) — never re-forked
 * @link appWeb/public_html/manage/tags.php                page consumer
 * @link appWeb/public_html/api.php                        admin_tag_* API consumer
 * @see #770 #1152 #1222
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_similarity.php';

/**
 * Normalise a typed tag name: trim, collapse internal whitespace, Title
 * Case, 50-char cap (mirrors tblSongTags.Name's column width). Same shape
 * as bulk_tag's own normaliser in the editor (#762) — a tag typed here and
 * a tag typed in the editor's inline "add a tag" box fold to the same
 * string.
 */
function tagAdminNormaliseName(string $name): string
{
    $clean = trim($name);
    $clean = preg_replace('/\s+/u', ' ', (string)$clean);
    $titled = mb_convert_case((string)$clean, MB_CASE_TITLE_SIMPLE, 'UTF-8');
    return mb_substr((string)$titled, 0, 50);
}

/**
 * Derive a URL-safe lowercase slug from a (normalised) tag name. Non-alnum
 * runs collapse to one hyphen; leading/trailing hyphens are stripped.
 */
function tagAdminSlugify(string $name): string
{
    $slug = strtolower((string)preg_replace('/[^a-z0-9]+/i', '-', $name));
    return trim($slug, '-');
}

/**
 * True once the #1152 standard-vocabulary columns (Source / ParentId /
 * CcliThemeId — added by migrate-seed-theme-vocabulary.php) exist. Both the
 * page's list rendering AND the canonicalisation-suggestions read gate on
 * this so a long-running pre-migration install degrades gracefully instead
 * of throwing under mysqli STRICT (rule #9/#19).
 */
function tagAdminThemeColumnsReady(\mysqli $db): bool
{
    $probe = $db->query("SHOW COLUMNS FROM tblSongTags LIKE 'Source'");
    $ready = $probe ? $probe->num_rows > 0 : false;
    if ($probe) { $probe->close(); }
    return $ready;
}

/**
 * Validate + normalise a create/update's name + description in one step.
 * Never touches the DB. Returns the SAME error text `create`/`merge`'s
 * pre-extraction inline checks used, so a caller composing its OWN
 * id-required message (update's combined "id and name are required.") can
 * still reuse this for the shared name/slug half — see manage/tags.php's
 * re-pointed `update` case for the exact composition.
 *
 * @return array{0: array{name:string,slug:string,description:string}, 1: ?string} [$fields, $error]
 */
function tagAdminValidateFields(string $rawName, string $rawDescription): array
{
    $name        = tagAdminNormaliseName($rawName);
    $description = trim($rawDescription);
    if ($name === '') {
        return [[], 'Name is required.'];
    }
    $slug = tagAdminSlugify($name);
    if ($slug === '') {
        return [[], 'Name has no usable slug characters.'];
    }
    return [['name' => $name, 'slug' => $slug, 'description' => $description], null];
}

/**
 * One tag row by id (Name/Slug/Description) — used for update's before-diff
 * capture and existence check.
 *
 * @return array{Name:string,Slug:string,Description:string}|null
 */
function tagAdminFetch(\mysqli $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT Name, Slug, Description FROM tblSongTags WHERE Id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Insert a new tag row. `ON DUPLICATE KEY UPDATE Id = LAST_INSERT_ID(Id)`
 * makes a same-Name re-submit idempotent (returns the EXISTING row's id
 * rather than throwing on the unique index) — the exact upsert shape
 * `manage/tags.php`'s `create` action always used.
 *
 * @return int the tag's id (new or pre-existing on a Name collision).
 */
function tagAdminCreate(\mysqli $db, string $name, string $slug, string $description): int
{
    $stmt = $db->prepare(
        'INSERT INTO tblSongTags (Name, Slug, Description) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE Id = LAST_INSERT_ID(Id), Name = VALUES(Name)'
    );
    $stmt->bind_param('sss', $name, $slug, $description);
    $stmt->execute();
    $newId = (int)$db->insert_id;
    $stmt->close();
    return $newId;
}

/** Write an existing tag row's Name/Slug/Description. Caller has already
 * confirmed the row exists (via tagAdminFetch()). */
function tagAdminUpdate(\mysqli $db, int $id, string $name, string $slug, string $description): void
{
    $stmt = $db->prepare('UPDATE tblSongTags SET Name = ?, Slug = ?, Description = ? WHERE Id = ?');
    $stmt->bind_param('sssi', $name, $slug, $description, $id);
    $stmt->execute();
    $stmt->close();
}

/** How many songs currently carry this tag — the delete confirmation count
 * AND the 409-requires-force gate (decided by the CALLER, not this file). */
function tagAdminUsageCount(\mysqli $db, int $id): int
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM tblSongTagMap WHERE TagId = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
    $stmt->close();
    return $count;
}

/**
 * Delete a tag: unmap every song first (explicit, even though the FK would
 * CASCADE, so the audit row's mapping count is accurate — same rationale as
 * the pre-extraction inline comment), then delete the tag row itself.
 * Caller wraps this in a transaction and has already resolved the
 * force-required 409 via tagAdminUsageCount().
 *
 * @return array{unmapped:int,deleted:int} deleted=0 means "no such tag" (404).
 */
function tagAdminDelete(\mysqli $db, int $id): array
{
    $stmt = $db->prepare('DELETE FROM tblSongTagMap WHERE TagId = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $unmapped = $stmt->affected_rows;
    $stmt->close();

    $stmt = $db->prepare('DELETE FROM tblSongTags WHERE Id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $deleted = $stmt->affected_rows;
    $stmt->close();

    return ['unmapped' => $unmapped, 'deleted' => $deleted];
}

/**
 * Name lookup for a merge's pre-flight existence check — deliberately
 * SEPARATE from `tagAdminMerge()` itself so a missing id can surface as a
 * clean 404 BEFORE any transaction opens (see this file's doc-block).
 *
 * @param list<int> $ids
 * @return array<int,string> id => Name, only for ids that actually exist.
 */
function tagAdminFetchNamesByIds(\mysqli $db, array $ids): array
{
    if (!$ids) { return []; }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT Id, Name FROM tblSongTags WHERE Id IN ($ph)");
    $types = str_repeat('i', count($ids));
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $byId = [];
    foreach ($rows as $r) { $byId[(int)$r['Id']] = (string)$r['Name']; }
    return $byId;
}

/**
 * Collapse $sourceId into $targetId. Caller MUST have already verified both
 * ids exist (tagAdminFetchNamesByIds()) and MUST wrap this in a
 * transaction (mirrors publisherAdminMerge()'s own contract).
 *
 * Conflict pre-flight DELETE first: any (SongId, sourceId) row whose SongId
 * is ALREADY mapped to targetId would collide on tblSongTagMap's unique key
 * when the UPDATE below repoints sourceId -> targetId, so those rows are
 * removed first — the union is preserved (the SongId already carries
 * targetId, which is what "merged" means). Then the remaining rows are
 * repointed, then the now-empty source tag is deleted.
 *
 * @return array{repointed:int,conflicts:int}
 */
function tagAdminMerge(\mysqli $db, int $sourceId, int $targetId): array
{
    $stmt = $db->prepare(
        'DELETE m1 FROM tblSongTagMap m1
         JOIN tblSongTagMap m2 ON m1.SongId = m2.SongId
         WHERE m1.TagId = ? AND m2.TagId = ?'
    );
    $stmt->bind_param('ii', $sourceId, $targetId);
    $stmt->execute();
    $conflicts = $stmt->affected_rows;
    $stmt->close();

    $stmt = $db->prepare('UPDATE tblSongTagMap SET TagId = ? WHERE TagId = ?');
    $stmt->bind_param('ii', $targetId, $sourceId);
    $stmt->execute();
    $repointed = $stmt->affected_rows;
    $stmt->close();

    $stmt = $db->prepare('DELETE FROM tblSongTags WHERE Id = ?');
    $stmt->bind_param('i', $sourceId);
    $stmt->execute();
    $stmt->close();

    return ['repointed' => $repointed, 'conflicts' => $conflicts];
}

/**
 * Canonicalisation suggestions (#1222): curator-added tags whose spelling
 * closely matches a seeded standard CCLI/OpenLyrics theme, via the shared
 * `includes/song_similarity.php` scorer — never a re-forked fuzzy match.
 * Curator-confirmed via `tagAdminMerge()` (the existing Merge) — this
 * function only SUGGESTS, it never writes. Returns `[]` on an un-migrated
 * install (tagAdminThemeColumnsReady() gate) rather than throwing.
 *
 * Candidate pool is capped at 300 (most-used first) and results at 50,
 * scored 0.84+ — the exact bounds `/manage/tags` always used, so the
 * suggestion list a curator sees is identical page vs. API.
 *
 * @return list<array{curId:int,curName:string,uses:int,stdId:int,stdName:string,score:float}>
 */
function tagAdminCanonicalSuggestions(\mysqli $db): array
{
    if (!tagAdminThemeColumnsReady($db)) { return []; }

    $std = [];
    $sr = $db->query("SELECT Id, Name FROM tblSongTags WHERE Source = 'ccli-openlyrics'");
    while ($sr && ($row = $sr->fetch_assoc())) {
        $std[] = ['id' => (int)$row['Id'], 'name' => (string)$row['Name'], 'norm' => ihymns_sim_normalise((string)$row['Name'])];
    }
    if ($sr) { $sr->close(); }
    if (!$std) { return []; }

    $suggestions = [];
    /* Curator tags, most-used first; bounded so the pairwise pass stays cheap. */
    $cr = $db->query(
        "SELECT t.Id, t.Name, COUNT(m.TagId) AS UseCount
           FROM tblSongTags t
           LEFT JOIN tblSongTagMap m ON m.TagId = t.Id
          WHERE t.Source <> 'ccli-openlyrics'
          GROUP BY t.Id
          ORDER BY UseCount DESC, t.Name ASC
          LIMIT 300"
    );
    while ($cr && ($row = $cr->fetch_assoc())) {
        $cn = ihymns_sim_normalise((string)$row['Name']);
        if ($cn === '') { continue; }
        $best = null; $bestScore = 0.0;
        foreach ($std as $s) {
            if ($s['norm'] === '') { continue; }
            $score = ($cn === $s['norm']) ? 1.0 : ihymns_sim_text($cn, $s['norm']);
            if ($score > $bestScore) { $bestScore = $score; $best = $s; }
        }
        /* >= 0.84 catches close spellings/typos; the strcasecmp guard is a
           belt-and-braces no-op (the unique Name index already prevents a
           curator row from ci-colliding with a standard one). */
        if ($best && $bestScore >= 0.84 && strcasecmp((string)$row['Name'], $best['name']) !== 0) {
            $suggestions[] = [
                'curId'   => (int)$row['Id'],
                'curName' => (string)$row['Name'],
                'uses'    => (int)$row['UseCount'],
                'stdId'   => $best['id'],
                'stdName' => $best['name'],
                'score'   => $bestScore,
            ];
        }
    }
    if ($cr) { $cr->close(); }
    usort($suggestions, static fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($suggestions, 0, 50);
}
