<?php

declare(strict_types=1);

/**
 * iHymns — Language registry admin CRUD shared core (#681/#736/#738,
 * API-coverage batch 4b-ii A6)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `/manage/languages` (the web admin page) and the new `admin_language_*`
 * native API actions both need to do the SAME things: validate a typed
 * BCP-47 code/name/native-name/direction/scope, create/update/toggle/delete
 * a `tblLanguages` row, and remap every use of a junk tag to a good one.
 * This file is the ONE place each of those is written — both surfaces call
 * these functions instead of re-typing their own copy (rule #22/#35).
 * Modelled on includes/tag_admin.php (#1969, batch 4b-i A3).
 *
 * DETAILED
 * ----------------------------------------------------------------------------
 * The remap WRITE core (`languageTagRemap()`, incl. its `line-path` branch
 * that goes through `lyricLinesEditableComponents()` /
 * `lyricLinesWriteComponents()` — rule #25, NEVER a raw UPDATE against
 * `tblLyricLines.LanguageCode`) and the live-usage SCAN
 * (`languageTagAuditScan()`) already live in `includes/language_tag_audit.php`
 * — that extraction happened when the Unknown-tags panel shipped, and this
 * file does not re-fork either. What THIS file adds is the thin
 * validate-then-confirm GLUE around that core
 * (`languageAdminRemapPreflight()`) that `manage/languages.php`'s `remap_tag`
 * action used to carry entirely inline — extracted so the new
 * `admin_language_remap_tag` API action makes the exact same 400/404/409
 * decisions the page does, from ONE place, rather than a second hand-typed
 * copy of the confirm-count dance (rule #35's own red flag: "a comment
 * saying 'keep these in sync' is the failure, not the fix").
 *
 * The PUBLIC language reads (`languages`/`scripts`/`regions`/`variants` +
 * their `*_search` actions, `includes/language_names.php`) are completely
 * untouched by this file — this is admin CRUD only.
 *
 * @link appWeb/public_html/includes/tag_admin.php           the extraction precedent this mirrors
 * @link appWeb/public_html/includes/language_tag_audit.php  languageTagRemap()/languageTagAuditScan() — the ONE remap write core + scan, reused not forked
 * @link appWeb/public_html/manage/languages.php              page consumer
 * @link appWeb/public_html/api.php                           admin_language_* API consumer
 * @see #681 #736 #738 #1969 rule #25
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'language_tag_audit.php'; /* languageTagAuditScan() / languageTagRemap() — the ONE remap core */

/** Allowed `tblLanguages.Scope` values — the ONE list; the page's filter
 *  dropdown and the create/update validator both read this, never a second
 *  typed copy (rule #35). */
const IHYMNS_LANGUAGE_SCOPES = ['individual', 'macrolanguage', 'collection', 'private-use', 'special'];

/** Allowed `tblLanguages.TextDirection` values. */
const IHYMNS_LANGUAGE_TEXT_DIRECTIONS = ['ltr', 'rtl'];

/**
 * Per-field validators — hoisted verbatim out of `manage/languages.php`'s
 * pre-extraction closures (byte-identical rules/messages), so a page and an
 * API caller reject the exact same input the exact same way.
 */
function languageAdminValidateCode(string $code): ?string
{
    $code = trim($code);
    if ($code === '')                             return 'Code is required.';
    if (strlen($code) > 35)                       return 'Code must be 35 characters or fewer.';
    if (!preg_match('/^[a-zA-Z0-9-]+$/', $code))  return 'Code may contain only letters, digits, and hyphens.';
    /* Soft-tolerant — IANA codes are lowercase primary, Title-case script,
       UPPERCASE region, but admins sometimes paste mixed case. We compare
       lowercase here; the picker lowercases on lookup so case differences
       don't matter for retrieval. */
    if (!preg_match('/^[a-z]{2,8}(-[a-z0-9]+)*$/', strtolower($code))) {
        return 'Code must look like an IETF BCP 47 tag (e.g. en, en-GB, zh-Hans).';
    }
    return null;
}

function languageAdminValidateName(string $name): ?string
{
    $name = trim($name);
    if ($name === '')        return 'Name is required.';
    if (strlen($name) > 250) return 'Name must be 250 characters or fewer.';
    return null;
}

function languageAdminValidateNativeName(string $native): ?string
{
    /* NativeName is optional — empty is fine and explicitly meaningful (the
       picker falls back to Name when NativeName is blank). */
    if (strlen($native) > 250) return 'NativeName must be 250 characters or fewer.';
    return null;
}

function languageAdminValidateTextDirection(string $td): ?string
{
    if (!in_array($td, IHYMNS_LANGUAGE_TEXT_DIRECTIONS, true)) {
        return 'TextDirection must be ltr or rtl.';
    }
    return null;
}

