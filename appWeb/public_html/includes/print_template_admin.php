<?php

declare(strict_types=1);

/**
 * iHymns — Print template (`tblPrintTemplates`, Scope='song') CRUD shared
 * core (#1350 Phase 2 / #1767, API-coverage batch 4b-ii A8)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `/manage/print-templates` (the web admin editor) and the new
 * `admin_print_template_*` native API actions both need to do the SAME
 * things: validate + save a template's blocks/page-options, clone one,
 * delete one, and make one the system default. This file is the ONE place
 * each of those scalar-row writes is done — both surfaces call these
 * functions instead of re-typing their own copy (rule #22/#35). Modelled on
 * includes/catalogue_admin.php (#941/#1765, batch 4b-i A4).
 *
 * SCOPE (deliberate)
 * ----------------------------------------------------------------------------
 * This file covers ONLY the `tblPrintTemplates` scalar-row actions
 * (save/clone/delete/set_default). The page's TWO other write actions are
 * DELIBERATELY left untouched here:
 *   - `layout_save` / `layout_delete` (the #1767 remainder P7 custom
 *     full-page layout skin) are ALREADY a single shared core —
 *     `printCustomLayoutSave()` / `printCustomLayoutDelete()` in
 *     `includes/print_custom_layout.php` — which already runs every upload
 *     through `ihymnsSanitizeHtml($raw, 'layout')` (rule #39). The new
 *     `admin_print_layout_save`/`_delete` API actions call THAT core
 *     directly; this file does not re-wrap it (rule #22 — never fork a
 *     writer that already exists).
 *   - `import` (paste-JSON import) is OUT OF SCOPE for this batch — see
 *     `.claude/api-coverage-2026-08-28.md` §4.3 A8 / §9 Batch 4, which
 *     proposes only save/clone/delete/set_default + layout_save/delete for
 *     A8's API surface.
 *
 * The block/page-option VALIDATION itself is never re-forked here either —
 * `$BLOCK_SCHEMA`/`$SHOWIF_CONDITIONS`/`$PAGE_OPTION_SCHEMA`/
 * `ptSanitiseBlocks()`/`ptSanitisePageOptions()` stay the ONE rulebook in
 * `includes/print_template_schema.php` (shared with `manage/print-pdf.php`,
 * rule #35) — `printTemplateAdminValidateContent()` below is a THIN wrapper
 * that calls those same functions, never a second copy of the schema.
 *
 * @link appWeb/public_html/includes/catalogue_admin.php        the extraction precedent this mirrors
 * @link appWeb/public_html/includes/print_template_schema.php  the ONE block/page-option rulebook — reused, not forked
 * @link appWeb/public_html/includes/print_custom_layout.php    the ONE custom-layout writer (already sanitiser-gated, rule #39) — reused directly by api.php, not wrapped here
 * @link appWeb/public_html/manage/print-templates.php          page consumer
 * @link appWeb/public_html/api.php                             admin_print_template_* / admin_print_layout_* API consumer
 * @see #1350 #1767 rule #39
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/** True once `tblPrintTemplates` exists — the page's own pre-migration-safe
 *  probe, extracted so the API action gates on the identical check (never a
 *  mysqli STRICT throw on a long-running un-migrated install). */
function printTemplateAdminTableExists(\mysqli $db): bool
{
    try {
        $r = $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblPrintTemplates' LIMIT 1"
        );
        $exists = $r && $r->fetch_row() !== null;
        if ($r) { $r->close(); }
        return $exists;
    } catch (\Throwable $e) {
        error_log('[printTemplateAdminTableExists] ' . $e->getMessage());
        return false;
    }
}

