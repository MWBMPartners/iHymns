# #1767 remainder — server-PDF, CCLI print-usage, custom HTML layouts — build plan

**Written 2026-08-11** (design pass on `claude/issue-sweep-fixes-89`; no code changed).
**Tracker:** #1767 · **Binds on:** the "REMAINDER DECISIONS — RESOLVED 2026-08-11" block in
`.claude/print-templates-1767-plan.md` (the four owner answers). This doc is the concrete build
plan for that remainder: a Sonnet builder should be able to implement it commit-by-commit with no
further design decisions beyond the ONE owner confirmation below.

Companion doc: `.claude/print-templates-1767-plan.md` (the shipped A–AM half + the decision log).
Nothing here re-plans what already shipped.

---

## DECISION FOR OWNER — confirm the PDF engine (non-blocking)

> **RESOLVED 2026-08-11 (owner): mPDF.** Build the server-PDF pipeline on **mPDF ~8.2**, vendored at
> dev time under `appWeb/private_html/lib/pdf/`, behind the ONE swappable `includes/pdf_renderer.php`.
> PDF/UA stays best-effort (accepted); a Chromium *service* remains the documented later swap path
> (same POSTed-HTML contract). All of §3/§4 already assume mPDF — proceed as written.


1. **The decision.** Which HTML→PDF engine the new server-side renderer uses: the pure-PHP
   **mPDF** library (recommended), the pure-PHP **Dompdf**, or a **self-hosted Chromium render
   service** in the CueRCode/IntApps mould.

2. **Why it needs deciding.** Three things only the owner can weigh: (a) **licence comfort** —
   mPDF is **GPL-2.0**; using it server-side in a proprietary app is legally fine because GPLv2
   obligations trigger on *distribution* and the appWeb server code is never distributed
   (SaaS use; the repo is private), but some owners prefer not to have GPL code in the tree at
   all — Dompdf is LGPL-2.1; (b) **accessibility ceiling** — NO pure-PHP engine can produce a
   fully **PDF/UA-tagged** PDF (feature AJ), so choosing pure-PHP caps AJ at "best-effort
   accessible" (title/lang metadata, logical order, alt text, optional PDF/A-1b) forever unless
   the engine is later swapped; (c) **infra appetite** — a Chromium service is browser-faithful
   and PDF/UA-capable but is a new project to build, host and key.

