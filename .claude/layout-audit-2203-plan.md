# Layout audit #2203 — plan (crooked form rows, sideways scroll, Settings at 360px)

Branch `claude/layout-audit-2203`. Every change here is **visual only**: no id, `name`, route, JavaScript
behaviour or text change, except where a step says a class is added to existing markup.
Lists below were produced by scripts (kept in the scratchpad, copy them into `tools/layout-audit/` in
step 0 so they survive): `rowscan.js` (static row scan), `tables.js` (wide tables), `rowmeasure.js`
and `ovf3.js` (live browser measurement). Line numbers are as of today and will drift — the guard,
not this list, is the source of truth.

## The four problems, their one shared fix each

### P1 — bottom-aligned rows whose columns carry help text
**Cause.** `row … align-items-end` lines columns up along their bottom edge. If one column has help
text under its box and the next does not, the boxes end up at different heights (configuration
IntApps: 64px apart; CAPTCHA 21px; public-domain 25px; notifications Web Push 47px).
**Shared fix.** Two new classes in `admin.css`, next to the slug rules:
- `.admin-form-row { align-items: flex-start; }` — replaces `align-items-end` on the row.
- `.admin-label-spacer { display:block; visibility:hidden; }` + `::before { content:"\00a0"; }` — an
  invisible line exactly as tall as a label, so a column with no label (a Save button, a tick box)
  still starts its control on the same line as its neighbours.
**Markup per row:** swap `align-items-end` for `admin-form-row`; in each column that has a control but
no label above it, add as first child `<span class="SAME-CLASSES-AS-THE-ROW'S-LABELS admin-label-spacer"
aria-hidden="true"></span>` (e.g. `form-label small admin-label-spacer`). Copying the sibling label's
classes is what makes the heights match; the guard checks it.
**Which rows (rule):** an element with classes `row` and `align-items-end`, with at least two direct
column children that hold a control, where any column holds `.form-text` or a `small`/`text-muted`
element after its control. Rows where every column is one label + one control are left alone.
Script result today (8): catalogues.php:534, configuration.php:2189, 2282, 2422, 2737, 2778,
musicians-bulk-promote.php:378, notifications.php:603. (The other ~15 bottom-aligned rows have no
help text and are correct as they are — that is exactly why bottom alignment was used.)
Tried live by injection: IntApps 4068/4132 → 4068/4068, CAPTCHA 5060/5081/5060 → all 5110,
Web Push 295/343 → 295/295, public-domain 7233/7258 → 7334/7334.

### P2 — a slug "Edit slug (advanced)" disclosure used as a column beside labelled inputs
**Cause.** The closed summary sits on the label line; when opened, its own "Slug" label + input sit
under the summary, ~25px lower than the neighbouring inputs (organisations, external-link-types,
songbook-series, works, tunes, publishers, catalogues — the live check flagged 7 at 1280px).
**Shared fix — in the partial, not the pages.** `manage/includes/slug-field.php` gains one optional
option `'rowLabelClass'` (string, default null = today's output byte-for-byte). When given (e.g.
`'form-label small'`): the summary takes those classes (plus `text-muted`), the inner `mt-1` wrapper
loses its top margin, and the inner `<label>Slug</label>` becomes `visually-hidden` (it still names
the input for screen readers; the summary is the visible label). Opened, the input then sits exactly
where its neighbours' inputs sit. Callers that sit in a row pass the option (13 call sites, derived by
grepping `ihymns_slug_advanced_field(` inside a `col-*` column whose siblings have labels).
**Not covered by a class:** restrictions.php rx-reason — "Reason (shown to user)" wraps to two lines in a
`col-sm-2`, pushing its box 17px down. One-line fix: widen that column (the measurement tool confirms).
organisations.php:1776 (tick box 5px off) gets a spacer like P1.

