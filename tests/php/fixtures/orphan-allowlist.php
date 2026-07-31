<?php

declare(strict_types=1);

/**
 * iHymns — the orphan allowlist (data for `tests/php/test-orphan-inventory.php`)
 * =============================================================================
 *
 * ELI5
 * ----
 * The orphan guard shouts when the codebase grows something nothing uses — an
 * API endpoint no screen calls, a table nothing writes, a permission nothing
 * checks. Some of those are on purpose. This file is the list of "yes, we know,
 * and here is why", and every line has to say why.
 *
 * THE RULES THIS FILE LIVES BY (enforced by the guard, not by good intentions)
 * ---------------------------------------------------------------------------
 *  1. **Name-exact.** An entry only suppresses the exact name it lists.
 *  2. **Count-exact / self-cleaning.** If an allowlisted orphan stops being an
 *     orphan — somebody finally wired the endpoint, or deleted it — the guard
 *     FAILS with "stale allowlist entry — delete it". An allowlist that can only
 *     suppress can only grow; this one can only shrink or be re-justified out
 *     loud. Copied from the proven `test-fragment-inline-scripts.php` pattern.
 *  3. **Every reason must contain an issue number (`#1234`) or the word
 *     `deliberate`** — asserted by the guard itself, so an entry cannot be added
 *     without saying something.
 *  4. **No `until` dates.** A date that passes silently is not a mechanism
 *     (rule #35); a stale entry failing the build is.
 *
 * READ THIS BEFORE ADDING AN ENTRY
 * --------------------------------
 * Adding a line here is a decision to ship something no first-party code uses.
 * That is sometimes right (a documented API surface for out-of-repo consumers, a
 * rule-#20 forward-looking table, a feature whose UI is the next batch). It is
 * usually not. The default is to wire it or delete it — endpoint, docs and
 * schema together, never one without the others.
 *
 * PROVENANCE
 * ----------
 * The initial population is the exact residue of the mechanically-derived audit
 * in `.claude/orphan-inventory-2026-07-30.md`, as re-verified by this guard's
 * own derive pass on branch `claude/wave3-fixes`. Section references below (§2.1
 * etc.) point into that document; work-item ids (X1, E1, F3 …) point into
 * `.claude/remediation-plan-2026-07-30.md` §2.
 */