function languageAdminValidateScope(string $scope): ?string
{
    if (!in_array($scope, IHYMNS_LANGUAGE_SCOPES, true)) {
        return 'Scope must be one of: ' . implode(', ', IHYMNS_LANGUAGE_SCOPES);
    }
    return null;
}

/**
 * Validate + normalise a create/update's five fields in one step. Never
 * touches the DB. Returns a per-field error map identical in shape to what
 * `manage/languages.php`'s pre-extraction `array_filter([...])` produced, so
 * both the page's `fields` JSON response and a new API 400 body carry the
 * same keys (code/name/native_name/text_direction/scope).
 *
 * @return array{0: array{code:string,name:string,native:string,textDir:string,scope:string}, 1: array<string,string>}
 *         [$fields, $fieldErrors] — $fieldErrors is empty when valid.
 */
function languageAdminValidateFields(
    string $rawCode,
    string $rawName,
    string $rawNative,
    string $rawTextDir,
    string $rawScope
): array {
    $code    = trim($rawCode);
    $name    = trim($rawName);
    $native  = trim($rawNative);
    $textDir = trim($rawTextDir) ?: 'ltr';
    $scope   = trim($rawScope) ?: 'individual';

    $errors = array_filter([
        'code'           => languageAdminValidateCode($code),
        'name'           => languageAdminValidateName($name),
        'native_name'    => languageAdminValidateNativeName($native),
        'text_direction' => languageAdminValidateTextDirection($textDir),
        'scope'          => languageAdminValidateScope($scope),
    ]);

    return [
        ['code' => $code, 'name' => $name, 'native' => $native, 'textDir' => $textDir, 'scope' => $scope],
        $errors,
    ];
}