### P3 — sideways scroll on admin pages at 360px
Found by hiding elements one at a time until the page stopped scrolling (`ovf3.js`):
- **configuration (406px):** card headers are `card-header d-flex …` with a `span.d-flex` holding the
  "Set up with a guide" button and a status badge (one badge reads "Team ID not set (AASA uses
  placeholder)", 256px, no wrapping). **Fix:** new `.admin-card-header` in admin.css:
  `flex-wrap:wrap; gap:.5rem` and `.admin-card-header > .d-flex { flex-wrap:wrap; }`. Add the class to
  every `card-header` that has `d-flex` in manage (24 today, 6 files). At wide sizes nothing changes,
  because wrapping only happens when it does not fit.
- **organisations (513), groups (549):** their tables carry `admin-table-responsive` but **no**
  `data-col-priority` on any column, so the class hides nothing, and the table is not inside a
  `.table-responsive` box. **Fix:** wrap in Bootstrap's `<div class="table-responsive">` (the table then
  scrolls inside its card, never the page). Rule: a `<table>` with 4+ header cells that is neither
  inside `.table-responsive` nor using `data-col-priority`. Today (8): groups.php:273,
  organisations.php:850, 1294, requests.php:260, songbook-series.php:745, songbooks.php:3513,
  works.php:1322, 1375. None holds a dropdown (a scroll box would clip one) — checked.
- **missing-numbers (374) — cause found:** each accordion header is a one-line flex row: code badge
  (min 3.5rem) + songbook name + "N present · highest #N" + status badge + Bootstrap's arrow. A name
  with one long word ("Miscellaneous") cannot shrink, so the line overflows by 39px. **Fix:** new
  `.admin-accordion-summary` (below 576px: `flex-wrap:wrap; row-gap:.25rem`) added to that page's
  `accordion-button`.
- **help (415) — cause found:** in the "configuration" section a list item contains the single
  unbreakable word `iOS/iPadOS/macOS/tvOS/watchOS/visionOS` (browsers do not break at `/`). **Fix:**
  `.container-admin { overflow-wrap: break-word; }` — only breaks a word that would otherwise stick
  out; it does not change how tables or flex rows size themselves.
All four fixes tried by injection: 374/415/406/513/549 → 360 on every page.

### P4 — public Settings at 360px (app.css only, plus one class)
- **Offline Songs rows:** the grid has 5 columns but each row has 6 items, so when the "remove"
  (trash) button appears it drops onto a line of its own — at every width, not just phones. **Fix:**
  give `.offline-songbook-row` named areas (`"badge info info dl ev" "badge size status dl ev"`,
  columns `44px minmax(0,1fr) auto auto auto`) and place each existing child by its existing class. No
  markup change. Tried: row heights 60/118/102 → 56/56/81, trash beside the download button.
- **Theme buttons:** wrap 2-1-1. **Fix:** add class `theme-option-group` to the existing
  `role="group"` div; below 576px it becomes a two-by-two grid of equal buttons. Tried: all 143px wide,
  two lines.
- **Live Follow idle timeout input:** 8rem wide for a number of minutes (5–240) is deliberate; no change.
  (Optional: move its inline `style="max-width:8rem"` to a class — not needed for this issue.)

## Guards (tree-derived, each proven able to fail — rule #34)
1. **Extend `tests/test-form-row-alignment.js`** (keep its text-area rule). Replace the regex with the
   small tag-stack parser from `rowscan.js` (PHP blocks and comments blanked first; the slug partial
   call is read as a `<details>`). Over every `.php` under `manage/` (floor: >20 files and >30 rows with
   controls found, so a broken parser cannot pass by finding nothing):
   a. a `row` + `align-items-end` element with a help-text column fails ("use admin-form-row");
   b. in an `admin-form-row`, a control column with no label must start with `admin-label-spacer`,
      and the spacer's other classes must equal the row's first label's classes;
   c. a slug partial call inside a row beside labelled columns must pass `rowLabelClass` equal to
      those labels' classes.
   Inline self-tests: one failing and one passing snippet per rule.
