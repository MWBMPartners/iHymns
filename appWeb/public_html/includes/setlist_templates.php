<?php

declare(strict_types=1);

/**
 * setlist_templates.php — the ONE service-plan ("slots") model (#301 / #1671 F4)
 * ============================================================================
 *
 * ELI5
 * ----
 * A worship leader's service usually has a SHAPE before it has songs: opening,
 * two praise items, a reading, the sermon, a closing. That shape is a
 * "template". Applying one to a set list gives you a labelled running order
 * with empty spaces you then drop songs into. This file is the one place that
 * says what a slot is, what a plan is, and how big either may get.
 *
 * WHY THIS FILE EXISTS AT ALL — the #1671 Batch-8 refusal
 * ------------------------------------------------------
 * `setlist_templates` / `setlist_template_save` shipped with #301 and have
 * never had a caller. Batch 8 (commit 2f0d9445) wired three sibling orphans to
 * real screens and DELIBERATELY REFUSED this one, for two reasons that were
 * both correct and are both fixed here:
 *
 *   1. **A template's whole payload is its slots, and there was nowhere to put
 *      them.** `user_setlists_sync` persisted SetlistId / Name / SongsJson /
 *      CreatedAt / UpdatedAt / ExpiresAt and nothing else, and
 *      `setlistCollabSanitiseSongs()` keeps only id/title/songbook/number/
 *      arrangement per entry. A slot carried into a set list would round-trip
 *      to the server and come back GONE — for signed-in users only, because
 *      signed-out users never sync. Silent data loss on sync is the exact class
 *      #1649 / #1662 / #1661 exist to remove, so shipping a UI on top of it
 *      would have been shipping the bug.
 *   2. **There was no `setlist_template_delete` or `_update`,** so every
 *      template — including one created with `is_public: true` and therefore
 *      visible to every user of the app — was permanent and uneditable from the
 *      screen that made it.
 *
 * Both are server problems, so both are solved server-first: this module plus
 * `appWeb/.sql/migrate-setlist-slots.php` (the `tblUserSetlists.SlotsJson`
 * column) plus the two new endpoints in `api.php`.
 *
 * THE STORAGE DECISION, AND THE ONE THAT WAS REJECTED
 * ---------------------------------------------------
 * A set list's plan is ONE new nullable JSON column, `tblUserSetlists.SlotsJson`,
 * holding an ENVELOPE:
 *
 *     { "templateId": 3, "templateName": "Sunday Service", "slots": [ … ] }
 *
 * Rejected alternative: a `TemplateId INT UNSIGNED NULL` column with a real
 * foreign key to `tblSetlistTemplates(Id)`. It looks more "correct" and is
 * worse on every axis that matters here:
 *
 *   - `ON DELETE SET NULL` means deleting a template silently erases the
 *     provenance of every set list built from it. The envelope's
 *     `templateName` is a SNAPSHOT, so "based on Sunday Service" survives the
 *     template being deleted, renamed or made private. That is strictly more
 *     information, not less.
 *   - A FK forces the sync path to prove every incoming id exists before it can
 *     bind it, or eat a 1452 under `MYSQLI_REPORT_STRICT` — an extra query on
 *     the single most damage-prone loop in this codebase.
 *   - Growth (per-slot duration, a "required" flag, a colour) lands inside the
 *     JSON with no second migration, which is what rule #20 asks for. A
 *     column-per-concept design guarantees the incremental `ALTER` rule #20
 *     forbids.
 *
 * CAPS ARE REJECTIONS, NEVER TRUNCATIONS
 * --------------------------------------
 * `setlistTemplateSlotsExceedCap()` exists so a caller can answer **413** and
 * leave the stored value untouched. Note what this file deliberately does NOT
 * do: it has no `array_slice()`. The pre-existing `setlist_template_save`
 * handler did (`array_slice($tplSlots, 0, 50)`), which is the same shape that
 * amputated users' set lists in #1649 and their 201st song in #1662 — the
 * server quietly stored a shortened version and then treated the shortened
 * version as the truth. A refused write is loud, retryable and lossless.
 *
 * SLOT TYPE IS A GROWABLE VOCABULARY, SO IT IS FREE TEXT WITH A SUGGESTION MAP
 * ---------------------------------------------------------------------------
 * `setlistTemplateSlotTypes()` is the ONE registry a UI reads to build its
 * picker — adding "Baptism" is one line there and nothing else, the same
 * one-line-registry shape as `TIER_CAPS` (rule #28) and
 * `userSyncTombstoneReasons()` (rule #20). But `setlistTemplateNormaliseType()`
 * does NOT reject an unknown value: the endpoint has been publicly documented
 * as accepting a free 50-character `type` since #301, and narrowing a shipped
 * contract to a closed list would 400 an out-of-repo caller that has been
 * correct all along. Known values are canonicalised (case-folded to the
 * registry key); unknown ones are trimmed and kept.
 *
 * EVERYTHING EXCEPT THE SCHEMA GATE IS PURE
 * -----------------------------------------
 * No database, no superglobals, no I/O — so `tests/php/test-setlist-templates.php`
 * CALLS these functions and asserts what they return, rather than grepping this
 * file for the right words. That is the standing lesson of
 * `tests/php/test-transaction-fatal.php`: a predicate guarded by source
 * inspection kept its vocabulary, lost its behaviour, and the suite stayed
 * green. Round-tripping a plan through the real sanitiser and asserting nothing
 * was lost is the only evidence that the #1671 F4 blocker is actually gone.
 *
 * @see appWeb/.sql/migrate-setlist-slots.php   the ONE migration
 * @see appWeb/public_html/api.php              the four endpoints that consume this
 * @see appWeb/public_html/includes/user_sync.php  the sibling sync-guard module
 * @see tests/php/test-setlist-templates.php    the behavioural lock
 * @link https://dev.mysql.com/doc/refman/8.0/en/json.html
 * @link https://www.php.net/manual/en/function.mb-substr.php
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

/* Direct access blocked so this can't be loaded as an endpoint. */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * Maximum number of slots in ONE plan (a template's, or a set list's).
 *
 * ELI5: a service order longer than this is almost certainly a mistake, and we
 * say so rather than quietly binning the tail.
 *
 * 100 is double the 50 the original `setlist_template_save` silently sliced at,
 * because raising a limit is safe and lowering one is not — and no real service
 * order approaches either. A FUNCTION rather than a `const` so this file stays
 * double-include-safe (a bare `const` at file scope fatals on a second
 * `require`, and this module is required from both `api.php` and the CLI test).
 */
