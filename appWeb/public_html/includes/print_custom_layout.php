<?php

declare(strict_types=1);

/**
 * iHymns — Custom print-layout storage (#1767 remainder P7, feature E)
 *
 * ELI5: this file is the ONE place that reads and writes a curator's
 * uploaded full-page HTML "skin" (a cover page, a branded letterhead, a
 * booklet shell) that wraps a printed song. It never renders anything
 * itself — it just sanitises what a curator pastes/uploads on SAVE, stores
 * BOTH the sanitised result and the raw original, and hands back ONLY the
 * sanitised copy to every reader that is building something a browser or a
 * PDF will actually display.
 *
 * DETAIL
 * ------
 * `.claude/print-templates-1767-remainder-plan.md` §7 ("E — uploadable
 * full-page designs"), storage = `tblPrintTemplateCustomLayout` (already
 * migrated in this branch's P1 commit, §9). Owner decision #4: full
 * sanitised HTML upload — the uploaded HTML is a document WRAPPER (a skin),
 * never a content renderer; song content always comes from the ONE renderer
 * (`renderTemplateBodyHtml()` in `js/modules/print.js`), injected where the
 * layout's literal `{{content}}` token sits (§7.1).
 *
 * THE TWO READ SHAPES, KEPT DELIBERATELY SEPARATE (§5.4 / §7.3 of the plan
 * — "the render path only ever uses the SANITISED column, never
 * HtmlOriginal"):
 *   - `printCustomLayoutGetSanitised()` / `…ForTemplates()` — RENDER-PATH
 *     reads. SELECT `HtmlSanitised` ONLY. Consumed by `api.php`'s
 *     `print_templates` action (which is what `js/modules/print.js`'s
 *     `loadTemplates()` fetches — the browser-print, admin-preview AND
 *     server-PDF paths all originate here) and by nothing else.
 *   - `printCustomLayoutGetForEdit()` — EDIT-PATH read. Returns BOTH
 *     columns, because a curator re-opening the editor needs to see (and
 *     re-submit) the HTML they actually wrote, not the already-stripped
 *     sanitised copy — pre-filling the textarea with the sanitised output
 *     would silently "eat" formatting on every re-save. This is a DISPLAY
 *     concern for `manage/print-templates.php`'s own editor chrome, never a
 *     render path — `tests/php/test-print-custom-layout.php` (b) polices
 *     the distinction by scanning the render-path FILES/FUNCTIONS for the
 *     literal `HtmlOriginal`, not this one.
 *
 * THE ONE WRITER: `printCustomLayoutSave()`. Sanitises `$rawHtml` through
 * `ihymnsSanitizeHtml($rawHtml, 'layout')` (the richer of the two profiles
 * `includes/html_sanitizer.php` ships, built specifically for this feature,
 * §5.1), REJECTS the save unless the sanitised output contains the literal
 * `{{content}}` token EXACTLY ONCE (§7.1 point 1 — "a layout that lost its
 * slot renders no song"), and persists BOTH the sanitised output
 * (`HtmlSanitised`, render-served) and the raw upload (`HtmlOriginal`,
 * dormant — kept solely so a future sanitiser-allow-list WIDENING can
 * re-sanitise from source, §5.4), stamped with the CURRENT
 * `IHYMNS_HTML_SANITISER_VERSION` so a future TIGHTENING can find and
 * re-sanitise rows produced under an older, looser version.
 *
 * FAIL-SOFT, STRICT-safe: every read is existence-gated
 * (`printCustomLayoutTableExists()`, mirrors
 * `includes/print_usage.php::_printUsageTableExists()`) so an un-migrated
 * install degrades to a silent "no layout" (`null`/`[]`), never a 500 under
 * mysqli's `MYSQLI_REPORT_STRICT` (CLAUDE.md red-flag: "a page or handler
 * that treats query() as returning false on error"). The table SHIPPED in
 * this branch's P1 commit, but migrations are web-run, never auto-applied
 * (rule #19) — a fresh/un-migrated install must still load
 * `/manage/print-templates` and `?action=print_templates` without error.
 *
 * @see .claude/print-templates-1767-remainder-plan.md §7, §9   the full design this file implements
 * @see appWeb/public_html/includes/html_sanitizer.php           ihymnsSanitizeHtml($x, 'layout') — the ONE sanitiser, reused not forked
 * @see appWeb/public_html/manage/print-templates.php            the ONE CRUD surface (create/edit/delete a layout)
 * @see appWeb/public_html/api.php                                print_templates action — the ONE render-path reader
 * @see appWeb/public_html/js/modules/print.js                    applyCustomLayout() — the ONE {{content}} substitution point
 * @see appWeb/public_html/manage/print-pdf.php                   the server-PDF funnel (widened to the 'layout' sanitiser profile, §7.1 point 4)
 * @see tests/php/test-print-custom-layout.php                    the mutation-proven guard for this feature
 * @see appWeb/.sql/migrate-print-template-layouts.php            the P1 migration this reads/writes
 * @link https://www.php.net/manual/en/mysqli-driver.report-mode.php
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'html_sanitizer.php';

/** v1 only ever writes 'page' — Slot is reserved multiplicity (rule #20), see the P1 migration's doc-block. */
const IHYMNS_PRINT_CUSTOM_LAYOUT_SLOT_DEFAULT = 'page';

