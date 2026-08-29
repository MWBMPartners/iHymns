<?php

declare(strict_types=1);

/**
 * iHymns — Content-restriction admin core (#2006, epic #2002)
 * ==============================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `tblContentRestrictions` is the table of "hide this song / require this
 * licence" rules a curator writes. This file is the ONE place that checks
 * a rule makes sense before it is saved, and the ONE place that actually
 * writes or deletes a row. `/manage/restrictions` (the human form), the
 * `admin_restriction_create` / `admin_restriction_delete` actions in
 * `api.php` (the native-app door), and the new guided content-gating
 * wizard on `/manage/gating` (#2006) all call THIS instead of each rolling
 * its own INSERT/DELETE.
 *
 * DETAILED / WHY THIS WAS EXTRACTED
 * ----------------------------------
 * Before #2006, `manage/restrictions.php` validated a rule inline (entity
 * type allow-list, non-empty entity id, restriction-type allow-list, the
 * #1769 P4 D10 `require_*` → `Effect='deny'` normalisation, effect
 * allow-list, priority range) and then ran its own `INSERT`/`DELETE`.
 * `api.php`'s `admin_restriction_create`/`_delete` ran a SECOND, separate
 * `INSERT`/`DELETE` with **no validation of any kind** — a pre-existing
 * rule-#22 fork this work makes visible (plan §11 OD-2; the two doors
 * could previously disagree about what counted as a valid rule). Both
 * doors now delegate to the SAME core here, so a curator posting through
 * the admin form and a native app posting through the API get IDENTICAL
 * validation, and there is exactly one place that writes this table
 * outside a migration (`tests/php/test-gating-wizard.php` assertion (k)
 * checks the census).
 *
 * The content-gating activation wizard (#2006) is a THIRD caller: its
 * optional "seed require_licence:ccli rows" step (step 3) plans a batch of
 * rows and asks THIS core to create each one — never a bespoke bulk
 * INSERT — so the wizard's rows are validated exactly the same way a
 * curator's hand-typed one would be.
 *
 * WHY VALIDATION LIVES HERE AND NOT ON EACH CALLER
 * --------------------------------------------------
 * A restriction rule that content_access.php's evaluator cannot honour is
 * worse than a rejected save — it is a rule that LOOKS live in the admin
 * list but silently does nothing (or, for `require_*` types, does
 * something subtly different from what its own Effect column claims — see
 * the #1769 P4 D10 note inline below). Centralising the check means that
 * fix, once made, protects every door at once.
 *
 * @see manage/restrictions.php   the human CRUD form — delegates create/delete here
 * @see appWeb/public_html/api.php  admin_restriction_create / admin_restriction_delete — the native-app door, same core
 * @see includes/gating_wizard.php  the content-gating wizard's optional row-seeding step
 * @see includes/content_access.php the evaluator these rows are FOR — never re-implement its rule model here
 * @see appWeb/.sql/schema.sql      tblContentRestrictions column semantics
 */

/* Direct-hit guard — library, never a page (mirrors licence_registry.php). */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';

/* =========================================================================
 * Allowed vocabularies — moved verbatim from manage/restrictions.php so the
 * ONE validator below and every caller read the SAME list. Kept tight so a
 * caller can't POST rubbish the evaluator will silently ignore (the
 * `content_access.php` header note on "an unrecognised RestrictionType is
 * ignored rather than fatal" — validating here stops that from ever
 * happening from a curator/wizard/API write, even though the evaluator
 * itself stays lenient by design for old data).
 * ========================================================================= */
const RESTRICTIONS_ENTITY_TYPES = ['song', 'songbook', 'feature'];
const RESTRICTIONS_TYPES = [
    'block_platform' => 'Block platform',
    'block_user'     => 'Block user',
    'block_org'      => 'Block organisation',
    'require_licence'=> 'Require licence',
    'require_org'    => 'Require organisation',
];
const RESTRICTIONS_EFFECTS = ['deny', 'allow'];