function setlistTemplateMaxSlots(): int
{
    return 100;
}

/**
 * The suggested slot-type vocabulary — key => human label.
 *
 * ELI5: the list of "what kind of thing is this bit of the service".
 *
 * ONE registry. A UI builds its picker from this and nothing else, so adding a
 * type is one line here — no schema change, no second map to keep in step
 * (rule #35: a mechanism, not a comment). Keys are lower-case ASCII because
 * `setlistTemplateNormaliseType()` case-folds before matching, so a client
 * sending "Song", "SONG" or "song" all canonicalise to the same stored value
 * and a plan can never contain two slots that differ only by case.
 *
 * ⚠ This is a SUGGESTION list, not an allow-list — see the file header for why
 * an unknown type is kept rather than rejected.
 *
 * @return array<string,string>
 */
function setlistTemplateSlotTypes(): array
{
    return [
        'song'         => 'Song',
        'reading'      => 'Scripture reading',
        'prayer'       => 'Prayer',
        'offering'     => 'Offering',
        'communion'    => 'Communion',
        'sermon'       => 'Sermon / message',
        'testimony'    => 'Testimony',
        'announcement' => 'Announcements',
        'welcome'      => 'Welcome',
        'benediction'  => 'Benediction / sending',
        'other'        => 'Other',
    ];
}

/**
 * Canonicalise a slot `type`.
 *
 * ELI5: tidy up what kind of slot this is; if we recognise the word, use our
 * spelling of it, and if we don't, keep theirs.
 *
 * Empty / non-string input becomes `'song'` — the overwhelmingly common case,
 * and a slot with no type at all would render as an unlabelled row.
 *
 * @param mixed $raw
 */