/** §7.1 point 1 — "file input (.html) or paste-textarea per template, ≤ 256 KiB". Checked on the RAW upload, before sanitisation. */
const IHYMNS_PRINT_CUSTOM_LAYOUT_MAX_BYTES = 262144;

/** The one literal slot token a layout must carry exactly once (§7.1). */
const IHYMNS_PRINT_CUSTOM_LAYOUT_TOKEN = '{{content}}';

/**
 * Memoised INFORMATION_SCHEMA existence probe for `tblPrintTemplateCustomLayout`.
 *
 * ELI5: "does the custom-layout table exist? remember the answer so we only
 * ask once." Mirrors `includes/print_usage.php::_printUsageTableExists()`.
 */
function printCustomLayoutTableExists(\mysqli $db): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $st = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblPrintTemplateCustomLayout' LIMIT 1"
        );
        $st->execute();
        $exists = $st->get_result()->fetch_row() !== null;
        $st->close();
    } catch (\Throwable $e) {
        $exists = false; // any error -> treat as "absent" -> fail open (no-op, never a 500)
    }
    return $exists;
}

/**
 * RENDER-PATH read — ONE template's ACTIVE layout, SANITISED HTML ONLY.
 * Never selects `HtmlOriginal` (rule §5.4/§7.3 — enforced here structurally:
 * the SELECT list below is the whole contract).
 *
 * @return string|null The sanitised layout HTML, or null when the table is
 *                       missing, the template has no active layout in this
 *                       slot, or any DB error occurred (fail-quiet — a
 *                       missing layout is not an error, it just means the
 *                       template renders its standard document shell).
 */
