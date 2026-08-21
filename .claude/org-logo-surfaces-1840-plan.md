# Org logos on three screen surfaces (#1840) — implementation plan

**Status: PLAN ONLY — nothing implemented.** Written 2026-08-21 (Fable-5 deep
design pass) on branch `claude/ilyrics-identity-work-model`, after a full read
of the #1830 system this extends. Companion to `.claude/org-logos-1830-plan.md`
(the base feature, IMPLEMENTED) and CLAUDE.md **rule #42** (binding doctrine:
served as `<img src="/org-logo.php?...">`, never inlined; one kind registry;
one read path; the surfaces this plan wires were explicitly deferred there —
*"never wire them without a fresh design pass on placement/sizing/theme-variant"*.
This is that design pass.)

**The owner's LOCKED decisions (2026-08-21):**

1. **App Header — Option A**: the org **emblem** co-branded to the LEFT of the
   iHymns wordmark, split by a hairline. Reversed emblem on the dark header.
2. **Projector — Option B**: a **persistent corner bug** (small
   reversed/monochrome emblem, low opacity, safe-margin corner), with an
   **operator on/off toggle** (default state recommended in §6.4).
3. **Share card (OG-image) — Option B**: a **branded colour band** in the
   church's own colour + the reversed logo + a small "via iHymns" credit.
   Requires a **new org brand-colour field**.
4. **Theme pairing: turn the dormant `Variant` axis ON now** — light/dark
   paired renditions of the same kind, auto-switched by the viewer's theme.

---

## 1. Verified current state (file anchors — every claim re-read on this branch)

### 1.1 The #1830 logo system (all live)

- **Kind registry + reads** — `appWeb/public_html/includes/org_logo_helpers.php`:
  `IHYMNS_ORG_LOGO_KINDS` (10 kinds, key order = the `'auto'` ladder, L61–102);
  **`IHYMNS_ORG_LOGO_VARIANTS = ['default','light','dark']` ALREADY EXISTS**
  (L109) — the vocabulary needs no change; `ihymnsOrgLogoResolveKind()` (L145);
  `orgLogoTableExists()` memoised probe (L167); **`orgLogoFetchServeRow()`
  (L200) already implements requested-variant → `'default'` fallback** and
  SELECTs `ContentSanitised` only; `orgLogoListForOrg()` (L240) returns
  per-(kind,variant) meta incl. `Variant`, `Sha256`, `IsActive`;
  `orgLogoRenderAdminCard()` (L296) — the ONE admin card markup, currently
  **folds to `Variant === 'default'` rows only** (L318) and its three POST
  forms carry no `variant` field.
- **Write core** — `includes/org_logo_admin.php`: `orgLogoValidateAndStage()`
  (finfo sniff → PNG/SVG branches, SVG through `ihymnsSanitizeSvg()`),
  `orgLogoUpsert()`/`orgLogoDelete()`/`orgLogoSetActive()` — **all already take
  `$variant` and validate it against `IHYMNS_ORG_LOGO_VARIANTS`** (L245–253,
  317, 335). Only the CALLERS hardcode `'default'`.
- **Both admin pages hardcode `'default'`** in their `logo_upload` /
  `logo_remove` / `logo_toggle` handlers — verified
  `manage/my-organisations.php` L415–457 (`orgLogoUpsert($db,$orgId,$kind,
  'default',…)`); `manage/organisations.php` mirrors it.
- **Serving endpoint** — `appWeb/public_html/org-logo.php`: **already accepts
  `&variant=`** validated against `IHYMNS_ORG_LOGO_VARIANTS` (L84–87); `&v=`
  sha-prefix keyed immutable caching (L138); 404-no-body; `nosniff` + sandbox
  CSP; anonymous. **Variant activation needs ZERO endpoint change.**
- **DDL** — `appWeb/.sql/schema.sql` L1306–1337: `tblOrganisationLogos`,
  `Variant VARCHAR(10) NOT NULL DEFAULT 'default'` inside
  `UNIQUE uq_OrgKindVariant (OrgId, Kind, Variant)`. **No schema change is
  needed for Variant activation** — column, vocabulary, unique key, endpoint
  param and read-path fallback all already exist. Only UIs/consumers dormant.
