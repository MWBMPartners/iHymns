/* ==========================================================================
 *  api-client.js — client for the v2 Song Editor API (manage/editor/api2.php)
 *
 *  Every WRITE sends the X-CSRF-Token header (read from the <meta name="csrf-
 *  token"> the editor head emits) and AWAITS the server's `{ ok: true }`. If the
 *  server reports failure (or a non-2xx, or ok!==true), this THROWS — so callers
 *  can never act on a false success (the exact bug behind the client-only
 *  deleteSong() that toasted "deleted" while the DB kept the song). #1200.
 *
 *  All methods return the parsed `{ ok, ... }` object on success, or throw an
 *  Error whose message is the server's admin error_detail (or the all-roles
 *  error_hint, or error, or HTTP status). The UI layer turns a throw into a
 *  real, visible failure toast +
 *  leaves the local state untouched — never an optimistic mutation.
 * ========================================================================== */

const ENDPOINT = '/manage/editor/api2.php';

/** The CSRF token the editor head emits as <meta name="csrf-token" content="…">. */
function csrfToken() {
    const m = document.querySelector('meta[name="csrf-token"]');
    return m ? (m.getAttribute('content') || '') : '';
}

/**
 * Normalise a fetch Response into the server payload or throw.
 *
 * ELI5: on failure this throws an error that also remembers the HTTP status
 * number, so a caller can tell "the migration hasn't run" (409) from "this song
 * has empty sections" (422) without reading the sentence the server wrote.
 *
 * Detail: this used to throw a bare `new Error(msg)`, discarding `res.status`.
 * Callers that need to distinguish failure KINDS were left with only one thing
 * to branch on — the server's prose — so arrangement-editor.js matched
 * /migration has not been run/i and /empty sections/i, with a comment admitting
 * "if that wording ever changes, update these two together".
 *
 * That is the failure class this codebase keeps rediscovering: two things that
 * must agree, with a comment where a mechanism should be. Reword a server error
 * and the client's explanatory UI silently degrades to a generic toast — no
 * exception, no test failure, just a worse experience nobody notices.
 *
 * `status` is attached as an own property rather than by subclassing Error,
 * because every existing `catch (e) { toast(e.message) }` keeps working
 * unchanged; only callers that WANT the status have to know about it.
 */
async function unwrap(res) {
    let data = null;
    try { data = await res.json(); } catch (_e) { /* non-JSON body */ }
    if (!res.ok || !data || data.ok !== true) {
        /* Preference order, most specific first (#1679 A8):
             error_detail — admin-only diagnostics (file, line, raw mysqli text);
             error_hint   — a refusal that is safe for EVERY role and tells the
                            user what to do about it (today: "this install is
                            missing migration X, run it, then move the song
                            again"). api2 gates entry on the `editor` role but
                            computes its admin flag from `admin`, so a plain
                            editor gets no error_detail — and was therefore shown
                            a bare "Server error." for the one failure that has a
                            precise, actionable answer;
             error        — the generic sentence.
           Note this picks the MESSAGE only; callers that need to distinguish
           failure KINDS branch on `err.status` below, never on the prose. */
        const msg = (data && (data.error_detail || data.error_hint || data.error))
            || ('HTTP ' + res.status);
        const err = new Error(msg);
        err.status = res.status;
        throw err;
    }
    return data;
}

/** GET read (no CSRF; reads are safe). */
async function getJson(action, params) {
    const qs = new URLSearchParams(Object.assign({ action: action }, params || {})).toString();
    const res = await fetch(ENDPOINT + '?' + qs, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    return unwrap(res);
}

/**
 * POST write — CSRF-guarded, JSON body.
 *
 * ELI5: `X-Requested-With` is the header that proves this request came from our
 * own page. Without it the server refuses the write, and every edit in this
 * editor fails.
 *
 * Detail (#1677): api2.php rejects EVERY POST that lacks
 * `X-Requested-With: XMLHttpRequest` (api2.php:148, the #1307 same-origin CSRF
 * gate). Neither write helper in this file sent it, so every mutation the v2
 * editor could perform — save, create, delete, component upsert, media upload,
 * bulk ops, the line-enrichment endpoints — returned 403 "Cross-site POST
 * blocked". Only `getJson()` sent the header, and reads are not gated, so the
 * one place it appeared was the one place it was not needed.
 *
 * It survived because v1 is still the default editor, so v2's write paths are
 * barely exercised. And the gate's own comment names the caller it was written
 * against — "editor.js ed2EnrichApi, which already sends it" — which is the V1
 * editor calling into api2. #1307 was verified against v1; this file, the other
 * consumer of the same endpoint, was never checked.
 *
 * The header is a "forbidden header name": a browser will not let a cross-origin
 * <form> or navigation set it, and setting it from cross-origin fetch triggers a
 * CORS preflight this server never honours. That is what makes its presence
 * proof of same origin.
 * https://developer.mozilla.org/docs/Glossary/Forbidden_header_name
 *
 * `X-CSRF-Token` below is vestigial — api2 stopped validating it in #1307
 * (api2.php:127-148). Harmless, and removing it is a separate cleanup.
 */
async function postJson(action, body) {
    const res = await fetch(ENDPOINT + '?action=' + encodeURIComponent(action), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': csrfToken(),
        },
        body: JSON.stringify(body || {}),
    });
    return unwrap(res);
}

