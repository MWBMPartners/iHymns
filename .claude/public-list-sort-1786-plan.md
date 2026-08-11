# #1786 Option B — User-controllable sorting for the PUBLIC app's card/list surfaces

**Status:** design complete, ready to build. **Branch:** `claude/issue-sweep-fixes-89`.
**Tracker:** #1786 (admin half DONE — shared `js/modules/admin-table-sort.js` multi-column module +
tree-derived guard `tests/php/test-admin-tables-sortable.php`). This plan covers the owner-chosen
Option B: the public app, whose catalogue surfaces are **card/list layouts, not `<table>`s**, so the
admin module cannot attach and a sibling mechanism is needed.

Everything below is decision-complete for a Sonnet builder **except** the items under
"DECISIONS FOR OWNER". Defensible defaults are picked for every sub-question and flagged inline as
`⚑ default`.

---

## 0. DECISIONS FOR OWNER

### D1 — Single-level "Sort by + direction" vs the admin's multi-level stack

1. **The decision:** should the public sort control offer ONE sort key + an asc/desc toggle, or the
   admin tables' multi-level "then by …" stacking (shift-click on admin, #1786)?
2. **Why it needs deciding:** product/UX judgement, not derivable from code. The admin multi-level
   exists because a dense data table has column headers to shift-click; a card/list layout has no
   headers, so multi-level would need a bespoke builder UI (two dropdowns? a chips row?), mostly on
   mobile-portrait viewports.
3. **Options:**

   | Option | Consequence |
   |---|---|
   | A. Single level (select + direction button) | One `<select>` + one toggle. Ties broken by the server's default order (stable sort), which is already a sensible secondary (e.g. Title-sort ties fall back to Number order). Cost: a user cannot express "Songbook, then Title" explicitly — but the per-surface keys below bake the natural secondary into each key anyway. |
   | B. Multi-level | Parity with admin, at the cost of a much heavier control on small screens, more state to persist/announce, and a UI with no established pattern in this app. |
   | Do nothing | Public stays unsortable; #1786 Option B unfulfilled. |

4. **Recommendation: A (single level).** The stable sort + composed keys ("Songbook & number" as one
   key) give 95 % of multi-level's value at a fraction of the UI weight, and it matches how every
   consumer app (Apple Music, Spotify, Files) exposes list sorting.
5. **Smallest reply:** "A" or "B".
6. **Blocks?** No — the plan is written for A; B would change only §4's control design.

### D2 — Which surfaces ship in this pass

1. **The decision:** confirm the surface list. Proposed **in scope**: `/songbooks` (tiles),
   `/songbook/<abbr>` (song list), `/search` (results), `/favorites`. Proposed **deferred** (phase D,
   pre-wired but not built): `/tag/<slug>`, `/musician/<slug>` grouped song lists, and the small
   lists on `/tune`, `/publisher`, `/work`, `/iswc`+siblings. Proposed **never**: set lists (manual
   order IS the feature), home ranked sections (Popular/Recently-viewed — rank is the semantic),
   stats (leaderboards), the home songbook grid (see ⚑ default in §3.1).
2. **Why:** product scope. Every added surface is control chrome on a public page.
3. **Options:** ship the four; ship the four + phase D now; trim further. Cost of deferring phase D:
   nothing breaks — those pages keep today's fixed order and carry a documented guard exclusion each.
4. **Recommendation:** the four in-scope surfaces now, phase D as a follow-up issue. They cover the
   overwhelming majority of list interactions; the deferred pages are short lists (usually < 30 rows)
   already grouped meaningfully.
5. **Smallest reply:** "four" / "four + D" / name removals.
6. **Blocks?** No.

### D3 — Should the chosen sort ride a URL param (`?sort=title&dir=desc`)?

1. **The decision:** deep-linkable/shareable sort in the address bar, or localStorage-only.
2. **Why:** rule #33 — a URL param another page emits is a contract forever. Emitting `?sort=` from
   the address bar means every future consumer (OG images, sitemaps, the Apple client's
   CanonicalURL) must decide whether to honour or strip it, and `tests/test-editor-deep-links.js`'s
   lesson says un-honoured params fail silently.
3. **Options:**

   | Option | Consequence |
   |---|---|
   | A. No URL param (localStorage only) | Zero new URL contract. A shared link always opens in the recipient's own (or default) order. |
   | B. Read + write `?sort=`/`?dir=` | Shareable, but the param must be honoured by the router, the fragment cache key (two sorts of one page would share one cached fragment — fine, since sorting is client-side, but confusing), and every future page that links here. |
   | C. Read-only (honour if present, never emit) | No emitter exists, so in practice dead code that must still be maintained. |

4. **Recommendation: A.** Note that the **search server-side `sort`/`dir` API params** (§6.4) are a
   deliberate, documented API contract regardless — that's an API-of-record addition to
   `api-docs.yaml`, not an address-bar param.
5. **Smallest reply:** "A", "B" or "C".
6. **Blocks?** No — plan written for A; B is an additive follow-up (router `parseRoute` +
   `buildApiUrl` pass-through + module read/write, ~30 lines).

### Flagged defaults (picked, trivially changeable, not blocking)

- **⚑ Home songbook grid gets NO sort control** — the home is a quick-access surface already
  carrying the #448 customise/reorder toolbar; the full `/songbooks` page is one tap away and gets
  the control. Changing this later = the same partial + three `data-sort-*` attrs on
  `includes/pages/home.php:246-383` (the tile loop mirrors `songbooks.php` exactly).
- **⚑ Persistence granularity is per-SURFACE, not per-songbook** — one saved preference applies to
  every `/songbook/<abbr>` page (a user who prefers Title order prefers it everywhere). Changing
  later = key the blob entry `songbook-songs:<abbr>` instead of `songbook-songs`.
- **⚑ Sort preference is device-local in v1** (localStorage, not account-synced). The whole-blob
  `user_settings` sync uses the `SYNC_PREF_KEYS` allow-list
  (`js/modules/settings.js:180-188`); adding the new key there later is one line + the server-side
  no-op (the blob is opaque). Deliberately NOT added now to keep the blob-sync blast radius zero.

