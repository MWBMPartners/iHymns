<?php

declare(strict_types=1);

/**
 * iHymns — External-link type + URL-pattern admin write core (#845/#1748 §5.2,
 * API-coverage batch 4b-ii A7)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `/manage/external-link-types` (the web admin page) and the new
 * `admin_external_link_type_save` native API action both need to do the
 * SAME thing: flip a link type's active flag, update which kinds of thing
 * it applies to, and replace its whole list of URL-matching patterns — all
 * in ONE write. This file is the ONE place that write is done — both
 * surfaces call these functions instead of re-typing their own copy
 * (rule #22/#35). Modelled on includes/tag_admin.php (#1969).
 *
 * SCOPE (deliberate)
 * ----------------------------------------------------------------------------
 * The page exposes exactly ONE write action — `save_type_patterns` — which
 * saves an EXISTING type's IsActive/AppliesTo plus a wholesale replace of
 * its patterns. There is no separate type create/delete on this page (the
 * registry's *types* are curated content shipped with the app, not
 * curator-minted rows), so there is no `admin_external_link_type_delete`
 * to extract — this file's shape mirrors exactly what the page has, per
 * the extraction discipline (rule #22: extract what exists, never invent a
 * write path the page doesn't have).
 *
 * @link appWeb/public_html/includes/tag_admin.php            the extraction precedent this mirrors
 * @link appWeb/public_html/includes/external_link_helpers.php IHYMNS_LINK_ENTITY_TYPES — the ONE AppliesTo allow-list
 * @link appWeb/public_html/manage/external-link-types.php     page consumer
 * @link appWeb/public_html/api.php                            admin_external_link_type_save API consumer
 * @see #845 #1748 rule #11 rule #12
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'external_link_helpers.php'; /* IHYMNS_LINK_ENTITY_TYPES */

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
 * THE ONE WRITER — updates a type's IsActive/AppliesTo and replaces its
 * whole pattern list (delete-then-reinsert, cheap at the small per-type
 * list sizes expected). Transactional: either everything lands or nothing
 * does. Caller has already resolved `$appliesToSave` via
 * externalLinkTypeAdminResolveAppliesTo() and normalised `$patternRows` via
 * externalLinkTypeAdminNormalisePatterns().
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

        $db->commit();
        return $insertCount;
    } catch (\Throwable $e) {
        $db->rollback();
        throw $e;
    }
}