/** POST write — CSRF-guarded, MULTIPART body (file upload). Deliberately does
 *  NOT set Content-Type: the browser sets multipart/form-data + the boundary.
 *  `X-Requested-With` is what api2's POST gate actually checks (#1677 — see the
 *  note on postJson above); the X-CSRF-Token alongside it is vestigial. */
async function postForm(action, formData) {
    const res = await fetch(ENDPOINT + '?action=' + encodeURIComponent(action), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': csrfToken(),
        },
        body: formData,
    });
    return unwrap(res);
}

/* The granular editor surface — one method per atomic server mutation. */
export const editorApi = {
    /* Reads */
    loadIndex:         ()                        => getJson('load_index', {}),

    /* Bulk ops (multi-select) */
    bulkVerify:        (songIds, verified)       => postJson('bulk_verify', { songIds: songIds, verified: verified ? 1 : 0 }),
    bulkTagAttach:     (songIds, name)           => postJson('bulk_tag_attach', { songIds: songIds, name: name }),
    /* The remove half. v1's single `bulk_tag` took add[] AND remove[]; v2 shipped
       attach only, so a curator who bulk-tagged 200 songs wrongly had no way back
       except one song at a time. The endpoint landed in `33f583e1` — this method
       and the toolbar button are what make it reachable, and their absence for
       part of a day is exactly the orphan class #1671 is about. */
    bulkTagDetach:     (songIds, name)           => postJson('bulk_tag_detach', { songIds: songIds, name: name }),
    loadSong:          (id)                      => getJson('load_song', { id: id }),

    /* Song lifecycle */
    createSong:        (songbook, title)         => postJson('create_song', { songbook: songbook, title: title }),
    deleteSong:        (songId)                  => postJson('delete_song', { songId: songId }),

    /* Metadata — one scalar field at a time */
    updateMetadata:    (songId, field, value)    => postJson('metadata_field_update', { songId: songId, field: field, value: value }),

    /* Components */
    upsertComponent:   (songId, component)       => postJson('component_upsert', { songId: songId, component: component }),
    deleteComponent:   (songId, componentId)     => postJson('component_delete', { songId: songId, componentId: componentId }),
    reorderComponents: (songId, order)           => postJson('component_reorder', { songId: songId, order: order }),
    /* Bulk-set the whole component list (reflow / single-song import). mode = 'replace' | 'append'. */
    replaceComponents: (songId, components, mode) => postJson('components_replace', { songId: songId, components: components, mode: mode || 'replace' }),

    /* Line enrichment — per-line translations/transliterations + annotations
       (#1235 P3 / #1088, #1627 item 3). Anchored on tblLyricLines.Id (rule
       #21): `translation`/`annotation` carry a `lineId`/`startLineId` the
       caller reads off `component.lineIds[i]` (parallel to `component.lines`,
       #1627's editor-shape addition to lyricLinesEditableComponents()) —
       never a LinesJson array index, which shifts under edits. The four
       action names + payload shapes mirror api2.php's line_translation_… /
       line_annotation_… cases exactly; see enrichment-panel.js for the actual
       UI + the vocab (kind/annotationType) it offers, which must stay a
       subset of includes/line_enrichment.php's LINE_TRANSLATION_KINDS /
       LINE_ANNOTATION_TYPES — tests/test-v2-enrichment-ui.js guards both the
       action-name and vocabulary agreement so this file can't drift from the
       server it calls. api2.php answers HTTP 409 (not 400/500) on an
       un-migrated install; postJson()'s unwrap() turns that into a thrown
       Error whose message contains "not migrated" — enrichment-panel.js's
       callers pattern-match that to show a calm "not available yet" notice
       instead of a red failure toast. */
    upsertLineTranslation: (songId, translation) => postJson('line_translation_upsert', { songId: songId, translation: translation }),
    deleteLineTranslation: (songId, id)          => postJson('line_translation_delete', { songId: songId, id: id }),
    upsertLineAnnotation:  (songId, annotation)  => postJson('line_annotation_upsert', { songId: songId, annotation: annotation }),
    deleteLineAnnotation:  (songId, id)          => postJson('line_annotation_delete', { songId: songId, id: id }),

    /* Credits — role is one of writers/composers/arrangers/adaptors/translators/artists */
    upsertCredit:      (songId, role, credit)    => postJson('credit_upsert', { songId: songId, role: role, credit: credit }),
    deleteCredit:      (songId, role, creditId)  => postJson('credit_delete', { songId: songId, role: role, creditId: creditId }),
    searchCredits:     (q, kind)                 => getJson('credit_search', { q: q || '', kind: kind || 'any', limit: 12 }),

    /* Tags — registry-backed; attach auto-creates the tag + returns its canonical form */
    listTags:          (songId)                  => getJson('tag_list', { id: songId }),
    searchTags:        (q, limit)                => getJson('tag_search', { q: q || '', limit: limit || 10 }),
    attachTag:         (songId, name)            => postJson('tag_attach', { songId: songId, name: name }),
    detachTag:         (songId, tagId)           => postJson('tag_detach', { songId: songId, tagId: tagId }),

    /* External links — whole sub-form reconcile (the shared card-list editor model).
       `links` is [{ typeId, url, note?, verified? }]; returns the persisted rows. */
    saveLinks:         (songId, links)           => postJson('link_save_all', { songId: songId, links: links }),

    /* Song-link / counterpart group (#1608) — five actions ported from the
       legacy v1 editor's inline "Suggested counterparts" panel onto the SAME
       api2.php cases described in that file's doc-block. No client-side
       similarity maths lives here or anywhere in this tree (CLAUDE.md rule
       #22) — song_link_suggestions only ever reads the server's pre-scored
       table. err.status carries the failure KIND (409 = the two songs are
       already in different groups) per rule #35 — counterparts-panel.js
       branches on that, never on err.message. */
    songLinks:                (songId)                     => getJson('song_links', { id: songId }),
    songLinkAdd:               (sourceSongId, targetSongId, note) =>
        postJson('song_link_add', { sourceSongId: sourceSongId, targetSongId: targetSongId, note: note || '' }),
    songLinkRemove:            (songId)                     => postJson('song_link_remove', { songId: songId }),
    songLinkSuggestions:       (songId)                     => getJson('song_link_suggestions', { id: songId }),
    songLinkSuggestionDismiss: (songIdA, songIdB, reason)   =>
        postJson('song_link_suggestion_dismiss', { songIdA: songIdA, songIdB: songIdB, reason: reason || '' }),

    /* Media — file metadata reads; upload is multipart; only annotation is mutable. */
    listMedia:         (songId)                  => getJson('media_list', { id: songId }),
    uploadMedia:       (songId, kind, file, annotation) => {
        const fd = new FormData();
        fd.append('songId', songId);
        fd.append('kind', kind);
        fd.append('annotation', annotation || '');
        fd.append('file', file);
        return postForm('media_upload', fd);
    },
    updateMedia:       (mediaId, annotation)     => postJson('media_update', { mediaId: mediaId, annotation: annotation }),
    deleteMedia:       (mediaId)                 => postJson('media_delete', { mediaId: mediaId }),
    reorderMedia:      (songId, kind, ids)       => postJson('media_reorder', { songId: songId, kind: kind, ids: ids }),

    /* Revisions — history (metadata) + full-snapshot restore */
    listRevisions:     (songId)                  => getJson('revision_list', { songId: songId }),
    restoreRevision:   (revisionId, songId)      => postJson('revision_restore', { revisionId: revisionId, songId: songId }),

    /* Arrangement — the song's running order (#161 / #1627 item 2). `arrangement`
       is a list of 0-based indexes into the song's section list, e.g. [0,1,1,2]
       for verse/chorus/chorus/verse; repetition is legal and expected. Pass null
       (or []) to clear it back to sequential component order. api2.php echoes
       back the STORED value (never the request) — see arrangement-editor.js's
       persist() for why the client must write THAT back into the store rather
       than trusting its own local array. 409 = the ArrangementJson migration
       hasn't run here; 422 = this song has an empty section, so the editor's
       section count and the published render's disagree — arrangement-editor.js
       pattern-matches both out of the thrown Error's message (same technique
       enrichment-panel.js uses for its own 409, see that file's isEnrichmentUnmigrated). */
    updateArrangement: (songId, arrangement)     => postJson('arrangement_update', { songId: songId, arrangement: arrangement }),
};

