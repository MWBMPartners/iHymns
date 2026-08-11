# #1769 P4 — management surfaces + DM-2 rights-facts derivation: implementation blueprint

> **STATUS (2026-08-05): Commits A–F LANDED + pushed on `claude/gating-model-review`; G (this docs sweep) in progress.**
> - A `5ff6a48c` licence-type CRUD · B `f4d97ce9` editor Rights panel · C `c1b1a15f` songbook default rights ·
>   D `9fb9bc0a` DM-2 derive-rights-facts · E `f44296d5` restrictions Effect honesty + gating-noop gate ·
>   F `067cd5c8` activity-log CI guard (+ fixed the tiers.php success-logging gap it found).
> - Every commit a verified no-op while `content_gating_enabled='0'`; PHP 120/120 + node 50/50 CI-parity; all
>   six new guards mutation-proven; DM-2 + fill-NULL-only sweep verified against a live DB.
> - **NOT done (owner-gated, P6):** the master-switch flip + the `/manage/gating` hub (D8) + #1772–#1777 +
>   the dead songbook/feature restriction pickers (tracked issue). Commit-C deviation: the songbook
>   apply-to-songs is a checkbox on the save, not a separate `admin_…` action (one code path, same
>   fill-NULL-only + IS NULL + logActivity contract).

