# Design

> Colour scheme, theming, and visual design guidelines

---

## Colour Scheme

iHymns uses a clean, neutral slate/grey palette — professional and easy on the eyes. No bright or vivid colours on songbook cards.

### Core Palette

| Element | Colour | Hex |
|---|---|---|
| Navbar | Solid dark slate | `#1e293b` |
| Accent | Muted teal | `#0d9488` |
| Songbook cards | Soft grey gradient | (all same, no rainbow) |
| Dark mode background | Charcoal blue | `#0f172a` |
| Text (light mode) | Dark slate | `#1e293b` |
| Text (dark mode) | Light slate | `#e2e8f0` |
| Muted text | Slate grey | `#94a3b8` |
| Admin amber | Warm amber | `#f59e0b` |

**The admin area has no palette of its own.** It reads the same theme-aware custom properties as the
public site — `--surface-bg`, `--surface-card` and the rest — resolved at runtime by
`manage/includes/admin-theme-init.php` from whichever theme the person has chosen (#955). So there
is no fixed admin background or surface colour to quote: it is whatever the active theme says.

> **Corrected 2026-09-08.** Three rows were removed from this table: Admin amber hover `#d97706`,
> Admin background `#1a1a2e` and Admin surface `#16213e`. **None of those three hex values appears
> anywhere under `appWeb/`.** They describe a hardcoded navy admin palette that the admin area
> stopped having when #955 made it theme-aware.

### Theme Modes

| Mode | Description |
|---|---|
| Light | Default — white background, dark text |
| Dark | Charcoal blue `#0f172a` background, light text |
| High Contrast | Maximum contrast for visual accessibility |
| System | Follows OS `prefers-color-scheme` preference |

### Colourblind Mode

CVD-safe palette based on Wong 2011:
- Uses colours distinguishable by people with protanopia, deuteranopia, and tritanopia
- Applied automatically when enabled in settings
- Affects songbook badges, component tags, and UI accents

---

## Component Tag Colours

Used in the arrangement editor and song display:

| Component | Colour | Hex |
|---|---|---|
| Verse | Blue | `#3b82f6` |
| Chorus | Amber | `#f59e0b` |
| Pre-Chorus | Pink | `#ec4899` |
| Bridge | Purple | `#8b5cf6` |
| Tag | Grey | `#6b7280` |
| Coda | Grey | `#6b7280` |
| Intro | Green | `#10b981` |
| Outro | Red | `#ef4444` |
| Interlude | Cyan | `#06b6d4` |
| Vamp | Orange | `#f97316` |
| Ad-lib | Lime | `#84cc16` |

---

## Role Badge Colours (Admin Panel)

Role badges use Bootstrap's own utility classes, not literal hex values — so they follow the active
theme automatically. From `manage/users.php`:

| Role | Class |
|---|---|
| Global Admin | `bg-danger` |
| Admin | `bg-warning text-dark` |
| Editor | `bg-primary` |
| User (the fallback) | `bg-secondary` |

Use the class, not a colour. A hardcoded hex will not match the rendered badge, and will not follow a
theme change.

> **Corrected 2026-09-08.** This table used to give four hex values. One of them, `#3b82f6`, does
> exist in the codebase — but as the **Verse** component colour in `js/utils/components.js`, nothing
> to do with role badges. (The Component Tag Colours table above it was checked at the same time and
> all eleven entries are correct.)

---

## Typography

- **Body font:** System font stack (Bootstrap default)
- **Monospace:** System monospace stack (for code, song numbers)
- **Lyrics font size:** User-adjustable (14px–28px, default 18px)
- **Line height:** 1.6 for lyrics readability

---

## Accessibility

### Colour Contrast
- **Target:** all text meets **WCAG 2.1 AA** minimum contrast ratios (4.5:1 normal, 3:1 large).
  ⚠️ This is the design INTENT and, since #1151, is believed true — but it has **never been
  measured in a real browser render**. Every ratio quoted on this page (and every ratio the CI
  guard below checks) is derived from the CSS custom-property cascade, which models the spec
  faithfully yet cannot account for `backdrop-filter`, shadow layering or renderer differences.
  No axe, WAVE or devtools contrast pass has been run. #1150 / #1151 stay open for exactly that
  measurement. **This bullet previously stated the compliance flatly, while High Contrast mode
  was in fact producing ~1.44:1** — a mistake the 2026-08-30 audit (epic #2027) repeated the
  pattern of finding elsewhere: `.btn-info` and the opt-in "Emphasise Links" mode's colours both
  carried a doc-comment claiming a contrast level nobody had actually computed, and both failed
  once someone did the maths (2.89:1 and 4.47:1/3.27:1 respectively, against a claimed 4.5:1+).
  Both are now fixed, and a new CI guard (`tests/test-contrast-registry.js`) recomputes WCAG
  contrast for a registry of colour pairs from the LIVE token values on every run, specifically so
  a claimed number can never again go unchecked — still the same CSS-cascade method as this whole
  section, so the "never measured in a real browser" caveat above still stands.
- Songbook badge text contrast is calculated automatically using relative luminance
- Badge colours checked against both light and dark backgrounds
- High Contrast mode (`data-ihymns-contrast="high"`) redefines both Bootstrap's own `--bs-*` tokens AND the app's parallel `--text-primary` / `--text-secondary` / `--text-muted` / `--glass-bg` / `--card-border` custom properties, so a component reading the app's own tokens directly (the bottom nav, header icons, footer) can't silently fall through to its ordinary light-theme values while High Contrast is on. That gap was a real regression (#1151): the bottom nav computed to ~1.8:1 and the header icons to ~1.44:1 — both well under AA — specifically *because* High Contrast mode was switched on, in the one mode whose entire purpose is legibility.

### Keyboard Alternatives to Drag
- Card layout reorder (the admin dashboard and the home page's card order) has a keyboard/switch alternative to dragging — Move up / Move down buttons alongside the drag handle — satisfying WCAG 2.2 SC 2.5.7 (Dragging Movements) (#1151)

### Focus Indicators
- Visible focus outlines on all interactive elements
- Custom focus ring colour matching the accent palette
- Tab order follows logical reading order

### Reduced Motion
- All animations respect `prefers-reduced-motion: reduce`
- Page transitions disabled when reduced motion is active
- Toggle available in Settings

### Reduced Transparency
- Background blur and opacity effects removed when `prefers-reduced-transparency: reduce` is active

---

## Responsive Breakpoints

Following Bootstrap 5.3 breakpoints:

| Breakpoint | Width | Layout |
|---|---|---|
| xs | < 576px | Single column, compact header |
| sm | >= 576px | Slightly wider content |
| md | >= 768px | Two-column where appropriate |
| lg | >= 992px | Full sidebar navigation |
| xl | >= 1200px | Wide content area |
| xxl | >= 1400px | Maximum content width |

---

## Icons

- **Icon library:** Font Awesome 6 (Free, solid style)
- **Admin panel:** Bootstrap Icons
- **PWA icons:** Generated from `favicon.svg` (48px–512px PNGs)
- **Favicon:** SVG with teal accent colour
