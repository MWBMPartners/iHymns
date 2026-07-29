# Setlists & Arrangements

> Create worship setlists with custom song arrangements

---

## Overview

Setlists (playlists) allow users to organise songs for worship services. Each setlist can contain up to 200 songs, and each song can have a custom arrangement that controls the order in which song components are performed.

---

## Creating a Setlist

1. Navigate to any song page
2. Click the **"Add to Set List"** button
3. Choose an existing setlist or create a new one
4. The song is added to the setlist

Alternatively, navigate to `/setlist` to manage all setlists.

---

## Custom Song Arrangements

Each song in a setlist can have a custom arrangement that overrides the default component order. This is modelled after **ProPresenter 7**'s arrangement system.

### Arrangement Editor

Click the **"Arrange"** button on a song within a setlist to open the arrangement editor.

The editor has three sections:

1. **Component Pool** — shows all available components for the song (e.g., V1, V2, C, B). Click a chip to add it to the arrangement.

2. **Arrangement Strip** — shows the current custom order. Drag chips to reorder, click the x to remove.

3. **Live Preview** — shows the lyrics in the current arrangement order, updating in real-time as you edit.

### Quick Actions

| Action | Description |
|---|---|
| **Auto-generate** | Creates a standard arrangement (e.g., V1, C, V2, C, V3, C) |
| **Sequential** | Resets to the original component order (V1, V2, V3, C, etc.) |
| **Song Default** | Removes the custom arrangement, uses the song's original order |

### Component Tags

Each component type has a short tag and colour:

| Tag | Type | Colour |
|---|---|---|
| V1, V2... | Verse | Blue |
| C, C1... | Chorus | Amber |
| R | Refrain | Amber |
| PC, PC1... | Pre-Chorus | Pink |
| B, B1... | Bridge | Purple |
| I | Intro | Green |
| O | Outro | Red |
| IL | Interlude | Cyan |
| T | Tag | Grey |
| CD | Coda | Grey |
| VP | Vamp | Orange |
| AL | Ad-lib | Lime |

### How Arrangements Work

Arrangements are stored as arrays of component indices. For example, if a song has:
- Index 0: Verse 1
- Index 1: Chorus
- Index 2: Verse 2
- Index 3: Bridge

An arrangement of `[0, 1, 2, 1, 3, 1]` would produce: V1, C, V2, C, B, C.

---

## Sharing Setlists

Click the **"Share"** button on a setlist to generate a shareable link. The link includes:
- Setlist name
- Song IDs
- Custom arrangements (if any)

Recipients can import the shared setlist via the link. Arrangements are preserved in the shared data.

---

## Playback (Setlist Navigation Mode)

Tapping any song within a setlist — the user's own, or one opened via a share link — arms **playback
mode** for that list (#1533). The song order is captured into a *playlist context* rather than looked
up by setlist id, which is what makes a **shared** setlist navigable at all: a shared list has no
local record on the viewer's device, so an id-based lookup (`getById()`) previously returned `null`
and shared setlists could not be navigated by any route.

### Storage

The active context lives in `sessionStorage` (`STORAGE_PLAYLIST_CONTEXT` in `js/constants.js`), not
`localStorage` — deliberately scoped to the browser tab and session, so it survives a reload but not
closing the tab, and never leaks between tabs:

```json
{
  "songIds": ["MP-0001", "MP-0002"],
  "titles": { "MP-0001": "Amazing Grace" },
  "name": "Sunday Service",
  "source": "own",
  "sourceId": "<setlist id, or share id for a shared list>"
}
```

### UI

A fixed navigation bar (`#setlist-song-nav`, `.playlist-bar`) is appended to `<body>` whenever the
current song is part of the active context — `SetList.renderSongNavigation()`, called by the router
after every page load:

- **Prev** / **Next** — real `<button disabled>` elements at the ends of the list (not hrefless
  links, so assistive tech announces the disabled state)
- Position indicator ("3 of 12")
- The next song's title on the Next button, once known
- An explicit exit control, which clears the context
- An `aria-live="polite"` announcement on every song change — the SPA swaps page content without a
  navigation event, so nothing announces the new song to a screen reader otherwise

`ArrowLeft` / `ArrowRight` mirror Prev/Next. The listener is bound once at `SetList` construction
(not per bar render) and yields to text entry, `[role="combobox"]`/`[role="listbox"]`/`[role="slider"]`
/`[role="tablist"]`, and any `Alt`/`Ctrl`/`Meta` combo.

### Implementation note — the fixed-bar teardown trap

Because the bar is `position: fixed` on `<body>` rather than in-flow page content, it is **not**
removed automatically when the router swaps the page fragment for a different route. Getting this
wrong strands the bar over whatever page the user navigates to next (e.g. Home). The fix has two
required halves: `renderSongNavigation()` removes any existing bar **unconditionally, as its first
statement**, before any early return; and `router.js` calls it on **every** navigation, not only
`page=song`. `body.has-playlist-bar` reserves matching bottom padding so the bar never covers the
final lyric line. `tests/test-setlist-playback.js` pins both halves structurally.

The pre-#1533 `activeSetListId` field (set via a setlist's "Use" button) survives only because
`applyCustomArrangement()` still reads it — the playlist context is now the primary source
`getNavigation()` checks first, with `activeSetListId` as its fallback.

---

## Cross-Device Sync

Logged-in users can sync setlists across all their devices:

1. **Sign in** on each device (PWA, iOS, Android)
2. **Sync** — local setlists are merged with server-side storage
3. **Automatic** — sync happens on login and can be triggered manually via the header menu

### Merge Strategy

- New setlists (by ID) are inserted on the server
- Existing setlists are updated if the local version is newer
- Server-only setlists are preserved and returned to the client
- Maximum 50 setlists per user, 200 songs per setlist

### Storage Locations

| State | Storage |
|---|---|
| Anonymous (not logged in) | Browser localStorage only |
| Logged in (PWA) | localStorage + server sync |
| Logged in (iOS) | Local storage + server sync |
| Logged in (Android) | Local storage + server sync |