> Durable blueprint for P4 (branch `claude/gating-model-review`, follows P3 = `.claude/gating-p3-design.md`).
> Produced by a sequential Fable design pass (orchestrator-verified against source), 2026-08-04.
> PRIME INVARIANT (unchanged from P0–P3): every commit is a verified no-op while `content_gating_enabled='0'`
> (the live default) AND changes no state-(b) enforcement outcome. P4 ships **management UI + dormant data-prep
> only**; the facts do not ENFORCE until P6 (the `test-gating-pipeline-structure.php` §(a) dead-branch lock stays
> green throughout P4 — flipping it is P6's deliberate act). P4 does NOT flip the master switch.

## 0. Verified findings that shape the build
1. **The editor already reads the fact columns for free** — `ed2_buildSongSnapshot()` does `SELECT * FROM tblSongs`; `load_song` emits the row verbatim. On a migrated install the payload already carries `LyricsRightsLicenceKey`/`MusicRightsLicenceKey`; absent otherwise. **No read-side server change**, and this path never touches `SongData.php`, so guard §(a) stays green by construction. Only the revision-restore loop (iterates `ED2_META_FIELDS`) needs the new keys to round-trip.
2. **Guard §(a) is file-scoped** (scans only `SongData.php`) → P4 adds a tree-derived §(g) **containment lock**: every reference to the two column names under `public_html` must be in the P4 allow-set (the P2 `'gatingEnabled'`-containment precedent).
3. **licence_registry already has the cache-bypass seam** — `licenceTypesAll(?\mysqli $db)`: an explicit `$db` bypasses the request cache. Admin surfaces pass explicit `$db` on every post-mutation read → no registry change, no new invalidation API. Guard it structurally (rule #35).
4. **Licence-type edits are LIVE even with gating off** — since P2-F, `resolveEffectiveTier()`'s conferral overlay reads `licenceConfersTier()` from the live table regardless of the switch. The CRUD code ships as a no-op, but editing `ConfersTier`/`Enabled` on `ccli` changes members' effective tiers immediately (same class as editing a tier's caps today). Surface honestly in UI copy + reference counts + audit; never hide/block.
5. **setup-database.php already logs card runs as `setup.run`** (no row counts). DM-2 adds its own `admin.migration.derive-rights-facts` logActivity with the row-count summary (function_exists-guarded).
6. **test-admin-gate-parity.php requires the PAGE to literally name the nav entitlement** — putting `gating-noop-verify` in the nav means swapping its `requireGlobalAdmin()` for an entitlement check.
7. **`includes/works_admin.php` does not exist** — the real shared-core exemplar is `includes/tune_admin.php` (#1748). Mirror it.
8. **Data-only migrations need no schema.sql edit** (precedent `migrate-reconcile-isrc-denorm.php`) → DM-2 changes schema.sql not at all (columns shipped in P1).
9. **`require_licence` stores the key in `tblContentRestrictions.TargetId`**, `Effect` ignored for `require_*` (`continue 2`), rows target `EntityType='song'`, `EntityId=<SongId>`.
10. **api.php admin CRUD siblings** gate on `getAuthenticatedUser()` + `userHasEntitlement()`, NOT `validateCsrfRequest()` (bearer posture); the PAGE handlers DO. Follow each sibling exactly.

## 1. Decisions
- **D1** NEW entitlement `manage_licence_types` = `['admin','global_admin']` (+ JS mirror + `$ENTITLEMENT_LABELS` + the 'Content gating' group + nav row; both parity guards derive it).
- **D2** Rights facts flow through the existing `metadata_field_update` funnel; SongData stays untouched (§a green; §g proves no other leak). Enforcement of the fact is P6.
- **D3** Rights-panel write entitlement = `ed2_requireEntitlement('edit_songs')` (same class as the PD flags).
- **D4** Songbook defaults = prefill-hint + explicit one-click **apply-to-NULL-only**, NEVER an automatic write.
- **D5** DM-2 mapping is registry-coverage-driven via a shared helper `rightsFactColumnForLicence(?array $covers): ?string` (covers lyrics_display/lyrics_print → Lyrics; music_reproduction → Music; neither → skip+report). Migration/probe/test all call the ONE fold.
- **D6** DM-2: no schema.sql change (data-only).
- **D7** DM-2 in "Apply all" (NOT `'manual'`) — additive/idempotent/reversible.
- **D8** `/manage/gating` hub DEFERRED to P6 (its readiness checklist can't finalise until P5 + #1772–#1777 resolve); P4 wires the two new nav rows into the existing Access group + files the hub issue.
- **D9** `gating-noop-verify` nav entitlement = `manage_configuration` (swap `requireGlobalAdmin()`; admittance-identical at defaults).
- **D10** Restrictions Effect honesty folded into Commit E (hide Effect for `require_*` + normalise `Effect='deny'` server-side — engine-dead, provably zero behaviour change). Dead songbook/feature pickers → tracked issue (needs native content_access usage decision).
- **D11** Editor picker vocab via `window._iHymnsLicenceTypes = json_encode(licenceTypesForPicker($db))` in editor2.php (the `_iHymnsLinkTypes` convention; editor2.php is a full admin page, rule #30 N/A). Songbook per-song defaults ride `load_song`.

## 2. Commit sequence (one PR → alpha)
- **A — licence-type CRUD**: entitlement plumbing (6 touchpoints) + `includes/licence_type_admin.php` (mirror tune_admin.php: pure core, validate [key immutable on update, covers vocab, live-tier ConfersTier], covers-merge preserving qualifiers, create/update/toggle/delete, referenceCounts existence-gated, delete-policy refuse system/referenced) + `/manage/licence-types` page (shared partials, responsive+sortable table, CSRF `validateCsrfRequest`, logActivity `admin.licence_types.*`, un-migrated degrade to read-only fallback, ConfersTier live-effect warning copy [finding 4], explicit-$db cache seam [finding 3]) + `admin_licence_type_*` api.php actions (mirror `admin_tier_create`, 409 on dup/un-migrated, api-docs). Tests: `test-licence-type-admin.php` (validation truth table, key immutability, covers-merge, delete policy, degrade), extend `test-gating-admin-gates.php`, structural cache-seam+CSRF asserts. All mutation-proven.
- **B — editor Rights panel**: `ED2_META_FIELDS` += the two keys→columns; `ed2_rightsColsPresent()` probe (409 un-migrated) in `metadata_field_update` + the restore loop; dedicated validation branch (`edit_songs`, `''`→NULL, else ∈ `licenceTypeKeys($db)` else 422, before/after audit); log `admin.song.rights_set`; `load_song` emits `songbookRightsDefaults` when the cols exist; editor2.php `window._iHymnsLicenceTypes`; `rights-panel.js` (DOM-first, err.status branching, disabled when un-migrated). Tests: `test-rights-panel-fields.php` + extend structure guard with **§(g) fact-column containment** (allow-set: api2, songbooks.php, licence_type_admin.php, licence_registry.php, access_resolver.php); §(a) run+green.
- **C — songbook rights defaults**: `manage/songbooks.php` update-case writes Default{Lyrics,Music}RightsLicenceKey (column-gated, validated), + `apply_rights_defaults` POST (fill-NULL-only, `IS NULL` lock, logActivity). `test-songbook-rights-defaults.php` (mutation: drop IS NULL → red).
- **D — DM-2**: `rightsFactColumnForLicence()` helper (registry) first; `migrate-derive-rights-facts.php` (registry-first mapping, fill-NULL-only UPDATE-JOIN per column, SortOrder deterministic, reports skips/coexisting rows, guarded logActivity); registry card (NOT manual) + data-derived drift-detecting probe (never `=> true`); `revert-derive-rights-facts.php` CLI recovery tool. `test-derive-rights-facts.php` (helper truth table + structural single-fold + IS NULL; mutations). No schema.sql change (D6).
- **E — deferred-P0**: restrictions Effect honesty (D10) + `gating-noop-verify` nav row + entitlement swap (D9). `test-restrictions-effect-honesty.php`.
- **F — activity-log CI guard**: `test-gating-admin-activity-log.php` — derive surfaces from the gating table set (write-verb OR gating-core-fn scan), slice action windows, flag a write window with no `logActivity`/`logActivityError`/`@no-activity-log` doctag; vacuity gate; mutation-proven both ways.
- **G — docs + issues**: annotate every new file; banner + plan + CHANGELOG + project-rules §18.7 P4 para + Wiki; file issues (P6 hub+master-switch [carries sub-Q7], dead songbook/feature pickers, cache-seam-as-invalidation-convention for-consideration).

## 3. State-(b) audit — MUST be empty (and is)
No commit changes enforcement in either flag state: fact columns read by no public path (§a+§g); restriction rows kept + engine untouched; Effect normalisation engine-dead for require_*; gate swaps admittance-identical at defaults; export/media/song paths untouched. Two operator-DATA caveats (not code deltas, disclosed in UI + PR): licence ConfersTier/Enabled edits act on live tier resolution (finding 4, pre-existing P2-F surface); DM-2/bulk-apply write inert columns. gating-noop-verify baseline/verify brackets B–E on alpha + the local flag-ON differential re-run after D.

## 4. Scope fence — P4 does NOT
Emit either fact key from SongData/any public payload (P6 — §a red is the intended flip signal); wire accessResolve()/coverage-refine into production (P6); build the hub or move the master switch (P6, issue); rename/retire feature-gating (P5); run DM-1 (P5); flip the master switch (P6); auto-apply songbook defaults on create (D4); touch the dead songbook/feature pickers (issue); flip mrl conferral (#1772); mass-rewrite the #1352/#1353 in-code citations.
