/**
 * iHymns — Shared Constants
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Centralised localStorage key names and other shared constants.
 * All modules import keys from here to prevent key-name drift (#139).
 */

/* ── localStorage Keys ─────────────────────────────────────────────────── */

/* Core data */
export const STORAGE_FAVORITES      = 'ihymns_favorites';
export const STORAGE_SETLISTS       = 'ihymns_setlists';
export const STORAGE_HISTORY        = 'ihymns_history';
export const STORAGE_SEARCH_HISTORY = 'ihymns_search_history';
export const STORAGE_CUSTOM_TAGS    = 'ihymns_custom_tags';
export const STORAGE_OWNER_ID       = 'ihymns_owner_id';

/* Per-device flag: set once the local recently-viewed history has been
   pushed to the server (tblSongHistory) for the signed-in account, so the
   one-time backfill doesn't re-stack rows on every login (WS-G #1019). */
export const STORAGE_HISTORY_BACKFILLED = 'ihymns_history_backfilled';

/* User preferences */
export const STORAGE_THEME              = 'ihymns_theme';
export const STORAGE_FONT_SIZE          = 'ihymns_fontSize';
export const STORAGE_REDUCE_MOTION      = 'ihymns_reduceMotion';
export const STORAGE_REDUCE_TRANSPARENCY = 'ihymns_reduceTransparency';
export const STORAGE_TRANSITION         = 'ihymns_transition';
export const STORAGE_DEFAULT_SONGBOOK   = 'ihymns_default_songbook';
export const STORAGE_AUTO_UPDATE_SONGS  = 'ihymns_auto_update_songs';
export const STORAGE_NUMPAD_LIVE_SEARCH = 'ihymns_numpad_live_search';
export const STORAGE_SEARCH_LYRICS      = 'ihymns_search_lyrics';
export const STORAGE_DISPLAY            = 'ihymns_display';
/* NOTE the odd value: this key predates the `ihymns_` convention and is
   already written into real users' browsers, so renaming it would silently
   reset everyone's language filter. The CONSTANT is what new code must use —
   it was a raw literal in three separate modules before #1031, which is the
   same drift class as the raw event names #1581 banned. */
export const STORAGE_LANGUAGE_FILTER    = 'songbook-language-filter';
/* Playlist context for setlist playback (#1533).
   sessionStorage, NOT localStorage — deliberately. "I am currently working
   through this setlist" is a property of THIS TAB's browsing session, not a
   durable preference. localStorage would silently resurrect a service from
   last Sunday the next time the user opened a song, and would leak the
   context between tabs where someone is comparing two lists. */
export const STORAGE_PLAYLIST_CONTEXT   = 'ihymns_playlist_context';

/* Shared set-list id grammar (#1791) — the CLIENT mirror of PHP
   sharedSetlistSafeShareId()'s `^[A-Za-z0-9_-]{6,64}$`. Matches a server
   share-id (legacy 8-hex OR a base64url capability token) and, crucially,
   does NOT match a legacy base64 blob (those carry `+`/`/`/`=` and run far
   longer than 64 chars) — so the shared page still routes an old inline-payload
   link to parseLegacySharedSetlist(). Kept in sync with the PHP fold by the C6
   guard (tests/php/test-setlist-share-tokens.php), not by this comment. */
export const SHARE_ID_RE = /^[A-Za-z0-9_-]{6,64}$/;

/* Status & consent */
export const STORAGE_ANALYTICS_CONSENT  = 'ihymns_analytics_consent';
export const STORAGE_ANALYTICS_DEBUG    = 'ihymns_analytics_debug';
export const STORAGE_DISCLAIMER_ACCEPTED = 'ihymns_disclaimer_accepted';
export const STORAGE_PWA_BANNER_DISMISSED = 'ihymns_pwa_banner_dismissed';

export const STORAGE_RECENT_SONGBOOKS = 'ihymns_recent_songbooks';

/* Auth (cross-subdomain SPA session token + cached user object) */
export const STORAGE_AUTH_TOKEN         = 'ihymns_auth_token';
export const STORAGE_AUTH_USER          = 'ihymns_auth_user';

/* Sync watermarks (#1649) — the DB-clock reading returned by the last
   SUCCESSFULLY-ABSORBED sync response, echoed back to the server as `since`
   on the next sync. The server refuses to delete rows written after it, which
   is what stops a stale device wiping another device's newer additions.
   Written ONLY by _absorbSetlistSync() / _absorbFavoritesSync() in
   user-auth.js — advancing one of these without also absorbing the returned
   list would mark unseen rows as "seen" and delete them a cycle later. */
export const STORAGE_SETLISTS_SYNCED_AT  = 'ihymns_setlists_synced_at';
export const STORAGE_FAVORITES_SYNCED_AT = 'ihymns_favorites_synced_at';

