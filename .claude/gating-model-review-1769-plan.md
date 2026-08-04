# Gating model review & consolidation — analysis + plan (#1769)

> **Status (2026-08-04): Owner chose MODEL 2. Build underway on `claude/gating-model-review` (from alpha).**
> **P0 (dormant defect-fixes) COMPLETE — 5 commits, each mutation-proven, all no-ops while gating is off:**
> - `2e9aa850` restrictions engine: deny beats allow at equal priority (`ORDER BY Effect DESC`) + audit tool + behavioural guard
> - `c0f73b04` tier ordering reads the live `tblAccessTiers.Level` (tierLevelRank), const=fallback — custom tiers stop ranking 0 (OV-4)
> - `51ddd88c` gating-family API actions gate on their page's entitlement + `admin_set_user_tier` validates tiers live (OV-10)
> - `72a7ad3b` one shared `contentGatingMediaKindCap()` — no re-inlined kind→cap switches (OV-11)
> - `74b79639` same-origin-aware CSRF (`validateCsrfRequest`) on tiers/restrictions/entitlements (rule #29)
> **DEFERRED from P0 (with rationale, GIRFT — don't churn code scheduled for rewrite):**
> - restrictions-form honesty (hide Effect for `require_*`) + dead `songbook`/`feature` picker options → **P4** (the form is fully rewritten there; and `songbook`/`feature` scopes are reachable via the native `content_access` query endpoint, so not provably dead — needs the P4 usage decision).
> - seed `feature_gating_rules_enabled='0'` → **P1** (pairs with the schema batch; the flag already reads '0' when absent, so nothing is broken meanwhile).
> - `gating-noop-verify` into the admin nav → **P4** (it belongs beside the readiness checklist on the new hub).
> **P1 (one-pass additive-dormant schema) COMPLETE — `70739838`.** Designed via a sequential Fable pass +
> adversarial "what forces a second migration?" stress. `migrate-add-gating-facts-and-licence-types.php`
> (byte-identical schema.sql mirror + registry OR-probe): `tblLicenceTypes` (#459, seeds ccli/mrl/
> ihymns_basic/ihymns_pro/custom with CoversJson + ConfersTier) · `tblSongs.{Lyrics,Music}RightsLicenceKey`
> (+idx) · `tblSongbooks.Default{Lyrics,Music}RightsLicenceKey` (editor-default only) · reserved dormant
> `tblSongArrangements.{MusicRightsStatus,MusicRightsLicenceKey}` (#1768 Q2) · `tblGatingCapabilities.EnforceJson`
> · `feature_gating_rules_enabled='0'` seed. Reserve-now (rule #20): CoversJson object-of-objects, Authority/
> AuthorityRef, songbook editor-defaults. Verified: applied+idempotent on live DB, schema-coverage/registry/
> installs green, 110/110 PHP + 50/50 node, no-op against the migrated DB.
> **P2 IN PROGRESS — design done (Fable, verified), full blueprint in `.claude/gating-p2-design.md`.**
> Commit protocol A–G (all still DORMANT — no emitter adoption; that's P3):
> - ✅ **A** `c86ec62f` — pure code-motion seam: `_contentGatingApplyLegacyCore` / `_contentGatingMediaAllowedLegacyCore` extracted (existing no-op tests prove pure motion).
> - ✅ **B** `7e7b2a65` — golden capture: `tools/capture-gating-goldens.php` + `tests/php/fixtures/gating-goldens.json` (240 apply + 144 media, DB-free so it matches CI's matrix path; sanity-verified real enforcement).
> - ✅ **C** `185ef7f4` — `includes/licence_registry.php` (the #459 reader + LICENCE_TYPES_FALLBACK + coverage vocab) + `test-licence-registry.php` (seed-parity + conferral-no-op + degrade-to-fallback, mutation-proven). Inert — nothing consumes it yet.
> - ⏳ **D** — TIER_CAPS optional 5th `enforce` element + `tierCapEnforce()` + `EnforceJson` into the union (additive; frozen-7 get none) + extend `test-gating-registry.php`.
> - ⏳ **E (the crux)** — `access_context.php` + `access_resolver.php`; rewrite the two CG delegates; DELETE the legacy cores; `test-gating-equivalence.php` (replay the 384 goldens byte-identically) + extend `test-gating-noop.php` + the structural/parity guards + RUN the mutation checklist (blueprint §8.6). This is the byte-identical-no-op proof — execute with full care.
> - ⏳ **F** — re-point the 6 licence-vocab sites onto licence_registry (pickers gain mrl/custom; restrictions loses dead `none`; ccli_validator `$licenceToTier`→fallback under live ConfersTier — identity-proven; 3 schema COMMENTs). ⚠ mrl-confers-no-tier end-state (sub-Q5) is NOT flipped in P2 (would change live tiers even gating-off) — one-line P6 first-enable owner decision; file tracking issue.
> - ⏳ **G** — docs/annotations (Wiki Architecture, project-rules §18 xref, CHANGELOG).
> **RESUME AT COMMIT D.** Local `appWeb/.auth/db_credentials.php` (gitignored) → local `ihymns_live` (P1 migration applied); the golden-capture tool needs it moved aside (it refuses if a DB is reachable).
>
> **ACTIVITY LOGGING (owner-flagged 2026-08-04 — standing requirement for ALL gating work):** every
> state-changing admin action in this program MUST call `logActivity(...)` — no silent mutations. Applies to:
> the new admin hub + licence-type CRUD (P4: `admin.licence_types.create/update/delete/toggle`), the editor
> Rights-panel writes (`admin.song.rights_set`), enabling/disabling the gate + each readiness step (P6:
> `admin.gating.enable/disable`, `admin.gating.audit_run`), and every data migration run (DM-1/DM-2:
> `admin.migration.<slug>` with a row-count summary). The existing tiers/restrictions/entitlements pages already
> logActivity; the re-pointed pickers (P2 F) are read-side so add none. P2 A–E are read-path refactors with no
> admin action to log. CI: extend the admin-action audit so a new `/manage` gating mutation without a
> logActivity() call is flagged (mirror the existing `admin.*` logging convention).
>
> **DATA MIGRATIONS (owner-flagged 2026-08-04 — must be built, not just schema):** the function reworks are no-op
> refactors, but the CONSOLIDATION needs existing data moved into the new model. Two first-class, additive,
> idempotent, reversible, dormant data migrations (each its own `migrate-*.php` + registry card + real probe + guard):
> - **DM-1 org-licence consolidation (P5):** for every org with a non-'none' legacy `tblOrganisations.LicenceType`,
>   INSERT the matching `tblOrganisationLicences` row (Number/ExpiresAt carried) if absent; migrate any hand-written
>   ghost `tblContentLicences` rows likewise (row-count probe first). ONLY THEN the gated-retire of the legacy
>   columns + the ghost read. `resolveEffectiveTier` already unions both stores, so the backfill is a verified no-op
>   for resolution; it just makes ONE store authoritative before retire.
> - **DM-2 existing-gating → per-song facts (P4/P6):** derive `tblSongs.LyricsRightsLicenceKey`='ccli' (and
>   ='mrl' where a `require_licence:mrl` row exists) from the current `tblContentRestrictions` `require_licence:*`
>   rows, so the P4 Rights panel + the resolver's fact path reflect gating that already exists. Reversible
>   (facts are NULL-able); restriction rows are KEPT (coexist — the Exceptions tab), so this is additive not a move.
>   Runs before first-enable (P6) so the new model matches current behaviour on day one.
>
> **Local dev note:** `appWeb/.auth/db_credentials.php` (gitignored) now points at local `ihymns_live`, which
> has the P1 migration APPLIED — so getDbMysqli()/the DB suites connect there without `IHYMNS_TEST_DSN`.
>
> ---
> **(original analysis follows — still current)**
> **Status: ANALYSIS COMPLETE, model chosen; P0 done, P1 next.**
> Produced by a THREE-STAGE sequential Fable-5 deep analysis (owner directive: deep analysis = sequential
> Fable 5, fall back to Opus). Every load-bearing claim was orchestrator-verified against source before this
> doc was written (citations inline). Raw stage outputs: `scratchpad/gating-stage{1,2}-*.md` (this session).
> This is the analysis-first pass #1769 asked for — NO code has been changed; the build does not start until
> the owner picks a model.

## Why this exists (owner brief, #1769)
The access system grew into overlapping concepts that are confusing to configure. Priority = **ease of use for
a non-technical admin team** ("shouldn't need to be an IT whiz"); flexibility matters but manageability matters
more. Keep staff RBAC separate. Collapse the overlapping consumer-access surfaces into one plain-language model;
make enforcement/master-switch invisible plumbing. This gate must land BEFORE #1768 (MRL music gating) and
#1767's gated print features are built (both add gating surface; building on the current model then redesigning
= doing it twice).

## Current state — it's FIVE surfaces, not four (verified)
| # | Surface | Where | Controls |
|---|---|---|---|
| 1 | **Entitlements** (staff RBAC) | `includes/entitlements.php` `userHasEntitlement()`, `/manage/entitlements` | what a staff role may DO in `/manage/*` (51 keys × 4 roles) |
| 2 | **Access Tiers** (consumer plans) | `tblAccessTiers`, `TIER_CAPS` registry (`access_tier_validation.php:70-87`), `/manage/tiers` | what KIND of content a plan permits (7 frozen TINYINT caps + JSON Capabilities) |
| 3 | **Content Restrictions** (per-entity) | `tblContentRestrictions`, `checkContentAccess()` (`content_access.php`), `/manage/restrictions` | per-song/songbook rules (require_licence, block_*) |
| 4 | **Content-Gating enforcement** | `content_gating.php` (`contentGatingApply`/`…MediaAllowed`), `content_gating_enabled` | strips gated fields/bytes from payloads by tier cap |
| 5 | **Feature-Gating RULES engine** | `gating_rules.php`, `/manage/feature-gating`, `feature_gating_rules_enabled` | admin-defined caps (tblGatingCapabilities) + deny-only payload rules (tblGatingRules) |

Plus supporting cast: the licence model (`$LICENCE_TYPES`, `checkTierAccess`/`resolveEffectiveTier`, `userHasValidCcli`),
Service-Mode presence unlock, read-rate-limit, audio-signing, master switches.

## The core problems (all verified against source)
- **Same outcome, many knobs.** "Require CCLI" is enforceable **4 ways** (require_licence:ccli row · RequiresCcli tier col+hard gate · CanViewCopyrighted=0 · Service-Mode presence), configured across 3 admin pages. "Hide audio from free users" is expressible 3 ways.
- **Licence vocabulary written in SIX places** — and the sixth (`my-organisations.php:82` = `['ccli','mrl','ihymns_basic','ihymns_pro','custom']`) has **already diverged**: it already contains **`mrl`** (the licence #1768 wants) and `custom`. An org can RECORD an MRL licence today → written to `tblOrganisationLicences`, returned by `getUserEffectiveLicences()` — but **nothing can consume it** (restrictions dropdown lacks it; `$licenceToTier` maps it to `'free'`; no enforcement knows the word). **#1768's problem has already half-happened by accident** — the sharpest possible evidence for the redesign. `tblLicenceTypes` (#459) was scoped in 2025 and never built (only a comment in `restrictions.php:67`).
- **A hidden bridge decides plans.** `$licenceToTier` (`ccli_validator.php:186`) silently converts an org licence (legal fact) into a consumer plan (commercial ladder) — shown on **no admin page**. The single biggest "why can this user see that?" mystery.
- **Two "Level" systems.** `tblAccessTiers.Level` (admin-editable 0-1000) vs hardcoded `TIER_LEVELS` (`ccli_validator.php:86-92`) — only the const is read by the resolver, so a **custom tier ranks 0** regardless of the Level typed in.
- **Dead configuration.** `songbook`/`feature` restriction EntityTypes + `RESTRICTIONS_FEATURES` are pickable, stored, listed — and consulted by **nothing** (every caller passes `'song'`).
- **A live, documented behaviour inversion.** `ORDER BY Priority DESC, Effect ASC` + return-on-first-match ⇒ at equal priority a matching **ALLOW beats DENY**, the OPPOSITE of the file header, schema comment, and the admin page's own copy (#1158, knowingly unfixed). And `require_*` rules `continue 2` — **ignore Effect entirely** — yet the form offers allow/deny, so `require_licence`+Effect=allow silently behaves as deny.
- **Enforcement is forked 4× and about to be 5×.** `contentGatingApply` + `song.php`'s inline re-implementation + `songbook_export` + `bulk_audio` each hand-write the composition; the kind→cap media map is textually duplicated in 3 files held by stale-line-number comments. `content_gating.php` has **zero chord handling** (chords are per-line `tblLyricLines.ChordsJson`). #1767's print path would be a brand-new 5th fork.
- **Turning it on is a 5–7-switch ritual** across 4+ surfaces (one flag unseeded, one prover URL-only, 3 documented live holes incl. signed-in web viewers treated as anonymous).

## Concern separation (the owner's own frame, confirmed correct)
- **Bucket A — staff RBAC** (who on my team manages what). Healthy, stays separate. Only upgrades: per-user grants (today role-global only) + override-delta semantics (today first save freezes the whole 51-key map).
- **Bucket B — consumer access** (who among users/orgs sees/downloads what). The mess: 3 overlapping surfaces. Target shape = **FACTS × GRANTS × one resolver × one pipeline**.
- **Bucket C — infrastructure** (channels, rate-limits, audio-signing, product feature-flags). Needs its own names so "gating" means one thing.

## Overlap verdicts (11 real overlaps): 9 COLLAPSE, 2 KEEP-DISTINCT
COLLAPSE: the 4 CCLI mechanisms → one declared licence-requirement fact + presence as a grant input; the 3 "hide media" mechanisms → cap carries its own enforcement mapping; the 2 cap registries+3 pages → one; the 2 Level systems → live Level; the 6 licence-vocab sites → `tblLicenceTypes`; dead songbook/feature pickers → removed; kind→cap ×3 → one map; the "feature gating" 4-way name collision → renamed; the API-vs-web gate inconsistency → all via `userHasEntitlement`.
KEEP-DISTINCT (concept kept, hidden wiring made visible): licence⇒tier AND licence⇒entity are two real relationships → both become declared attributes of the licence type; `block_user` (per-entity) vs account deactivation (whole account) → keep, but UI must say which.

---

## THE THREE MODELS

### Model 1 — "Consolidate the surface, keep the plumbing" (lowest risk)
One **Content Access** hub (`manage/content-access.php`) with Plans / Licences / Exceptions tabs; the 3 old pages 302 in. Build `tblLicenceTypes` (#459). Fix all the safe defects (allow-beats-deny, dead pickers, live-Level, hardcoded tier list, raw-role API gates, CSRF upgrades). Centralise enforcement composition into one `access_pipeline.php` (thin — no new engine). **#1768 lands in ~10–12 touchpoints, 1 forced-bad decision remains; MRL becomes recordable + visible but only whole-song-grain enforceable (chords/sheet-only gate still inexpressible). Goal-3 ease-of-use 2/5.** 3 phases / 2 PRs. Low risk.

### Model 2 — "Facts × Grants × one resolver" (the target shape) ⭐ RECOMMENDED
Everything in Model 1, **plus** the deeper plumbing that only works together:
- **Facts move to where content is edited** — a small "Rights" panel in the song editor (`Lyrics PD ☐ · Music PD ☐ · Needs licence [None|CCLI|MRL ▼]`), with songbook-level bulk defaulting.
- **Licences become structured** — each type declares what it COVERS (display-lyrics / print-lyrics / reproduce-music / play-audio — growable JSON) and "also grants plan: X" (the now-explicit conferral). #1768 MRL = enable the seeded row + tick "reproduce music". Done.
- **`/manage/feature-gating` retires** — its cap definitions fold into the Plans tab (define+value in one place); its rules become redundant because a cap definition now **carries its own enforcement mapping** (`TIER_CAPS` gains an additive 5th tuple element `enforce`; verified index-read so it's safe).
- **ONE resolver + ONE pipeline** (`access_context.php` + `access_resolver.php`): `accessViewerContext()` resolves tier/caps/licence-set/presence/bypass ONCE per request; `accessResolve(viewer, facts, action)`; `accessApplySong()` / `accessMediaAllowed()`. `contentGatingApply`/`…MediaAllowed` keep their signatures as thin delegates (derive-not-remove). **The frozen 7 columns + `access_tiers` camelCase emit stay stored/emitted forever — the new model FEEDS them, never re-homes them.** `checkBulkAccess()` gains an additive presence param (fixes the D-2 congregant-denied-by-bulk gap by construction). Every emitter — API, media bytes, HTML page, export, **print (#1767)** — ADOPTS the pipeline instead of forking.
- **One-pass additive-dormant schema batch** (rule #20): `tblLicenceTypes` (+`CoversJson`), `tblSongs.{Lyrics,Music}RightsLicenceKey`, **reserved dormant** `tblSongArrangements.MusicRightsStatus`+`.MusicRightsLicenceKey` (arrangement-grain PD flips later with zero migration), `tblGatingCapabilities.EnforceJson`. Destructive retires are separate `'manual'`+`confirm=1` steps, later.

**#1768 + #1767 land in ~4–5 touchpoints, ZERO forced-bad decisions, ZERO new forks** (vs ~17 on the current model, ~10–12 on Model 1) — and the shape recurs FREE for every future gate family (video/projection/regional). **Goal-3 ease-of-use 4/5.** 6 phases / ~4 PRs. Medium risk (P3 emitter adoption, pinned by the no-op prover per docroot).

### Model 3 — "Rebuild around a policy model" (highest ambition) — RECOMMEND DECLINE
A first-class policy object (subject × resource × action × condition) + sentence-builder UI + simulator, subsuming tiers+restrictions+rules. **Recommend declining**, three concrete reasons: (1) it makes priority/effect rule-interaction — today's *worst* defect — the admin's core daily UX, for the exact non-technical team we're protecting; (2) the frozen native-app tier→cap contract inverts from "stored" to "derived from policies" (a standing cross-representation agreement, rule #35); (3) the flexibility it buys has **no buyer** — every current goal + both queued features are fully expressible in facts×grants, and Model 2's `accessResolve` is the correct growth point (additive condition field) if policy-grade needs ever appear. 9+ phases, highest risk.

## Recommendation: **Model 2, staged so Model 1 is literally its first two phases.**
Not a compromise — a sequencing. Every Model-1 artefact is a strict subset of Model 2, so choosing Model 2 wastes no Model-1 work, and if the owner prefers to stop after the Model-1 phases nothing is stranded. Fits the owner's priority ordering: **ease-of-use first** (only model that turns #1768 from "no path" into tick-boxes and makes enforcement genuinely invisible); **flexibility second** (registry-line-plus-fact per gate family = more *real* flexibility than Model 3's unused generality); **manageability over cleverness** (3 nouns, no rule-ordering semantics, native contract stays stored). Only model that pays for itself on the immediate queue.

### Phase sequence
- **P0** — defect fixes, all while dormant, one PR: allow-beats-deny `ORDER BY` flip **(one-time equal-priority audit FIRST — last cheap moment; after enable it's a permanent behaviour change)**; hide Effect for require_*; live-Level ranking; live-table tier validation; entitlement-gate the raw-role API actions; drop dead pickers; extract the kind→cap map; seed `feature_gating_rules_enabled`; CSRF upgrades; `gating-noop-verify` into the nav. CI: 4 tree-derived mutation-tested guards.
- **P1** — one-pass additive-dormant schema batch (incl. mrl seed + reserved arrangement columns), schema.sql byte-identical, one registry entry, OR-probe.
- **P2** — registries + resolver + pipeline (still dormant): `licence_registry.php`, TIER_CAPS 5th element, `access_context.php`+`access_resolver.php`, delegates, licence-vocab sites re-pointed ($licenceToTier → fallback under ConfersTier, fixture-tested). Extended no-op test.
- **P3** — emitter adoption, one per commit (song_detail/data/random → songbook_export → bulk_audio/song-media → **song.php fork collapses in + auth-forwarding gap closes here** → checkBulkAccess presence param). No-op prover per docroot each commit.
- **P4** — admin hub + editor Rights panel + naming/"Plans" copy pass.
- **P5** — gated `'manual'` retires (tblGatingRules, ghost tblContentLicences read, legacy LicenceType union), after all envs on the new path.
- **P6** — **#1768 + #1767 land** (~4–5 touchpoints), then **first enable** — permitted by the status-card checklist only when: P0 ordering fix ✓, P3 song.php fix ✓, audio signing + `.htaccess` seal ✓, no-op verify green on all 3 docroots ✓.
Hard constraints: allow-beats-deny (P0) + song.php auth-forwarding (P3) MUST precede first enable; destructive retires (P5) follow full P3 adoption; #1768/#1767 follow P2+P3 — which is why #1769 gates them.

## Open sub-questions (defaults chosen — all NON-BLOCKING except #8; trivially changeable)
1. **#1768 Q1 one master switch or two?** → **ONE** (`content_gating_enabled`; MRL rides as facts+caps). A 2nd switch re-grows the checklist sprawl we're killing.
2. **#1768 Q2 arrangement-grain music-PD now or later?** → **song-grain first; arrangement columns reserved dormant in P1** (flip later = zero migration).
3. **#1768 Q3 does MIDI count as music reproduction?** → **yes** (`CanDownloadMidi` gains `requiresCoverage:'music_reproduction'`; one line to flip).
4. **#1768 Q4 do curators always see gated content?** → **yes in `/manage/*` + editor previews** (edit_songs bypass, flagged), **no on public site** (staff browse as their own plan); "Preview as plan" tool later.
5. **NEW — should licence⇒tier conferral exist at all?** → **keep, but as the visible `ConfersTier` attribute** (full orthogonality would silently strip today's ccli-org members of their derived tier; it was hidden, not wrong). MRL ships `ConfersTier NULL`.
6. **NEW — `block_user` restriction type keep or remove?** → audit row count; **if zero ever written, remove from picker** (deactivation is the real per-user tool; engine keeps honouring the VARCHAR).
7. **NEW — hub absorbs the master switch from `configuration#feature-gating`?** → **yes**, cross-link stub left behind (switch belongs beside its readiness checklist).
8. **THE decision — which model + may we stop after Model-1 phases?** → **commit to Model 2, review after P1** (staging makes that a real checkpoint). **This is the one decision that blocks P2+; P0/P1 are safe to start under any answer.**

## Verification notes (orchestrator, before writing this doc)
Spot-verified against source: the allow-beats-deny inversion (`content_access.php:300` `Effect ASC` + `continue 2` at :488/:508); hardcoded `$validTiers` in `admin_set_user_tier`; `TIER_LEVELS` const :86-92; `gatingRulesApply` nested in `contentGatingApply` :312; `my-organisations.php:82` already carries `mrl`+`custom` while `$licenceToTier` lacks `mrl` (→ 'free'); `content_gating.php` has 0 chord matches; `require_music_pd` comment-only; `TIER_CAPS` tuples read by index (`[$k][2]`/`[$k][3]` — 5th element additive); `tblSongs.{Lyrics,Music}PublicDomain` at schema.sql:291-292; `tblLicenceTypes` absent from the tree (only the #459 comment). All held.
