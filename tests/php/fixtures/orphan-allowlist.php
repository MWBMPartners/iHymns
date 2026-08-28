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
        /* #1741 P2-B renamed Credit People -> Musicians. The
           admin_credit_person_* action names are the shipped-Apple-contract
           back-compat ALIASES (api.php keeps them as fallthrough case
           labels into the SAME admin_musician_* body) — still dispatched,
           still nothing first-party calls either name, so both the old
           alias and the new canonical action keep an entry here. */
        'admin_credit_person_add'               => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30; #1741 P2-B back-compat alias for admin_musician_add',
        'admin_credit_person_delete'            => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30; #1741 P2-B back-compat alias for admin_musician_delete',
        'admin_credit_person_merge'             => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30; #1741 P2-B back-compat alias for admin_musician_merge',
        'admin_credit_person_rename'            => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30; #1741 P2-B back-compat alias for admin_musician_rename',
        'admin_credit_person_update'            => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30; #1741 P2-B back-compat alias for admin_musician_update',
        'admin_musician_add'                    => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30; #1741 P2-B canonical name (renamed from admin_credit_person_add)',
        'admin_musician_delete'                 => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30; #1741 P2-B canonical name (renamed from admin_credit_person_delete)',
        'admin_musician_merge'                  => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30; #1741 P2-B canonical name (renamed from admin_credit_person_merge)',
        'admin_musician_rename'                 => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30; #1741 P2-B canonical name (renamed from admin_credit_person_rename)',
        'admin_musician_update'                 => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30; #1741 P2-B canonical name (renamed from admin_credit_person_update)',
        'admin_data_health'                     => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_export'                          => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_group_create'                    => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_group_delete'                    => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_group_member_add'                => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_group_member_remove'             => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_group_update'                    => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        'admin_groups'                          => 'deliberate API-first surface #719; Swagger console consumer; owner decision D1 default A, 2026-07-30',
        /* #1769 P4 — licence-type vocabulary CRUD API. Same D1-default-A posture
           as admin_tune / admin_tier: deliberate API-first surface, documented in
           api-docs.yaml, reachable from the Swagger try-it-out console;
           manage/licence-types.php does its own direct DB work via the SAME shared
           includes/licence_type_admin.php cores (never a fork), so nothing
           first-party calls the JSON twins yet. */
        'admin_licence_type_create'             => 'deliberate API-first surface #1769 P4; Swagger console consumer; same D1-default-A posture as the admin_tune / admin_tier families',
        'admin_licence_type_delete'             => 'deliberate API-first surface #1769 P4; Swagger console consumer; same D1-default-A posture as the admin_tune / admin_tier families',
        'admin_licence_type_toggle'             => 'deliberate API-first surface #1769 P4; Swagger console consumer; same D1-default-A posture as the admin_tune / admin_tier families',
        'admin_licence_type_update'             => 'deliberate API-first surface #1769 P4; Swagger console consumer; same D1-default-A posture as the admin_tune / admin_tier families',
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
        /* #1748 — tune admin CRUD API. Same D1-default-A posture as the
           admin_musician family (and admin_tier, immediately above):
           deliberate API-first surface, documented in api-docs.yaml,
           reachable from the Swagger try-it-out console; manage/tunes.php
           does its own direct DB work via the SAME shared
           includes/tune_admin.php cores (never a fork), so nothing
           first-party calls the JSON twins yet. */
        'admin_tune_add'                        => 'deliberate API-first surface #1748; Swagger console consumer; same D1-default-A posture as the admin_musician / admin_tier families',
        'admin_tune_delete'                     => 'deliberate API-first surface #1748; Swagger console consumer; same D1-default-A posture as the admin_musician / admin_tier families',
        'admin_tune_merge'                      => 'deliberate API-first surface #1748; Swagger console consumer; same D1-default-A posture as the admin_musician / admin_tier families',
        'admin_tune_update'                     => 'deliberate API-first surface #1748; Swagger console consumer; same D1-default-A posture as the admin_musician / admin_tier families',
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
        /* #1969 (API-coverage plan §4.1 C5, batch 1) — JSON twin of
           manage/my-ccli-report.php, delegating to the SAME shared
           includes/ccli_report.php core (rule #22) that page already uses.
           Same D1-default-A posture as the admin_musician / admin_tier /
           admin_tune / admin_licence_type families immediately above:
           deliberate API-first surface, documented in api-docs.yaml,
           reachable from the Swagger try-it-out console — the web page
           does its own direct DB work via the shared core, so nothing
           first-party calls the JSON twin yet. (Its C2/C3/C4 batch-1
           siblings — org_venues/tune/publisher_detail — are NOT listed
           here: this guard's own doc-comment-URL chain rule already
           credits them a caller via the `?action=…` mentions in this same
           commit's doc-blocks, so adding them would trip the "stale
           allowlist entry" self-cleaning check instead.) */
        'org_ccli_report'                       => 'deliberate API-first surface #1969 (API-coverage batch 1 C5); Swagger console consumer; same D1-default-A posture as the admin_musician / admin_tier / admin_tune / admin_licence_type families; manage/my-ccli-report.php does its own direct DB work via the SAME shared includes/ccli_report.php core (never a fork), so nothing first-party calls the JSON twin yet',

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
        /* #1791 collab-by-link — setlist_token_update / setlist_share_list /
           setlist_share_revoke WERE allowlisted here while the server (C2b)
           was ahead of the client. The share dialog (setlist.js
           renderShareDialog()) and the shared-page token-edit surface
           (initSharedSetListPage()) now call all three, so the entries were
           removed per the allowlist's own self-cleaning rule — see git log
           on this file for the commit that wired them. */

        /* ---------------------------------------------------------------
         * 1d. Misc public API, API-parity spillover — 8 (§2.5 six + 2 the
         * inventory missed).
         *
         * `regions` (api.php:5349) and `scripts` (api.php:5302) are the read
         * side of the seed-only reference tables tblRegions /
         * tblLanguageScripts. Neither appears anywhere in js/, manage/,
         * includes/, appApple or appAndroid. Found by THIS guard.
         * --------------------------------------------------------------- */
        'song_by_identifier'    => 'deliberate API parity; dormancy note in api-docs.yaml is remediation X7',
        /* #1741 P2-B — person_by_identifier is the shipped-Apple-contract
           back-compat alias for musician_by_identifier (same shape as the
           admin_credit_person_* / admin_musician_* pairing above). */
        'person_by_identifier'  => 'deliberate API parity; dormancy note in api-docs.yaml is remediation X7; #1741 P2-B back-compat alias for musician_by_identifier',
        'musician_by_identifier'=> 'deliberate API parity; dormancy note in api-docs.yaml is remediation X7; #1741 P2-B canonical name (renamed from person_by_identifier)',
        'songs_list'            => 'deliberate API parity; dormancy note in api-docs.yaml is remediation X7',
        /* my_organisations REMOVED from this allowlist (#1830) — it now has a
           genuine web caller: js/modules/print.js's fetchPrintOrgLogos()
           (the print `logo` block's org/logo resolution, §6.3 of the plan). */
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
        /* F1 (devices_list / device_signout), F2 (my_song_requests) and F3
           (song_key / song_key_save) LANDED in the #1671 Batch-8 pass; their
           five entries were deleted there in the same commit, and this guard's
           stale-entry check is what proved the wiring is real rather than
           merely written — it went RED naming exactly those five the moment
           the modules referenced the action names.

           F4 (setlist_templates / setlist_template_save) has now gone the same
           way, and its two entries died here. Batch 8's refusal to build it was
           correct and its analysis was the spec: a template's whole payload is
           its slots and `user_setlists_sync` had nowhere to keep them, so a
           plan applied to a set list round-tripped and came back GONE —
           silently, and for signed-in users only. That was a SERVER problem, so
           it was fixed server-first: `tblUserSetlists.SlotsJson`
           (migrate-setlist-slots.php), the shared slot model in
           includes/setlist_templates.php, and the two missing endpoints
           (`setlist_template_update` / `_delete`) so a template is no longer
           permanent and uneditable from the screen that made it. Only then did
           the UI land.

           F6 (push_subscribe / push_unsubscribe) went the same way. Its blocker
           was not a UI at all: NOTHING HAD EVER SENT A PUSH. There was no VAPID
           keypair, no RFC 8291 payload encryption and no service-worker `push`
           handler — three missing links in a four-link chain, so the two
           endpoints were a permanently untested surface that read as working.
           Fixed by includes/web_push.php (VAPID ES256 + aes128gcm, hand-rolled
           on openssl because this project has no Composer), the service
           worker's `push` / `notificationclick` listeners, and the operator
           surface on /manage/notifications. Their two entries died here. */

        /* ---------------------------------------------------------------
         * 1f. TEMPORARY — scheduled for DELETION in remediation Batch 3.
         *
         * The plan would have let these go red as "correct pressure". They
         * are allowlisted instead so Batch 1 lands green and the debt is
         * enumerated rather than drowning the signal; the entries are
         * self-cleaning in the same direction — deleting the endpoint makes
         * the entry stale and the guard demands the entry go too, so the
         * endpoint and its allowlist line die in the same commit.
         *
         * The self-cleaning worked as designed: `user_preferences` and
         * `user_preferences_sync` were removed from api.php in #1671 F5
         * (superseded by the namespaced `user_settings`) and their two
         * entries died in the same commit — the guard's stale-entry check
         * is what forces that pairing.
         * --------------------------------------------------------------- */

        /* ---------------------------------------------------------------
         * 1g. #1770 C4/C5 — the external presentation-app driver family.
         * `service_drive` is the stable internal contract any OUT-OF-REPO
         * automation (a ProPresenter-class shim, a Companion webhook, a curl
         * loop) can drive a church's Service-Mode session through,
         * authenticated by a `tblServiceDriverKeys` credential (#1770 C1,
         * dormant schema) rather than a login — that consumer is, by
         * construction, invisible to an in-repo scan (same posture as the
         * admin/org JSON API-parity family above) and may stay allowlisted
         * indefinitely, unless a future in-repo test harness starts
         * exercising it directly.
         *
         * The self-cleaning worked as designed here too: the credential's
         * lifecycle trio (`service_driver_key_mint`/`_revoke`/`_list`) went
         * from server-only (C4) to caller'd the moment #1770 C5's
         * "Presentation-app control" admin card landed on
         * /manage/service-projection (plan §4.6) — their three entries were
         * removed in that same commit, exactly the F5/F6 pattern this
         * file's history already proves out.
         * --------------------------------------------------------------- */
        'service_drive'                         => 'deliberate API-first surface #1770 C4; out-of-repo driver-shim consumer (curl/Companion/ProPresenter-class automation), invisible to an in-repo scan by construction — same posture as the admin/org API-parity family',

        /* ---------------------------------------------------------------
         * 1h. #1860 Phase 3 — the work-identity find-or-link server core
         * landed AHEAD of its Editor2 client (deliberately — the build
         * spec's own §7 scopes the client wiring as a SEPARATE follow-up
         * build, the same "schema/core now, UI next" shape as 1g's
         * service_drive family above). All three delegate entirely to
         * includes/work_admin.php's workFindOrLinkByIdentifier() /
         * workLinkPlan() — no decision logic lives in api2.php itself.
         *
         * Self-cleaning happened the moment the Editor2 follow-up wired
         * metadata-tab.js's CCLI/ISWC commit listener + the "Part of work"
         * picker (design §3.7, #1907 Phase-5 Commit 9) — the same F5/F6/1g
         * pattern this file's history already proves out; see the removal
         * note just below.
         * --------------------------------------------------------------- */
        /* 'work_search' entry removed (#1907 Phase-5 Commit 4): it now has a real
           caller — the Structure-tab per-section Source-work picker
           (structure-tab.js) — so it is no longer an orphan.
           'song_work_autolink' / 'song_work_set' entries removed (#1907
           Phase-5 Commit 9): self-cleaning, as designed — metadata-tab.js's
           CCLI/ISWC auto-link hook (design §3.7 item 1) now calls
           api.autolinkWork(), and its manual "Part of work" picker (design
           §3.7 item 2) now calls api.setSongWork(), both via the new
           api-client.js methods. Same F5/F6/1g/1h self-cleaning pattern
           this file's history already proves out. */
        /* #1862's 'song_copyright_holder_set' temporary entry (B2 server-
           core-ahead-of-client) was HERE and has been removed — self-
           cleaning, as designed: metadata-tab.js's holder picker +
           api-client.js's setCopyrightHolder() (B3) now call it. */
    ],

    /* =====================================================================
     * 2. TABLES AN APP PATH READS THAT NOTHING WRITES (§3.2)
     *
     * A reader with no writer is a feature that can only ever return an
     * empty result — "API-reachable, user-invisible, data-impossible".
     * ===================================================================== */
    'tables_reader_no_writer' => [
        /* tblSongAlternativeTitles graduated OUT of this list in #1783: the
           duplicate_song endpoint (manage/editor/api2.php) now writes it via
           INSERT...SELECT when copying a song's alt titles, so it is no longer
           reader-with-no-writer. Removing the entry keeps the count exact. */
        'tblSongArrangements'      => '#1066 one-pass dormant — ?include=arrangements read side shipped, write side is future feature work',
        'tblSongRoyaltyIds'        => '#1066 one-pass dormant — ?include=royaltyIds read side shipped, write side is future feature work',
        'tblSongScriptureRefs'     => '#1066 one-pass dormant — ?include=scriptureRefs read side shipped, write side is future feature work',
        'tblVocalParts'            => '#1066 one-pass dormant — ?include=vocalParts read side shipped, write side is future feature work',
        'tblContentLicences'       => '#1668 licence-store consolidation — catalogue rows ship only in .sql/.fulldata/ihymns-full.sql, so a schema-only install reads an empty catalogue',
        /* #1741 P4c entries for tblTuneAliases/tblTuneCredits/
           tblTuneExternalLinks RETIRED by #1748 — manage/tunes.php +
           includes/tune_admin.php now write all three (aliases/credits/
           links replace + the merge cascade), so they are no longer
           reader-only. Removing a stale entry here is itself asserted by
           "no stale allowlist entries in [tables_reader_no_writer]". */
        /* tblCreditPersonLinks' entry retired #1741 P2-A — the table itself
           was renamed to tblMusicianLinks and tblCreditPersonLinks became a
           back-compat VIEW (migrate-musicians-rename.php), so it no longer
           matches orphanDeriveTables()'s `CREATE TABLE` regex and dropped
           out of the reader-no-writer candidate set entirely at that point
           (P2-A was schema-only; app code still said tblCreditPersonLinks,
           reading it through the view). #1741 P2-B (this rename) then moved
           the app-code read path onto the NEW base-table name directly
           (index.php:541 / includes/pages/musician.php:288 now read
           `tblMusicianLinks` literally, not through the view) — so
           tblMusicianLinks is a real base table again as far as this
           scanner is concerned, and needs its OWN entry, below, rather than
           inheriting the retired one. */
        'tblMusicianLinks' => 'deliberate legacy fallback — index.php:541 / includes/pages/musician.php:288 read it only `if (empty($linksUnified))`, i.e. on a pre-backfill install. Dead on migrated installs BY DESIGN; #1741 P2-B renamed from tblCreditPersonLinks (originally allowlisted, then retired #1741 P2-A when the table became a view — see the note above)',
        /* tblMusicianDuplicatesDismissed's #1785 C6 entry RETIRED in C7:
           manage/musician-duplicates.php now writes it too (dismiss INSERT
           / undismiss DELETE), so it is no longer reader-with-no-writer.
           Removing the entry keeps the count exact (the same self-cleaning
           shape as the tblSongAlternativeTitles / tblTuneAliases notes
           above). */
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
        /* #1741 P2-B renamed tblCreditPersonExternalLinks -> tblMusicianExternalLinks
           (app code + this map's key both moved together in the same commit). */
        'tblMusicianExternalLinks' => 'appWeb/public_html/includes/external_link_helpers.php',
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
    /* ---------------------------------------------------------------------
     * ENTITLEMENTS — empty, and that is the interesting part.
     *
     * This bucket held ONE entry: `manage_org_licences`, the tenth decorative
     * permission, invisible to both the orphan inventory and remediation plan
     * §4.6 because CHECK 4 walks `$ENTITLEMENT_LABELS` keys and this one had no
     * label. Giving it a label (#1590 E2) is what surfaced it.
     *
     * It is now WIRED, so the entry is gone — removed because this list is
     * count-exact and self-cleaning, and it went red the moment the wiring
     * landed. That is the mechanism working: an allowlist that must shrink
     * cannot quietly accumulate excuses.
     *
     * The wiring took the intent from the primary source rather than a guess.
     * #462 (commit 73851b01) registered it alongside `manage_user_licences` and
     * `view_licence_audit` "so future admin UI can delegate licence edits
     * without granting full org admin". The other two got endpoints; this one
     * never did, because on /manage/organisations the licence fields share ONE
     * form and ONE UPDATE with Name/Slug/Parent/Description — there was no
     * separate handler to attach it to. Splitting the FIELDS rather than the
     * statement is what made it real, and its default roles are byte-identical
     * to `manage_organisations`, so nobody's access changed.
     *
     * The earlier reading recorded here — that wiring it would strip org owners
     * of licence editing — was wrong, and worth keeping on the record. It came
     * from assuming the target was /manage/my-organisations (the ORG OWNER's
     * self-service page, gated on `manage_own_organisation`, which deliberately
     * includes plain users). The intent was always the SITE-ADMIN page. Reading
     * the originating commit settled in minutes what inference had got backwards.
     * ------------------------------------------------------------------- */
    'entitlements' => [
    ],
];
