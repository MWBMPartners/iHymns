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
| Admin amber hover | Dark amber | `#d97706` |
| Admin background | Deep navy | `#1a1a2e` |
| Admin surface | Dark navy | `#16213e` |

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

| Role | Colour | Hex |
|---|---|---|
| Global Admin | Red | `#dc2626` |
| Admin | Amber | `#f59e0b` |
| Editor | Blue | `#3b82f6` |
| User | Grey | `#6b7280` |

---

## Typography

- **Body font:** System font stack (Bootstrap default)
- **Monospace:** System monospace stack (for code, song numbers)
- **Lyrics font size:** User-adjustable (14px–28px, default 18px)
- **Line height:** 1.6 for lyrics readability

---

## Accessibility

### Colour Contrast
- All text meets **WCAG 2.1 AA** minimum contrast ratios (4.5:1 for normal text, 3:1 for large text)
- Songbook badge text contrast is calculated automatically using relative luminance
- Badge colours checked against both light and dark backgrounds

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
