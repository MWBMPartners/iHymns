<?php

declare(strict_types=1);

/**
 * iHymns — Licence-type admin CRUD shared core (#459 / #1769 P4)
 * =============================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `/manage/licence-types` (the web admin page) and `admin_licence_type_*` (the
 * native-app API in `api.php`) both need to do the SAME things to a licence
 * type: check its fields are sane, save/create it, toggle it enabled, delete it,
 * and count what references it before a delete. This file is the ONE place each
 * of those is written — both surfaces call these functions instead of re-typing
 * the logic (rule #22/#35, the `includes/tune_admin.php` precedent).
 *
 * DETAILED / DORMANT + LIVE-EFFECT HONESTY
 * ----------------------------------------
 * `tblLicenceTypes` shipped in #1769 P1 (seeded + read by `licence_registry.php`);
 * P4 gives curators a write path. Every function is pure-PHP-plus-`\mysqli` (no
 * `$_POST`/`$_SERVER`/`json_decode()` reads), so a form-POST caller and a
 * JSON-body caller both normalise into the same plain-array shape first. The
 * field-array keys mirror the page's POST names: `key`, `label`, `description`,
 * `confers_tier`, `covers` (array of coverage-kind slugs), `requires_number`,
 * `authority`, `authority_ref`, `enabled`, `sort_order`.
 *
 * ⚠ LIVE EFFECT even with content gating OFF: since #1769 P2-F,
 * `resolveEffectiveTier()` overlays a licence's `ConfersTier` onto the tier it
 * grants a member's org, and that runs regardless of the master switch. So an
 * edit to `ConfersTier`/`Enabled` changes members' effective tiers immediately
 * (the same class as editing a tier's caps on `/manage/tiers` today). The page
 * surfaces this in copy + reference counts; this core never hides or blocks it.
 *
 * Direct access is blocked (same guard as the other shared cores) so this file
 * can't be requested as an endpoint via an open Apache config.
 *
 * @link .claude/gating-p4-design.md  Commit A
 * @link appWeb/public_html/manage/licence-types.php  page consumer
 * @link appWeb/public_html/api.php  admin_licence_type_* API consumer
 * @link appWeb/public_html/includes/licence_registry.php  the ONE reader (licenceKeyValid / licenceCoverageKindValid / licenceTypesTableExists / LICENCE_COVERAGE_KINDS)
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'licence_registry.php';

/**
 * Memoised INFORMATION_SCHEMA column probe (rule #9: mysqli STRICT throws on a
 * missing column, and migrations are web-run). Any failure ⇒ false.
 */
function _ltaColumnExists(\mysqli $db, string $table, string $col): bool
{
    static $cache = [];
    $k = $table . '.' . $col;
    if (array_key_exists($k, $cache)) { return $cache[$k]; }
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
        );
        $stmt->bind_param('ss', $table, $col);
        $stmt->execute();
        $cache[$k] = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    } catch (\Throwable $_e) {
        $cache[$k] = false;
    }
    return $cache[$k];
}

/** Memoised INFORMATION_SCHEMA table probe. Any failure ⇒ false. */
function _ltaTableExists(\mysqli $db, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) { return $cache[$table]; }
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"
        );
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $cache[$table] = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    } catch (\Throwable $_e) {
        $cache[$table] = false;
    }
    return $cache[$table];
}

/**
 * Is the tblLicenceTypes table present? Reuses the registry's probe — never a
 * re-fork (rule #22).
 *
 * @return array{hasTable:bool}
 */
function licenceTypeAdminGates(\mysqli $db): array
{
    return ['hasTable' => licenceTypesTableExists($db)];
}

/**
 * Validate + normalise a licence-type field array. Returns the errors (empty on
 * success) and a `norm` array ready to bind. `LicenceKey` is IMMUTABLE on update
 * (too many stores reference it — the tier-Name precedent), so it is validated
 * only on create.
 *
 * @param array $f        Field array (page POST names / API JSON keys).
 * @param bool  $isCreate True for create (validates + requires the key).
 * @return array{errors: string[], norm: array}
 */
