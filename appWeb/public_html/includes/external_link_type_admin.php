<?php

declare(strict_types=1);

/**
 * iHymns — External-link type + URL-pattern admin write core (#845/#1748 §5.2,
 * API-coverage batch 4b-ii A7, #1992 curator-mintable types)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `/manage/external-link-types` (the web admin page, both its manual "Add
 * provider" form AND its guided wizard) and the `admin_external_link_type_*`
 * native API actions all need to do the SAME things: create a brand-new
 * link type, or flip an EXISTING one's active flag / which kinds of thing
 * it applies to / its whole list of URL-matching patterns. This file is
 * the ONE place those writes are done — every surface calls these
 * functions instead of re-typing their own copy (rule #22/#35). Modelled
 * on includes/tag_admin.php (#1969).
 *
 * SCOPE (#1992 — supersedes the pre-#1992 "no create" stance below)
 * ----------------------------------------------------------------------------
 * Before #1992 this file's scope note said the page had exactly ONE write
 * action — `save_type_patterns`, editing an EXISTING type only — because
 * the registry's *types* were curated content shipped with the app
 * (migration seeds), never curator-minted. Owner decision A (#1992)
 * confirmed the opposite: curators MAY mint new types. Three funnels now
 * exist, all delegating to the SAME core (rule #22):
 *   - a plain manual "Add provider" form (`create_type`, classic POST)
 *   - a guided wizard (`wizard_create_type`, fetch()+X-Requested-With JSON)
 *   - the `admin_external_link_type_create` API twin (Bearer JSON)
 * `save_type_patterns` / `externalLinkTypeAdminSave()` (edit an EXISTING
 * type) is UNCHANGED — this file just gained a second write path
 * alongside it, not a replacement.
 *
 * @link appWeb/public_html/includes/tag_admin.php            the extraction precedent this mirrors
 * @link appWeb/public_html/includes/external_link_helpers.php IHYMNS_LINK_ENTITY_TYPES / IHYMNS_LINK_TYPE_CATEGORIES — the ONE allow-lists
 * @link appWeb/public_html/manage/external-link-types.php     page consumer (edit forms + manual create + guided wizard)
 * @link appWeb/public_html/api.php                            admin_external_link_type_save / _create API consumers
 * @see #845 #1748 #1992 rule #11 rule #12 rule #22
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'external_link_helpers.php'; /* IHYMNS_LINK_ENTITY_TYPES, IHYMNS_LINK_TYPE_CATEGORIES */

/**
 * Security audit F2 — hard cap on posted pattern-row arrays shared by every
 * caller of externalLinkTypeAdminNormalisePatterns() below (the manual
 * "Add provider" form, the guided wizard's create/save branches, and both
 * admin_external_link_type_save/_create API twins). A curator's real
 * pattern list per provider is a handful of host/path rules; the classic
 * form funnels are naturally bounded by PHP's max_input_vars (~1000
 * fields), but the JSON-speaking wizard branch and the API twins are
 * bounded only by post_max_size, so an uncapped request could queue
 * 10^5+ per-row INSERTs in one transaction. Capped well above any real
 * curator's list and well below anything that could hurt the DB; each
 * caller checks this BEFORE calling the normaliser and responds 422
 * rather than silently truncating.
 */
const IHYMNS_EXTERNAL_LINK_PATTERN_ROW_CAP = 100;

/**
 * Thrown by externalLinkTypeAdminCreate() when the INSERT itself hits the
 * `uq_slug` unique-key violation (mysqli errno 1062) — a race between two
 * curators creating the same slug at once, or a caller that skipped
 * externalLinkTypeAdminValidateNewType()'s own pre-check. Callers catch
 * this specifically to respond 409 rather than a generic 500, matching
 * the pre-check's own 409 for the common (non-race) case.
 */
final class ExternalLinkTypeDuplicateSlugException extends \RuntimeException
{
}

