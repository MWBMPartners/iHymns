/**
 * presence-identity.js — shared anonymous-follower identity (#1770 C5).
 *
 * ELI5: both ways of following a live thing in iHymns — a worship leader's
 * "Go Live" session (live-follow.js) and a church's "Service Mode" (#1335,
 * service-follow.js) — need to remember "which device is this?" and "does
 * this device currently hold a presence pass?" in EXACTLY the same shape, so
 * a follower on either path unlocks gated content the same way. This module
 * is that ONE shape, extracted so neither follower has to carry its own copy
 * (CLAUDE.md modularity rule — "when logic exists in more than one place,
 * extract it").
 *
 * DETAILED — lifted verbatim from `service-follow.js`'s pre-#1770
 * `_deviceId()` (:99-110) and `_setPresenceCookie()`/`_clearPresenceCookie()`
 * (:365-373), which #1770 C3's server work (`live_follow_join`'s additive
 * POST-presence mode, `includes/service_mode.php`'s
 * `serviceMode_presenceCcliNumber()` host branch) made a SECOND consumer of:
 * a Quick "Go Live" follower now ALSO mints a `tblServicePresence` row and
 * needs the SAME device id + the SAME `ihymns_sf_presence_token` cookie the
 * Phase-3 gate (`content_access.php:416-422`) already reads. Re-forking a
 * second copy in `live-follow.js` would be the exact regression the
 * modularity rule exists to stop; this module is the extraction instead.
 *
 * `deviceId()` is durable (localStorage — survives a reload/relaunch, NOT a
 * login) so a device's presence rows upsert onto the SAME
 * `uq_DeviceSession (SessionId, PresenceDeviceId)` key across visits rather
 * than minting a fresh row every join. The presence COOKIE is deliberately a
 * SESSION cookie (no `Max-Age`) — it should not outlive the browser session
 * the same way the anonymous device id does; the server-side presence row's
 * own `ExpiresAt` is the durable bound.
 *
 * @see js/modules/live-follow.js    Quick "Go Live" follower — consumer #1
 * @see js/modules/service-follow.js Service Mode congregant — consumer #2
 * @see includes/content_access.php:416-422  the ONE cookie-reading gate
 * @link https://developer.mozilla.org/docs/Web/API/Crypto/randomUUID
 * @link https://developer.mozilla.org/docs/Web/API/Document/cookie
 */

/** localStorage key for the durable anonymous device id. */
const PRESENCE_DEVICE_KEY = 'ihymns_sf_device';
/** Same-origin cookie name the Phase-3 gate reads (content_access.php). */
const PRESENCE_COOKIE_NAME = 'ihymns_sf_presence_token';

/**
 * Durable anonymous device id (NOT a login) — minted once per browser,
 * kept in localStorage. Falls back to a timestamp+random string when
 * `crypto.randomUUID` is unavailable (older browsers / non-secure contexts).
 *
 * @returns {string} A stable per-device identifier.
 */
export function deviceId() {
    let id = null;
    try { id = localStorage.getItem(PRESENCE_DEVICE_KEY); } catch (_e) { /* private mode */ }
    if (!id) {
        id = (window.crypto && crypto.randomUUID)
            ? crypto.randomUUID()
            : ('dev-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10));
        try { localStorage.setItem(PRESENCE_DEVICE_KEY, id); } catch (_e) { /* private mode — in-memory only for this load */ }
    }
    return id;
}

/**
 * Set the same-origin presence-token cookie the Phase-3 content gate reads
 * (`checkContentAccess($e,$id,$uid,$plat,$presenceToken)` via `song.php` /
 * `song-media.php` / `audio-media.php`). A SESSION cookie on purpose (no
 * `Max-Age`) — presence is a "for the life of this browser session" grant,
 * never a persistent one; the server-side row's own `ExpiresAt` is what
 * actually bounds how long the token unlocks anything.
 *
 * @param {string} token The presence token minted by `service_join` or
 *                        `live_follow_join`'s POST mode.
 */
export function setPresenceCookie(token) {
    try {
        const secure = (location.protocol === 'https:') ? '; Secure' : '';
        document.cookie = PRESENCE_COOKIE_NAME + '=' + encodeURIComponent(token) + '; path=/; SameSite=Lax' + secure;
    } catch (_e) { /* cookies blocked — the gate simply sees no presence */ }
}

/** Clear the presence-token cookie (leave / session-ended / active:false). */
export function clearPresenceCookie() {
    try { document.cookie = PRESENCE_COOKIE_NAME + '=; path=/; Max-Age=0; SameSite=Lax'; } catch (_e) { /* ignore */ }
}
