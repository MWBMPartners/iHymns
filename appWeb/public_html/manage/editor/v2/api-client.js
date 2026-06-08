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
 *  Error whose message is the server's admin error_detail (or error, or HTTP
 *  status). The UI layer turns a throw into a real, visible failure toast +
 *  leaves the local state untouched — never an optimistic mutation.
 * ========================================================================== */

const ENDPOINT = '/manage/editor/api2.php';

/** The CSRF token the editor head emits as <meta name="csrf-token" content="…">. */
function csrfToken() {
    const m = document.querySelector('meta[name="csrf-token"]');
    return m ? (m.getAttribute('content') || '') : '';
}

/** Normalise a fetch Response into the server payload or throw. */
async function unwrap(res) {
    let data = null;
    try { data = await res.json(); } catch (_e) { /* non-JSON body */ }
    if (!res.ok || !data || data.ok !== true) {
        const msg = (data && (data.error_detail || data.error)) || ('HTTP ' + res.status);
        throw new Error(msg);
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

/** POST write — CSRF-guarded, JSON body. */
async function postJson(action, body) {
    const res = await fetch(ENDPOINT + '?action=' + encodeURIComponent(action), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-Token': csrfToken(),
        },
        body: JSON.stringify(body || {}),
    });
    return unwrap(res);
}

/** POST write — CSRF-guarded, MULTIPART body (file upload). Deliberately does
 *  NOT set Content-Type: the browser sets multipart/form-data + the boundary.
 *  The CSRF token still rides in the X-CSRF-Token header. */
async function postForm(action, formData) {
    const res = await fetch(ENDPOINT + '?action=' + encodeURIComponent(action), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrfToken() },
        body: formData,
    });
    return unwrap(res);
}

/* The granular editor surface — one method per atomic server mutation. */
export const editorApi = {
    /* Reads */
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

    /* Credits — role is one of writers/composers/arrangers/adaptors/translators/artists */
    upsertCredit:      (songId, role, credit)    => postJson('credit_upsert', { songId: songId, role: role, credit: credit }),
    deleteCredit:      (songId, role, creditId)  => postJson('credit_delete', { songId: songId, role: role, creditId: creditId }),

    /* Tags — registry-backed; attach auto-creates the tag + returns its canonical form */
    listTags:          (songId)                  => getJson('tag_list', { id: songId }),
    searchTags:        (q, limit)                => getJson('tag_search', { q: q || '', limit: limit || 10 }),
    attachTag:         (songId, name)            => postJson('tag_attach', { songId: songId, name: name }),
    detachTag:         (songId, tagId)           => postJson('tag_detach', { songId: songId, tagId: tagId }),

    /* External links — whole sub-form reconcile (the shared card-list editor model).
       `links` is [{ typeId, url, note?, verified? }]; returns the persisted rows. */
    saveLinks:         (songId, links)           => postJson('link_save_all', { songId: songId, links: links }),

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
};

export { csrfToken };
