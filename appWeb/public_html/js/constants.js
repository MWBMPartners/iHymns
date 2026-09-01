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

/* #1786 Option B — the public card/list "Sort ▾" control's saved spec, one
   JSON blob keyed by surface id: `{ "<surface>": [{key,dir},…] }`. Device
   copy for anonymous users / first-paint; signed-in users additionally sync
   this SAME shape through the `list_sorts` namespace of the existing
   `user_settings` endpoint (#1671 F5) — see js/modules/list-sort.js. */
export const STORAGE_LIST_SORT = 'ihymns_list_sorts';

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

/* Accessibility — opt-in "accessible links" display mode (#1984, S1). Value
   'on' | absent (default off — see rule #18: prose links have NO at-rest
   colour/underline cue by default, which the owner deliberately reversed
   #951/#952 for). When 'on', the global `<a>` (app.css Section 2.5) and
   `.song-meta-link` gain an at-rest accent COLOUR (WCAG 1.4.1 "Use of
   Colour") without reintroducing the banned underline/border. Mirrors
   STORAGE_CVD_MODE exactly: independent of theme, read directly by
   applyTheme() (not routed through Settings.get()/set()'s `ihymns_`-prefix
   derivation) so admin-theme-init.php's synchronous mirror can read the
   SAME literal key with no prefix mismatch. */
export const STORAGE_LINK_EMPHASIS      = 'ihymns_link_emphasis';

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

/* #1270 — two-column chord-chart layout toggle. Deliberately a single GLOBAL
   flag (not per-song like STORAGE_TRANSPOSE_PREFIX above): a guitarist who
   prefers reading chord charts in two columns wants that on every song, not
   re-toggled per page. Value '1' | absent (mirrors STORAGE_OFFLINE_INCLUDE_AUDIO's
   canonical-key shape above — presence, not a stringly 'true'/'false'). */
export const STORAGE_CHORD_COLUMNS      = 'ihymns_chord_columns';

/* #1770 §4.7 — Live Follow leader-idle timeout, the USER layer of the
   three-layer precedence chain (app default → org override → user
   preference; includes/service_mode.php's serviceMode_resolveIdleTimeoutMins()).
   Deliberately UNPREFIXED — breaks the `ihymns_` convention on purpose, the
   SAME odd-value shape as STORAGE_LANGUAGE_FILTER above. Reason: the
   whole-blob `user_settings` sync (settings.js's _collectSyncableSettings())
   mirrors a syncable localStorage key's OWN NAME verbatim into the pushed
   `tblUsers.Settings` JSON blob's key — and the server-side resolver reads
   a FIXED root key literally spelled `liveIdleTimeoutMins`
   (LIVE_FOLLOW_IDLE_TIMEOUT_USER_SETTING_KEY, service_mode.php). Prefixing
   this key would sync it under `ihymns_liveIdleTimeoutMins`, which the
   resolver would never read — a rule-#35 silent-drift class, avoided here
   by making the two spellings the SAME constant string rather than two
   things a comment promises to keep in sync. */
export const STORAGE_LIVE_IDLE_TIMEOUT_MINS = 'liveIdleTimeoutMins';

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
/* #1786 Option B — fired on `document` by list-sort.js every time a
   surface's applied sort spec changes (including a reset back to Default),
   `detail: { surface, spec }`. Dispatcher: list-sort.js's DOM-mode apply
   path. Listener: songbook-index.js, which rebuilds its alphabet-strip
   letter map after the song list is re-ordered — the pre-existing #111
   toggle this absorbs had no such event because it WAS the only sorter on
   the page; now that list-sort.js owns sorting, the strip has to learn
   about a re-order it didn't itself perform. */
export const EVT_LIST_SORT_CHANGED = 'ihymns:list-sort-changed';

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
                /* #1531 part 2 — `isOfficial` re-added (it lived here once,
                   was removed in #1696 as a no-caller orphan — see
                   songbookIsOfficial() below for that history). The API has
                   sent `isOfficial` all along (SongData::getSongbooks(),
                   `b.IsOfficial AS isOfficial`, CLAUDE.md rule #24); this is
                   the one line that reads it back into the client registry
                   now that something actually consumes it. */
                _SONGBOOK_REGISTRY.set(book.id, {
                    name: book.name || '',
                    /* Preserve "the API didn't send a boolean" as `undefined`
                       rather than coercing it to `false` — songbookIsOfficial()
                       below treats a non-boolean as unresolved and assumes
                       Official (the safe default), which a stray `false`
                       here would defeat. */
                    isOfficial: typeof book.isOfficial === 'boolean' ? book.isOfficial : undefined,
                });
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

/**
 * Is this songbook Official (`tblSongbooks.IsOfficial = 1`, rule #24)?
 *
 * #1531 part 2 — re-added. A same-named helper lived here once, was removed
 * in #1696 as a no-caller orphan ("a shipped-looking capability that does
 * nothing" — see that issue), and the registry stopped carrying the flag at
 * all. Re-adding it now that `js/modules/search.js` actually branches on it
 * (italic writing-team credits for Unofficial books — callers test
 * `!songbookIsOfficial(abbr)`) is the difference #1696 asked for: shape the
 * helper against a REAL consumer, not a guessed one.
 *
 * @param {string} abbr Songbook abbreviation
 * @returns {boolean} `true` for a genuinely Official book AND for an abbr the
 *   registry hasn't loaded/resolved yet (assume-official is the safe
 *   default: misreporting an OFFICIAL book as Unofficial would italicize
 *   real songbook provenance, while the reverse just withholds the italic
 *   affordance until the registry has loaded — matching songbookFullName()'s
 *   same best-effort posture above). `false` only once the registry has
 *   POSITIVELY resolved this abbr as Unofficial.
 */
export function songbookIsOfficial(abbr) {
    const entry = _SONGBOOK_REGISTRY.get(abbr);
    if (!entry || typeof entry.isOfficial !== 'boolean') return true;
    return entry.isOfficial;
}