2. **New `tests/test-admin-narrow-layout.js`** (auto-discovered by `tools/run-node-tests.js`):
   a. every `card-header` with `d-flex` in manage markup also has `admin-card-header`;
   b. every `<table>` with 4+ header cells is inside `.table-responsive` or uses `data-col-priority`;
   c. an `accordion-button` with 3+ child elements has `admin-accordion-summary`;
   d. `admin.css` still defines the four classes and `.container-admin` keeps `overflow-wrap`;
   plus self-test snippets for a–c.
3. **Mutation proof, by hand, recorded in the commit body:** put `align-items-end` back on
   configuration.php:2189 → (1a) red; delete one spacer → (1b) red; change a spacer to `form-label`
   only → (1b) red; drop `rowLabelClass` from works.php → (1c) red; remove `admin-card-header` from one
   header → (2a) red; unwrap groups.php's table → (2b) red. Restore each, green again.

## Before/after check with the running rig (php -S :8000, MariaDB :3307, admin cookie as in `audit.js`)
- `node tools/layout-audit/rowmeasure.js 1280 <pages>` — for each `.row`, groups columns that share a
  line and prints control tops that differ by more than 3px. Run on the P1/P2 pages before and after;
  after must print nothing for those rows. Ignore setup-database (its "rows" are card grids, not
  forms) and the musicians toolbar (a filter bar, centre-aligned on purpose).
- `node tools/layout-audit/ovf3.js` at 360 on every page in `pages-admin.json` plus `/settings`:
  `document.documentElement.scrollWidth` must equal 360. It names the culprit if not.
- Repeat at 768 (rows switch from stacked to side by side around 576–768) and in dark theme.
- Screenshots of the configuration IntApps/CAPTCHA cards, works create form, Settings offline list.

## Steps (one commit each, in this order)
| # | Step | Risk |
|---|------|------|
| 0 | Copy the four scripts into `tools/layout-audit/` with a short plain-English header | none (dev tool) |
| 1 | admin.css: `.admin-form-row`, `.admin-label-spacer` + convert the 8 P1 rows | low; spacer class mismatch = a few px — guard catches |
| 2 | slug partial `rowLabelClass` + 13 callers; restrictions column width; organisations tick box | low-medium: visually-hidden label must keep `for=`; check open + closed |
| 3 | `.admin-card-header` + 24 headers | low; wraps only when it does not fit |
| 4 | Wrap the 8 wide tables in `.table-responsive` | low; check sorting still works (sort JS uses the table, not its parent) |
| 5 | `.admin-accordion-summary` (missing-numbers) + `.container-admin` word breaking | very low |
| 6 | Settings: offline row areas + `theme-option-group` | low; check the trash appears/disappears and download progress text fits |
| 7 | Extend test-form-row-alignment.js (guard 1) + mutation proof | none |
| 8 | test-admin-narrow-layout.js (guard 2) + mutation proof | none |
| 9 | Live before/after run, screenshots, close-out (issue, CHANGELOG; no WHATS-NEW — admin-only polish, except one bullet for the Settings fix) | none |

## What stays NOT checked
- Markup built in JavaScript (template strings under `js/` and `manage/editor/v2/`): the guards read
  PHP only. The live check covers it only on pages we load, in the state they load in.
- Public pages other than Settings, the v2 editor, and widths 320/375/600-landscape/1024/1440 (only
  360/768/1280 were measured). High-contrast theme not looked at.
- Rows whose columns wrap onto separate lines: a spacer then leaves one blank label-height gap above a
  wrapped button (configuration Live Follow row). Harmless, not fixed.
- Label text that wraps in a narrow column (the restrictions case) cannot be found from the source;
  only the live measurement finds it.
- Pages that need data the rig does not have (long names, many rows, error states).
