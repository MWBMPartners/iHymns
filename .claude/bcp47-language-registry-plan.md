# BCP 47 language registry — refresh automation, picker live-search & unknown-tag curation

**Status: PLANNED** (2026-08-25, Fable-5 deep-planning pass, branch
`claude/dormant-features-settings-1sdw4t`). Written after a full read of the
existing #738 system — this plan deliberately does **NOT** re-plan #738, which
already exists and covers most of what the original brief asked for. Read §1
before anything else.

**Owner's binding decisions (do not re-litigate):**
1. Full IETF BCP 47 / ISO 639 support — the picker searches the complete registry.
2. The code list refreshes automatically into the backend database, ideally by a
   scheduled GitHub Action, silently.
3. Free-text language values remain allowed as a fallback (rule #21), BUT with an
   admin/curator surface to review/map/promote "unknown" languages.

---

## 0. Verdict — what is actually broken (two independent faults)

**Fault 1 — the observed screenshot bug is a DOM-timing bug, not a data bug.**
The 14-row base seed in `appWeb/.sql/schema.sql:2068` **includes English**
(`('en','English','English','ltr')`). If the picker's datalist were populated at
all — even on a completely un-migrated install — typing "Engli" would match
"English". It matched nothing, which means the datalist was **empty**, and the
code shows why: `bootIetfLanguagePicker()` resolves its three `<datalist>`s via
`document.getElementById(input.getAttribute('list'))`
(`js/modules/ietf-language-picker.js:224-229`) and captures the result ONCE into
closures it never re-queries. `getElementById` searches only the live document —
a `<datalist>` inside a detached `createElement()` subtree is invisible to it.
Both dynamic builders boot **detached**:

- `manage/editor/v2/enrichment-panel.js:137` — `bootIetfLanguagePicker(wrap)`
  runs before the caller appends `picker.el` (per-line language override AND
  translation target language — the two surfaces in the owner's screenshot).
- `manage/editor/editor.js` `buildInlineIetfPicker()` (~L1335+) — v1, same order,
  same fault.

So all four lists (`langList`/`scriptList`/`regionList`/`variantList`) are
`null`: `rebuildDatalist(null, …)` no-ops forever, no suggestion ever renders,
and `resolveCode(input, null)` **always** falls through to raw typed text.
**This exact class was already diagnosed and fixed for the Metadata tab in
#1849** — see the long comment at `manage/editor/v2/metadata-tab.js:1562-1578`,
which even predicts the misread: "the picker works, it just never suggests
anything". The enrichment panel and the v1 inline builder are the unfixed
sibling sites (the rule-#33/#34 lesson: a fix applied at one site, not derived
across the class).

**Fault 2 — the #738 registry migration is (almost certainly) un-applied on the
shared DB.** Migrations are web-run, never auto-applied on deploy (CLAUDE.md red
flag list; rule #28-C: the three docroots share ONE MySQL). The registry probe
(`manage/includes/migration-registry.php:627-660`) is
`!tableExists('tblLanguageVariants') || !columnExists('tblLanguages','Scope')`;
un-applied, the operator sees a pending "IETF BCP 47 Reference Data (#738)" card
on `/manage/setup-database` with a "Run IANA + CLDR Import" button, and:
`action=languages` returns just the 14 base-seed rows, `action=variants` returns
`[]` + a note. The IANA import inserts rows **without** naming `IsActive`, so
they take the column `DEFAULT 1` — applying #738 immediately makes all ~8,273
languages visible to `WHERE IsActive = 1`. Nothing hides them.

**Corollary — the "IsActive means site UI language" premise is wrong.** Nothing
in the codebase treats `tblLanguages.IsActive = 1` as a curated site-language
short-list; `manage/languages.php`'s own doc-block defines it as "retire a
deprecated subtag from the picker without dropping the row". The site language
FILTER derives from songbook languages, not from tblLanguages. Therefore **no
new flag/column is needed** (rule #44: don't add a field nothing acts on), and
the full registry going Active pollutes nothing — with two payload-weight
caveats filed as follow-ups (§8).

**What a "fix" therefore is NOT:** re-importing data, a new registry schema, or
a new migration. §1 lists what exists; §2 lists the genuine gaps this plan
covers.

---

## 1. What already exists (all verified against source, 2026-08-25)

| Piece | Where | State |
|---|---|---|
| Full IANA import (languages/scripts/regions/variants + CLDR name overlay) | `appWeb/.sql/migrate-iana-language-subtag-registry.php` (#738), 581 lines, idempotent (INSERT IGNORE + selective UPDATE), never disturbs curator-flagged rows | EXISTS |
| Bundled offline snapshots | `appWeb/.sql/data/` — `iana-language-subtag-registry.txt` (**8,273** languages, 225 scripts, 305 regions, 139 variants, `File-Date: 2026-04-21`) + 4 CLDR JSONs | EXISTS (4 months stale) |
| Tables | `tblLanguages` (+`Scope`), `tblLanguageScripts`, `tblRegions`, `tblLanguageVariants` — `schema.sql:1585/1604/1620/1634` | EXISTS |
| Migration card + probe | `migration-registry.php:627-660`, real multi-object probe | EXISTS |
| Manual live refresh | card button `data-action="refresh-iana-cldr"` → `POST /api?action=admin_refresh_iana_cldr` (`api.php:17584`): global_admin session, server-side outbound fetch of 5 **hardcoded** IANA/CLDR URLs, overwrites deployed `.sql/data/`, re-runs the import inline | EXISTS (manual only) |
| Native-name overlay | `migrate-cldr-native-names.php` (depends on the IANA import) | EXISTS |
| Read endpoints | `api.php` `action=languages` (full active dump, Scope-ranked) / `scripts` / `regions` / `variants`; prefix searches `script_search` / `region_search` on `/manage/songbooks` (`songbooks.php:156-300`) | EXISTS |
| Admin CRUD | `/manage/languages` (`manage_languages` entitlement; add/edit/toggle-IsActive/delete-with-refuse-on-cite; filters; summary counts) | EXISTS |
| Grammar validator | `_ietfBcp47Validate()` (`includes/song_importers.php:71`) — the ONE grammar check, consumed by `lineEnrichmentValidateLanguage()` (`includes/line_enrichment.php:605`) for translations + per-line overrides | EXISTS (grammar-only, no registry lookup) |
| Shared typeahead | `js/modules/place-search.js` `attach()` (generalised: `pickMode:'value'`, `searchUrl`, `parseResults`, `noun`, optional `hiddenIdInput`, `minChars`, `debounceMs`) + `js/modules/combobox-a11y.js`; already consumed by tune/publisher/work pickers in `metadata-tab.js` and loaded on every picker surface (songbooks.php, editor/index.php, editor2 shell) | EXISTS |
| Detached-boot fix precedent | `metadata-tab.js:1562-1578` (#1849) | EXISTS (one site only) |
| `.sql/` deploys to every channel | `deploy.yml:759+` uploads `appWeb/.sql/`; docroot renamed per channel (rule #41), `.sql/` is the un-renamed sibling | EXISTS |
| Keyed-drain endpoint pattern | `webhook-drain.php` (#1909): secret app-setting via `secretSettingKeys()`, `hash_equals`, `X-*-Key` header or `?key=`, rate-limited fail-open, 200/403/503-no-body contract | EXISTS (pattern to copy) |

## 2. What is genuinely missing (the scope of this plan)

- **M1** — Automatic/scheduled DB refresh (owner decision 2). Today's refresh is a manual global-admin button. → §3.
- **M2** — A working suggestion list in the DYNAMIC pickers at all (Fault 1), and live-search instead of an 8,273-option `<datalist>` once #738 is applied (rule #43). → §4.
- **M3** — Free-text fall-through is silent: no "not a recognised language" feedback at commit, and the per-line-override funnel silently DROPS malformed tags server-side (`lineEnrichmentBuildLanguagesJson()` maps invalid → null with no error). → §4.4.
- **M4** — No curator surface for unknown/junk tags already stored in song data (owner decision 3). → §5.
- **M5** — Stale comments: `ietf-language-picker.js:46-48` + `:235-237` claim "only ~14 active rows" — false once #738 applies, and it misdirected this investigation twice (rule #35). → fixed in commit 5.
- **M6** — One-time OPERATIONS step: apply the #738 card on the shared DB. Not code; runbook §7.

Explicit answer to the brief's question: **no `tblLanguages` data migration is
needed for existing rows, and nothing currently stored in tblLanguages violates
the model** — the 14 base rows are legitimate IANA subtags the import overlays
in place. Junk tags exist only in SONG data columns (that is M4's job — curated
remap, never a blind migration). The only "data migration" is pressing the
existing #738 card once.

---

## 3. M1 — Scheduled silent refresh (GitHub Action → shared DB)

### 3.1 The constraint, honestly

A GH Action has no MySQL access (shared hosting, DB not internet-exposed), and
migrations are deliberately web-run. But two facts make this easy: (a) the
server **can** fetch outbound HTTPS — `admin_refresh_iana_cldr` already fetches
IANA + CLDR from PHP; (b) the three channels share ONE MySQL, so the DB needs
refreshing **once**, not per channel.

### 3.2 Design: two independent, individually-harmless legs

**Leg A (repo baseline):** a scheduled workflow fetches the 5 upstream files,
sanity-checks them, and commits any diff to `appWeb/.sql/data/` on `alpha`.
Keeps fresh installs / offline installs current; the push triggers the normal
deploy (paths `appWeb/**`), which uploads `.sql/data/` to the alpha server.

**Leg B (live DB):** the same workflow POSTs to a new **keyed** endpoint that
runs the existing refresh core server-side (server fetches upstream itself →
overwrites deployed snapshots → re-runs the idempotent import). No ordering
dependency on Leg A or on a deploy: the server pulls from IANA/CLDR directly,
exactly as the manual button does today.

Rejected alternatives:
- *Commit-then-wait-for-deploy-then-poke-an-"apply from snapshots" endpoint*:
  couples the two legs through deploy timing for zero benefit — the server-side
  fetch already exists and is proven.
- *cPanel cron*: works (it's how `webhook-drain.php` documents its wiring) but
  the owner asked for a GH Action; the endpoint below serves either caller, so
  cron remains a free fallback.
- *GH Action holding DB credentials*: never — no direct DB path exists or should.

### 3.3 The ONE core (rule #22 / #35)

New `appWeb/public_html/includes/language_registry_refresh.php`:

```php
/** @return array{ok:bool,status:int,fetched:string[],failed:string[],migrationLog:string} */
function languageRegistryRefreshCore(): array
function languageRegistrySchemaReady(\mysqli $db): bool   // tblLanguageVariants exists AND tblLanguages.Scope exists — the #738 probe, inverted
```

`languageRegistryRefreshCore()` is the body of today's
`admin_refresh_iana_cldr` handler **hoisted verbatim** (hardcoded `$sources`
map, 30s timeouts, `strlen < 100` sanity floor, snapshot write, migration
re-run via `require`); `api.php:17584` becomes a thin delegate. The snapshot
dir resolves as `dirname(__DIR__, 2) . '/.sql/data'` from `includes/` (the
docroot's un-renamed sibling — rule #41 compliant; the migration itself already
uses the runner-safe include pattern, verified: its requires are
`function_exists`/`IHYMNS_SETUP_DASHBOARD`-guarded).

**Security invariant (CI-guarded, §6):** the endpoint accepts **no
caller-supplied URL, filename, or payload** — the only input is the key. A
leaked key can only make us re-fetch canonical IANA/CLDR data at a bounded
rate; it cannot inject data or reach any other host.

### 3.4 The endpoint

New `appWeb/public_html/language-registry-refresh.php` — standalone docroot
script, byte-for-byte the `webhook-drain.php` shape:

```
POST /language-registry-refresh.php        (X-Refresh-Key: <key>  or  ?key=)
  200 {ok:true, data:{fetched:[…], migrationLog:"…"}}   — refresh ran
  403 (no body)                                          — wrong/absent key
  503 (no body)                                          — dormant: no key configured
                                                           OR #738 schema not yet applied
  502 {error, failed:[…]}                                — upstream fetch failed;
                                                           snapshots + DB untouched
```

- Key: app-setting `language_registry_refresh_key`, registered in
  `secretSettingKeys()` (`includes/secret_crypto.php:430`) so it is encrypted at
  rest; pasted on `/manage/configuration` beside `webhook_drain_key`; compared
  with `hash_equals()`.
- Rate limit: `enforceReadRateLimitKeyed('language_registry_refresh', 6)`
  (6/hour, fail-open, rule #28-C).
- **Dormant-until-activated:** `languageRegistrySchemaReady()` false → 503. The
  first #738 apply (schema rename/widen/ADD COLUMN) stays a **deliberate human
  press of the existing card** — an unattended endpoint must never be the thing
  that first runs DDL on the shared DB. After that one press, every scheduled
  run's schema steps are `[skip]` no-ops and the refresh is data-only + silent.

### 3.5 The workflow

New `.github/workflows/language-registry-refresh.yml`:

```yaml
on:
  schedule:
    - cron: '17 4 1 * *'      # monthly, 1st, 04:17 UTC (registry moves ~monthly; off the :00/:30 marks)
  workflow_dispatch:
permissions: { contents: write }
jobs:
  snapshot-sync:               # Leg A
    # checkout alpha → curl the 5 hardcoded upstream URLs → sanity gates:
    #   IANA file: non-empty File-Date header; record count >= 99% of the bundled file's
    #   CLDR files: jq parses; ['main']['en']['localeDisplayNames'] present
    # → if git diff non-empty: commit "chore(data): refresh IANA/CLDR language snapshots"
    #   and push HEAD:alpha (the changelog.yml push pattern, PR fallback on rejection).
    #   NO [skip ci] — the deploy SHOULD run so the alpha server's .sql/data/ updates.
  db-refresh:                  # Leg B — independent; runs even if snapshot-sync found no diff
    # curl -fsS -X POST "${{ vars.IHYMNS_LANG_REFRESH_URL }}" \
    #      -H "X-Refresh-Key: ${{ secrets.IHYMNS_LANG_REFRESH_KEY }}"
    # non-200 fails the job (surfaces in the Actions tab; otherwise fully silent).
```

`vars.IHYMNS_LANG_REFRESH_URL` points at ONE channel's endpoint (recommend the
alpha host) — one shared DB means one poke updates every channel. Repo `vars` +
`secrets` mirror the existing `vars.SFTP_ENABLED` convention.

### 3.6 Failure modes (stated plainly)

| Failure | Effect | Recovery |
|---|---|---|
| Upstream down (Leg A) | No commit; job red in Actions tab | Next month / manual dispatch |
| Upstream down from server (Leg B) | 502; snapshots + DB untouched | Same |
| Key not yet configured / #738 not applied | 503 dormant; job red | Activation runbook §7 |
| Server snapshots newer than git between runs | Next deploy re-uploads git's copy; INSERT IGNORE ⇒ DB keeps every row, zero loss | Self-heals on next Leg A commit |
| IANA removes/renames a subtag | Nothing is deleted (import is additive by design); curators retire via IsActive on `/manage/languages` | Deliberate |
| **Does the card still need a human press?** | **Yes — exactly ONCE per shared DB** (first schema apply). Thereafter fully silent. | §7 |

---

## 4. M2/M3 — the picker: live-DOM boot, shared typeahead, honest fall-through

### 4.1 Fix the class, not the site: lazy first-focus boot

Change `bootIetfLanguagePicker()` so every document-dependent step — the
`getElementById` datalist resolution today, the `attach()` calls after §4.2 —
runs inside a one-time `focusin` listener on `rootEl` instead of at boot. An
element can only receive focus once it is IN the document, so this makes the
module **immune to detached construction** for every caller, past and future:
the server partial, `metadata-tab.js` (whose #1849 ordering becomes
belt-and-braces), `enrichment-panel.js`, and v1's `buildInlineIetfPicker` — no
caller-order contract to remember. `setTag()` (called pre-focus for prefill)
routes its lookups through the same lazy resolver.

### 4.2 Replace `<datalist>` with the shared typeahead (rule #43 — binding)

Assessment the re-scope asked for, honestly: even fully migrated, the current
design is wrong twice over — `action=languages` ships **all 8,273 rows
(~600 KB+ JSON)** to build an 8,273-`<option>` datalist per picker instance
(DOM cost × up to 4 instances on an enrichment card), and native datalists have
no ARIA, no loading state, no "nothing matched" signal, and no commit hook. The
in-file precedent already exists: scripts and regions use server prefix search
(`script_search`/`region_search`); **only languages load whole**. So: all four
subtag inputs move to `window.iHymnsPlaceSearch.attach()` +
`combobox-a11y.js` — never a fork (rule #43); `place-search.js` is already
loaded on every picker surface, and the module degrades to a plain input when
it is absent (matching today's graceful-degradation doctrine).

Per input:

```js
window.iHymnsPlaceSearch.attach(langInput, {
  pickMode:  'value',                       // no upsert — a registry lookup, never a mint
  minChars:  2,                             // subtags are 2-3 chars ("tw", "zh")
  debounceMs: 200,                          // keeps the picker's documented 200ms parity
  noun:      { singular: 'language', plural: 'languages' },
  searchUrl: (q) => '/api?action=language_search&q=' + encodeURIComponent(q) + '&limit=12',
  parseResults: (d) => (d.suggestions || []).map((s) => ({
      display_name: s.name + (s.nativeName && s.nativeName !== s.name ? ' (' + s.nativeName + ')' : ''),
      hint: s.code, id: s.code, code: s.code, name: s.name,
  })),
  hiddenIdInput: langCodeHidden,            // per-subtag hidden code input (attach clears it on free-typing)
  onSelect: refreshTag,
});
```

Script/region/variant inputs are identical with their own `searchUrl` + noun.
`resolveCode()` becomes: hidden code if set (a real pick) → else exact
case-insensitive match of the typed text against code/name in the last result
set → else the typed text, **flagged unknown** (§4.4). `decomposeTag()` /
`composeTag()` and their exports are untouched. The `<datalist>` elements and
`rebuildDatalist()` are deleted; the markup contract in the module doc-block,
the server partial `manage/includes/partials/ietf-language-picker.php`, and
both dynamic builders update in the same commit (rule #33: grep every consumer
— the four consumer files are enumerated in §1).

### 4.3 The search endpoints — ONE handler, four vocabularies

New in `api.php` (public — mirrors the `action=variants` precedent: registry
data is public, native clients get parity, and the picker stops depending on a
`/manage/songbooks` admin URL from editor surfaces):

```
GET /api?action=language_search&q=<text>&limit=12
  → {"suggestions":[{"code":"en","name":"English","nativeName":"English","scope":"individual"}]}
GET /api?action=script_search | region_search | variant_search   — same shape (scope omitted)
```

One shared helper `bcp47SubtagSearch(\mysqli $db, string $kind, string $q, int $limit): array`
with a `const` kind→(table, columns, probe) map — identifiers from PHP source
only, every value bound (checkpoint #5). Query shape (languages; others analogous):

```sql
SELECT Code AS code, Name AS name, NativeName AS nativeName /*, Scope AS scope — column-probed as in action=languages */
  FROM tblLanguages
 WHERE IsActive = 1 AND (Name LIKE ? OR NativeName LIKE ? OR Code LIKE ?)          -- '%q%' substring, matching script_search
 ORDER BY (LOWER(Code) = ?) DESC,             -- exact code first ("en" beats "Bende")
          (Name LIKE ?) DESC,                 -- 'q%' name-prefix next ("English" beats "Middle English")
          (Scope = 'macrolanguage') DESC,     -- when probed present
          CHAR_LENGTH(Name) ASC, Name ASC
 LIMIT ?
```

8k rows × unindexed LIKE is a trivial scan; no index migration needed. Empty
`q` → `{"suggestions":[]}`. Un-migrated tables → `[]` + `note` (the existing
sibling contract). The legacy `/manage/songbooks?action=script_search|region_search`
handlers **stay** as aliases — links outlive code (rule #33); the picker just
stops calling them. `action=languages` (full dump) also stays untouched — it is
a documented native-client contract; its weight post-#738 is a filed follow-up
(§8), not a silent behaviour change here.

### 4.4 Free-text fall-through: allowed, but never silent (M3)

- **Client:** on commit (blur / suggestion-panel close), if the composed tag
  contains any subtag that was neither picked nor exactly matched, render an
  amber `.form-text` beneath the picker: *"'engli' is not a recognised
  language subtag — it will be saved exactly as typed."* The tag preview keeps
  composing; Save stays enabled (owner decision 3 / rule #21). Additionally
  run `decomposeTag()` client-side: a grammatically-malformed primary subtag
  (like `engli`, 5 letters) upgrades the message to *"…and is not a valid
  BCP 47 tag — the server will reject it"* so the curator learns BEFORE the
  400/silent-drop, not after.
- **Server (unchanged, stated as the deliberate line):** grammar-malformed tags
  keep failing `_ietfBcp47Validate()` — a 400 on `line_translation_upsert`, a
  drop-to-null on per-line overrides. "Free text remains allowed" means
  *registry membership is never enforced* (well-formed-but-unregistered tags
  save fine and are surfaced by §5); it does NOT mean grammatical junk must
  persist. Flagged as defensible-default D3 in §9.
- No write-path hooks, no observation table: unknown-tag discovery is
  derive-live (§5, rule #44).

---

## 5. M4 — the unknown-language curator surface

### 5.1 Where: a section of `/manage/languages` (never a new page)

`?view=unknown` renders an "Unknown language tags" panel above the existing
table (server-rendered, POST-redirect actions with the page's existing CSRF —
its architecture unchanged). Entitlement: `manage_languages`, the page's own
gate = its `admin-links.php:88` advertisement (nav-parity red flag honoured).
Table opts into `.admin-table-responsive` + sortable headers (checkpoint #13).

### 5.2 The audit core — new `includes/language_tag_audit.php`

```php
function languageTagSources(\mysqli $db): array
// Derived, not typed (rule #34): INFORMATION_SCHEMA columns in this schema whose
// COLUMN_NAME IN ('Language','LanguageCode','TargetLanguage'), minus a documented
// exclusion map (tblLanguages itself = the registry; tblUserGroups etc. never match).
// Each source: ['table','column','label','remap' => 'direct'|'line-path'|'report-only'].
// Today's derivation yields: tblSongbooks.Language, tblSongs.Language,
// tblSongComponents.Language, tblLyricLines.LanguageCode, tblSongTranslations.TargetLanguage,
// tblSongRequests.Language, tblSongAlternativeTitles.Language, tblSongbookLanguages.Language,
// tblSongLanguages.Language, tblLyricLineTranslations.TargetLanguage,
// tblLyricLineAnnotations.LanguageCode, tblSearchSynonyms.Language.

function languageTagAuditScan(\mysqli $db): array
// Per source: SELECT <col>, COUNT(*) GROUP BY <col> (identifiers from the derived
// map only). Merge per distinct tag; classify each via bcp47ClassifyTag():
//   'malformed'    — fails _ietfBcp47Validate() (require_once song_importers.php — the ONE grammar check)
//   'unregistered' — grammar OK but ≥1 subtag absent from its registry table
//                    (one batched  WHERE Code IN (…)  per table, case-folded per subtag convention)
//   'inactive'     — all subtags known, ≥1 with IsActive = 0
//   'ok'           — excluded from the panel
// Runs on the ?view=unknown request only (a few GROUP-BY scans; tblLyricLines is the
// largest and LanguageCode is NULL-dominant — acceptable on demand, so NO new index
// and NO persisted results table: usage counts are derived truth, rule #44).
```

### 5.3 Actions per unknown tag

- **Remap →** (`action=remap_tag`, POST, type-the-count confirm like the #1218
  guard): from-tag → to-tag; to-tag must pass grammar and SHOULD resolve in the
  registry (warn otherwise). Per source class:
  - `direct` sources: one bound `UPDATE <table> SET <col> = ? WHERE <col> = ?`
    each — EXCEPT `tblSongTranslations.TargetLanguage`, which carries the
    schema's one real FK + `uq_Translation(SourceSongId, TargetLanguage)`: remap
    only when the to-tag exists in `tblLanguages` (offer "add to registry
    first"), and skip-with-report any row whose remap would collide with the
    unique key.
  - `line-path` (`tblLyricLines.LanguageCode` and its gated
    `tblSongComponents.LanguagesJson` shadow): NEVER a raw UPDATE (rule #25).
    Per affected song: `lyricLinesEditableComponents()` → substitute the tag in
    each component's `languages` array → `lyricLinesWriteComponents()` — the
    ONE write path, exactly the revision-restore shape, keeping line-Id
    stability and the shadow JSON in lockstep. Songs resolved via
    `SELECT DISTINCT ComponentId → SongId`; batch with a per-request cap +
    "N of M done, run again" (junk tags are low-usage in practice).
  - `report-only` (`tblSongRequests.Language` — user-typed request text): shown
    with counts, no remap button (rewriting a user's request text is not
    curation).
  - Every remap logs `logActivity('language.remap', 'language', $from, ['to'=>…, 'counts'=>…])`.
- **Add to registry** — links to the page's EXISTING Add form, pre-filled
  (`?prefill_code=<primary subtag>`); acceptance = the row existing, after
  which the tag classifies `ok` and leaves the panel. No second CRUD (rule #22).
- **Activate** — for `inactive` tags, the page's existing IsActive toggle.

---

## 6. E/F — migrations and CI guards

### 6.1 Migrations: NONE

No new table, column, or registry entry. Verified against each candidate: the
registry exists (#738); refresh needs a secret app-setting row (runtime-written
via `/manage/configuration`, like `webhook_drain_key` — not schema); the audit
derives everything live; the search endpoints add no storage. `schema.sql`
untouched ⇒ `test-schema-coverage.php` / `test-migration-registry.php` stay
green by construction. (`tblLanguages.Scope` being an ENUM is grandfathered —
rule #20 explicitly leaves existing ENUMs alone; do not churn it.)

### 6.2 New guards — each tree-derived and mutation-proven (rule #34)

1. **`tests/test-bcp47-search-endpoints.js`** (node): scan the tree for every
   `action=language_search|script_search|region_search|variant_search|languages|scripts|regions|variants`
   URL any JS/PHP emits (comment-stripped), and assert `api.php` (or the legacy
   `songbooks.php` alias) carries a matching `case`. The emit-list is derived
   by grep, never typed. *Mutation-proof:* delete the `language_search` case →
   red; point the picker at a misspelled action → red; restore → green.
2. **`tests/php/test-language-tag-audit.php`**: (a) parse `schema.sql` for
   `VARCHAR(35)` columns named `Language`/`LanguageCode`/`TargetLanguage`
   (tree-derived) and assert `languageTagSources()`'s derivation + exclusion
   map accounts for every one — a NEW language column added later fails the
   build until classified; (b) a truth table for `bcp47ClassifyTag()`
   (`engli`→malformed, `xq`→unregistered, `pt-BR`→ok, `zz-Latn`→unregistered,
   retired-subtag→inactive). *Mutation-proof:* drop one source from the map →
   red; flip a classifier branch → red.
3. **`tests/php/test-language-registry-refresh.php`**: comment-stripped source
   assertions that (a) `language-registry-refresh.php` reads NO request input
   beyond the key (no `$_GET`/`$_POST`/header read feeding a URL or filename);
   (b) the upstream `$sources` map exists exactly ONCE, in
   `includes/language_registry_refresh.php`, and `api.php`'s
   `admin_refresh_iana_cldr` contains no second copy (rule #35 — one
   mechanism); (c) the key is registered in `secretSettingKeys()` and compared
   via `hash_equals`; (d) the schema-ready dormancy gate is called before any
   fetch. *Mutation-proof:* re-inline a `$sources` array in api.php → red; add
   `$_GET['url']` to the endpoint → red.
4. **`tests/test-ietf-picker-live-dom.js`** (node): assert
   `ietf-language-picker.js` performs no top-level (boot-time)
   `document.getElementById` capture — every document lookup and `attach()`
   call sits inside the lazy `focusin` path — and that no `<datalist>`
   fingerprint (`rebuildDatalist(`, `list="ietf-`) survives in the module or
   its four consumer files (enumerated by grep at test runtime, not typed).
   *Mutation-proof:* hoist one `getElementById` back to boot scope → red;
   re-add a `list=` attribute in `enrichment-panel.js` → red.

Implementation checklist item for every guard: break → watch red → restore →
record the mutation evidence in the commit body (the #1840 (k) lesson: anchor
each assertion on the surface's own distinguishing marker, never a file-wide
"does X exist anywhere").

---

## 7. Activation runbook (the honest human steps)

1. Merge + deploy (normal flow).
2. **Once, on the shared DB:** `/manage/setup-database` → press "Run IANA +
   CLDR Import" on the #738 card (global_admin). This is the ONLY press ever
   needed; verify the card flips applied and `action=languages` count jumps to ~8,273 (the 14 base-seed codes are a subset of the IANA set — INSERT IGNORE skips them).
3. `/manage/configuration` → generate + paste `language_registry_refresh_key`.
4. GitHub → repo settings: secret `IHYMNS_LANG_REFRESH_KEY` (same value),
   variable `IHYMNS_LANG_REFRESH_URL` = `https://<alpha host>/language-registry-refresh.php`.
5. Actions → run `language-registry-refresh` once via `workflow_dispatch`;
   confirm both jobs green and the endpoint's 200 tally.

## 8. Follow-up issues to file at implementation start (rule: issue per finding)

- **F1** — Post-#738 payload weight: `action=languages` full dump (~600 KB) is
  still preloaded by the v1 editor (`editor/index.php:2084` →
  `window.iHymnsLanguageOptions` → an 8k-option component-lang datalist) and
  `getLanguageMetaMap()` (`includes/language_names.php`) loads the full table
  per request on name-resolving pages. Measure on alpha post-activation; likely
  fixes: v1 preload → `language_search`, meta-map → targeted `WHERE Code IN`.
  Not this plan's scope (v1 is the legacy surface; correctness is unaffected).
- **F2** — Retrospective issue for the #1849 detached-boot class listing the
  two sibling sites this plan fixes (enrichment-panel, v1 builder), per
  standing-tasks §2a.
- **F3** — `fk_Trans_Lang` on `tblSongTranslations.TargetLanguage` still cannot
  record `zh-Hans`-shaped counterpart tags (documented outlier,
  `schema.sql:1671-1685` says dropping it "is the change that would need
  discussing") — surface to the owner separately; NOT changed here.

## 9. Owner decisions (batched up front per standing-directives §10 — NONE block the build)

**D1 — Refresh cadence.** Monthly (cron above) vs weekly. IANA publishes ~monthly;
CLDR ~twice a year. *Recommend monthly; changing it later is a one-line cron
edit.* Need back: "monthly OK" or a cadence.

**D2 — Leg A lands as a direct push to `alpha` (changelog.yml precedent) vs a
PR.* Direct push = truly silent; PR = a review click per refresh (not "silent").
*Recommend direct push — the payload is byte-diffed canonical registry data
gated by sanity checks, and the deploy pipeline already trusts changelog.yml's
pushes.* Need back: "push" or "PR".

**D3 — Grammar line for free text.** Keep rejecting grammatically-malformed tags
(`engli`) server-side while always allowing well-formed-but-unregistered ones
(§4.4), vs storing literally anything typed. *Recommend keeping the grammar
floor — rule #21's purpose (script subtags must never fail ingest) is fully
served by the former, and the picker now warns before the curator ever hits it.*
Defensible default; proceeding with it unless overruled.

**D4 — Remap entitlement.** `manage_languages` (the page's own gate) vs
requiring `edit_songs` too, since remap writes song rows. *Recommend
`manage_languages` alone — the page is admin+global_admin by default and a
split gate on one page invites the #1587 nav-parity trap.* Defensible default.

## 10. Commit plan (one branch, ordered, each atomic + individually revertable)

1. `refactor(api): hoist the IANA/CLDR refresh into languageRegistryRefreshCore()`
   — new `includes/language_registry_refresh.php`; `admin_refresh_iana_cldr`
   becomes a thin delegate. Pure move, no behaviour change. (+ guard 3's
   one-mechanism assertions land here, mutation-proven.)
2. `feat(registry): keyed refresh endpoint /language-registry-refresh.php`
   — webhook-drain shape, `secretSettingKeys()` registration,
   `/manage/configuration` field, `api-docs.yaml`. (+ rest of guard 3.)
3. `feat(ci): scheduled language-registry-refresh workflow` — both legs +
   sanity gates.
4. `feat(api): BCP 47 subtag search actions` — `bcp47SubtagSearch()` +
   the four cases, `api-docs.yaml`. (+ guard 1, mutation-proven.)
5. `fix(editor): IETF picker — lazy live-DOM boot, shared typeahead, unknown-tag
   feedback` — module rework (§4.1-§4.4), the four consumer updates, the server
   partial, stale-comment fixes (M5). (+ guard 4, mutation-proven.)
6. `feat(admin): unknown-language audit + remap on /manage/languages` —
   `includes/language_tag_audit.php`, `?view=unknown` panel, remap through the
   ONE line write path. (+ guard 2, mutation-proven.)
7. `docs: wiki (API + Setup pages), CHANGELOG, help, .claude (ProjectBrief,
   MEMORY, handoff)` — plus this plan flipped to IMPLEMENTED with SHAs.

Per-commit audit: `php -l` / `node --check` over touched files; each commit
message records its guard's mutation evidence. Issues: file an epic +
sub-issues (M1-M5, F1-F3) at implementation start, before commit 1 (issues
precede the commits that close them).