/** True once BOTH `tblExternalLinkTypes` and `tblExternalLinkPatterns` exist
 *  — the page's own pre-migration-safe probe, extracted so the API action
 *  gates on the identical check (never a mysqli STRICT throw on a
 *  long-running un-migrated install).
 *
 * @return array{types:bool,patterns:bool}
 */
function externalLinkTypeAdminSchemaReady(\mysqli $db): array
{
    $hasTypes    = false;
    $hasPatterns = false;
    try {
        $r = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblExternalLinkTypes' LIMIT 1");
        $hasTypes = $r && $r->fetch_row() !== null;
        if ($r) { $r->close(); }
        $r = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblExternalLinkPatterns' LIMIT 1");
        $hasPatterns = $r && $r->fetch_row() !== null;
        if ($r) { $r->close(); }
    } catch (\Throwable $e) {
        error_log('[externalLinkTypeAdminSchemaReady] ' . $e->getMessage());
    }
    return ['types' => $hasTypes, 'patterns' => $hasPatterns];
}

/** One type's current `AppliesTo` CSV string, or null when no such id. Used
 *  by the caller both to check existence (404) and as the "existing" input
 *  to externalLinkTypeAdminResolveAppliesTo(). */
function externalLinkTypeAdminFetchAppliesTo(\mysqli $db, int $typeId): ?string
{
    $stmt = $db->prepare('SELECT AppliesTo FROM tblExternalLinkTypes WHERE Id = ?');
    $stmt->bind_param('i', $typeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (string)$row['AppliesTo'] : null;
}

/**
 * #1748 §5.2 — AppliesTo tick-UI resolution. Intersect the posted tokens
 * against the central allow-list (IHYMNS_LINK_ENTITY_TYPES, rule #35's
 * mechanism, not a page-local list) so a tampered request can't write an
 * arbitrary token, THEN preserve any token the row already carries that
 * ISN'T in the const — the legacy 'person' back-compat token
 * (migrate-musicians-rename.php:76-80) predates this UI and must survive an
 * edit that doesn't know about it. An empty resulting set keeps the row's
 * CURRENT value instead of writing '' — an empty AppliesTo would hide the
 * type from every editor (§5.2's explicit guard).
 *
 * Pure — no DB access. Byte-identical logic lifted from the page's
 * pre-extraction inline block.
 *
 * @param  list<string> $postedApplies Raw posted applies_to[] tokens (unfiltered).
 * @param  string       $existingAppliesTo The row's CURRENT AppliesTo CSV.
 * @return array{value:string,warning:string}
 */
function externalLinkTypeAdminResolveAppliesTo(array $postedApplies, string $existingAppliesTo): array
{
    $postedApplies = array_values(array_intersect(
        array_map('strval', $postedApplies),
        IHYMNS_LINK_ENTITY_TYPES
    ));

    $existingTokens = array_values(array_filter(array_map(
        'trim', explode(',', $existingAppliesTo)
    ), static fn(string $t): bool => $t !== ''));
    $legacyTokens = array_values(array_diff($existingTokens, IHYMNS_LINK_ENTITY_TYPES));
    $newApplies   = array_values(array_unique(array_merge($postedApplies, $legacyTokens)));

    if ($newApplies) {
        return ['value' => implode(',', $newApplies), 'warning' => ''];
    }
    /* Never write an empty AppliesTo — it would hide this type from every
       editor. Keep the row's current value and surface a warning instead of
       erroring the whole save (IsActive + patterns still apply). */
    return [
        'value'   => $existingAppliesTo,
        'warning' => ' AppliesTo was left unchanged — untick nothing without ticking something else first.',
    ];
}

/**
 * Normalise the posted parallel pattern arrays into a clean list of pattern
 * rows, applying the SAME host/path cleanup + skip rules the page's
 * pre-extraction inline loop used verbatim (protocol/wildcard/trailing-
 * slash stripping, 255-char caps, "no dot in host" skip).
 *
 * Pure — no DB access.
 *
 * @param array<int,mixed> $hosts
 * @param array<int,mixed> $paths
 * @param array<int,mixed> $subdomains
 * @param array<int,mixed> $priorities
 * @param array<int,mixed> $notes
 * @param array<int,mixed> $actives
 * @return list<array{host:string,path:string,matchSubdomains:int,priority:int,isActive:int,note:string}>
 */
function externalLinkTypeAdminNormalisePatterns(
    array $hosts,
    array $paths,
    array $subdomains,
    array $priorities,
    array $notes,
    array $actives
): array {
    $rows  = [];
    $count = max(count($hosts), count($paths));
    for ($i = 0; $i < $count; $i++) {
        $host = trim((string)($hosts[$i] ?? ''));
        if ($host === '') { continue; }
        /* Strip protocol / leading wildcard / trailing slash a curator
           might include by accident. */
        $host = preg_replace('#^https?://#i', '', $host);
        $host = ltrim((string)$host, '*.');
        $host = rtrim((string)$host, '/');
        $host = mb_substr($host, 0, 255);
        if ($host === '' || strpos($host, '.') === false) { continue; }

        $path = trim((string)($paths[$i] ?? ''));
        if ($path !== '' && $path[0] !== '/') { $path = '/' . $path; }
        $path = mb_substr($path, 0, 255);

        $rows[] = [
            'host'            => $host,
            'path'            => $path,
            'matchSubdomains' => !empty($subdomains[$i]) ? 1 : 0,
            'priority'        => isset($priorities[$i]) ? max(0, min(65535, (int)$priorities[$i])) : 100,
            'isActive'        => !empty($actives[$i]) ? 1 : 0,
            'note'            => mb_substr((string)($notes[$i] ?? ''), 0, 255),
        ];
    }
    return $rows;
}

/**
 * THE ONE PLACE `INSERT INTO tblExternalLinkPatterns` is written
 * (test-external-link-wizard.php guard (b) asserts exactly one literal of
 * this shape tree-wide). Extracted VERBATIM from
 * externalLinkTypeAdminSave()'s former inline insert loop (#1992) so a
 * NEW type's first pattern batch (externalLinkTypeAdminCreate(), below)
 * and an EXISTING type's replace-on-save (externalLinkTypeAdminSave())
 * both insert through the identical code — Create() cannot simply CALL
 * Save() instead, because Save() opens its own transaction and a nested
 * `START TRANSACTION` implicitly commits the outer one (mysqli/InnoDB has
 * no true nested transactions), which would make Create()'s own
 * type-row INSERT durable even if the pattern insert afterwards failed.
 *
 * Does NOT open its own transaction — the caller (Save() or Create())
 * owns the transaction boundary so the whole write is atomic.
 *
 * @param  list<array{host:string,path:string,matchSubdomains:int,priority:int,isActive:int,note:string}> $patternRows
 * @return int the number of pattern rows actually inserted.
 */
function externalLinkTypeAdminInsertPatterns(\mysqli $db, int $typeId, array $patternRows): int
{
    $insertCount = 0;
    $insert = $db->prepare(
        'INSERT INTO tblExternalLinkPatterns
             (LinkTypeId, Host, PathPrefix, MatchSubdomains, Priority, IsActive, Note)
         VALUES (?, ?, NULLIF(?, ""), ?, ?, ?, NULLIF(?, ""))'
    );
    foreach ($patternRows as $p) {
        $insert->bind_param(
            'issiiis',
            $typeId, $p['host'], $p['path'], $p['matchSubdomains'], $p['priority'], $p['isActive'], $p['note']
        );
        $insert->execute();
        $insertCount++;
    }
    $insert->close();
    return $insertCount;
}

/**
 * THE ONE WRITER for an EXISTING type — updates its IsActive/AppliesTo and
 * replaces its whole pattern list (delete-then-reinsert, cheap at the
 * small per-type list sizes expected). Transactional: either everything
 * lands or nothing does. Caller has already resolved `$appliesToSave` via
 * externalLinkTypeAdminResolveAppliesTo() and normalised `$patternRows` via
 * externalLinkTypeAdminNormalisePatterns().
 *
 * Byte-identical behaviour to the pre-#1992 version — the insert loop
 * moved into externalLinkTypeAdminInsertPatterns() (above) but does the
 * exact same binds in the exact same order.
 *
 * @param  list<array{host:string,path:string,matchSubdomains:int,priority:int,isActive:int,note:string}> $patternRows
 * @return int the number of pattern rows actually inserted.
 */
function externalLinkTypeAdminSave(\mysqli $db, int $typeId, int $isActive, string $appliesToSave, array $patternRows): int
{
    $db->begin_transaction();
    try {
        $stmt = $db->prepare('UPDATE tblExternalLinkTypes SET IsActive = ?, AppliesTo = ? WHERE Id = ?');
        $stmt->bind_param('isi', $isActive, $appliesToSave, $typeId);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare('DELETE FROM tblExternalLinkPatterns WHERE LinkTypeId = ?');
        $stmt->bind_param('i', $typeId);
        $stmt->execute();
        $stmt->close();

        $insertCount = externalLinkTypeAdminInsertPatterns($db, $typeId, $patternRows);

        $db->commit();
        return $insertCount;
    } catch (\Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

/**
 * #1992 — derive a URL-safe lowercase slug from a type NAME, capped to
 * `tblExternalLinkTypes.Slug`'s VARCHAR(60) width. Same regex shape as
 * `tagAdminSlugify()` (includes/tag_admin.php) — non-alnum runs collapse
 * to one hyphen, leading/trailing hyphens stripped — with the 60-char cap
 * tags don't need (`tblSongTags.Slug` has more headroom).
 *
 * Pure — no DB access.
 */
function externalLinkTypeAdminSlugify(string $name): string
{
    $slug = strtolower((string)preg_replace('/[^a-z0-9]+/i', '-', $name));
    $slug = trim($slug, '-');
    return mb_substr($slug, 0, 60);
}

/**
 * #1992 — validate + normalise a brand-new type's fields, shared by all
 * three create funnels (manual form / wizard / API twin). Never mutates
 * the DB except for the read-only slug-uniqueness probe (a race is still
 * possible between this check and the INSERT — externalLinkTypeAdminCreate()
 * catches that as a belt-and-braces 1062 → ExternalLinkTypeDuplicateSlugException).
 *
 * @param  list<mixed> $postedApplies Raw posted applies_to[] tokens (unfiltered).
 * @return array{0:?array{name:string,slug:string,category:string,iconClass:string,appliesTo:string,allowMultiple:int,isActive:int},1:?string,2:int}
 *         [$fields, $error, $httpStatus] — exactly one of $fields/$error is non-null.
 */
function externalLinkTypeAdminValidateNewType(
    \mysqli $db,
    string  $rawName,
    string  $rawSlug,
    string  $rawCategory,
    string  $rawIconClass,
    array   $postedApplies,
    int     $allowMultiple,
    int     $isActive
): array {
    $name = mb_substr(trim($rawName), 0, 120);
    if ($name === '') {
        return [null, 'Name is required.', 422];
    }

    $slug = strtolower(trim($rawSlug));
    if ($slug === '') {
        $slug = externalLinkTypeAdminSlugify($name);
    }
    if ($slug === '' || !preg_match('/^[a-z0-9-]{1,60}$/', $slug)) {
        return [null, 'Slug must be 1-60 lowercase letters, digits or hyphens.', 422];
    }

    $category = trim($rawCategory);
    if (!array_key_exists($category, IHYMNS_LINK_TYPE_CATEGORIES)) {
        return [null, 'Category must be one of the known link-type categories.', 422];
    }

    $iconClass = trim($rawIconClass);
    if ($iconClass !== '' && !preg_match('/^bi-[a-z0-9-]{1,57}$/', $iconClass)) {
        return [null, 'Icon class must look like "bi-icon-name" (Bootstrap Icons), or be left blank.', 422];
    }

    $appliesTo = array_values(array_unique(array_intersect(
        array_map('strval', $postedApplies),
        IHYMNS_LINK_ENTITY_TYPES
    )));
    if ($appliesTo === []) {
        return [null, 'Pick at least one thing this provider can apply to (song, songbook, …).', 422];
    }

    $stmt = $db->prepare('SELECT 1 FROM tblExternalLinkTypes WHERE Slug = ? LIMIT 1');
    $stmt->bind_param('s', $slug);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    if ($exists) {
        return [null, "A link type with the slug \"{$slug}\" already exists.", 409];
    }

    return [[
        'name'          => $name,
        'slug'          => $slug,
        'category'      => $category,
        'iconClass'     => $iconClass,
        'appliesTo'     => implode(',', $appliesTo),
        'allowMultiple' => $allowMultiple ? 1 : 0,
        'isActive'      => $isActive ? 1 : 0,
    ], null, 200];
}

/**
 * THE ONE CREATOR — inserts a brand-new `tblExternalLinkTypes` row plus its
 * initial pattern batch, transactionally (#1992). Caller has already
 * validated via externalLinkTypeAdminValidateNewType() (so `$fields` is
 * the validated/normalised shape that function returns) and normalised
 * `$patternRows` via externalLinkTypeAdminNormalisePatterns() — the SAME
 * pure normaliser `save_type_patterns` already used, never a second copy.
 *
 * `DisplayOrder` is end-of-category (`MAX(DisplayOrder)+1` scoped to the
 * new row's own Category) so a freshly-minted type sorts after the
 * existing ones in its category rather than jumping to the front.
 *
 * @param  array{name:string,slug:string,category:string,iconClass:string,appliesTo:string,allowMultiple:int,isActive:int} $fields
 * @param  list<array{host:string,path:string,matchSubdomains:int,priority:int,isActive:int,note:string}> $patternRows
 * @return int the new type's Id.
 * @throws ExternalLinkTypeDuplicateSlugException on a uq_slug race (1062) —
 *         belt-and-braces on top of the pre-check in
 *         externalLinkTypeAdminValidateNewType().
 */
function externalLinkTypeAdminCreate(\mysqli $db, array $fields, array $patternRows): int
{
    $db->begin_transaction();
    try {
        $orderStmt = $db->prepare('SELECT COALESCE(MAX(DisplayOrder), 0) + 1 FROM tblExternalLinkTypes WHERE Category = ?');
        $orderStmt->bind_param('s', $fields['category']);
        $orderStmt->execute();
        $orderRow = $orderStmt->get_result()->fetch_row();
        $displayOrder = (int)($orderRow[0] ?? 1);
        $orderStmt->close();

        $insert = $db->prepare(
            'INSERT INTO tblExternalLinkTypes
                 (Slug, Name, Category, IconClass, AppliesTo, AllowMultiple, IsActive, DisplayOrder)
             VALUES (?, ?, ?, NULLIF(?, ""), ?, ?, ?, ?)'
        );
        $insert->bind_param(
            'sssssiii',
            $fields['slug'], $fields['name'], $fields['category'], $fields['iconClass'],
            $fields['appliesTo'], $fields['allowMultiple'], $fields['isActive'], $displayOrder
        );
        $insert->execute();
        $newId = (int)$db->insert_id;
        $insert->close();

        externalLinkTypeAdminInsertPatterns($db, $newId, $patternRows);

        $db->commit();
        return $newId;
    } catch (\mysqli_sql_exception $e) {
        $db->rollback();
        if ((int)$e->getCode() === 1062) {
            throw new ExternalLinkTypeDuplicateSlugException(
                'A link type with that slug already exists.', 409, $e
            );
        }
        throw $e;
    } catch (\Throwable $e) {
        $db->rollback();
        throw $e;
    }
}