/* Pending explicit set-list deletions (#1661) — the ids this device has
   deleted but not yet had acknowledged by a successful sync.
   PERSISTED, not in-memory, and that is the whole point: a deletion made
   offline, or one made a millisecond before the tab is closed, must still be
   announced. Under sync protocol 2 the server no longer infers deletion from
   a set list being absent from the payload, so if this queue were lost the
   deletion would simply never reach the server — and the next reconcile would
   hand the set list straight back. Cleared only by the ids the server has
   actually accepted (see UserAuth.syncSetlists). */
export const STORAGE_SETLISTS_DELETED    = 'ihymns_setlists_deleted';

/* Accessibility — Colour Vision Deficiency mode (#319) */
export const STORAGE_CVD_MODE           = 'ihymns_cvd_mode';

/* Offline downloads — whether to also cache MIDI audio when bulk-saving songs.
   CANONICAL key, value '1' | '0'. */
export const STORAGE_OFFLINE_INCLUDE_AUDIO = 'ihymns_offline_include_audio';

/* The SAME preference under the key `Settings.set('includeAudioOffline', …)`
   derives from its `ihymns_` prefix, value 'true' | 'false' (#1597 RC7).
   ELI5: the setting accidentally ended up written down in two places with two
   different spellings, and the two halves of the app each read a different one.
   Detail: Settings has always been the writer via its prefix-derived key,
   while offline-ui.js read the canonical key above — so "include audio in
   offline downloads" was inert for tile downloads from the day it shipped.
   Settings now mirror-writes the canonical key; this legacy name is declared
   here (rather than hard-coded in the reader) so the pair is visible in the
   ONE place key names live (#139), and is still read so an existing choice
   survives the upgrade. Remove once Settings no longer writes it. */
export const STORAGE_OFFLINE_INCLUDE_AUDIO_LEGACY = 'ihymns_includeAudioOffline';

/* Dynamic key prefix (appended with song ID) */
export const STORAGE_TRANSPOSE_PREFIX   = 'ihymns_transpose_';

/* ── Service-worker cache bucket names (#1597) ────────────────────────────
   ELI5: the names of the boxes the offline downloads live in, written down
   once so the app and the service worker can never disagree about them.

   Detail: `service-worker.js.php` is a CLASSIC worker generated by PHP — it
   cannot `import` this module, so it declares the same names itself. These
   exports are the CLIENT-side half of that contract; the two must be kept in
   step by hand, and they are listed together here so the pairing is obvious to
   the next person who adds a bucket. This is the same drift-prevention job
   this file already does for localStorage keys (#139).

   CACHE_SONGS_SAVED  — deliberately downloaded songs. NEVER trimmed. Shrinks
                        only when the user removes a songbook (#1597 RC1).
   CACHE_SONGS_RECENT — incidental song views. Capped and trimmed oldest-first.
   CACHE_MEDIA        — audio + sheet music. Historical camelCase name kept
                        deliberately: renaming it would discard every user's
                        already-downloaded audio (#1597 RC2). */
export const CACHE_SONGS_SAVED  = 'ihymns-saved-songs';
export const CACHE_SONGS_RECENT = 'ihymns-recent-songs';
export const CACHE_MEDIA        = 'iHymns-media-v1';

/* ── Custom DOM event names (#1581) ──────────────────────────────────────
   ELI5: every custom event the app fires has its name written down once,
   here, so two files can never disagree about it.
   Detail: DOM event types are CASE-SENSITIVE, so `ihymns:x` and `iHymns:x`
   are different events entirely — a casing typo is a silent no-op with no
   error. That is exactly what broke the Settings language filter → Song of
   the Day refresh. Canonical prefix is lowercase `ihymns:` (8 of the 10
   pre-existing names already used it).
   https://developer.mozilla.org/docs/Web/API/EventTarget/addEventListener */
export const EVT_AUTH_CHANGED             = 'ihymns:auth-changed';
export const EVT_REFRESH_REQUESTED        = 'ihymns:refresh-requested';
export const EVT_REFRESH_COMPLETE         = 'ihymns:refresh-complete';
export const EVT_FETCH_FAILED             = 'ihymns:fetch-failed';
export const EVT_FETCH_SUCCEEDED          = 'ihymns:fetch-succeeded';
export const EVT_LANGUAGE_FILTER_CHANGED  = 'ihymns:language-filter-changed';
export const EVT_OFFLINE_SETTINGS_CHANGED = 'ihymns:offline-settings-changed';

/* ── Songbook Name Lookup ─────────────────────────────────────────────── */

/**
 * Canonical mapping of songbook abbreviation → full display name.
 * Used by songbookLabel() to render responsive names across the app.
 */
export const SONGBOOK_NAMES = {
    CP:   'Carol Praise',
    JP:   'Junior Praise',
    MP:   'Mission Praise',
    SDAH: 'Seventh-day Adventist Hymnal',
    CH:   'The Church Hymnal',
    Misc: 'Miscellaneous',
};

/* Local HTML escaper — kept inline to keep constants.js DOM-free and
   avoid pulling utils/html.js into the dependency graph. The output of
   songbookLabel is interpolated into innerHTML across many modules, so
   any caller-supplied abbr/fullName must be escaped here at the source
   to satisfy CodeQL's "DOM text reinterpreted as HTML" rule and stay
   safe even when callers forget to escape. */
