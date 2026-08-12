# Organisation logos (#1830) — implementation plan

**Status:** PLAN ONLY — nothing implemented. Written 2026-08-12 on branch
`claude/issue-sweep-fixes-89` after a full read of the conventions this feature
must honour. One PR, atomic commits (§11).

**The feature:** an organisation uploads/publishes its brand assets — a full
professional taxonomy of logo **kinds** (primary, secondary, mark, wordmark,
lockups, monochrome, reversed, app icon — the final vocabulary is §4.2, held in
ONE central app-validated map) — so Print Templates (#1767) and, later, the app
header / projector / OG images can carry the church's branding. **SVG
(preferred) + PNG/APNG, both from launch** (owner decision locked). SVG is
accepted ONLY through a dedicated hardened sanitiser (§3); logos are **served
as `<img src>` — never inlined** — with `nosniff` + a restrictive CSP (§5); a
mutation-proven CI guard proves a booby-trapped SVG comes back stripped (§9);
and the print `logo` block lets the template author **choose which kind to
render**, with a defined fallback ladder and graceful nothing-not-broken
degradation (§6). Owner additions of 2026-08-12 (richer taxonomy + chooseable
print kind) are folded in throughout.

---

## 1. What the codebase already says about this problem (mandatory-reading findings)

These are the constraints the plan is built on, with the exact evidence:

1. **The existing HTML sanitiser deliberately BLOCKS SVG — twice, with stated
   rationale.** `includes/html_sanitizer.php`:
   - `IHYMNS_HTML_ALWAYS_BANNED_TAGS` lists `'svg'` among "the greatest hits of
     tags a sanitiser must never trust" (L97–101).
   - The `layout` profile's `data_uri_mimes` comment: *"Self-contained only — no
     network fetch, no SVG (SVG is a script vector:
     `<svg><script>…</script></svg>` is valid SVG)"* (L164–167).
   - `ihymnsSanitizeImgSrcAllowed()`'s doc-block: *"Structurally excludes …
     `data:image/svg+xml` (SVG is not in `data_uri_mimes` — SVG can carry its
     own `<script>`, so it is never treated as 'just an image')"* (L558–560).

   **This posture is correct and stays.** The SVG sanitiser in §3 is a NEW,
   separate, STRICTER module (`includes/svg_sanitizer.php`) — we never widen
   `html_sanitizer.php` to admit `<svg>` markup, and no surface ever inlines
   logo SVG into a page. Logos reach pages only as `<img src="/org-logo.php…">`,
   which keeps SVG in an isolated image context where scripts cannot execute
   even in a browser that ignored our sanitising (defence in depth, not the
   defence).

2. **The serving-endpoint shape to mirror is `qr.php` / `og-image.php`**
   (rule #38): a standalone top-level PHP file; `X-Content-Type-Options:
   nosniff` + `Referrer-Policy: no-referrer` sent first; keyed fail-open rate
   limit (`enforceReadRateLimitKeyed('qr', 240)` wrapped in a swallow-all
   `try/catch`); input validate-and-clamp; **absent/unconfigured → status code
   with no body** (qr.php's `_qrFail()` `never` helper) so a consuming `<img>`
   simply fails and the surface degrades; hard caching on success. `song-media.php`
   adds the other two ingredients we need: an INFORMATION_SCHEMA **table-existence
   probe so a pre-migration deploy answers 404 cleanly** (its `$hasSchema`
   closure), and `ETag` (= stored Sha256) / `Last-Modified` conditional-GET.

3. **A print block is registered in TWO lockstepped registries** (rule #39):
   `$BLOCK_SCHEMA` in `includes/print_template_schema.php` (server allow-list +
   option coercion via `ptSanitiseBlocks()`) and `PRINT_BLOCK_TYPES` +
   `renderBlock()` in `js/modules/print.js` (the ONE body renderer), held in
   agreement by `tests/php/test-print-block-registry.php`. The worked example
   for an image-emitting block is `case 'qr'` (print.js L335–359): a **pure
   synchronous `<img>` emit** pointing at the same-origin endpoint, with an
   always-usable degradation when the endpoint 404/503s.

4. **The PDF path cannot fetch URLs.** `includes/pdf_renderer.php`'s
   `_pdfAdaptHtml()` runs a single DOM pass over the (already-sanitised) client
   HTML: a `data:image/…` src passes through untouched; a `/qr.php?…` src is
   resolved **directly** (never by mPDF self-requesting over HTTP — "a needless
   SSRF-shaped surface") via `_pdfInlineQrImage()` into a `data:` URI; **any
   other `<img>` is removed outright**. So the logo block's
   `/org-logo.php?…` src needs (a) admission in the sanitiser profiles'
   `img_src` patterns and (b) its own direct resolver in `pdf_renderer.php`
   (§6.4), or the PDF silently drops every logo.

5. **Uploads precedent** (`includes/SongMediaStorage.php` + `song-media.php`):
   never trust `$_FILES['type']` — `finfo` sniff the actual bytes; per-kind size
   caps; canonical extension re-derived from sniffed MIME; small kinds live in a
   `MEDIUMBLOB` ("small files, atomic backups, transactional gating"), only
   audio goes to the filesystem (range requests); the row's `StorageBackend`
   column wins on read so storage can be rebalanced later without touching
   consumers. Note its `StorageBackend ENUM(...)` is **grandfathered** — ours
   must be VARCHAR (rule #20).

6. **Migrations** (rules #19/#41): ONE entry in
   `manage/includes/migration-registry.php` (script + card + real probe — never
   `=> true`); byte-identical `schema.sql` mirror in the same commit; any shared
   include resolved via `IHYMNS_INCLUDES_DIR` with the `/public_html/` literal
   only as the repo/CLI fallback (the exact idiom in
   `migrate-delete-songs-rewiden.php` L80–84); CI: `test-schema-coverage.php`,
   `test-migration-registry.php`, `test-deploy-paths.php`.

7. **Admin gates already exist** — no new entitlement needed:
   `manage/organisations.php` gates on `userHasEntitlement('manage_organisations')`;
   `manage/my-organisations.php` gates on `manage_own_organisation` + the
   row-level `$canActOnOrg($orgId)` closure (#707: "A forged POST against an org
   the current user doesn't admin returns 403 even if CSRF is valid"). Both
   pages use classic full-page form POSTs with `validateCsrf()` — the logo
   upload joins those forms, so the rule-#29 long-lived-AJAX concern doesn't
   apply (any FUTURE AJAX upload endpoint must use `validateCsrfRequest()`).

8. **The sanitised-vs-original storage pattern** already exists:
   `tblPrintTemplateCustomLayout` stores `HtmlSanitised` (the ONLY render-served
   payload), `HtmlOriginal` (dormant, sole source for re-sanitising after an
   allow-list change), and `SanitiserVersion` (a bump flags rows for
   re-sanitise). §2 mirrors this shape for SVG bytes.

---

## 2. Schema — `tblOrganisationLogos` (one-pass, additive, dormant-friendly)

### 2.1 Design + adversarial stress (rule #20)

One row per `(OrgId, Kind, Variant)`; re-upload replaces the row (upsert).
"What would force a second migration?" — stress answers baked in:

| Foreseeable need | How the DDL absorbs it with NO second migration |
|---|---|
| New logo kind (e.g. `banner`, `seal`) | `Kind` is `VARCHAR(20)` app-validated against the ONE `IHYMNS_ORG_LOGO_KINDS` map (`includes/org_logo_helpers.php`, §4.2) — a new kind is one line in the map; the schema, admin UI, validator, API emit and print block all read that map, so nothing else changes. |
| Light/dark (theme) variants | `Variant VARCHAR(10) DEFAULT 'default'` already in the UNIQUE key. v1 only ever writes `'default'`; `'light'`/`'dark'` are vocabulary additions. |
| Storage rebalance (filesystem / object store) | `StorageBackend VARCHAR(20)` + dormant `StoragePath VARCHAR(255) NULL` (the `tblSongMedia` dual-column shape, minus its grandfathered ENUM). v1 writes only `'database'`. |
| Sanitiser tightening → re-sanitise stored SVGs | `ContentOriginal` (dormant, never served) + `SanitiserVersion` — the `tblPrintTemplateCustomLayout` pattern verbatim. |
| Accessibility label | `AltText VARCHAR(255) NULL` from day one (default rendered alt is "<Org name> logo"). |
| Focal point / padding / background hints for future surfaces (header, projector, OG) | `MetaJson JSON NULL` — a growable bag, per the rule-#28 "growable vocabulary is JSON" discipline. |
| Cache-bust / dedupe / conditional GET | `Sha256 CHAR(64)` computed at upload (the `song-media.php` ETag precedent). |

### 2.2 Final DDL (the `schema.sql` mirror — byte-identical in the migration)

Placed in `schema.sql` directly after the `tblOrganisationLicences` block
(the organisations family, ~L1275):

```sql
-- ----------------------------------------------------------------------------
-- tblOrganisationLogos (#1830) — per-organisation branding images for Print
-- Templates (and later the app header / projector / OG images). One row per
-- (OrgId, Kind, Variant); a re-upload replaces the row. SVG rows store the
-- OUTPUT of includes/svg_sanitizer.php in ContentSanitised — the ONLY bytes
-- any serving path may read — plus the upload as received in ContentOriginal
-- (dormant; sole source for re-sanitising after an allow-list change; never
-- served). PNG/APNG rows store the validated original bytes in
-- ContentSanitised with SanitiserVersion = 0 (not applicable).
-- Mirrors appWeb/.sql/migrate-organisation-logos.php (rule #19).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblOrganisationLogos (
    Id               INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    OrgId            INT UNSIGNED    NOT NULL COMMENT 'FK tblOrganisations.Id — whose branding this is',
    Kind             VARCHAR(20)     NOT NULL COMMENT 'Brand-asset kind vocabulary token -- registry: IHYMNS_ORG_LOGO_KINDS (includes/org_logo_helpers.php, #1830). primary | secondary | emblem | logotype | full | horizontal | stacked | monochrome | reversed | favicon. VARCHAR not ENUM (rule #20) — a new kind is one map line, no ALTER',
    Variant          VARCHAR(10)     NOT NULL DEFAULT 'default' COMMENT 'default | light | dark (reserved multiplicity, rule #20 — v1 only ever writes ''default'')',
    Mime             VARCHAR(127)    NOT NULL COMMENT 'image/svg+xml | image/png — sniffed from the bytes at upload, never taken from the client',
    Width            SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT 'Intrinsic pixel width (PNG) or viewBox-derived width (SVG); NULL when underivable',
    Height           SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT 'Intrinsic pixel height — see Width',
    ByteSize         INT UNSIGNED    NOT NULL COMMENT 'LENGTH(ContentSanitised) at save — cheap cap/audit read without pulling the blob',
    Sha256           CHAR(64)        NOT NULL COMMENT 'sha256 of ContentSanitised — the ETag and the &v= cache-bust token',
    StorageBackend   VARCHAR(20)     NOT NULL DEFAULT 'database' COMMENT 'database | filesystem | object-store (VARCHAR not ENUM, rule #20 — v1 only ever writes ''database''; the row wins on read, SongMediaStorage precedent)',
    ContentSanitised MEDIUMBLOB      NULL COMMENT 'The ONLY serve-readable bytes: svg_sanitizer.php output for SVG, validated original bytes for PNG/APNG',
    ContentOriginal  MEDIUMBLOB      NULL COMMENT 'SVG upload as received (dormant) — sole source for re-sanitising after an allow-list change; guard-banned from serving paths; NULL for raster rows',
    StoragePath      VARCHAR(255)    NULL DEFAULT NULL COMMENT 'Dormant (rule #20) — relative path for a future non-database StorageBackend',
    SanitiserVersion INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'IHYMNS_SVG_SANITISER_VERSION that produced ContentSanitised (0 = raster, not applicable); a bump flags SVG rows for re-sanitise',
    AltText          VARCHAR(255)    NULL DEFAULT NULL COMMENT 'Accessible label for the rendered <img>; NULL = "<Org name> logo"',
    MetaJson         JSON            NULL DEFAULT NULL COMMENT 'Dormant grab-bag for future surface hints (focal point, background, padding) — growable vocabulary is JSON, never new columns (rule #20/#28)',
    IsActive         TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '0 = hidden from every surface without deleting the upload',
    UploadedBy       INT UNSIGNED    NULL DEFAULT NULL COMMENT 'FK tblUsers.Id — who uploaded it',
    CreatedAt        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_OrgKindVariant (OrgId, Kind, Variant),
    INDEX idx_UploadedBy (UploadedBy),

    CONSTRAINT fk_OrgLogo_Org
        FOREIGN KEY (OrgId) REFERENCES tblOrganisations(Id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_OrgLogo_User
        FOREIGN KEY (UploadedBy) REFERENCES tblUsers(Id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Notes: `DATETIME` not `TIMESTAMP` for Created/Updated (the newer-table
convention, cf. `tblPrintTemplates`); `MEDIUMBLOB` caps a row at ~16 MB, far
above the §4 upload caps (512 KiB SVG / 2 MiB PNG).

### 2.3 Migration file — `appWeb/.sql/migrate-organisation-logos.php` (skeleton)

```php
<?php
declare(strict_types=1);
/**
 * iHymns — Organisation logos (#1830)
 * Creates tblOrganisationLogos (see the file's full doc-block: what, why,
 * ELI5 + detail registers, links). Idempotent — CREATE TABLE IF NOT EXISTS,
 * safe to re-run. DDL byte-identical to schema.sql (rule #19).
 */

/* Rule #41 — the deployed docroot is renamed per channel; resolve includes/
   via the runner-provided IHYMNS_INCLUDES_DIR, with the /public_html/ literal
   only as the standalone/CLI repo fallback. */
$_incDir = defined('IHYMNS_INCLUDES_DIR')
    ? IHYMNS_INCLUDES_DIR
    : dirname(__DIR__) . '/public_html/includes';
require_once $_incDir . '/db_mysql.php';

$db = getDbMysqli();
$db->query(<<<'SQL'
CREATE TABLE IF NOT EXISTS tblOrganisationLogos ( … byte-identical to §2.2 … )
SQL);
/* progress line per house style (_mig*_out() cli/html dual emitter) */
```

No data backfill (there is no existing logo data anywhere), so the migration is
a single idempotent CREATE.

### 2.4 The ONE registry entry — `manage/includes/migration-registry.php`

Appended at the end of the returned array (order = deployment order; no
dependencies beyond `tblOrganisations`, which every install already has):

```php
'organisation-logos' => [
    'script' => 'migrate-organisation-logos.php',
    'card' => [
        'title'  => 'Organisation Logos (#1830)',
        'body'   => 'Creates <code>tblOrganisationLogos</code> so a church can'
                  . ' upload its logo (mark, wordmark, combined) for printed'
                  . ' song sheets and future screens. Idempotent — safe to re-run.',
        'button' => 'Run Organisation Logos Migration',
    ],
    'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblOrganisationLogos'),
],
```

A single-object migration → a single-object existence probe (real, never
`=> true`); `$scriptMap`/`$migrationOrder`/`$migrationCards`/`$migrationProbes`
all derive from this one entry.

---

## 3. The SVG sanitiser — `includes/svg_sanitizer.php` (the crux)

A NEW module, separate from and stricter than `html_sanitizer.php`. Same
philosophy (default-deny, **rebuild-not-strip**: parse into a DOM, then build a
brand-new document containing only nodes this file explicitly created — the
html_sanitizer doc-block's "a default-deny tree rebuild structurally cannot let
an unrecognised node ride through" rationale), different mechanics (XML parse,
namespace-aware, **reject-not-degrade** on anything unparseable).

### 3.1 Public contract

```php
const IHYMNS_SVG_SANITISER_VERSION = 1;   // bumped on ANY rule change; stored per row (SanitiserVersion)

/**
 * @param  string $bytes  Untrusted SVG upload, as received.
 * @return array{bytes:string, width:?int, height:?int}|null
 *         Sanitised standalone SVG document bytes (+ intrinsic dimensions from
 *         viewBox or width/height), or NULL to REJECT. There is no partial
 *         success and no exception path: null on unparseable XML, wrong root,
 *         DOCTYPE/ENTITY present, over the node/depth budget, or any internal
 *         error. Disallowed-but-parseable content (a <script> child, an
 *         onload=) is STRIPPED, not a rejection — the caller decides whether
 *         "survived but altered" is worth warning the uploader about (§4 does).
 */
function ihymnsSanitizeSvg(string $bytes): ?array
```

### 3.2 Parse mechanism (PHP specifics)

1. **Byte pre-checks (reject):** empty, > cap (§4), or containing
   `<!DOCTYPE` / `<!ENTITY` case-insensitively anywhere — a logo never
   legitimately carries an internal DTD subset, and rejecting the SHAPE
   outright (the `marcxml.php` "refuse the shape" posture) removes the whole
   XXE / billion-laughs class before libxml ever sees it.
2. **Entity loader nulled:** `libxml_set_external_entity_loader(static fn() => null)`
   around the parse (restored after) — belt-and-braces on top of check 1;
   PHP ≥ 8.0 disables external entity loading by default
   (`libxml_disable_entity_loader()` is deprecated), this makes it explicit
   and local.
3. **Parse:** `DOMDocument::loadXML($bytes, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)`
   — `loadXML`, not `loadHTML` (SVG is XML; we need namespace fidelity), NONET
   (never fetch anything), and **never `LIBXML_NOENT`** (never substitute
   entities — the same posture `html_sanitizer.php` mirrors from `marcxml.php`).
   Never `LIBXML_PARSEHUGE` (keep libxml's own depth/size guards).
4. **Post-parse rejects:** parse failed; `$doc->doctype !== null` (a DTD
   survived); root element is not `svg` in the SVG namespace
   (`http://www.w3.org/2000/svg`); node count > 10 000 or element depth > 64
   (decompression/render-bomb budget — a real logo is hundreds of nodes).
5. **Rebuild:** recursive default-deny walk (the `ihymnsSanitizeWalkNode()`
   shape) into a fresh `DOMDocument`. Unlike the HTML sanitiser, a disallowed
   SVG element is **DROPPED whole, never unwrapped** — unwrapping `<script>`'s
   text into an SVG rendering context is meaningless at best; nothing outside
   `<text>`/`<tspan>` renders as text in SVG, but dropping is the posture that
   needs no argument. Comments, PIs, CDATA → dropped.
6. **Serialise:** `saveXML()` of the rebuilt root, emitted as a standalone
   document with the `xmlns` (and nothing else) re-stamped on the root.
   Dimensions recorded from `viewBox` (preferred) or `width`/`height`.

### 3.3 Element allowlist (SVG namespace only — any foreign-namespace element or attribute is dropped)

```
svg, g, defs, title, desc,
path, rect, circle, ellipse, line, polyline, polygon,
text, tspan,
linearGradient, radialGradient, stop,
clipPath, symbol
```

Deliberately EXCLUDED (each is a named threat in §3.5): `script`, `style`,
`foreignObject`, `use`, `image`, `a`, `animate`/`set`/`animateMotion`/
`animateTransform` (all SMIL), every `fe*` filter primitive + `filter`,
`marker`, `mask`, `pattern`, `switch`, `view`, `metadata`, `font`/glyph
elements. Losses this implies for real-world logos (masks, patterns, filters,
`<style>`-block class styling from Illustrator exports) are accepted v1
degradations — see §12 decision (c). Every exclusion is re-admittable later by
widening the allowlist + bumping `IHYMNS_SVG_SANITISER_VERSION` (which flags
stored rows), never by a schema change.

### 3.4 Attribute allowlist

Unconditional bans checked FIRST, before any allowlist (the
`ihymnsSanitizeCopyAttributes()` ordering): any attribute whose local name
starts with `on`; `href` and `xlink:href` (no allow-listed element needs
either); any attribute in a non-null, non-SVG namespace (kills
`xlink:*`, `xml:base`, editor junk like `inkscape:*`/`sodipodi:*` wholesale —
`xml:space` is the one namespaced attribute admitted).

Allowed, with per-attribute value validation (regex-validated; an invalid
value drops the attribute, never the element):

- **Structure:** `viewBox` (4 numbers), `width`/`height` (number + optional
  `px`/`%`/`em`), `x`, `y`, `x1`, `y1`, `x2`, `y2`, `cx`, `cy`, `r`, `rx`,
  `ry`, `dx`, `dy`, `points` (numbers/whitespace/commas/signs/dots only),
  `d` (path-data charset: `[A-Za-z0-9 ,.eE+-]` only), `transform`
  (`matrix|translate|scale|rotate|skewX|skewY` with numeric args only),
  `preserveAspectRatio` (enum shape), `version`.
- **Identity for same-document refs:** `id` restricted to
  `[A-Za-z][A-Za-z0-9_-]{0,63}`. (The HTML sanitiser bans `id` to stop DOM
  clobbering of the HOST page; an SVG served as an isolated image has no host
  page, and gradients/clips are unusable without `fill="url(#…)"`.)
- **Paint & presentation:** `fill`, `stroke`, `stop-color`, `color`, whose
  value must match EXACTLY one of: `none` | `currentColor` | `transparent` |
  `#rgb/#rrggbb/#rrggbbaa` | `rgb()/rgba()/hsl()/hsla()` with numeric args |
  a CSS named colour (`[a-zA-Z]{3,20}`) | **`url(#ID)`** where ID matches the
  `id` charset — the ONLY `url()` form that survives anywhere, and it can only
  point inside the same sanitised document. Plus the numeric/enum presentation
  set: `fill-opacity`, `stroke-opacity`, `opacity`, `stop-opacity`, `offset`,
  `stroke-width`, `stroke-linecap`, `stroke-linejoin`, `stroke-miterlimit`,
  `stroke-dasharray`, `stroke-dashoffset`, `fill-rule`, `clip-rule`,
  `clip-path` (only `url(#ID)`), `gradientUnits`, `gradientTransform`
  (same grammar as `transform`), `spreadMethod`, `font-family` (charset-limited,
  no quotes needed for the common case), `font-size`, `font-weight`,
  `font-style`, `text-anchor`, `letter-spacing`, `xml:space`.
- **`style` attribute:** filtered declaration-by-declaration through a NEW
  SVG-specific wrapper that reuses `html_sanitizer.php`'s
  `ihymnsCssSplitDeclarations()` + the `ihymnsCssValueSafe()` banned-substring
  core (`url(`, `expression(`, `@import`, `@charset`, any backslash, `</`) but
  with the SVG property list above and the same paint-value validator — so
  `style="fill:url(#g1)"` survives via the identical `url(#ID)` carve-out and
  nothing else with `url(` does. ONE declaration filter core, never a fork
  (rule #35) — the reuse is of the exported tokenizer/value-safety helpers,
  not a copy.

### 3.5 Threat table (what the allowlist structurally blocks)

| Threat | Attack shape | Blocked by |
|---|---|---|
| Script execution | `<script>`, `<svg onload=…>`, `on*` on any element | `script` not in the element allowlist (dropped whole, §3.2.5); `on*` unconditionally banned before the attribute allowlist is consulted |
| `javascript:`/`data:` URI execution | `<a href="javascript:…">`, `<use href="data:…">` | `a`/`use` not in the element allowlist; `href`/`xlink:href` unconditionally banned on every element; no attribute's value validator admits a URI scheme |
| Arbitrary HTML injection | `<foreignObject><iframe>…` | `foreignObject` not in the element allowlist |
| External fetch / tracking pixel / SSRF | `<image href="http://…">`, `<use href="https://…#x">`, `<feImage>`, `fill="url(http://…)"`, `style="…url(…)"`, `@import` | `image`/`use`/`fe*` banned; the ONLY surviving `url()` form anywhere (attribute or style) is same-document `url(#ID)`; `ihymnsCssValueSafe()` bans `url(`/`@import` in style values; `LIBXML_NONET` at parse |
| XXE / entity expansion (billion laughs) | `<!DOCTYPE svg [<!ENTITY …>]>` | pre-parse byte reject of `<!DOCTYPE`/`<!ENTITY`; entity loader nulled; no `LIBXML_NOENT`; post-parse `$doc->doctype !== null` reject |
| CSS attacks | `expression(…)`, escaped smuggling (`\75 rl(`) | `ihymnsCssValueSafe()` core: `expression(` banned, ANY backslash banned (sidesteps decoding escapes), whitespace-collapsed ban scan |
| SMIL retargeting | `<set attributeName="href" to="javascript:…">` | all SMIL animation elements banned (and every href sink is banned anyway) |
| Recursion / render bombs | nested `<use>` bombs, 100k-node docs, 1000-deep trees | `use` banned; node-count (10 000) + depth (64) + byte-size budgets, over-budget = reject |
| MIME confusion on serve | polyglot file sniffed as HTML | upload-side `finfo` sniff + this sanitiser's XML/root checks; serve-side `nosniff` + exact `Content-Type` + CSP + `Content-Disposition` (§5) |
| Host-page interference | `id` clobbering, CSS leaking out | logos are NEVER inlined (CI-guarded, §9) — an `<img>` is an isolated context; `id` is admitted only inside that isolation |

### 3.6 What it never does

No best-effort repair of unparseable input (reject); no regex-based stripping
of the original string (rebuild only); no network access; no exceptions across
the public boundary (null covers every failure); never serves — it only
transforms bytes for §4 to store.

---

## 4. Upload / validation path — the ONE shared core (helpers + admin, the publisher split)

Two files, mirroring the rule-#37 publisher precedent exactly
(`publisher_helpers.php` = vocabularies + pure helpers + reads;
`publisher_admin.php` = the write core). Both are side-effect-free to require.
Both admin pages (§7), the serving endpoint (§5), the print schema (§6.1), the
PDF resolver (§6.4) and any future `admin_org_logo_*` / `org_admin_logo_*` API
delegate here — never a second copy of validate/store/read/resolve.

### 4.1 `includes/org_logo_helpers.php` (vocabularies + reads + kind resolution)

```php
/* THE ONE kind registry (owner requirement 2026-08-12) — see §4.2 for the
   vocabulary. Shape: key => [plain label, one-line description]. Schema, admin
   UI, validator, API emit, print block option and fallback ladder ALL read
   this map; a new kind is ONE line here, no migration (VARCHAR, rule #20). */
const IHYMNS_ORG_LOGO_KINDS = [ /* §4.2 table, in ladder order */ ];

/* The kind-resolution ladder = the MAP'S OWN KEY ORDER (one source of truth —
   no second ordered list to drift). 'auto' resolution walks it first-hit. */
function ihymnsOrgLogoKindKeys(): array          // array_keys(IHYMNS_ORG_LOGO_KINDS)
function ihymnsOrgLogoResolveKind(string $requested, array $availableKinds): ?string
    // 'auto'  → first ladder kind present in $availableKinds, else null
    // explicit → $requested if present in $availableKinds, else null
    // null   → the caller renders NOTHING (never a broken image, §6.3)

const IHYMNS_ORG_LOGO_VARIANTS = ['default', 'light', 'dark'];   // v1 UI only writes 'default'
const IHYMNS_ORG_LOGO_MAX_SVG_BYTES  = 524288;    // 512 KiB
const IHYMNS_ORG_LOGO_MAX_PNG_BYTES  = 2097152;   // 2 MiB
const IHYMNS_ORG_LOGO_MAX_DIMENSION  = 4096;      // px, either axis (render-bomb cap)

orgLogoTableExists(\mysqli $db): bool   // INFORMATION_SCHEMA probe, memoized — the dormancy gate every consumer checks first
orgLogoFetchServeRow(\mysqli $db, int $orgId, string $kind, string $variant): ?array
    // active row; requested variant falling back to 'default';
    // SELECTs ContentSanitised — NEVER ContentOriginal
orgLogoListForOrg(\mysqli $db, int $orgId): array   // per-kind/variant meta (no blobs) for the admin UI + API emit
```

### 4.2 The kind vocabulary (owner requirement: real brand coverage)

Final v1 set — 10 kinds, in **ladder order** (which doubles as the `'auto'`
fallback order and the admin-card display order). Labels/descriptions are the
plain-English strings the UI renders (`.claude/admin-plain-english.md` — no
jargon beyond what a brand guide itself uses):

| Key | Label | One-line description |
|---|---|---|
| `primary` | Primary logo | "Your main logo — used by default wherever one logo is needed." |
| `full` | Combined logo | "Symbol and name together in your standard arrangement." |
| `horizontal` | Wide layout | "Symbol beside the name — for wide, short spaces like page headers." |
| `stacked` | Stacked layout | "Symbol above the name — for square or tall spaces." |
| `emblem` | Symbol only | "Just the emblem or icon, no words — for tight corners and small spaces." |
| `logotype` | Name only | "Your organisation's name in its typeface, without the symbol." |
| `secondary` | Alternative logo | "A different logo for settings where the primary doesn't fit or suit." |
| `monochrome` | Single-colour | "A one-colour (usually black) version for plain printing." |
| `reversed` | Light-on-dark | "A white or light version for dark backgrounds." |
| `favicon` | App icon | "A small square icon for browser tabs and app tiles." |

Notes: the ladder order is deliberate — `primary → full → horizontal → stacked
→ emblem → logotype → …` means an `'auto'` block always prefers the most
complete asset the org has actually uploaded (the owner's "'primary' → fall
back to 'full' → first available" made concrete). `monochrome`/`reversed` are
distinct brand ASSETS (per standard brand-guideline practice), while the
dormant `Variant` column remains the per-kind theme axis (a light and a dark
rendition of the SAME asset) — the overlap is documented in the helpers
doc-block so a future light/dark surface picks Variant, not a kind (flagged
§12(e) as a defensible default). All keys fit `VARCHAR(20)`. Because this is
a VARCHAR + one central map, curators get further kinds later with a one-line
code change and no migration.

### 4.3 `includes/org_logo_admin.php` (the write core)

```php
orgLogoValidateAndStage(string $tmpPath, int $sizeBytes): array   // throws \RuntimeException with a PLAIN-ENGLISH message on any failure
orgLogoUpsert(\mysqli $db, int $orgId, string $kind, string $variant, array $staged, ?string $altText, ?int $userId): int
    // $kind validated against ihymnsOrgLogoKindKeys(), $variant against
    // IHYMNS_ORG_LOGO_VARIANTS, BEFORE any SQL; upsert on uq_OrgKindVariant —
    // an org holds at most ONE logo per (kind, variant), re-upload replaces
orgLogoDelete(\mysqli $db, int $orgId, string $kind, string $variant): bool
orgLogoSetActive(\mysqli $db, int $orgId, string $kind, string $variant, bool $active): bool
```

### 4.4 `orgLogoValidateAndStage()` pipeline

1. `$sizeBytes > 0` and `<=` the LARGER cap (pre-sniff coarse gate);
   `is_readable`.
2. `finfo` MIME sniff on the actual bytes (`SongMediaStorage::validateUpload()`
   precedent — never `$_FILES['type']`, never the filename suffix).
3. Branch on sniffed MIME:
   - **`image/png`** (covers APNG — an APNG IS a PNG container; `finfo`
     reports `image/png`): cap `IHYMNS_ORG_LOGO_MAX_PNG_BYTES`; assert the
     8-byte magic `\x89PNG\r\n\x1a\n` explicitly (defence in depth over
     finfo); `getimagesize()` must succeed and both axes must be
     `<= IHYMNS_ORG_LOGO_MAX_DIMENSION`; stored bytes = the original upload
     unchanged (**no re-encode** — re-encoding would strip APNG animation
     frames); `SanitiserVersion = 0`, `ContentOriginal = NULL`.
   - **`image/svg+xml`** (finfo may also say `text/xml`/`text/plain` for
     lean SVGs — accept those three sniffs ONLY IF `ihymnsSanitizeSvg()`
     then succeeds, since the sanitiser's own root-element check is the real
     arbiter of "is this SVG"): cap `IHYMNS_ORG_LOGO_MAX_SVG_BYTES`; run
     `ihymnsSanitizeSvg()`; **null → reject** with plain-English copy ("That
     file couldn't be read as a safe logo. Please export a plain SVG without
     scripts or embedded images and try again."); stored bytes = the
     sanitiser OUTPUT; `ContentOriginal` = the upload as received;
     `SanitiserVersion = IHYMNS_SVG_SANITISER_VERSION`; canonical Mime =
     `image/svg+xml`.
   - anything else → reject ("Logos must be SVG or PNG files.").
4. Compute `Sha256` + `ByteSize` over the stored bytes; return the staged
   array for `orgLogoUpsert()` (one prepared INSERT … ON DUPLICATE KEY UPDATE,
   every value bound — checkpoint #5).

### 4.5 Where uploads POST

Both admin pages' existing full-page form-POST handlers gain `logo_upload` /
`logo_remove` / `logo_toggle` actions (multipart form, `validateCsrf()` first
like every sibling action, `$_FILES['logo_file']` + `UPLOAD_ERR_OK` check,
then delegate to the core). Activity log rows follow each page's existing
key family: `org.logo_upload`/`org.logo_remove` (organisations.php) and
`org_admin.logo_upload`/`org_admin.logo_remove` (my-organisations.php), with
`['kind' => …, 'mime' => …, 'bytes' => …]` detail. PHP `upload_max_filesize`/
`post_max_size` must comfortably exceed 2 MiB on all three channels (verify at
deploy; the handler maps `UPLOAD_ERR_INI_SIZE` to the same plain-English
too-large message).

No new API endpoints in this PR (the pages are form-driven, like the rest of
both files); when the native apps need one, `org_admin_logo_upload` delegates
to the same core (rule #22) and uses `validateCsrfRequest()` (rule #29).

---

## 5. Serving — `org-logo.php` (mirrors `qr.php` / `og-image.php` / `song-media.php`)

New top-level `appWeb/public_html/org-logo.php`. No `.htaccess` change needed
(top-level `.php` files are directly reachable, as `qr.php` is).

**Request:** `GET /org-logo.php?org=<int>&kind=<one of the §4.2 registry keys>[&variant=<default|light|dark>][&v=<hex>]`

**Behaviour, in order:**

1. `header('X-Content-Type-Options: nosniff')` + `header('Referrer-Policy: no-referrer')`
   first (the qr.php ordering — set before any exit path).
2. `_orgLogoFail(int $status): never` helper — status + **no body** (the
   consuming `<img>` treats any non-image as a load failure; the surface
   degrades to nothing/text, the qr.php principle).
3. Rate limit: `enforceReadRateLimitKeyed('org-logo', 240)` in a swallow-all
   `try/catch` (fail-open — the limiter must never break the endpoint).
4. Validate + clamp: `org` positive int; `kind` in `ihymnsOrgLogoKindKeys()`
   (the ONE map, §4.2); `variant` in `IHYMNS_ORG_LOGO_VARIANTS` (default
   `'default'`); anything else → 400.
5. Dormancy: `orgLogoTableExists()` false (pre-migration deploy) → **404**
   (the song-media.php schema-probe posture; 404 not 503 because "no such
   logo" and "table not there yet" must be indistinguishable to a probe).
   DB unreachable → 503.
6. `orgLogoFetchServeRow()` — active row, requested variant → `'default'`
   fallback; none → **404, no body, no placeholder** (a placeholder image
   would render a broken grey box into printed handouts; absence must render
   as absence).
7. Conditional GET: `ETag: "<Sha256>"` always queued; `If-None-Match` hit →
   304, no body (song-media.php pattern — and only ever AFTER the row lookup,
   which for a public logo carries no gating concern).
8. Success headers:
   - `Content-Type:` the row's `Mime` (only ever `image/svg+xml` or
     `image/png` — enforced at upload).
   - `X-Content-Type-Options: nosniff` (already sent).
   - `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox`
     — belt-and-braces for a DIRECT navigation to the file: even if a hostile
     SVG somehow reached storage, a document-context load could run no
     script, fetch nothing, and sits in an origin-less sandbox. (`style-src
     'unsafe-inline'` keeps the logo's own `style=` attributes rendering.
     This is the industry-standard user-SVG serving header set.)
   - `Content-Disposition: inline; filename="org-<id>-<kind>.<svg|png>"`.
   - `Cache-Control: public, max-age=31536000, immutable` **only when** the
     request's `v` equals the row's `Sha256` (or a documented prefix of it);
     otherwise `public, max-age=3600`. A logo is mutable (unlike a QR for a
     fixed payload), so blanket-immutable would strand stale logos for a
     year; the `&v=` token every emitting surface appends (§6/§7) restores
     hard caching safely.
   - `Content-Length`.
9. `echo` the `ContentSanitised` bytes. **Never `ContentOriginal`** — the CI
   guard (§9) bans any serving-path read of that column, mirroring the
   `HtmlOriginal` "guard-banned from render paths" rule.

**Access:** anonymous, like `og-image.php`/`qr.php`. A logo is public branding
that ships on printed handouts; there is nothing to gate (flagged §12(d)).

---

## 6. Print integration — the `logo` block

### 6.1 Server registry — `includes/print_template_schema.php`

The block's `kind` option is **chooseable by the template author** (owner
requirement 2026-08-12) and validated against the SAME central map — the
schema file `require_once`s `includes/org_logo_helpers.php` (side-effect-free,
per its §4.1 contract) rather than typing a second kind list:

```php
require_once __DIR__ . '/org_logo_helpers.php';   // the ONE kind registry (#1830)

$BLOCK_SCHEMA['logo'] = ['kind' => 'logokind', 'size' => 'size', 'align' => 'align'];  // #1830
```

plus one coercion case in `ptSanitiseBlocks()`:

```php
case 'logokind':
    /* 'auto' (the default) = resolve via the ladder at render time (§6.3);
       an explicit kind must be in the ONE registry or it coerces to 'auto' —
       never persisted unvalidated (a crafted POST can't smuggle a kind). */
    $row[$key] = ($v === 'auto' || in_array($v, ihymnsOrgLogoKindKeys(), true)) ? $v : 'auto';
    break;
```

(`size` reuses the existing `sm|md|lg` coercion; `align` reuses
`left|center|right`. The showIf vocabulary gains nothing — the block already
self-suppresses when no logo resolves, §6.3.)

### 6.2 Client registry — `js/modules/print.js`

```js
logo: { label: 'Organisation logo', options: { kind: 'auto', size: 'md', align: 'center' } },  /* #1830 */
```

The editor's per-block option panel renders `kind` as a select whose choices
are "Automatic (best available)" + the kind labels — sourced from a client
mirror `ORG_LOGO_KINDS` (key → label, in ladder order) declared beside
`PRINT_BLOCK_TYPES`. `tests/php/test-print-block-registry.php` parses both
block registries and will fail until they agree, and the §9 surfaces guard
additionally parses `IHYMNS_ORG_LOGO_KINDS` (PHP) against `ORG_LOGO_KINDS`
(JS) and asserts identical keys IN IDENTICAL ORDER — the ladder is the key
order, so order drift is ladder drift (rule #35: a mechanism, not a comment).

### 6.3 Rendering — how the block learns WHICH org, and what it emits

`renderBlock(song, block)` is synchronous and pure, so the org context is
resolved BEFORE render and stashed on the print context, exactly as
`song.scriptureRefs`/`song.tune` already arrive via `?include=`:

- The `my_organisations` API payload (api.php ~L8124, authenticated) gains an
  additive, `orgLogoTableExists()`-gated field per org:
  `logos: [{kind, variant, v, alt, width, height}]` from
  `orgLogoListForOrg()` (meta only, no bytes).
- The print dialog's existing async fetch phase additionally loads
  `my_organisations` (session-cached) and stashes
  `song._printOrgLogos = { orgId, name, byKind: {…} }` for the user's first
  org that has any active logo (template-org override: when the template row
  carries `OrgId` — the dormant #1767 K column — that org wins). Anonymous
  user / no org / no logos / fetch failure → the stash is absent.
- **Kind resolution** (`case 'logo'` in `renderBlock()`), the owner's ladder
  made concrete — the JS twin of `ihymnsOrgLogoResolveKind()` (§4.1), driven
  by the SAME ordered kind list (§6.2's lockstep guard):
  1. `block.kind === 'auto'` (the default) → walk the ladder
     (`primary → full → horizontal → stacked → emblem → logotype →
     secondary → monochrome → reversed → favicon`) and take the FIRST kind
     the org has an active logo for.
  2. explicit `block.kind` → that kind if the org has it, else **nothing**
     (the author asked for something specific; substituting a different
     asset behind their back is worse than absence).
  3. nothing resolved / absent stash → `return ''` (the subtitle/copyright
     "renders nothing gracefully" pattern — a shared template prints cleanly
     for a user with no org, and there is NEVER a broken-image glyph or
     placeholder box on a handout).
- Emission, mirroring `case 'qr'` — the resolved org + kind are baked into
  the same-origin `<img src>` (which is exactly what the server-side PDF
  pass then resolves, §6.4):

```js
const px  = block.size === 'lg' ? 220 : block.size === 'sm' ? 90 : 150;   // height budget
const src = origin + '/org-logo.php?org=' + orgId + '&kind=' + encodeURIComponent(kind)
          + '&v=' + encodeURIComponent(meta.v);
return `<div class="print-logo" style="text-align:${align}">`
     + `<img class="print-logo-img" src="${esc(src)}" alt="${esc(meta.alt || name + ' logo')}"`
     + ` style="max-height:${px}px;max-width:100%"`
     + ` onerror="this.style.display='none'"></div>`;
```

`print-logo`/`print-logo-img` fit the `print` profile's existing
`/^(?:print|lyric)-[a-z0-9…]/` class pattern. The `onerror` degradation
matches the QR block's (harmless under a CSP that blocks the handler). The
admin editor's live preview runs this same `renderBlock()` (one-renderer
invariant) with a sample stash, so the author sees the ladder behave.

### 6.4 Sanitiser + PDF plumbing (without which the block silently dies)

- **`includes/html_sanitizer.php`:** add `'#^/org-logo\.php\?#'` to BOTH
  profiles' `img_src.patterns` (beside `'#^/qr\.php\?#'`). Same structural
  argument as the QR pattern: a same-origin, self-validating endpoint that can
  never match `/song-media/<id>` (rule M intact) and admits no scheme. Bump
  `IHYMNS_HTML_SANITISER_VERSION` 1 → 2 per its own contract ("bumped on ANY
  rule change") — a pure widening, so existing stored layouts need no urgent
  re-sanitise, but the version must tell the truth. Also allow `style` on
  `img` in the `print` profile? **No** — instead the emitted
  `max-height`/`max-width` styles ride the existing `'*' => ['style']`
  allowance, and `max-` is already in `IHYMNS_CSS_ALLOWED_PREFIXES`. No CSS
  allow-list change needed.
- **`includes/pdf_renderer.php`:** in `_pdfAdaptHtml()`'s img pass, before the
  QR resolver: `/org-logo.php?…` srcs go to a new `_pdfInlineOrgLogo(string $src): ?string`
  that parses `org`/`kind`/`variant` from the query, validates `kind` against
  `ihymnsOrgLogoKindKeys()` and `variant` against `IHYMNS_ORG_LOGO_VARIANTS`
  (the ONE map — a crafted POST can't probe arbitrary column values), calls
  `orgLogoFetchServeRow()` **directly** (same "never self-request over HTTP"
  doctrine as `_pdfInlineQrImage()` — no SSRF-shaped hop), and returns
  `data:<mime>;base64,…` or null (→ the `<img>` is dropped, absence renders
  as absence — never a broken image in a PDF). The KIND CHOICE itself needs no
  server logic here: the client's ladder resolution (§6.3) already baked the
  chosen kind into the src before the HTML was POSTed. No gating question arises: the bytes are public (§5). mPDF
  renders both `data:image/png` and `data:image/svg+xml` URIs; the alpha
  deploy-verify pass must include one SVG and one PNG logo in a real PDF
  (mPDF's SVG subset is imperfect — if a specific logo renders poorly, the
  org uploads the PNG variant; that degradation is per-logo, not structural).
- The batch set-list PDF flows through the same renderer + adapt pass —
  covered for free (rule #39's one-renderer invariant).

---

## 7. Admin UI

### 7.1 Placement

- **`manage/organisations.php`** (system admins, `manage_organisations` gate
  unchanged): inside each org's edit view, a new "Logos" card iterating the
  §4.2 kind registry. Section renders only when `orgLogoTableExists()`
  (dormant on an un-migrated install — the `placeColumnExists()` posture this
  page already uses for optional schema).
- **`manage/my-organisations.php`** (org admins, `manage_own_organisation` +
  `$canActOnOrg()` row gate unchanged): the same card per administered org.
  The card itself is a shared partial or a shared render helper in
  `includes/org_logo_helpers.php` — ONE markup source for both pages
  (modularity rule), the way `organisation_validation.php` already serves
  both.

### 7.2 Card contents (one row per kind, iterated from the ONE map)

- The card **iterates `IHYMNS_ORG_LOGO_KINDS`** (§4.2) in map order — never a
  typed kind list in the page. Each row shows the kind's plain label + its
  one-line description straight from the map. Ten kinds is a lot of vertical
  space, so rows WITHOUT an upload render collapsed (label + "Add" button);
  rows WITH one expand to the preview + controls. An org that only ever
  uploads a primary logo sees one expanded row and a tidy "add more shapes"
  list — not ten empty upload forms.
- Current-logo preview as `<img src="/org-logo.php?org=…&kind=…&v=…">` —
  **the preview obeys the never-inline rule too** (it goes through the same
  endpoint, same headers; no `<svg>` markup, no data-URI from DB bytes ever
  printed into admin HTML).
- Per row: `<input type="file" name="logo_file" accept=".svg,.png,image/svg+xml,image/png">`
  + optional alt-text input + Upload button (multipart POST, `logo_upload`
  with the row's `kind`), Remove button (`logo_remove`, confirm), active
  toggle (`logo_toggle`). The posted `kind` is validated server-side against
  `ihymnsOrgLogoKindKeys()` before anything else (§4.3).
- Card intro + helper copy per `.claude/admin-plain-english.md` (plain
  English, minimal disclosure — no table/endpoint/sanitiser talk); the
  per-kind labels/descriptions are the §4.2 table verbatim:

  > **Organisation logos** — "Upload your organisation's logo so printed song
  > sheets can carry your branding. You can add several shapes — a main logo,
  > wide and stacked layouts, a symbol on its own, single-colour and
  > light-on-dark versions — and printouts will use the best one available."
  > - Upload hint: "SVG files look sharpest in print; PNG also works. For your
  >   safety we tidy SVG files on upload, so decorative effects like animation
  >   or embedded pictures won't be kept."
  > - Reject message (sanitiser null): "That file couldn't be read as a safe
  >   logo. Please export a plain SVG without scripts or embedded images and
  >   try again."

### 7.3 Gate/nav parity

No new nav entries, no new entitlements — both pages' existing `admin-links.php`
entries and gates stay identical (avoids the rule "an admin page whose own gate
differs from the entitlement its nav entry advertises").

---

## 8. Dormancy / fail-open matrix (rule #28 shape)

| Install state | Behaviour |
|---|---|
| Migration not run | Admin "Logos" card hidden (`orgLogoTableExists()` false); `org-logo.php` → 404 no body; `my_organisations` omits `logos`; the print block finds no stash → renders `''`; PDF resolver finds no row → drops the `<img>`. Zero errors anywhere. |
| Migration run, no logos uploaded | Identical user-visible behaviour, minus the hidden admin card (now shown, empty). |
| Logo exists, endpoint down/DB blip | `<img>` load fails → browser print shows nothing at that spot (`onerror` hides the glyph); PDF drops the node. Never an error page. |
| Un-keyed/unrelated features | No interaction with CueRCode, content gating, or licences. `content_gating_enabled` is irrelevant — logos are org-published public branding, not gated content. |

---

## 9. CI guards (mutation-proven, tree-derived — rule #34)

1. **`tests/php/test-svg-sanitizer.php`** — functional truth table over
   `ihymnsSanitizeSvg()` (the `test-html-sanitizer.php` sibling):
   - **Booby-trapped inputs come back stripped:** `<script>` child;
     `onload`/`onclick`/`onerror` on `svg`/`path`/`g`; `<foreignObject>` with
     an `<iframe>`; `<use xlink:href="#x">` and `<use href="https://evil">`;
     `<image href="http://evil/p.png">`; `<a href="javascript:alert(1)">`;
     `<animate attributeName="href">`; `style="background:url(http://evil)"`;
     `fill="url(http://evil)"`; a `\75 rl(` escape; `<style>` block —
     each asserted ABSENT from the output bytes (no `script`, no `on`-attr,
     no `href`, no external `url(`), while sibling legitimate shapes survive.
   - **Rejects:** `<!DOCTYPE svg [<!ENTITY x "y">]>`; non-SVG root; truncated
     XML; over-budget node count; oversize bytes — each returns null.
   - **Survivors:** a realistic gradient logo (paths + linearGradient +
     `fill="url(#g1)"` + transform) round-trips with its gradient intact;
     dimensions extracted from viewBox.
   - **Mutation proof documented in the doc-block:** comment out the `on*`
     ban → red; drop `foreignObject` from the ban (add to allowlist) → red;
     re-add `url(` to the value-safe list → red; restore → green. (House
     style: run the mutations while writing the test, record them.)
2. **`tests/php/test-org-logo-surfaces.php`** — tree-derived wiring guard
   (the `test-qr-cuercode.js` shape, comment-stripped before scanning):
   - Derives every file mentioning `org-logo.php` or `tblOrganisationLogos`
     by globbing the tree (never a typed list) and asserts:
     (a) **no surface inlines SVG** — no `<svg` emission fed from logo
     content, no `ContentSanitised`/`ContentOriginal` echoed outside
     `org-logo.php` + `pdf_renderer.php`'s data-URI resolver, every consumer
     emission is an `<img` whose src contains `/org-logo.php?`;
     (b) `org-logo.php` source contains `nosniff`, the CSP line,
     `Content-Disposition`, and calls `orgLogoFetchServeRow` (never a raw
     SELECT of the table, never `ContentOriginal`);
     (c) upload handlers call `orgLogoValidateAndStage` (never a second
     validation fork; no `move_uploaded_file` outside the core);
     (d) `includes/svg_sanitizer.php` is required by the core and
     `ihymnsSanitizeSvg` is called on the SVG branch;
     (e) the sanitiser profiles carry the `/org-logo.php` img pattern and
     `pdf_renderer.php` carries the resolver (the §6.4 pair can't half-land);
     (f) **kind-registry lockstep** — parses `IHYMNS_ORG_LOGO_KINDS`'s keys
     out of `org_logo_helpers.php` and `ORG_LOGO_KINDS`'s keys out of
     `print.js` and asserts identical keys in IDENTICAL ORDER (the key order
     IS the `'auto'` fallback ladder, §4.1/§6.3 — order drift is ladder
     drift), and that neither `ptSanitiseBlocks()`'s `logokind` case nor any
     admin page carries a second typed kind list.
   - Mutation-proven the same way (delete the CSP header → red; echo
     `ContentOriginal` → red; inline an `<svg>` from a fetch → red).
3. **Existing guards that light up automatically:**
   `test-print-block-registry.php` (the two `logo` registry entries must
   agree), `test-schema-coverage.php` (schema.sql mirror),
   `test-migration-registry.php` (probe present + not `=> true`),
   `test-deploy-paths.php` (no `/public_html/` literal in the migration),
   `test-print-one-renderer.php` (no second renderer snuck in),
   `test-html-sanitizer.php` (extended for the new img pattern in both
   profiles — positive and negative cases).

---

## 10. Out of scope for this PR (tracked, not dribbled)

- App header / projector / OG-image logo surfaces (§12(b)) — the schema,
  endpoint, alt text and variants already carry everything they need.
- Native-app API endpoints (`org_admin_logo_*`) — the core is API-ready.
- Light/dark variant UPLOAD UI — schema-ready (`Variant`), one form change
  when wanted.
- A re-sanitise maintenance pass for `SanitiserVersion` bumps — the columns
  and the layout-table precedent define it; build it with the first tightening.
- Any object-store backend (`StorageBackend`/`StoragePath` dormant).

Each gets a `for consideration` issue at PR time per standing-tasks §2.

---

## 11. Commit breakdown (ONE PR, atomic, ordered)

1. **`feat(db): tblOrganisationLogos migration + schema.sql mirror + registry entry (#1830)`**
   — §2 exactly: migration file (IHYMNS_INCLUDES_DIR idiom), byte-identical
   schema.sql block, the ONE registry entry with the real table-existence
   probe. Green: schema-coverage, migration-registry, deploy-paths.
2. **`feat(security): hardened SVG sanitiser includes/svg_sanitizer.php + truth-table guard (#1830)`**
   — §3 module + `tests/php/test-svg-sanitizer.php`, mutation runs recorded.
   No consumers yet (the html_sanitizer "lands with its guard, wired later"
   precedent).
3. **`feat(org-logos): ONE shared core — org_logo_helpers.php (kind registry + reads) + org_logo_admin.php (writes) (#1830)`**
   — §4: the `IHYMNS_ORG_LOGO_KINDS` map (§4.2 vocabulary, ladder order) +
   `ihymnsOrgLogoResolveKind()` + variants/caps/table-gate/fetch/list in the
   helpers file; validate/stage/upsert/delete/toggle in the admin file;
   unit-style tests for the validation branches AND the resolve-kind ladder
   (fixture SVG/PNG under `tests/php/fixtures/`).
4. **`feat(org-logos): public serving endpoint org-logo.php (#1830)`**
   — §5 endpoint; `tests/php/test-org-logo-surfaces.php` lands here with the
   endpoint-header + never-ContentOriginal assertions (mutation-proven).
5. **`feat(manage): logo upload cards on organisations + my-organisations (#1830)`**
   — §7: shared card partial/helper, POST actions on both pages, activity-log
   keys, plain-English copy, dormancy gating.
6. **`feat(print): logo block — chooseable kind + fallback ladder, registries, renderer, sanitiser pattern, PDF resolver (#1830)`**
   — §6: `$BLOCK_SCHEMA['logo']` (`logokind` coercion against the ONE map) +
   `PRINT_BLOCK_TYPES.logo` + the editor's kind select ("Automatic" + the
   §4.2 labels) + `renderBlock` case with the `'auto'` ladder / explicit-kind
   / render-nothing resolution; the JS `ORG_LOGO_KINDS` mirror;
   `my_organisations` `logos` field; html_sanitizer img_src widening +
   `IHYMNS_HTML_SANITISER_VERSION` bump; `_pdfInlineOrgLogo()`;
   test-print-block-registry + test-html-sanitizer extensions; the
   surfaces-guard grows the §9.2(e) pair assertion and the §9.2(f)
   kind-registry lockstep.
7. **`test(org-logos): finish tree-derived surface guard + mutation notes (#1830)`**
   — any guard assertions that needed commits 5–6 to exist; recorded
   mutation runs for every new assertion.
8. **`docs(org-logos): wiki, CHANGELOG, help copy, .claude context (#1830)`**
   — Wiki (Schema + a short "Organisation branding" page), CHANGELOG,
   `.claude/ProjectBrief.md` note, CLAUDE.md rule addition if the owner wants
   one, this plan marked as implemented.

Pre-PR audit per house rules (php -l / node --check sweep, security +
a11y pass). PR targets `alpha`.

---

## 12. Open decisions for the owner (none block the build)

### (a) Where do logo bytes live — database blob or filesystem?

- **The decision:** store uploaded logo files inside the database, or as files
  on the server's disk.
- **Why it needs deciding:** it's a data-placement call with backup and deploy
  consequences the code can't infer.
- **Options:**

| Option | Consequence |
|---|---|
| **A. Database (`MEDIUMBLOB`)** — recommended | Logos ride the existing DB backups atomically; all three channels (which share ONE MySQL) see the same logo instantly; no upload-directory permissions or deploy-path concerns (rule #41 class); files this small (≤2 MiB) are exactly what the existing "PDF/MIDI go in the blob" precedent covers. |
| B. Filesystem (`appWeb/uploads/org-logos/`) | Marginally cheaper DB; but adds a second thing to back up, per-server divergence risk, and directory-permission setup on every channel — the costs the audio kind pays for range-request streaming, which logos don't need. |
| Do nothing | Feature can't ship. |

- **Recommendation:** **A**. The schema keeps `StorageBackend`/`StoragePath`
  dormant so a later rebalance is a data move, not a migration.
- **Need back:** "A" or "B". **Blocks nothing** — the plan is written for A
  and switching to B touches only the core's stage/fetch functions.

### (b) Wire the app header / projector now, or print-only first?

- **The decision:** does this PR also show the org logo in the app header and
  on the Service Projection screen, or only in Print Templates?
- **Why:** product scope — the issue says "and beyond", but each extra surface
  is its own UI/design review.
- **Options:** print-only now (recommended) — smallest reviewable PR, and the
  endpoint/alt/variant schema already serves the later surfaces unchanged;
  or header+projector in the same PR — bigger review, design decisions
  (placement, sizing, theme variants) not yet made.
- **Recommendation:** print-only; file the header/projector surface as its own
  issue immediately (standing-tasks §2).
- **Need back:** "print-only" or "include header". **Blocks nothing.**

### (c) SVG strictness v1 — flagged defensible default, changeable cheaply

Per the owner-decision house rule on unanswered sub-questions: the sanitiser
v1 **bans `<style>` blocks, filters, masks, patterns and all animation**
(§3.3), which means some design-tool exports (notably Illustrator's
class-styled SVGs) will upload but lose their colours, with the UI hint
telling the uploader to re-export with inline styling. This is the safe
default; widening any of it later is an allowlist line + a
`IHYMNS_SVG_SANITISER_VERSION` bump, no schema change. **No reply needed
unless you want `<style>`-block support attempted in v1** (it would mean
sanitising selectors + a scoped-id rewrite — meaningful extra attack surface,
which is why it's not the default).

### (d) Logos are served publicly (flagged default)

`org-logo.php` is anonymous, like the OG image and QR endpoints — a logo is
public branding that ships on handouts, and gating it would break printing for
congregants. If an org ever wants an unlisted logo, `IsActive=0` hides it
everywhere today, and a proper visibility flag is one `MetaJson` key away.
**No reply needed unless you disagree.**

### (e) `monochrome`/`reversed` as KINDS vs the dormant `Variant` axis (flagged default)

Your taxonomy list names single-colour and light-on-dark versions, so v1
models them as **kinds** (distinct brand assets, matching how brand guides
publish them). The dormant `Variant` column stays reserved for true
theme-paired renditions of the SAME kind (e.g. a light and a dark `primary`
the app header would auto-switch between). The two can coexist without
conflict; if you'd rather reversed/monochrome live on the Variant axis
instead, it's a map edit + upload-form tweak before launch (after launch it's
a data move). **No reply needed unless you want them re-homed onto Variant.**