function licenceTypeAdminValidate(\mysqli $db, array $f, bool $isCreate): array
{
    $errors = [];
    $norm   = [];

    if ($isCreate) {
        $key = strtolower(trim((string)($f['key'] ?? '')));
        if ($key === '' || !licenceKeyValid($key)) {
            $errors[] = 'Key must be lowercase, start with a letter, and be 2–30 chars of a-z 0-9 _.';
        }
        $norm['key'] = $key;
    }

    $label = trim((string)($f['label'] ?? ''));
    if ($label === '') { $errors[] = 'Label is required.'; }
    elseif (mb_strlen($label) > 100) { $errors[] = 'Label must be 100 characters or fewer.'; }
    $norm['label'] = $label;

    $description = trim((string)($f['description'] ?? ''));
    if (mb_strlen($description) > 255) { $errors[] = 'Description must be 255 characters or fewer.'; }
    $norm['description'] = $description;

    /* ConfersTier: '' → NULL, else must be a live tblAccessTiers.Name (the OV-10
       live-table posture — never a hardcoded tier list). */
    $confers = trim((string)($f['confers_tier'] ?? ''));
    if ($confers === '') {
        $norm['confers_tier'] = null;
    } else {
        $ok = false;
        try {
            $stmt = $db->prepare('SELECT 1 FROM tblAccessTiers WHERE Name = ? LIMIT 1');
            $stmt->bind_param('s', $confers);
            $stmt->execute();
            $ok = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
        } catch (\Throwable $_e) {
            $ok = false; /* degrade-safe — an unresolvable tier is rejected */
        }
        if (!$ok) { $errors[] = "Confers-tier '{$confers}' is not a defined access tier."; }
        $norm['confers_tier'] = $confers;
    }

    /* Covers: each ticked kind must be an allow-listed coverage kind. */
    $ticked = is_array($f['covers'] ?? null) ? $f['covers'] : [];
    foreach ($ticked as $ck) {
        if (!licenceCoverageKindValid((string)$ck)) {
            $errors[] = "Unknown coverage kind '" . (string)$ck . "'.";
        }
    }
    $norm['covers'] = array_values(array_filter(array_map('strval', $ticked), 'licenceCoverageKindValid'));

    $authority = trim((string)($f['authority'] ?? ''));
    if ($authority !== '' && !preg_match('/^[a-z][a-z0-9_]{0,29}$/', $authority)) {
        $errors[] = 'Authority must be a lowercase token (≤30 chars) or blank.';
    }
    $norm['authority']     = $authority === '' ? null : $authority;
    $authorityRef          = trim((string)($f['authority_ref'] ?? ''));
    $norm['authority_ref'] = $authorityRef === '' ? null : mb_substr($authorityRef, 0, 50);

    $norm['requires_number'] = !empty($f['requires_number']) ? 1 : 0;
    $norm['enabled']         = array_key_exists('enabled', $f) ? (!empty($f['enabled']) ? 1 : 0) : 1;
    $norm['sort_order']      = max(0, (int)($f['sort_order'] ?? 0));

    return ['errors' => $errors, 'norm' => $norm];
}

/**
 * Build a CoversJson string from the ticked coverage kinds, PRESERVING the
 * per-kind qualifier objects on kinds that stay ticked (the reserved
 * object-of-objects shape must survive a round-trip through the checkbox editor).
 * A new kind gets `{}`; no ticked kinds ⇒ NULL.
 *
 * A stored `{}` decodes (with assoc=true) to an EMPTY PHP array, and
 * json_encode([]) is `[]` not `{}` — so a naive preserve would silently flip an
 * unqualified object to an array on the first round-trip. We therefore normalise
 * an empty / absent qualifier to `new \stdClass()` (→ `{}`) and preserve only a
 * NON-empty qualifier object, keeping the stored shape stable across edits.
 *
 * @param ?array   $existing    The row's decoded covers map (kind => qualifier), or null.
 * @param string[] $tickedKinds The coverage kinds ticked in the editor.
 * @return ?string JSON, or null when nothing is covered.
 */