3. **The options.**

   | option | CSS fidelity | RTL / embedded fonts (W) | PDF/UA (AJ) | deployability on DreamHost shared (no shell — `DEV_NOTES.md:764`) | licence |
   |---|---|---|---|---|---|
   | **mPDF ~8.2 (recommended)** | good (columns via adaptation, §4.4) | **excellent** (its origin is multilingual; DejaVu shipped) | best-effort only; PDF/A-1b yes | ✅ pure PHP; needs ext-gd + ext-mbstring, both proven in prod (`og-image.php:137,178`; `mb_substr` everywhere) | GPL-2.0 (server-side use OK; note in LICENSING.md) |
   | Dompdf | weaker (no columns, fragile floats) | partial (font embedding manual) | no | ✅ pure PHP | LGPL-2.1 |
   | wkhtmltopdf / headless Chromium binary | browser-faithful | full | Chromium: yes (tagged) | ❌ needs a system binary + process exec the shared host forbids | n/a |
   | Chromium **render service** (CueRCode-pattern, `includes/cuercode_client.php`) | browser-faithful | full | yes | ✅ (the binary lives on OUR service host) | n/a — but a whole new service to build/host |
   | Do nothing (stay browser-only) | — | — | — | — | overridden: owner decided BUILD (decision #1) |

4. **Recommendation: mPDF.** It is the only option that is simultaneously deployable on the
   real production host today, strong at exactly the features this remainder unlocks (W
   embedded-fonts/RTL, U/T running footers + page numbers, AC metadata), and zero new infra.
   The GPL point is genuinely benign for server-side SaaS use. Crucially, **the architecture in
   §4 makes the engine a swappable internal detail** — HTML always arrives from the client's one
   renderer, and the engine sits behind ONE module (`includes/pdf_renderer.php`), so a later
   swap to a Chromium service (if full PDF/UA ever becomes a requirement) changes one file and
   zero call sites.

5. **What I need back:** one word — "mPDF", "Dompdf", or "service".
   **Blocks:** nothing. P1–P2 are engine-agnostic; the plan proceeds on mPDF as the defensible
   default and only P3's vendoring step commits to it (a Dompdf swap at that point is ~1 day).

---

## 1. Grounded facts (verified this pass, with citations)

**Environment.** Production = DreamHost shared hosting, PHP 8.1–8.5 + mysqli, **web-only, no
CLI/SSH** (`DEV_NOTES.md:613,764`). GD **with FreeType** is proven live (`og-image.php:137`
`imagecreatetruecolor`, `:178` `imagettftext`); mbstring and curl are used throughout. There is
**no composer.json anywhere in the repo** and `appWeb/public_html/vendor/` is empty in-tree —
populated at deploy by `tools/download-vendor.sh` (JS/CSS assets only). Chromium exists in the
dev sandbox but **NOT in prod** — nothing may depend on a browser binary server-side. The deploy
tree carries `private_html` as a sibling of `public_html`
(`manage/setup-database.php:1848-1849,1865`), i.e. we have a **non-web-served directory that
deploys** — the right home for a PHP library.

**The one renderer.** `js/modules/print.js` — `PRINT_BLOCK_TYPES` (L25), `PRINT_PAGE_OPTIONS`
(L50), `PRINT_SHOWIF_CONDITIONS` (L94), `renderBlock()` (L211), `renderTemplateBodyHtml()`
(L354, THE single source of truth), `printCss()` (L362), `buildPrintDoc()` (L407),
`printDoc()` (L423, opens a window + `print()`), `pickPrintTemplate()` (L477, shared picker),
`openSongPrintDialog()` (L546). The set-list print consumes the same picker + renderer
(`js/modules/setlist.js:4032-4090`). The admin editor previews through the same functions
(`manage/print-templates.php:775,791`, inline module on a full admin page — rule #30 exempt).

**Server allow-lists.** `manage/print-templates.php` — `$BLOCK_SCHEMA` (L54), `$SHOWIF_CONDITIONS`
(L75), `$PAGE_OPTION_SCHEMA` (L81), `ptSanitiseBlocks()` (L119), `ptSanitisePageOptions()` (L173);
page gate = `manage_songbooks` (L30-39), matching its nav entry
(`manage/includes/admin-links.php:80`). Lockstep guard:
`tests/php/test-print-block-registry.php` (tree-derived, mutation-proven).

**Storage.** `tblPrintTemplates` (`appWeb/.sql/schema.sql:4895` — BlocksJson/PageOptionsJson/
Scope/OwnerId/IsActive/IsDefault/SortOrder). Registry entry `'print-templates'`
(`manage/includes/migration-registry.php:2696`).

**Usage-log substrate (AK).** `tblSongUsageEvents` **already exists dormant**
(`schema.sql:3948`, migration `migrate-usage-events.php`): it has `UsageContext VARCHAR(20)`
with `'printed'` in its documented vocabulary, **`Quantity` ("Copies/prints… count")**,
`OrgId`/`UserId`/`LicenceId`/`SetlistId`/`Source`/`MetaJson`. **No PHP writer exists yet**
(grep: only the registry card + `song_relocate.php:461` reference it) — AK is its first writer,
and **zero new schema is needed for AK**. The CCLI report page (`manage/ccli-report.php`,
entitlement `view_ccli_report`) currently reports views from `tblSongHistory` only — it gains a
printed-copies section (P5).

**Licence resolution (AK's gate).** `includes/licences.php::getUserEffectiveLicences()` (L228)
resolves a user's personal + org-chain licence set (`type`, `key`, `source`, `source_id`,
`inherited`); `licenceCcliQualifies()` (L140) is the qualifying test. `resolveEffectiveTier()`
(`includes/ccli_validator.php:163`) and `checkContentAccess()`
(`includes/content_access.php:234`) stay untouched — AK asks "is this user CCLI-licensed",
which is exactly `getUserEffectiveLicences()` + `licenceCcliQualifies()` (the same pair the
#1770 host-CCLI branch reuses, `includes/service_mode.php:1049-1051`).

**Sanitiser status.** There is **NO existing house HTML sanitiser**: no HTMLPurifier anywhere,
no `includes/*sanit*`; `strip_tags` appears only incidentally (`EmailService.php`,
`duplicate-songs.php`, `setup-database.php`); `DOMDocument` is used only by importers
(`lyrics_ingest.php`, `MusicXmlImporter.php`, `marcxml.php`, `PptxImporter.php`). E therefore
requires a NEW module — `includes/html_sanitizer.php` (§5) — built once, reused by both E and
the PDF endpoint.

**Patterns to mirror.** Outbound fail-soft client: `includes/cuercode_client.php` /
`includes/intapps_client.php`. Standalone byte-streaming endpoint: `qr.php` / `og-image.php`.
Not-public admin discipline + tokenizer-stripped truth-table guards: #94's
`tests/php/test-ia-reconcile-guards.php`. CSRF for state-changing AJAX:
`validateCsrfRequest()` (`manage/includes/auth.php:1191`, rule #29). Rate limiting:
`enforceReadRateLimitKeyed()` (`includes/read_rate_limit.php`; pairing discipline in
`tests/php/test-rate-limit-pairing.php`). Safe `Content-Disposition`:
`manage/analytics.php:56-60`. Vendored pdf.js already exists for any future inline preview
(`includes/config.php:415-422`) though §7 uses the browser's native viewer.

---

## 2. Architecture centrepiece — where the HTML comes from (the one-renderer invariant)

The invariant (held since #1350 and restated in the shipped plan): **`renderTemplateBodyHtml()`
in `js/modules/print.js` is the single source of truth for the printed page — the admin preview
and the printout are byte-identical.** A server PDF renderer must not become a second block
renderer. Three candidate architectures, assessed against that invariant:

| | (a) client renders, POSTs finished HTML; server does HTML→PDF only | (b) headless Chromium runs print.js server-side | (c) PHP port of the renderer |
|---|---|---|---|
| invariant | **preserved** — the server never knows what a "block" is | preserved (same JS) | **violated** — two renderers, guaranteed drift |
| prod deployability | ✅ pure PHP converter | ❌ browser binary + exec on shared hosting | ✅ but rejected anyway |
| batch (X) | ✅ client loops `renderTemplateBodyHtml()` per song, POSTs the set | ✅ | — |
| headless/cron PDFs with no browser | ❌ (accepted: every PDF surface is browser-initiated) | ✅ | — |
| new attack surface | POSTed HTML is untrusted → must be sanitised (§5 — needed for E anyway) | service hardening | — |

**FIRM DECISION: (a).** The client — the one place the one renderer lives — produces the HTML
exactly as it does for browser print (`buildPrintDoc()`), and POSTs `{bodyHtml, css,
pageOptions, meta}` to a server endpoint whose ONLY jobs are *sanitise → adapt → convert →
enforce → stream* (§3). (c) is rejected outright. (b) is rejected for prod but its spirit
survives as the engine-swap path: because the HTML source is the client under (a), a future
Chromium *service* engine (see the owner decision) would receive the same POSTed HTML — the
HTML-source decision is **orthogonal to** and survives any engine change.

**What the server MAY add without becoming a renderer** (the precise line): document *chrome*
that the browser path structurally cannot produce — running headers/footers/page numbers
(mPDF `SetHTMLHeader`/`SetHTMLFooter`, the H-family), PDF metadata (AC), the enforced CCLI
notice line (AK) and, later, the #1769 branding footer (L). These are page-furniture strings
built from **server-fetched song rows** (never parsed out of the POSTed HTML), each a single
`<div>` with an allow-listed class — not block rendering. The mutation-proven guard in §8 bans
anything beyond that line (no `case '<blocktype>'` in any server PDF module).

**Trust note.** A malicious client can POST any HTML it likes — that is why the endpoint (i)
sanitises input through the same default-deny module that guards E uploads, (ii) never grants
the PDF path data the caller couldn't already fetch (the body came FROM `song_data`, which is
already tier-gated server-side, `api.php:1188-1196`), and (iii) performs AK logging and footer
enforcement from server-resolved data only. A PDF is a *formatting* service, not an access
grant.

---

## 3. The server-PDF pipeline

### 3.1 Endpoint — `manage/print-pdf.php` (new, standalone, NOT in api.php)

Mirrors the #94 not-public discipline: an authenticated `/manage/*` endpoint, **no `api.php`
action, no page fragment, nothing in `$_cacheablePages`**, and a guard asserting all three (§8).

- **Method/auth:** POST JSON only. `requireAuth()` **before any output** (guard-checked
  ordering, mirroring `tests/php/test-admin-gate-parity.php`). Deliberately NO entitlement
  beyond authentication: the endpoint formats data its caller can already read via public
  `song_data`, and the surfaces that call it (template editor, batch card) carry their own
  page gates (`manage_songbooks`). Document this reasoning in the file doc-block so the
  admin-gate audit doesn't read it as an omission.
- **CSRF:** `validateCsrfRequest()` (rule #29); the clients always send `X-Requested-With`.
- **Rate limit:** `enforceReadRateLimitKeyed('pdf', 20)` (PDF renders are heavy; fail-open,
  same posture as `qr.php`). Register the pair per `test-rate-limit-pairing.php`.
- **Request contract:**

  ```jsonc
  {
    "mode": "song" | "batch" | "preview",
    "documents": [ { "bodyHtml": "…", "meta": { "songId": "MP-1008", "title": "…", "lang": "en", "dir": "ltr" } } ],
    "css": "…printCss() output…",
    "pageOptions": { … },                 // re-validated server-side (see below)
    "templateId": 12,                     // optional, MetaJson only
    "copies": 30,                         // optional — AK, §6
    "filename": "amazing-grace"           // slug-sanitised server-side (analytics.php:56 discipline)
  }
  ```

  Caps (400 on breach, constants at top of file): ≤ 150 documents, ≤ 512 KiB per `bodyHtml`,
  ≤ 8 MiB total, ≤ 64 KiB `css`, `copies` 1–10 000.
- **`pageOptions` re-validation:** re-run **the same `$PAGE_OPTION_SCHEMA` coercion** the save
  path uses. To avoid a third copy of the schema (rule #35), **P3 extracts** `$BLOCK_SCHEMA`,
  `$SHOWIF_CONDITIONS`, `$PAGE_OPTION_SCHEMA`, `ptSanitiseBlocks()` and
  `ptSanitisePageOptions()` from `manage/print-templates.php` into a new shared
  `includes/print_template_schema.php`; the admin page and the endpoint both require it.
  `test-print-block-registry.php`'s anchors are updated to read the new file (mutation re-prove
  after the move — rule #34).
- **Pipeline order (fixed):** validate + caps → sanitise each `bodyHtml`
  (`ihymnsSanitizeHtml($html, 'print')`, §5) → sanitise `css`
  (`ihymnsSanitizeCss($css)`, §5.3) → adapt (§4) → server-side enrichment: AK notice footer
  (§6.3), page numbers / running header when the pageOptions ask (§4.3), AC metadata → convert
  via `includes/pdf_renderer.php` → AK usage log when `copies` present and the caller is
  CCLI-licensed (§6.2) → stream `application/pdf` as `attachment` (or `inline` for
  `mode=preview`).
- **Failure statuses are the contract (rule #35):** 400 invalid/caps · 401 unauthenticated ·
  403 CSRF · 429 rate-limited · **503 engine unavailable** (library not vendored on this
  install — mirrors `qr.php`'s dormant-503 discipline; the client toasts "PDF engine not
  installed" and the browser-print path is unaffected). Clients branch on `err.status`, never
  on prose.
- **`?ping=1` GET mode:** answers 204 when the caller is authenticated AND the engine is
  available, else 401/503 with no body. This is how public-page JS decides whether to show the
  "Download PDF" affordance (§3.3) without hardcoding roles client-side.

### 3.2 Converter module — `includes/pdf_renderer.php` (new)

The ONE engine wrapper, fail-soft like `cuercode_client.php`:

- `ihymnsPdfEngineAvailable(): bool` — true when the vendored autoloader exists.
- `ihymnsPdfRender(array $docs, string $css, array $pageOptions, array $opts): ?string` —
  returns PDF bytes or **null on any failure** (never throws out).
- **Vendoring:** new `tools/build-pdf-vendor.sh` runs composer **on a dev machine** (`composer
  require mpdf/mpdf:^8.2` into a scratch dir) and copies the resulting tree to
  **`appWeb/private_html/lib/pdf/`** (committed; outside every docroot so mPDF's utility files
  are never web-addressable; deploy already carries `private_html`,
  `setup-database.php:1848`). Add a belt-and-braces `.htaccess` deny inside it. Prod needs no
  composer, no shell — matching the existing "no CLI on the host" reality. Add the GPL-2.0
  note to `LICENSING.md` (the animate.css note at `LICENSING.md:47` is the precedent format).
- **Hardened mPDF config:** `tempDir` = `appWeb/private_html/tmp/mpdf/` (created on demand;
  fallback `sys_get_temp_dir()`); remote fetching irrelevant because the sanitiser has already
  removed every remote reference (§5) and QR imgs are inlined as data URIs (§4.5); fonts =
  shipped DejaVu set only in v1; `SetTitle`/`SetAuthor`/`SetCreator('iHymns')`/`SetSubject`/
  `SetKeywords` from validated `meta` (AC); optional `PDFA` flag reserved (dormant).
- **CI smoke test** `tests/php/test-pdf-smoke.php`: when the vendored tree exists, render
  `"<p>hello</p>"` and assert the output starts `%PDF-`; when absent, assert
  `ihymnsPdfEngineAvailable()` is false and the endpoint source maps that to 503 (so CI stays
  green on a checkout without the vendor tree, and the dormant path is itself tested).

### 3.3 Client affordances

- **Picker "Download PDF" button** (`pickPrintTemplate()`, print.js L477): rendered alongside
  Print, hidden until a session-cached `?ping=1` says 204 (one GET per session, memoised).
  Resolves the promise with `{tpl, action: 'print'|'pdf'}`; `openSongPrintDialog()` (L546) and
  `setlist.js printSetList()` (L4032) branch: `'print'` = today's `printDoc()` path unchanged;
  `'pdf'` = build the same doc pieces and POST via `apiFetch` (rule #31), then
  `URL.createObjectURL` → programmatic download. **Browser print stays the default and never
  regresses** — a 503/failed POST toasts and offers Print instead.
- **Admin editor "Preview as PDF" (AA):** a button beside the live preview
  (`print-templates.php:775`) POSTs the *sample-song* doc with `mode=preview` and shows the
  blob in a modal `<iframe>` (native browser PDF viewer; no pdf.js needed). This is the true
  paginated preview the browser preview can't give.
- **Public end-user PDF download** (anonymous / non-admin accounts) is **NOT in this
  remainder** — it is queued behind the #1769 gating epic where the existing `download_pdf`
  action / `CanDownloadPdf` cap (`includes/ccli_validator.php:452` matrix; rule #28) already
  reserves its gate. File a follow-up issue; the endpoint architecture needs zero change to
  serve it later (an api.php-side thin wrapper adding tier checks).

---

## 4. CSS-fidelity reconciliation (browser path vs mPDF path)

`printCss()` (print.js L362-404) stays THE stylesheet for both paths. The server never authors
CSS; it **adapts** the client's CSS/HTML deterministically and the divergences are enumerated,
documented in the endpoint doc-block, and frozen by tests:

### 4.1 What passes through unchanged
Everything `printCss()` emits today is inside the sanitiser's CSS allow-list (§5.3) and inside
mPDF's supported set: `@page size`, font sizes/weights/styles, colors, margins,
`break-inside/page-break-inside: avoid` (T — mPDF honours it), `white-space: pre-wrap` (chords),
`text-align`, `@media print` (harmless — mPDF applies print media).

### 4.2 Documented divergences (v1, accepted)
1. **Font faces:** Georgia / Courier New aren't shipped; `pdf_renderer` maps the two known
   stacks → DejaVu Serif / DejaVu Sans Mono (a literal 2-entry map, not a CSS engine). Metrics
   differ slightly → line wraps can differ from the browser printout. Accepted: the PDF is a
   *sibling* output, not a byte-clone of the browser print.
2. **CSS multi-columns** (`columns:2` on `.print-lyrics`, print.js L208): mPDF does not support
   the CSS property. Adapter: a regex-free DOM transform (on the already-sanitised tree)
   rewrites `.print-lyrics[style*=columns:2]` → mPDF's proprietary `<columns column-count="2">`
   wrapper. Builder MUST verify this against the vendored mPDF version in P3; if it
   misbehaves, the fallback is single-column + a documented divergence note — never a PHP
   re-render of the lyrics.
3. **`filter: grayscale(1)`** (ink-saver QR): unsupported; adapter requests the QR from
   CueRCode in mono instead (§4.5) or leaves colour — cosmetic only.
4. **PDF/UA:** best-effort only (see the owner decision). Emit `lang`, `dir`, metadata, alt
   text; no tagged structure tree.

### 4.3 The H-family lives ONLY on the server path — as `serverOnly` page options
Browser `@page` margin-box support is too patchy to ship (the reason U/T/AA/AG were deferred).
New `PRINT_PAGE_OPTIONS` entries (JS, print.js L50) + `$PAGE_OPTION_SCHEMA` mirrors, each
carrying a new `serverOnly: true` / `'server_only' => true` flag:

| key | kind | default | effect (PDF path only) |
|---|---|---|---|
| `pageNumbers` (U) | bool | false | mPDF `SetHTMLFooter('{PAGENO} / {nbpg}')` |
| `runningHeader` | enum `none\|title\|titleBook` | `none` | mPDF `SetHTMLHeader` from `meta` (server-fetched title, not parsed HTML) |
| `onePerPage` (T-strong) | bool | false | `AddPage` between documents / forced `page-break-before` per doc |

`printCss()` ignores them (browser output byte-identical); the editor renders them in a
"PDF only" group with a badge. `test-print-block-registry.php` is extended to assert the
`serverOnly` flag agrees on both sides (mutation-prove: flip one side → red).
**AG fit-to-page** (bounded font-scale iteration: render → page count → binary-search `fontPt`,
max 4 renders) is designed but deferred to a follow-up issue — multiple renders per request is
the wrong first cost on shared hosting.

### 4.4 Batch (X) composition
`mode=batch`: the client fetches the songbook via the existing scoped
`?action=songbook_export` (api.php:1273 — rule #17 compliant, one songbook, already
export-gated) or the set-list via the existing `fetchSong` loop (`setlist.js:4052`), renders
each song with `renderTemplateBodyHtml()`, and POSTs the array. Server concatenates with page
breaks (or `onePerPage`). v1 cap 150 songs; whole-book PDFs for larger books are a follow-up
(FPDI chunk-merge), stated honestly in the UI ("first 150 songs").

### 4.5 QR images
POSTed HTML contains `<img src="/qr.php?data=…">` (print.js L335). mPDF would have to
self-request the host — slow and fragile on shared hosting. Adapter instead: for each img whose
src matches `^/qr\.php\?` (the ONLY img src the `print` sanitiser profile admits), parse the
query, call `cuercodeGenerate()` **directly** (the ONE QR client, rule #38) and inline the
result as a `data:` URI; on null (CueRCode dormant) drop the img — the caption URL beside it
(always emitted, print.js L340) is the fallback, same principle as the browser path.

---

## 5. The shared HTML sanitiser — `includes/html_sanitizer.php` (new; security-critical)

One module, two consumers (E uploads + the PDF endpoint), profile-driven **default-deny**.
No existing house sanitiser exists (§1) and HTMLPurifier is rejected (huge, composer-oriented,
and our needs are narrow + print-specific). Build on `DOMDocument` (already a house dependency
via the importers) with `LIBXML_NONET`.

### 5.1 Shape
- `const IHYMNS_HTML_SANITISER_VERSION = 1;` — bumped on any rule change; stored per row (§5.4).
- `ihymnsSanitizeHtml(string $html, string $profile): string` — parse → walk → **rebuild into a
  fresh document keeping only allow-listed nodes/attrs** (default-deny: unknown element ⇒
  unwrapped to its text children; unknown attr ⇒ dropped) → serialise. Never regex-based.
- `ihymnsSanitizeCss(string $css): string` (§5.3) and
  `ihymnsSanitizeStyleAttr(string $decl): string` share one declaration filter.
- Profiles are **data** (one `IHYMNS_SANITIZER_PROFILES` const), not forked code paths:
  - **`print`** (PDF-endpoint input): exactly the tags/classes `renderTemplateBodyHtml()` can
    emit — `div,span,h1,p,br,table? (no),img` + `class` restricted to the `print-*`/`lyric-*`
    token set **derived at guard-time from print.js** (§8), `style` (declaration-filtered),
    `dir`, `lang`; `img src` ONLY `^/qr\.php\?`.
  - **`layout`** (E uploads): formatting superset — `div,span,p,h1–h6,br,hr,strong,em,b,i,u,
    small,sup,sub,blockquote,ul,ol,li,table,thead,tbody,tfoot,tr,th,td,img,header,footer,
    section`; attrs `class` (`[A-Za-z0-9_-]+` tokens), `style`, `dir`, `lang`, `colspan/rowspan`
    (ints), `img`: `alt`,`width`,`height` + `src` ONLY `^data:image/(png|jpe?g|gif|webp);base64,`
    with ≤ 200 KiB decoded (self-contained, no network, no SVG — SVG is a script vector) or
    `^/qr\.php\?`.
- **Banned always, both profiles:** `script,style,link,meta,base,iframe,frame,object,embed,
  form,input,button,select,textarea,svg,math,template,slot,audio,video,source`, every `on*`
  attribute, `id`, `href`/`<a>` (v1 — print layouts don't need live links; the permalink block
  prints text), any URL scheme other than the two src shapes above. **Default-deny also kills
  every mPDF proprietary tag** (`<barcode>`, `<pagebreak>`, `<indexentry>`, `<annotation>`,
  `<columns>` …) in *uploaded* content — only the server adapter may introduce `<columns>`
  (§4.2), after sanitisation, from its own literal strings.

### 5.2 Why sanitise even our own renderer's output
The `print` profile looks redundant (the client built the HTML from `esc()`d data) — it is not:
the POST body is attacker-writable independently of print.js. The endpoint must hold even if
print.js never ran. The tight profile also structurally guarantees rule M: a
`/song-media/<id>` URL **cannot survive** either profile's src allow-list, so the PDF path can
never leak gated media bytes regardless of what was POSTed.

### 5.3 CSS filter
Tokenize declarations (split on `;` outside quotes/parens); keep only allow-listed properties
(the full set `printCss()` uses today + layout-useful additions: `color, background(-color),
font-*, line-height, letter-spacing, text-*, margin*, padding*, border*, width, height,
max-*, min-*, display (enum), float, clear, position (static|relative|absolute), top, left,
right, bottom, z-index, page-break-*, break-*, columns, column-*, white-space, vertical-align,
opacity, border-radius, box-sizing, size, filter (grayscale only), word-break, print-color-adjust`);
reject any VALUE containing `url(`, `expression(`, `@import`, `@charset`, backslash escapes, or
`</`. For the whole-sheet `css` string additionally allow `@page { … }` and `@media print { … }`
wrappers and class/element selectors only (no attribute selectors, no `@font-face`).
`position:absolute` is deliberately allowed in `layout` — a full-page design needs it (owner
chose max flexibility) and in a print/PDF context it carries no interactive-overlay risk.

### 5.4 Storage + versioning (feeds §7's table)
Store **only** sanitiser OUTPUT in the render-served column (`HtmlSanitised`); keep the raw
upload in a dormant `HtmlOriginal` column that **no render path may read** (guard-banned, §8) —
it exists solely so a future allow-list *widening* can re-sanitise from source, and
`SanitiserVersion` records which rules produced each row so a security *tightening* (version
bump) can flag rows for re-sanitise via a one-click admin action on `/manage/print-templates`.

### 5.5 Guard — `tests/php/test-html-sanitizer.php` (functional truth table, rule #34)
Modelled on `test-ia-reconcile-guards.php`'s SSRF table: `require` the module and run real
inputs through it — `<script>`, `<img onerror=…>`, `javascript:`/`vbscript:` URLs,
`data:text/html`, SVG data-URI, `<style>@import`, `style="background:url(//evil)"`,
**`/song-media/123` as img src and as CSS url()**, mPDF `<barcode>`, an `{{content}}` token
(must survive as text), nested/malformed markup, a benign styled fixture (must survive
byte-meaningfully) — assert exact expected outputs. Mutation-prove by commenting out one
allow-list line → red → restore.

---

## 6. AK — CCLI print-usage logging (prompt-for-copies) + auto-footer

Owner decision #2: count = **copies, prompted**; reuse `tblSongUsageEvents.MetaJson`; only
under a CCLI licence. Zero new schema (§1).

### 6.1 Context read — `api.php?action=print_usage_context` (GET, new)
Input `songId`. Output `{ ccli: { licensed: bool, licenceKey: string|null } }` where
`licensed` = `getAuthenticatedUser()` resolves AND `getUserEffectiveLicences()` contains a
`type==='ccli'` entry passing `licenceCcliQualifies()` (licences.php:140,228) AND the song row
has a non-empty `Ccli`. Anonymous/unlicensed/no-CCLI-number → `false` (and the whole feature is
invisible — the print flow today is byte-identical for them). Cheap, uncached, no rate concern
beyond the API default.

### 6.2 The ONE write path — `includes/print_usage.php` (new)
`printUsageLog(int $userId, string $songId, int $copies, array $meta): bool` — **re-validates
the licence server-side** (never trusts the client's `licensed` claim), resolves `OrgId` from
the licence row's `source_id` when `source==='org'`, inserts into `tblSongUsageEvents`:
`UsageContext='printed'`, `Quantity=$copies` (clamped 1–10 000), `Source='app'`,
`SetlistId` when supplied, `MetaJson={templateId, surface:'browser'|'pdf', licenceKey}`.
`LicenceId` stays NULL in v1 (`getUserEffectiveLicences()` doesn't surface the
`tblOrganisationLicences.Id`; extending it is a noted, trivially-changeable follow-up —
defensible default, flagged per the owner-decision protocol). Table-existence-gated
(INFORMATION_SCHEMA probe; `migrate-usage-events` may be unapplied — degrade to no-op `false`,
never a 500; red-flag §"treats query() as returning false" respected via try/catch).
Consumers: a new `api.php` POST action `print_usage_log` (requires auth +
`X-Requested-With`, rule #29 posture) for the browser-print path, and `manage/print-pdf.php`
directly for the PDF path. **Both funnel here — one writer.**

### 6.3 UX + the auto-footer
- **Picker:** when `print_usage_context` says licensed, `pickPrintTemplate()` shows a
  "Copies to print" number input (default 1) + one line of explanation ("logged for CCLI
  reporting"). On Print → open the print window as today, then POST `print_usage_log`
  (fire-and-forget; a failed log toasts but never blocks printing — under-blocking beats
  losing the printout; the toast asks the user to retry so the count isn't silently lost).
  On Download PDF → `copies` rides the PDF POST and the SERVER logs (§3.1) — no separate call.
- **Client auto-footer (advisory):** `buildPrintDoc()` appends
  `<div class="print-ccli-notice">` when the context is licensed and the song has a CCLI
  number — wording built by a new exported `ccliNoticeText(song, licenceKey)` in print.js:
  `CCLI Song #<ccli> · Reproduced under CCL Licence #<key>. Used by permission.`
- **Server auto-footer (ENFORCED):** `manage/print-pdf.php` appends the same notice as an
  mPDF page footer for every document whose server-fetched song row has a CCLI number, when the
  authenticated caller is CCLI-licensed — **regardless of what the POSTed HTML contains** (this
  is the enforcement the browser path can never give; PHP-side text lives in
  `includes/print_usage.php::ccliNoticeText()`). The JS and PHP format strings are held in
  lockstep by extending `test-print-block-registry.php` to extract and compare both literals
  (rule #35 — a mechanism, not a comment).
- **Report surface:** `manage/ccli-report.php` gains a "Printed copies" column
  (`SUM(Quantity)` over `UsageContext='printed'` in the date range, existence-gated LEFT
  JOIN) and the CSV gains the same column — the table's first reader, closing the loop from
  prompt → log → report.

---

## 7. E — uploadable full-page designs (`tblPrintTemplateCustomLayout`)

Owner decision #4: full sanitised HTML upload. The uploaded HTML is a **document WRAPPER** (a
skin), never a content renderer — song content still comes only from
`renderTemplateBodyHtml()`, injected where the layout's `{{content}}` token sits. This is how E
and the one-renderer invariant coexist.

### 7.1 Data flow
1. **Upload** (`manage/print-templates.php`, gate unchanged `manage_songbooks`): file input
   (`.html`, ≤ 256 KiB) or paste-textarea per template → server runs
   `ihymnsSanitizeHtml($raw, 'layout')` → validates **exactly one `{{content}}` token
   survives** (reject otherwise — a layout that lost its slot renders no song) → stores
   `HtmlSanitised` (+ `HtmlOriginal`, `SanitiserVersion`, `SizeBytes`) keyed
   `(TemplateId, Slot='page')`.
2. **Serve:** the `print_templates` API payload (api.php:1227) gains `layoutHtml` per template
   (existence-gated JOIN; absent pre-migration → key omitted, back-compat).
3. **Render (client, both preview and print):** new exported
   `applyCustomLayout(layoutHtml, song, contentHtml)` in print.js — replaces `{{content}}`
   with the `renderTemplateBodyHtml()` output and a small fixed metadata token set
   (`{{title}} {{songbook}} {{number}} {{ccli}} {{copyright}} {{date}} {{pageUrl}}`) with
   `esc()`d values; unknown tokens are left visible (fail-visible in the preview, not silently
   dropped). `buildPrintDoc()` and the admin preview both call it when `layoutHtml` is present
   — the preview stays byte-identical to the printout, invariant intact.
4. **PDF path:** nothing special — the client POSTs the post-substitution HTML; the endpoint's
   `print` profile is widened to ALSO accept the `layout` profile's tag set when the template
   carries a layout (simplest correct rule: the endpoint sanitises with `layout`, which is a
   superset — one profile at the endpoint, tightest at upload).

### 7.2 Why substitution happens AFTER sanitisation
Sanitising the stored layout once at save (not per render) keeps render cheap; the substituted
values cannot re-open XSS because metadata tokens are `esc()`d and `contentHtml` is
renderer-built (escaped by construction, print.js L154). The tokens themselves are plain text
nodes — the sanitiser passes them through untouched.

### 7.3 Guard (§8) — the two sinks
The layout renders in (i) the print window / preview (same-origin, no CSP — an XSS here would
run as the site) and (ii) mPDF. Both are fed ONLY from `HtmlSanitised`; the guard bans any
`HtmlOriginal` read outside the sanitiser/re-sanitise admin action, and the truth-table (§5.5)
is the vector-level proof.

---

## 8. CI guards (all tree-derived + mutation-proven, rule #34)

1. **`tests/php/test-html-sanitizer.php`** — the §5.5 functional truth table.
2. **`tests/php/test-print-pdf-endpoint.php`** — static, tokenizer-stripped
   (`token_get_all`, the `test-ia-reconcile-guards.php` lesson): asserts (a) `requireAuth()`
   + `validateCsrfRequest()` appear before any body emit in `manage/print-pdf.php`; (b) NO
   `print_pdf`-shaped `case` exists in `api.php` and the endpoint path appears nowhere under
   `includes/pages/`/`includes/partials/` (not-public, #94 discipline); (c)
   `ihymnsSanitizeHtml(` is called before `ihymnsPdfRender(` (source-order check); (d)
   `enforceReadRateLimitKeyed('pdf'` present; (e) `Content-Disposition` built only from the
   slug-sanitised name; (f) `printUsageLog(` is reachable only behind the licence re-check.
3. **`tests/php/test-print-one-renderer.php`** — THE invariant guard. Derives the block-type
   set from `PRINT_BLOCK_TYPES` in print.js (same extraction as the registry guard) and
   asserts none of `manage/print-pdf.php`, `includes/pdf_renderer.php`,
   `includes/html_sanitizer.php`, `includes/print_usage.php` contains `case '<type>'` /
   `renderBlock` / a `print-<type>`-class EMITTER (allow-list: the two server-chrome classes
   `print-ccli-notice` and the running header/footer wrappers). Also asserts `printCss` is
   defined only in print.js. Mutation-prove: add `case 'title':` to pdf_renderer.php → red.
4. **`test-print-block-registry.php` extensions** — after the P3 schema extraction, re-anchor
   on `includes/print_template_schema.php` (re-prove mutations); add `serverOnly` flag parity
   (§4.3); add the JS↔PHP `ccliNoticeText` literal comparison (§6.3).
5. **Layout guards** (inside #1/#2 or a small third file): `HtmlOriginal` referenced only in
   the sanitiser + the admin re-sanitise action (tree-derived grep with tokenizer comment
   stripping); `{{content}}`-required validation present in the save path; `/song-media/`
   fixture rejection in the truth table (rule M).
6. **`tests/php/test-pdf-smoke.php`** — §3.2 (engine render or honest-dormant, both asserted).

Every guard follows the discipline: derive from the tree, break the thing, watch red, restore
byte-identical.

---

## 9. One-pass schema batch (rules #19/#20) — ONE migration, additive, idempotent, dormant

**`appWeb/.sql/migrate-print-template-layouts.php`** creates/adds, gated per-object
(INFORMATION_SCHEMA existence probes; mysqli STRICT-safe):

```sql
CREATE TABLE IF NOT EXISTS tblPrintTemplateCustomLayout (
    Id               INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    TemplateId       INT UNSIGNED    NOT NULL COMMENT 'FK tblPrintTemplates.Id — the template this full-page layout skins',
    Slot             VARCHAR(20)     NOT NULL DEFAULT 'page' COMMENT 'page | cover | continuation | … (VARCHAR not ENUM, rule #20; reserved multiplicity — v1 writes only ''page'')',
    HtmlSanitised    MEDIUMTEXT      NOT NULL COMMENT 'The ONLY render-served payload — output of ihymnsSanitizeHtml(layout). Render paths must never read HtmlOriginal.',
    HtmlOriginal     MEDIUMTEXT      NULL DEFAULT NULL COMMENT 'Upload as received (dormant) — sole source for re-sanitising after an allow-list change; guard-banned from render paths',
    SanitiserVersion INT UNSIGNED    NOT NULL DEFAULT 1 COMMENT 'IHYMNS_HTML_SANITISER_VERSION that produced HtmlSanitised; a bump flags rows for re-sanitise',
    SizeBytes        INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'LENGTH(HtmlSanitised) at save — cheap cap/audit read without pulling the blob',
    IsActive         TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '0 = template falls back to the standard document shell without deleting the upload',
    CreatedBy        INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK tblUsers.Id — who uploaded it',
    CreatedAt        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_TemplateSlot (TemplateId, Slot),
    INDEX idx_CreatedBy (CreatedBy),

    CONSTRAINT fk_PtLayout_Template
        FOREIGN KEY (TemplateId) REFERENCES tblPrintTemplates(Id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_PtLayout_User
        FOREIGN KEY (CreatedBy) REFERENCES tblUsers(Id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

plus (K, org-default templates — schema now even if the UI slips to P9):

```sql
ALTER TABLE tblPrintTemplates
    ADD COLUMN OrgId INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'FK tblOrganisations.Id — NULL = global/curated; set = visible only to that org''s members (K, #1767; UI may lag the column)'
        AFTER OwnerId;      -- @migration-adds tblPrintTemplates.OrgId
ALTER TABLE tblPrintTemplates ADD INDEX idx_Org (OrgId);
ALTER TABLE tblPrintTemplates
    ADD CONSTRAINT fk_PrintTemplate_Org FOREIGN KEY (OrgId)
        REFERENCES tblOrganisations(Id) ON DELETE CASCADE ON UPDATE CASCADE;
```

- Byte-identical mirrors appended to `appWeb/.sql/schema.sql` (incl. COMMENT text) in the SAME
  commit; `@migration-adds` doctag per added column (rule #19).
- ONE registry entry `'print-template-layouts'` in `manage/includes/migration-registry.php`
  with a multi-object OR-probe:
  `!_migProbe_tableExists($db,'tblPrintTemplateCustomLayout') || !_migProbe_columnExists($db,'tblPrintTemplates','OrgId')`.
- **Explicitly NOT in the batch:** AK storage (`tblSongUsageEvents` exists), AL thumbnails
  (client-render — the review already rejected a `ThumbnailPath` column), all D/L branding
  stores (deferred to #1769 — decision #3; **the hook it leaves behind:** the enforcement
  point for the future `CanRemoveIHymnsBranding` cap is the same server-chrome seam as the AK
  footer in `manage/print-pdf.php` §3.1's enrichment step — one `if (!tierCap…) append
  branding footer` line when #1769 lands, no schema here).

---

## 10. Commit plan (one PR to `alpha`, atomic commits, each with its guard)

| # | commit | contents | proves |
|---|---|---|---|
| P1 | `feat(print): schema batch — custom layouts + template OrgId` | §9 migration + schema.sql + registry entry/probe | `test-schema-coverage.php`, `test-migration-registry.php` green; dormant (no reader yet) |
| P2 | `feat(print): shared HTML/CSS sanitiser` | `includes/html_sanitizer.php` (§5), no consumers yet | new `test-html-sanitizer.php` truth table, mutation-proven |
| P3 | `feat(print): server PDF pipeline (mPDF) + endpoint` | `tools/build-pdf-vendor.sh` + committed `private_html/lib/pdf/`; `includes/pdf_renderer.php`; **schema extraction** to `includes/print_template_schema.php`; `manage/print-pdf.php` (song + preview modes); LICENSING.md note | `test-pdf-smoke.php`, `test-print-pdf-endpoint.php`, `test-print-one-renderer.php`, re-proven `test-print-block-registry.php` |
| P4 | `feat(print): PDF affordances + H-family server page options` | picker Download-PDF (+ ping memo), editor Preview-as-PDF (AA); `pageNumbers`/`runningHeader`/`onePerPage` as `serverOnly` options (U/T); W basics (dir/lang passthrough, font map); AC metadata | registry guard `serverOnly` parity; existing JS suites (`test-setlist-print.js` updated) |
| P5 | `feat(print): AK CCLI print-usage (copies prompt + log + enforced footer)` | `includes/print_usage.php`; api actions `print_usage_context`/`print_usage_log`; picker copies input; client notice + server-enforced footer; ccli-report printed-copies column/CSV | endpoint guard (licence-gated log); notice-literal lockstep in registry guard |
| P6 | `feat(print): batch songbook/set-list PDF (X)` | `mode=batch` + caps; batch card on `/manage/print-templates`; set-list Download-PDF | endpoint guard caps assertions |
| P7 | `feat(print): E — uploadable full-page layouts` | upload/paste UI + save (sanitise, `{{content}}` validation, versioning, re-sanitise action); `layoutHtml` in `print_templates` payload; `applyCustomLayout()` in print.js wired into preview + `buildPrintDoc()` + PDF path | layout guards (§8.5); truth-table vectors |
| P8 | `feat(print): AL template thumbnails (client-render)` | list-page mini previews: sandboxed `srcdoc` iframes of `buildPrintDoc(PRINT_SAMPLE_SONG, tpl)` scaled ~0.18, lazy via IntersectionObserver; no schema | visual; no new guard needed (pure consumer) |
| P9 *(optional, only if genuinely cheap)* | `feat(print): K — org-scoped templates` | editor OrgId assignment (global curators); `print_templates` API merges `OrgId IS NULL OR OrgId IN (caller's orgs)` for authed users | column-existence-gated; if not cheap, ship nothing — the P1 column stays dormant and an issue tracks it |

Every commit: `php -l` + `node --check` sweeps, suite run, annotations to the house standard,
and the standing-tasks checklist (issues per feature-code, CHANGELOG, wiki API/Schema pages,
`ProjectBrief.md`) at the end of the pass.

**Follow-up issues to file (not this branch):** AD booklet imposition (needs FPDI page
re-ordering — low value/high effort); AG fit-to-page (bounded re-render loop, §4.3); full
PDF/UA (engine-dependent — the owner decision's consequence); public end-user PDF download
(behind #1769 `download_pdf`); whole-book batch beyond 150 songs (FPDI chunk-merge);
`getUserEffectiveLicences()` surfacing `LicenceId` (§6.2); L/D branding enforcement hook
consumption (#1769).

---

## 11. Adversarial pass — what would force the things we forbid

**A second migration?**
- *Per-page-role layouts (cover/continuation)* → `Slot` reserved in the UNIQUE key (§9).
- *Sanitiser rule changes needing source re-runs* → `HtmlOriginal` + `SanitiserVersion`
  reserved, dormant.
- *Org anything* → `OrgId` lands now even though its UI may wait (P9).
- *New page options / block options / showIf conditions* → JSON columns, zero DDL (already the
  #1350 property).
- *AK growth (surfaces, licences)* → `MetaJson` + the VARCHAR `UsageContext` vocabulary absorb
  it; `Quantity` already exists.
- *Residual risk accepted:* a per-USER custom layout (not per-template) would need a new table
  — deliberately NOT reserved; nothing in #1767's scope hints at it, and a guessed table is
  the #1010 "guessed bridge view" anti-pattern.

**A second renderer?**
- The standing temptation: "block X looks wrong in mPDF — quick-fix it in PHP". The rule the
  guard enforces: a PDF-path rendering wrong is fixed in the **adapter** (a deterministic
  transform of the client's HTML, §4) or in print.js itself — never by re-emitting a block
  server-side. `test-print-one-renderer.php` makes the shortcut red.
- Headless/cron PDF generation with no browser in the loop would be the one true forcing
  function — and even then the answer is the engine-swap path (a service that RUNS print.js),
  not a PHP port; the client-POSTs-HTML architecture is what keeps that door open.
- `printCss()` port temptation: the endpoint deliberately accepts the client's `css` string
  (filtered) instead of regenerating CSS in PHP — regenerating would be the stylesheet fork.

**A sanitiser bypass?**
- *Post-sanitise substitution reopening XSS* → tokens are `esc()`d; `{{content}}` is
  renderer-built; covered by truth-table vectors.
- *Two divergent allow-lists (upload vs endpoint)* → one module, profiles as data, one guard
  file.
- *Parser differential (DOMDocument vs browser vs mPDF)* → we serialise a REBUILT tree
  (default-deny construction), never patch the original string; unknown nodes cannot ride
  through unparsed.
- *mPDF proprietary-tag injection* (`<barcode>` etc.) → default-deny drops unknown elements;
  only the adapter's own literals may add `<columns>` after sanitisation.
- *Gated-media leak (rule M)* → both img-src allow-lists structurally exclude
  `/song-media/`; CSS `url(` is banned outright; truth-table fixture asserts both.
- *`HtmlOriginal` reaching a sink* → tree-derived guard bans reads outside the
  sanitise/re-sanitise pair.

---

## 12. Invariants held throughout (extends the shipped plan's list)

- `renderTemplateBodyHtml()` stays the ONLY block renderer; the server adds page furniture
  only, and `test-print-one-renderer.php` is the mechanism (rule #35), not this sentence.
- Browser print never regresses and never grows a server dependency: every PDF feature is
  additive beside it, and a 503 engine (un-vendored install) degrades to Print with a toast.
- The PDF endpoint is authenticated, CSRF-checked, rate-limited, capped, and unreachable from
  any public or cacheable path (#94 discipline, guard-asserted).
- Sanitiser is default-deny, versioned, single-module; only its OUTPUT is ever rendered.
- Schema is one additive dormant batch, VARCHAR-not-ENUM, byte-mirrored in schema.sql, one
  registry entry with a multi-object OR-probe.
- AK logs only under a server-re-validated CCLI licence, through ONE writer, into the existing
  dormant table; the copies prompt appears only when it will be logged.
- Branding/white-label stays OUT (decision #3); its future enforcement point is named (§9) so
  #1769 lands with one line here, not a redesign.
