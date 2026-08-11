# #1786 Option B — User-controllable MULTI-LEVEL sorting for the PUBLIC app's card/list surfaces

**Status:** design complete (rev 2), ready to build. **Branch:** `claude/issue-sweep-fixes-89`.
**Tracker:** #1786 (admin half DONE — `js/modules/admin-table-sort.js` multi-column module + guard
`tests/php/test-admin-tables-sortable.php`). This plan covers the owner-chosen Option B: the public
app, whose catalogue surfaces are **card/list layouts, not `<table>`s**, so the admin module cannot
attach and a sibling mechanism is needed.

Everything below is decision-complete for a Sonnet builder. New sub-decisions hit during rev 2 are
resolved with defensible defaults, flagged inline as `⚑ N1`–`⚑ N6` and summarised in §0.2.

---

## 0. DECISIONS — RESOLVED 2026-08-11 (owner)

| # | Question (rev 1) | **Owner's answer** |
|---|---|---|
| **D1** | Single-level "Sort by + direction" vs multi-level | **MULTI-LEVEL.** Public card lists must support "sort by A, then by B" like the admin tables — with a card-appropriate control (no column headers exist to shift-click). Reuse the existing multi-key comparator; the new work is the control UX + persisting an ordered `[{key,dir},…]` SPEC instead of a single key. Keyboard-operable + `aria-live` announced. |
| **D2** | Four core surfaces vs everything | **ALL public listings now.** The formerly-deferred surfaces (tags, musicians, tunes, publishers, identifier pages, works) fold INTO this build alongside `/songbooks`, `/songbook/<abbr>`, `/favorites`, `/search`. Guard covers them all; only genuinely-permanent exclusions remain (home ranked sections, set lists, stats), each with a recorded reason. |
| **D3** | URL param vs localStorage | **Device localStorage AND account-synced when signed in** (no URL param). Reuse the per-user-preference-sync pattern the card-layout feature already established; anonymous → localStorage only; signed-in → account read (localStorage as offline/first-paint fallback) + write-through to both on change, with a defined precedence + merge rule; `validateCsrfRequest()` on the write; dormant/no-op-safe (rule #9); one-pass migration IF schema were needed (rules #19/#20). **Investigation result (§7.1): NO schema is needed** — the store already exists. |

### 0.2 New sub-decisions (defensible defaults — do not stall; trivially changeable)

- **⚑ N1 — Max 3 sort levels.** The admin badge glyphs allow 9; on a card control 3 covers every
  real "A, then B, then C" need, keeps the panel small on mobile, and bounds the persisted spec.
  Enforced client-side (the "then by" button disables) and server-side (sanitiser truncates).
- **⚑ N2 — Account store rides the EXISTING namespaced `user_settings` endpoint** (namespace
  `list_sorts`) rather than new bespoke endpoints — see §7. Belt-and-braces
  `validateCsrfRequest()` is added to the **namespaced** POST branch only (the `live_follow_extend`
  precedent, api.php:17573-17586); the legacy whole-blob branch stays byte-for-byte (shipped
  native-app contract, api.php:4116-4121). Fallback if touching the shared branch is vetoed in
  review: a dedicated `list_sort_save` case delegating to the same `userSettings*` pure helpers.
- **⚑ N3 — Precedence: account wins on read; write-through on change; no silent auto-upload.**
  Details + merge rule in §7.3. The known legacy whole-blob wipe hazard is documented (§7.4) with a
  filed follow-up, not silently absorbed.
- **⚑ N4 — No entitlement gate on sort prefs.** Any authenticated user may sync them. Unlike card
  layout (`customise_own_card_layout` + the group `AllowCardReorder` veto, card_layout.php:410-457),
  a sort order is transient view-state, not shared layout state; there is nothing for an org to
  veto. Adding a gate later = one check in the write path.
- **⚑ N5 — Search carries multi-level sort as `sort=key.dir,key.dir` (≤ 3 tokens)**, each token
  validated against a fixed allow-list and mapped to hardcoded ORDER BY fragments (§6.4).
- **⚑ N6 — The HOME songbook grid stays excluded.** The owner's D2 list names the catalogue pages,
  not the home; the home grid duplicates `/songbooks` one tap away and already carries the #448
  customise toolbar — two stacked control chromes on the landing page serves nobody. Adopting it
  later = the same partial + three `data-sort-*` attrs on `includes/pages/home.php:246-383`.
- **⚑ (carried from rev 1)** Persistence granularity is per-SURFACE (one pref for all
  `/songbook/<abbr>` pages); the sync store is keyed by surface id so per-book granularity later is
  a key-shape change only.

---

## 1. THE MECHANISMS THIS BUILDS ON (and must not fork)

### 1.1 The card-layout split — the fragment-cache architecture template (rule #6)

`includes/card_layout.php:24-56`: `/api?page=home` (and `songbooks`, `songbook`, `song`, `search`,
`musician`, `work`, `tag` … — `api.php:600-613` `$_cacheablePages`) is a **shared-cache, ETag'd
fragment served identically to every visitor**. The server emits the **canonical default**;
per-viewer state is applied **client-side after the fragment lands** (`applyCardLayout()`,
`js/modules/card-layout.js:404-466`, wired from `router.js afterPageLoad()`). Sorting gets the
same split:

- **Baked into the cached fragment** (same-for-everyone, derived from song data): the sort
  **control markup** and per-item **`data-sort-*` values**. Precedent: the `isOfficial` badge,
  argued safe-to-cache at `includes/pages/home.php:278-284`.
- **Applied client-side after injection** (per-viewer): the viewer's saved sort spec
  (localStorage + account). Never a per-user ETag; never server-side personalisation of a cached
  fragment.

### 1.2 The admin sort module — the multi-key comparator to EXTRACT, not copy

`js/modules/admin-table-sort.js` already owns the multi-level core, pure and exported:
`makeCompare(type, direction)` (lines 68-86) and **`multiKeyCompare(keys, valsA, valsB)`** (lines
103-109) — walk the ordered levels, first non-zero wins, caller tie-breaks on original index for
stability. That IS the public comparator. Copying it would be the `_bsls_*` re-fork the red-flags
list bans; importing `admin-table-sort.js` from the public bundle drags in its module-load side
effect (`bootSortableTables()` auto-runs at import, lines 288-295). Rule #36's lesson — the shared
thing must be small enough to adopt — says: **extract `makeCompare` + `multiKeyCompare` verbatim
into a new pure, DOM-free `js/utils/sort-compare.js`**; both modules import from it (C1).

### 1.3 ⚠ A third, ad-hoc sorter already exists and must be ABSORBED

`js/modules/songbook-index.js` (#111) already injects a **"# Number / A-Z Title" toggle** on
`/songbook/<abbr>` pages with ≥ 20 songs (built at lines 72-84; sorts at 123-149 by scraping
`.song-title` textContent; rebuilds its alphabet-strip letter map at 150-171). No article fold, no
direction, no persistence, no announcement, single-level only. **The new mechanism replaces this
toggle** (the `/manage/duplicate-songs` absorbing `/manage/song-link-suggestions` precedent):
songbook-index keeps its alphabet strip, drops its own sort UI, and rebuilds the letter map by
listening for `EVT_LIST_SORT_CHANGED` (§5.2). Two competing sort authorities on one page is the
divergence the modularity rule exists to stop.

### 1.4 The account-preference store — investigated per D3

Two candidate mechanisms exist; **the second is the right one**:

1. **Card-layout's bespoke endpoints** (`card_layout_get`/`card_layout_save_user`/
   `card_layout_reset_user`, api.php:8382-8455) → `cardLayoutSaveUserOverride()` merging the
   subtree `cardLayouts.<surface>` into **`tblUsers.Settings`** (a JSON column;
   card_layout.php:252-341). Auth rides the `ihymns_auth` cookie / bearer via
   `getAuthenticatedUser()`; entitlement-gated. This proves the STORE (`tblUsers.Settings`) and
   the client pattern (`applyCardLayout()`'s cookie-auth fetch + fail-soft), but its bespoke
   endpoints pre-date the generic mechanism below.
2. **The namespaced `user_settings` endpoint** (#1671 F5, api.php:3976-4130 +
   `includes/user_settings.php`): `GET /api?action=user_settings&namespace=<ns>` returns one
   subtree (`userSettingsSubtree()`, user_settings.php:238); `POST {namespace, settings}` does a
   read-modify-write that **replaces only that subtree** (`userSettingsMergeNamespace()`,
   user_settings.php:222; size-capped via `userSettingsRejectReason()`, which maps machine reason
   codes → HTTP statuses per rule #35). The doc-block's stated purpose is exactly this use: *"a
   write contract that a SECOND product can share: name a namespace and only that subtree is
   replaced."* Namespace grammar `^[a-z][a-z0-9_]{1,31}(\.[a-z][a-z0-9_]{1,31})?$`
   (user_settings.php:162-165) — hence **`list_sorts`**, not camelCase.

**Consequence: no new table, no new column, no migration.** `tblUsers.Settings` has existed since
before #448; there is no un-migrated state to be dormant against — rule #9 is satisfied by the
existing endpoint's own fail-soft posture plus the client's try/catch (§7.5). The coordinator's
"one-pass additive migration if a schema row is needed" clause is answered: it is not needed, and
adding a dedicated table for a ≤ 1 KB preference blob when the forward-looking store already exists
would itself violate rule #20's spirit.

---

## 2. SURFACE INVENTORY — every public card/list surface (D2: all in scope unless reasoned out)

| # | Surface | File / lines | Render mode | Sortable container → items | Current default order | Cacheable? | Verdict |
|---|---------|--------------|-------------|----------------------------|-----------------------|------------|---------|
| 1 | `/songbooks` tiles | `includes/pages/songbooks.php:48-225` | server HTML | `.row.g-3` → `.col-*` tile divs (line 82) | Name, article-aware #150 (`getSongbooks()` → `ihymns_sort_by_title_key`, SongData.php:594-655) | yes | **IN — DOM mode** |
| 2 | `/songbook/<abbr>` songs | `includes/pages/songbook.php:341-380` | server HTML | `.list-group.song-list` → `a.song-list-item` | numbered ASC then un-numbered tail by `LOWER(Title)` (`getSongsSlimIndex`, SongData.php:2283+, degradation note 2432-2440) | yes | **IN — DOM mode** (absorbs §1.3 toggle) |
| 3 | `/search` results | `includes/pages/search.php:144-154` shell + `js/modules/search.js:272-369` | **JS-rendered, server-paginated** (PAGE_SIZE 50 + "Load more" offset) | `#search-results-list` | `ORDER BY relevance DESC, SongbookAbbr, Number` (SongData.php:3277; LIKE path 3343) | shell yes | **IN — server-sort (re-fetch) mode** — client DOM-sort of a paginated list sorts only the loaded page (§9.1) |
| 4 | `/favorites` | `includes/pages/favorites.php:66-71` + `js/modules/favorites.js:462-576` | **JS-rendered from localStorage array** | `#favorites-list` | insertion order = date-added ASC (`favorites.push({…addedAt})`, favorites.js:100-107) | no | **IN — array mode** |
| 5 | `/tag/<slug>` | `includes/pages/tag.php:175-210` | server HTML, **grouped by songbook** (one `.song-list` per book, source-level one loop) | per-group `.song-list` → `a.song-list-item` | `ORDER BY SongbookAbbr, Number, Title` (tag.php:72) | yes | **IN — DOM mode, multi-container** (within-group sort; grouping is the page's information architecture and is preserved) |
| 6 | `/musician/<slug>` discography (+ `/writer/*` alias, api.php:704-721) | `includes/pages/musician.php:988-1022` | server HTML, **grouped by role** | per-role `.song-list` → items | `ORDER BY SongbookAbbr, Number` (musician.php:341) | yes | **IN — DOM mode, multi-container** |
| 7 | `/tune/<slug>` song list | `includes/pages/tune.php:634+` | server HTML | `.song-list` items | `ORDER BY SongbookAbbr, Number, Title` (tune.php:329/340) | no | **IN — DOM mode** |
| 8 | `/publisher/<slug>` songbook list | `includes/pages/publisher.php:183+` | server HTML | songbook rows | `ORDER BY b.Name` (publisher.php:183) | no | **IN — DOM mode** (keys: name + song count if the row renders one — builder verifies at adoption) |
| 9 | `/work/<slug>` member songs | `includes/pages/work.php` | server HTML | member-song list | work-defined order | no | **IN — DOM mode**, with the work's own curated order as the Default (a work's movement order is meaningful — Default label "Work order") |
| 10 | `/iswc` + `ipi`/`isni`/`ccli`/`bowi`/`isrc` | `includes/pages/identifier.php:227+` | server HTML | `.song-list` items | per-scheme resolver order | no | **IN — DOM mode** |
| 11 | Home Popular / Recently-Viewed / Theme chips | `home.php:387-417` + `js/modules/home-page.js` | JS-rendered | list-groups / chips | server-ranked (views-30d / recency / usage) | yes | **OUT (permanent) — rank IS the semantic**; re-sorting a popularity list falsifies it |
| 12 | Home songbook grid | `home.php:246-383` | server HTML | `.row.row-cols-*` | as #1 | yes | **OUT (⚑ N6)** |
| 13 | Set lists / shared set list | `setlist.php`, `setlist-shared.php` + `setlist.js` | JS-rendered | user-ordered | **manual order is the feature** (own reorder UI) | no | **OUT (permanent)** — sort would fight manual reorder; the admin guard's `songbooks.php` Order-column precedent (test-admin-tables-sortable.php:95-97) |
| 14 | `/stats` | `stats.php` + `router.js:1255-1368` | JS-rendered | frequency bars | rank-ordered | no | **OUT (permanent) — leaderboards** |
| 15 | Song-page related/translations | `song.php` containers + `router.js:1377-1461` | JS-rendered | 5-item capped | relevance-scored | (excluded when gating on) | **OUT (permanent) — curated shortlist** |
| 16 | Musician "As Compiler" books list | `musician.php:960-985` | server HTML | plain `.list-group` (NOT `.song-list`) | SortOrder | yes | **OUT** — a short curated credit list; also outside the guard's fingerprint by construction |

### Per-surface sort keys (Default always = today's server order, restored exactly)

| Surface id | Default label | Keys (`key` → label, type, initial dir) |
|---|---|---|
| `songbooks` | Name (A–Z) | `name` → Name, text, asc · `abbr` → Abbreviation, text, asc · `songs` → Song count, number, **desc** |
| `songbook-songs` | Number | `number` → Number, number, asc · `title` → Title, text, asc · `writers` → Writer, text, asc |
| `search` | Relevance | `title` → Title, text, asc · `number` → Songbook & number, number, asc (server-side, §6.4) |
| `favorites` | Date added | `added` → Date added, date, **desc** · `title` → Title, text, asc · `book` → Songbook & number, text, asc |
| `tag-songs` | Number | `number`, `title` (as songbook-songs; per-group) |
| `musician-songs` | Songbook & number | `number`, `title`, `book` → Songbook, text, asc (per-group) |
| `tune-songs` | Songbook & number | `number`, `title`, `book` |
| `publisher-books` | Name (A–Z) | `name` → Name, text, asc (+ `songs` if rendered) |
| `work-songs` | Work order | `number`, `title`, `book` |
| `identifier-songs` | Songbook & number | `number`, `title`, `book` |

Key values are server-computed: title-ish → `ihymns_title_sort_key()` (`includes/sort_helpers.php:29-48`,
the ONE #150 article-aware fold — never re-implemented in a page); numbers raw ints; `book` →
`mb_strtolower($abbr) . ' ' . str_pad((string)$number, 6, '0', STR_PAD_LEFT)` (a composed key so a
single level gives the natural "Songbook, then number" read).

---

## 3. THE MULTI-LEVEL CONTROL (D1) — card-appropriate, no headers required

### 3.1 UX

A compact **"Sort" button opening a Bootstrap dropdown panel** (Bootstrap JS is globally loaded by
`index.php`; `data-bs-auto-close="outside"` keeps the panel open while interacting inside it — the
established dropdown pattern used by the export menus):

```
[ ⇅ Sort: Default (Number) ▾ ]          ← button label IS the live summary

  ┌────────────────────────────────────┐
  │ Sort by                            │
  │ ① [ Title      ▾ ] [↑] [×]         │   ← level row: key select · dir toggle · remove
  │ ② [ Number     ▾ ] [↓] [×]         │
  │ [ + then by… ]   [ Reset to default ] │
  └────────────────────────────────────┘
```

- **Summary**: button text renders the active spec — `Sort: Default (Number)` /
  `Sort: Title ↑` / `Sort: Title ↑, then Number ↓` (truncated with `text-truncate` beyond ~40ch;
  the full spec remains readable inside the panel). The ①②③ level markers mirror the admin
  module's priority badges (admin-table-sort.js:148-151) so the two halves of #1786 share a visual
  language.
- **Level row**: a native `<select>` of keys (keys already used by other levels render `disabled`
  — one key appears in at most one level), a direction `<button aria-pressed>` (`fa-arrow-up` /
  `fa-arrow-down`, `aria-label` "Level 1 direction: ascending"), and a remove `<button>`
  (`aria-label` "Remove sort level 1"). Removing the last level = Default.
- **"+ then by…"** appends a level (hidden at ⚑ N1's 3-level cap or when no unused keys remain).
- **"Reset to default"** clears the spec, restores server order, hides itself when already default.
- Changes apply **immediately** on every interaction (no Apply button — matches the admin tables'
  instant response and avoids a dirty-state machine).
- **Keyboard**: everything is native `<select>`/`<button>` inside a Bootstrap dropdown (its
  focus/Escape handling is stock); the toggle button carries `aria-expanded` from Bootstrap.
  **Announce** (shared `announce()`, `js/utils/announce.js`, WCAG 4.1.3): "Sorted by Title
  ascending, then Number descending" / "Default order restored" on every applied change.

### 3.2 Persisted spec

```json
{ "<surface>": [ { "key": "title", "dir": "asc" }, { "key": "number", "dir": "desc" } ] }
```

An ordered array, 1–3 entries (⚑ N1). Empty/absent = Default. Same shape in localStorage
(`STORAGE_LIST_SORT = 'ihymns_list_sorts'`, one JSON blob keyed by surface) and in the account
subtree (§7.2). Validation on every read: array, ≤ 3 entries, `key` matching `[a-z0-9_-]{1,40}`,
`dir` ∈ {asc, desc}, keys de-duplicated first-wins; anything else → treated as absent (the
`loadSavedSubtags` posture, songbook-language-filter.js:83-93). A saved key with no matching
`<option>` on today's control is dropped silently — "a saved layout is a wish, not a contract"
(card_layout.php:466-527).

### 3.3 The control partial — `includes/partials/list-sort-control.php` (new)

Server-emitted, module-wired (NOT module-injected): (a) identical for every viewer ⇒
shared-cache-safe (rule #6); (b) exists before JS runs, no layout jump; (c) statically checkable by
the guard — an injected control is invisible to source analysis, the under-reporting trap rule #34
warns about. Mirrors the `export-menu.php` `$exportMenuSurface` contract (songbook.php:264-272):

```php
$listSortSurface = 'songbook-songs';   // [a-z0-9-]{1,40}; partial validates, renders nothing on mismatch
$listSortDefault = 'Number';           // label of the server default, for the summary + reset row
$listSortOptions = [
    'number'  => ['label' => 'Number', 'type' => 'number', 'dir' => 'asc'],
    'title'   => ['label' => 'Title',  'type' => 'text',   'dir' => 'asc'],
    'writers' => ['label' => 'Writer', 'type' => 'text',   'dir' => 'asc'],
];
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'list-sort-control.php';
```

Emitted markup (NO inline `<script>` — rule #30; `tests/php/test-fragment-inline-scripts.php`
already scans `includes/partials/` and enforces this for free):

```html
<div class="list-sort-control dropdown"
     data-list-sort-surface="songbook-songs"
     data-list-sort-default-label="Number"
     data-list-sort-options="<?= htmlspecialchars(json_encode($opts, JSON_UNESCAPED_SLASHES)) ?>">
    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle list-sort-toggle"
            data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
        <i class="fa-solid fa-arrow-down-wide-short me-1" aria-hidden="true"></i>
        <span class="list-sort-summary">Sort: Default (Number)</span>
    </button>
    <div class="dropdown-menu p-3 list-sort-panel">
        <div class="fw-semibold small mb-2" id="list-sort-label-songbook-songs">Sort by</div>
        <div class="list-sort-levels" role="group" aria-labelledby="list-sort-label-songbook-songs"></div>
        <div class="d-flex gap-2 mt-2">
            <button type="button" class="btn btn-sm btn-outline-secondary list-sort-add">+ then by…</button>
            <button type="button" class="btn btn-sm btn-outline-secondary list-sort-reset d-none">Reset to default</button>
        </div>
    </div>
</div>
```

`$opts` is the sanitised options array (keys regex-validated `[a-z0-9_-]+` — the
`cardLayoutSanitiseIds()` allow-list posture, card_layout.php:612-637 — since keys become
`data-sort-<key>` attribute lookups). The **level rows are built by the module via DOM APIs** (the
card-layout edit-controls precedent, card-layout.js:273-319) — dynamic add/remove UI cannot be
static markup, and DOM-API construction keeps CodeQL's DOM-text rules quiet. The guard still
cross-checks statically because the **PHP `$listSortOptions` array literal** stays in each page's
source (§8.1.3).

### 3.4 Container + item contract (unchanged from rev 1 except multi-container is now first-class)

- Sortable container(s): `data-list-sort-list="<surface>"` on each element whose **direct
  children** are the sortable units. Grouped pages (tag, musician) put it on every per-group
  `.song-list` — one control, N containers, each sorted independently (within-group sort; the
  grouping is the page's information architecture and is never flattened).
- Items carry `data-sort-<key>` per key they have a value for. **A missing attribute sorts after
  every present value at that level, in BOTH directions** (the un-numbered Misc tail stays a tail;
  writer-less songs group at the end of a Writer level). Never emit an empty-string attribute.

### 3.5 `js/modules/list-sort.js` — exports and behaviour

```
initListSort(root = document)              // DOM-reorder mode; router boots unconditionally
getListSort(surface)                       // validated [{key,dir},…] | null (local ∪ account cache)
wireListSortControl(surface, onChange)     // array-mode: wires the panel, persists (both stores),
                                           //   announces, dispatches; onChange(spec) on USER change
primeAccountListSorts()                    // §7.3 — one account fetch per app session
```

`initListSort()` per `[data-list-sort-surface]` control:

1. Idempotence via `control.dataset.listSortWired` (pull-to-refresh re-runs `afterPageLoad`;
   the `initCardLayout` guard shape, card-layout.js:231).
2. Containers = `[data-list-sort-list="<surface>"]` inside `#page-content`. **None ⇒ array-mode
   surface ⇒ return** (its consumer calls `wireListSortControl` itself). This split keeps DOM mode
   fully automatic and array mode fully explicit.
3. Capture each container's original children ONCE (`_listSortOriginal`) so Default restores the
   server order exactly (admin's `_adminSortOriginalRows`, admin-table-sort.js:227); restore
   re-appends only `node.isConnected` entries (§9.3).
4. Restore the saved spec via `getListSort(surface)` (which consults the account cache — §7.3),
   build the panel rows, apply.
5. **Apply (per container):** decorate direct children with
   `{ vals: {key: attr ?? null}, i }`; sort by walking the spec levels — per level,
   missing-vs-present decides first (missing → after), then
   `makeCompare(type, dir)` from `js/utils/sort-compare.js`; final tie-break `a.i - b.i`
   (stability). This is `multiKeyCompare`'s walk with a missing-last clause —
   implemented as `multiKeyCompareMissingLast()` in sort-compare.js beside the verbatim admin
   `multiKeyCompare` (admin semantics unchanged). Re-append via ONE `DocumentFragment` (single
   reflow; Mission Praise ≈ 3,517 rows). Hidden (`d-none`) children ARE sorted and stay hidden —
   deliberately unlike the admin module's visible-only dance; the language filter
   (songbook-language-filter.js:158+) only class-hides, and there is no interleaving contract
   here. Document the divergence in the module doc-block.
6. Persist: localStorage always; account write-through when signed in (§7.3).
7. Announce (§3.1) + dispatch `EVT_LIST_SORT_CHANGED` (`detail: {surface, spec}`) on `document`.

### 3.6 Router wiring — `js/modules/router.js afterPageLoad()` (line 681)

ONE unconditional boot beside `bootOfflineUi()` (line 685) — never a per-page branch (a hand-typed
page list rots, rule #34):

```js
import('./list-sort.js').then(m => m.initListSort())
    .catch(err => console.error('[Router] list-sort init failed:', err));
```

Array-mode surfaces (favorites, search) wire themselves inside their own modules (§6.3, §6.4);
their controls have no `data-list-sort-list` container, so the boot skips them by construction.

---

## 4–5. SURFACE ADOPTION DETAILS

### 4.1 `/songbooks` (DOM mode)

`songbooks.php`: `data-list-sort-list="songbooks"` on the `.row.g-3` (line 48); on each `.col-*`
tile div (line 82): `data-sort-name="<?= htmlspecialchars(ihymns_title_sort_key($book['name'])) ?>"`
(add `require_once includes/sort_helpers.php` — do not rely on `getSongbooks()` having loaded it),
`data-sort-abbr` = mb-lowercased **visible** label (`$sbAbbr`, line 115 — users sort what they see;
identity still `Abbreviation`, rule #27 untouched), `data-sort-songs` = `(int) songCount`. Control
partial inserted between the language filter (line 45) and the grid.

### 4.2 `/songbook/<abbr>` (DOM mode + absorb)

`songbook.php`: attribute on the `.list-group.song-list` (line 341); per row (lines 343-378):
`data-sort-number` (only when number > 0, matching the badge guard at 355-357), `data-sort-title`
(via `ihymns_title_sort_key`), `data-sort-writers` (mb-lowercased `implode('; ', …)` of
`$writersMap[$song['id']]`, only when non-empty; `; ` = the #495 canonical separator). Control
above the list. `number` IS an explicit option (only an explicit level can be direction-flipped).

**Same commit — absorb songbook-index.js's toggle:** delete the sortGroup (lines 72-84) + its sort
branch (123-149); extract the letter-map rebuild (150-171) into `_rebuildStrip()`; add
`document.addEventListener(EVT_LIST_SORT_CHANGED, onSort)` where `onSort` self-evicts when
`!document.contains(strip)` (the fragment-swap listener-leak guard — a rule-#32 analogue for
`document`-level listeners) and filters `detail.surface === 'songbook-songs'`. Import the EVT
constant from constants.js (raw literals banned, #1581).

### 4.3 Remaining server-rendered catalogue surfaces (D2 fold-in; one mechanical recipe)

For each of `tag.php` (container inside the per-book loop at 175-210 → the ONE source-level
`.song-list` at 182 gets the attribute; the loop stamps it on every group), `musician.php`
(per-role `.song-list` at 998), `tune.php` (song list at ~634), `publisher.php` (songbook rows at
~183+), `work.php` (member-song list), `identifier.php` (song list at 227): add
`data-list-sort-list="<surface>"`, the per-item `data-sort-*` keys from §2's table (computed with
the same folds), and the control partial above the first group. Multi-container surfaces need
nothing extra — §3.5 step 5 iterates containers. Builder note per rule #17: these pages already
SELECT every field the keys need (id/title/songbook/number); no query changes, no new hydration.

### 4.4 A11y + event summary

`EVT_LIST_SORT_CHANGED`: dispatcher = list-sort.js; listener = songbook-index.js — satisfies
`tests/test-event-names.js`'s both-sides rule automatically. Announcements queue politely behind
the focus/navigation announcements (announce() defers by a frame — router.js:228-246 ordering note).

---

## 6. ARRAY-MODE AND SERVER-MODE SURFACES

### 6.3 `/favorites` (array mode)

Control markup in `favorites.php` between count badge (52) and tag filter (59); surface
`favorites`; **no** `data-list-sort-list`. In `favorites.js loadFavoritesList()` (462): before the
render map (528), read `getListSort('favorites')`; when set, sort a COPY of the array with
`multiKeyCompareMissingLast` over mapped fields — `added` → `fav.addedAt ?? null` (date; legacy
rows without it sort last), `title` → `titleSortKey(fav.title)` (the JS fold, §8.2), `book` →
composed abbr+padded-number string. After render:
`wireListSortControl('favorites', () => this.loadFavoritesList())` — the reload already resets
select-mode (471-472), so batch state can't strand. Default = stored order, exactly today.

### 6.4 `/search` (server-sort mode — pagination makes client DOM-sort dishonest, §9.1)

- **`SongData::searchSongs()`** (3081): new optional `array $sortSpec = []` (validated
  `[{key,dir},…]`, ≤ 3). ONE private helper `_searchOrderBy(array $spec): string` composes the
  ORDER BY by concatenating **hardcoded constant fragments** per level from a fixed map — never
  interpolating request strings (rule #5's allow-list carve-out):
  `relevance` → `relevance DESC` (FULLTEXT path only; ignored on the LIKE path, which has no
  relevance column), `title` → `s.Title ASC|DESC`, `number` → `s.SongbookAbbr ASC|DESC, s.Number
  ASC|DESC`; always suffixed with the default `s.SongbookAbbr, s.Number` as the final tie-break.
  BOTH the FULLTEXT (3277) and LIKE (3343) paths call the same helper (rule #35: one mechanism).
  SQL `s.Title` is not article-aware — the documented acceptable degradation (SongData.php:
  2432-2440; the `TitleSortKey` generated-column follow-up fixes both sites at once).
- **`api.php` search case** (891): parse `sort` as CSV of `key.dir` tokens (⚑ N5), each validated
  against `['relevance','title','number']` × `['asc','desc']`; unknown tokens dropped, empty ⇒
  default relevance (never a 4xx — the PWA and server skew across deploys). **`api-docs.yaml`
  updated the same commit** — the param is an API contract from the moment it ships (rule #33).
- **Client:** control in `search.php` after the songbook filter (128), surface `search`, options
  title + number (Default = Relevance). `search.js`: `apiSearch()` (379) appends
  `sort=title.asc,number.desc` built from `getListSort('search')`;
  `wireListSortControl('search', …)` re-runs `performSearch` fresh (append = false ⇒ offset resets
  ⇒ pagination coherent under the new order). Offline fallback (458): apply the spec client-side
  to the ≤ 50 slim-index rows with the shared comparator. All requests already ride `apiFetch`
  (rule #31; search.js:388) — no new fetch paths.

---

## 7. PERSISTENCE (D3) — device + account, one store, no schema

### 7.1 Store (investigated — §1.4)

**`tblUsers.Settings` JSON, namespace `list_sorts`**, via the existing `?action=user_settings`
namespaced GET/POST (#1671 F5). No new endpoint, no schema row, no migration. The subtree is the
§3.2 map: `{ "<surface>": [{key,dir},…] }` — well under the per-namespace byte cap
(`userSettingsRejectReason`, user_settings.php:186+).

### 7.2 Write path + CSRF (D3's `validateCsrfRequest()` requirement)

`POST /api?action=user_settings` body `{"namespace":"list_sorts","settings":{…}}` — auth required
(`getAuthenticatedUser()`, api.php:3978-3981); the namespaced branch replaces ONLY this subtree
(api.php:4069-4113). **Add belt-and-braces `validateCsrfRequest()` to the namespaced POST branch**
(⚑ N2), the exact `live_follow_extend` precedent: `require_once manage/includes/auth.php` then
reject on failure with 403 (api.php:17568-17586 — the comment there records that the global
X-Requested-With gate at api.php's top already blocks cross-site POSTs; this is the second,
independent check rule #29 asks for on a state-changing write). The client sends no token —
`validateCsrfRequest(null)` passes via its same-origin branch (`X-Requested-With` present, no
cross `Origin`/`Referer`), which `apiFetch` already guarantees on every call (rule #31 /
api-client.js). The **legacy whole-blob branch is untouched, byte-for-byte** (shipped native-app
contract, api.php:4116-4121). Review fallback if touching the shared branch is vetoed: a dedicated
`list_sort_save` case delegating to the same `userSettings*` pure helpers + `validateCsrfRequest()`.

### 7.3 Precedence + merge rule (⚑ N3)

- **Anonymous:** localStorage only. Signing out keeps device prefs; the module just drops its
  account cache on `EVT_AUTH_CHANGED`.
- **Signed-in, page load:** apply localStorage **immediately** (first paint, offline-safe — the
  `applyCardLayout` fail-soft posture, card-layout.js:416-424); `primeAccountListSorts()` fetches
  `GET ?action=user_settings&namespace=list_sorts` ONCE per app session (module-level memo,
  re-primed on login via `EVT_AUTH_CHANGED`). For each surface present in the account subtree,
  **account wins**: it is mirrored into localStorage and, if the visible surface's applied spec
  differs, re-applied (with an announce). Surfaces present only in localStorage stay device-local —
  **no silent auto-upload on read** (a stale device must not overwrite fresher devices' account
  state just by opening a page).
- **On user change:** write-through to BOTH — localStorage synchronously, then (signed-in) POST
  the FULL `list_sorts` map (namespace write replaces the subtree; the client's map is
  account-merged-plus-this-change, so nothing regresses). Last-write-wins across a user's own
  devices — the store's documented posture (card_layout.php:292-296, user_settings namespaced
  branch comment api.php:4070-4075). No timestamps, no vector clocks: a ≤ 3-entry cosmetic pref
  does not earn them.
- **Failure anywhere** (network, 401, 413): localStorage-only behaviour, console-logged, never a
  user-facing error (§7.5).

### 7.4 Known limit — the legacy whole-blob wipe (documented, not silently absorbed)

The no-namespace `user_settings` POST (settings.js `_pushSyncedSettings`) still **replaces the
entire blob and destroys sibling namespaces** — stated in code at api.php:4117-4124 ("⚠ It also
still REPLACES the whole blob… migrating settings.js to `namespace: 'ihymns.web'` is the remaining
step") and in card_layout.php:298-305 for `cardLayouts`, which lives with the same exposure today.
Consequence: a Settings-screen sync push wipes the ACCOUNT copy of `list_sorts`; the device copy
is untouched, and the next sort change re-populates the account — degradation is "cross-device
sync pauses until the next change", never lost local behaviour. **Root fix is out of scope here**
(namespacing settings.js's push moves `liveIdleTimeoutMins`, which the server resolver reads at
blob ROOT — constants.js:116-130 — so it needs its own contract work): **file the follow-up issue**
in C10 referencing api.php:4122-4124, benefiting `cardLayouts` too.

### 7.5 Dormancy / rule #9

No un-migrated state exists (`tblUsers.Settings` predates #448). Client: every account read/write
in try/catch → localStorage-only degrade. Server: the endpoint already fail-softs and maps reason
codes → statuses (rule #35). The feature is a verified no-op for anonymous users beyond
localStorage, and a verified no-op server-side when never called.

---

## 8. GUARDS (rule #34 — tree-derived, mutation-proven)

### 8.1 `tests/php/test-public-list-sort.php` (new; CI globs `tests/php/*.php`, test.yml:233)

Modelled on `test-admin-tables-sortable.php` (stripPhp/bareText, floor check 136-140, documented
exclusions):

1. **Candidates, tree-derived:** `glob('includes/pages/*.php')`, PHP-stripped; fingerprint =
   `class="…song-list…"` container OR the `card-songbook` tile grid. Never a hand-typed list.
2. **`$PAGE_EXCLUSIONS` — permanent only** (D2: the phase-D entries are GONE): `song.php`
   (JS-populated 5-item relevance shortlists), `home.php` (ranked sections + ⚑ N6),
   `setlist.php`/`setlist-shared.php` (manual order is the feature), `stats.php` (leaderboards).
   `favorites.php`/`search.php` are PARTIAL entries: the control's presence is still asserted;
   only the container/attr checks are skipped (array/server mode — wiring asserted in 8.2.3).
3. **Per candidate:** (a) a `list-sort-control.php` `require` + `$listSortSurface` assignment;
   (b) every source-level `song-list`/tile-grid container tag carries
   `data-list-sort-list="<same surface>"` **within the tag** (catches a second list added to an
   adopted page — closing the page-level coverage gap the admin guard honestly documents at
   test-admin-tables-sortable.php:47-52); (c) **option-key ↔ attribute cross-check**: regex the
   `$listSortOptions` literal (WIDE window — everything between the token and its `require`; the
   #34 post-mortem on narrow windows) for `'([a-z0-9_-]+)'\s*=>` keys; each must appear as
   `data-sort-<key>=` in the same file. This is the mechanism against the quietest failure the
   feature can produce: a typo'd key makes every row tie — the control "works", the list never
   moves, nothing errors.
4. **Vacuity floors:** ≥ 8 fully-checked pages (all-surfaces scope) AND ≥ 15 option keys, else
   FAIL.
5. **Mutation proofs, run + recorded in the commit body:** (a) delete `data-sort-title` from
   songbook.php → red; (b) add a `'year'` option without an attribute → red; (c) delete the
   partial require from tag.php → red; (d) delete the `stats.php` exclusion → red (exclusions are
   load-bearing); (e) add a bare `.song-list` container without the attribute to musician.php →
   red; restore → green. Plus the narrowness check: the finished tree passes with zero
   undocumented exceptions.

### 8.2 `tests/test-list-sort.js` (new; `tools/run-node-tests.js` globs `tests/*.js`)

1. **Pure units:** `makeCompare` (text/number/date), `multiKeyCompare` (level walk),
   `multiKeyCompareMissingLast` (missing-after in both dirs), `titleSortKey`, spec
   validation/dedupe/cap.
2. **PHP ⇄ JS article-vocabulary parity:** extract the alternation from
   `includes/sort_helpers.php` and `SORT_ARTICLES` from `js/utils/sort-compare.js`; assert
   set-equality (mutation proof: add `'los'` to one side → red). A mechanism, not a "keep in sync"
   comment (rule #35).
3. **Wiring, tree-derived:** router.js imports `./list-sort.js` + calls `initListSort(` (the
   #1799 "tagged but dead" shape); scan `includes/pages/*.php` for every `$listSortSurface`
   value; any surface with NO `data-list-sort-list` anywhere (⇒ array/server mode) must have a
   `js/modules/*.js` consumer referencing both the surface literal and
   `wireListSortControl`/`getListSort`. Songbook-index absorb: assert its old `data-sort="number"`
   toggle markup is gone AND it listens for the EVT constant.
4. **Admin non-regression:** `admin-table-sort.js` imports the comparator from
   `utils/sort-compare.js` and defines no local copy.
5. **Sync contract:** the module's account read/write targets
   `action=user_settings` + `namespace=list_sorts` literals (one grep each), so a rename on either
   side goes red — the two spellings live in constants the test cross-checks.

Free coverage from existing guards (verify green, add nothing): `test-event-names.js`,
`test-fragment-inline-scripts.php`, `test-admin-table-sort.js` (post-extraction),
`test-dom-target-integrity.js`, `lint:js`.

---

## 9. ADVERSARIAL — WHAT BREAKS THIS

1. **Paginated list under DOM-reorder** sorts only the loaded page (search: PAGE_SIZE 50 +
   offset, search.js:39/304-336) — hence server-sort mode for search, and the module doc-block
   rule: **paginated/virtualised ⇒ never `data-list-sort-list`**. The guard cannot detect
   pagination statically — stated honestly in its SCOPE/LIMITS header (the admin guard's
   convention).
2. **JS-rendered list missed by DOM mode:** structurally impossible to half-wire — no container ⇒
   not DOM mode (§3.5.2), and guard 8.2.3 fails if no array-mode consumer exists.
3. **Default restoring removed nodes:** original-order capture holds references; restore filters
   `node.isConnected` so a future removal-based filter can't resurrect nodes.
4. **Stale alphabet strip after sort:** absorbed + `EVT_LIST_SORT_CHANGED` rebuild with a
   self-evicting document listener (§4.2).
5. **Cached fragment gaining a per-user ETag:** nothing server-side reads the viewer (§10);
   reviewer red-flag: ANY diff to api.php's ETag/cache-key logic in this PR is wrong.
6. **Typo'd key = all ties = silent no-op** (the rule-#30/#33 texture): guard 8.1.3 is the
   mechanism, designed red-first.
7. **Multi-level UI edge cases:** duplicate keys prevented by disabled options; removing level 1
   of 2 promotes level 2 (the array just shifts — ① badge follows index); the 3-level cap hides
   "then by"; an empty panel = Default. Summary truncates, panel shows the full spec.
8. **Perf (~3.5 k rows, ≤ 3 levels):** one attribute-read decorate pass (no layout reads — NOT
   the admin's per-row `offsetParent`), one DocumentFragment re-append = one reflow, user-initiated.
9. **Corrupt localStorage / account blob:** shape-validation on every read → treated as absent;
   never throws (§3.2).
10. **Sync races:** two devices → last-write-wins per the store's own documented posture; the
    full-map write (§7.3) means a race loses at most the other device's latest change, never
    corrupts the subtree shape. The legacy whole-blob wipe (§7.4) is documented with a filed
    follow-up — degradation is paused sync, never lost local behaviour.
11. **The search `sort` CSV is a forever API contract** (rule #33): unknown tokens fold to
    default (never an error), documented in `api-docs.yaml` the same commit; failure kinds stay
    distinguished by HTTP status only (rule #35).
12. **Favourites batch/select mode across a re-sort:** `loadFavoritesList()` fully re-renders and
    resets `selectMode` (favorites.js:471-472).
13. **Grouped surfaces flattening:** impossible by design — per-container sort only; no code path
    moves a node between containers.
14. **`validateCsrfRequest` on the shared namespaced branch breaking non-browser writers:** a
    native/second-product client sending `X-Requested-With` and no cross-origin `Origin` passes
    the same-origin branch; one that sends neither was already blocked by api.php's global
    X-Requested-With POST gate (per the live_follow_extend comment). Verify with the existing
    user-settings tests before merging; the ⚑ N2 fallback exists if review disagrees.

---

## 10. CSP / CACHEABLE-FRAGMENT CORRECTNESS (explicit)

1. **No inline `<script>` anywhere** (rule #30): the partial emits inert markup + `data-*` (a
   JSON options attribute is data, not code); behaviour arrives via `list-sort.js` imported from
   `afterPageLoad()` (the home-page.js pattern, router.js:909-911).
   `test-fragment-inline-scripts.php` enforces for free.
2. **No server-side personalisation of a cached fragment** (rule #6): everything emitted is
   identical for every viewer; the viewer's spec exists only client-side
   (localStorage/account-fetch after injection — the `applyCardLayout` split, card_layout.php's
   two-hydration-modes doc). No per-user ETag added anywhere.
3. **All network via `apiFetch`** (rule #31): search re-query (existing path) + the two
   `user_settings` calls — no bare fetch, no global patches.
4. **Cache-skew fail-soft both ways:** stale fragment without control ⇒ module no-ops; new
   fragment with stale JS ⇒ inert button for one SW update cycle — the same exposure every
   module-wired control already has.

---

## 11. PHASED BUILD (one PR, atomic commits)

Every commit: `php -l` on touched PHP, `node --check` on touched JS, `npm test`,
`php tests/php/test-admin-tables-sortable.php` stays green, project-standard annotations
(ELI5 + detailed), WHY in the body.

| # | Commit | Contents |
|---|--------|----------|
| C1 | `refactor(sort): extract shared multi-key comparator to js/utils/sort-compare.js` | `makeCompare` + `multiKeyCompare` moved VERBATIM from admin-table-sort.js (68-86, 103-109); add `titleSortKey`, `SORT_ARTICLES`, `multiKeyCompareMissingLast`, spec validator; admin module imports; behaviour-neutral — `tests/test-admin-table-sort.js` green unchanged |
| C2 | `feat(public-sort): multi-level list-sort module + control partial + router boot` | `js/modules/list-sort.js` (§3), `includes/partials/list-sort-control.php`, `STORAGE_LIST_SORT` in constants.js, router unconditional boot. No EVT yet (constant + dispatch + listener land atomically in C4 so `test-event-names.js` stays green per commit); no surface adopted — module no-ops everywhere |
| C3 | `feat(public-sort): /songbooks tiles — name / abbreviation / song count` | §4.1 |
| C4 | `feat(public-sort): /songbook songs; absorb songbook-index toggle; EVT_LIST_SORT_CHANGED` | §4.2 — page attrs + control, songbook-index absorb + strip-rebuild listener, EVT constant + dispatch in list-sort.js |
| C5 | `feat(public-sort): catalogue surfaces — tag, musician, tune, publisher, work, identifier` | §4.3, one mechanical recipe ×6 (multi-container on tag/musician) |
| C6 | `feat(public-sort): favourites — date added / title / songbook (array mode)` | §6.3 |
| C7 | `feat(public-sort): search — server-side multi-level sort param` | §6.4: `_searchOrderBy` + `searchSongs` spec param, api.php CSV validation, `api-docs.yaml`, search.php control, search.js wiring + offline-fallback sort |
| C8 | `feat(public-sort): account sync via user_settings namespace list_sorts` | §7: `primeAccountListSorts()` + write-through in list-sort.js; `validateCsrfRequest()` belt-and-braces on the namespaced POST branch (live_follow_extend precedent); `api-docs.yaml` note for the namespace |
| C9 | `test(public-sort): tree-derived mutation-proven guards` | §8.1 + §8.2; every mutation proof run, seen red, restored, RECORDED in the commit body |
| C10 | `docs(public-sort): changelog, wiki, issue bookkeeping` | CHANGELOG.md, wiki PWA-Features page, #1786 comment linking commits + this plan; **file follow-ups:** (i) settings.js namespaced push (§7.4, benefits cardLayouts too, cites api.php:4122-4124 + the `liveIdleTimeoutMins` root-key constraint), (ii) `TitleSortKey` generated column (SongData.php:2438), (iii) optional home-grid adoption (⚑ N6) |

**Model tier** (project-rules §17): C1–C8 default model; C9's guard authoring gets the careful
tier — the #34 history of wrong-but-green first runs is exactly this kind of test.

---

## 12. FILE-TOUCH SUMMARY

**New:** `js/utils/sort-compare.js` · `js/modules/list-sort.js` ·
`includes/partials/list-sort-control.php` · `tests/php/test-public-list-sort.php` ·
`tests/test-list-sort.js`

**Edited:** `js/modules/admin-table-sort.js` (comparator import) · `js/constants.js` ·
`js/modules/router.js` (one boot line) · `includes/pages/{songbooks,songbook,tag,musician,tune,publisher,work,identifier,favorites,search}.php` ·
`js/modules/songbook-index.js` (absorb) · `js/modules/favorites.js` · `js/modules/search.js` ·
`includes/SongData.php` (`_searchOrderBy` + `searchSongs` param) · `api.php` (search CSV
validation; `validateCsrfRequest()` on the namespaced `user_settings` POST branch ONLY) ·
`api-docs.yaml` · `CHANGELOG.md` + wiki.

**Explicitly untouched:** `$_cacheablePages`/ETag logic · `includes/card_layout.php` · the legacy
whole-blob `user_settings` branch (api.php:4116-4130, byte-for-byte) · default ORDER BYs in
`getSongbooks()`/`getSongsSlimIndex()`/page queries (defaults must not move) · `home.php` (⚑ N6) ·
`schema.sql` (no schema change — §7.1) · everything under `/manage`.