/** True when `$code` is already a row in `tblLanguages`. */
function languageAdminCodeExists(\mysqli $db, string $code): bool
{
    $stmt = $db->prepare('SELECT 1 FROM tblLanguages WHERE Code = ? LIMIT 1');
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

/** One language row's Code/Name/NativeName/TextDirection/Scope/IsActive —
 *  used for update's before-diff capture and existence check. */
function languageAdminFetch(\mysqli $db, string $code): ?array
{
    $stmt = $db->prepare(
        'SELECT Code, Name, NativeName, TextDirection, Scope, IsActive
           FROM tblLanguages WHERE Code = ? LIMIT 1'
    );
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** Insert a new language row. Caller has already confirmed the Code isn't
 *  taken (languageAdminCodeExists()). */
function languageAdminCreate(
    \mysqli $db,
    string $code,
    string $name,
    string $native,
    string $textDir,
    string $scope,
    int $isActive
): void {
    $stmt = $db->prepare(
        'INSERT INTO tblLanguages (Code, Name, NativeName, TextDirection, Scope, IsActive)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('sssssi', $code, $name, $native, $textDir, $scope, $isActive);
    $stmt->execute();
    $stmt->close();
}

/** Write an existing language row's Name/NativeName/TextDirection/Scope/
 *  IsActive (Code is the PK — never rewritten; a "rename" is delete+create,
 *  matching the page's own read-only Code field in edit mode). */
function languageAdminUpdate(
    \mysqli $db,
    string $code,
    string $name,
    string $native,
    string $textDir,
    string $scope,
    int $isActive
): void {
    $stmt = $db->prepare(
        'UPDATE tblLanguages
            SET Name = ?, NativeName = ?, TextDirection = ?, Scope = ?, IsActive = ?
          WHERE Code = ?'
    );
    $stmt->bind_param('ssssis', $name, $native, $textDir, $scope, $isActive, $code);
    $stmt->execute();
    $stmt->close();
}

/** Cheap one-shot IsActive toggle — the table's per-row on/off switch and
 *  the API's `admin_language_toggle` action. @return int affected rows (0 =
 *  no such code, or the value already matched — caller decides which). */
function languageAdminToggleActive(\mysqli $db, string $code, int $isActive): int
{
    $stmt = $db->prepare('UPDATE tblLanguages SET IsActive = ? WHERE Code = ?');
    $stmt->bind_param('is', $isActive, $code);
    $stmt->execute();
    $touched = $stmt->affected_rows;
    $stmt->close();
    return $touched;
}

/**
 * Pre-flight cite count across `tblSongs.Language` and
 * `tblSongbooks.Language` — the delete confirmation count AND the
 * 409-requires-force gate (decided by the CALLER, not this file, mirroring
 * `tagAdminUsageCount()`/`tagAdminDelete()`'s split). The picker normalises
 * tags to lowercase on the BCP 47 primary subtag, so we match against that
 * prefix as well as the exact code so a row 'en' surfaces every 'en',
 * 'en-GB', 'en-US' that uses it.
 *
 * @return array{songs:int,songbooks:int}
 */
function languageAdminUsageCounts(\mysqli $db, string $code): array
{
    $likePrefix = $code . '-%';
    /* @deleted-visible: refuse-on-cite integrity count (#1694) — a
       soft-deleted song still cites the language and would come back
       citing it on restore, so it must keep blocking the delete.
       @disabled-visible: same reasoning, one predicate over (#1765) — a
       song/songbook in a disabled state still cites the language and must
       keep blocking the delete; disabled is reversible, same as
       soft-delete. */
    $stmt = $db->prepare(
        'SELECT
            (SELECT COUNT(*) FROM tblSongs     WHERE Language = ? OR Language LIKE ?) AS songs,
            (SELECT COUNT(*) FROM tblSongbooks WHERE Language = ? OR Language LIKE ?) AS songbooks'
    );
    $stmt->bind_param('ssss', $code, $likePrefix, $code, $likePrefix);
    $stmt->execute();
    $usage = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ['songs' => (int)($usage['songs'] ?? 0), 'songbooks' => (int)($usage['songbooks'] ?? 0)];
}

/** Delete a language row. Caller has already resolved the force-required
 *  409 via languageAdminUsageCounts(). @return int affected rows (0 = no
 *  such code — caller's 404). */
function languageAdminDelete(\mysqli $db, string $code): int
{
    $stmt = $db->prepare('DELETE FROM tblLanguages WHERE Code = ?');
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $deleted = $stmt->affected_rows;
    $stmt->close();
    return $deleted;
}

/**
 * BCP 47 registry plan §5.3 (M4) — the validate-then-confirm GLUE for a
 * remap request, extracted out of `manage/languages.php`'s `remap_tag`
 * action so `admin_language_remap_tag` makes the IDENTICAL 400/404/409
 * decisions from the SAME place (rule #22/#35). Never writes — the actual
 * remap is `languageTagRemap()` in `includes/language_tag_audit.php`
 * (already the ONE write core, reused here verbatim, never re-forked); this
 * function only decides whether that call is allowed to happen.
 *
 * Type-the-count confirm (the #1218 guard shape): a caller only reaches
 * `ok:true` once it has supplied the EXACT live usage total this function
 * independently recomputes right now (never trusting a client-cached
 * number) — a stale confirm surfaces `code:409` with the fresh total so the
 * caller can re-confirm, the same "surface the truth, make them re-confirm"
 * pattern `/manage/duplicate-songs` uses for a same-official-songbook merge.
 *
 * @param  int|string|null $confirmTotal Raw posted confirm value (unchecked type).
 * @return array{ok:bool,error:?string,code:int,liveTotal:?int}
 *         $code is the HTTP status the caller should answer with on
 *         !$ok (400/404/409); 200 on $ok (proceed to languageTagRemap()).
 */
function languageAdminRemapPreflight(\mysqli $db, string $fromTag, string $toTag, $confirmTotal): array
{
    $fromTag = trim($fromTag);
    $toTag   = trim($toTag);

    if ($fromTag === '' || $toTag === '') {
        return ['ok' => false, 'error' => 'from_tag and to_tag are both required.', 'code' => 400, 'liveTotal' => null];
    }
    if ($fromTag === $toTag) {
        return ['ok' => false, 'error' => 'to_tag is identical to from_tag — nothing to remap.', 'code' => 400, 'liveTotal' => null];
    }

    require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_importers.php'; /* _ietfBcp47Validate() — the ONE grammar check, rule #21 */
    if (_ietfBcp47Validate($toTag) === false) {
        return [
            'ok' => false,
            'error' => "'{$toTag}' is not a grammatically valid BCP 47 tag — the server would reject it everywhere else too.",
            'code' => 400, 'liveTotal' => null,
        ];
    }

    /* Recompute the LIVE total for $fromTag right now — a caller's own
       number may be stale by the time this request lands (another curator
       remapped it, or new data landed). */
    $liveRows  = languageTagAuditScan($db);
    $liveTotal = 0;
    foreach ($liveRows as $r) {
        if ($r['tag'] === $fromTag) { $liveTotal = $r['total']; break; }
    }
    if ($liveTotal === 0) {
        return [
            'ok' => false,
            'error' => "'{$fromTag}' no longer appears in the audit scan — it may already have been remapped.",
            'code' => 404, 'liveTotal' => 0,
        ];
    }
    if ($confirmTotal === null || (int)$confirmTotal !== $liveTotal) {
        return [
            'ok' => false,
            'error' => 'Confirm count does not match the current live usage — type the number shown and try again.',
            'code' => 409, 'liveTotal' => $liveTotal,
        ];
    }

    return ['ok' => true, 'error' => null, 'code' => 200, 'liveTotal' => $liveTotal];
}