/* ==========================================================================
 *  THE ONE OTHER ENDPOINT THIS EDITOR TALKS TO — /api (#298 song keys, #1671 F3)
 *
 *  ELI5: musical key, tempo and time signature live on a different server file
 *  from everything else in this editor, so they get their own two functions
 *  here rather than a second copy of "how to call the server" in the panel.
 *
 *  WHY NOT api2.php. `tblSongKeys` already has a complete, public, documented
 *  pair — `?action=song_key` (GET) and `?action=song_key_save` (POST) — used by
 *  the public song page too. Re-implementing them as api2 actions would be a
 *  second writer for one table, which is the fork the modularity rule exists to
 *  prevent, and would leave the original pair orphaned again.
 *
 *  WHY NOT js/utils/api-client.js's `apiFetch` (rule #31). That module is the
 *  PUBLIC SPA's client: it dispatches the SPA's offline/online events, attaches
 *  the reader's `X-Preferred-Languages` filter, and reads an auth-header
 *  provider that only `js/app.js` installs. None of that exists or belongs
 *  inside /manage, whose tree already has exactly one client — this file. The
 *  spirit of rule #31 is "one client per tree, by structure, never a global
 *  patch"; two clients in two trees satisfies it, importing the SPA's into the
 *  admin bundle would not.
 *
 *  WHAT MAKES THE WRITE WORK. `api.php` refuses EVERY POST without
 *  `X-Requested-With: XMLHttpRequest` (its top-of-file CSRF gate), and
 *  authenticates from the `ihymns_auth` cookie a /manage sign-in mints
 *  (`mintCrossSurfaceAuthToken()`, #1377) when no bearer token is present.
 *  `credentials: 'same-origin'` is what sends that cookie.
 *
 *  BOTH HELPERS ATTACH `err.status` — callers branch on the NUMBER, never on
 *  the server's sentence (rule #35 / #1677). It matters concretely here: 401
 *  means "this admin session has no app-API cookie, sign in again", 403 means
 *  "your role lacks edit_songs", and 404 on the GET is the ordinary "this song
 *  has no key recorded" — three completely different things to say.
 * ========================================================================== */