/**
 * Validate one restriction's raw fields against the vocabularies above.
 * PURE (no DB, no I/O) — every caller passes the same field NAMES whether
 * the source was a classic form POST (`manage/restrictions.php`), a JSON
 * body (`api.php`), or a wizard-generated plan row (`gating_wizard.php`).
 *
 * ELI5: checks a rule "makes sense" before anyone is allowed to save it.
 *
 * @param array<string,mixed> $in Raw input, keyed: entity_type, entity_id,
 *        restriction_type, target_type, target_id, effect, priority, reason.
 *        Values are cast with (string)/(int) so both a $_POST array (all
 *        strings) and a json_decode()'d body (already typed) work the same.
 * @return array{fields: ?array<string,mixed>, error: ?string}
 *         On success: `fields` = [entityType, entityId, restrictionType,
 *         targetType, targetId, effect, priority, reason] (camelCase,
 *         ready for restrictionAdminCreate()); `error` = null.
 *         On failure: `fields` = null; `error` = a human-readable message —
 *         the SAME wording `manage/restrictions.php` has always shown, so
 *         this extraction changes no visible copy.
 */
function restrictionAdminValidate(array $in): array
{
    $entityType      = trim((string)($in['entity_type']      ?? ''));
    $entityId        = trim((string)($in['entity_id']        ?? ''));
    $restrictionType = trim((string)($in['restriction_type'] ?? ''));
    $targetType      = trim((string)($in['target_type']      ?? ''));
    $targetId        = trim((string)($in['target_id']        ?? ''));
    $effect          = trim((string)($in['effect']           ?? 'deny'));
    $priority        = (int)($in['priority']                 ?? 0);
    $reason          = trim((string)($in['reason']           ?? ''));

    if (!in_array($entityType, RESTRICTIONS_ENTITY_TYPES, true)) {
        return ['fields' => null, 'error' => 'Invalid entity type.'];
    }
    if ($entityId === '') {
        return ['fields' => null, 'error' => 'Entity ID is required (use "*" to target every entity of the type).'];
    }
    if (!array_key_exists($restrictionType, RESTRICTIONS_TYPES)) {
        return ['fields' => null, 'error' => 'Invalid restriction type.'];
    }
    /* #1769 P4 Commit E (D10) — Effect HONESTY. content_access.php IGNORES
       Effect for every require_* type ("licence found -> pass; absent ->
       deny", never an allow/deny toggle). Storing Effect='allow' on such a
       row is a lie the engine can't honour, so normalise it to 'deny'
       server-side — ENGINE-DEAD (the value was already ignored), it just
       stops the row from claiming a policy it never had. Moved verbatim
       from manage/restrictions.php. */
    if (str_starts_with($restrictionType, 'require_')) {
        $effect = 'deny';
    }
    if (!in_array($effect, RESTRICTIONS_EFFECTS, true)) {
        return ['fields' => null, 'error' => 'Effect must be allow or deny.'];
    }
    if ($priority < 0 || $priority > 1000) {
        return ['fields' => null, 'error' => 'Priority must be between 0 and 1000.'];
    }

    return [
        'fields' => [
            'entityType'      => $entityType,
            'entityId'        => $entityId,
            'restrictionType' => $restrictionType,
            'targetType'      => $targetType,
            'targetId'        => $targetId,
            'effect'          => $effect,
            'priority'        => $priority,
            'reason'          => $reason,
        ],
        'error' => null,
    ];
}

/**
 * Insert ONE validated restriction row. Callers pass the `fields` array
 * `restrictionAdminValidate()` returned — never raw, unvalidated input.
 *
 * @param \mysqli $db
 * @param array{entityType:string,entityId:string,restrictionType:string,
 *              targetType:string,targetId:string,effect:string,
 *              priority:int,reason:string} $fields
 * @return int The new row's Id.
 */
function restrictionAdminCreate(\mysqli $db, array $fields): int
{
    $stmt = $db->prepare(
        'INSERT INTO tblContentRestrictions
            (EntityType, EntityId, RestrictionType, TargetType, TargetId, Effect, Priority, Reason)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    /* Types: six string columns, then Priority(int), then Reason(string) —
       byte-identical bind shape to the pre-#2006 inline INSERT. */
    $stmt->bind_param(
        'ssssssis',
        $fields['entityType'],
        $fields['entityId'],
        $fields['restrictionType'],
        $fields['targetType'],
        $fields['targetId'],
        $fields['effect'],
        $fields['priority'],
        $fields['reason']
    );
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();
    return $id;
}

/**
 * Delete ONE restriction row by Id. Byte-identical to the pre-#2006 inline
 * DELETE both `manage/restrictions.php` and `api.php` ran separately.
 *
 * @param \mysqli $db
 * @param int     $id
 * @return void
 */
function restrictionAdminDelete(\mysqli $db, int $id): void
{
    $stmt = $db->prepare('DELETE FROM tblContentRestrictions WHERE Id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
}