function printCustomLayoutGetSanitised(int $templateId, string $slot = IHYMNS_PRINT_CUSTOM_LAYOUT_SLOT_DEFAULT): ?string
{
    if ($templateId <= 0) {
        return null;
    }
    try {
        $db = getDbMysqli();
        if (!printCustomLayoutTableExists($db)) {
            return null;
        }
        $stmt = $db->prepare(
            'SELECT HtmlSanitised FROM tblPrintTemplateCustomLayout
              WHERE TemplateId = ? AND Slot = ? AND IsActive = 1 LIMIT 1'
        );
        $stmt->bind_param('is', $templateId, $slot);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (string)$row['HtmlSanitised'] : null;
    } catch (\Throwable $e) {
        error_log('[print_custom_layout] read failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * RENDER-PATH read — BATCH form of `printCustomLayoutGetSanitised()`, one
 * round trip for a whole `print_templates` listing (`api.php`). SANITISED
 * HTML ONLY (same contract as the single-row form above).
 *
 * @param  list<int> $templateIds
 * @return array<int,string> templateId => sanitised layout HTML, present
 *                             ONLY for templates that have an active layout
 *                             (absent key == "no layout", never an empty
 *                             string sentinel — the caller's `isset()` check
 *                             is the contract).
 */
function printCustomLayoutGetSanitisedForTemplates(array $templateIds, string $slot = IHYMNS_PRINT_CUSTOM_LAYOUT_SLOT_DEFAULT): array
{
    $ids = array_values(array_unique(array_filter(
        array_map('intval', $templateIds),
        static fn($n) => $n > 0
    )));
    if ($ids === []) {
        return [];
    }
    try {
        $db = getDbMysqli();
        if (!printCustomLayoutTableExists($db)) {
            return [];
        }
        /* Placeholder count matches $ids exactly — array_fill()'d, the
           house-blessed dynamic-IN-clause shape (CLAUDE.md rule #5). */
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids)) . 's';
        $stmt = $db->prepare(
            "SELECT TemplateId, HtmlSanitised FROM tblPrintTemplateCustomLayout
              WHERE TemplateId IN ($placeholders) AND Slot = ? AND IsActive = 1"
        );
        $params = $ids;
        $params[] = $slot;
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[(int)$row['TemplateId']] = (string)$row['HtmlSanitised'];
        }
        $stmt->close();
        return $out;
    } catch (\Throwable $e) {
        error_log('[print_custom_layout] batch read failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * EDIT-PATH read — everything the admin editor needs to redisplay/re-edit a
 * template's layout: the raw upload (for the textarea — never render this),
 * the sanitised copy (for the live preview, via `applyCustomLayout()` in
 * print.js), and the bookkeeping columns. `manage/print-templates.php`'s
 * OWN GET-time preload is the only caller — this is display chrome for the
 * curator who uploaded it, never a path any browser/PDF reader reaches.
 *
 * @return array{htmlOriginal:string,htmlSanitised:string,sanitiserVersion:int,sizeBytes:int,isActive:bool}|null
 */
function printCustomLayoutGetForEdit(int $templateId, string $slot = IHYMNS_PRINT_CUSTOM_LAYOUT_SLOT_DEFAULT): ?array
{
    if ($templateId <= 0) {
        return null;
    }
    try {
        $db = getDbMysqli();
        if (!printCustomLayoutTableExists($db)) {
            return null;
        }
        $stmt = $db->prepare(
            'SELECT HtmlOriginal, HtmlSanitised, SanitiserVersion, SizeBytes, IsActive
               FROM tblPrintTemplateCustomLayout
              WHERE TemplateId = ? AND Slot = ? LIMIT 1'
        );
        $stmt->bind_param('is', $templateId, $slot);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        return [
            'htmlOriginal'     => (string)($row['HtmlOriginal'] ?? ''),
            'htmlSanitised'    => (string)$row['HtmlSanitised'],
            'sanitiserVersion' => (int)$row['SanitiserVersion'],
            'sizeBytes'        => (int)$row['SizeBytes'],
            'isActive'         => (int)$row['IsActive'] === 1,
        ];
    } catch (\Throwable $e) {
        error_log('[print_custom_layout] edit-read failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * THE ONE WRITER — sanitise, validate, persist. §7.1 point 1's exact
 * pipeline: `ihymnsSanitizeHtml($raw, 'layout')` → validate exactly one
 * `{{content}}` token survives (reject otherwise) → upsert
 * `(HtmlSanitised, HtmlOriginal, SanitiserVersion, SizeBytes)` keyed on the
 * table's `uq_TemplateSlot (TemplateId, Slot)` unique key.
 *
 * ELI5: "here is some HTML a curator pasted — clean it, make sure it still
 * has exactly one spot to put the song, and save both the clean copy and
 * what they originally typed."
 *
 * @param  int    $templateId  Must already exist in `tblPrintTemplates`
 *                               (the FK enforces this — a bad id surfaces as
 *                               a caught DB error, `ok:false`).
 * @param  string $rawHtml     The curator's pasted/uploaded HTML, untrusted.
 * @param  int|null $userId    `getCurrentUser()['id']` — stamped as
 *                               `CreatedBy` on first insert only.
 * @param  string $slot        Reserved multiplicity (rule #20); v1 callers
 *                               always pass the default.
 * @return array{ok:bool,error?:string,sizeBytes?:int}
 */
function printCustomLayoutSave(
    int $templateId,
    string $rawHtml,
    ?int $userId,
    string $slot = IHYMNS_PRINT_CUSTOM_LAYOUT_SLOT_DEFAULT
): array {
    if ($templateId <= 0) {
        return ['ok' => false, 'error' => 'A template must be saved before it can have a custom layout.'];
    }
    if (trim($rawHtml) === '') {
        return ['ok' => false, 'error' => 'Paste some HTML for the custom layout.'];
    }
    /* Cheap size check on the RAW upload, before the (more expensive)
       DOMDocument parse — §7.1's ≤ 256 KiB cap. strlen() (bytes), matching
       the migration's own SizeBytes doc-comment ("LENGTH(HtmlSanitised) at
       save" — a byte length, not a character count). */
    if (strlen($rawHtml) > IHYMNS_PRINT_CUSTOM_LAYOUT_MAX_BYTES) {
        return ['ok' => false, 'error' => 'That layout is too large (max 256 KiB).'];
    }

    /* THE sanitise step — 'layout', the richer of the two profiles, built
       specifically for this feature (§5.1). Never 'print' here: 'print' is
       the PDF-endpoint's INPUT profile (renderTemplateBodyHtml()'s own
       narrow vocabulary), not what a curator's full-page skin needs. */
    $sanitised = ihymnsSanitizeHtml($rawHtml, 'layout');

    /* §7.1 point 1 — "validates exactly one {{content}} token survives
       (reject otherwise — a layout that lost its slot renders no song)".
       Checked on the SANITISED output (what actually gets stored/served),
       not the raw upload — a token that only survives inside something the
       sanitiser strips outright (vanishingly unlikely, since the walker
       preserves ALL text nodes regardless of surrounding tag fate, but
       checked on the real output rather than assumed). */
    $tokenCount = substr_count($sanitised, IHYMNS_PRINT_CUSTOM_LAYOUT_TOKEN);
    if ($tokenCount === 0) {
        return ['ok' => false, 'error' => 'The layout must contain the {{content}} token exactly once — that is where the song will be inserted.'];
    }
    if ($tokenCount > 1) {
        return ['ok' => false, 'error' => 'The layout must contain the {{content}} token exactly ONCE (found ' . $tokenCount . ').'];
    }

    try {
        $db = getDbMysqli();
        if (!printCustomLayoutTableExists($db)) {
            return ['ok' => false, 'error' => 'Custom layouts are not available on this install yet — run the pending database migration first.'];
        }

        $sizeBytes = strlen($sanitised);
        $version   = IHYMNS_HTML_SANITISER_VERSION;
        $createdBy = ($userId !== null && $userId > 0) ? $userId : null;

        /* Upsert on the table's uq_TemplateSlot (TemplateId, Slot) unique
           key — CreatedBy is set only on the FIRST insert (an UPDATE leaves
           it as whoever originally uploaded the layout; VALUES(CreatedBy)
           inside the UPDATE clause would silently overwrite that with the
           re-editor's id, which is not what "who uploaded it" should mean). */
        $stmt = $db->prepare(
            'INSERT INTO tblPrintTemplateCustomLayout
                (TemplateId, Slot, HtmlSanitised, HtmlOriginal, SanitiserVersion, SizeBytes, IsActive, CreatedBy)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE
                HtmlSanitised = VALUES(HtmlSanitised),
                HtmlOriginal  = VALUES(HtmlOriginal),
                SanitiserVersion = VALUES(SanitiserVersion),
                SizeBytes     = VALUES(SizeBytes),
                IsActive      = 1'
        );
        $stmt->bind_param('issssii', $templateId, $slot, $sanitised, $rawHtml, $version, $sizeBytes, $createdBy);
        $stmt->execute();
        $stmt->close();

        return ['ok' => true, 'sizeBytes' => $sizeBytes];
    } catch (\Throwable $e) {
        error_log('[print_custom_layout] save failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not save the custom layout — please try again.'];
    }
}

/**
 * Delete a template's layout row outright (the editor's "Remove layout" —
 * the template falls back to its standard document shell). Not a soft
 * `IsActive = 0` toggle: the deliverable is "create/edit/delete", and an
 * orphaned sanitised/original blob serves no purpose once removed (unlike
 * `IsActive`, which exists for the DIFFERENT case of a sanitiser-tightening
 * re-sanitise pass wanting to flag-then-fix a row without losing it, §5.4 —
 * that path, when built, would use its own UPDATE, not this function).
 *
 * @return bool true if a row was deleted (or never existed — idempotent),
 *              false only on an actual DB error.
 */
function printCustomLayoutDelete(int $templateId, string $slot = IHYMNS_PRINT_CUSTOM_LAYOUT_SLOT_DEFAULT): bool
{
    if ($templateId <= 0) {
        return false;
    }
    try {
        $db = getDbMysqli();
        if (!printCustomLayoutTableExists($db)) {
            return true; // nothing to delete — idempotent no-op
        }
        $stmt = $db->prepare('DELETE FROM tblPrintTemplateCustomLayout WHERE TemplateId = ? AND Slot = ?');
        $stmt->bind_param('is', $templateId, $slot);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (\Throwable $e) {
        error_log('[print_custom_layout] delete failed: ' . $e->getMessage());
        return false;
    }
}