const PUBLIC_API = '/api';

/**
 * Turn a non-OK /api response into a throw that remembers its status.
 *
 * @param {Response} res
 * @returns {Promise<Error>}
 */
async function publicApiError(res) {
    let msg = '';
    try {
        const data = await res.json();
        if (data && typeof data.error === 'string') msg = data.error;
    } catch (_e) { /* non-JSON body (an HTML error page from a 500) */ }
    const err = new Error(msg || ('HTTP ' + res.status));
    err.status = res.status;
    return err;
}

/**
 * Read a song's stored key, or null when it has none.
 *
 * The 404 is the NORMAL answer — `tblSongKeys` is sparse — so it is translated
 * into `null` here rather than thrown, and only a genuine failure throws.
 *
 * @param {string} songId
 * @returns {Promise<{originalKey:string, tempo:number|null, timeSignature:string}|null>}
 */
export async function songKeyGet(songId) {
    const res = await fetch(
        PUBLIC_API + '?action=song_key&id=' + encodeURIComponent(songId),
        {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        }
    );
    if (res.status === 404) return null;
    if (!res.ok) throw await publicApiError(res);
    const data = await res.json();
    return data && data.key ? data.key : null;
}

/**
 * Save a song's key. Resolves with the STORED values the server echoes back.
 *
 * The echo is load-bearing: the server normalises (`bB` → `Bb`, an omitted time
 * signature → `''`), so a caller that kept its own copy would display something
 * the database does not hold.
 *
 * @param {string} songId
 * @param {{originalKey:string, tempo:number|null, timeSignature:string}} key
 * @returns {Promise<{originalKey:string, tempo:number|null, timeSignature:string}>}
 */
export async function songKeySave(songId, key) {
    const res = await fetch(PUBLIC_API + '?action=song_key_save', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            /* The header api.php's global POST gate actually checks. Without it
               every save here is a flat 403 — the exact #1677 failure the v2
               client had against api2.php for months. */
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
            song_id:        songId,
            original_key:   key.originalKey,
            tempo:          key.tempo,
            time_signature: key.timeSignature,
        }),
    });
    if (!res.ok) throw await publicApiError(res);
    const data = await res.json();
    if (!data || data.ok !== true) {
        const err = new Error((data && data.error) || 'Save failed.');
        err.status = res.status;
        throw err;
    }
    return data.key;
}

export { csrfToken };