const _ESC_MAP = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
function _escSongbook(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => _ESC_MAP[c]);
}

/**
 * Return responsive songbook label HTML showing full name by default
 * and abbreviation on narrow screens. Both are always present in the
 * DOM; CSS toggles visibility based on viewport width.
 *
 * @param {string} abbr  Songbook abbreviation (e.g. 'MP')
 * @param {string} [fullName]  Optional override for full name
 * @returns {string} HTML with both spans
 */
export function songbookLabel(abbr, fullName) {
    /* #1531 — resolve order: an explicit override → the client songbook
       registry (covers EVERY songbook, loaded once from /api?action=songbooks)
       → the six hardcoded fallbacks → the abbreviation. This is what makes
       list views (setlists, favourites, search, home) show the full Songbook
       NAME rather than the abbreviation, for all songbooks not just the six. */
    const full = fullName || songbookFullName(abbr) || SONGBOOK_NAMES[abbr] || abbr;
    const safeAbbr = _escSongbook(abbr);
    if (full === abbr) return safeAbbr; /* no full name available */
    return `<span class="songbook-name-full">${_escSongbook(full)}</span><span class="songbook-name-abbr">${safeAbbr}</span>`;
}

/* ── #1531 — Client songbook registry ──────────────────────────────────────
   The ONE abbr → { name } source so every list view shows the full
   Songbook NAME (not the abbreviation) for ALL songbooks — not just the six in
   SONGBOOK_NAMES above. Populated once from /api?action=songbooks at app init
   (App.init() calls loadSongbookRegistry); songbookLabel()/songbookFullName()
   read it synchronously. Best-effort: if the fetch hasn't completed (or failed)
   the resolvers fall back to SONGBOOK_NAMES / the abbreviation, so nothing
   breaks — the name simply fills in once the registry loads. */
const _SONGBOOK_REGISTRY = new Map();

/** Load the songbook registry once. Fire-and-forget from App.init(). */
export async function loadSongbookRegistry(apiUrl) {
    if (!apiUrl) return;
    try {
        /* DELIBERATELY a bare fetch(), NOT apiFetch() — one of the few in the
           codebase (rule #31), and the reason is not obvious from here.

           ELI5: this list is how we look up a songbook's NAME, so we need all of
           them — not just the ones in the languages you've picked.

           `/api?action=songbooks` LANGUAGE-FILTERS its response
           (`makeLanguageFilterPredicate(resolvePreferredLanguagesForRequest(…))`,
           api.php). `apiFetch()` attaches `X-Preferred-Languages`, so routing
           this through it would hand back a registry filtered to the user's
           chosen languages — and this registry is a NAME LOOKUP, not a browsable
           list. Any songbook outside the filter would then resolve to null in
           songbookFullName(), and every list view mentioning it would quietly
           fall back to the bare abbreviation: cosmetic, gradual, attributable to
           nothing. The enforcing mechanism is the count-exact allowlist entry in
           tests/test-api-client-usage.js (#1700); this comment is for whoever
           reads the call. Prefer an unfiltered endpoint when the songbooks
           action is next touched — then this can become an ordinary apiFetch. */
        const res = await fetch(`${apiUrl}?action=songbooks`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!res.ok) return;
        const data = await res.json();
        for (const book of (data.songbooks || [])) {
            if (book && book.id) {
                /* Only the NAME is kept. An `isOfficial` flag was stored here
                   too, read by exactly one exported helper that nothing ever
                   called — both removed in #1696. When #1531 part 2 is actually
                   built it should shape this against the requirements it has
                   then; the API still sends `isOfficial`, so re-adding it is one
                   line. (Rule #20's reasoning, applied to JS: a helper written
                   against guessed requirements is the same mistake as a guessed
                   schema.) */
                _SONGBOOK_REGISTRY.set(book.id, { name: book.name || '' });
            }
        }
    } catch {
        /* Best-effort — songbookLabel falls back to SONGBOOK_NAMES / abbr. */
    }
}

/** Full name for a songbook abbreviation from the registry, or null. */
export function songbookFullName(abbr) {
    const entry = _SONGBOOK_REGISTRY.get(abbr);
    return (entry && entry.name) || null;
}

/* songbookIsOfficial() lived here until #1696. It was written as the enabling
   helper for #1531 part 2 ("Unofficial → show writing team") and its own
   definition was the ONLY match in the entire tree — no caller, on any surface.
   That is precisely the shape the orphan programme exists to remove: a
   shipped-looking capability that does nothing, which reads to the next person
   as though part 2 were half-built.

   Worth noting WHY it survived so long: tests/php/test-orphan-inventory.php
   derives its corpus from dispatch surfaces, schema tables and entitlement
   labels, and a plain exported JS helper is none of those — the guard is
   structurally blind to this class and honestly says so in its header. Whether
   it should grow an unimported-export check is on the record in #1696 and is
   NOT settled by this deletion. */
