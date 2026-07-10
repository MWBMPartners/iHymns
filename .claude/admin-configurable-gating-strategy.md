# Admin-configurable feature gating — strategy (DRAFT for owner review)

> **Status: DRAFT — awaiting owner approval. No issues/branches/code created from this yet.**
> Produced by two sequential deep-planning rounds on **Fable 5** (round 1 = deep draft; round 2 = adversarial verify + refine against the real code), then reviewed on Opus. Date: **2026-07-10**.
> Builds on the shipped #1352/#1353/#1354 gating registry (CLAUDE.md rule #28, `project-rules.md §18`). Every load-bearing claim is cited to `path:line`.

**Owner ask (verbatim intent):** *"Access Tiers gates some features (Lyrics, Copyrighted, Audio, MIDI, PDF, Offline, Needs-CCLI). But there's no simple way in the UI to configure gating of ADDITIONAL (current or future) features. We'd like to avoid adding new dev code just to add gating for existing/new feature(s) — Global System Admins should be able to do this easily using the UI."*

**Honest formula:** *Admins compose; developers register primitives.* A feature whose gating is expressible as an existing **behaviour kind** on a **covered surface** → zero code, pure UI. A genuinely-new enforcement *kind*, a new API endpoint, or a new client affordance → one-time dev work, then pure UI thereafter. Realistically **~⅓–½ of plausible future gates are pure-UI** under this design — and the design says so out loud rather than overpromising.

---

## The recommendation in one paragraph

The #1352 registry already did the hard part: every admin/API/enforcement surface *derives* its capability list from one `TIER_CAPS` registry, and the resolver `checkTierAccess()`/`capValueForTierAction()` already accepts an **arbitrary registered cap key** against the live tier row. So the change is two-layered:
- **P1 — make the registry admin-editable:** a new `tblGatingCapabilities` table whose rows a Global Admin CRUDs (key, label, default), unioned into `TIER_CAPS` via one new `tierCapsEffective()` accessor. The `/manage/tiers` matrix auto-grows a column, the API emits the cap, and it's enforceable the moment any caller names it. (~8 direct `TIER_CAPS` references must be re-pointed to the union — mechanical.)
- **P2 — make enforcement composable:** a code-registered **catalog of behaviour kinds** (`strip_payload_keys`, `drop_media_kinds` — the two generalisations of what `contentGatingApply()` already hardcodes), mapped to caps by admin-CRUD'd `tblGatingRules`, applied in a loop *after* the built-in trims (below the dormancy switch, deny-only, coverage-mapped).

Everything is additive, dormant (a second nested flag `feature_gating_rules_enabled` gated under `content_gating_enabled`), fail-open/STRICT-safe across the 3-docroot shared DB, and preserves the 7 native-contract caps byte-identically.

**The honest boundary:** admins compose within **code-owned primitives + code-owned safety allow-lists** (`GATING_STRIPPABLE_KEYS`, the must-never-gate set), on **explicitly named surfaces** (the 3 JSON endpoints in v1). A brand-new *kind* of enforcement (watermarking, truncation, time-boxing) still needs a one-time dev primitive.

---

*(Full adversarially-reviewed strategy follows — verbatim from the Fable round-2 output.)*

## Changes from the first-pass draft (adversarial review)

**Verified against the code:** `TIER_CAPS` registry + accessors + `tierCapsColumnExists()` INFORMATION_SCHEMA/cache/try-catch pattern (`includes/access_tier_validation.php:72-246`); `capValueForTierAction()` resolves an arbitrary registered key excl. `RequiresCcli`, null on failure (`includes/ccli_validator.php:318-362`); `contentGatingApply()` master-switch early-return + outer try/catch (`includes/content_gating.php:152-292`); both tier-CRUD surfaces split by storage + the additive `access_tiers` json-merge (`manage/tiers.php`, `api.php:8124-8171`); `getAppSetting()` fail-safe default (`includes/maintenance.php:60-77`).

