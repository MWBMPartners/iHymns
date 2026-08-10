# #1767 — Print-template enhancements — reconciled build plan

**Branch:** `claude/print-templates-1767` (off `alpha`) · **Tracker:** #1767 · **Owner-approved full scope A–AM.**

This doc reconciles the design-workflow outputs (`pt_features.md`, `pt_schema.md`, `pt_plan.md`,
`pt_review.md`) after the adversarial review found the *build plan* and the *schema design*
contradicted each other. **Where they disagree, the schema-design version wins** (review gap #1).

## STATUS — 2026-08-05 (branch `claude/print-templates-1767`, off `alpha`)

**Shipped this pass (6 commits, all pushed, all verified via node + php-lint + the mutation-proven guard):**

| commit | features |
|---|---|
| 1 `2ef711f3` | rule-#35 registry guard (`test-print-block-registry.php`) + this plan doc |
| 2 `853965e8` | N Scripture · O Tune+metre · P Themes blocks |
| 3 `8f606c07` | R QR-code block (async pre-pass, CDN→vendored fallback) |
| 4 `08ce00bf` | A lyrics align+scale · B songbook-abbr mode |
| 5 `00b5ee15` | G page-size · F line-spacing · V high-contrast · AM accent · AB ink-saver (+ `PRINT_PAGE_OPTIONS` registry, guard extended) |
| 6 `5f023cdb` | Y conditional block visibility (`showIf`, universal, guard extended) |

Guard now covers: 14 block types + per-type option keys + 6 page options + 7 showIf conditions,
all mutation-proven (break→red→restore byte-identical).

**Z + J SHIPPED (2026-08-10, `4025ac8c`, on `claude/issue-sweep-fixes-89`):** template clone +
`?export=`/`import` JSON round-trip (import reuses the save sanitiser) + `set_default`
(single-default invariant, transactional) on `/manage/print-templates`. Live-DB verified (11
assertions: clone independence, one-default-per-scope, export/import round-trip). The CueRCode
credential card (#1782) also landed earlier (`96028999`), closing that QR blocker.

**Buildable-now, still open:** only the H-family multi-page/running-headers/page-numbering
(U/T/AA/AG — high-risk, patchy `@page` browser support; may prefer server-PDF), deferred, not
skipped.

**Decision-gated (NOT this branch — batched on #1767, non-blocking):** the schema + server-PDF
half (D/E/K/L/AK/AL/W/X/AC/AD/AJ + data-prereq Q/S/AH). See "Batched owner decisions" below.

## The current system (grounded, 2026-08-05)

- **`appWeb/public_html/js/modules/print.js`** — the client renderer. `PRINT_BLOCK_TYPES` registry
  (10 block types), 3 built-in templates, `renderBlock()` switch, `renderLyrics()`, `printCss(pageOptions)`
  (reads only `fontPt`), `buildPrintDoc()` → opens a **new window** and calls `print()`. Public entry
  `openSongPrintDialog(app)`. The print document is a **separate window** with no nonce CSP, so it may
  carry inline `<style>` freely (rule #30 does **not** bite the printout; it only bites new controls
  added to the cacheable `song`/`songbook` SPA fragment).
- **`appWeb/public_html/manage/print-templates.php`** — a **full admin page with its own `<head>`**
  (via `head-libs.php`), so its inline `<script type="module">` is legitimate (rule #30 exempts full
  pages). `$BLOCK_SCHEMA` is the server-side allow-list that **mirrors** `PRINT_BLOCK_TYPES` — held
  together **only by a comment** ⇒ rule-#35 missing mechanism (fixed below).
- **`appWeb/.sql/migrate-print-templates.php` + `tblPrintTemplates`** — storage is `BlocksJson`
  (ordered block list) + `PageOptionsJson` (page-level opts), both **JSON columns that already exist**.
  `Scope` VARCHAR, `OwnerId` FK (NULL = curated), `IsActive`, `IsDefault`, `SortOrder`.

**Consequence:** every *block type*, *block option* and *page option* is new JSON the editor +
renderer understand — **zero schema** (rule #20 one-pass already paid). Only genuinely new *stores*
(org branding, uploadable layouts, usage log, org-scoped ownership) need schema, and those are the
ones with open owner decisions.

## The split — buildable now vs decision-gated

### A. Buildable NOW (no schema, this branch)

| code | feature | kind | notes |
|---|---|---|---|
| — | **rule-#35 registry guard** | CI guard | derive block-type set from print.js + print-templates.php; assert equal; mutation-proven |
| N | Scripture reference block | new-block-type | data via `?include=scriptureRefs` (#1112) |
| O | Tune name + metre block | new-block-type | `tuneName` base + metre via `?include=tune` (#1748 done) |
| P | Themes/tags block | new-block-type | `tags` already in payload (#1152 done) |
| R | QR-code block → permalink | new-block-type | vendored `qrcodegen`; SVG inlined into the print doc |
| A | Per-component typography & alignment | block-option | on the `lyrics` block: align, size-scale |
| B | Book-name presentation mode | block-option | on `subtitle`: full / abbr / hide |
| F | Vertical-rhythm + Spacer slider | render-only | extend spacer sizes; line-height page opt |
| Y | Conditional block visibility | block-option | `showIf` (hasChords / hasCcli / …) — skip empties |
| G | Page-size selector (A4/Letter/Legal) | page-option | `@page size:` in printCss |
| V | Large-print + high-contrast a11y variant | page-option | `contrast` page opt |
| AB | Printer-friendly (mono/ink-saving) | page-option | `inkSaver` → strip greys to black, no bg |
| AM | Per-template accent/theme colour | page-option | `accentColor` on title/labels |
| Z | Template clone + JSON import/export | admin-ui-only | dupe row; textarea round-trip in the editor |
| J | Global-admin templates + system default | admin-ui-only | `IsDefault` (exists) — set-default action |

**Deferred within no-schema (higher risk, own follow-up slice, still this branch if time):**
H (multi-page running headers/footers via `@page` margin boxes — patchy browser support), and its
dependents U (page numbering), T (keep-verse-together / one-song-per-page — partially free: the
renderer already sets `break-inside:avoid` on components), AA (true-page preview), AG (fit-to-page).
M (content-gating of the print path): the browser path is **already** covered because print.js fetches
the gated `song_data`; the residual is an **invariant + guard** that print templates never emit raw
`/song-media/<id>` URLs (buildable now as a guard).

### B. Decision-gated (NOT this branch — batched to owner, non-blocking)

These need schema whose **final shape depends on an owner answer** (rule #20 forbids freezing DDL
before the shape is known). They are batched below and do **not** block the slice A work.

| code | feature | why gated |
|---|---|---|
| E | Uploadable full-page designs | **Decision #4** — full sanitised HTML upload vs constrained slot layout changes the table shape (`tblPrintTemplateCustomLayout`) |
| D | Org branding (SVG logo upload) | branding model (**Decision #3**) + `tblOrgBrandingAssets` shape |
| K | Org-default templates + org lock | `tblPrintTemplates.OrgId` + org-settings — pairs with D/E |
| L | Remove iHymns branding (white-label) | **one** `TIER_CAPS` JSON line, but only truly enforceable on the **server-PDF** path (review gap #3); depends on E/K |
| AK | CCLI auto-footer + print-usage log | reuse `tblSongUsageEvents.MetaJson` (review — no new table); **copies-vs-documents count** is an owner Q (review gap #6) |
| AL | Template preview thumbnails | **client-render** (review — no `ThumbnailPath` column) |
| W, X, AC, AD, AJ | RTL/embedded-fonts, batch, PDF metadata, booklet imposition, PDF/UA | **server-PDF pipeline** — needs **Decision #1** (build a server renderer at all?) |
| Q, S, AH | transpose/capo, bilingual parallel, chord diagrams | depend on #1768 chords / #1088 per-line translations — data-model prerequisites |

## Batched owner decisions (for GitHub #1767 — none block slice A)

1. **Server-side PDF renderer — build one, or stay browser-`print()`-only?** *(gates W/X/AC/AD/AJ/AK-footer-enforcement/L-enforcement)*
   Recommendation: **stay browser-only for now**; revisit only if a concrete need for pixel-exact,
   server-generated PDFs (batch mailouts, PDF/UA compliance) appears. Browser print covers the
   overwhelming majority of "print a song sheet" needs at zero new infra.
2. **AK print-usage count = documents printed, or physical copies?** Recommendation: **prompt for
   copies on print** (a licence-reporting under-count is a compliance defect, not cosmetic — review gap #6).
   Trivially changeable default if unanswered: count documents.
3. **Branding / white-label model** *(gates D/L/K)* — per-org uploaded logo + a `CanRemoveIHymnsBranding`
   tier cap? Recommendation: **defer to the #1769 gating epic** so the cap lands in the one `TIER_CAPS`
   registry, not bolted on here.
4. **Uploadable full-page designs (E) — full sanitised HTML upload, or a constrained slot layout?**
   Recommendation: **constrained slot layout** (safer, no HTML-sanitiser attack surface, still covers
   the "SDA Creation-style branded sheet" use case). This decision **fixes the shape** of
   `tblPrintTemplateCustomLayout`, so the schema batch waits on it.

## Invariants held throughout

- Rule #35: block-type set is enforced equal across print.js ↔ print-templates.php by a mutation-proven guard.
- Rule #30: no executable inline script added to any SPA fragment; the picker stays an ES module; the
  printout is a separate window.
- Rule #34: guards derived from the tree, proven able to fail (break→red→restore).
- One read path for the printout: `renderTemplateBodyHtml()` is the single source of truth for both the
  admin live preview and the printed page (byte-identical).
- Every new block-type option key is added to BOTH `PRINT_BLOCK_TYPES.options` (JS default) and
  `$BLOCK_SCHEMA` (PHP coercion/allow-list) in the same commit.
