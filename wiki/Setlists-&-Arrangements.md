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