**Corrected:**
1. **"One seam, everything auto-grows" was overstated** — ~8 direct `TIER_CAPS` references bypass the accessors and must each be re-pointed to the union (`manage/tiers.php:259,301,316,324,406,450` + `capValueForTierAction` key resolution `ccli_validator.php:325-331`). Mechanical, but not a 1-function change.
2. **The rules engine does NOT inherit fail-open through `checkTierAccess()`** — an unresolvable cap falls to the legacy matrix, which **denies** unknown actions (fail-closed). Fix: rules resolve via `capValueForTierAction()` directly and **skip the rule when it returns null**.
3. **Surface coverage is three JSON endpoints, not app-wide** — `contentGatingApply()` is called from `random` + `song_detail`/`song_data` only. The HTML song page (`includes/pages/song.php:157-242`), `songbook_export`, `bulk_audio` have their own gates. Now an explicit coverage map (§5), UI-surfaced.
4. **No-escalation needs a cheap engine subset-assertion**, not just architecture.
5. **v1 rules must key on DB-defined caps ONLY** (not the 7 built-ins) — the Service-Mode presence OR-grant is applied *after* `checkTierAccess` inside `contentGatingApply` (`content_gating.php:217-218`); a rule keying on `CanPlayAudio` would strip media from a congregant mid-service.
6. **Schema fixes:** `Scope VARCHAR NOT NULL DEFAULT ''` (nullable in a UNIQUE key lets duplicate rules coexist); CapKey grammar is XSS-load-bearing (`tiers.php:408,453` emit `cap_<col>` unescaped).
7. **Must-never-gate set widened:** shared-cache page fragments (`$_cacheablePages`/`page=home`, rule #6), copyright/CCLI **attribution fields** (the inverse compliance failure), and a `Cache-Control: private` rule for any newly tier-varying response.

**Walked back:**
- **`feature_flag` rule kind dropped** — a cap with `EmitInApi=1` and no rule *is* the advisory channel; a do-nothing-server-side rule invites "admin believes it's protected".
- **P3 (`gate_api_action`) demoted** from the build path to "on concrete need" (footgun surface, no named use case).
- **Zero-code fraction honestly ~⅓–½**, not near-universal.

## 1. Owner ask and honest formula
(above)

## 2–16 + Open Questions

The complete verified strategy (current state with citations · the seam inventory · the P2 enforcement architecture with the fail-open correction · the 3-endpoint coverage map · the no-escalation stress · code-owned allow-lists · the two-flag nested topology · native-contract preservation · admin UX · security model · cross-docroot per-kind analysis · one-pass schema · the "is it worth it" verdict · back-compat/rollback) is captured in the session's Fable round-2 output and mirrored here. Key decisions extracted below.

### Recommended scope
- **P1 (registry union + capability CRUD + matrix auto-grow + emits + `manage_feature_gating` entitlement + audit + tests)** — the honest MVP; delivers the headline ask; dormant (empty table = byte-identical no-op). **Build.**
- **P2 (two deny-only rule kinds `strip_payload_keys`/`drop_media_kinds` + rules CRUD + the `contentGatingApply` loop + per-rule effect indicators + coverage banner + no-op tests).** **Build** (fold the honesty UI in, don't defer it).
- **Defer:** P3 `gate_api_action` (on concrete need), P4 preview/dry-run tester, P5 (`rate_limit_override`, per-songbook `Scope` values, `gate_page_route`, native `features` map).

### Owner decisions
1. **Two-flag nested topology** — rules run only when `content_gating_enabled='1'` AND `feature_gating_rules_enabled='1'` (both default `'0'`). *Recommended.*
2. **Entitlement split** — capability/rule *definitions* = new `manage_feature_gating => ['global_admin']`; per-tier *values* stay `manage_access_tiers` (`admin`+`global_admin`). *Confirm.*
3. **Build scope** — P1 only, or P1+P2? *Recommend P1+P2.*
4. **`songbook_export` tier-trim gap (§5)** — it returns full song records untrimmed by tier caps today (a pre-existing built-in gap, not introduced here). Fix independently, accept, or fold into P2? *Recommend: independent item.*
5. **`EmitInApi` default** = `1` (caps exist to be seen by clients). *Recommend.*
6. **Native `features` map on `tier_check`** — defer to the release a native client will consume it. *Recommend defer.*

### Schema (one-pass, rule #19/#20)
`migrate-add-gating-registry.php` creating both `tblGatingCapabilities` (definitions) + `tblGatingRules` (cap→behaviour-kind + Params JSON), multi-object OR-probe, schema.sql mirror, ONE migration-registry entry. VARCHAR-not-ENUM vocab (`BehaviorKind`/`Source`/`Scope`), JSON params, per-tier values ride the existing `tblAccessTiers.Capabilities` JSON (no `tblAccessTiers` change). CI: `test-schema-coverage.php` + `test-migration-registry.php`.

### Effort
P1 **M** · P2 **M** · deferred P3 **S–M**, preview tester **S**, P5 per-item.

---

*Planning provenance: 2 sequential Fable-5 rounds (draft → adversarial verify+refine, both against the real code) + Opus review, 2026-07-10. Draft for owner review — no code/issues/branches created.*
