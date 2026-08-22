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

## Sharing Setlists (#1790 playlist-first + #1791 collab-by-link)

The **Share** icon on a setlist's detail page opens a Share dialog offering three distinct, combinable
grant models — server-side capability rows in `tblSharedSetlists` (view/edit scope) plus the
pre-existing `tblSetlistCollaborators` email-invite table:

| Model | Recipient needs an account? | Can the recipient edit? | Mint path |
|---|---|---|---|
| **View link** | No | No — read-only, tap-to-play | `setlist_share` (`scope:"view"`, default) |
| **Edit link** | No, unless the owner (or their org) requires it | Yes — reorder/remove songs | `setlist_share` (`scope:"edit"`) |
| **Email invite (Collaborators)** | Yes — an existing iHymns account | View or edit, chosen per invite | `setlist_collab_invite` |

A **view link** is a short URL (legacy 8-hex, or a 22-char/128-bit token for new links) that anyone can
open — no account required. Recipients land on a read-only, playlist-first page (#1790): tapping any
song, or the **Start set list** button, arms the Prev/Next playback bar; the page no longer leads with
"Import it to use it" — importing is demoted to a single secondary **Save a copy** button. A **live**
link (minted by a signed-in owner from one of their saved setlists) re-resolves the owner's current list
on every open, so later edits reach every link holder automatically.

An **edit link** is a 43-char/256-bit capability token minted only by a signed-in owner from one of
their own live-linked setlists. Per link, the owner chooses:
- **Who can edit** — *anyone with the link* (the default) or *people signed in to iHymns*. An
  organisation may set a preference or a hard requirement for its members' edit links (three-layer
  app → org → user precedence, `SetlistEditAudience`/`EnforceSetlistEditAudience` on
  `tblOrganisations`, mirroring the #1770 Live Follow idle-timeout resolver) — the server may clamp a
  requested "anyone" link to "signed-in required" and the dialog reflects whatever was actually stored,
  never assuming the request was honoured verbatim.
- **Show my name on the shared page** — an opt-in "Shared by &lt;name&gt;" byline (`ShowSharerName`),
  off by default.

On the public shared page, when the server reports `canWrite:true` for the link, the song list becomes
an editable staged-copy surface: reorder/remove buttons push immediately to `setlist_token_update`
(optimistic UI, aria-live save status, reverts to the last-known-good order on a refused write). If the
link requires sign-in and the viewer isn't signed in, the server answers `401 {reason:"signin_required"}`
and the client shows a sign-in prompt instead of the editor. The Share dialog's **Active links** list
(`setlist_share_list`) shows every link minted for a setlist — scope, label, created/last-used,
view/edit counts — with a per-link **Revoke** (`setlist_share_revoke`, hard, immediate).

The row-move/remove markup and wiring are ONE shared pair of client helpers
(`sharedSetlistRowsHtml()` / `bindSharedSetlistRowControls()` in `js/modules/setlist.js`), consumed by
both the anonymous/token edit surface above and the pre-existing signed-in **email-invite Collaborators**
detail view below — not two forks of the same row template. Since **#1802** these surfaces also carry an
**Add a song** search box (a third shared helper, `mountSetlistAddSongPicker()`): an accessible combobox
over the public `?action=search` read that appends a picked song through the same write path — no new
endpoint, nothing minted (a set-list entry only references a song).

Both link types carry custom arrangements (if any) in the shared payload/live setlist.

### Sync, conflicts & the song cap (#1662 / #1675 / #1660)

- **The 200-song cap is a hard limit, not a silent trim.** A set list pushed with more than 200 songs is
  refused with **HTTP 413** (`reason:"too_many_songs"`, `maxSongs`) and nothing is stored — the old
  behaviour silently kept the first 200 and dropped the tail. (Legacy native apps still truncate until
  they adopt sync protocol 2, #1923.)
- **Multi-device sync is conflict-safe.** `user_setlists_sync` no longer blindly overwrites: a client that
  sends a `since` watermark and pushes a row that was edited on **another device** since it last synced is
  **refused for that row** (the server keeps the newer copy and lists it in a `conflicts[]` array). The
  client takes the server's version, shows a "updated on another device — your last change wasn't applied"
  toast, and the user re-applies the edit. An identical push is a no-op (it does not bump the row's
  timestamp), so routine syncs never manufacture false conflicts.

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

## Printing & PDF (#1767 / #1789 / #302)

A set list can be put on paper or saved as a PDF without any projection software:

- **Print** renders the set list through the **same** print-template engine as a single song
  (#1789, `83d45a68` — set lists reuse the ONE body renderer; there is no second renderer). Screen-only
  chrome (the playback bar, nav) is stripped from the printout.
- **Save as PDF** (#302) goes through the browser's own print dialog.
- **Download PDF** (#1767 remainder P6) — for a signed-in user — renders the **whole list as one PDF**
  server-side: a cover page, the running order, then each song's words in order. When the org holds a
  CCLI licence, a **single** copies prompt covers the whole set, logged to the org's CCLI report, and
  the CCL notice is added to the footer automatically.

The one-renderer invariant (browser Print, single-song server PDF, whole-set-list PDF, and the admin
live preview all flow through the same body renderer) is CI-guarded — see [[Architecture]] § Print
pipeline.

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