function setlistTemplateNormaliseType($raw): string
{
    if (!is_string($raw) && !is_int($raw)) {
        return 'song';
    }
    $t = trim((string)$raw);
    if ($t === '') {
        return 'song';
    }
    $folded = mb_strtolower($t);
    if (array_key_exists($folded, setlistTemplateSlotTypes())) {
        return $folded;
    }
    /* Unknown but non-empty: keep it, bounded to the column's own width. The
       template table declares SlotsJson as JSON, so the 50 here is the shipped
       #301 contract rather than a storage constraint. */
    return mb_substr($t, 0, 50);
}

/**
 * Normalise a client-generated slot id to the stored shape.
 *
 * ELI5: keep only letters, digits, underscores and hyphens.
 *
 * Deliberately the SAME charset as `userSyncSanitiseId()` applies to a set-list
 * id, because these two ids travel together through the same payload and a
 * reader who has learned one rule should not have to learn a second. Returns
 * `''` when nothing usable survives, which the caller replaces with a
 * position-derived fallback.
 *
 * @param mixed $raw
 */
function setlistTemplateSanitiseSlotId($raw): string
{
    if (!is_string($raw) && !is_int($raw)) {
        return '';
    }
    return mb_substr((string)preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$raw), 0, 40);
}

/**
 * Would this slots payload be over the cap?
 *
 * ELI5: is the running order longer than we accept?
 *
 * Answered SEPARATELY from sanitising so the caller can respond 413 and store
 * nothing, rather than storing a shortened plan. See the file header: this
 * module owns no `array_slice`, on purpose.
 *
 * A non-array (null, a string, a number from a confused client) is NOT over
 * cap — it sanitises to the empty plan, which is a legitimate "this set list
 * has no service order" and must not 413.
 *
 * @param mixed    $raw
 * @param int|null $max Override for tests; defaults to setlistTemplateMaxSlots().
 */
function setlistTemplateSlotsExceedCap($raw, ?int $max = null): bool
{
    return is_array($raw) && count($raw) > ($max ?? setlistTemplateMaxSlots());
}

/**
 * Sanitise a client-supplied slots array into the ONE stored shape.
 *
 * ELI5: keep only the five things a slot is allowed to say, trim them to fit,
 * and throw away anything else.
 *
 * The stored shape is:
 *   id     — stable, client-generated, used only to key DOM rows and to survive
 *            a reorder. Falls back to `'s' . $index` when the client sent none;
 *            that is position-derived and therefore NOT stable across an
 *            insertion, which is exactly why a real client mints its own.
 *   label  — REQUIRED. A slot with no label is dropped, mirroring the original
 *            `setlist_template_save`'s `empty($slot['label'])` skip, because an
 *            unlabelled slot is indistinguishable from a rendering bug.
 *   type   — see setlistTemplateNormaliseType().
 *   songId — OPTIONAL. Present only on a SET LIST's plan (a template describes
 *            a shape, not a song choice). Absent/blank means "not filled in
 *            yet", which is the normal state of a freshly applied template.
 *   note   — OPTIONAL free text for the leader ("verse 1 only", "key of G").
 *
 * ⚠ THE OPTIONAL KEYS ARE OMITTED WHEN EMPTY, NOT SET TO NULL. A plan that
 * round-trips must compare equal to itself, and `{"songId": null}` versus no
 * `songId` key at all is a difference a client's dirty-check would see on every
 * load — producing an endless "you have unsaved changes" that nothing explains.
 *
 * @param mixed $raw The `slots` array straight off a decoded request body.
 * @return array<int,array<string,mixed>> Clean slots, re-indexed from 0.
 */
function setlistTemplateSanitiseSlots($raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $clean = [];
    $index = 0;
    foreach ($raw as $slot) {
        $index++;
        if (!is_array($slot)) {
            continue;
        }
        $label = is_string($slot['label'] ?? null) || is_int($slot['label'] ?? null)
            ? trim((string)$slot['label'])
            : '';
        if ($label === '') {
            continue;   /* an unlabelled slot cannot be rendered meaningfully */
        }

        $id = setlistTemplateSanitiseSlotId($slot['id'] ?? '');
        $entry = [
            'id'    => $id !== '' ? $id : ('s' . $index),
            'label' => mb_substr($label, 0, 100),
            'type'  => setlistTemplateNormaliseType($slot['type'] ?? null),
        ];

        /* SongId charset matches the SongId prefix grammar rule #27 pins
           (`<letters>-<digits>`) only loosely on purpose: this is a pointer
           into the set list's own `songs` array, whose ids are whatever the
           catalogue holds, and inventing a stricter rule here would silently
           unassign a legitimately-keyed song. Bounded, not parsed. */
        $songId = is_string($slot['songId'] ?? null) || is_int($slot['songId'] ?? null)
            ? trim((string)$slot['songId'])
            : '';
        if ($songId !== '') {
            $entry['songId'] = mb_substr($songId, 0, 100);
        }

        $note = is_string($slot['note'] ?? null) ? trim($slot['note']) : '';
        if ($note !== '') {
            $entry['note'] = mb_substr($note, 0, 500);
        }

        $clean[] = $entry;
    }
    return $clean;
}