return [

    /* =====================================================================
     * 1. DISPATCHED ACTIONS WITH NO FIRST-PARTY CALLER
     *
     * "No first-party caller" means: zero hits across web JS, admin JS/PHP,
     * public PHP, the service worker, appApple and appAndroid. It does NOT
     * mean "unused" — an out-of-repo API-key consumer is invisible to any
     * static scan, and the guard says so in its header.
     * ===================================================================== */
    'actions' => [

        /* ---------------------------------------------------------------
         * 1a. The admin / org JSON API-parity family — 53 actions (§2.1).
         *
         * Every one is documented in api-docs.yaml and reachable from the
         * Swagger try-it-out console at /manage/api-docs (a real, mounted,
         * entitlement-gated UI). The equivalent /manage/* pages do their own
         * direct DB work, so nothing first-party calls the JSON twins.
         *
         * KEEP, per remediation plan §5 + owner decision D1 (default A):
         * culling 53 published endpoints is the only irreversible option and
         * the one that breaks out-of-repo integrations silently. The debt is
         * now count-exact instead of invisible, which was the actual danger.
         *
         * The classifier can tell this family apart from a genuine caller:
         * `admin_refresh_iana_cldr` is NOT here, because setup-database.php
         * really does call it. That is pinned as a positive control.
         * --------------------------------------------------------------- */
        'admin_activity_log'                    => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_analytics_searches'              => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_cleanup'                         => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_credit_person_add'               => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_credit_person_delete'            => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_credit_person_merge'             => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_credit_person_rename'            => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_credit_person_update'            => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_data_health'                     => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_export'                          => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_group_create'                    => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_group_delete'                    => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_group_member_add'                => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_group_member_remove'             => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_group_update'                    => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_groups'                          => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_migrations_status'               => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_organisation_delete'             => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_organisation_member_add'         => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_organisation_member_remove'      => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_organisation_member_role_change' => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_organisation_update'             => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_organisations'                   => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_pending_revisions'               => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_revision_review'                 => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_schema_audit'                    => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_song_request_update'             => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_song_requests'                   => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_songbook_create'                 => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_songbook_delete'                 => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_songbook_delete_cascade'         => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_songbook_health'                 => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_songbook_update'                 => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_songbooks_auto_colour_fill'      => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_songbooks_auto_colour_reassign'  => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_songbooks_reorder'               => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_tier_create'                     => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_tier_delete'                     => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_tier_update'                     => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_user_create'                     => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_user_delete'                     => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_user_password_reset'             => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_user_rename'                     => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_user_role_change'                => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_user_toggle_active'              => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_user_update'                     => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_users'                           => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'org_admin_licence_add'                 => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'org_admin_licence_change'              => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'org_admin_licence_remove'              => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'org_admin_member_add'                  => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'org_admin_member_remove'               => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'org_admin_member_role_change'          => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',

        /* ---------------------------------------------------------------
         * 1b. Content-gating / licensing family — 12 (§2.2 + `tier_check`).
         *
         * The whole program is dormant behind `content_gating_enabled='0'`
         * (rule #28 A). These are the API face of it; they light up with the
         * flag, not with a new UI.
         *
         * `tier_check` was NOT in the inventory's §2.2 list of 11 — this
         * guard found it (api.php:9405; its only other mentions are prose in
         * manage/tiers.php:14 and api-docs.yaml). Recorded here as the 12th.
         *
         * `user_access` keeps its entry even after remediation X5 fixes its
         * 500 (#1670): making a broken endpoint work does not give it a
         * caller.
         * --------------------------------------------------------------- */
        'user_access'              => 'dormant by design, rule #28/#20; content-gating family. 500-fix is #1670 — a fix is not a caller',
        'content_access'           => 'dormant by design, rule #28/#20; content-gating family',
        'admin_restrictions'       => 'dormant by design, rule #28/#20; content-gating family',
        'admin_restriction_create' => 'dormant by design, rule #28/#20; content-gating family',
        'admin_restriction_delete' => 'dormant by design, rule #28/#20; content-gating family',
        'ccli_validate'            => 'dormant by design, rule #28/#20; content-gating family',
        'access_tiers'             => 'dormant by design, rule #28/#20; content-gating family',
        'admin_set_user_tier'      => 'dormant by design, rule #28/#20; content-gating family',
        'admin_set_user_ccli'      => 'dormant by design, rule #28/#20; content-gating family',
        'user_effective_licences'  => 'dormant by design, rule #28/#20; content-gating family',
        'licence_check'            => 'dormant by design, rule #28/#20; content-gating family',
        'tier_check'               => 'dormant by design, rule #28/#20; content-gating family. Found by THIS guard, not by the 2026-07-30 inventory — file under #1590',

        /* ---------------------------------------------------------------
         * 1c. #1511 device-code + control-token pairs — 4 (§2.4).
         * The approve side is live (device-link.js:54/65); the device side's
         * consumer is the tvOS client, which has no device-code code yet.
         * api-docs.yaml:3564/11097 already declares the family live-dormant.
         * --------------------------------------------------------------- */
        'auth_device_code_request'    => '#1511 live-dormant; consumer = tvOS client, approve side already live',
        'auth_device_code_poll'       => '#1511 live-dormant; consumer = tvOS client, approve side already live',
        'service_control_token_mint'  => '#1511 live-dormant; documented dormant at api-docs.yaml:11097',
        'service_control_token_revoke'=> '#1511 live-dormant; documented dormant at api-docs.yaml:11097',

        /* ---------------------------------------------------------------
         * 1d. Misc public API, API-parity spillover — 8 (§2.5 six + 2 the
         * inventory missed).
         *
         * `regions` (api.php:5349) and `scripts` (api.php:5302) are the read
         * side of the seed-only reference tables tblRegions /
         * tblLanguageScripts. Neither appears anywhere in js/, manage/,
         * includes/, appApple or appAndroid. Found by THIS guard.
         * --------------------------------------------------------------- */
        'song_by_identifier'   => 'deliberate API parity; dormancy note in api-docs.yaml is remediation X7',
        'person_by_identifier' => 'deliberate API parity; dormancy note in api-docs.yaml is remediation X7',
        'songs_list'           => 'deliberate API parity; dormancy note in api-docs.yaml is remediation X7',
        'my_organisations'     => 'deliberate API parity; dormancy note in api-docs.yaml is remediation X7',
        'songs_by_tag'         => 'deliberate API parity; superseded for the web by ?page=tag (#1637); X7 adds the yaml note',
        'song_revisions'       => 'deliberate API parity; dormancy note in api-docs.yaml is remediation X7',
        'regions'              => 'deliberate API/native parity read of the seed-only tblRegions. Found by THIS guard, not by the 2026-07-30 inventory',
        'scripts'              => 'deliberate API/native parity read of the seed-only tblLanguageScripts. Found by THIS guard, not by the 2026-07-30 inventory',

        /* --------------------------------------------------------------- */
        'custom_tags' => 'deliberate native/API-parity read; only the sync twin custom_tags_sync has a web caller (user-auth.js:781). Remediation F7',

        /* ---------------------------------------------------------------
         * 1e. TEMPORARY — servers complete, UI is a scheduled batch.
         *
         * These are #1671's "a permanently untested surface that reads as
         * working" class. Each entry dies when its UI lands: wiring a caller
         * makes the entry stale and the guard fails until it is deleted.
         * That is the intended pressure, and the reason none of them carries
         * a date.
         * --------------------------------------------------------------- */
        'push_subscribe'       => '#1435 / #1671 — server live, no SW push handler yet; wiring is remediation F6. DELETE THIS ENTRY when F6 lands',
        'push_unsubscribe'     => '#1435 / #1671 — server live, no SW push handler yet; wiring is remediation F6. DELETE THIS ENTRY when F6 lands',
        'devices_list'         => '#1671 — server complete (api.php:4292); devices screen is remediation F1. DELETE THIS ENTRY when F1 lands',
        'device_signout'       => '#1671 — server complete (api.php:4341); devices screen is remediation F1. DELETE THIS ENTRY when F1 lands',
        'song_key'             => '#1671 / #298 — server complete; editor + song-page UI is remediation F3. DELETE THIS ENTRY when F3 lands',
        'song_key_save'        => '#1671 / #298 — server complete; editor + song-page UI is remediation F3. DELETE THIS ENTRY when F3 lands',
        'setlist_templates'    => '#1671 / #301 — server complete incl. IDOR guard; UI is remediation F4. DELETE THIS ENTRY when F4 lands',
        'setlist_template_save'=> '#1671 / #301 — server complete incl. IDOR guard; UI is remediation F4. DELETE THIS ENTRY when F4 lands',
        'my_song_requests'     => '#1671 — server complete (api.php:5201); "My requests" view is remediation F2. DELETE THIS ENTRY when F2 lands',

        /* ---------------------------------------------------------------
         * 1f. TEMPORARY — scheduled for DELETION in remediation Batch 3.
         *
         * The plan would have let these go red as "correct pressure". They
         * are allowlisted instead so Batch 1 lands green and the debt is
         * enumerated rather than drowning the signal; the entries are
         * self-cleaning in the same direction — deleting the endpoint makes
         * the entry stale and the guard demands the entry go too, so the
         * endpoint and its allowlist line die in the same commit.
         * --------------------------------------------------------------- */
        'user_preferences'      => 'deliberate temporary — superseded by user_settings (#1671); deletion is remediation F5. DELETE ENDPOINT AND ENTRY TOGETHER',
        'user_preferences_sync' => 'deliberate temporary — superseded by user_settings (#1671); deletion is remediation F5. DELETE ENDPOINT AND ENTRY TOGETHER',
    ],

    /* =====================================================================
     * 2. TABLES AN APP PATH READS THAT NOTHING WRITES (§3.2)
     *
     * A reader with no writer is a feature that can only ever return an
     * empty result — "API-reachable, user-invisible, data-impossible".
     * ===================================================================== */
    'tables_reader_no_writer' => [
        'tblSongAlternativeTitles' => '#1669 — SongData.php:955/966/2946 reads it; the creating migration has zero INSERTs. Writer is remediation X8',
        'tblSongArrangements'      => '#1066 one-pass dormant — ?include=arrangements read side shipped, write side is future feature work',
        'tblSongRoyaltyIds'        => '#1066 one-pass dormant — ?include=royaltyIds read side shipped, write side is future feature work',
        'tblSongScriptureRefs'     => '#1066 one-pass dormant — ?include=scriptureRefs read side shipped, write side is future feature work',
        'tblVocalParts'            => '#1066 one-pass dormant — ?include=vocalParts read side shipped, write side is future feature work',
        'tblContentLicences'       => '#1668 licence-store consolidation — catalogue rows ship only in .sql/.fulldata/ihymns-full.sql, so a schema-only install reads an empty catalogue',
        'tblCreditPersonLinks'     => 'deliberate legacy fallback — index.php:541 / pages/person.php:287 read it only `if (empty($linksUnified))`, i.e. on a pre-backfill install. Dead on migrated installs BY DESIGN',
    ],

    /* =====================================================================
     * 3. TABLES AN APP PATH WRITES THAT NOTHING READS (§3.3)
     * ===================================================================== */
    'tables_writer_no_reader' => [
        'tblLyricWords'     => 'deliberate — per-word timing accrues at ingest (lyrics_ingest.php:358) for the future karaoke/sync read path; see .claude/lyrics-normalisation-strategy.md. Deleting the write throws away data we collect on purpose',
        'tblLyricSyllables' => 'deliberate — per-syllable timing accrues at ingest (lyrics_ingest.php:362) for the future karaoke/sync read path; see .claude/lyrics-normalisation-strategy.md',
    ],

    /* =====================================================================
     * 4. TABLES WRITTEN THROUGH AN INTERPOLATED `{$table}` NAME
     *
     * NOT an allowlist — a resolution map, and the guard asserts the named
     * file still contains the table literal. That is what stops the map
     * rotting into a permanent excuse (rule #35: a mechanism, not a comment).
     *
     * external_link_helpers.php:202/277 writes four tbl*ExternalLinks tables
     * through an allow-list map, which no literal `INSERT INTO tblX` scan can
     * see. This false-flagged tblWorkExternalLinks in the source audit until
     * it was checked by hand (inventory §0.3).
     * ===================================================================== */
    'tables_dynamic_writers' => [
        'tblWorkExternalLinks'     => 'appWeb/public_html/includes/external_link_helpers.php',
        'tblSongExternalLinks'     => 'appWeb/public_html/includes/external_link_helpers.php',
        'tblSongbookExternalLinks' => 'appWeb/public_html/includes/external_link_helpers.php',
        'tblCreditPersonExternalLinks' => 'appWeb/public_html/includes/external_link_helpers.php',
    ],

    /* =====================================================================
     * 5. LABELLED ENTITLEMENTS NOTHING CHECKS (§6.3)
     *
     * `/manage/entitlements` lets an operator edit the role→entitlement map
     * at runtime. For a key nothing checks, that edit changes NOTHING, and
     * silently — the same species as #1587's nav-vs-page mismatch. What
     * actually gates these operations today is a raw ROLE test.
     *
     * NINE ENTRIES WERE REMOVED HERE BY THE #1590 TRUTH-UP (Batch 6, E1):
     * `delete_songs`, `bulk_edit_songs`, `edit_users`, `change_user_roles`,
     * `assign_global_admin`, `delete_users`, `run_db_migrate`, `run_db_backup`
     * and `run_db_restore` are now checked at their real enforcement points, so
     * an entry for any of them would be reported stale by CHECK 4's companion
     * assertion. They are gone rather than reworded — that is the self-cleaning
     * property working as designed.
     *
     * NOT here, deliberately: `access_alpha` / `access_beta`. The inventory
     * listed them as decorative; they are not. They are checked at
     * channel_gate.php:107 via a name assigned by a ternary at :92 (which a
     * literal-argument scan cannot see), behind a documented "TEMPORARILY
     * DISABLED" early `return;` at :83. The guard pins that as a positive
     * control instead of allowlisting it — an entry for a non-orphan would be
     * stale by rule 2 above.
     * ===================================================================== */
    'entitlements' => [
        /* The TENTH decorative entitlement — found by this pass, on neither the
         * inventory's §6.3 list nor remediation plan §4.6's table of nine.
         *
         * It was invisible to both because CHECK 4 above walks the keys of
         * `$ENTITLEMENT_LABELS`, and `manage_org_licences` had no label. It now
         * has one (#1590 E2), which is exactly why it surfaces here.
         *
         * NOT WIRED, deliberately, because no wiring is a no-op. Its comment in
         * includes/entitlements.php says it exists so "licence edits can be
         * delegated without granting full org admin", and its default is admin+.
         * But the live org-licence editor is /manage/my-organisations.php, gated
         * on `manage_own_organisation` — which deliberately includes the plain
         * `user` role, because someone must be able to run their OWN
         * organisation without a site-wide role. Adding an admin+ AND to that
         * page would remove licence editing from every org owner who is not a
         * site admin: a live privilege change, which #1590's governing rule
         * forbids. Aligning the map DOWN to user+ instead would make the key an
         * exact synonym for `manage_own_organisation` and delete the separation
         * it was created for.
         *
         * Which surface it should govern — the org-admin self-service editor,
         * the site-admin /manage/organisations editor, or both with different
         * defaults — is a product decision, not something derivable from the
         * code. Tracked as the one open item of #1590 Batch 6.
         */
        'manage_org_licences' => 'deliberate — the tenth decorative key, found by #1590 E2 giving it a label. Wiring it to the live editor (/manage/my-organisations, gated on manage_own_organisation which includes plain users) would strip org owners of licence editing; aligning the map down to user+ would make it a synonym of manage_own_organisation. Needs an owner decision on which surface it governs — #1590',
    ],
];
