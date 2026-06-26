# API Platform — Strategy (DRAFT for owner decision)

> Status: **draft strategy only** — no issues/branches/code yet (per "plan = draft the doc").
> Drafted 2026-06-25 alongside the `feat/api-native-gating` branch. Decide direction, then we scope PRs.

## 1. The idea
Turn the iHymns API from "the app's private backend + a single lyrics-ingest key" into a **small, governed read/write platform** that native apps and trusted third parties (sibling projects like iLyricsDB / MeedyaDL, partner churches, integrators) can build on — with **scoped keys, per-key rate limits, usage visibility, and live docs**.

## 2. What ALREADY exists (the foundation — mostly #1066)
Most of the hard infra is in place, which is why this is medium-effort, not a from-scratch build:
- **`tblApiKeys`** — `Scope` (space-separated, e.g. `lyrics:ingest`), `KeyHash`/`KeyPrefix` (raw shown once), `Active`, `LastUsedAt`/`LastUsedIp`, **`RateLimitPerMin`/`RateLimitPerDay`** per key, `CreatedBy`.
- **`includes/api_keys.php`** — `apiKeyAuthorize(db, requiredScope)` (the endpoint guard, 401 + `WWW-Authenticate`), `apiKeyVerify`, `apiKeyHasScope`, `apiKeyEnforceRateLimit` (429 + Retry-After), idempotency (`tblApiKeyIdempotency`), usage counters (`tblApiKeyUsage`).
- **`/manage/api-keys`** — admin CRUD to mint/label/scope/revoke keys (now on the robust same-origin CSRF too).
- **Read rate-limiting (#1354)** — token/IP throttling on the heavy public reads, already shipped on this branch.
- **OpenAPI 0.990.0** — the spec is now current (this session) incl. the Editor v2 + Service Mode + rate-limit + content-gating, rendered by `/manage/api-docs` (Swagger UI).
- **Content gating (#1352/#1353)** — a tier/registry model that a future *keyed* read tier could plug into.

**Gap:** today the only scope in real use is `lyrics:ingest` (write). There is **no read scope**, no usage dashboard, and Swagger "Try it out" isn't wired. The catalogue reads are either fully public (rate-limited) or session-token (the app). There's no keyed, governed read tier for third parties.

## 3. Proposed phases (each a self-contained PR; pick the subset you want)

### Phase A — Read scopes + keyed read endpoints  *(core)*
- Define a small scope vocabulary (a registry, mirroring the gating registry pattern — **VARCHAR, app-validated, never ENUM**): e.g. `song:read`, `songbook:read`, `search:read`, `media:read`, plus the existing `lyrics:ingest`.
- Add an **optional** `apiKeyAuthorize(db, 'song:read')` path to the read endpoints (`song_detail`, `songs_index`, `songs_list`, `search`, `bulk_*`): a valid key with the scope gets the per-key rate limit (generous) **instead of** the anonymous IP limit, and (later) can be granted access to gated content per the tier model. No key → today's public/anonymous behaviour, unchanged.
- **Why it's small:** `apiKeyAuthorize` + the rate-limit hook already exist; this is wiring + a scope list.

### Phase B — API-key usage & limits dashboard  *(high-visibility, low-risk)*
- In `/manage/api-keys`: per-key **usage charts** (from `tblApiKeyUsage` minute/day windows), `LastUsedAt`/`LastUsedIp`, current-window counts vs the key's limit, and an inline **editor for `RateLimitPerMin`/`PerDay`**.
- Optional: a "top talkers / recent 429s" view (pairs with the read-rate-limit observability idea).
- **Why it's small:** the data already accrues; this is a read-only admin view + the limit editor.

### Phase C — Developer experience  *(adoption)*
- Enable Swagger **"Try it out"** on `/manage/api-docs` (and/or a public read-only docs portal) now the spec is accurate.
- A short **API quickstart** (auth header, scopes, rate-limit headers — `X-RateLimit-Limit/-Remaining/-Window`, idempotency) — fold into help.php `#api-keys` + a Wiki `API-Reference` section.

### Phase D — Optional / later
- Self-serve key request flow (org-admin requests → global-admin approves) instead of admin-only minting.
- Webhooks on song/songbook changes (could reuse the dormant `tblExternalSystems`, #1327).
- Finer scopes (per-songbook, write scopes for partners), key expiry/rotation, per-key content-tier grants.

## 4. Schema impact
Minimal. The existing `tblApiKeys.Scope` (VARCHAR) + `tblApiKeyUsage` cover A and B. A scope **registry** is a PHP map (like `TIER_CAPS`), not a table. Phase D (webhooks/self-serve) would add tables — out of scope until decided.

## 5. Decisions needed (owner)
1. **Public vs keyed reads** — keep catalogue reads fully public (IP-rate-limited) and add keys only as a *higher-limit / gated-content* tier? Or move some reads behind a required key?
2. **Who gets keys** — admin-issued only (today), or a self-serve request flow (Phase D)?
3. **Scope granularity** — coarse (`song:read`) vs fine (per-songbook, per-action)?
4. **Monetisation / tiers** — is a keyed read tier tied to the gating tiers (paid API access = `pro`), or free-for-partners?
5. **Content via keys** — should a key be able to unlock gated/copyrighted content (ties to #1353 + the CCLI licensing basis), or read public catalogue only?

## 6. Recommendation
Start with **B (usage dashboard)** — highest visibility, lowest risk, no API contract change, and it makes the keys you already issue observable. Then **A (read scopes)** as the real platform step, scoped conservatively (keyed reads = higher limits on public catalogue only; no gated-content-via-key until decision #5). **C** is a cheap adoption follow-on. Defer **D**.

**Value:** medium-high (opens the catalogue to sibling projects + native apps with governance). **Effort:** B small, A small-medium, C small, D medium. **Risk:** low for B/C, low-medium for A (it's additive/optional), medium for D (new surface area + the content/monetisation decisions).

## 7. Dependencies / ties
Builds directly on #1066 (key infra), #1354 (read rate-limiting), the #1352/#1353 gating model, and the refreshed OpenAPI (this branch). No blockers — purely additive.
