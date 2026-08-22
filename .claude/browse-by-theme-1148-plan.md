# Browse-by-Theme at scale — locked implementation spec (#1148 remainder: the searchable `/themes` index)

**Fable-5 design pass, 2026-08-22.** Branch at design time: `claude/ilyrics-identity-work-model` @ `14ee2886`.
**No code changed by this pass.**

**Verdict up front: #1148 is PARTIAL — the "build now" half shipped long ago; this plan is the
follow-on half plus the gaps the follow-on exposed.** The unbounded home chip wall is already gone
(replaced by the Top-8 "Popular themes" strip + a `popular_tags` endpoint); the per-theme
destination page `/tag/<slug>` also already exists (#1637, cacheable, CI-guarded). What does NOT
exist is the **searchable `/themes` A–Z index** — and this pass found four adjacent defects worth
folding in because they are the same feature's loose ends: the home "Browse all themes" reveal
re-creates the wall one click away; `popular_tags` counts **disagree** with what the tag page
actually lists (no visibility filter); `/tag/` pages have **no `<title>` entry, no sitemap
presence and no OG tags** (the issue's `seo` label is only half-served today); and the
`action=tags` api-doc claims a `songCount` field the endpoint has never returned.

| Piece | Status | Evidence |
|---|---|---|
| `popular_tags` endpoint (bound limit, usage-ranked, counts) | **DONE** | `api.php:9981-10012` |
| Home Top-8 strip with count badges + a11y suffix | **DONE** | `js/modules/home-page.js:246-289`, `includes/pages/home.php:405-417` |
| Per-theme page `/tag/<slug>` (the chip's destination) | **DONE** (#1637) | `includes/pages/tag.php`, `router.js:338-345`, `api.php:788-799`, guard `tests/test-tag-route.js` |
| Searchable `/themes` A–Z index | **NOT BUILT** | no `includes/pages/themes.php`; no router/api case; grep for `themes` finds no route |
| "Browse all themes" → still an unbounded inline reveal | **REAL (defect)** | `home-page.js:291-316` fetches `action=tags` and appends every remaining tag |
| Counts aligned with the tag page's visibility rules | **REAL (defect)** | `popular_tags` counts unfiltered (`api.php:9986-9994`); `tag.php:67-75` lists only `songVisibleSql AND songServableSql` |
| SEO: sitemap / titles / OG for theme surfaces | **REAL (gap)** | `sitemap.xml.php:86-95` (no theme URLs), `router.js:640-671` (no `'tag'` title), `index.php:291-733` (no tag/themes OG matcher) |
| Hierarchy grouping "waits on #1113" | **STALE in issue body** | `tblSongTags.ParentId` landed via #1152 (`schema.sql:2202`); grouping is unblocked on the existing table |

---

## 1. Verified current state (file:line, all under `appWeb/public_html/` unless noted)

### 1a. What the "build now" half already shipped

- **`api.php:9981` `case 'popular_tags'`** — limit `(int)`-cast + clamped 1..50 (`:9982-9983`) and
  **bound** (`bind_param('i', …)`, `:9995`); the count is
  `COUNT(m.TagId) … JOIN tblSongTagMap m ON m.TagId = t.Id GROUP BY t.Id, t.Name, t.Slug ORDER BY
  useCount DESC, t.Name ASC LIMIT ?` (`:9986-9994`) — INNER JOIN, so only tags in use appear.
  Fail-soft to `['tags' => [], 'fallback' => true]` (`:10008-10010`). Scoped per rule #17 — no
  whole-corpus load. Documented at `api-docs.yaml:6078`.
- **`api.php:9946` `case 'tags'`** — the old flat list (`SELECT Id, Name, Slug, Description …
  ORDER BY Name ASC`, unbounded, no counts). Its api-doc (`api-docs.yaml:6050-6076`) claims a
  `songCount` field (`:6076`) **it does not return** — pre-existing drift, noted in the issue's
  2026-06-05 comment, still unfixed.
- **`js/modules/home-page.js`** — `POPULAR_TAGS_LIMIT = 8` (`:246`); `renderThemeChip()`
  (`:252-263`) emits `<a href="/tag/<slug>" data-navigate="tag" class="btn btn-sm
  btn-outline-secondary theme-chip">` with a `badge rounded-pill` count carrying the
  visually-hidden `" songs"` suffix (SR reads "Easter, 42 songs"); `loadTags()` (`:265-317`)
  fetches `popular_tags&limit=8` via `apiFetch` (rule #31) and removes the whole `#448`
  card-layout wrapper when empty (`:279-284`).
- **`includes/pages/home.php:405-417`** — the `$homeCard('tags')` "Popular Themes" section
  (`#tags-section` / `#tags-list`, `aria-live="polite"`), client-populated (correct for the
  shared-cache fragment, rule #6/#30 — the fragment carries no script and no per-user data).
- **CSS** — `.theme-chip` hover-lift gated on BOTH reduced-motion predicates
  (`css/app.css:3643-3676`, incl. `body.reduce-motion .theme-chip` at `:3674`).

### 1b. The residual wall: the inline "Browse all themes" reveal

`home-page.js:291-316`: when the popular fetch fills the cap, a `moreBtn` ("Browse all themes →")
is appended; on click it fetches **`/api?action=tags`** (`:302` — the unbounded list) and appends
every not-yet-shown tag inline. At today's vocabulary (§1d) that is a one-click ~150-chip wall,
growing with every curator addition — the exact failure #1148 was filed about, one interaction
deep. The module's own doc-block (`:240-244`) calls the dedicated `/themes` index "the tracked
follow-on". `action=tags` has exactly **one** web caller: this reveal (tree grep — the only other
hit is its api-docs entry).

### 1c. The per-theme destination is a shipped, guarded contract

- `router.js:338-345` — `case 'tag'` → `{ page: 'tag', params: { slug } }`.
- `api.php:788-799` — `case 'tag'` sets `$tagSlug` and requires `includes/pages/tag.php`;
  `'tag'` is in `$_cacheablePages` (`api.php:608-621`, membership at `:620`, with the note that
  the slug in the query string keys distinct ETags — the fragment ETag is hashed from the
  rendered response per request, `:880-890`).
- `includes/pages/tag.php` — slug lookup (`:47-55`), then the song list **filtered by
  `songVisibleSql($tdb,'s') AND songServableSql($tdb,'s')`** (`:67-75`, #1694/#1765 — soft-deleted
  songs and disabled songbooks excluded), grouped by songbook (`:121-132`), themed 404 for
  empty/unknown slug (`:99-117`), #1786 sort control (`:180-188`). Defensive try/catch for a
  pre-#1152 install with no tag tables (`:81-89`).
- Guard: `tests/test-tag-route.js` (#1637) — the four-file structural agreement test this plan's
  new guard is modelled on.
- **Gap found:** `router.js updateTitle()`'s map (`:640-671`) has **no `'tag'` entry** — a theme
  page's browser/history title is the bare app name.

### 1d. The data model and its scale (why live counting wins)

- `schema.sql:2197-2214` — `tblSongTags`: `Name`/`Slug` `VARCHAR(50)` both UNIQUE,
  `Description VARCHAR(255)`, **`ParentId` self-FK** (2-level hierarchy, #1152, `:2202`),
  `CcliThemeId`, `Source` (`'curator' | 'ccli-openlyrics'`), `idx_Slug`/`idx_ParentId`/`idx_Source`.
- `schema.sql:2220-2239` — `tblSongTagMap`: PK `(SongId, TagId)`; `fk_TagMap_Tag` on `TagId` —
  InnoDB materialises an index for that FK, so a `GROUP BY`/count keyed on `TagId` is
  index-backed.
- Seed size: `appWeb/.sql/migrate-seed-theme-vocabulary.php`'s themelist heredoc carries
  **144 themes, 36 of them parent/child pairs** (counted this pass), plus whatever curator tags
  exist. Corpus ≈ 14k songs; the tag map is at most a few-thousand rows. A single indexed
  `GROUP BY` over that is sub-millisecond — **a denormalised `UseCount` column would buy nothing
  and cost a migration + a write-path maintenance obligation + a drift risk** (and rule #44:
  derive, don't store, when the live read is cheap). Rule #17 is satisfied by scope, not by
  caching.
- The **only** admin count query is `manage/tags.php:341-347` — `LEFT JOIN` (zero-use tags shown
  to curators, correctly different semantics from the public INNER JOIN), with the
  `$hasThemeCols` INFORMATION_SCHEMA probe (`:48`, `:338-340`) gating the #1152 columns for
  un-migrated installs — the probe pattern the new helper must copy.

### 1e. The count-mismatch defect

`popular_tags` counts **every** `tblSongTagMap` row; `tag.php` lists only
visible-and-servable songs (§1c). On any install with soft-deleted songs (#1694) or disabled
songbooks (#1765), the home chip says "42" and the page it links to shows fewer — a quiet
contract violation between the two ends of one link (rule #33's spirit applied to a number
instead of a param). Helper signatures verified: `songVisibleSql(\mysqli, string $alias='s')`
(`includes/song_soft_delete.php:206`) and `songServableSql(\mysqli, string $songAlias='s')`
(`includes/songbook_visibility.php:276`), both readiness-gated internally (fail-open pre-migration).

### 1f. Routing / caching / wiring infrastructure a new page rides on

- `.htaccess` catch-all serves the SPA shell for any unmatched path — **no server config change**
  for `/themes`.
- Router: parse case → `{page, params}` (`router.js:280-400`); `buildApiUrl()` (`:442-460`) maps
  `params.slug/id/code` to the query string; fragments load via `api.php?page=…`; post-load
  module wiring lives in `afterPageLoad()` — the home import at `:908-922`
  (`import('./home-page.js').then(m => m.initHomePage())`) is the rule-#30 reference shape.
- `api.php` page switch: a case per page requiring `includes/pages/<page>.php` (`:700-860`);
  `$_cacheablePages` (`:608-621`) marks same-for-everyone fragments; the person/people→musician
  normalisation (`:434-445`) is the precedent for a route alias that must be folded **before**
  the cache key.
- Navigation is delegated on `[data-navigate]` (`app.js:696-704`, `preventDefault` + SPA
  navigate); a plain anchor without `data-navigate` is left to the browser.
- In-page letter jumping precedent: `js/modules/songbook-index.js` (#111) — **module-built**
  button strip + `scrollIntoView({behavior: reduce-motion ? 'auto' : 'smooth'})` (`:161`),
  deliberately buttons-not-hash-anchors (no history entries, no popstate interaction).
- Polite announcements: `js/utils/announce.js:53` `announce(message)`.
- SEO surfaces: `sitemap.xml.php` — `$staticPages` (`:86-95`), songbook/song loops (`:107-145`),
  registry-driven musician loop (`:149-177`, the DB-outage-tolerant model); **no theme URLs
  anywhere**. `index.php` OG matchers (`:291-733`: song, songbook, writer, person/musician, work,
  setlist-shared) — **no `/tag/` or `/themes` matcher**, so theme surfaces share the generic OG
  block. The logo dropdown nav lists Home/Songbooks/Favourites/Set Lists/Stats
  (`index.php:1245-1260`); the footer nav is space-capped at six items (`:1525-1560`).
- Test infrastructure: **both runners glob** — `tools/run-node-tests.js` runs every `tests/*.js`,
  `tools/run-php-tests.php` globs `tests/php/*.php` (`test.yml:233,254`) — a new test file
  auto-registers (rule #35's mechanism, already built). `tests/php/test-fragment-inline-scripts.php`
  is tree-derived over `includes/pages/` — a new fragment is covered with **zero test edits**.

---

## 2. Design principles (inherited, not re-argued)

- **Scoped reads only** (rule #17): every query is bounded by the tag registry (~hundreds of rows)
  or a single indexed GROUP BY — never a corpus materialise.
- **Shared-cache fragments carry no per-user data and no executable script** (rules #6, #30): the
  `/themes` fragment is identical for every viewer; behaviour is wired from
  `afterPageLoad()` as a real ES module reading DOM-first.
- **One query core** (rule #22): the visible-song theme count exists ONCE; `popular_tags`, the
  `/themes` page and the sitemap all call it — a second inline `COUNT(m.TagId)` on a public
  surface is the regression.
- **A link another surface emits is a contract** (rule #33): `/tag/<slug>` is shipped and stays;
  every `/themes` emission this plan adds is asserted against its destination by a tree-derived
  guard.
- **No hardcoded theme vocabulary** (rules #23, #24): everything renders from `tblSongTags`;
  hierarchy comes from `ParentId`, never a typed list.
- **Zero schema change**: the whole feature reads existing tables. No migration, no
  registry entry, no schema.sql edit — the strongest possible dormancy story (§4).

---

## 3. Locked design

### 3.1 The ONE count core — `includes/theme_index.php` (new)

```php
/* themeIndexReady(\mysqli): bool
     — INFORMATION_SCHEMA probe: tblSongTags + tblSongTagMap exist.
   themeIndexHierarchyReady(\mysqli): bool
     — column probe for tblSongTags.ParentId (#1152), static-cached per request
       (the manage/tags.php $hasThemeCols pattern, §1d).
   themeIndexCounts(\mysqli $db, ?int $limit = null, string $order = 'name'): array
     — rows: {id, name, slug, description, parentId|null, parentName|null, useCount} */
```

The single SQL shape (hierarchy columns present only when `themeIndexHierarchyReady()`):

```sql
SELECT t.Id AS id, t.Name AS name, t.Slug AS slug, t.Description AS description,
       t.ParentId AS parentId, p.Name AS parentName,          -- gated pair
       COUNT(m.TagId) AS useCount
  FROM tblSongTags t
  JOIN tblSongTagMap m ON m.TagId = t.Id
  JOIN tblSongs s      ON s.SongId = m.SongId
  LEFT JOIN tblSongTags p ON p.Id = t.ParentId                -- gated
 WHERE <songVisibleSql($db,'s')> AND <songServableSql($db,'s')>
 GROUP BY t.Id, t.Name, t.Slug, t.Description, t.ParentId, p.Name
 ORDER BY  useCount DESC, t.Name ASC     -- $order='popular'
        |  t.Name ASC                    -- $order='name' (default)
 [LIMIT ?]                               -- bound when $limit !== null
```

Load-bearing choices:

- **INNER JOIN** — a theme with zero *visible* songs does not appear on any public surface
  (matches the shipped `popular_tags` posture; avoids ~100 dead seed-vocabulary links and the
  thin-page SEO penalty; the admin `LEFT JOIN` in `manage/tags.php` keeps showing zero-use rows
  and is explicitly out of this core's scope — different question, different audience).
- The `tblSongs` join + the two predicates are what **aligns the count with what `tag.php`
  lists** (§1e). Both helpers are internally readiness-gated, so a pre-#1694/#1765 install
  degrades to today's unfiltered count — fail-open, never a throw.
- `p.Name` sits in the GROUP BY explicitly — `ONLY_FULL_GROUP_BY`'s functional-dependency
  detection through a chained join is not something to bet a public page on.
- `$limit` is bound (`bind_param('i', …)`), never interpolated. The helper never throws to its
  caller for "tables missing" — `themeIndexReady()` false ⇒ callers render their empty state.

**Consumer 1 — `api.php` `case 'popular_tags'`** (refactor in place): keep the clamp 1..50 and
the exact response shape `{tags:[{id,name,slug,useCount}]}` (native/API contract — additive-only;
`parentId`/`parentName` are simply not emitted here), but source the rows from
`themeIndexCounts($db, $popularLimit, 'popular')`. The fail-soft catch stays.

### 3.2 The `/themes` fragment — `includes/pages/themes.php` (new)

Server-rendered, cacheable, script-free. Structure (modelled on `tag.php` + `songbooks.php`):

1. Guard: `themeIndexReady()` false or zero rows ⇒ a themed **empty-state card** ("No themes yet
   — themes appear as curators tag songs"), HTTP 200 (an index with nothing in it is not a 404 —
   contrast `tag.php`'s record-not-found).
2. Breadcrumb `Home › Themes`; header card: `<h1>Themes</h1>`, total theme count + total
   tagged-song figure (both already in hand from the one query — no extra SQL).
3. **Filter block** (static markup only; the module wires it):

```html
<div class="mb-3" id="themes-filter-block" hidden>
    <label for="themes-filter" class="form-label small text-muted">Filter themes</label>
    <input type="search" id="themes-filter" class="form-control" autocomplete="off"
           placeholder="Type to filter…">
    <p id="themes-filter-count" class="small text-muted mt-1 mb-0" role="status"></p>
</div>
<div id="themes-jump-bar" class="themes-jump-bar" hidden></div>
```

   Both hosts ship `hidden` and are revealed by the module — a no-JS visitor (and a crawler)
   gets the complete A–Z list below with zero dead controls. `role="status"` gives the match
   count an implicit polite live region (WCAG 4.1.3) with no dynamic region insertion.
4. **A–Z sections**: bucket by first letter of `Name` (mb-uppercased; non A–Z bucket `#` last).
   Per section: `<section id="themes-letter-A" data-themes-letter="A">` + `<h2>` letter heading +
   a `list-group` of rows. Each row:

```html
<a href="/tag/<?= $slug ?>" data-navigate="tag"
   class="list-group-item list-group-item-action d-flex align-items-center theme-index-row"
   data-theme-fold="<?= $fold /* lowercased name + parent name, for the filter */ ?>">
    <span class="flex-grow-1">
        <?= $name ?>
        <?php if ($parentName !== null): ?>
            <span class="text-muted small ms-1">· <?= $parentName ?></span>
        <?php endif; ?>
    </span>
    <span class="badge rounded-pill text-bg-secondary"><?= $useCount ?><span
        class="visually-hidden"> songs</span></span>
</a>
```

   - The count rides INSIDE the link ⇒ the accessible name is "Easter, 42 songs" — the same
     contract as the home chip (`home-page.js:252-263`), by the same mechanism.
   - The parent context ("· God" on a child like "Attributes") is **display only** — rows stay
     one flat A–Z list, keyed by leaf `Name` exactly as stored (#1152: the leaf lives in `Name`,
     the path is reconstructed). A grouped-by-parent browse view is deferred (§8.4).
   - `list-group-item` padding already clears the ≥44×44 tap target; no bespoke sizing.
   - `$fold` includes the parent name so filtering "god" surfaces the children of *God*.
5. Hierarchy degrade: `themeIndexHierarchyReady()` false ⇒ `parentName` is null everywhere ⇒ the
   context span never renders — the page is correct, just flat, on an un-migrated install.

### 3.3 The client module — `js/modules/themes-page.js` (new)

`export function initThemesPage()`, imported from `router.js`'s `afterPageLoad()`
(`if (page === 'themes')`, beside the home import at `:908` — the rule-#30 pattern). All inputs
read DOM-first from the fragment's `data-*`; idempotent no-op when `#themes-filter-block` is
absent (navigated away mid-import / empty-state render).

- **Reveal**: un-`hidden` the filter block and jump-bar host (progressive enhancement — they only
  exist usefully with JS).
- **Jump bar** (the `songbook-index.js` #111 shape, simplified — the sections here are static,
  so no rebuild-on-sort machinery):
  - ≥576px: a sticky strip (`position: sticky; top: …`) of one `<button type="button">` per
    letter derived from the rendered `[data-themes-letter]` sections (never a typed A–Z list —
    letters with no section simply don't exist as buttons). Click ⇒
    `section.scrollIntoView({behavior: document.body.classList.contains('reduce-motion') ?
    'auto' : 'smooth', block:'start'})` — buttons, not hash anchors, exactly as #111 chose:
    no history entries, no popstate interaction with the SPA router.
  - <576px: the strip hides (`d-none d-sm-flex`) and a `<select>` ("Jump to…", `d-sm-none`,
    `aria-label="Jump to letter"`) renders instead, `change` ⇒ same scroll. (The issue's stated
    mobile behaviour.)
- **Filter**: `input` on `#themes-filter` (no debounce needed — it's pure DOM work over a few
  hundred rows, no fetch; the issue mandates "filters the already-loaded array — no
  per-keystroke fetch"). Fold = `value.toLowerCase().normalize('NFD').replace(/\p{M}/gu,'')`
  matched against each row's pre-folded `data-theme-fold` (fold the attribute value once at
  init through the same JS fold so diacritics match from both sides). Hide non-matching rows
  (`hidden`), hide a section when all its rows hide, dim/disable the corresponding jump
  letters, and set `#themes-filter-count.textContent` to `"N of M themes shown"` (the
  `role="status"` region announces it politely; empty filter ⇒ clear the counter text so idle
  state announces nothing).
- **No fetches at all** — the module touches only the DOM. (It therefore needs no `apiFetch`
  import; nothing for rule #31 to police.)
- Listener lifecycle: registered fresh per visit against that visit's elements; once the router
  swaps the fragment, the elements are gone and the module re-inits next visit — the
  `songbook-index.js` "dead listeners are free" posture (its doc-block `:35-42`), and nothing
  here is `position:fixed`, so rule #32 does not engage.

### 3.4 Route + title + cache wiring

- `router.js` route parse: after `case 'tag'` (`:345`):

```js
case 'themes':
case 'tags':     /* forgiving alias — the people/person, work/works convention */
    return { page: 'themes', params: {} };
```

- `api.php`: normalise `if ($page === 'tags') { $page = 'themes'; }` **beside the
  person/people→musician fold at `:434-445`** (before the cache-key logic, for the same
  cache-fragmentation reason); add `case 'themes':` requiring the fragment; append `'themes'` to
  `$_cacheablePages` (`:620` region) — same-for-everyone by construction (§4).
- `router.js updateTitle()` map (`:642-670`): add **both** `'themes': 'Themes — ' + appName`
  **and the missing `'tag': 'Theme — ' + appName`** (§1c's gap — one line, this feature's own
  destination page deserves a title).
- `afterPageLoad()`: the `themes-page.js` import (§3.3).

### 3.5 Home strip: the reveal becomes the link (`home-page.js`)

Replace the `moreBtn` block (`:291-316`) with a static chip-styled link appended under the same
`tags.length >= POPULAR_TAGS_LIMIT` condition:

```js
el.insertAdjacentHTML('beforeend',
    '<a href="/themes" data-navigate="themes" class="btn btn-sm btn-link theme-show-all px-1">'
    + 'Browse all themes <span aria-hidden="true">&rarr;</span></a>');
```

- The `[data-navigate]` delegation (`app.js:696-704`) handles the SPA navigation — no listener
  in this module, no fetch, and the `action=tags` call disappears from the module entirely.
- `action=tags` then has **zero** web callers. **The endpoint stays** — it is a documented public
  API (`api-docs.yaml:6050`) that native/third-party clients may consume; published contracts
  outlive their first caller (rule #33). Its api-doc loses the phantom `songCount` field in C5.
- The reveal's removal is the deliberate UX change #1148 specified ("a single *Browse all
  themes →* affordance"); nothing else in `loadTags()` changes.

### 3.6 SEO surfaces (the issue's `seo` label, completed)

- **`sitemap.xml.php`**: `/themes` joins `$staticPages` (`priority 0.6, changefreq weekly`); a
  new registry-driven loop emits `/tag/<slug>` for every row of
  `themeIndexCounts($db)` (priority 0.5, monthly) — the musician-loop model (`:149-177`):
  try/catch, omit-on-outage, never a fatal. Same ONE core ⇒ the sitemap can never advertise a
  theme the index hides.
- **`index.php` OG matchers** (two small `elseif` blocks in the `:291-733` ladder):
  - `#^/themes$#` — static: title "Browse songs by theme — {app}", description naming the
    catalogue; no DB read.
  - `#^/tag/([a-z0-9\-]{1,50})$#` — one indexed slug lookup + the aligned count (reuse
    the core's predicates via a tiny `themeIndexOne($db, $slug)` sibling in the same helper
    file — NOT a second inline count); title "{Name} — songs by theme", description with the
    count; unknown slug falls through to the generic block (matches the writer matcher's
    posture). No custom og-image (the generic app image serves; an og-image `?tag=` mode is
    out of scope).
- **Nav**: one "Themes" `dropdown-item` (`fa-tags`) in the logo dropdown between Songbooks and
  Favourites (`index.php:1248` region). NOT the footer nav (six items, space-capped — §1f).
  Owner-flagged as trivially removable (§8.6).

### 3.7 Per-theme link contract (rule #33 ledger)

| Emitter | Emits | Destination handles? |
|---|---|---|
| Home popular chips (`home-page.js:252`) | `/tag/<slug>` + `data-navigate="tag"` | ✅ shipped (#1637), guarded by `test-tag-route.js` |
| `/themes` rows (§3.2) | same `/tag/<slug>` shape | ✅ same destination, asserted by the new guard |
| Home "Browse all themes" (§3.5) | `/themes` + `data-navigate="themes"` | ✅ new route (this plan), asserted |
| Nav dropdown (§3.6) | `/themes` | ✅ same |
| Sitemap (§3.6) | `/themes`, `/tag/<slug>` | ✅ top-level hits serve the shell; the SPA resolves |
| Typed `/tags` | router alias → page `themes` | ✅ §3.4 (normalised before the cache key) |

Nothing emits `?theme=…` today and nothing will — introducing a second URL shape for a shipped
route is the rule-#33 failure in reverse (§8.3).

---

## 4. Dormancy / no-op / shared-cache-safety proofs

- **Zero schema change.** No migration, no registry entry, no `schema.sql` edit. Every query
  reads tables that ship in `schema.sql` today; the two #1152 columns and the two visibility
  predicates are probe-gated (§3.1), so the feature is correct on a fresh install, an
  un-migrated install, and a fully-migrated one — the three docroots sharing one MySQL (rule
  #28-C's environment) can run mixed code versions without a throw.
- **`/themes` is shared-cache-safe by construction**: the fragment reads no auth state, no
  cookie, no per-user table — its inputs are `tblSongTags` ⋈ `tblSongTagMap` ⋈ `tblSongs`
  (global) only. Personalisation (filter text, jump position) is client-side, in-memory,
  post-load — never in the cached bytes (rule #6). The ETag is recomputed from the rendered
  body per request (`api.php:880-890`), so curation changes propagate exactly as they do for
  `page=tag`; a service-worker-cached copy revalidates on the same 304 terms as every other
  cacheable fragment — counts can lag one SW revalidation cycle, identically to the songbook
  counts on home (accepted, pre-existing class).
- **Byte-level deltas to existing surfaces**, exhaustively: (1) `popular_tags` counts shrink on
  installs with hidden/soft-deleted content — the §1e alignment fix; byte-identical where
  nothing is hidden (the common case); response *shape* unchanged. (2) `home-page.js` swaps the
  reveal button for a link — the deliberate #1148 UX. (3) `updateTitle` gains two entries —
  `'tag'` pages stop titling as the bare app name (bug fix). (4) `index.php` gains two OG
  matchers + one dropdown item; `sitemap.xml.php` gains URLs. Nothing else changes; `page=home`
  markup is untouched (the strip is client-rendered), so the home fragment's shared cache is
  byte-identical.
- **No new endpoint** is minted for the index page (it is a `page=` fragment, not an `action=`);
  `action=tags` is neither changed nor removed.

---

## 5. §A — Adversarial analysis

**A.1 Empty vocabulary / pre-#1152 install.** `themeIndexReady()` false (or zero counted rows) ⇒
`/themes` renders the empty-state card, HTTP 200, no throw (mysqli STRICT would otherwise
white-screen — the #1228 class; the probe + try/catch posture is mandatory, not stylistic). The
home strip already self-removes (`home-page.js:279-284`). The sitemap loop try/catches to
omission. Nothing 500s, nothing renders a dead control.

**A.2 A theme with 10 000 songs.** The index and strip are untouched (a count is a count). The
*pre-existing* exposure is `tag.php` itself: it lists every song for the tag in one unpaginated
fragment (`:67-75` — fine at today's tag sizes, quadratic-feeling at 10k). This plan deliberately
does NOT widen into tag-page pagination; it is filed as a `for consideration` issue at
implementation time (§9) so the exposure is tracked, not silently inherited.

**A.3 A theme named `Ω` / `1 Advent` / all-punctuation.** Bucketing: first mb-uppercased
character not in A–Z ⇒ the `#` bucket (always rendered last, only when non-empty). The fold
strips diacritics on both sides (§3.3) so "Éternité" files under E's *visual* letter? — no:
bucketing uses the RAW first character (Unicode-aware uppercase), folding is only for the
*filter* match. A theme whose name starts with a combining sequence lands in `#` rather than
crashing — degenerate but correct. Slugs in emitted URLs are the stored `Slug` (UNIQUE,
URL-safe by the registry's own contract) — never re-derived from `Name`.

**A.4 The deep-link the destination ignores (rule #33's own trap).** Three new emissions, one
alias: all four rows of §3.7 are asserted by the tree-derived guard (§6.1) that greps the
emitting files for `/themes` + `data-navigate="themes"` and requires the router case, api case,
fragment and cacheable-membership to exist — remove any one ⇒ red. The `tags` alias is
normalised **before** the ETag key (§3.4) — skipping that ordering would silently double-cache
identical content (the person/people lesson, `api.php:434-445`).

**A.5 Count drift between surfaces.** The failure mode this plan exists to close (§1e) can
re-open only if a second count query appears. Guard §6.2 bans any `COUNT` over `tblSongTagMap`
in the public tree outside the ONE helper (admin `manage/tags.php` exempted by path — its LEFT
JOIN semantics are deliberately different, §3.1). The OG matcher reuses `themeIndexOne()` for
the same reason — an OG description count that disagrees with the page it advertises is the
same bug in a crawler's clothes.

**A.6 The filter as a performance/a11y foot-gun.** No fetch, no debounce needed; worst case
~300 rows × a string `includes` per keystroke — microseconds. The `role="status"` counter is
the ONLY live region the page adds; announcing on every keystroke is the *intended* WCAG 4.1.3
behaviour for a filter (match count), and clearing it when the filter empties prevents idle
chatter. The jump `<select>`/strip are real form controls (keyboard-native); `scrollIntoView`
honours the reduce-motion predicate both ways (§3.3). Focus is NOT moved on jump — moving focus
on a scroll affordance would trap keyboard users mid-list (the #111 module made the same call).

**A.7 The cacheable fragment betraying a future personalisation.** If someone later adds
"pin my favourite themes" server-side into this fragment, the shared cache would leak one
user's pins to everyone — the exact rule-#6 trap. The fragment's doc-block must state (as
`home.php:34-43` does for intappsFlag) that any per-viewer divergence belongs in a client-side
apply step, never in the rendered bytes. Spec'd into C2's annotation obligations.

**A.8 What would make each piece wrong.** Helper: an inline second count (A.5); unbound
`$limit`; unprobed `ParentId` select (STRICT throw on un-migrated installs); LEFT JOIN on the
public surface (zero-song dead links + thin pages). Fragment: an inline `<script>` (rule #30 —
`test-fragment-inline-scripts.php` already reds it with zero new test code); per-user reads
(A.7). Module: hash-anchor jump links (popstate/history pollution, §1f); a fetch-per-keystroke
(the issue's explicit ban); a hardcoded A–Z letter list (derive from the rendered sections).
Home: keeping the reveal alongside the link (two competing affordances, one of them the wall).
Routing: aliasing after the cache key (A.4); emitting `?theme=` anywhere (§3.7).

---

## 6. CI guards (tree-derived, mutation-proven — rule #34)

Both runners glob (§1f) — new files auto-register; every guard's first run must be proven able
to fail (break → red → restore, recorded in the commit verification).

1. **`tests/test-themes-route.js` (new)** — sibling of `test-tag-route.js` (#1637), same
   "read the shipped source" structural style, covering the cross-file agreement no unit test
   sees:
   - Existence: `includes/pages/themes.php`, `js/modules/themes-page.js`.
   - **Tree-derived emissions**: grep `js/modules/*.js` + `includes/**/*.php` + `index.php` for
     `data-navigate="themes"` / `href="/themes"` (never a typed list of emitters) — assert ≥ 2
     found (home + nav) and that for the set found, `router.js` has the `case 'themes'` (+
     `case 'tags'` alias) route, `api.php` has the `case 'themes':` page require, `'themes'` is
     in the `$_cacheablePages` array literal, and the api-side `tags`→`themes` normalisation
     sits **above** the `$_cacheablePages` membership line (ordering assertion — A.4).
   - `afterPageLoad` imports `./themes-page.js` under a `page === 'themes'` branch; `updateTitle`
     has BOTH `'themes'` and `'tag'` entries.
   - `home-page.js` contains NO `action=tags` reference (the reveal is gone) and DOES emit the
     `/themes` link; both chip renderers (grep for `visually-hidden`-suffixed count in
     `home-page.js` + `themes.php`) carry the `" songs"` accessible-name suffix.
   - Mutation proofs: delete the router case → red; move the alias fold below the cacheable
     check → red; restore.
2. **`tests/php/test-theme-index.php` (new)** — the one-core guard:
   - Functional: `theme_index.php` parses; `themeIndexCounts` exists; its SQL string contains
     `songVisibleSql` AND `songServableSql` call sites and a bound (`?`) limit — never an
     interpolated one.
   - Structural, tree-derived: enumerate every `COUNT` + `tblSongTagMap` co-occurrence under
     `api.php`, `includes/pages/`, `includes/*.php`, `sitemap.xml.php` (comment-stripped first —
     the #1676-family lesson); assert each hit is inside `theme_index.php` itself. Path-scoped
     exemption: `manage/` (the admin LEFT JOIN, §3.1) — an *exemption by directory*, not by
     count, so a new public inline count can never hide behind the allowlist (rule #34's
     narrowness edge: the guard must not fire on the legitimately-different admin query).
   - Assert `api.php`'s `popular_tags` case contains a `themeIndexCounts(` call and no
     `JOIN tblSongTagMap` literal of its own.
   - Mutation proofs: re-inline the old query into `popular_tags` → red; drop `songServableSql`
     from the helper → red; restore.
3. **Existing guards that cover this work with zero edits** (stated so nobody duplicates them):
   `test-fragment-inline-scripts.php` (tree-derived over `includes/pages/` — the new fragment);
   `test-api-client-usage.js` / `test-import-graph.js` (module hygiene); `test-tag-route.js`
   (the destination half of every chip). Verify all stay green; none need touching.

---

## 7. Commit breakdown (one branch, one PR to `alpha`, atomic, smallest-safest-first)

**C1 — `feat(themes): one visible-song theme-count core; popular_tags aligns with the tag page (#1148)`**
`includes/theme_index.php` (probe-gated, §3.1) + the `popular_tags` refactor onto it. Response
shape unchanged; counts now honour #1694/#1765 visibility. Tests: guard 2 (mutation-proven).
Verify: `php -l`, full suites, break-red-restore log. References #1148 (does not close).
Self-contained: nothing consumes the new file's index mode yet; the endpoint's only behavioural
delta is the documented count alignment.

**C2 — `feat(themes): searchable /themes A–Z index page (#1148)`**
`includes/pages/themes.php` (§3.2) + `js/modules/themes-page.js` (§3.3) + the route/title/cache
wiring (§3.4: router cases + alias, api case + normalisation + `$_cacheablePages`, both
`updateTitle` entries, the `afterPageLoad` import) + the small CSS block (`.themes-jump-bar`
sticky strip, reduce-motion-gated like `.theme-chip`). Tests: guard 1. The flagship; depends on
C1 (the page reads the core).

**C3 — `feat(home): "Browse all themes" navigates to /themes; retire the inline reveal (#1148)`**
The `home-page.js` swap (§3.5) only. Depends on C2 (never emit a link before its destination
exists — rule #33 applied to our own commit ordering). Guard 1's "no `action=tags` in
home-page.js" assertion flips from red to green at this commit — C2 lands that assertion
tolerant (reveal-or-link accepted), C3 tightens it; the tightening is part of C3.

**C4 — `feat(seo): sitemap, OG tags and nav entry for the theme surfaces (#1148)`**
`sitemap.xml.php` static entry + registry loop; the two `index.php` OG matchers (+
`themeIndexOne()` in the helper file); the dropdown nav item (§3.6). Tests: extend guard 2
(sitemap call-site is enumerated by its tree-derivation automatically) + guard 1 (nav emission
joins the derived set automatically). **Closes #1148** — the close comment cites C1–C4 SHAs,
records the §1 table (what was already done and when), the A.2 carve-out, and the §9 follow-ups.

**C5 — `docs(themes): api-docs, changelog, wiki + close-out sweep`**
`api-docs.yaml`: fix the `action=tags` phantom `songCount` (§1a), note `popular_tags`' aligned
counts; CHANGELOG; Wiki (API + Architecture pages); `.claude/` docs + handoff per
`standing-tasks.md`. No code.

Ordering rationale: C1 is a pure server-side refactor with one documented behavioural fix; C2
is additive (new route, nothing links to it yet); C3 flips the home affordance only once the
destination exists; C4 is emission-only on top of proven destinations; C5 is prose. Each commit
reverts cleanly alone (C3's revert restores the reveal against a still-working `action=tags`).

---

## 8. Owner sub-decisions surfaced (defaults picked; **none block** — implementation can start on the defaults and every one is a small later change)

1. **Top-N strip size — default: keep 8** (`POPULAR_TAGS_LIMIT`, `home-page.js:246`, one line;
   the server clamp already allows 1..50). Shipped at 8 per the issue's own spec; two wrapped
   lines on mobile. Change = one constant.
2. **Live GROUP BY vs denormalised `UseCount` — default: live, no schema.** Measured scale
   (§1d): ~150–300 registry rows, thousands of map rows, FK-index-backed — sub-millisecond,
   and the fragment is ETag-cached on top. A denorm column would need a migration + write-path
   maintenance in every tagging funnel + drift risk, to speed up a query that is not slow
   (rules #17 scope-not-cache, #44 derive-don't-store). Escalation path if the vocabulary ever
   grows 100×: a denorm column maintained in `manage/tags.php`'s write paths — recorded here so
   nobody re-designs from scratch.
3. **URL shape — default: `/themes` for the index; `/tag/<slug>` stays the per-theme URL.**
   `/tag/<slug>` is a shipped contract (#1637 — chips, cache, sitemap-to-be, the CI guard);
   renaming to `/theme/<slug>` breaks months of emitted links for cosmetics, and `?theme=` would
   be a second shape nothing needs (rule #33 both ways). The `tags` router alias (§3.4) catches
   the natural typo; a `/theme/<slug>` alias is NOT added (nothing emits it; aliases exist for
   links that exist). Cheapest to change now, near-impossible later — say the word before C2
   if `/theme/` is preferred.
4. **Hierarchy presentation v1 — default: flat A–Z with muted parent context on child rows**
   (§3.2). The #1152 `ParentId` is honoured (context + filter-through-parent) without a second
   view mode. A grouped "browse by category" presentation is a clean later enhancement on the
   same helper (it already returns `parentId`/`parentName`); building both now double-scopes
   the page. The issue's "wait for #1113" deferral is obsolete (§1 table) — this default is the
   unblocked middle path.
5. **Zero-visible-song themes hidden from all public surfaces — default: yes** (INNER JOIN,
   §3.1). The seeded vocabulary alone would otherwise contribute ~100 empty rows/dead links and
   thin crawlable pages. Curators still see zero-use tags on `/manage/tags` (LEFT JOIN,
   untouched).
6. **Nav entry — default: yes, logo dropdown only** (§3.6; not the six-item footer). One line
   to remove.
7. **`action=tags` endpoint — default: keep** (documented public API; zero web callers after
   C3). Retiring it is an API-deprecation conversation, not a side effect of a UX commit.

---

## 9. Issue actions on landing

- **#1148**: close at C4 (SHAs C1–C4 + the §1 already-shipped table with its historical commit
  refs; note the stale "wait on #1113" line and that §8.4's default supersedes it; cite §8's
  defaults so the owner can veto any in one sentence).
- **File at implementation time**:
  - (a) `for consideration` — **paginate/virtualise `tag.php`'s song list** for very large
    themes (A.2; today unbounded, fine at current sizes).
  - (b) `for consideration` — **grouped-by-parent view on `/themes`** (§8.4's deferred half;
    the helper already returns everything it needs).
  - (c) retrospective note on #1637's issue (or a small new issue) — the missing `'tag'`
    `updateTitle` entry fixed here in C2, naming the commit (standing-tasks §2: work done
    without its own issue gets a retrospective record).
- **#1147** (parent, Home & UI modernisation): tick the #1148 line on landing.
- **#1113** (taxonomy): comment that `/themes` consumed the #1152 `ParentId` hierarchy directly
  and #1113's eventual taxonomy work should build on the same `theme_index.php` core rather
  than a parallel query.