- **The one existing consumer pattern** — print: `includes/
  print_template_schema.php` L91 (`'logo' => ['kind'=>'logokind',…]`);
  `js/modules/print.js` L49 (`ORG_LOGO_KINDS` client mirror), L390–429
  (`case 'logo'` — 'auto' ladder / explicit-kind-or-nothing), **L571–597
  (`fetchPrintOrgLogos()` — session-cached `my_organisations` fetch whose
  byKind fold `byKind[l.kind] = l` does NOT filter variant — a LAST-ROW-WINS
  latent bug the moment a second variant row exists**, §3.4);
  `includes/pdf_renderer.php` L335–367 (`_pdfInlineOrgLogo()` — the
  "resolve bytes directly, never mPDF self-request over HTTP" mirror this
  plan's OG-image path copies).
- **API meta emit** — `api.php` `case 'my_organisations'` (L8214+): additive
  `logos` field per org, **already emits `variant`** + `v` (Sha256), gated on
  `orgLogoTableExists()`.
- **Guard** — `tests/php/test-org-logo-surfaces.php`: tree-derived
  `$mentioning` set (anchors `org-logo.php` / `tblOrganisationLogos` /
  `orgLogo`/`OrgLogo`/`ORG_LOGO_` — so **every new file this plan adds is
  auto-scanned**, L162–169); checks (a) never-inline-SVG + never
  `ContentOriginal` outside the write core, (b) endpoint shape, (c)
  upload-handler wiring + ≥2-site floor, (d) sanitiser wiring, (e)
  sanitiser-pattern/PDF-resolver pair, (f) PHP↔JS kind-registry lockstep +
  ladder-fingerprint second-list ban (L369–378).

### 1.2 The three target surfaces

- **App header** — `appWeb/public_html/index.php` L1214–1243: `<header
  id="app-header">` → `.navbar-brand` dropdown-toggle `<button
  id="logo-nav-btn">` containing `<i class="fa-solid fa-music">` + the
  `<span class="fw-bold">` app-name wordmark (+ env badge). **The shell is NOT
  per-user personalisable**: it is served identically to everyone AND
  pre-cached by the service worker (`service-worker.js.php` L446 pre-caches
  `'/'`; navigations fall back to the cached shell). Signed-in state is a
  client-side concern (`#nav-manage-li` starts `d-none`, toggled by
  `user-auth.js`). `<html>` hardcodes `data-bs-theme="light"` (L844) and
  `js/modules/settings.js applyTheme()` (L682–706) swaps
  `data-bs-theme`/`data-ihymns-theme` client-side. There is **no theme-change
  DOM event** (grep: no `ihymns:theme*` in `js/constants.js`; the events that
  do exist include `EVT_AUTH_CHANGED = 'ihymns:auth-changed'`, constants.js
  L169, dispatched by `user-auth.js` L116).
- **Org identity is per-USER, many-to-many** — `tblOrganisationMembers
  (UserId, OrgId, Role 'owner|admin|member')` (schema.sql L1247–1261);
  `my_organisations` returns ALL memberships ordered `Name ASC`. There is
  **no single "the org" for the public header and no "primary org" concept
  anywhere** — flagged as a design question with a recommended default
  (§5.2, §B.1).
- **Projector** — `manage/service-projection.php`: full-bleed overlay
  `#svc-projection` with **fixed dark background `#0b1020` regardless of the
  admin's theme** (L164, its own comment: "theme-independent"); occupied
  regions: End button top-RIGHT (L191), venue/instr/code/URL centre column,
  foot text bottom-centre (L190), operator console bottom-centred ≤720px
  (L196–199, collapsible). Org context is fully known server-side per venue
  (`VENUES[].OrgId`, L94–104) and the page is per-request PHP (never
  SW-cached). **It shows a join code, not lyric slides** — the "over every
  slide" wording in the locked decision is examined in §B.5 (the lyric-slide
  surface in this codebase is `js/modules/present-mode.js`, the public song
  page's Present overlay).
- **OG-image** — `appWeb/public_html/og-image.php`: GD-composed 1200×630 PNG,
  anonymous, `Cache-Control: public, max-age=86400` (L76), modes
  generic/song/songbook/setlist; 630×630 centre safe zone (`$safeLeft=285`);
  current branding = `drawBranding()` at `H-55` bottom-centre (L290–304).
  **GD cannot rasterise SVG** (`imagecreatefromstring()` has no SVG support) —
  a hard constraint on which logo rows this surface can composite (§7.3).
  Setlist mode resolves through `sharedSetlistResolveWire()`; the raw share
  row (incl. **`_ownerUserId`** and **`_showSharerName`**) is available via
  `sharedSetlistGet()` (`includes/SharedSetlist.php` L177, L213–226).
  `tblSharedSetlists` carries `OwnerUserId` + `ShowSharerName` but **no
  OrgId** (schema.sql L1495–1530). The og:image URLs are emitted by
  `index.php` (L273/340/488/750) — fixed per PAGE, not per sharer, so only
  the setlist card (whose URL carries the share id → owner → org) can ever be
  org-branded (§7.1).
- **Theme system** — `manage/includes/admin-theme-init.php` (#955): admin
  pages resolve `localStorage.ihymns_theme` → `data-bs-theme` pre-CSS; public
  site does the same in `settings.js`. **The theme signal every consumer must
  key off is the `data-bs-theme` attribute on `<html>`** (rule #16) — not a
  media query alone (a user can pick dark on a light-OS machine).
- **tblOrganisations** (schema.sql L1208–1240): no brand/colour column today.
  Precedents on this table for org preferences: `SetlistEditAudience` (#1791),
  `LiveIdleTimeoutMins` (#1770). A strict-hex `'color'` coercion precedent
  already exists: `print_template_schema.php` `ptSanitisePageOptions()` L218–221.
- **Shared org validation home** — `includes/organisation_validation.php` is
  already required by api.php AND both admin pages (the natural home for the
  brand-colour normaliser, rule #22).
- **Migration registry** — `manage/includes/migration-registry.php`: one entry
  per migration, real probes, array-key order = apply order (tail verified).

---

## 2. Design overview — one resolver, four consumers

Everything in this plan hangs off ONE new pure resolution mechanism in the
existing registry file, so no surface ever forks its own kind/variant logic:

```
IHYMNS_ORG_LOGO_SURFACE_PREFS   (org_logo_helpers.php — the ONE surface registry)
ihymnsOrgLogoResolveThemedAsset()  (PHP, pure: available rows + surface + theme → {kind,variant})
        │
        ├── header      → js/modules/org-logo.js mirror (client resolves — the shell
        │                 is SW-cached and anonymous-identical; meta comes from the
        │                 existing my_organisations `logos` field)
        ├── projector   → resolved SERVER-side in service-projection.php (org known
        │                 per venue; theme fixed dark) → URL baked into VENUES JSON
        ├── og-card     → resolved SERVER-side in og-image.php; BYTES fetched
        │                 directly via orgLogoFetchServeRow() (the _pdfInlineOrgLogo()
        │                 "never self-request over HTTP" doctrine); PNG rows only (GD)
        └── print       → UNTOUCHED (author-driven kind option + 'auto' ladder stays;
                          only its byKind fold gains the variant filter, §3.4)
```

`org-logo.php` itself changes **not at all** (its `&variant=` + `&v=` contract
already covers everything). Emitters always emit the RESOLVED
`(kind, variant, v)` triple, so the immutable-cache key stays exact.

---

## 3. Variant activation (the core)

### 3.1 Semantics (documented in the helpers doc-block)

`Variant` names the background/theme a rendition is drawn FOR:

- `'default'` — the org's standard rendition (what v1 has always stored);
  assumed legible on light backgrounds.
- `'light'` — a rendition specifically tuned for LIGHT theme/backgrounds
  (used when it exists; `default` otherwise).
- `'dark'` — a rendition tuned for DARK theme/backgrounds (a "reversed"
  drawing of that same kind).

The `reversed` KIND (a distinct brand asset, rule #42) is unchanged and
coexists: it stays the brand's published "light-on-dark version" as an asset
in its own right; the `dark` VARIANT is the per-kind theme pairing. The two
meet only inside the surface preference lists below — never by a consumer
guessing.

**No schema change** (§1.1 — column, vocabulary, unique key, endpoint param,
read fallback all pre-exist). No migration card. Activation = admin upload UI
+ the resolver + the consumers.

### 3.2 The ONE surface registry + themed resolver (`org_logo_helpers.php`)

```php
/**
 * #1840 — per-surface kind preference lists. Key order within each list IS
 * that surface's resolution order (same key-order-is-the-ladder doctrine as
 * IHYMNS_ORG_LOGO_KINDS). 'darkCapableOnly' surfaces sit on a permanently
 * dark/brand-coloured ground where a default (light-background) rendition
 * would be illegible — for them the 'default'-variant fallback step is
 * SKIPPED for every kind except 'reversed' (the one kind whose default
 * rendition is BY DEFINITION light-on-dark).
 */
const IHYMNS_ORG_LOGO_SURFACE_PREFS = [
    'header'    => ['kinds' => ['emblem', 'favicon'],             'darkCapableOnly' => false],
    'projector' => ['kinds' => ['emblem', 'reversed', 'favicon'], 'darkCapableOnly' => false],
    'og-card'   => ['kinds' => ['reversed', 'emblem'],            'darkCapableOnly' => true],
];

/**
 * Pure resolution — list-in, choice-out, DB-free (unit-testable like
 * ihymnsOrgLogoResolveKind()).
 *
 * @param list<array{kind:string,variant:string}> $available ACTIVE rows only
 *        (callers filter IsActive; og-card additionally pre-filters to PNG
 *        rows — the FILTER is the caller's constraint, the ladder is shared).
 * @param string $surface  key of IHYMNS_ORG_LOGO_SURFACE_PREFS
 * @param string $theme    'light'|'dark'
 * @return array{kind:string,variant:string}|null  null => render NOTHING.
 */
function ihymnsOrgLogoResolveThemedAsset(array $available, string $surface, string $theme): ?array
```

Algorithm, per kind K in the surface's list, in order:

1. `(K, $theme)` — the exact theme-paired rendition.
2. `(K, 'default')` — **skipped** when the surface is `darkCapableOnly` and
   `K !== 'reversed'`.

First hit wins; nothing → `null` (absence renders as absence — never a
broken image, never a substituted asset the ladder doesn't document).

Worked consequences (the fallback decision, justified):

- **Header, dark theme, org has only a default emblem** → the default emblem
  shows (most real logos are legible on both; an empty header slot would make
  the feature look broken for the 90% single-upload org). Uploading
  `emblem@dark` upgrades it — that IS the owner's "reversed emblem on the
  dark header". The header deliberately does **NOT** substitute the
  `reversed` KIND: its lockup shape is unknown (often a full wordmark) and
  wrong for a ~28px co-brand slot; the `'auto'`-style substitution rule #42
  allows applies only to ladders the registry itself documents.
- **Projector** (theme is always `'dark'` — the overlay ground is fixed
  `#0b1020`): `emblem@dark` → `reversed@dark` → `reversed@default` →
  `emblem@default` → `favicon@dark` → `favicon@default`. The `reversed` kind
  IS in this list (the owner named it; the corner slot is shape-tolerant).
  **`monochrome` is deliberately absent from every dark-ground list** — its
  registry description is "one-colour (usually black)": black at 35% opacity
  on `#0b1020` is invisible-to-illegible, and we never tint stored bytes.
- **OG-card** (always `'dark'`-context — the ground is the org's brand
  colour): `reversed@dark` → `reversed@default` → `emblem@dark` — and NOT
  `emblem@default` (a coloured light-background emblem on an arbitrary brand
  colour is the illegibility case `darkCapableOnly` exists to prevent).
  Nothing resolves → the band renders the org NAME in contrast text instead
  (§7.3) — still branded, never broken.

### 3.3 JS mirror — NEW shared module `js/modules/org-logo.js`

The header is the one surface that must resolve client-side (§1.2). To keep
rule #35's "a mechanism, not a comment":

```js
export const ORG_LOGO_SURFACE_PREFS = { header: [...], projector: [...], 'og-card': [...] };
export function resolveThemedAsset(logos, surface, theme)  // exact twin of the PHP algorithm
export function orgLogoUrl(orgId, { kind, variant, v })    // '/org-logo.php?org=…&kind=…&variant=…&v=…'
export function fetchMyOrgs()                              // session-cached my_organisations orgs
                                                           // (ONE shared promise — apiFetch, auth:true,
                                                           // null on 401/failure)
```

- `fetchMyOrgs()` extracts the fetch half of print.js's private
  `fetchPrintOrgLogos()` (modularity rule: the second consumer is the
  extraction trigger). `print.js` keeps its print-specific FOLD but delegates
  the fetch.
- The `projector`/`og-card` entries in the JS mirror exist purely for the
  lockstep guard's completeness (only `header` is consumed client-side);
  the guard (§8.1) asserts the WHOLE map matches, so a future client surface
  can't fork.
- Kind literals inside the surface lists live ONLY in the two registry files
  — no page/module types a kind name (the existing ladder-fingerprint ban
  plus the new §8.1 check keep it that way).

### 3.4 Print stays byte-stable — the fold fix that MUST land first

`print.js` `fetchPrintOrgLogos()`'s fold (`byKind[l.kind] = l`, L586) takes
the LAST row per kind. Today only `default` rows exist; the moment the admin
UI can create `light`/`dark` rows, `orgLogoListForOrg()`'s
`ORDER BY FIELD(Kind,…), Variant` makes `light` (alphabetically last) win —
**silently re-branding every existing print template**. Fix (in the SAME
commit that lands the resolver, BEFORE any variant upload UI exists — the
ordering is load-bearing):

```js
logos.forEach((l) => { if (l && l.kind && l.variant === 'default') { byKind[l.kind] = l; } });
```

Print output is thereby deliberately UNCHANGED by variant activation (paper
is the `default` rendition's home ground; a future "prefer `light` on paper"
enhancement is a one-line conscious change, not an accident).

The same reasoning applies to `orgLogoRenderAdminCard()`'s existing
`Variant === 'default'` fold (already correct) — it stays the row driver;
variants render as sub-slots (§3.5).

### 3.5 Admin upload UI for variants (both pages via the ONE card)

`orgLogoRenderAdminCard()` (the shared renderer both admin pages call) grows,
per EXPANDED kind row (i.e. a kind that has a `default` upload), a compact
**"Theme versions (optional)"** strip with two slots — **Light theme** /
**Dark theme**:

- Empty slot: label + a small file input + Add (posts `logo_upload` with a
  new hidden `variant` field `light`/`dark`).
- Filled slot: 60px preview `<img src="/org-logo.php?org=…&kind=…&variant=…
  &v=…">` (the never-inline rule applies to variant previews too) +
  Replace / Remove (posts `logo_remove` with `variant`).
- No per-variant alt-text input — `AltText` meaning rides the kind's default
  row (the variant is the same asset re-drawn; one accessible name).
- Plain-English copy (`.claude/admin-plain-english.md` register):
  *"Theme versions (optional) — add a version drawn for light or dark
  screens and iHymns will pick the right one automatically."*
- **Variants require the default first** (collapsed rows offer only the main
  Add). Rationale: an orphan variant (dark-only, no default) would show on
  dark screens and silently vanish on light ones — the silent-half-feature
  class this repo documents. Enforced by the card only rendering variant
  slots inside expanded rows; the HANDLER does not need to enforce it
  (a hand-crafted POST creating an orphan variant is harmless — the ladder
  simply resolves nothing on light).

**Handler change (both pages, identical shape):** the three `logo_*` cases
read `$variant = (string)($_POST['variant'] ?? 'default');` and validate
`in_array($variant, IHYMNS_ORG_LOGO_VARIANTS, true)` before delegating (the
core re-validates anyway — rule #5's belt-and-braces). Activity-log detail
gains `'variant' => $variant`.

**Remove-the-default cascades:** removing a kind's `default` row also removes
that kind's `light`/`dark` rows — otherwise they become invisible orphans the
card can no longer manage. New small core helper in `org_logo_admin.php`:

```php
function orgLogoDeleteKindAll(\mysqli $db, int $orgId, string $kind): int  // rows deleted, all variants
```

`logo_remove` with `variant === 'default'` calls it (confirm copy: *"Remove
the {label} logo and its theme versions?"*); `logo_remove` with an explicit
`light`/`dark` removes just that row via the existing `orgLogoDelete()`.

`logo_toggle` stays kind-level (toggles all variants of the kind via a
widened `orgLogoSetActive()` call per variant, or an `orgLogoSetActiveKind()`
sibling — one visibility switch per ASSET, not per rendition; a
half-hidden kind that appears only on dark screens is another silent-half
state we refuse to mint).

---

## 4. New org brand-colour field (for Share Card B)

### 4.1 Schema (rules #19/#20 — one-pass, additive, VARCHAR/JSON not ENUM)

Adversarial "what would force a second migration?" pass: the brand family
foreseeably grows (a secondary colour, a dark-theme brand colour, a brand
font token). One scalar column for the token the app ACTS ON now, plus the
house-style dormant JSON bag for the growable vocabulary (`MetaJson` on
`tblOrganisationLogos` is the accepted #1830 precedent; rule #44 governs
FORM fields, not forward-looking schema — no form control is rendered for
the bag):

```sql
    BrandColor      VARCHAR(9)      NULL DEFAULT NULL COMMENT 'Org brand colour as a strict hex token — #rrggbb or #rrggbbaa, lowercase, app-validated by ihymnsOrgBrandColourNormalise() (includes/organisation_validation.php, #1840). NULL = no brand colour; every branded surface (OG share card band) stays dormant',
    BrandJson       JSON            NULL DEFAULT NULL COMMENT 'Dormant grab-bag for future brand tokens (secondaryColor, darkColor, font…) — growable vocabulary is JSON, never new columns (rule #20/#28, the tblOrganisationLogos.MetaJson precedent). Nothing reads it yet (#1840)',
```

Placed in `tblOrganisations` directly after `EnforceSetlistEditAudience`
(schema.sql ~L1225), byte-identical in migration and mirror.

**Migration** `appWeb/.sql/migrate-org-brand-colour.php`:

- Rule #41 include idiom (`IHYMNS_INCLUDES_DIR` with the `/public_html/`
  literal only as the repo/CLI fallback).
- Each `ALTER TABLE … ADD COLUMN` gated on its own
  `!columnExists('tblOrganisations', …)` probe (mysqli STRICT — a re-run must
  not throw), with a `@migration-adds tblOrganisations.BrandColor` /
  `@migration-adds tblOrganisations.BrandJson` doctag PER column (rule #19's
  multi-column ALTER note).
- **Registry entry** (`manage/includes/migration-registry.php`, appended):

```php
'org-brand-colour' => [
    'script' => 'migrate-org-brand-colour.php',
    'card' => [
        'title'  => 'Organisation brand colour (#1840)',
        'body'   => 'Adds <code>tblOrganisations.BrandColor</code> (+ a dormant'
                  . ' <code>BrandJson</code> token bag) so a church can set its brand'
                  . ' colour for branded share-preview cards. Additive, idempotent,'
                  . ' dormant until an org sets a colour. Safe to re-run.',
        'button' => 'Run Brand Colour Migration',
    ],
    /* Multi-object OR-probe (rule #19) — a partial apply never shows green. */
    'probe' => static fn(\mysqli $db) => !_migProbe_columnExists($db, 'tblOrganisations', 'BrandColor')
                                       || !_migProbe_columnExists($db, 'tblOrganisations', 'BrandJson'),
],
```

### 4.2 The ONE validator + persist core (`includes/organisation_validation.php`)

Already required by api.php and both admin pages — the rule-#22 home:

```php
/** Strict allowlist normalise: '' / null → null (clear); '#RGB' widened to
 *  '#rrggbb'; '#RRGGBB'/'#RRGGBBAA' → lowercased; ANYTHING else → false
 *  (reject — never stored, never echoed back unescaped). */
function ihymnsOrgBrandColourNormalise(?string $in): string|false|null

/** #rrggbb(aa) → [r,g,b] ints (alpha ignored — a share-card band must be
 *  solid for legibility; documented). The ONE parse og-image.php uses. */
function ihymnsOrgBrandColourRgb(string $hex): array

/** Column-existence-gated UPDATE (dormant-safe on an un-migrated install —
 *  returns false, callers show nothing). Kind of write both admin pages call. */
function orgSetBrandColour(\mysqli $db, int $orgId, ?string $normalised): bool
```

Regex: `/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i` at the door, canonical
stored form always `#rrggbb` or `#rrggbbaa` lowercase. The value is NEVER
interpolated into CSS or HTML anywhere in this plan — og-image consumes it as
three GD ints; the admin form echoes it `htmlspecialchars()`'d into a
`value=` attribute (belt-and-braces over the allowlist).

### 4.3 Admin UI (which page, what control)

Both org-editing pages, gated `columnExists('tblOrganisations','BrandColor')`
(the `placeColumnExists()` dormancy posture both already use):

- **`manage/my-organisations.php`** (org admins — the primary audience): a
  "Brand colour" row in the org's settings area, beside the existing
  Set-list-sharing preference block (the most recent org-preference
  precedent, #1791): `<input type="color">` paired with a text input showing
  the hex (the pattern `js/modules/colour-picker.js` already serves
  elsewhere — reuse, don't fork a swatch widget), plus a Clear button.
  Helper copy: *"Used where iHymns shows your church's branding — for
  example the coloured band on shared set-list preview images."*
- **`manage/organisations.php`** (system admins): the same field in each
  org's edit form.
- POST: a small `brand_save` action in each page's existing switch →
  `ihymnsOrgBrandColourNormalise()` → reject with plain-English copy
  (*"That doesn't look like a colour code — use the picker or a value like
  #6a1b9a."*) or `orgSetBrandColour()`; activity keys `org.brand_save` /
  `org_admin.brand_save` with `['colour' => …]`.
- No `<input type="color">-only` trust: the normaliser is the gate (a colour
  input still submits attacker-controlled text).

---

## 5. Surface 1 — App Header (Option A)

### 5.1 Why client-side is the only correct wiring (verified, §1.2)

The shell is one document for everyone AND pre-cached by the service worker;
auth + org identity are client-side facts. So the header co-brand is a
progressive enhancement module — exactly the `user-auth.js`/`nav-manage-li`
pattern — and **no change to `index.php`'s markup is required at all** (the
module injects into the existing `#logo-nav-btn`). CSP: `img-src 'self'`
already admits `/org-logo.php` (same origin); the module is a real ES module
loaded from `app.js`, so the nonce CSP is untouched (rule #30 does not even
arise — this is not a fragment).

### 5.2 NEW module `js/modules/header-branding.js`

Booted once from `app.js` (NOT the router — the header lives outside the
swapped page content; but note rule #32 does not apply either: the emblem is
INSIDE the persistent header, not a fixed element over swapped content).

Behaviour:

1. On boot and on every `EVT_AUTH_CHANGED` (constants.js — the existing
   event, no new name needed): signed-out → remove any injected emblem,
   done. Signed-in → `fetchMyOrgs()` (§3.3, shared session cache — the SAME
   single request print.js uses).
2. **Org choice**: the first org (the API's existing `Name ASC` order) whose
   `logos` meta resolves a `header` asset for EITHER theme. Multi-org
   members: first-match — flagged with a recommended default in §B.1 (no
   schema, no new setting in this pass).
3. **Theme resolution**: current theme = `document.documentElement
   .getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light'` — the SAME
   attribute the stylesheets read (rule #16's "one theme signal"), covering
   user choice, system-follow and the high-contrast-on-light case in one
   read. Re-resolution via a **`MutationObserver` on `<html>`'s
   `data-bs-theme` attribute** — deliberately NOT a new `ihymns:*` event:
   the attribute IS the source of truth, the observer can never miss a
   dispatcher (the #1581 two-spellings failure class), and it also catches
   the admin-theme-init path for free if this module is ever loaded there.
4. **Injection** (idempotent — teardown-first, then rebuild):

```html
<button … id="logo-nav-btn">                    <!-- unchanged -->
    <img class="header-org-emblem" src="/org-logo.php?org=…&kind=…&variant=…&v=…"
         alt="" aria-hidden="true">             <!-- injected, decorative -->
    <span class="header-brand-divider" aria-hidden="true"></span>  <!-- the hairline -->
    <i class="fa-solid fa-music fa-lg" …>       <!-- existing content follows -->
```

   - `alt=""` + `aria-hidden`: the button's accessible name is its
     `aria-label` (which OVERRIDES descendant text anyway — WCAG accname
     computation); the co-brand is decorative "this church, on iHymns"
     signage, and the button's function (open navigation) is unchanged.
   - `onerror` → remove the img + divider (absence renders as absence).
   - Theme change → re-run the resolver; if the resolved `(kind,variant,v)`
     differs, swap `src`; if nothing resolves for the new theme, remove.
5. **CSS** (app.css, header section):

```css
.header-org-emblem   { max-height: 28px; max-width: 44px; object-fit: contain; }
.header-brand-divider{ width: 1px; align-self: stretch; margin: 2px 0;
                       background: currentColor; opacity: .3; }  /* theme-aware hairline */
```

   `currentColor` makes the hairline follow the navbar's own foreground in
   both themes — no per-theme token needed.

Degradations: anonymous / no org / no resolvable logo / meta fetch failure /
un-migrated install (`logos` field absent) → the header renders exactly as
today. Zero layout reservation (flex `gap-2` absorbs the late-arriving img;
a one-time reflow on sign-in is the accepted progressive-enhancement cost —
same class as the Manage menu item appearing).

---

## 6. Surface 2 — Projector corner bug (Option B)

### 6.1 Server-side resolution (org + theme both known server-side)

`service-projection.php` (per-request PHP, org per venue, ground fixed dark):
after loading `$venues`, when `orgLogoTableExists($db)`, resolve ONCE per
distinct OrgId:

```php
$rows   = array_filter(orgLogoListForOrg($db, $orgId), fn($r) => (int)$r['IsActive'] === 1);
$asset  = ihymnsOrgLogoResolveThemedAsset(
              array_map(fn($r) => ['kind' => $r['Kind'], 'variant' => $r['Variant']], $rows),
              'projector', 'dark');
$logoUrl = $asset ? '/org-logo.php?org=' . $orgId
         . '&kind=' . rawurlencode($asset['kind'])
         . '&variant=' . rawurlencode($asset['variant'])
         . '&v=' . rawurlencode($shaOfThatRow) : null;
```

Attached per venue row as `$v['OrgLogoUrl']` → rides the existing
`VENUES` `json_encode` (JSON_HEX_* already applied). Un-migrated / no logo →
key null → the client renders nothing.

### 6.2 Overlay markup + CSS

```html
<img id="svc-proj-logo" class="d-none" alt="" aria-hidden="true">
```

```css
/* #1840 — org corner bug: TOP-LEFT is the one always-free corner (End button
   owns top-right, foot text bottom-centre, operator console bottom-centre up
   to 720px wide). Low opacity so it reads as signage, never content. */
#svc-proj-logo { position: absolute; top: 3vmin; left: 3vmin;
    max-height: 8vmin; max-width: 20vmin; object-fit: contain;
    opacity: .35; pointer-events: none; }
```

On session start: if the operator toggle (§6.4) is on and the chosen venue's
`OrgLogoUrl` is set → set `src`, un-hide on `load`, remove on `error`
(never a broken glyph on a projector). Torn down in `teardown()`.

### 6.3 Placement rationale (the "safe-margin corner")

Verified occupied regions (§1.2): top-right = End button; bottom-centre =
foot text + operator console (console spans nearly full width on narrow
screens). **Top-left** is the only corner nothing ever occupies, balances
the top-right End control, and — should the overlay ever gain lyric slides —
is the corner furthest from where reading starts to matter least at 35%
opacity with a 3vmin margin. `pointer-events: none` so it can never eat a
click aimed at anything layered nearby.

### 6.4 The operator toggle — where it lives, and the default

- **Control**: a checkbox in the SETUP card (*"Show your organisation's logo
  on the projection"*), visible before starting, PLUS a small "Logo" toggle
  button in the operator console header beside "Hide" (the operator's
  existing live control strip) so it can be flipped mid-service without
  ending the session. Both drive the same state.
- **State home: `localStorage` on the projection device**, key
  `ihymns_svcproj_logo` (`'1'`/`'0'`). Rationale over the alternatives:
  - a projection laptop is a stable, venue-bound device — per-device
    persistence matches how the preference is actually exercised
    ("this screen, this room");
  - it needs no schema, no endpoint, no migration (fully additive);
  - an ORG setting would force every operator of an org into one choice and
    would be the only projector preference stored server-side (the console
    collapsed state isn't either);
  - it follows the established device-preference idiom
    (`ihymns_theme` / `ihymns_cvd_mode`).
  Read/write wrapped in try/catch (private-window safe), falling back to ON.
- **Default: ON** — recommendation, justified: (1) uploading a projector-
  resolvable logo is already the org's deliberate opt-in — an org that
  doesn't want it simply hasn't uploaded one, and no logo means the toggle
  changes nothing; (2) a default-OFF toggle on a screen operators rarely
  explore is the silent-undiscovered-feature class this repo repeatedly
  documents (rule #30's lesson generalised) — the feature would effectively
  not exist; (3) the off-switch is one click and remembered per device.

---

## 7. Surface 3 — OG-image branded band (Option B)

### 7.1 Which cards CAN be branded (structural finding)

An `og:image` URL is emitted per PAGE and fetched by anonymous unfurlers —
it cannot know who pressed Share. The ONLY card whose URL carries an
identity chain to an org is the **setlist card** (`?setlist=<shareId>` →
`tblSharedSetlists.OwnerUserId` → `tblOrganisationMembers`). Song and
songbook cards have no org context and stay exactly as they are. This is a
structural property, not a scope choice — recorded for the owner in §B.4.

**No `&org=` request parameter is added.** Deriving the org server-side from
the share row makes the branding unforgeable (a crafted og-image URL cannot
paint Church A's colours onto arbitrary content); an explicit param can be
added additively later if a surface with a legitimate org-in-URL need
appears.

### 7.2 Org resolution + the privacy gate

In `og-image.php`, setlist mode only, ALL failure paths falling through to
today's unbranded render (rule #28-C posture — fail to the current card,
never an error):

1. `sharedSetlistGet($cleanId)` → `_ownerUserId`, `_showSharerName`
   (§1.2 — both already surfaced by the share core; no second row-reader).
2. **Privacy gate: brand only when `_showSharerName === true`.** The share
   card names the sharer's church far louder than a name line would;
   `ShowSharerName` (default 0 = anonymous, owner-set per link at mint,
   #1791 G3) is the existing per-link "show who this came from" consent and
   is reused rather than minting a parallel flag (rule #44: one consent the
   app already collects, not a second field). Flagged §B.4 with this as the
   recommended default.
3. Owner's orgs (`tblOrganisationMembers` JOIN `tblOrganisations`,
   `IsActive=1`, `Name ASC` — the SAME order `my_organisations` uses, so
   every surface agrees on "first org"): pick the first with a non-NULL
   `BrandColor` (column-existence-gated read, rule #9 — un-migrated install
   → unbranded).
4. Logo: `orgLogoListForOrg()` → ACTIVE rows → **filter `Mime ===
   'image/png'`** (GD cannot rasterise SVG — §1.2; the constraint is the
   caller's, per §3.2) → `ihymnsOrgLogoResolveThemedAsset(…, 'og-card',
   'dark')` → bytes via **`orgLogoFetchServeRow()` directly** (the ONE read
   path — never an HTTP self-request, mirroring `_pdfInlineOrgLogo()`'s
   stated doctrine) → `imagecreatefromstring()`.

### 7.3 Rendering

- **Band**: full-width solid rectangle, bottom 84px (`y = 546…630`), colour
  = `ihymnsOrgBrandColourRgb(BrandColor)` (alpha ignored — solid band for
  legibility). A 1200-wide band survives every crop in the file's own
  platform table: the iMessage 630×630 centre-crop keeps full height and
  trims sides, so band content is kept inside the horizontal safe zone.
- **Logo**: resolved PNG resampled to max-height 56px (proportional,
  max-width ~240px), vertically centred in the band, left edge at
  `$safeLeft + 30` (inside the crop-safe zone), alpha-composited
  (`imagealphablending` already true).
- **No PNG logo resolves** (SVG-only org, or nothing dark-capable): draw the
  **org name** (truncated via the existing `truncateText()`) in the band
  instead — still branded, never a broken/illegible mark.
- **"via iHymns" credit**: right side of the safe zone inside the band —
  the existing 28px `assets/icon-512.png` resample + `via iHymns` at 12pt.
  In branded mode the existing `drawBranding()` call is SKIPPED for the
  setlist card (the credit replaces it — never two iHymns marks).
- **Contrast**: text/credit colour computed from the band colour's YIQ
  brightness (`(299R+587G+114B)/1000 >= 150` → near-black `#1e2030`, else
  white) so a church with a pale brand colour still gets a readable credit
  and name. One small pure helper beside `ihymnsOrgBrandColourRgb()`.
- **Content reflow**: setlist preview-line loop's floor tightens from
  `H - 70` to `H - 110` in branded mode so titles never underlap the band.
- **Caching**: the endpoint's `max-age=86400` means colour/logo edits lag on
  already-unfurled links up to 24h — accepted and documented (social
  platforms cache far longer anyway).

---

## 8. Guards (§ rule #34 — tree-derived, mutation-proven)

### 8.1 `tests/php/test-org-logo-surfaces.php` extensions

The existing `$mentioning` derivation (`orgLogo`/`OrgLogo`/`ORG_LOGO_`/
`org-logo.php`/`tblOrganisationLogos` fingerprints) **auto-scans every new
file this plan creates** — `og-image.php`, `header-branding.js`,
`org-logo.js`, the touched projector page — so checks (a) never-inline-SVG
and never-`ContentOriginal` apply to all of them with zero guard change.
New checks, each with its mutation recorded in the doc-block as it is
proven:

- **(g) Surface-prefs PHP↔JS lockstep**: parse
  `IHYMNS_ORG_LOGO_SURFACE_PREFS` (org_logo_helpers.php) and
  `ORG_LOGO_SURFACE_PREFS` (js/modules/org-logo.js) — identical surface
  keys, identical kind arrays IN IDENTICAL ORDER (order is the ladder), with
  a parsed-count floor per side (≥2 kinds per surface, ≥3 surfaces — the
  anti-under-report floor idiom already used for check (f)). Mutations:
  swap `emblem`/`favicon` in the JS header list → red; delete a surface from
  PHP → red.
- **(h) OG-image never self-requests**: og-image.php (comment-stripped) must
  call `orgLogoFetchServeRow(` and must NOT contain an
  `org-logo.php` substring inside any `http`/`curl`/`file_get_contents(`
  fetch shape (assert: the string `org-logo.php` appears in og-image.php
  ONLY if `orgLogoFetchServeRow(` also does, and no `curl_init` /
  `file_get_contents('http` line contains it). Mutation: replace the direct
  read with a `file_get_contents('https://…/org-logo.php…')` → red.
- **(i) Brand colour goes through the ONE normaliser**: derive every file
  writing `BrandColor` (tree grep for the column name, comment-stripped) and
  assert each write site's window contains
  `ihymnsOrgBrandColourNormalise(` or `orgSetBrandColour(`; og-image's read
  side must call `ihymnsOrgBrandColourRgb(` (never an inline hex-parse
  fork). Mutation: inline a `sscanf('#%02x…')` in og-image → red.
- **(j) Print fold variant filter**: `fetchPrintOrgLogos()`'s fold window in
  print.js must reference `.variant` (the `'default'` filter). Mutation:
  remove the filter → red. (Narrow by design — it asserts the filter
  exists, not its exact spelling, per rule #34's "don't fail on correct
  code".)
- **(k) Projector + header emit `<img` + `/org-logo.php?`**: for the two new
  consumer files, assert their logo emission is an `<img` (DOM
  `createElement('img')` or literal) whose src building references
  `/org-logo.php?` — the never-inline rule's positive half (check (a)
  already bans the negative).

Every new assertion is mutation-proven before its commit lands (break → red
→ restore → green, recorded), per the guard's own established doc-block
practice.

### 8.2 Existing guards that light up automatically

- check (f) kind-registry lockstep — unchanged (no kind is added).
- `test-schema-coverage.php` — the BrandColor/BrandJson mirror.
- `test-migration-registry.php` — the new entry's real OR-probe.
- `test-deploy-paths.php` — the migration's include idiom.
- `test-print-block-registry.php` — unchanged (no new block).
- `php -l` / `node --check` sweep pre-PR (house rule).

### 8.3 Unit-style tests

- PHP: `ihymnsOrgLogoResolveThemedAsset()` truth table (every ladder step,
  darkCapableOnly skip, monochrome-never-on-dark absence, empty input);
  `ihymnsOrgBrandColourNormalise()` (3/6/8-digit, case, junk → false,
  ''/null → null) + `…Rgb()` + the YIQ helper.
- JS: `resolveThemedAsset()` mirrored truth table (same fixtures, ideally
  generated from one fixture list so the two suites can't drift).

---

## 9. Dormancy / fail-open matrix (rule #28 shape)

| Install / data state | Behaviour |
|---|---|
| `tblOrganisationLogos` not migrated | Header: `logos` absent from my_organisations → no emblem. Projector: `OrgLogoUrl` null → no bug. OG: list read gated → unbranded. Admin variant slots hidden with the whole card. |
| BrandColor migration not run | og-image column-gated read → unbranded card; admin brand field hidden (`columnExists` gate); everything else unaffected. |
| Logos exist, no variants uploaded | Ladders end at `default` → header/projector show the default emblem (today's assets); og-card shows `reversed@default` or the org-name text; print byte-identical (fold filter). |
| Variants uploaded | Theme-paired swap on header; projector prefers `@dark`; print STILL byte-identical (deliberate, §3.4). |
| Org sets BrandColor, no PNG reversed/emblem | Band + org-name text + credit — branded, never a broken mark. |
| Share link with `ShowSharerName=0` (default) | Unbranded card — privacy gate. |
| Anonymous viewer / no org membership | Header unchanged; nothing anywhere. |
| DB blip mid-render | og-image falls to its existing generic/unbranded paths; header/projector `<img onerror>` → removed. |
| `content_gating_enabled` | Irrelevant — logos and brand colour are org-published public branding (the #1830 §12(d) posture, unchanged). |

---

## 10. Commit breakdown (ONE PR, atomic, smallest-safest-first; dormant until an org uploads/sets)

1. **`feat(org-logos): themed-surface registry + pure resolver in the ONE helpers file (#1840)`**
   — §3.1/§3.2: `IHYMNS_ORG_LOGO_SURFACE_PREFS` +
   `ihymnsOrgLogoResolveThemedAsset()` + PHP truth-table test. Pure, no
   consumer, no behaviour change.
2. **`feat(org-logos): shared client module org-logo.js + print fold variant filter (#1840)`**
   — §3.3/§3.4: the JS mirror + `fetchMyOrgs()` extraction; print.js
   delegates the fetch and gains the `variant === 'default'` filter; guard
   check (g) + (j) land here, mutation-proven. **Must precede commit 3**
   (the fold fix before any variant row can exist).
3. **`feat(manage): light/dark variant upload slots on the shared logo card + variant-aware handlers (#1840)`**
   — §3.5: card strip, `variant` POST field on both pages,
   `orgLogoDeleteKindAll()` + kind-level toggle, activity-log detail,
   plain-English copy. Guard check (c) floor unchanged (same two sites).
4. **`feat(db): tblOrganisations.BrandColor + BrandJson — migration, mirror, registry, ONE normaliser (#1840)`**
   — §4.1/§4.2: migration (rule #41 idiom, per-column gates + doctags),
   byte-identical schema.sql mirror, registry OR-probe,
   `ihymnsOrgBrandColourNormalise()/…Rgb()/orgSetBrandColour()` + unit tests.
5. **`feat(manage): brand-colour field on organisations + my-organisations (#1840)`**
   — §4.3: the gated control + `brand_save` actions + logging; guard check
   (i) lands, mutation-proven.
6. **`feat(header): org emblem co-brand — header-branding.js + hairline CSS (#1840)`**
   — §5: the module (EVT_AUTH_CHANGED + data-bs-theme MutationObserver,
   teardown-first injection), app.css rules, app.js boot line; guard check
   (k) half 1.
7. **`feat(projector): corner bug on service-projection — server-resolved URL, toggle, localStorage default-on (#1840)`**
   — §6: VENUES `OrgLogoUrl`, overlay img + CSS, setup checkbox + console
   toggle, teardown; guard check (k) half 2.
8. **`feat(og-image): branded setlist share card — brand band + reversed PNG logo + via-iHymns credit (#1840)`**
   — §7: share-row org resolution, ShowSharerName gate, PNG filter, direct
   bytes read, band/contrast/name-fallback render, drawBranding skip; guard
   check (h), mutation-proven.
9. **`test(org-logos): consolidate surface-guard extensions + recorded mutation runs (#1840)`**
   — the guard's "finish" pass: re-verify (g)–(k) end-to-end, record every
   mutation in the doc-block (the file's own established practice).
10. **`docs(#1840): wiki, CHANGELOG, help copy, .claude context, rule #42 update`**
    — CLAUDE.md rule #42's "Variant … dormant" and "scope is print only"
    sentences updated (that rule has a documented history of going stale —
    update it in the SAME PR); `.claude/ProjectBrief.md`; this plan marked
    implemented; admin card copy note re PNG-for-share-cards (§B.6).

Pre-PR audit per house rules (php -l / node --check sweep, security + a11y
pass). PR targets `alpha`. Every commit leaves the tree green and the
feature dormant-safe if the PR stopped there.

---

## §A. Adversarial review (attack/failure table)

| Concern | Analysis + mitigation (all encoded above) |
|---|---|
| **Theme mismatch at first paint** | The shell hardcodes `data-bs-theme="light"`; settings.js flips it during boot. The header module resolves AFTER its async org fetch (theme long since applied) AND observes attribute mutations — so a boot-order race at worst shows the light-resolved emblem for one observer tick, then swaps. No FOUC-class hardcoding is introduced (the #953/#955 regression stays dead). |
| **Missing variant / missing kind** | Every ladder ends at `(K,'default')` (except darkCapableOnly, which ends at the org-name text on og-card, or nothing elsewhere) — absence always renders as absence or a documented degradation, never a broken image, never an undocumented kind substitution (rule #42's print-block explicit-kind ban is untouched; the surface ladders live in the ONE registry, which is what makes them "documented resolution", not substitution). |
| **Variant activation silently re-brands PRINT** | The latent `byKind` last-row-wins fold (§3.4) is fixed in commit 2, structurally BEFORE the UI that can mint variant rows (commit 3). Ordering is stated as load-bearing in both commits' messages. |
| **Brand-colour injection** | Strict allowlist normalise at every write (`false` = reject, nothing stored); stored canonical lowercase hex; consumed only as GD ints + `htmlspecialchars()`'d form echo; never concatenated into CSS/HTML/SQL (bound param). VARCHAR(9) caps the row even against a bypassed app layer. Guard (i) bans a second parse fork. |
| **Header org identity (multi-tenant reality)** | There is NO single tenant — orgs are churches, membership is many-to-many (§1.2). Default: first org by the API's existing Name-ASC order with a resolvable header asset — deterministic, matches print's established "first org with a logo" precedent. Multi-org members flagged §B.1. |
| **Forged org branding on share cards** | No `&org=` param exists; the org derives server-side from the share row's owner. A crafted og-image URL can only ever brand a card with the branding its OWN share row legitimately resolves to. |
| **Share-card privacy (affiliation leak)** | A church-branded card outs the sharer's affiliation harder than a name line. Gated on the existing per-link `ShowSharerName` consent (default off) — §7.2, flagged §B.4. |
| **SVG logos on the GD canvas** | GD cannot rasterise SVG; og-card resolution filters to `Mime='image/png'` rows and degrades to org-name text. Admin copy nudges orgs to add a PNG (§B.6). No server-side SVG rasteriser is introduced (shared-host reality; and rasterising sanitised SVG server-side would be new attack surface for one surface's nicety). |
| **Projector bug obscures content** | Today the overlay shows a join code (verified — no lyric slides); the bug sits top-left, the one always-free corner, 8vmin max, 35% opacity, `pointer-events:none`, and behind an operator toggle. If the overlay (or present-mode, §B.5) ever gains slides, the same safe-margin/low-opacity/toggle spec transfers; the toggle is the operator's ultimate answer to "it's in the way". |
| **Projector `<img>` failure on a venue screen** | `error` → hide; `load` → show. A 404/503 from org-logo.php can never paint a broken-image glyph on a wall. |
| **Cache staleness** | org-logo.php URLs always carry the resolved row's `&v=` sha → immutable-cache correct per rendition; a deleted-variant race between meta fetch and img load falls to org-logo.php's own default-variant fallback with a soft (1h) cache. og-image lags ≤24h on colour/logo edits (documented; platform caches dominate anyway). |
| **Guard under-report on the NEW files** | The tree-derived `$mentioning` fingerprints (camelCase + constant families) auto-capture every new file — verified against the guard's own recorded near-miss history (its doc-block L14–26). New checks (g)–(k) each carry a floor or a paired-halves shape and a recorded mutation, per rule #34. |
| **Orphan variant rows** | Default-removal cascades (`orgLogoDeleteKindAll()`), kind-level visibility toggle, and variants-require-default card layout together make "a rendition exists that no UI shows and one theme silently renders" unrepresentable through the UI; a hand-crafted orphan degrades harmlessly (resolves on one theme, nothing on the other). |
| **`my_organisations` payload growth** | Variants multiply `logos` rows (≤30/org, meta-only) — negligible; the emit already carries `variant` so the API contract is unchanged (additive rows, not shape). |
| **Rule #43/#44 audit** | No free-text-into-registry field (a colour is a value, not a registry reference); no derivable field collected (the colour cannot be derived); BrandJson renders NO form control (dormant schema, not a vanity field). |

---

## §B. Owner sub-decisions surfaced by this design (none block the build)

Presented per the house decision shape; each has a defensible default the
plan builds on, changeable cheaply.

### B.1 Header org for multi-org members

- **Decision**: which church co-brands the header for a user belonging to
  several orgs.
- **Why**: product call — no "primary org" concept exists anywhere in the
  schema or API.
- **Options**: (a) first org, API Name-ASC order, with a resolvable emblem —
  zero schema, deterministic, matches print's precedent; (b) a per-user
  "primary organisation" picker — new column + settings UI + API; (c) show
  nothing when membership >1 — punishes the common single-org case's code
  path for a rare ambiguity.
- **Recommendation: (a)**, filing (b) as a `for consideration` follow-up
  issue to be built only if real multi-org members ask. **Blocks nothing.**

### B.2 Projector toggle default

- **Decision**: corner bug default ON or OFF per projection device.
- **Recommendation: ON** (uploading a resolvable logo is already the org's
  opt-in; default-OFF is the undiscovered-feature failure class; the off
  switch is one click, remembered per device in localStorage). §6.4 carries
  the full justification. **Blocks nothing.**

### B.3 Missing-variant fallback shape

- **Decision**: when a theme-paired rendition is missing, fall back to the
  same kind's default rendition, or substitute the `reversed`/`monochrome`
  kind.
- **Recommendation (built)**: same-kind `default` on the header (a reversed
  lockup's shape is wrong for a 28px slot); the `reversed` KIND participates
  only where the surface registry lists it (projector, og-card);
  `monochrome` appears on NO dark-ground list ("usually black" — invisible
  on dark, and we never tint stored bytes). **Blocks nothing.**

### B.4 Share-card branding scope + consent

- **Decision**: (i) accept that org branding structurally applies to
  **setlist cards only** (song/songbook og:image URLs carry no sharer
  identity — §7.1); (ii) gate it on the existing per-link `ShowSharerName`
  consent (default off).
- **Why**: (i) is physics of how og:image URLs work; (ii) is a privacy call
  — a church-branded card reveals affiliation.
- **Options for (ii)**: gate on ShowSharerName (recommended — reuses the one
  consent already collected, rule #44); always brand when the org has
  BrandColor (simpler, leaks affiliation on "anonymous" shares); a third
  per-link "show my church" flag (a new field for a distinction most users
  won't grasp).
- **Recommendation: ShowSharerName gate.** Smallest reply that unblocks a
  different choice: "always brand" or "new flag". **Blocks nothing** (the
  gate is one condition).

### B.5 "Over every slide" — the projector surface identity

- **Decision**: the locked instruction anchors
  `manage/service-projection.php`, whose overlay shows the **join code**,
  not lyric slides; the phrase "over every slide" better matches
  `js/modules/present-mode.js` (the public song page's full-screen Present
  overlay — the thing a church actually projects lyrics with).
- **Recommendation**: ship the service-projection bug now as locked (it IS
  the org-context screen, and the spec transfers verbatim), and file a
  follow-up for present-mode (client-side org resolution identical to the
  header's — `fetchMyOrgs()` + the `projector` surface prefs — plus the same
  localStorage toggle). Smallest reply: "projection only" or "both".
  **Blocks nothing** for this PR.

### B.6 PNG nudge for share cards

- The og-card can only composite PNG rows (GD). Recommendation: one sentence
  added to the admin card's helper copy — *"For share-preview images, add a
  PNG version of your light-on-dark logo (SVG can't be used there)."* Flag
  only; trivially changeable. **Blocks nothing.**
