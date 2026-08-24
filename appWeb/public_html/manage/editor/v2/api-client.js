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

// #1855: extensionless — a literal /manage/editor/api2.php gets 301'd by
// .htaccess (cosmetic URL-hiding), and a browser replays a 301'd POST as a
// body-less GET, which silently drops every write's payload. Requesting the
// clean URL directly (the same convention v1's editor already uses) sends
// writes straight to the endpoint with no redirect at all.
const ENDPOINT = '/manage/editor/api2';

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
    /* #1628 item 3 — the other two bulk actions v1 had that v2 shipped
       without: moving many songs to a different songbook, and deleting many
       at once. Both delegate server-side to the SAME per-song cores the
       single-song actions use (songRelocate() / songSoftDelete()) — see
       api2.php's doc-block for the full contract. Neither is all-or-
       nothing: the response always carries BOTH a success list and a
       `failed:[{id,error,status}]` list, so a curator moving/deleting 300
       songs can see exactly which few refused, never a single opaque error
       for the whole batch.
       bulkMove's `moved` list carries `{oldId,newId}` pairs — option B
       (#1679) re-keys every SongId it touches, so the caller MUST treat
       every id it just sent as stale and re-key its own selection from
       this response (never assume the old ids still resolve directly —
       they only resolve via the redirect layer, editor2.php's handler
       refreshes the sidebar's index unconditionally for exactly this
       reason). */
    bulkMove:          (songIds, targetAbbr)     => postJson('bulk_move', { songIds: songIds, targetAbbr: targetAbbr }),
    bulkDelete:        (songIds, reason)         => postJson('bulk_delete', { songIds: songIds, reason: reason || '' }),
    loadSong:          (id)                      => getJson('load_song', { id: id }),

    /* Song lifecycle */
    createSong:        (songbook, title)         => postJson('create_song', { songbook: songbook, title: title }),
    /* #1783 — copy an existing song into the hidden staging book as a starting
       point for a new songbook; the duplicate opens with empty Songbook + Number. */
    duplicateSong:     (sourceId)                => postJson('duplicate_song', { sourceId: sourceId }),
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

    /* Credits — role is one of writers/composers/arrangers/adaptors/translators/artists.
       `credit` is EITHER the structured {id?, first?, surname?, suffix?} shape
       credits-tab.js sends per keystroke, or the flat back-compat {id?, name}
       shape (e.g. adopting an autocomplete suggestion pick) — api2.php's
       credit_upsert normalises either one server-side via the shared
       creditEntryNormalise() and ALWAYS responds with the REGISTRY's
       {name, first, surname, suffix} (#960 D2): the backfill that fills those
       columns never overwrites an existing curated value, so echoing the
       caller's own input back would show a silent no-op as if it had saved.
       credits-tab.js adopts this response into the three boxes rather than
       trusting what it sent. */
    upsertCredit:      (songId, role, credit)    => postJson('credit_upsert', { songId: songId, role: role, credit: credit }),
    deleteCredit:      (songId, role, creditId)  => postJson('credit_delete', { songId: songId, role: role, creditId: creditId }),
    searchCredits:     (q, kind)                 => getJson('credit_search', { q: q || '', kind: kind || 'any', limit: 12 }),

    /* Tags — registry-backed; attach auto-creates the tag + returns its canonical form */
    listTags:          (songId)                  => getJson('tag_list', { id: songId }),
    searchTags:        (q, limit)                => getJson('tag_search', { q: q || '', limit: limit || 10 }),
    attachTag:         (songId, name)            => postJson('tag_attach', { songId: songId, name: name }),
    detachTag:         (songId, tagId)           => postJson('tag_detach', { songId: songId, tagId: tagId }),

    /* Tune registry typeahead + the ONE tune write (#1741 P5c). searchTunes
       mirrors searchTags's shape (q/limit), plus an optional `meter` leg
       (folded server-side via ihymns_meter_normalize()) for the "matching
       metre only" toggle. setSongTune is consumed by metadata-tab.js's tune
       control on `change` (blur) + a typeahead pick — deliberately NOT on
       every keystroke, since it can find-or-CREATE a tblTunes row
       (metadata-tab.js's saveTune() doc-comment explains why). An empty
       `tuneName` is a legal CLEAR (both TuneName/TuneId -> NULL server-side),
       distinct from omitting the key entirely (400). */
    searchTunes:       (q, limit, meter)         => getJson('tune_search', Object.assign({ q: q || '', limit: limit || 10 }, meter ? { meter: meter } : {})),
    setSongTune:       (songId, tuneName)        => postJson('song_tune_set', { songId: songId, tuneName: tuneName }),

    /* Publisher registry typeahead + the ONE copyright-holder write (#1862,
       activating the #1864 dormant CopyrightHolderId FK). searchPublishers
       mirrors searchTunes's shape (q/limit only — no meter-style extra leg).
       setCopyrightHolder is consumed by metadata-tab.js's holder control on
       `change` (blur) + a typeahead pick — deliberately NOT on every
       keystroke, the exact #1679/#1741 P5c reasoning restated for
       publishers (a debounced write could find-or-CREATE a junk
       tblPublishers row per keystroke pause). `publisherId` is the
       picker's CLAIMED id (or null when nothing was picked / it was
       cleared by free-typing) — trust-but-verify happens server-side
       (publisherResolvePickedOrCreate()), never assumed by this client. An
       empty `name` is a legal CLEAR (both columns -> cleared server-side). */
    searchPublishers:   (q, limit)                  => getJson('publisher_search', { q: q || '', limit: limit || 10 }),
    setCopyrightHolder: (songId, name, publisherId) => postJson('song_copyright_holder_set', { songId: songId, name: name, publisherId: publisherId }),

    /* Multi-holder copyright (#1900 Wave 4 C8) — the ordered chip-list
       sibling of setCopyrightHolder above. listCopyrightHolders is a plain
       read; setCopyrightHolders REPLACES the FULL ordered list in one call
       (never a per-chip add/remove endpoint — the server always resolves
       the whole thing atomically, rule #35's read-back: metadata-tab.js
       re-renders its chips from THIS response's `holders`, never from the
       `holders` array it just sent). Both 409 on an install that hasn't run
       the #1900 migration card — metadata-tab.js feature-detects that via
       `err.status`, never the error sentence, and falls back to the
       original single-pick control above when it sees one. `holders` is
       `[{publisherId?, name, role?}]`; `role` defaults server-side to
       `'holder'` when omitted. */
    listCopyrightHolders: (songId)          => getJson('song_copyright_holders', { id: songId }),
    setCopyrightHolders:  (songId, holders) => postJson('song_copyright_holders_set', { songId: songId, holders: holders }),

    /* Work registry typeahead + the two "Part of work" writes (#1860 Phase 5
       Commit 9, design §3.7 items 1-2). searchWorks mirrors searchPublishers'
       /searchTunes' shape (q/limit only). autolinkWork is the commit-time
       hook metadata-tab.js fires after a CCLI/ISWC field's `change` (blur)
       lands — server-authoritative: it reads the song's STORED Ccli/Iswc,
       never anything this client sends (rule #35's read-back posture), so
       the request carries only songId. setSongWork is the manual "Part of
       work" picker's write: EXACTLY ONE of `opts.workId` (a typeahead pick)
       or `opts.title` (find-or-create for an identifier-less hymn) per call
       — never both, per api2.php's `song_work_set` contract (400 otherwise).
       Both endpoints answer HTTP 409 when the work-identity migration cards
       haven't been applied yet; metadata-tab.js's callers branch on
       `err.status`, never on the error sentence (rule #35). A work-link
       CONFLICT is not a failure — it rides back as a `conflict` string on
       the 200 body (a work-link ambiguity must never fail the song save). */
    searchWorks:  (q, limit)     => getJson('work_search', { q: q || '', limit: limit || 10 }),
    autolinkWork: (songId)       => postJson('song_work_autolink', { songId: songId }),
    setSongWork:  (songId, opts) => postJson('song_work_set', Object.assign({ songId: songId }, opts || {})),

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

    /* External IDs (#1741 P5b) — tblSongExternalIds' first UI write path. A
       card-list of {Spotify, ISRC, MusicBrainz, …} recording identifiers, on
       the Metadata tab (external-ids-panel.js), NOT the Links tab (which is a
       different registry — see that panel's file header). `created:false` on
       addExternalId means the (idType,idValue) pair already existed (INSERT
       IGNORE server-side); err.status carries the failure KIND (409 =
       un-migrated, 422 = unknown idType or bad shape) per rule #35 — the
       panel branches on that, never on err.message. */
    listExternalIds:   (songId)                  => getJson('song_external_ids', { id: songId }),
    addExternalId:     (songId, idType, idValue) => postJson('song_external_id_add', { songId: songId, idType: idType, idValue: idValue }),
    deleteExternalId:  (songId, id)              => postJson('song_external_id_delete', { songId: songId, id: id }),

    /* Alternative titles (#1669, epic #832) — tblSongAlternativeTitles' first
       UI write path. Per-song FREE TEXT "also known as" titles (NOT a
       registry reference — rule #43 does not apply, see
       includes/song_alt_titles.php's doc-block), shown on the Metadata tab
       beside the Title field (alt-titles-panel.js). `created:false` on
       addAltTitle means the exact title already existed for this song
       (INSERT IGNORE server-side, the uq_song_title unique key);
       err.status carries the failure KIND (409 = un-migrated, 422 =
       empty/over-length title, an unrecognised language tag, or the title
       being just the song's own main title again) per rule #35 — the panel
       branches on that, never on err.message. */
    listAltTitles:     (songId)                          => getJson('song_alt_titles', { id: songId }),
    addAltTitle:       (songId, title, language, note)   => postJson('song_alt_title_add', { songId: songId, title: title, language: language || '', note: note || '' }),
    deleteAltTitle:    (songId, id)                       => postJson('song_alt_title_delete', { songId: songId, id: id }),

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

    /* Revisions — history (metadata) + before/after diff pair + full-snapshot
       restore. getRevision is the #1628 item 4 diff-view read: the server
       resolves the before-snapshot LADDER (previousData -> priorRevision ->
       none, api2.php's revision_get doc-block) so this client never
       re-implements that chain (rule #35) — it only reads `beforeSource` and
       branches on it. */
    listRevisions:     (songId)                  => getJson('revision_list', { songId: songId }),
    /* #1122 — the whole-history raw snapshot bulk read that per-field BLAME walks
       (blameFromSnapshots() in revisions-tab.js). Newest-first, with the window
       base + ED2_META_FIELDS-derived fieldMap + noRollback list; see api2.php's
       revision_snapshots doc-block. */
    listRevisionSnapshots: (songId, limit)       => getJson('revision_snapshots', { songId: songId, limit: limit }),
    getRevision:       (revisionId, songId)      => getJson('revision_get', { revisionId: revisionId, songId: songId }),
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