---

## 1. THE TWO EXISTING MECHANISMS THIS BUILDS ON (and must not fork)

### 1.1 The card-layout split — the architecture template (rule #6)

`includes/card_layout.php:24-56` states the constraint that shapes everything here: `/api?page=home`
(and `songbooks`, `songbook`, `search` — `api.php:600-613` `$_cacheablePages`) is a **shared-cache,
ETag'd fragment served identically to every visitor**. The server therefore emits the **canonical
default** and per-viewer state is applied **client-side after the fragment lands** —
`applyCardLayout()` (`js/modules/card-layout.js:404-466`), wired from `router.js`'s
`afterPageLoad()`, reading `data-*` attributes the fragment already carries. Sorting is the same
problem with a smaller state (one `{key, dir}` instead of an order array), so it gets the same
split:

- **Baked into the cached fragment** (same-for-everyone, derived from song data, never user data):
  the sort **control markup** and the per-item **`data-sort-*` values**. Precedent: the
  `isOfficial` badge, explicitly argued safe-to-cache at `includes/pages/home.php:278-284`.
- **Applied client-side after injection** (per-viewer): which sort the viewer picked, read from
  localStorage by a module the router imports. Never a per-user ETag; never server-side
  personalisation of a cached fragment.

### 1.2 The admin sort module — the comparator to extract, not copy

`js/modules/admin-table-sort.js` owns three pure pieces: `makeCompare(type, direction)` (lines
68-86: text via `localeCompare(…, {numeric:true, sensitivity:'base'})`, number via `parseFloat`,
date via `Date.parse`), `multiKeyCompare` (103) and `nextStack` (197). The public module needs
`makeCompare` verbatim. Copying it would be exactly the `_bsls_*` re-fork the red-flags list bans
("second copy of the similarity maths"). But importing `admin-table-sort.js` from the public bundle
drags in its module-load side effect (`bootSortableTables()` auto-runs at import, lines 288-295) —
harmless but wrong-shaped, and rule #36's lesson applies: **the shared thing must be small enough
to adopt**. So: **extract the comparator into a new pure, DOM-free `js/utils/sort-compare.js`** and
have BOTH modules import it (C1 below).

### 1.3 ⚠ A third, ad-hoc sorter already exists and must be ABSORBED