/**
 * Validate + sanitise a save/import's name + blocks + page-options in one
 * step. Never touches the DB. `$blockSchema`/`$showIfConditions`/
 * `$pageOptionSchema` are the caller's already-`require_once`d
 * `$BLOCK_SCHEMA`/`$SHOWIF_CONDITIONS`/`$PAGE_OPTION_SCHEMA` globals from
 * `includes/print_template_schema.php` (passed in rather than required
 * again here, so this file never depends on that file's top-level-scope
 * `require_once` contract — see that file's own doc-block).
 *
 * Byte-identical rules/messages to the page's pre-extraction `save` case.
 *
 * @param  mixed $rawBlocksJson    Raw POSTed `blocks_json` string (or already-decoded array).
 * @param  mixed $rawPageOptsJson  Raw POSTed `page_options_json` string (or already-decoded array).
 * @return array{0: array{name:string,blocksJson:string,pageOptsJson:?string,blocksCount:int}, 1: ?string} [$fields, $error]
 */
function printTemplateAdminValidateContent(
    string $rawName,
    $rawBlocksJson,
    $rawPageOptsJson,
    array $blockSchema,
    array $showIfConditions,
    array $pageOptionSchema
): array {
    $name = mb_substr(trim($rawName), 0, 120);
    if ($name === '') {
        return [[], 'A template name is required.'];
    }

    /* Decode + sanitise the block list. Must be a non-empty array of
       recognised blocks AFTER sanitisation, else reject. We persist the
       RE-ENCODED clean JSON, never the raw POST string. */
    $rawBlocks = is_array($rawBlocksJson) ? $rawBlocksJson : json_decode((string)$rawBlocksJson, true);
    if (!is_array($rawBlocks) || $rawBlocks === []) {
        return [[], 'Add at least one block before saving.'];
    }
    $blocks = ptSanitiseBlocks($rawBlocks, $blockSchema, $showIfConditions);
    if ($blocks === []) {
        return [[], 'None of the submitted blocks were recognised.'];
    }
    $blocksJson = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    /* Page options decode → sanitise → JSON or NULL. */
    $rawPageOpts = is_array($rawPageOptsJson) ? $rawPageOptsJson : json_decode((string)$rawPageOptsJson, true);
    $pageOpts    = ptSanitisePageOptions($rawPageOpts, $pageOptionSchema);
    $pageOptsJson = $pageOpts !== null
        ? json_encode($pageOpts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : null;

    return [
        ['name' => $name, 'blocksJson' => $blocksJson, 'pageOptsJson' => $pageOptsJson, 'blocksCount' => count($blocks)],
        null,
    ];
}

/** Next `SortOrder` for a new/cloned Scope='song' row — appended to the end
 *  of the curator's ordered list. */
function printTemplateAdminNextSortOrder(\mysqli $db): int
{
    $res = $db->query("SELECT COALESCE(MAX(SortOrder),0)+1 AS n FROM tblPrintTemplates WHERE Scope='song'");
    $row = $res ? $res->fetch_assoc() : null;
    if ($res) { $res->close(); }
    return (int)($row['n'] ?? 0);
}

/** One Scope='song' template's Name/BlocksJson/PageOptionsJson/IsActive by
 *  id — the update/set_default existence check AND clone's source read
 *  (a superset of clone's original 3-column SELECT; the extra column is
 *  unused by clone and changes no behaviour). Null when no such row (or a
 *  row in a different Scope — this page can never touch another scope). */
function printTemplateAdminFetch(\mysqli $db, int $id): ?array
{
    $scope = 'song';
    $stmt = $db->prepare('SELECT Name, BlocksJson, PageOptionsJson, IsActive FROM tblPrintTemplates WHERE Id = ? AND Scope = ? LIMIT 1');
    $stmt->bind_param('is', $id, $scope);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** Insert a new Scope='song', OwnerId NULL (curated) template row.
 *  @return int the new tblPrintTemplates.Id. */
function printTemplateAdminCreate(\mysqli $db, string $name, string $blocksJson, ?string $pageOptsJson, int $isActive, int $createdBy): int
{
    $sortOrder = printTemplateAdminNextSortOrder($db);
    $stmt = $db->prepare(
        "INSERT INTO tblPrintTemplates
            (Name, Scope, OwnerId, BlocksJson, PageOptionsJson, IsActive, IsDefault, SortOrder, CreatedBy)
         VALUES (?, 'song', NULL, ?, ?, ?, 0, ?, ?)"
    );
    $stmt->bind_param('sssiii', $name, $blocksJson, $pageOptsJson, $isActive, $sortOrder, $createdBy);
    $stmt->execute();
    $newId = (int)$db->insert_id;
    $stmt->close();
    return $newId;
}

/** Write an existing Scope='song' template row's Name/BlocksJson/
 *  PageOptionsJson/IsActive. Caller has already confirmed the row exists
 *  (printTemplateAdminFetch()). */
function printTemplateAdminUpdate(\mysqli $db, int $id, string $name, string $blocksJson, ?string $pageOptsJson, int $isActive): void
{
    $scope = 'song';
    $stmt = $db->prepare(
        'UPDATE tblPrintTemplates
            SET Name = ?, BlocksJson = ?, PageOptionsJson = ?, IsActive = ?
          WHERE Id = ? AND Scope = ?'
    );
    $stmt->bind_param('sssiis', $name, $blocksJson, $pageOptsJson, $isActive, $id, $scope);
    $stmt->execute();
    $stmt->close();
}

/** Delete a Scope='song' template row. @return int affected rows (0 = no
 *  such id in this scope — caller's 404). */
function printTemplateAdminDelete(\mysqli $db, int $id): int
{
    $scope = 'song';
    $stmt = $db->prepare('DELETE FROM tblPrintTemplates WHERE Id = ? AND Scope = ?');
    $stmt->bind_param('is', $id, $scope);
    $stmt->execute();
    $deleted = $stmt->affected_rows;
    $stmt->close();
    return $deleted;
}

/**
 * #1767 Z — clone an existing Scope='song' template into a fresh
 * independent row (blocks + page options copied verbatim; the copy starts
 * active, never default). Caller has already confirmed the source exists
 * (or calls this and checks for null — both are fine, this function does
 * its own existence read via printTemplateAdminFetch()).
 *
 * @return array{id:int,name:string}|null null when $srcId names no
 *         Scope='song' row.
 */
function printTemplateAdminClone(\mysqli $db, int $srcId, int $createdBy): ?array
{
    $src = printTemplateAdminFetch($db, $srcId);
    if (!$src) {
        return null;
    }
    $cloneName = mb_substr(trim((string)$src['Name']) . ' (copy)', 0, 120);
    $sortOrder = printTemplateAdminNextSortOrder($db);
    $ins = $db->prepare(
        "INSERT INTO tblPrintTemplates
            (Name, Scope, OwnerId, BlocksJson, PageOptionsJson, IsActive, IsDefault, SortOrder, CreatedBy)
         VALUES (?, 'song', NULL, ?, ?, 1, 0, ?, ?)"
    );
    $ins->bind_param('sssii', $cloneName, $src['BlocksJson'], $src['PageOptionsJson'], $sortOrder, $createdBy);
    $ins->execute();
    $newId = (int)$db->insert_id;
    $ins->close();
    return ['id' => $newId, 'name' => $cloneName];
}

/**
 * #1767 J — make ONE Scope='song' template the system default. A single
 * default is the invariant, so this clears IsDefault on every other song
 * template first, in a transaction, and forces the chosen one active (a
 * hidden default would be pointless). Caller decides existence (404) via
 * printTemplateAdminFetch() BEFORE calling this — `affected_rows` on the
 * targeted UPDATE is NOT a reliable existence signal here (a template that
 * is ALREADY the default touches 0 rows on a re-run, same "no columns
 * actually changed" mysqli behaviour tagAdminUpdate's callers avoid
 * relying on elsewhere in this codebase).
 */
function printTemplateAdminSetDefault(\mysqli $db, int $id): void
{
    $db->begin_transaction();
    try {
        $db->query("UPDATE tblPrintTemplates SET IsDefault = 0 WHERE Scope = 'song' AND IsDefault = 1");
        $stmt = $db->prepare("UPDATE tblPrintTemplates SET IsDefault = 1, IsActive = 1 WHERE Id = ? AND Scope = 'song'");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollback();
        throw $e;
    }
}