/**
 * Sanitise a whole set-list PLAN envelope, or decide there isn't one.
 *
 * ELI5: tidy up "which template this came from, and what the running order is".
 * If there is no running order, there is no plan — say so with null.
 *
 * Returns `null` for "this set list has no service plan", which is what gets
 * stored in the nullable column. Note the asymmetry that makes clearing work:
 * a caller that sends `{"slots": []}` gets `null` back, i.e. sending an empty
 * plan CLEARS the plan. That is the same "what you POST is what you GET" rule
 * `userSettingsMergeNamespace()` follows, and it is chosen over "an empty array
 * preserves the old plan" because the latter is the `isset()` clear-semantics
 * trap this codebase keeps paying for — a value nothing can ever delete.
 *
 * Accepts BOTH shapes a client might reasonably send:
 *   - the envelope `{templateId, templateName, slots}`;
 *   - a bare `[ …slots… ]` list, which is what `tblSetlistTemplates.SlotsJson`
 *     itself stores, so "apply this template" can hand its own value straight
 *     through without a wrapper step.
 *
 * @param mixed $raw
 * @return array{templateId:?int,templateName:?string,slots:array}|null
 */
function setlistTemplateSanitisePlan($raw): ?array
{
    if (!is_array($raw)) {
        return null;
    }

    /* A bare list of slots (no envelope keys) is the template table's own
       shape — accepted so "apply template" needs no wrapper. array_is_list()
       distinguishes it from an envelope without guessing. */
    $slotsRaw = array_is_list($raw) ? $raw : ($raw['slots'] ?? null);

    $slots = setlistTemplateSanitiseSlots($slotsRaw);
    if ($slots === []) {
        return null;   /* no slots = no plan; see this function's docblock */
    }

    $templateId = null;
    if (!array_is_list($raw) && isset($raw['templateId']) && is_numeric($raw['templateId'])) {
        $candidate = (int)$raw['templateId'];
        /* Advisory provenance only — never a foreign key (see the file
           header), so a nonsense id is dropped rather than 400-ing a save the
           user cares about. Zero and negatives cannot be an AUTO_INCREMENT id. */
        $templateId = $candidate > 0 ? $candidate : null;
    }

    $templateName = null;
    if (!array_is_list($raw) && is_string($raw['templateName'] ?? null)) {
        $trimmed = trim($raw['templateName']);
        if ($trimmed !== '') {
            $templateName = mb_substr($trimmed, 0, 200);   /* tblSetlistTemplates.Name width */
        }
    }

    return [
        'templateId'   => $templateId,
        'templateName' => $templateName,
        'slots'        => $slots,
    ];
}

/**
 * Would this plan payload be over the slot cap?
 *
 * ELI5: same question as setlistTemplateSlotsExceedCap(), asked of the whole
 * envelope instead of a bare list.
 *
 * Exists so the sync handler can ask ONE question per set list regardless of
 * which of the two accepted shapes the client sent. Getting this wrong in the
 * quiet direction would mean a 101-slot plan silently losing its tail, which is
 * the failure this module was written to prevent.
 *
 * @param mixed    $raw
 * @param int|null $max Override for tests.
 */
function setlistTemplatePlanExceedsCap($raw, ?int $max = null): bool
{
    if (!is_array($raw)) {
        return false;
    }
    $slotsRaw = array_is_list($raw) ? $raw : ($raw['slots'] ?? null);
    return setlistTemplateSlotsExceedCap($slotsRaw, $max);
}