`js/modules/songbook-index.js` (#111) already injects a **"# Number / A-Z Title" sort toggle** onto
`/songbook/<abbr>` pages with ≥ 20 songs (lines 72-84 build it, 123-173 sort by scraping
`.song-title` textContent / badge text, then rebuild the alphabet-strip letter map). It has no
article-aware fold, no direction, no persistence, no announcement, and reads display text instead
of data attributes. **The new mechanism replaces this toggle** (the `/manage/duplicate-songs`
absorbing `/manage/song-link-suggestions` precedent, rule #22): songbook-index keeps its alphabet
strip but drops its own sort UI, and rebuilds its letter map by listening for the new
`EVT_LIST_SORT_CHANGED` event (§4.6). Leaving both would ship two competing sort controls on one
page whose states can disagree — the exact divergence class the modularity rule exists to stop.

---

## 2. SURFACE INVENTORY (every public card/list surface, with render mode + current default)

| # | Surface | File(s) | Render mode | Container / items | Current default order | Cacheable fragment? | Verdict |
|---|---------|---------|-------------|-------------------|-----------------------|---------------------|---------|
| 1 | `/songbooks` tiles | `includes/pages/songbooks.php:48-225` | server HTML | `.row.g-3` → `.col-*` tile divs | Name, article-aware (#150): `SongData::getSongbooks()` fetches `ORDER BY b.Name` then `ihymns_sort_by_title_key()` (`SongData.php:594-655`) | **yes** | **IN — DOM-reorder mode** |
| 2 | `/songbook/<abbr>` songs | `includes/pages/songbook.php:341-380` | server HTML | `.list-group.song-list` → `a.song-list-item` | `getSongsSlimIndex($abbr)`: numbered ASC, then un-numbered tail by `LOWER(s.Title)` (`SongData.php:2283+`, article-degradation documented at 2432-2440) | **yes** | **IN — DOM-reorder mode** (absorbs songbook-index toggle) |
| 3 | `/search` results | `includes/pages/search.php:144-154` shell + `js/modules/search.js:272-369` | **JS-rendered, server-paginated** (PAGE_SIZE 50, "Load more" offset) | `#search-results-list` built by `_renderResultItems` | server: `ORDER BY relevance DESC, s.SongbookAbbr, s.Number` (`SongData.php:3277`, LIKE path 3343) | yes (the SHELL is; results are per-query JSON) | **IN — server-sort (re-fetch) mode.** Client DOM-sort would sort only the loaded page — dishonest (see §8.1) |
| 4 | `/favorites` | `includes/pages/favorites.php:66-71` shell + `js/modules/favorites.js:462-576` | **JS-rendered from localStorage array** | `#favorites-list` | array insertion order = date-added ascending (`favorites.push({… addedAt})`, favorites.js:100-107) | no (not in `$_cacheablePages`) | **IN — array mode** (sort the in-memory array pre-render) |
| 5 | `/tag/<slug>` | `includes/pages/tag.php:175-210` | server HTML, **grouped by songbook** | one `.list-group.song-list` per book | `ORDER BY SongbookAbbr, Number, Title` (tag.php:72) | **yes** (`tag` in `$_cacheablePages`) | **DEFERRED (phase D)** — within-group sort, multi-container |
| 6 | `/musician/<slug>` (+ `/writer/*` alias, api.php:704-721) discography | `includes/pages/musician.php:988-1022` | server HTML, **grouped by role** | one `.song-list` per role | `ORDER BY s.SongbookAbbr, s.Number` (musician.php:341) | **yes** | **DEFERRED (phase D)** |
| 7 | `/tune`, `/publisher`, `/work`, `/iswc`+5 id siblings | `tune.php:634`, `publisher.php:183`, `identifier.php:227`, `work.php` | server HTML, short lists | `.song-list` / `.list-group` | per-page `ORDER BY` (book/number/title or name) | mostly no | **DEFERRED (phase D)** |
| 8 | Home Popular Songs / Recently Viewed / Theme chips | `home.php:387-417` + `js/modules/home-page.js` | JS-rendered | list-groups / chip strip | server-ranked (views 30d / recency / usage) | yes | **OUT — rank IS the semantic** |
| 9 | Home songbook grid | `home.php:246-383` | server HTML | `.row.row-cols-*` | same as #1 | yes | **OUT (⚑ default, D2/§0)** |
| 10 | Set lists / shared set list | `setlist.php`, `setlist-shared.php` + `setlist.js` | JS-rendered | user-ordered | **manual user order — has its own reorder UI** | no | **OUT — sort would fight manual reorder** (the same reasoning as the admin guard's `songbooks.php` Order-column exclusion, `test-admin-tables-sortable.php:95-97`) |
| 11 | `/stats` | `stats.php` + `router.js populateStats():1255-1368` | JS-rendered | bar rows | frequency-ranked | no | **OUT — leaderboards** |
| 12 | Song page related/translations lists | `song.php` containers, `router.js:1377-1461` | JS-rendered | 5-item capped | relevance-scored | (song excluded when gating on, api.php:615-622) | **OUT — curated shortlist** |

---

## 3. THE MECHANISM — one shared module, two modes

### 3.1 New shared pieces

| Piece | Path | Role |
|---|---|---|
| Pure comparator utils | `js/utils/sort-compare.js` (**new**) | `makeCompare(type, dir)` (moved from admin-table-sort.js), `titleSortKey(str)` (JS mirror of `ihymns_title_sort_key()`), `SORT_ARTICLES` const, `compareWithMissingLast()` |
| Public sort module | `js/modules/list-sort.js` (**new**) | control wiring, DOM-reorder mode, array-mode API, persistence, announce, event dispatch |
| Control partial | `includes/partials/list-sort-control.php` (**new**) | the ONE server-emitted "Sort by ▾ + direction" markup — pages `require` it, never hand-inline (the `export-menu.php` `$exportMenuSurface` contract pattern, songbook.php:264-272) |
| Constants | `js/constants.js` (**edit**) | `STORAGE_LIST_SORT = 'ihymns_list_sort'`; `EVT_LIST_SORT_CHANGED = 'ihymns:list-sort-changed'` (#1581 — raw literals banned, `tests/test-event-names.js` requires ≥1 dispatcher AND ≥1 listener, which §4.6 provides) |
| Server sort-key helper | `includes/sort_helpers.php` (**reuse, no change**) | `ihymns_title_sort_key()` computes every server-emitted `data-sort-title` / `data-sort-name` — the ONE article-aware fold (#150); never re-implement in a page |

**Control markup is server-emitted, module-wired** (not module-injected). Reasons: (a) it renders
identically for every viewer so it is shared-cache-safe (rule #6); (b) it exists before JS runs, so
layout doesn't jump; (c) it is statically checkable by the guard (§7) — an injected control would be
invisible to source analysis, the exact under-reporting trap rule #34 warns about. This mirrors
card-layout's server-emitted `data-layout-handle` strip wired by the module.

### 3.2 The partial's contract (`includes/partials/list-sort-control.php`)

Inputs set by the including page immediately before the `require` (same convention as
`$exportMenuSurface`):

```php
$listSortSurface = 'songbook-songs';          // [a-z0-9-]{1,40} — validated by the partial, else it renders nothing
$listSortDefault = 'Number';                  // human label of the server default, for the reset option
$listSortOptions = [
    'number  ' => ['label' => 'Number',  'type' => 'number', 'dir' => 'asc'],
    'title'    => ['label' => 'Title',   'type' => 'text',   'dir' => 'asc'],
    'writers'  => ['label' => 'Writer',  'type' => 'text',   'dir' => 'asc'],
];
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'list-sort-control.php';
```

Emitted markup (NO inline `<script>` — rule #30; `tests/php/test-fragment-inline-scripts.php`
already scans `includes/partials/` and will enforce this automatically):

```html
<div class="list-sort-control d-flex align-items-center gap-2 mb-3"
     data-list-sort-surface="songbook-songs">
    <label class="form-label small text-muted mb-0" for="list-sort-songbook-songs">Sort by</label>
    <select id="list-sort-songbook-songs" class="form-select form-select-sm w-auto list-sort-select">
        <option value="" selected>Default (Number)</option>
        <option value="title" data-sort-type="text" data-sort-initial-dir="asc">Title</option>
        <option value="writers" data-sort-type="text" data-sort-initial-dir="asc">Writer</option>
        <option value="number" data-sort-type="number" data-sort-initial-dir="asc">Number</option>
    </select>
    <button type="button"
            class="btn btn-sm btn-outline-secondary list-sort-dir d-none"
            aria-pressed="false" aria-label="Sort direction: ascending"
            title="Toggle sort direction">
        <i class="fa-solid fa-arrow-up" aria-hidden="true"></i>
    </button>
</div>
```

Details the builder must honour:

- Every option key is `htmlspecialchars`'d AND regex-validated `[a-z0-9_-]+` by the partial (the
  `cardLayoutSanitiseIds()` allow-list posture, card_layout.php:612-637 — keys reach
  `data-sort-<key>` attribute lookups, so they are template-author vocabulary, not free text).
- The `<select>` is natively keyboard-operable; the direction `<button>` uses `aria-pressed` and an
  `aria-label` rewritten on toggle ("Sort direction: ascending"/"descending"), icon
  `fa-arrow-up`/`fa-arrow-down` (both in the FA subset already used site-wide, e.g. router.js:326).
- The direction button stays `d-none` while "Default" is selected — the server default is not a
  directional key (it's a composite: numbered-then-alphabetical-tail), so flipping it is undefined.
- No `<form>`; no `name` attributes — this control never participates in native submission.

### 3.3 Container + item contract

- Each sortable container carries `data-list-sort-list="<surface>"` — the element whose **direct
  children** are the sortable units (`/songbooks`: the `.row.g-3` at songbooks.php:48; songbook: the
  `.list-group.song-list` at songbook.php:341). Multiple containers may share one surface (that is
  the phase-D grouped-page shape: each per-book/per-role `.song-list` gets the attribute and is
  sorted independently — one control, N containers).
- Each sortable item (a direct child) carries `data-sort-<key>` for each key it has a value for.
  **A missing attribute means "sorts after every item that has one", in BOTH directions** — this is
  how the un-numbered Misc tail stays a tail under Number sort, and how writer-less songs group at
  the end of a Writer sort. (Emit the attribute only when a real value exists; never emit
  `data-sort-number=""`.)
- Server-computed keys: title-ish values pass through `ihymns_title_sort_key()` (lowercased,
  single leading article stripped) so "The Solid Rock" files under S — fixing, for sorted views,
  the acceptable degradation documented at SongData.php:2433-2440. Numeric values are the raw int.

### 3.4 `js/modules/list-sort.js` — exports and behaviour

```
initListSort(root = document)            // DOM-reorder mode; router calls unconditionally
getListSort(surface)                     // {key, dir} | null — validated read of the saved blob
wireListSortControl(surface, onChange)   // array-mode: wires the control, persists, announces,
                                         //   dispatches; calls onChange({key, dir, type}) on USER
                                         //   change only (never at wire time — the consumer reads
                                         //   getListSort() itself during its own render)
```

`initListSort()` per control (`[data-list-sort-surface]`):

1. Idempotence: skip if `control.dataset.listSortWired === '1'` (fragments arrive fresh each
   navigation, but pull-to-refresh `router.refresh()` re-runs `afterPageLoad` — same guard shape as
   `initCardLayout`, card-layout.js:231).
2. Find containers `[data-list-sort-list="<surface>"]` inside `#page-content`. **None found ⇒ this
   is an array-mode surface ⇒ return without wiring** (the array consumer calls
   `wireListSortControl` itself). This split keeps DOM mode fully automatic and array mode fully
   explicit.
3. Capture each container's original child order ONCE
   (`container._listSortOriginal = Array.from(container.children)`) so "Default" restores the
   server order exactly — the admin module's `_adminSortOriginalRows` pattern
   (admin-table-sort.js:227). On restore, re-append **only `node.isConnected` entries** (see §8.3).
4. Restore: `getListSort(surface)`; if saved key matches an existing `<option>`, set the select,
   reveal + set the direction button, apply. A saved key with no matching option (option removed in
   a later deploy) is DROPPED silently and the entry cleared — the `cardLayoutMerge` "saved layout
   is a wish, not a contract" posture (card_layout.php:466-527).
5. Apply (per container): decorate visible-AND-hidden direct children with
   `{ has: el.hasAttribute('data-sort-'+key), val: el.getAttribute(...) ?? '', i }`; sort with
   `compareWithMissingLast(makeCompare(type, dir))` then `a.i - b.i` for stability; re-append via a
   single `DocumentFragment` (one reflow — Mission Praise is ~3,517 rows, songbook.php:52).
   **Deliberately unlike the admin module:** hidden (`d-none`) children ARE sorted and moved — the
   language filter (songbook-language-filter.js:158+, toggling classes on the `.col` wrappers)
   keeps them hidden wherever they land, and there is no filtered-interleaving contract to
   preserve on these surfaces. Document this divergence in the module doc-block.
6. Persist `{[surface]: {key, dir}}` into the `STORAGE_LIST_SORT` JSON blob (try/catch — private
   mode degrades to session-only, matching `saveSubtags`, songbook-language-filter.js:95-103).
7. Announce via the shared `announce()` (`js/utils/announce.js`, #1645 — WCAG 4.1.3):
   "Sorted by Title, ascending" / "Default order restored". Never a page's worth of content.
8. Dispatch `EVT_LIST_SORT_CHANGED` on `document` with `detail: {surface, key, dir}` — consumed by
   songbook-index.js (§4.6) and available to future listeners.

### 3.5 Router wiring (`js/modules/router.js` `afterPageLoad`, line 681)

One unconditional boot alongside `bootOfflineUi()` (line 685) — NOT per-page branches:

```js
/* #1786 Option B — wire any "Sort by" control the fragment emitted. The module
   no-ops when the fragment has none, so this costs one resolved import on
   sort-less pages. Unconditional for the same reason bootOfflineUi is: a
   per-page list here is a hand-typed list that rots (rule #34). */
import('./list-sort.js').then(m => m.initListSort()).catch(err => console.error('[Router] list-sort init failed:', err));
```

DOM-mode surfaces need nothing else. Array-mode surfaces (favorites, search) wire themselves inside
their own page modules (§6.3, §6.4) — their controls have no `data-list-sort-list` container, so
the unconditional boot skips them by construction (step 2 above).

---

## 4. PER-SURFACE DESIGN

### 4.1 `/songbooks` (DOM mode, C3)

- **Container:** add `data-list-sort-list="songbooks"` to the `.row.g-3` (songbooks.php:48).
- **Items** (each `.col-12.col-sm-6.col-md-4.col-lg-3` div, line 82):
  - `data-sort-name="<?= htmlspecialchars(ihymns_title_sort_key($book['name'])) ?>"` (require
    `includes/sort_helpers.php` at top — it is already loaded by `getSongbooks()` but the page must
    not rely on that side effect; `require_once` is free).
  - `data-sort-abbr="<?= htmlspecialchars(mb_strtolower($sbAbbr)) ?>"` — the **visible** label
    (`ihymns_songbook_abbr_label()`, already computed at line 115), because users sort what they
    see; URLs/identity still use the real `Abbreviation` (rule #27 untouched).
  - `data-sort-songs="<?= (int)($book['songCount'] ?? 0) ?>"`.
- **Control:** inserted between the language-filter partial (line 45) and the grid. Surface
  `songbooks`; default label `Name (A–Z)`; options `name` (text), `abbr` (text), `songs` (number,
  initial dir `desc` — "most songs first" is the intuitive reading of picking Song count).
- **Default order unchanged:** an untouched page renders exactly today's #150 article-aware
  alphabetical order; the control's "Default" restores it byte-identically (original-children
  capture).

### 4.2 `/songbook/<abbr>` (DOM mode, C4)

- **Container:** `data-list-sort-list="songbook-songs"` on the `.list-group.song-list`
  (songbook.php:341).
- **Items** (each `a.song-list-item`, lines 343-378):
  - `data-sort-number="<?= (int)$song['number'] ?>"` — emitted **only when** number > 0 (the
    existing badge guard, lines 355-357), so the Misc/unofficial un-numbered tail sorts last under
    Number in both directions.
  - `data-sort-title="<?= htmlspecialchars(ihymns_title_sort_key((string)$song['title'])) ?>"`.
  - `data-sort-writers="<?= htmlspecialchars(mb_strtolower(implode('; ', $writersMap[$song['id']]))) ?>"`
    — only when `$writersMap[$song['id']]` is non-empty (the `; ` join matches the #495 canonical
    separator, search.js:493-495).
- **Control:** inserted directly above the song list (before line 340, after the external-links
  panel). Surface `songbook-songs`; default label `Number`; options `number` (number), `title`
  (text), `writers` (text). `number` IS offered as an explicit option even though it's the default,
  because only an explicit key can be direction-flipped (highest-number-first).
- **Absorb songbook-index.js's toggle (same commit):**
  - Delete the sortGroup build + handlers (songbook-index.js:72-84, 123-149 sort branch).
  - Keep the alphabet strip; extract the letter-map rebuild (current lines 150-171) into a
    `_rebuildStrip(songList, strip)` method.
  - Listen: `document.addEventListener(EVT_LIST_SORT_CHANGED, onSort)` where `onSort` first checks
    `document.contains(strip)` and **removes itself** when the strip has been swapped out by a
    navigation — the fragment-swap listener-leak guard (a fixed-teardown analogue of rule #32; the
    listener is on `document`, which survives SPA swaps, so it must self-evict).
  - Filter `detail.surface === 'songbook-songs'`.
  - Import `EVT_LIST_SORT_CHANGED` from constants.js (never the raw literal — #1581 guard).

### 4.3 `/favorites` (array mode, C5)

- **Control markup** added to `includes/pages/favorites.php` between the count badge (line 52) and
  the tag filter (line 59). Surface `favorites`; default label `Date added`; options `added`
  (**date** type — `makeCompare` already supports `Date.parse`, admin-table-sort.js:77-83; initial
  dir `desc` = newest first, which is the useful non-default read), `title` (text), `book` (text).
  No `data-list-sort-list` container — that is what routes it to array mode.
- **`js/modules/favorites.js` `loadFavoritesList()` (line 462):**
  - Before the `favorites.map(...)` render (line 528): read `const sort = getListSort('favorites')`
    and, when non-null, sort a **copy** of the array:
    - `added` → `makeCompare('date', dir)` over `fav.addedAt ?? ''` (field exists —
      favorites.js:106; pre-`addedAt` legacy rows missing it sort last via
      `compareWithMissingLast`).
    - `title` → `makeCompare('text', dir)` over `titleSortKey(fav.title)` (the JS fold — §5).
    - `book` → text over `` `${fav.songbook} ${String(fav.number ?? 0).padStart(6,'0')}` `` —
      the composed "Songbook & number" key (D1's baked secondary).
  - After render: `wireListSortControl('favorites', () => this.loadFavoritesList())` — idempotent
    (the module's wired-flag), and `loadFavoritesList` already fully re-renders + resets select
    mode (lines 471-472), so batch-select state cannot strand across a re-sort.
- Default (no saved sort / "Default" chosen): the array renders in stored order — exactly today.

### 4.4 `/search` (server-sort mode, C6)

Client DOM-reorder is ruled out by pagination (§8.1). The sort is pushed into the query:

- **Server — `SongData::searchSongs()` (SongData.php:3081):** new optional params
  `string $sort = 'relevance'`, `string $dir = 'asc'` (dir ignored for relevance). ONE private
  helper `_searchOrderBy(string $sort, string $dir): string` returns a **hardcoded-constant** ORDER
  BY chosen from a fixed map — never interpolating request values (rule #5's allow-list carve-out):

  ```php
  'relevance' => 'relevance DESC, s.SongbookAbbr, s.Number',   // FULLTEXT path (line 3277)
                 's.SongbookAbbr, s.Number',                    // LIKE path (line 3343, no relevance col)
  'title'     => 's.Title {ASC|DESC}, s.SongbookAbbr, s.Number',
  'number'    => 's.SongbookAbbr {ASC|DESC}, s.Number {ASC|DESC}, s.Title',
  ```

  `{ASC|DESC}` resolved by `$dir === 'desc' ? 'DESC' : 'ASC'` — two literals, never the raw string.
  BOTH the FULLTEXT and LIKE paths call the same helper (rule #35: one mechanism, not two branches
  a comment promises to keep in sync). `s.Title` sorting is NOT article-aware in SQL — the
  acceptable degradation already documented at SongData.php:2433-2440 (MySQL 5.7 support, no
  REGEXP_REPLACE); the follow-on `TitleSortKey` generated column noted there would fix both sites
  at once.
- **Server — `api.php` search case (line 891):** read + validate
  `$sort = in_array($_GET['sort'] ?? '', ['relevance','title','number'], true) ? … : 'relevance'`;
  `$dir = ($_GET['dir'] ?? '') === 'desc' ? 'desc' : 'asc'`; pass through. Unknown values fold to
  the default (never a 4xx — this is a read param, and the PWA may run ahead of or behind the
  server across deploys). **Update `api-docs.yaml`** — the param is an API contract from the moment
  it ships (rule #33/#35; documentation is not the mechanism, but an undocumented param is worse).
- **Client — `includes/pages/search.php`:** control markup after the songbook filter (line 128).
  Surface `search`; default label `Relevance`; options `title` (text), `number`
  ("Songbook & number", number). No `data-list-sort-list` container ⇒ array/consumer mode.
- **Client — `js/modules/search.js`:**
  - `apiSearch()` (line 379): append `sort`/`dir` from `getListSort('search')` when set.
  - `initSearchPage()` (line 153): `wireListSortControl('search', () => { const q = input.value.trim(); if (q.length >= 2) this.performSearch(q, filter?.value || '', results); })`
    — a fresh (`append = false`) search resets `_search.offset`, so pagination restarts coherently
    under the new order.
  - Offline fallback (`_offlineSearchFallback`, line 458): apply the saved sort client-side to the
    ≤ 50 filtered slim-index rows with the same comparator utils (the slim rows have
    `title`/`songbook`/`number`) so offline behaviour tracks the user's choice.
  - All requests already go through `apiFetch` (rule #31) — no new fetch paths.

### 4.5 Phase D (deferred; design recorded so adoption is mechanical)

`tag.php` / `musician.php`: one control per page (surface `tag-songs` / `musician-songs`), every
per-group `.song-list` gets `data-list-sort-list="<surface>"`, items get
`data-sort-number`/`data-sort-title` exactly as §4.2 — the module already sorts each container
independently (§3.4 step 5 iterates containers). Within-group sorting is the right semantic: the
grouping (by songbook / by role) is the page's information architecture; sorting must not flatten
it. Small pages (`tune`, `publisher`, `work`, `identifier`) follow the same recipe if ever wanted.
Each carries a documented guard exclusion until adopted (§7).

### 4.6 Event + a11y summary

- `EVT_LIST_SORT_CHANGED`: dispatcher = list-sort.js; listener = songbook-index.js — satisfies
  `tests/test-event-names.js`'s both-sides assertion automatically.
- `announce()` on every applied change (§3.4.7). The results containers on search
  (`#text-search-results`, search.php:146 `aria-live="polite"`) already announce content changes;
  the sort announcement is the *reason*, queued politely before it.
- Control is a labelled native `<select>` + `aria-pressed` toggle button — the language-filter
  picker and #1151 card-layout keyboard precedents; no custom listbox needed.

---

## 5. THE ONE ARTICLE FOLD, TWICE — WITH A MECHANISM, NOT A COMMENT (rule #35)

Server-emitted sort keys use `ihymns_title_sort_key()` (`includes/sort_helpers.php:29-48`,
vocabulary `the|a|an|o|el|la|le|les|der|die|das`). Array-mode surfaces (favorites, search-offline)
sort client-side, so `js/utils/sort-compare.js` carries the JS mirror:

```js
export const SORT_ARTICLES = ['the','a','an','o','el','la','le','les','der','die','das'];
export function titleSortKey(s) { /* trim, toLowerCase, strip ONE leading article + whitespace */ }
```

Two copies of one vocabulary is exactly the rule-#35 drift class (`cardLayoutMerge` ⇄
`mergeLayout` lives with a warning comment; we can do better): **`tests/test-list-sort.js`
regex-extracts the alternation from `sort_helpers.php` and `SORT_ARTICLES` from
`sort-compare.js` and asserts set-equality.** Change one side without the other → red. (The same
test style as `test-vendor-sri.js` asserting card-layout.js's SortableJS constants against
`config.php`, card-layout.js:36-43.)

---

## 6. CSP / CACHEABLE-FRAGMENT CORRECTNESS (spelled out, per the task)

1. **No inline `<script>` anywhere in this feature** (rule #30). The partial emits inert markup +
   `data-*` only; behaviour arrives exclusively via `list-sort.js`, imported from
   `afterPageLoad()` (the home-page.js pattern, router.js:909-911). The existing
   `tests/php/test-fragment-inline-scripts.php` already scans `includes/partials/` and
   `includes/pages/` and will fail the build if anyone regresses this — no new guard needed for
   this specific property.
2. **No server-side personalisation of a cached fragment** (rule #6). `songbooks`, `songbook`, and
   `search` are in `$_cacheablePages` (api.php:600-613). Everything the server emits for this
   feature (control, options, `data-sort-*` values) is derived from song data and identical for
   every viewer — the `isOfficial`-badge cacheability argument (home.php:278-284). The viewer's
   chosen sort exists ONLY in the client (localStorage → DOM reorder after injection), so no
   per-user ETag is ever needed and none is added. `card_layout.php:538-544`'s warning applies
   verbatim: the resolve step must never move server-side for these surfaces.
3. **All re-fetches ride `apiFetch`** (rule #31): the only network activity this feature adds is
   search re-querying, and that goes through the existing `apiSearch()` which already uses
   `apiFetch` (search.js:388). No new `fetch`, no global patches.
4. **Old cache ↔ new code skew is fail-soft both ways.** A stale SW-cached fragment without the
   control ⇒ module finds nothing, no-ops, default order stands. A new fragment with a stale JS
   bundle ⇒ the select renders but does nothing — a silent no-op, bounded to one SW update cycle,
   and the same exposure every module-wired control on the site already has (export menu, present
   button).

---

## 7. THE GUARD (rule #34 — tree-derived, mutation-proven)

### 7.1 `tests/php/test-public-list-sort.php` (new; auto-run — CI globs `tests/php/*.php`, test.yml:233)

Modelled directly on `test-admin-tables-sortable.php` (its `stripPhp`/`bareText` helpers, floor
check at lines 136-140, documented-exclusions discipline):

- **Candidates, tree-derived:** `glob('appWeb/public_html/includes/pages/*.php')`, PHP-stripped;
  a page is a candidate when it contains a sortable-list fingerprint —
  `class="…song-list…"` on a container OR the `card-songbook` tile-grid marker. Never a hand-typed
  page list.
- **`$PAGE_EXCLUSIONS`** (each with a one-line reason, the audit trail):
  `song.php` (JS-populated 5-item relevance shortlists), `home.php` (ranked sections + ⚑ D2
  default: the control lives on /songbooks), `setlist.php`/`setlist-shared.php` (manual user order
  is the feature — sort would fight the reorder UI, the admin `songbooks.php` Order-column
  precedent), `stats.php` (leaderboards), `favorites.php` + `search.php` (**array-mode surfaces —
  control asserted here, wiring asserted by the node test**, so these are PARTIAL exclusions: the
  test still requires `data-list-sort-surface` in them, and excludes only the container/attr
  checks), and one entry each for the deferred phase-D pages (`tag.php`, `musician.php`,
  `tune.php`, `publisher.php`, `work.php`, `identifier.php`) whose reason string names this plan —
  adopting one deletes its entry, keeping the list self-shrinking.
- **Per non-excluded candidate:**
  1. contains `list-sort-control.php` in a `require` (the partial, not a fork) AND
     `data-list-sort-surface` would be emitted (the `$listSortSurface = '…'` assignment exists);
  2. ≥ 1 `data-list-sort-list="` whose surface id string-matches the assignment;
  3. **option-key ↔ attribute cross-check:** regex the `$listSortOptions` array literal (window =
     everything between the `$listSortOptions` token and its partial `require` — a WIDE window,
     because the #34 post-mortem says narrow regex windows produced confident wrong greens on
     `test-editor-api2-contract.php`) for `'([a-z0-9_-]+)'\s*=>` keys; each key must appear as a
     literal `data-sort-<key>=` in the same file. This is the mechanism against the quietest
     failure this feature can produce: a key with no matching attribute makes every row tie —
     the control "works", the list never moves, nothing errors (§8.6).
- **Vacuity floors:** ≥ 2 fully-checked pages AND ≥ 5 option keys checked, else FAIL — a broken
  glob or over-eager exclusion cannot produce a vacuous green (the admin guard's `< 10` check).
- **Mutation proofs, run and recorded in the commit message** (the guard is not merged until each
  has been seen red): (a) delete `data-sort-title` from songbook.php → red; (b) add a `'year'`
  option to songbooks.php without an attribute → red; (c) delete the partial require from
  songbooks.php → red; (d) delete the `tag.php` exclusion entry → red (proves exclusions are
  load-bearing, not decorative); (e) restore → green. Also the NARROWNESS check the rule demands:
  the full current tree must pass without any undocumented exception, so the guard never fails on
  correct code and never gets weakened.

### 7.2 `tests/test-list-sort.js` (new; auto-run — `tools/run-node-tests.js` globs `tests/*.js`)

1. **Pure-unit:** `makeCompare` text/number/date behaviour, `titleSortKey` article strip,
   missing-key-sorts-last in both directions.
2. **Article-vocabulary parity** PHP ⇄ JS (§5). Mutation proof: add `'los'` to one side → red.
3. **Wiring, tree-derived:** assert `router.js` imports `./list-sort.js` and calls
   `initListSort(` in `afterPageLoad` (the #1799 "tagged but dead" shape is the admin half's own
   history — a control emitted but never booted is a silent no-op). Then scan
   `includes/pages/*.php` for every emitted `$listSortSurface` value; for each surface that has NO
   `data-list-sort-list` container anywhere (⇒ array mode), require that some `js/modules/*.js`
   file contains both the surface-id string literal and a `wireListSortControl`/`getListSort`
   reference — so a future array-mode surface added without its consumer goes red automatically.
4. **Admin regression:** `admin-table-sort.js` no longer defines its own comparator (imports from
   `utils/sort-compare.js`) — one grep assertion, so the extraction can't silently revert to a
   fork.

Existing guards that cover this feature for free (verify green, add nothing):
`test-event-names.js` (EVT constant, both sides), `test-fragment-inline-scripts.php` (partial),
`test-admin-table-sort.js` (comparator behaviour post-extraction), `test-dom-target-integrity.js`
(the module queries by attribute, not by hardcoded ids), `lint:js`.

---

## 8. ADVERSARIAL — WHAT BREAKS THIS, AND WHY THE DESIGN ALREADY ANSWERS IT

1. **A paginated list under DOM-reorder sorts only the visible page.** Search loads 50 rows and
   appends via "Load more" (search.js:39-40, 304-336); client-sorting that DOM would present
   "Title order" that is silently wrong past row 50 — a lie with no error. That is why search is
   server-sort mode (§4.4), and why the module's doc-block must state the rule: **any paginated or
   virtualised list MUST use consumer/server mode, never `data-list-sort-list`**. The guard cannot
   detect pagination statically (stated honestly in its SCOPE/LIMITS header, the
   `test-admin-tables-sortable.php:41-52` convention); the doc-block + this plan are the record.
2. **A JS-rendered list the DOM-reorder approach misses.** Favourites' rows don't exist when
   `initListSort()` runs. The mode split (§3.4 step 2: no container ⇒ not DOM mode) makes this
   structurally impossible to half-wire: a control without a container does nothing until its
   consumer wires it, and node-test 7.2.3 fails if no consumer exists.
3. **"Default" restoring removed nodes.** The original-order capture holds references; if a future
   feature REMOVES children (today's language filter only class-hides), a naive restore would
   re-append detached nodes and resurrect them. Restore filters on `node.isConnected` (§3.4.3).
4. **The songbook alphabet strip goes stale after a sort** — its letter map points at the first
   DOM item per letter (songbook-index.js:49-58). Handled by the absorb + `EVT_LIST_SORT_CHANGED`
   rebuild (§4.2); the self-evicting document listener prevents the SPA-swap leak (a rule-#32
   analogue for listeners on `document`).
5. **A cached fragment must not gain a per-user ETag.** Nothing in this feature reads the viewer
   server-side; §6.2 is the invariant statement. Reviewer red-flag: ANY diff to api.php's ETag/
   cache-key logic in this PR is out of scope and wrong.
6. **A typo'd sort key = every row ties = silent no-op** (the rule-#30/#33 failure texture: the
   page loads, the control responds, the list never moves). The guard's option-key ↔
   `data-sort-*` cross-check (§7.1.3) is the mechanism; it was designed red-first.
7. **Locale/Unicode:** keys are mb-lowercased server-side; comparison uses
   `localeCompare(sensitivity:'base', numeric:true)` so diacritics and digit runs order sanely.
   Slicing never happens (whole-string compares only), so no code-point traps (rule #21's class).
8. **Perf on Mission Praise (~3.5 k rows):** one decorate pass reading attributes (no layout
   reads — deliberately NOT the admin module's per-row `offsetParent` check, which forces layout),
   one `DocumentFragment` re-append = one reflow. Measured admin tables sort hundreds of rows this
   way; 3.5 k anchors is fine on mobile, and the operation is user-initiated.
9. **Corrupt localStorage blob:** `getListSort()` shape-validates (`key` string, `dir`
   `asc|desc`) and returns null otherwise — degrade to default, never throw (the
   `loadSavedSubtags` posture, songbook-language-filter.js:83-93).
10. **The search `sort`/`dir` API params are a forever contract** (rule #33): unknown values fold
    to relevance (never an error), the params are documented in `api-docs.yaml` the same commit
    they ship, and failure kinds remain distinguished by HTTP status only (rule #35) — no client
    prose-matching is added.
11. **Favourites batch/select mode stranding across a re-sort:** `loadFavoritesList()` fully
    re-renders and resets `selectMode` (favorites.js:471-472); the onChange path re-enters through
    it, so no stale checkbox state survives.
12. **Two sort authorities on one page** — the pre-existing songbook-index toggle. Absorbed
    (§4.2); leaving it would be the exact divergent-copy regression the modularity rule bans, and
    the node test asserts the sortGroup markup is gone (grep for `data-sort="number"` in
    songbook-index.js → must be absent).

---

## 9. PHASED BUILD (one PR, atomic commits — CLAUDE.md commit policy)

Every commit: `php -l` over touched PHP, `node --check` over touched JS, `npm test`,
`php tests/php/test-admin-tables-sortable.php` (must stay green throughout), annotations to project
standard (ELI5 + detailed registers), and body text explaining WHY.

| # | Commit | Contents | Proof it worked |
|---|--------|----------|-----------------|
| C1 | `refactor(sort): extract shared comparator to js/utils/sort-compare.js` | New util (makeCompare moved VERBATIM from admin-table-sort.js:68-86 + titleSortKey + SORT_ARTICLES + compareWithMissingLast); admin-table-sort.js imports it, deletes its local copy; behaviour-neutral | `tests/test-admin-table-sort.js` green unchanged; `npm test` |
| C2 | `feat(public-sort): shared list-sort module, control partial, constants, router boot` | `js/modules/list-sort.js`; `includes/partials/list-sort-control.php`; constants.js `STORAGE_LIST_SORT` + `EVT_LIST_SORT_CHANGED`; router.js unconditional boot (§3.5). No surface adopted yet — module no-ops everywhere | `npm test`; test-event-names.js will fail here on the one-sided EVT (no listener yet) → either add the constant in C4 instead, or (preferred) land C2+C4's listener in the same commit window and run the suite at C4; builder: **put the EVT constant in C4** to keep every commit green |
| C3 | `feat(public-sort): /songbooks tiles — name / abbreviation / song count` | §4.1 edits to songbooks.php | manual: pick each key, both dirs; Default restores #150 order; language filter + sort compose |
| C4 | `feat(public-sort): /songbook songs — number / title / writer; absorb songbook-index toggle` | §4.2 edits to songbook.php + songbook-index.js (+ the EVT constant + listener); un-numbered tail stays last | alphabet strip letters jump correctly after Title sort; toggle markup gone |
| C5 | `feat(public-sort): favourites — date added / title / songbook` | §4.3 favorites.php + favorites.js | sort survives tag-edit re-render; select-mode resets |
| C6 | `feat(public-sort): search — server-side sort param (relevance/title/number)` | §4.4: SongData `_searchOrderBy` + searchSongs params, api.php validation, api-docs.yaml, search.php control, search.js wiring + offline fallback | fresh search resets pagination; `hasMore` coherent under title order; unknown `sort` folds to relevance |
| C7 | `test(public-sort): tree-derived mutation-proven guards` | §7.1 + §7.2, mutation proofs (a)–(e) run and RECORDED in the commit body | full suite green; each mutation red then restored |
| C8 | `docs(public-sort): changelog, wiki, issue bookkeeping` | CHANGELOG.md, wiki PWA-Features page, #1786 comment linking commits + this plan; file the phase-D follow-up issue (D2) and the `TitleSortKey` generated-column note (already flagged at SongData.php:2438) if not tracked | standing-tasks.md checklist run |

**Model tier** (project-rules §17): C1–C6 are standard implementation — default model. C7's guard
authoring deserves care (the #34 history of wrong-but-green first runs) — do not delegate it to the
fast tier.

---

## 10. FILE-TOUCH SUMMARY

**New:** `js/utils/sort-compare.js` · `js/modules/list-sort.js` ·
`includes/partials/list-sort-control.php` · `tests/php/test-public-list-sort.php` ·
`tests/test-list-sort.js`

**Edited:** `js/modules/admin-table-sort.js` (import swap) · `js/constants.js` ·
`js/modules/router.js` (one boot line) · `includes/pages/songbooks.php` ·
`includes/pages/songbook.php` · `js/modules/songbook-index.js` (absorb) ·
`includes/pages/favorites.php` · `js/modules/favorites.js` · `includes/pages/search.php` ·
`js/modules/search.js` · `includes/SongData.php` (searchSongs order helper) · `api.php` (search
param validation only) · `api-docs.yaml` · `CHANGELOG.md` + wiki.

**Explicitly untouched:** `$_cacheablePages` / ETag logic; `includes/card_layout.php`;
`getSongbooks()` / `getSongsSlimIndex()` default ORDER BYs (defaults must not move); home.php
(⚑ D2); everything under `/manage` except nothing at all.