function licenceTypeAdminCoversMerge(?array $existing, array $tickedKinds): ?string
{
    $out = [];
    foreach ($tickedKinds as $k) {
        $k = (string)$k;
        if (!licenceCoverageKindValid($k)) { continue; }
        $q = (is_array($existing) && isset($existing[$k]) && is_array($existing[$k]) && $existing[$k] !== [])
            ? $existing[$k]        /* real qualifier object — preserve verbatim */
            : new \stdClass();     /* new or unqualified kind — {} (never []) */
        $out[$k] = $q;
    }
    if ($out === []) { return null; }
    return (string)json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Create a curator-defined licence type. Returns the new Id.
 * @throws \RuntimeException on a duplicate key.
 */
function licenceTypeAdminCreate(\mysqli $db, array $norm, ?int $actorUserId): int
{
    /* Duplicate-key pre-check (friendlier than the UNIQUE throw). */
    $stmt = $db->prepare('SELECT 1 FROM tblLicenceTypes WHERE LicenceKey = ? LIMIT 1');
    $stmt->bind_param('s', $norm['key']);
    $stmt->execute();
    $dup = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    if ($dup) { throw new \RuntimeException("A licence type with key '{$norm['key']}' already exists."); }

    $covers = licenceTypeAdminCoversMerge(null, $norm['covers']);
    $stmt = $db->prepare(
        'INSERT INTO tblLicenceTypes
            (LicenceKey, Label, Description, ConfersTier, CoversJson, RequiresNumber,
             Authority, AuthorityRef, Enabled, SortOrder, Source, CreatedBy, UpdatedBy, CreatedAt, UpdatedAt)
         VALUES (?,?,?,?,?,?,?,?,?,?,\'curator\',?,?,NOW(),NOW())'
    );
    $stmt->bind_param(
        'sssssissiiii',
        $norm['key'], $norm['label'], $norm['description'], $norm['confers_tier'], $covers,
        $norm['requires_number'], $norm['authority'], $norm['authority_ref'],
        $norm['enabled'], $norm['sort_order'], $actorUserId, $actorUserId
    );
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();
    return $id;
}

/**
 * Update an existing licence type by Id. LicenceKey + Source are NOT changed.
 * Re-reads the row's existing CoversJson so qualifier objects survive.
 */
function licenceTypeAdminUpdate(\mysqli $db, int $id, array $norm, ?int $actorUserId): void
{
    $existingCovers = null;
    $stmt = $db->prepare('SELECT CoversJson FROM tblLicenceTypes WHERE Id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row && $row['CoversJson'] !== null && $row['CoversJson'] !== '') {
        $dec = json_decode((string)$row['CoversJson'], true);
        if (is_array($dec)) { $existingCovers = $dec; }
    }
    $covers = licenceTypeAdminCoversMerge($existingCovers, $norm['covers']);

    $stmt = $db->prepare(
        'UPDATE tblLicenceTypes
            SET Label = ?, Description = ?, ConfersTier = ?, CoversJson = ?, RequiresNumber = ?,
                Authority = ?, AuthorityRef = ?, Enabled = ?, SortOrder = ?, UpdatedBy = ?, UpdatedAt = NOW()
          WHERE Id = ?'
    );
    $stmt->bind_param(
        'ssssissiiii',
        $norm['label'], $norm['description'], $norm['confers_tier'], $covers, $norm['requires_number'],
        $norm['authority'], $norm['authority_ref'], $norm['enabled'], $norm['sort_order'], $actorUserId, $id
    );
    $stmt->execute();
    $stmt->close();
}

/** Toggle Enabled 0↔1 for a licence type. Returns the new state. */
function licenceTypeAdminToggle(\mysqli $db, int $id, ?int $actorUserId): int
{
    $stmt = $db->prepare(
        'UPDATE tblLicenceTypes SET Enabled = 1 - Enabled, UpdatedBy = ?, UpdatedAt = NOW() WHERE Id = ?'
    );
    $stmt->bind_param('ii', $actorUserId, $id);
    $stmt->execute();
    $stmt->close();
    $stmt = $db->prepare('SELECT Enabled FROM tblLicenceTypes WHERE Id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['Enabled'] ?? 0);
}

/**
 * Count everything that references a licence key across the stores that use it.
 * Each read is existence-gated (rule #9). Used by the delete policy + the page.
 *
 * @return array<string,int> keyed by a human store label.
 */
function licenceTypeAdminReferenceCounts(\mysqli $db, string $key): array
{
    $counts = [];
    $countWhere = static function (\mysqli $db, string $sql, string $val) : int {
        try {
            $stmt = $db->prepare($sql);
            $stmt->bind_param('s', $val);
            $stmt->execute();
            $n = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
            $stmt->close();
            return $n;
        } catch (\Throwable $_e) { return 0; }
    };

    if (_ltaColumnExists($db, 'tblOrganisations', 'LicenceType')) {
        $counts['Organisations (primary licence)'] = $countWhere($db, 'SELECT COUNT(*) FROM tblOrganisations WHERE LicenceType = ?', $key);
    }
    if (_ltaTableExists($db, 'tblOrganisationLicences')) {
        $counts['Organisation licences'] = $countWhere($db, 'SELECT COUNT(*) FROM tblOrganisationLicences WHERE LicenceType = ?', $key);
    }
    if (_ltaTableExists($db, 'tblContentLicences')) {
        $counts['Content licences'] = $countWhere($db, 'SELECT COUNT(*) FROM tblContentLicences WHERE LicenceType = ?', $key);
    }
    if (_ltaTableExists($db, 'tblContentRestrictions') && _ltaColumnExists($db, 'tblContentRestrictions', 'TargetId')) {
        $counts['require_licence restrictions'] = $countWhere($db, "SELECT COUNT(*) FROM tblContentRestrictions WHERE RestrictionType = 'require_licence' AND TargetId = ?", $key);
    }
    /* @deleted-visible: FK pre-check (#1694 / #1769 P4) — a soft-deleted song
       still carries its rights-fact licence key and can be restored losslessly,
       so deleting a licence type it references would orphan the restored row.
       Hidden rows MUST be counted here; the delete-refusal must see them.
       @disabled-visible: same reasoning for #1765 — a song in a DISABLED songbook
       still references the licence key, so the delete-safety count must include it
       (an admin FK pre-check, never a public read). */
    if (_ltaColumnExists($db, 'tblSongs', 'LyricsRightsLicenceKey')) {
        $counts['Songs (lyrics rights fact)'] = $countWhere($db, 'SELECT COUNT(*) FROM tblSongs WHERE LyricsRightsLicenceKey = ?', $key);
    }
    if (_ltaColumnExists($db, 'tblSongs', 'MusicRightsLicenceKey')) {
        $counts['Songs (music rights fact)'] = $countWhere($db, 'SELECT COUNT(*) FROM tblSongs WHERE MusicRightsLicenceKey = ?', $key);
    }
    if (_ltaColumnExists($db, 'tblSongbooks', 'DefaultLyricsRightsLicenceKey')) {
        $counts['Songbook lyrics default'] = $countWhere($db, 'SELECT COUNT(*) FROM tblSongbooks WHERE DefaultLyricsRightsLicenceKey = ?', $key);
    }
    if (_ltaColumnExists($db, 'tblSongbooks', 'DefaultMusicRightsLicenceKey')) {
        $counts['Songbook music default'] = $countWhere($db, 'SELECT COUNT(*) FROM tblSongbooks WHERE DefaultMusicRightsLicenceKey = ?', $key);
    }
    return $counts;
}

/**
 * Delete a licence type by Id. REFUSES (throws) when the row is a system seed
 * (Source='system' — toggle Enabled instead) or when anything references its
 * key (the Enabled column's app-enforced-retire promise).
 *
 * @throws \RuntimeException with a human reason when the delete is refused.
 */
function licenceTypeAdminDelete(\mysqli $db, int $id): void
{
    $stmt = $db->prepare('SELECT LicenceKey, Source FROM tblLicenceTypes WHERE Id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) { throw new \RuntimeException('Licence type not found.'); }
    if ((string)$row['Source'] === 'system') {
        throw new \RuntimeException('System licence types cannot be deleted — disable it instead.');
    }
    $refs = array_filter(licenceTypeAdminReferenceCounts($db, (string)$row['LicenceKey']));
    if ($refs !== []) {
        $parts = [];
        foreach ($refs as $label => $n) { $parts[] = "{$n} × {$label}"; }
        throw new \RuntimeException('Still referenced by: ' . implode(', ', $parts) . ' — disable it instead of deleting.');
    }
    $stmt = $db->prepare('DELETE FROM tblLicenceTypes WHERE Id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
}