/**
 * Encode a sanitised plan for storage, or `null` to clear the column.
 *
 * ELI5: turn the plan into the text we save; "no plan" saves as nothing at all.
 *
 * ONE encoder so the bytes measured, stored and read back are the same bytes —
 * the same reasoning as `userSettingsEncode()`. Flags match every other JSON
 * column written by `api.php`.
 *
 * @param array|null $plan Output of setlistTemplateSanitisePlan().
 */
function setlistTemplateEncodePlan(?array $plan): ?string
{
    if ($plan === null) {
        return null;
    }
    return (string)json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Decode a stored plan back into the response shape.
 *
 * ELI5: read the saved plan; if there isn't one, or it is unreadable, say
 * "no plan" rather than exploding.
 *
 * A NULL column, an empty string, or JSON that no longer parses all become
 * `null`. Failing that way round is deliberate: a set list whose plan cannot be
 * read must still LOAD — the songs are the irreplaceable part, the plan is
 * re-appliable from its template.
 *
 * @param string|null $json Raw column value.
 * @return array{templateId:?int,templateName:?string,slots:array}|null
 */
function setlistTemplateDecodePlan(?string $json): ?array
{
    if ($json === null || trim($json) === '') {
        return null;
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return null;
    }
    /* Re-sanitised on the way OUT as well as IN. Belt and braces, and it is not
       paranoia: this column is shared with any future writer (iLyricsDB, a
       native client, a restored backup) and a reader that trusts its own
       storage is a reader that hands a malformed slot to a renderer. */
    return setlistTemplateSanitisePlan($decoded);
}

/**
 * May this user edit or delete this template?
 *
 * ELI5: you can change your own templates. Nobody else's.
 *
 * PURE so it can be asserted directly — the whole point of extracting an
 * authorisation decision is that a test can ask it questions rather than
 * grepping the handler for the word "CreatedBy".
 *
 * OWNER-ONLY, deliberately, and NOT "any member of the template's org":
 * `setlist_templates` returns org templates to every MEMBER, so org membership
 * is a VISIBILITY grant. Reusing it as an EDIT grant would silently give every
 * member of a church destructive power over a colleague's template — a
 * privilege expansion nobody asked for, invented by a reader who assumed the
 * two questions were one question.
 *
 * ⚠ KNOWN, DOCUMENTED GAP. `fk_Template_User` is `ON DELETE SET NULL`, so a
 * template outlives its author with `CreatedBy = NULL` and is then editable by
 * nobody. A public one in that state can only be removed with direct database
 * access. The alternative — an admin override — means minting a new
 * entitlement for a case that needs (a) a public template and (b) its author's
 * account deleted, so it is recorded here rather than built speculatively.
 *
 * @param int|null $createdBy `tblSetlistTemplates.CreatedBy` (NULL once the author is gone).
 * @param int      $userId    The authenticated caller.
 */
function setlistTemplateCanEdit(?int $createdBy, int $userId): bool
{
    if ($createdBy === null || $createdBy <= 0 || $userId <= 0) {
        return false;
    }
    return $createdBy === $userId;
}

/**
 * Is the optional plan column present on THIS install?
 *
 * ELI5: check the new column exists before we try to use it.
 *
 * The three docroots share ONE MySQL and migrations are WEB-RUN from
 * /manage/setup-database, never applied automatically on deploy — so "it is in
 * schema.sql" is a different statement from "it exists here". mysqli runs under
 * MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT (includes/db_mysql.php), so
 * selecting an absent column THROWS rather than returning false; an ungated
 * read would white-screen set-list sync on an un-migrated install, which is
 * the #1228 class exactly.
 *
 * Memoised per request: the answer cannot change mid-request and the sync path
 * asks more than once. Mirrors `userSyncExpiryReady()` deliberately, so the two
 * optional set-list columns are gated by two functions that read identically.
 *
 * @param \mysqli $db Live connection from getDbMysqli().
 * @return bool True when tblUserSetlists.SlotsJson exists.
 */
function setlistSlotsColumnReady(\mysqli $db): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblUserSetlists'
            AND COLUMN_NAME = 'SlotsJson' LIMIT 1"
    );
    $stmt->execute();
    $ready = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $ready;
}
