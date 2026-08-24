# Search synonyms activation (#1903 item 1) — implementation plan

**Status:** locked spec, ready to implement. Owner greenlit 2026-08-21: seed British↔American
spelling pairs + hymnody archaisms from a NAMED source, curators maintain it going forward, and a
self-maintenance/re-import option. Planning pass only — no code changed by this document.
**Branch:** `claude/ilyrics-identity-work-model` (one PR, four commits, per CLAUDE.md).
**Predecessor scoping:** `.claude/wave4-actionable-remainder-plan.md` §0.6 (three parts: CRUD
surface, query-expansion arm, seed data — this plan is that mini-spec expanded and locked).

## Goal (one paragraph)

Activate the dormant `tblSearchSynonyms` table so live song/lyric search expands a query term into
its known equivalents (Saviour↔Savior, colour↔color, thee/thou↔you, o'er↔over) — implemented as
**query rewriting inside the existing bound FULLTEXT boolean expression** (zero SQL-shape change,
byte-identical when no synonym matches), fed by a **symmetric, one-hop, capped (≤4/term)** expansion
map loaded from the table; maintained by curators on a new **`/manage/search-synonyms`** admin page
(gated by a new `manage_search_synonyms` entitlement, mirroring the external-link-types registry
page); seeded from **named sources** (Wikipedia "American and British English spelling differences"
classes cross-checked against VarCon/SCOWL; Early Modern English pronoun/verb paradigm; lexicographic
variants) via a **`migrate-seed-search-synonyms.php`** migration with `Source='seed-*'` provenance so
a **"Re-import seed"** button can refresh the vocabulary without ever clobbering curator rows.

---

## §1 Verified current state (all anchors read 2026-08-21 on this branch)

### 1.1 `tblSearchSynonyms` is fully dormant

- **DDL (live installs):** `appWeb/.sql/migrate-search-synonyms.php:62-71` — created by the #1142
  migration (now the #1039 Part A card). Columns: `Id INT UNSIGNED AI PK`,
  `PrimaryTerm VARCHAR(120) NOT NULL`, `Synonym VARCHAR(120) NOT NULL`,
  `Language VARCHAR(35) NULL DEFAULT NULL`, `SortOrder INT UNSIGNED NOT NULL DEFAULT 0`,
  `UNIQUE KEY uq_Term_Syn (PrimaryTerm, Synonym, Language)`.
- **Schema mirror:** `appWeb/.sql/schema.sql:4616-4627` (byte-identical to the migration's CREATE).
- **Registry:** `manage/includes/migration-registry.php:2359-2384` — the `search-synonyms` card;
  its probe ORs `!_migProbe_tableExists($db,'tblSearchSynonyms')` with the folded-column/index/
  backfill checks. **The table therefore already exists on every migrated install.**
- **Readers/writers: NONE.** `grep -r tblSearchSynonyms` over the tree hits only the two files
  above plus two `.claude/` docs (`orphan-inventory-2026-07-30.md:236`,
  `wave4-actionable-remainder-plan.md:27,84`). No SELECT, no INSERT, no admin page, no API action.
  Confirmed dormant exactly as §0.6 recorded.
- **No `Source`, `IsActive`, `Note`, or `CreatedAt` columns exist** — provenance and
  disable-without-delete need the §3 additive batch.
- **Unique-key gotcha (verified against the DDL):** `Language` is NULL-able and part of
  `uq_Term_Syn`; MySQL UNIQUE treats NULLs as distinct, so two identical `(PrimaryTerm, Synonym,
  NULL)` rows are BOTH insertable. Idempotency can therefore **never** rely on the unique key
  alone — every write path (seed + CRUD) must existence-check first (§5.3, §6.3).

### 1.2 The search path it bolts onto (`includes/SongData.php`)

- `searchSongs()` — `:3119`. Flow: scripture expansion (`:3134`) → jsonMode fallback (`:3137`) →
  `<3`-char / no-token LIKE route (`:3185-3186`) → #1908 space-less-script LIKE-first arm
  (`:3203-3205`) → FULLTEXT ladder: required pass `_booleanPrefixExpr($tokens, true)` (`:3213`),
  folded twin (`:3224-3238`), `_runFulltextSearch(...)` (`:3240`), loose any-term pass (`:3245-3248`)
  → credits attach (`:3254`) → `_attachLyricsSnippets($results, $tokens, $foldReady)` (`:3260`) →
  writer/composer fallback (`:3266`), SOUNDEX (`:3274`), page-1 curated merges (`:3281`).
- `_tokenizeSearch()` — `:3295-3300`: strips every BOOLEAN-mode operator char (`+ - > < ( ) ~ * "
  @`) from user input, then whitespace-splits. **This is the injection sanitiser; expansion terms
  must be equally operator-free (§5.4 — the fold guarantees it).**
- `_booleanPrefixExpr()` — `:3307-3317`: emits `+tok*` (required) / `tok*` (loose) per token.
  Callers: primary `:3213`, scripture expr `:3215`, folded primary `:3229`, folded scripture
  `:3231`, folded loose `:3236`, loose `:3246`. Six call sites, all inside `searchSongs()`.
- `_runFulltextSearch()` — `:3450-3533`: dual-arm `MATCH(raw) OR MATCH(folded)` where the folded
  expression is a **bound string parameter** (`:3456-3470`, `:3515-3521`). The whole query text
  reaches SQL as `?`-bound values — **changing only the bound expression string changes zero SQL
  shape and zero placeholder counts.** Empty `$foldedExpr` ⇒ byte-identical single-arm SQL
  (documented `:3448`).
- `_searchByLike()` — `:3540-3622`: sub-3-char + space-less-script route; folded LIKE arm gated by
  `_searchFoldReady(false)` (`:3550`).
- `_searchFoldReady()` — `:3413-3433`: the memoised INFORMATION_SCHEMA index probe, fail-closed to
  the byte-identical path. **This is the gate pattern the synonym loader copies (§5.5).**
- `_attachLyricsSnippets()` — `:3718-3791`: scans component lines for the query needles (raw then
  folded). A row matched ONLY via a synonym would today be a match-with-no-snippet — the same
  silent degradation class #1039 fixed for folds; §5.6 extends the needle set.
- **Sole consumer:** `api.php` `search` case, `:995` (`searchSongs($query, $bookId, $limit + 1, …)`;
  over-fetch-by-one pagination). No other production caller
  (`title_normalize.php` mentions it in a doc-comment only).
- **The fold:** `ihymns_search_fold()` = alias of `ihymns_normalize_title()`
  (`includes/title_normalize.php:260-263` / `:138-173`): NFKD + mark-strip + lowercase +
  `IHYMNS_FOLD_SPECIAL` + **strips everything that isn't a Unicode letter/number/whitespace**
  (`:170`). Consequences used below: (a) a folded term can never contain a FULLTEXT boolean
  operator; (b) `o'er` folds to `oer`, `heav'n` to `heavn`.

### 1.3 The CRUD reference shape (`manage/external-link-types.php`, read in full)

Auth + entitlement gate `:29-38` (`isAuthenticated()` → `userHasEntitlement('manage_external_link_types', …)`
→ 403); schema probes `:46-58` (INFORMATION_SCHEMA, error-logged, page renders a "run
/manage/setup-database" card when absent `:310-323`); classic form POST with CSRF `:61-66`;
transaction + `logActivity()` `:133-196`; shared partials `head-libs.php`/`head-favicon.php`/
`admin-nav.php`/`admin-footer.php` (`:286-291`, `:529`); `$activePage` `:39`. Note this page
pre-dates rule #29's helper and still calls `validateCsrf()`; the NEW page uses
`validateCsrfRequest()` (`manage/includes/auth.php:1227`) per rule #29.

### 1.4 Entitlement + nav + help machinery (all derived-guarded)

- Map: `includes/entitlements.php` (`manage_external_link_types => ['admin','global_admin']`
  `:213`; the registry-surface cluster `:191-223`). JS mirror: `js/modules/entitlements.js:136-140`.
- Labels + grouping: `manage/entitlements.php:91` ('Content structure' group) + `:185-186`.
- Nav registry: `manage/includes/admin-links.php:75-98` ('Song Library' group; row shape
  `[page, url, icon, label, entitlement, group]`).
- Derived CI guards that auto-cover a new page/entitlement: `tests/php/test-entitlement-parity.php`
  + `tests/test-entitlement-parity.js` (PHP↔JS map lockstep), `tests/php/test-admin-gate-parity.php`
  (page gate ≡ nav entitlement, derived from the tree), `tests/php/test-admin-help-coverage.php`
  (every nav id needs a `manage/help.php` section or a reasoned alias),
  `tests/php/test-admin-tables-sortable.php` (list-page conventions #842/#844).

### 1.5 The seed precedent (`appWeb/.sql/migrate-seed-theme-vocabulary.php`, #1152)

Named-source vocabulary (OpenLyrics themelist, heredoc at `:154-156`), additive probe-guarded
ALTERs byte-identical to schema.sql (`:56-64`), `Source` provenance column (`'curator' |
'ccli-openlyrics'`, `:21`), idempotent re-runnable data phase (`:44-54`), rule-#41-free include
handling. `migrate-search-synonyms.php:116-119` is the in-family precedent for the
`IHYMNS_INCLUDES_DIR` docroot-include pattern (rule #41).

### 1.6 The guard template

`tests/php/test-search-fold.php:1-55` — the three-half shape this feature's guard copies:
(1) functional truth table, no DB; (2) LIVE half against a scratch table, loudly skipped without
MySQL; (3) tree-derived structural guard + a narrow windowed check on `SongData.php` internals.
Mutation-proven per rule #34.

---

## §2 Locked decisions (with justification)

| # | Decision | Locked choice | Why |
|---|----------|---------------|-----|
| D1 | Expansion mechanism | **Query rewriting** inside the bound boolean expression — per-token group `+(saviour* savior*)`; **never a JOIN** | A JOIN cannot parameterise `AGAINST()` per row; rewriting changes only a bound STRING value — zero SQL-shape change, zero new placeholders, provable byte-identical no-op (§1.2). MySQL boolean mode's `+(a b)` = "require any of" is exactly per-token OR-expansion. |
| D2 | Directionality | **Symmetric (undirected edge)**, applied both ways at query time; stored once per pair; **no direction/flag column** | Spelling + archaism pairs are equivalences; both directions aid recall (modern user finds archaic text AND vice versa). Expansion only ever ADDS an OR-arm, so a "wrong-direction" match costs precision, never correctness. A flag the code doesn't act on is a rule-#44 vanity field — excluded; §A covers the escape hatch. |
| D3 | Chain control | **One hop only** (direct edges, never expand an expansion) + caps: **≤4 alternates/token**, **≤16 added terms/query**; alternates **<3 chars skipped** in FT arms | §0.6's ≤4 cap; one-hop makes a synonym chain structurally impossible; the <3 skip avoids both the InnoDB `innodb_ft_min_token_size=3` dead zone and short-prefix noise (`ye*` → "yellow"). |
| D4 | Prefix-star on alternates | Alternates ≥3 chars get the **same trailing `*`** as user tokens | `savior` exact would miss the FT tokens `savior's`/`saviors` (apostrophe-in-token + plural); the star recovers them, and seeding STEMS (honour/honor) then covers derivatives (honoured/honored) for free. |
| D5 | Stored term form | Both sides stored **already folded** through `ihymns_search_fold()`; **single token per side** (post-fold whitespace ⇒ reject), 2–120 chars; write path folds + validates | One canonical lookup key (query tokens fold for lookup, so Saviour/saviour/SAVIOUR all hit); the fold strips every boolean operator (`title_normalize.php:170`) so **a stored term can never inject FT syntax** — the fold IS the write-side sanitiser; single-token keeps D1's per-token grouping trivial (multi-word is a future app-only change — VARCHAR(120) already holds it). |
| D6 | Scope of expansion | **FULLTEXT ladder only** (required + loose passes, raw + folded arms, both title-only and title+lyrics modes) + **snippet needles**. LIKE / SOUNDEX / writer-composer / curated merges / scripture-expansion tokens: untouched | Mirrors the documented narrow-trade-off precedent (`SongData.php:3106-3116` — the langSubtags doc-block); LIKE expansion would change SQL shape (new placeholders) for the lowest-value route; scripture expansions are canonical book names. Snippets MUST be included or synonym hits ship as match-with-no-snippet (§1.2, rule-#33 half-ship class). |
| D7 | Gate | `searchSynonymsReady()` — memoised INFORMATION_SCHEMA probe for `tblSearchSynonyms.IsActive` (table + the new columns), fail-closed to `[]` map ⇒ byte-identical expressions | Mirrors `_searchFoldReady()` (`:3413`); gating on the NEW column (not the table) keeps an un-migrated v1-table install STRICT-safe (rule #28-C / red-flag list: the loader's `WHERE IsActive = 1` would throw where only the #1142 table exists). |
| D8 | Schema delivery | **New `migrate-seed-search-synonyms.php`** (mirrors #1152's naming + shape): probe-guarded ALTERs for the §3 columns + the idempotent seed. `migrate-search-synonyms.php` untouched. Requires the table to exist (prints "[SKIP] … run the Search Synonyms card first" and returns, probe stays pending) — `$migrationOrder` places it after `search-synonyms` so Apply-All just works | Keeps the already-applied #1039 card stable (no confusing re-pend of a card operators believe done); #1152 is the worked example of "columns + named-source seed in one dedicated migration". |
| D9 | Seed provenance | `Source` values: `'curator'` (default), `'seed-en-spelling'`, `'seed-en-archaic'`, `'seed-en-variant'`; seed rows get `Language='en'` | Mirrors #1152's `Source` pattern; versionable by suffix if a seed class is ever revised; `'en'` non-NULL also makes `uq_Term_Syn` actually bite for seed rows (§1.1 gotcha). |
| D10 | Curator suppression of a seed pair | **Disable (`IsActive=0`), not delete** — re-import existence-checks BOTH orientations including inactive rows, so a disabled pair stays disabled forever; Delete stays available but the UI warns "Re-import seed will restore this pair — disable instead to keep it suppressed" | This is what makes "self-maintenance" and "never clobber curator edits" compatible without a tombstone table. |
| D11 | Entitlement | **New `manage_search_synonyms` => `['admin','global_admin']`** (not `edit_songs`) | Every sibling registry surface (`manage_tags`, `manage_tunes`, `manage_publishers`, `manage_external_link_types` — `includes/entitlements.php:191-223`) is a dedicated `manage_*` at admin+; a synonym row changes SEARCH BEHAVIOUR for every user, which is registry curation, not song editing. Defensible-default flag: trivially changeable to include `editor` later (one line in two mirrored maps). |
| D12 | CSRF | `validateCsrfRequest((string)($_POST['csrf_token'] ?? ''))` on every POST action | Rule #29's blessed helper (accepts fresh session token OR genuine same-origin), strictly more robust than the reference page's `validateCsrf()`. |
| D13 | CSV export/import | **Omitted in v1** (deliberate) | The vocabulary is ~85 seed rows + incremental curator pairs; the seed migration IS the bulk channel; a CSV parser is new validation surface with no demonstrated need (rule #44's spirit). Revisit only if curators ask — filed as a `for consideration` note on #1903, not built. |
| D14 | Per-language filtering | Loader ignores `Language` in v1 (loads all active rows) | We don't know the query's language; expansion is additive-only so a cross-language false expansion merely adds an OR-arm. The column already exists — a future per-language filter is app-code only, no migration. Stated in the admin UI help text so curators know Language is informational for now. |

---

## §3 DDL — the one additive batch (rule #20 one-pass)

Added by `migrate-seed-search-synonyms.php` via per-column probe-guarded ALTERs
(`_migThemes_addCol` shape, #1152 precedent), **byte-identical** (COMMENT included) to the
declarations added inline to the `tblSearchSynonyms` CREATE TABLE in `schema.sql:4619-4626`
(rule #19). One `@migration-adds` doctag per column.

```sql
ALTER TABLE tblSearchSynonyms ADD COLUMN Source    VARCHAR(40)  NOT NULL DEFAULT 'curator' COMMENT 'Provenance: curator | seed-en-spelling | seed-en-archaic | seed-en-variant (VARCHAR not ENUM, growable) (#1903)';
ALTER TABLE tblSearchSynonyms ADD COLUMN IsActive  TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'Disable = suppress from expansion without deleting; a disabled seed row survives re-import (#1903)';
ALTER TABLE tblSearchSynonyms ADD COLUMN Note      VARCHAR(255) NULL DEFAULT NULL COMMENT 'Curator reference note (#1903)';
ALTER TABLE tblSearchSynonyms ADD COLUMN CreatedAt TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Row creation time (#1903)';
```

No new indexes (the loader's full read of ~a few hundred rows needs none; admin list likewise).
No `UpdatedAt`, no `Weight`, no direction flag, no `ApprovedBy` — nothing the code doesn't act on
(rule #44); `logActivity()` is the audit trail. `SortOrder` stays as shipped (unused, harmless).

**"What would force a second migration?" stress:** directionality → deliberately excluded (D2/§A);
multi-word phrases → VARCHAR(120) already fits, app-validation change only; per-language activation
→ `Language` exists; seed versioning → `Source` string suffix; confidence/weighting → not acted on,
and if ever wanted it's one additive ALTER under rule #20's "genuinely unforeseeable" carve-out.
Nothing else in the feature's acted-on set touches schema.

---

## §4 Seed data — named sources + the actual list

Lives as `IHYMNS_SEARCH_SYNONYM_SEED` in **`includes/search_synonyms.php`** (docroot, so the admin
page requires it directly and the migration reaches it via the rule-#41
`IHYMNS_INCLUDES_DIR` pattern already used at `migrate-search-synonyms.php:116-119`). Every entry
`[termA, termB, source]`, both terms pre-folded canonical; convention (not semantics): termA =
modern/American form, termB = British/archaic/variant.

**Named sources (doc-blocked in the file, rule #23 discipline):**
- `seed-en-spelling` — Wikipedia, *American and British English spelling differences*
  (https://en.wikipedia.org/wiki/American_and_British_English_spelling_differences), classes
  -our/-or, -re/-er, -ise/-ize, -ence/-ense, -ll-/-l-, misc.; membership cross-checked against
  **VarCon (SCOWL)** (http://wordlist.aspell.net/varcon/), restricted to hymnody-relevant stems
  (the corpus is hymn titles/lyrics — importing VarCon wholesale would bloat the map with pairs
  no hymn contains; same finite-named-list shape as #1152's themelist).
- `seed-en-archaic` — Early Modern English (KJV/hymnody) second-person pronoun paradigm and
  irregular verb forms: Wikipedia *Thou* + *Early Modern English* grammar tables
  (https://en.wikipedia.org/wiki/Thou, https://en.wikipedia.org/wiki/Early_Modern_English).
- `seed-en-variant` — lexicographic spelling variants attested in Merriam-Webster entries for the
  specific words (e.g. hallelujah *var.* alleluia; Immanuel/Emmanuel).

**The initial list (~85 rows; the generation rule is "apply the named class to a hymnody-relevant
stem", so review adds/removes stems, not mechanism):**

- **-our/-or:** savior/saviour, honor/honour, favor/favour, labor/labour, neighbor/neighbour,
  splendor/splendour, color/colour, valor/valour, endeavor/endeavour, vigor/vigour, armor/armour,
  ardor/ardour, fervor/fervour, harbor/harbour, succor/succour, behavior/behaviour, odor/odour,
  clamor/clamour.
- **-re/-er:** center/centre, scepter/sceptre, sepulcher/sepulchre, luster/lustre, miter/mitre.
- **-ise/-ize:** baptize/baptise, recognize/recognise, realize/realise, evangelize/evangelise.
- **-ence/-ense:** defense/defence, offense/offence, pretense/pretence.
- **-ll-/-l- + misc doubling:** traveling/travelling, marvelous/marvellous, counselor/counsellor,
  fulfill/fulfil, willful/wilful, worshiping/worshipping, worshiped/worshipped.
- **misc spelling:** gray/grey, plow/plough, mold/mould, draft/draught.
- **Archaic pronouns (`seed-en-archaic`):** you/thee, you/thou, you/ye†, your/thy, your/thine,
  yours/thine, yourself/thyself.
- **Archaic verb forms:** are/art, were/wert, was/wast, have/hast, has/hath, do/dost, does/doth,
  did/didst, shall/shalt, will/wilt, can/canst, would/wouldst, should/shouldst, says/saith,
  spoke/spake.
- **Archaic adverbs/nouns:** before/ere, often/oft, near/nigh, between/betwixt, while/whilst,
  morning/morn, brothers/brethren, whoever/whosoever.
- **Poetic elisions (fold-collapsed — the folded lyrics column stores them apostrophe-stripped,
  which plain modern queries can't reach):** over/oer, ever/eer, never/neer, heaven/heavn,
  power/powr, flower/flowr, every/evry.
- **Variants (`seed-en-variant`):** hallelujah/alleluia, hallelujah/halleluia, alleluia/halleluia,
  emmanuel/immanuel, jesus/jesu.

† `ye` (2 chars) is stored but FT-inert under D3's <3 skip in the expansion direction *toward* it;
the `you`-ward direction still works when a user types "ye" of length ≥3? — no: a 2-char QUERY
takes the LIKE route (no expansion, D6). Kept as data for the future LIKE-arm extension; documented.

**Deliberate exclusions (documented in the seed file):** the productive `-eth/-est` verb class
(cometh, dwelleth, …) is unenumerable pair-by-pair AND already half-covered: prefix-star means a
modern query `love` matches `loveth`/`lovest` today; stopword-class pairs (unto/to) whose modern
side is FT-inert; theological near-synonyms (Jehovah/Yahweh, Zion/Sion) — curator calls, not seed.

---

## §5 The expansion arm — exact insertion spec (`includes/search_synonyms.php` + `SongData.php`)

### 5.1 The shared core (new file `includes/search_synonyms.php`)

Framework-free (the `title_normalize.php` shape — requirable from api, manage, and the migration).
PURE functions first (testable without DB, the `lyricLinesBuildDesiredFromComponents()` precedent):

- `searchSynonymsBuildMap(array $rows): array` — rows `[{PrimaryTerm, Synonym}]` → symmetric
  adjacency `['saviour' => ['savior'], 'savior' => ['saviour'], …]`, deterministic order
  (insertion order per term), de-duped.
- `searchSynonymsExpandTokens(array $tokens, array $map, int $perTokenCap = 4, int $totalCap = 16): array`
  — returns **groups**: `list<list<string>>`, group[0] = the original token verbatim, rest = its
  alternates. Lookup key = `ihymns_search_fold($token)`; one hop only; alternates `< 3` chars or
  equal to the token's own fold are skipped; per-token cap 4; running total cap 16 (once hit,
  remaining tokens pass through as singletons). No map hits ⇒ every group is a singleton.
- Constants: `IHYMNS_SEARCH_SYNONYM_SEED`, `IHYMNS_SEARCH_SYNONYM_SOURCES` (the allowed `seed-*`
  value list the CRUD + tests validate against — one central map, never page-local).

DB-touching functions (all take `\mysqli $db`):

- `searchSynonymsReady(\mysqli $db): bool` — memoised (static) INFORMATION_SCHEMA probe for
  `tblSearchSynonyms.IsActive`; try/catch fail-closed false (D7).
- `searchSynonymsLoadMap(\mysqli $db): array` — `[]` unless ready; else
  `SELECT PrimaryTerm, Synonym FROM tblSearchSynonyms WHERE IsActive = 1` → `searchSynonymsBuildMap()`;
  memoised per request; try/catch fail-closed `[]`.
- `searchSynonymsPairExists(\mysqli $db, string $a, string $b, ?string $lang): bool` — bound
  existence check in **both orientations**, ignoring IsActive (D10). (Language matched with
  NULL-safe equality; the §1.1 unique-key NULL gotcha is why this exists.)
- `searchSynonymsApplySeed(\mysqli $db): array{added:int, skipped:int}` — for each seed entry:
  fold-validate, `searchSynonymsPairExists()` (skip if present in either orientation, active or
  not), else bound INSERT with `Source`, `Language='en'`, `IsActive=1`. Idempotent by
  pair-existence — never UPDATE, never resurrect, never touch `Source='curator'` rows. Consumed by
  BOTH the migration and the admin page's Re-import action (rule #22 — one seed core).

### 5.2 `SongData::searchSongs()` changes (minimal diff, all inside the FULLTEXT else-branch)

1. After `$tokens = self::_tokenizeSearch($query)` (`:3181`): load the map
   (`require_once … search_synonyms.php; $synMap = searchSynonymsLoadMap($this->db);`).
2. `_booleanPrefixExpr()` (`:3307`) gains group support: each element may be a `string`
   (current behaviour, byte-identical output) or a `list<string>` group → emits
   `+(a* b* c*)` / `(a* b* c*)`; a singleton group emits exactly `+a*` (NOT `+(a*)`) so
   no-synonym output is **byte-identical** to today. (One builder — extending in place avoids the
   rule-#35 two-builders-must-agree trap.)
3. Required pass `:3213`: `$primary = self::_booleanPrefixExpr(searchSynonymsExpandTokens($tokens, $synMap), true);`
   Loose pass `:3246`: same with `false`. Folded twins `:3229/:3236`: same call over
   `$foldedTokens` (alternates are stored folded, so the same map serves both arms; FULLTEXT under
   `utf8mb4_unicode_ci` is case-insensitive, so folded alternates are valid against the raw
   columns too). **Scripture-expansion exprs (`:3215`, `:3231`) stay on plain token lists (D6).**
4. Snippets `:3260`: pass the union — `$this->_attachLyricsSnippets($results, array_merge($tokens,
   $expansionTerms), $foldReady)` where `$expansionTerms` = the flattened alternates actually used
   (returned alongside the groups or recomputed via a tiny helper). `_attachLyricsSnippets` itself
   is unchanged — it already lowercases + fold-retries each needle.
5. Nothing else changes: `_runFulltextSearch()` untouched (the expression is just a different bound
   string), `_searchByLike()` untouched, ORDER BY/pagination/langSubtags untouched.

### 5.3 Byte-identical no-op proof obligations

(a) empty map ⇒ `searchSynonymsExpandTokens()` returns all-singletons ⇒ extended
`_booleanPrefixExpr` emits the exact pre-change string (golden-string test); (b) un-migrated
install ⇒ `searchSynonymsReady()` false ⇒ empty map ⇒ (a); (c) probe failure ⇒ try/catch ⇒ (a).
Same contract language as `_searchFoldReady` (`:3406-3410`).

### 5.4 Injection safety

Expansion terms reach SQL ONLY inside the `?`-bound `$ftQuery`/`$foldedExpr` strings (rule #5
holds); within the boolean expression they cannot smuggle operators because stored terms are
fold-canonical (the fold strips `+ - > < ( ) ~ * " @` wholesale — `title_normalize.php:170`) and
the write path + seed validate `ihymns_search_fold($t) === $t`, single-token, length 2–120.

### 5.5 Performance

One `SELECT` of ~100-300 tiny rows per **search** request (memoised for the request), PHP-side map
work O(tokens). No FULLTEXT index changes, no new query passes, no JOIN.

### 5.6 Failure honesty

A synonym-only hit must carry a snippet (step 4) — omitting it is the #1039 match-with-no-snippet
silent degradation, and it is a listed guard target (§7 G1-s).

---

## §6 The curator CRUD surface — `manage/search-synonyms.php`

Mirrors `external-link-types.php` structurally (§1.3), with the newer conventions layered on:

1. **Gate:** `isAuthenticated()` → `userHasEntitlement('manage_search_synonyms', …)` → 403 echo.
   `$activePage = 'search-synonyms';`.
2. **Wiring (one commit, all five files, all derived-guarded §1.4):**
   `includes/entitlements.php` (`'manage_search_synonyms' => ['admin', 'global_admin']`, doc-comment
   citing #1903, placed in the `:191-223` registry cluster); `js/modules/entitlements.js` mirror;
   `manage/entitlements.php` — add to the `'Content structure'` group (`:91`) + `$ENTITLEMENT_LABELS`
   (`['Manage search synonyms', 'Curate the word-equivalents (Saviour↔Savior, thee↔you) that song search expands automatically']`);
   `manage/includes/admin-links.php` — `['search-synonyms', '/manage/search-synonyms',
   'bi-arrow-left-right', 'Search Synonyms', 'manage_search_synonyms', 'Song Library']`;
   `manage/help.php` — a `search-synonyms` section (plain-English: what a pair does, symmetric
   behaviour, disable-vs-delete for seed rows, Re-import).
3. **Schema probe:** INFORMATION_SCHEMA for table + `IsActive` column; absent ⇒ the standard
   "Schema not yet installed → run /manage/setup-database" card (reference page `:310-323`).
4. **Read:** one query, all rows, ordered `PrimaryTerm, Synonym`; rendered as
   `<table class="admin-table-responsive">` with sortable headers (#842/#844 — Term A
   `data-col-priority="primary"`, Term B primary, Language tertiary, Source secondary (badge:
   `Seed` warning-subtle / `Curator` secondary-subtle), Active secondary, Note tertiary, Actions).
5. **POST actions** (all `validateCsrfRequest()`-gated (D12), bound params, `logActivity()`
   `search_synonyms.add|update|toggle|delete|reseed`):
   - `add` — two term fields (+ optional language, note). Server folds both via
     `ihymns_search_fold()`; rejects: empty-after-fold, multi-token, `<2`/`>120` chars, equal
     folds, pair already exists in either orientation (via `searchSynonymsPairExists`). Inserts
     `Source='curator'`.
   - `update` — edit note/language of a row; term edits are delete+add (terms are the identity —
     editing them in place would silently re-key the pair).
   - `toggle` — flip `IsActive` (the D10 suppression mechanism; primary affordance for seed rows).
   - `delete` — any row; for `Source LIKE 'seed-%'` rows the confirm copy warns
     "Re-import seed will restore this pair — disable instead to keep it suppressed" (D10).
   - `reseed` — calls `searchSynonymsApplySeed()`; success banner reports "Added N new seed
     pair(s), skipped M already present". Adds only; never updates/resurrects (§5.1).
6. **UI copy** explains the mechanics curators must know: pairs are symmetric; terms are normalised
   (lowercased/folded) on save; pairs under 3 letters don't take effect in search yet; Language is
   informational for now (D14).
7. **No inline `<script>` concern** — this is an admin page with its own document (not an SPA
   fragment); a small progressive-enhancement script matching the reference page's pattern is fine.

---

## §7 CI guards (tree-derived, mutation-proven — rule #34)

**G1 — new `tests/php/test-search-synonyms.php`** (the `test-search-fold.php` three-half shape):

- *Functional (no DB):* map symmetry (`(a,b)` row ⇒ both directions); per-token cap (5 alternates
  ⇒ 4 used); total cap; one-hop (a–b, b–c ⇒ expanding `a` never yields `c`); `<3`-char alternate
  skip; folded lookup (`Saviour` token hits the `saviour` key); **golden byte-identity** — for a
  singleton-groups input, extended `_booleanPrefixExpr`-shape output `+amaz* +grac*` exactly (via
  the shared core's group renderer if extracted, else ReflectionMethod on the private static —
  precedent: this suite already reflects into SongData structurally); operator-strip truth
  (`ihymns_search_fold('+savior*') === 'savior'`).
- *Seed integrity (no DB):* every `IHYMNS_SEARCH_SYNONYM_SEED` entry: fold-canonical
  (`fold(t)===t`), single-token, 2–120 chars, sides differ, `Source` ∈
  `IHYMNS_SEARCH_SYNONYM_SOURCES`, no duplicate pair including reversed orientation.
- *Structural (tree-windowed, generous regex windows per the #34 lesson):* `searchSongs()` calls
  `searchSynonymsExpandTokens` in BOTH the required and loose passes and on BOTH raw + folded
  token lists; the snippet call receives the merged needle set (G1-s); the loader gates on the
  `IsActive` column probe; scripture exprs remain unexpanded.
- *LIVE optional half* (scratch table, loudly skipped without MySQL, `IHYMNS_TEST_DSN`): a row
  containing only "Savior" matches `+(saviour* savior*)` in boolean mode; ditto the folded arm.
- *Mutation proofs recorded in the commit body:* delete a cap → red; drop the reverse edge → red;
  remove one `searchSynonymsExpandTokens` call site → structural red; un-fold a seed entry → red.

**G2 — existing derived guards that auto-cover (no new code, verify they fire):**
`test-entitlement-parity.php`/`.js` (new key in both maps), `test-admin-gate-parity.php` (page gate
≡ nav), `test-admin-help-coverage.php` (help section required), `test-admin-tables-sortable.php`
(#842/#844 conventions), `test-schema-coverage.php` (`@migration-adds` ×4 ↔ schema.sql),
`test-migration-registry.php` (probe not always-true), `test-deploy-paths.php` (rule-#41 include in
the migration), `test-csrf-same-origin.php` (write-endpoint check, if it derives POST handlers).
Part of C3's verification is deliberately breaking one of each (e.g. drop the JS mirror line) and
watching the derived guard go red.

---

## §8 Commit plan (one PR, smallest-safest first; each atomic + revertable)

**C1 — schema + shared core + seed** (`feat(search): tblSearchSynonyms provenance columns + named-source seed core (#1903)`)
- `appWeb/.sql/migrate-seed-search-synonyms.php` (D8: probe-guarded §3 ALTERs, byte-identical to
  schema.sql, `@migration-adds` ×4, rule-#41 `IHYMNS_INCLUDES_DIR` include of the seed core,
  table-absent early-out, seed apply + summary counts).
- `appWeb/.sql/schema.sql` — the 4 columns added inside the `tblSearchSynonyms` CREATE (`:4619-4626`).
- `manage/includes/migration-registry.php` — `seed-search-synonyms` entry after `search-synonyms`;
  probe: `!tableExists || !columnExists(Source) || !columnExists(IsActive) || !columnExists(Note)
  || !columnExists(CreatedAt) || !hasSeedRows` (new `_migProbe_hasSeedSynonyms`: ≥1 row with
  `Source LIKE 'seed-%'`; STRICT-safe via the leading table/column existence arms).
- `includes/search_synonyms.php` (§5.1 core + `IHYMNS_SEARCH_SYNONYM_SEED` §4).
- `tests/php/test-search-synonyms.php` — functional + seed-integrity halves.
- *Verify:* `php -l` all touched; run the new test + full CI-faithful suite; mutation-prove seed
  checks. *Issue:* comment on #1903 with the SHA + locked decisions.

**C2 — query-expansion arm** (`feat(search): synonym expansion inside the FULLTEXT boolean expression (#1903)`)
- `includes/SongData.php` — §5.2 steps 1–4 only.
- `tests/php/test-search-synonyms.php` — structural + golden byte-identity + LIVE halves.
- *Verify:* `php -l`; full suite; mutation-prove (cap, call-site removal); manual spot-check
  transcript (`/api?action=search&q=saviour` finds a Savior-only title) recorded on the issue.

**C3 — curator CRUD + entitlement + Re-import** (`feat(manage): /manage/search-synonyms curator page (#1903)`)
- `manage/search-synonyms.php` (§6), the five wiring files (§6.2), help section.
- *Verify:* `php -l`, `node --check` on touched JS; full suite (G2 derived guards are the net —
  deliberately break one mirror to watch it fire, restore); screenshot/transcript on the issue.

**C4 — docs + close-out** (`docs: search synonyms (#1903)`)
- `CHANGELOG.md`, wiki (Search/API architecture page + admin guide page), `api-docs.yaml` only if
  it narrates search behaviour (no parameter surface changes), `.claude/ProjectBrief.md` note,
  handoff. Close #1903 item 1 (or the whole issue if item 2 already landed via C2 of the wave-4
  plan) with SHAs + evidence per standing-tasks §2.

Deploy note for the operator: after merge-to-alpha deploy, run the new **Seed search synonyms**
card on `/manage/setup-database` (migrations are never auto-applied — red-flag list item; the
expansion arm is a verified no-op until then, D7).

---

## §A Adversarial — what would make each part wrong

- **A1 (schema):** relying on `uq_Term_Syn` for idempotency — NULL `Language` makes duplicate rows
  legal (§1.1), so any INSERT…ON DUPLICATE approach silently duplicates; every write goes through
  the existence check. Mirrors #37's "idempotent by existence, not key" lesson.
- **A2 (no-op contract):** the extended `_booleanPrefixExpr` emitting `+(a*)` for singleton groups
  would break byte-identity (different bound string ⇒ different FT parse) — the golden-string test
  is the tripwire. Equally: loading the map on the LIKE/jsonMode routes would be wasted work but
  harmless; loading it OUTSIDE a try/catch would make a table hiccup fail the whole search
  (the rule-#31 lesson shape: a bug in an enrichment must not fail the request).
- **A3 (STRICT trap):** the loader running `WHERE IsActive = 1` on a #1142-only install throws
  `mysqli_sql_exception` and would white-screen search — D7 gates on the COLUMN, not the table
  (the #1228/#1229 class from the red-flag list).
- **A4 (chain explosion):** transitive expansion (you→thee→? …) or uncapped alternates is the
  query-bloat failure §0.6 warned about; one-hop + 4/16 caps are asserted and mutation-proven.
- **A5 (precision rot):** short alternates with prefix-star (`ye*` → yellow) — the <3 skip; a
  curator adding a broad pair (god↔lord) degrades precision for everyone — the mitigation is the
  entitlement gate (admin+, D11), `logActivity`, and one-click disable; not a code guard.
- **A6 (half-ship, rule #33):** expansion without snippet needles ships synonym hits as
  match-with-no-snippet (G1-s guards); a nav entry without a page gate, help section, or JS
  entitlement mirror is caught by the G2 derived guards — but only if C3 actually runs them
  red-once (rule #34: a guard never seen red proves nothing).
- **A7 (seed resurrection):** re-import that upserts (the #1152 promotion behaviour) would
  resurrect a curator-disabled pair — here re-import is INSERT-if-absent-in-either-orientation
  ONLY (D10); the guard asserts `searchSynonymsApplySeed` contains no UPDATE.
- **A8 (directionality regret):** if a genuinely one-way pair ever emerges, symmetric-only storage
  can't express it — accepted: expansion is additive-only so the failure mode is extra results,
  never missing ones; the escape is one additive ALTER + app change (rule #20 carve-out),
  documented here so it isn't re-litigated.
- **A9 (fold drift):** if `ihymns_search_fold()` ever changes (a #1908-class fix), stored terms
  fold-canonical under the OLD fold may no longer equal `fold(term)` — lookups miss silently. The
  seed-integrity test (`fold(t)===t` over the seed) turns red on the next run, and the fix is a
  refold pass over the table (the `refold-search-columns` card precedent,
  `migration-registry.php:2386`). Noted in the core's doc-block.
- **A10 (migration path):** a column-0 literal `/public_html/` require in the new migration fatals
  on alpha/beta (rule #41) — use `IHYMNS_INCLUDES_DIR`; `test-deploy-paths.php` scans `.sql/`.
- **A11 (multi-word future):** allowing multi-word terms later must change the group renderer to
  quoted-phrase FT syntax (`"it is"`) — a deliberate app-only extension point; the write-path
  single-token validation is what keeps today's renderer sound.
