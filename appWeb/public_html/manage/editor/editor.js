/**
 * iHymns Song Editor — Developer Tool
 * ====================================
 * Plain JavaScript (no ES modules). Relies on Bootstrap 5.3 loaded globally via CDN.
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 * This software is proprietary and confidential. Unauthorised copying, distribution,
 * or modification of this file, via any medium, is strictly prohibited.
 */

/* ========================================================================
 *  SECTION 1 — Global State
 * ======================================================================== */

/**
 * Master data object that mirrors the songs.json structure.
 * .meta      — file-level metadata (generatedAt, version, etc.)
 * .songbooks — array of songbook descriptor objects
 * .songs     — array of individual song objects
 */
var songData = {
    meta: {},       // will be populated on load
    songbooks: [],  // will be populated on load
    songs: []       // will be populated on load
};

/**
 * Primary load/save endpoint — the editor's PHP API reads/writes
 * directly from/to appWeb/data_share/song_data/songs.json (#154).
 */
/* Absolute path so the fetch resolves the same regardless of whether
   the browser landed on /manage/editor/ or /manage/editor (no trailing
   slash). The previous relative form ('api') resolved to /manage/api on
   the no-slash variant, which 404s and silently dropped credit-search
   results. (#593) */
var EDITOR_API_URL = '/manage/editor/api';

/**
 * WS-D #1016 — the editor loads a LIGHTWEIGHT slim index of every song
 * (id/number/title/songbook, no lyrics/components) live from MySQL via
 * ?action=load_index, then fetches each song's full editable record on
 * demand when it is opened (?action=load_song). The legacy whole-corpus
 * load (?action=load → songs.json) and its static-file fallbacks are
 * gone — the editor never holds the corpus in memory. SONGS_URL_CANDIDATES
 * stays a (now single-entry) list because the post-import reload reuses
 * SONGS_URL_CANDIDATES[0] to refresh the sidebar index.
 */
var SONGS_URL_CANDIDATES = [EDITOR_API_URL + '?action=load_index'];

/** Slim-index load endpoint (all songs, lightweight). */
var DEFAULT_SONGS_URL = SONGS_URL_CANDIDATES[0];

/** Per-record full-song load endpoint, hit when a song is opened. */
var LOAD_SONG_URL = EDITOR_API_URL + '?action=load_song';

/** ID of the song the user has SELECTED (null when nothing selected). May
 *  briefly differ from the song actually rendered in the form while that
 *  song's full record is being fetched (WS-D #1016). */
var currentSongId = null;

/** ID of the song whose full record is actually RENDERED in the form right
 *  now (null when the form is empty/loading). Distinct from currentSongId:
 *  external-links flushing keys off this so a rapid A→B→A switch can't write
 *  the displayed song's DOM into a different song's record (WS-D #1016). */
var _renderedSongId = null;

/** Set of song IDs that have been modified since last save. */
var modifiedSongIds = new Set();

/** Timestamp (Date object) of the most recent save/download action, or null. */
var lastSaveTime = null;

/** Current sort mode for the song list: 'title', 'number', or 'songbook' (#251). */
var currentSortMode = 'title';

/* ========================================================================
 *  SECTION 2 — Data Management (#53, #58)
 * ======================================================================== */

/**
 * loadSongsFromURL(url)
 * ---------------------
 * Fetches a songs.json file from the provided URL (or the default relative path),
 * parses it, and stores the result in `songData`.
 *
 * @param {string} [url] - The URL to fetch. Falls back to DEFAULT_SONGS_URL.
 * @returns {Promise<void>}
 */
function loadSongsFromURL(url) {
    /* If a specific URL is provided, fetch that directly. */
    if (url) {
        return _fetchAndParseSongs(url);
    }

    /* Otherwise, try each candidate path in order until one succeeds. */
    var candidates = SONGS_URL_CANDIDATES.slice();

    /* #925 — capture the most-recent candidate's error and surface it
       in the final fallback toast. Pre-#925 the catch silently
       discarded the error and tried the next candidate; if every
       candidate failed (e.g. /api?action=load returns 500 with a real
       exception), the curator only saw the bare "could not load from
       any path" message and lost the diagnostic detail that the API
       already returns in its `detail` field. */
    function tryNext(lastErr) {
        if (candidates.length === 0) {
            var msg = 'Could not load songs.json from any path. Use "Load JSON" to load manually.';
            if (lastErr && lastErr.message) {
                msg += ' Last error: ' + lastErr.message;
            }
            showToast(msg, 'warning');
            return Promise.resolve();
        }
        var candidate = candidates.shift();
        return _fetchAndParseSongs(candidate).catch(function (err) {
            /* This path failed — try the next one, carrying the
               error forward so we can surface it if every
               candidate also fails. */
            return tryNext(err);
        });
    }

    return tryNext();
}

/**
 * _fetchAndParseSongs(target)
 * ---------------------------
 * Internal helper: fetches and parses a single songs.json URL.
 *
 * @param {string} target - The URL to fetch.
 * @returns {Promise<void>}
 */
function _fetchAndParseSongs(target) {
    /* Fetch the remote JSON file. */
    return fetch(target)
        .then(function (response) {
            /* If the HTTP status indicates failure, try to read the
               server's JSON error body so the toast shows the real
               cause instead of a bare "HTTP 500". The editor's API
               returns `{error, detail, file}` on the load path; older
               / non-API fallback URLs (static songs.json) won't have
               a body, so we fall through to the status-line message. */
            if (!response.ok) {
                return response.text().then(function (body) {
                    var detail = '';
                    try {
                        var parsed = body ? JSON.parse(body) : null;
                        if (parsed && typeof parsed === 'object') {
                            detail = parsed.detail || parsed.error || '';
                        }
                    } catch (_e) { /* not JSON — ignore */ }
                    var msg = 'HTTP ' + response.status + ' ' + response.statusText;
                    if (detail) msg += ' — ' + detail;
                    throw new Error(msg);
                });
            }
            /* Parse the response body as JSON. */
            return response.json();
        })
        .then(function (parsed) {
            /* Store each top-level key into the global songData. */
            songData.meta      = parsed.meta      || {};
            songData.songbooks = parsed.songbooks  || [];
            songData.songs     = parsed.songs      || [];
            rebuildSongIndex();   /* keep the O(1) id index in sync (#1180-A1) */

            /* Reset tracking state. */
            modifiedSongIds.clear();
            currentSongId = null;

            /* Re-render everything. */
            renderSongList();
            clearEditForm();
            updateStatusBar();

            /* Notify the user. */
            showToast('Loaded ' + songData.songs.length + ' song(s).', 'success');
        })
        .catch(function (err) {
            /* Network or parse error — inform the user but don't crash. */
            showToast('Could not load from URL: ' + err.message, 'warning');
        });
}

/**
 * saveSongs()
 * -----------
 * Primary save action for the editor. Persists every song the admin has
 * edited in this session, via POST api.php?action=save_song (one request
 * per modified song, sequentially).
 *
 * Why per-song and not a single bulk write:
 *   - `action=save` rewrites the whole corpus — TRUNCATE + re-INSERT of
 *     every songbook, song, writer, composer and component row. That
 *     blocks the save on validation of songs the user never touched
 *     (a missing component on SDAH-1653 refuses to let you save a fix
 *     on SDAH-93) and risks wiping good data if the POST body is
 *     malformed mid-way.
 *   - `action=save_song` UPSERTs a single song's rows inside a txn and
 *     writes a revision to tblSongRevisions (#400). Exactly the unit
 *     the user worked on.
 *
 * Validation is scoped to the modified set — issues on other songs are
 * surfaced by the standalone "Validate" button (toolbar), not by Save.
 *
 * Invoked by the toolbar "Save" button (#btn-save).
 */
function saveSongs() {
    /* Nothing edited -> no-op with a friendly toast. */
    if (modifiedSongIds.size === 0) {
        showToast('No unsaved changes.', 'info');
        return;
    }

    var ids = Array.from(modifiedSongIds);

    /* Validate only the songs we're about to save. The user should
       never be blocked from saving a fix on song X because song Y (that
       they never opened) is missing a field. #961 — split into hard
       errors (block) and warnings (toast then proceed). Saved a real
       case where a song in an Official-flagged songbook didn't have
       a number yet — the curator wanted to capture the lyrics first
       and number later. */
    var validation = validateSongsByIds(ids);
    if (validation.errors.length > 0) {
        validation.errors.forEach(function (msg) { showToast(msg, 'danger'); });
        return;
    }
    if (validation.warnings.length > 0) {
        validation.warnings.forEach(function (msg) { showToast(msg, 'warning'); });
        /* fall through — warnings are informational, save proceeds */
    }

    /* Stream each song to the per-song endpoint. */
    enqueueSave(ids).then(function (summary) {
        if (summary.saved.length > 0) {
            lastSaveTime = new Date();
            summary.saved.forEach(function (id) { modifiedSongIds.delete(id); });
            renderSongList();
            updateStatusBar();
            showToast(
                'Saved ' + summary.saved.length + ' song' +
                (summary.saved.length === 1 ? '' : 's') + ' to the database.',
                'success'
            );
        }
        if (summary.failed.length > 0) {
            summary.failed.forEach(function (f) {
                showToast('Failed to save ' + f.id + ': ' + f.error, 'danger');
            });
        }
    });
}

/* ========================================================================
 *  SECTION 3 — Song List / Navigation (#53)
 * ======================================================================== */

/**
 * renderSongList(filter)
 * ----------------------
 * Builds the sidebar list of songs. Optionally filters by songbook ID and/or
 * the current search-box text.
 *
 * @param {string} [filter] - Optional songbook ID to restrict the list to.
 */
function renderSongList(filter) {
    /* Grab the <ul> or container element that holds the song list items. */
    var listEl = document.getElementById('song-list');
    if (!listEl) return; // guard against missing DOM element

    /* Clear existing list items. */
    listEl.innerHTML = '';

    /* Read the search input value (lowercased for case-insensitive matching). */
    var searchEl = document.getElementById('song-search');
    var searchTerm = searchEl ? searchEl.value.toLowerCase().trim() : '';

    /* Read the songbook filter dropdown value. */
    var songbookFilter = filter !== undefined ? filter : getSelectedSongbookFilter();

    /* Build the FULL filtered + sorted list. Search + songbook filters run over
       the WHOLE corpus — only the DOM RENDER is incremental. The result is kept
       on the module so the scroll handler can append more rows on demand. */
    var sortedSongs = songData.songs.slice().sort(function (a, b) {
        if (currentSortMode === 'title') {
            return (a.title || '').localeCompare(b.title || '', undefined, { sensitivity: 'base' });
        } else if (currentSortMode === 'number') {
            return (Number(a.number) || 0) - (Number(b.number) || 0);
        } else if (currentSortMode === 'songbook') {
            var sbCmp = (a.songbook || '').localeCompare(b.songbook || '', undefined, { sensitivity: 'base' });
            if (sbCmp !== 0) return sbCmp;
            return (Number(a.number) || 0) - (Number(b.number) || 0);
        }
        return 0;
    });

    _sidebarVisible = sortedSongs.filter(function (song) {
        if (songbookFilter && song.songbook !== songbookFilter) { return false; }
        if (searchTerm) {
            var titleMatch  = (song.title  || '').toLowerCase().indexOf(searchTerm) !== -1;
            var numberMatch = String(song.number || '').toLowerCase().indexOf(searchTerm) !== -1;
            if (!titleMatch && !numberMatch) { return false; }
        }
        return true;
    });
    _sidebarRendered = 0;

    /* Infinite scroll (#1180-B1): bind once. As the user nears the bottom of the
       sidebar we append the next batch, so the editor never builds all ~16k rows
       up front (the slow-open / slow-Edit cause) yet the WHOLE filtered list is
       reachable by scrolling — and search/filter still span the full corpus. */
    if (!_sidebarScrollBound) {
        _sidebarScrollBound = true;
        listEl.addEventListener('scroll', function () {
            if (_sidebarRendered >= _sidebarVisible.length) { return; }
            if (listEl.scrollTop + listEl.clientHeight >= listEl.scrollHeight - 300) {
                renderSidebarBatch();
            }
        });
    }

    renderSidebarBatch();   /* first batch */

    /* If the first batch is shorter than the viewport, keep loading until it
       fills (so there is something to scroll on, and the scroll handler can fire). */
    var _fillGuard = 0;
    while (_sidebarRendered < _sidebarVisible.length
           && listEl.clientHeight > 0
           && listEl.scrollHeight <= listEl.clientHeight + 4
           && _fillGuard++ < 80) {
        renderSidebarBatch();
    }

    /* Update the song-count badge in the sidebar header (full match count). */
    var countEl = document.getElementById('song-count');
    if (countEl) {
        countEl.textContent = _sidebarVisible.length + ' / ' + songData.songs.length;
    }
}

/* Sidebar incremental-render state (#1180-B1). */
var SIDEBAR_BATCH       = 200;   /* rows built per batch */
var _sidebarVisible     = [];    /* the full filtered+sorted song list */
var _sidebarRendered    = 0;     /* how many rows are currently in the DOM */
var _sidebarScrollBound = false;

/* Append the next batch of sidebar rows from _sidebarVisible (#1180-B1). */
function renderSidebarBatch() {
    var listEl = document.getElementById('song-list');
    if (!listEl) { return; }
    var end  = Math.min(_sidebarRendered + SIDEBAR_BATCH, _sidebarVisible.length);
    var frag = document.createDocumentFragment();
    for (var i = _sidebarRendered; i < end; i++) {
        frag.appendChild(buildSongListRow(_sidebarVisible[i]));
    }
    listEl.appendChild(frag);
    _sidebarRendered = end;
}

/* Build one sidebar row <a> for a song — extracted so the initial render and the
   scroll-loaded batches share identical markup + behaviour (#1180-B1). */
function buildSongListRow(song) {
    var li = document.createElement('a');
    li.href = '#';
    li.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';

    /* Multi-select mode (#399): a checkbox that toggles selection without
       navigating to the song. */
    if (window._selectMode) {
        var cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.className = 'form-check-input me-2 flex-shrink-0';
        cb.dataset.songId = song.id;
        cb.checked = window._selectedIds && window._selectedIds.has(song.id);
        cb.addEventListener('click', function (e) { e.stopPropagation(); });
        cb.addEventListener('change', function () {
            if (!window._selectedIds) window._selectedIds = new Set();
            if (cb.checked) window._selectedIds.add(song.id);
            else window._selectedIds.delete(song.id);
            updateBulkActionsBar();
        });
        li.appendChild(cb);
    }

    /* Highlight the currently selected song. */
    if (song.id === currentSongId) { li.classList.add('active'); }

    /* "<#>  Title" — the number sits in a fixed-width, right-aligned, tabular-nums
       column so every title lines up regardless of a 1–4 digit number (or no
       number for Misc / unofficial books, which show a muted dash). Tidies the
       ragged sidebar left edge (#1344); replaces the old unpadded "number - title". */
    var label = document.createElement('span');
    label.className = 'd-flex align-items-baseline gap-2 text-truncate';
    label.style.cssText = 'flex:1 1 auto; min-width:0;';
    var numSpan = document.createElement('span');
    var _hasNum = song.number != null && String(song.number).trim() !== '';
    numSpan.textContent = _hasNum ? String(song.number) : '–';
    numSpan.style.cssText = 'flex-shrink:0; min-width:3em; text-align:right; font-variant-numeric:tabular-nums; opacity:.6;';
    var titleSpan = document.createElement('span');
    titleSpan.className = 'text-truncate';
    titleSpan.textContent = toTitleCase(song.title || 'Untitled');
    label.appendChild(numSpan);
    label.appendChild(titleSpan);

    /* Right-side badges: modified indicator + songbook abbreviation (#249). */
    var badges = document.createElement('span');
    badges.className = 'd-flex align-items-center gap-1 flex-shrink-0';
    if (modifiedSongIds.has(song.id)) {
        var modBadge = document.createElement('span');
        modBadge.className = 'badge bg-warning text-dark';
        modBadge.style.fontSize = '0.65rem';
        modBadge.textContent = 'modified';
        badges.appendChild(modBadge);
    }
    if (song.songbook) {
        var sbBadge = document.createElement('span');
        sbBadge.className = 'badge rounded-pill';
        sbBadge.style.cssText = 'background-color: rgba(245,158,11,0.15); color: #f59e0b; font-size: 0.65rem; font-weight: 600;';
        sbBadge.textContent = song.songbook;
        badges.appendChild(sbBadge);
    }

    li.appendChild(label);
    li.appendChild(badges);

    /* Clicking a row loads the song into the editor. */
    li.addEventListener('click', function (e) {
        e.preventDefault();
        selectSong(song.id);
    });
    return li;
}

/**
 * getSelectedSongbookFilter()
 * ---------------------------
 * Reads the value of the songbook-filter <select> in the sidebar.
 *
 * @returns {string} The selected songbook ID, or '' for "all songbooks".
 */
function getSelectedSongbookFilter() {
    var sel = document.getElementById('songbook-filter');
    return sel ? sel.value : '';
}

/**
 * updateNumberLabelRequiredness(abbr)
 * -----------------------------------
 * Toggles the red `*` and the "Optional — this songbook is unofficial"
 * hint next to the Song Number input based on whether the songbook
 * `abbr` carries IsOfficial=1 in songData.songbooks. Required for
 * official songbooks (SDAH, ACH, …); optional for unofficial ones
 * (Misc, custom collections). #392.
 *
 * @param {string} abbr Songbook abbreviation (or empty string).
 */
function updateNumberLabelRequiredness(abbr) {
    var star  = document.getElementById('edit-number-required');
    var hint  = document.getElementById('edit-number-hint');
    var input = document.getElementById('edit-number');
    if (!star || !hint || !input) return;

    /* No songbook selected: hide both — there's nothing to require yet. */
    var trimmed = (abbr || '').trim();
    if (trimmed === '') {
        star.hidden = true;
        hint.hidden = true;
        input.removeAttribute('aria-required');
        return;
    }

    var sb = (songData.songbooks || []).find(function (s) { return s.id === trimmed; });
    var isOfficial = !!(sb && sb.isOfficial);
    star.hidden = !isOfficial;
    hint.hidden = isOfficial;
    if (isOfficial) {
        input.setAttribute('aria-required', 'true');
    } else {
        input.removeAttribute('aria-required');
    }
}

/**
 * populateSongbookFilterDropdown()
 * --------------------------------
 * Fills the songbook filter <select> (and the metadata songbook dropdown)
 * with options derived from songData.songbooks.
 */
function populateSongbookFilterDropdown() {
    /* --- Sidebar filter dropdown --- */
    var filterEl = document.getElementById('songbook-filter');
    if (filterEl) {
        /* Keep the first "All Songbooks" option; remove the rest. */
        filterEl.innerHTML = '<option value="">All Songbooks</option>';

        /* Add one <option> per songbook. */
        songData.songbooks.forEach(function (sb) {
            var opt = document.createElement('option');
            opt.value = sb.id;
            opt.textContent = sb.name || sb.id;
            filterEl.appendChild(opt);
        });
    }

    /* --- Metadata songbook dropdown inside the edit form --- */
    var metaSel = document.getElementById('edit-songbook');
    if (metaSel) {
        metaSel.innerHTML = '<option value="">-- select --</option>';

        songData.songbooks.forEach(function (sb) {
            var opt = document.createElement('option');
            opt.value = sb.id;
            opt.textContent = sb.name || sb.id;
            metaSel.appendChild(opt);
        });
    }
}

/**
 * selectSong(songId)
 * ------------------
 * Finds the song with the given ID and loads it into the edit form.
 *
 * @param {string} songId - The unique ID of the song to select.
 */
function selectSong(songId) {
    /* Find the song stub in the slim index. */
    var song = songData.songs.find(function (s) { return s.id === songId; });
    if (!song) {
        showToast('Song not found: ' + songId, 'danger');
        return;
    }

    /* #833 — flush any pending External-Links DOM changes back into the
       song that is actually RENDERED before we navigate away. Keyed off
       _renderedSongId (not currentSongId) so a rapid A→B→A switch can't
       flush the displayed song's DOM into a different song (WS-D #1016). */
    if (typeof _renderedSongId !== 'undefined' && _renderedSongId && _renderedSongId !== songId) {
        try { syncSongExternalLinksFromDom(); } catch (_e) { /* defensive — must not block navigation */ }
    }

    /* Store the selection globally + highlight it in the sidebar right
       away, so the active state and the currentSongId async-guards engage
       even while the full record is still loading. */
    currentSongId = songId;
    updateHistoryButtonState();
    renderSongList();

    /* WS-D #1016 — the sidebar carries only the slim index, so fetch the
       full editable record per song on open. Songs already loaded
       (_full), freshly added, or imported are populated straight from
       memory; everything else is fetched live from MySQL. */
    if (song._full) {
        _populateSongForm(song);
        return;
    }

    /* Clear the form to a loading state so the previous song's data isn't
       left on screen (mismatching the sidebar highlight) during the fetch. */
    clearEditForm();
    _setEditorLoading(true);

    _loadSongFull(songId).then(function (full) {
        if (currentSongId !== songId) return; /* user switched away mid-fetch */
        _setEditorLoading(false);
        if (!full) {
            /* Deleted / not found — keep the form empty rather than
               populating a slim stub (which would look like an empty song
               and, if saved, wipe its DB rows). currentSongId is reset so
               a subsequent Save can't write under this id. */
            currentSongId = null;
            renderSongList();
            updateStatusBar();
            showToast('Song not found: ' + songId, 'danger');
            return;
        }
        Object.assign(song, full);
        song._full = true;
        _populateSongForm(song);
    }).catch(function (err) {
        if (currentSongId !== songId) return;
        _setEditorLoading(false);
        /* Fetch failed (offline / 500). Leave the form empty + deselect so
           the form never misrepresents another song under this id. */
        currentSongId = null;
        clearEditForm();
        renderSongList();
        updateStatusBar();
        showToast('Could not load song ' + songId + ': ' +
                  (err && err.message ? err.message : err), 'danger');
    });
}

/**
 * _setEditorLoading(on)
 * ---------------------
 * Toggles a lightweight "loading song" hint in the status bar while a
 * per-record fetch is in flight (WS-D #1016). Kept minimal — the form is
 * already cleared, so there is no stale data; this just signals progress.
 */
function _setEditorLoading(on) {
    var statusEl = document.getElementById('status-text');
    if (!statusEl) return;
    /* Restore the resting "Ready" text on clear so the indicator can't get
       stuck (e.g. when a quick switch to an already-loaded song renders
       synchronously and never re-runs the fetch path). */
    statusEl.textContent = on ? 'Loading song…' : 'Ready';
}

/**
 * _loadSongFull(songId)
 * ---------------------
 * Fetches ONE song's full editable record from MySQL (WS-D #1016) — the
 * per-record replacement for reading it out of the old in-memory corpus.
 *
 * @param {string} songId
 * @returns {Promise<object|null>} The full song, or null if not found.
 */
function _loadSongFull(songId) {
    return fetch(LOAD_SONG_URL + '&id=' + encodeURIComponent(songId), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
    }).then(function (r) {
        if (!r.ok) {
            return r.text().then(function (body) {
                var detail = '';
                try {
                    var p = body ? JSON.parse(body) : null;
                    if (p && typeof p === 'object') detail = p.detail || p.error || '';
                } catch (_e) { /* not JSON */ }
                throw new Error('HTTP ' + r.status + (detail ? ' — ' + detail : ''));
            });
        }
        return r.json();
    }).then(function (d) { return d && d.song ? d.song : null; });
}

/**
 * _loadSongsFull(ids)
 * -------------------
 * Batch-fetches the full editable records for the given song IDs (WS-D
 * #1016) and merges them into their slim sidebar stubs, marking each
 * _full. Used by the multi-select bulk operations (verify / move /
 * export) so they never mutate or persist a slim stub — which would make
 * save_song DELETE-then-INSERT empty credits/components and wipe the
 * song's lyrics. Only fetches the stubs that aren't already _full.
 *
 * @param {string[]} ids
 * @returns {Promise<void>}
 */
function _loadSongsFull(ids) {
    var need = (ids || []).filter(function (id) {
        var s = (songData.songs || []).find(function (x) { return x.id === id; });
        return s && !s._full;
    });
    if (!need.length) return Promise.resolve();

    return fetch(EDITOR_API_URL + '?action=load_songs', {
        method:      'POST',
        headers:     { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body:        JSON.stringify({ ids: need })
    }).then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    }).then(function (d) {
        var byId = Object.create(null);
        (d && d.songs || []).forEach(function (full) {
            if (full && full.id) byId[full.id] = full;
        });
        (songData.songs || []).forEach(function (s) {
            if (byId[s.id]) {
                Object.assign(s, byId[s.id]);
                s._full = true;
            }
        });
        /* Any id we asked for but didn't get back no longer exists in the
           DB — surface it rather than silently bulk-editing a ghost. */
        var missing = need.filter(function (id) { return !byId[id]; });
        if (missing.length) {
            throw new Error(missing.length + ' selected song(s) could not be loaded.');
        }
    });
}

/**
 * _populateSongForm(song)
 * -----------------------
 * Renders an open song's full record into the edit form. Split out of
 * selectSong() (WS-D #1016) so the per-record fetch can populate the form
 * once the data arrives.
 *
 * @param {object} song Full editable song record
 */
function _populateSongForm(song) {
    /* This song's full record is now the one on screen — track it so the
       external-links flush keys off the displayed song (WS-D #1016). */
    _renderedSongId = song.id;
    /* A song is rendering — clear any "Loading song…" indicator. Covers the
       synchronous _full path (no fetch) and the async success path. */
    _setEditorLoading(false);

    /* Enable the toolbar's Export-current-song button (#883 follow-up).
       Stays disabled while no song is open so the user can't trigger
       an empty-payload download. */
    var exportSongBtn = document.getElementById('btn-export-song');
    if (exportSongBtn) exportSongBtn.disabled = false;

    /* #853 — broadcast a song-loaded event so independent modules
       (e.g. the Media tab editor) can refresh their state without
       having to monkey-patch this function. The detail.songId is
       canonical; window.currentSongId stays the source of truth
       for late-joiners that miss the event. */
    try {
        document.dispatchEvent(new CustomEvent('iHymns:song-loaded', {
            detail: { songId: song.id }
        }));
    } catch (_e) { /* IE11 polyfill territory; harmless to skip */ }

    /* Show the editor form, hide the empty state (#246). */
    var editorEmpty = document.getElementById('editorEmpty');
    var editorForm = document.getElementById('editorForm');
    if (editorEmpty) editorEmpty.style.display = 'none';
    if (editorForm) editorForm.style.display = '';

    /* Populate the metadata fields. */
    setVal('edit-title', song.title || '');
    setVal('edit-number', song.number || '');
    setVal('edit-songbook', song.songbook || '');
    /* Show/hide the red `*` next to "Song Number" based on the loaded
       song's songbook IsOfficial flag (#392). */
    updateNumberLabelRequiredness(song.songbook || '');
    setVal('edit-ccli', song.ccli || '');
    setVal('edit-iswc', song.iswc || '');            /* #497 */
    setVal('edit-tune-name', song.tuneName || '');   /* #497 */
    /* Places adoption — pre-fill both the visible display string +
       the hidden tblPlaces.Id sidecar so the place-search module's
       attach() finds the input "already picked". */
    setVal('edit-origin-city', song.originCity || '');
    setVal('edit-origin-city-id', song.originCityId ? String(song.originCityId) : '');
    /* Hand the saved IETF BCP 47 tag to the shared picker (#687). The
       picker is booted by the module script in index.php and stashed
       on window.editSongIetfPicker; setTag() decomposes the tag,
       resolves the codes against the live tblScripts / tblRegions
       endpoints, and writes the composed tag back to the hidden
       #edit-language field. */
    if (window.editSongIetfPicker && typeof window.editSongIetfPicker.setTag === 'function') {
        window.editSongIetfPicker.setTag(song.language || 'en');
    }

    /* Populate the boolean checkboxes (#222, #225). */
    setChecked('edit-verified', !!song.verified);
    setChecked('edit-lyricsPublicDomain', !!song.lyricsPublicDomain);
    setChecked('edit-musicPublicDomain', !!song.musicPublicDomain);

    /* Render the structure / arrangement components. */
    renderComponents(song);

    /* Render the arrangement editor (#161). */
    renderArrangement(song);

    /* Render all five credit chip lists — writers + composers are
       long-standing; arrangers / adaptors / translators are the new
       #497 collections. */
    renderWriters(song);
    renderComposers(song);
    renderArrangers(song);    /* #497 */
    renderAdaptors(song);     /* #497 */
    renderTranslators(song);  /* #497 */
    renderArtists(song);      /* #587 */

    /* Render the translations (cross-song link) panel (#352). */
    renderTranslations(song);

    /* Render external website links (#833). Mounts the shared
       /js/modules/external-links-editor.js card-list editor on the
       Links tab and hydrates with song.links from the corpus cache. */
    renderSongExternalLinks(song);

    /* Render cross-book counterparts (#807) and pull suggestions
       for the open song (#808). Both are async — the panels show
       a loading hint until their fetch resolves, and a switched
       song mid-flight cancels via the currentSongId guard inside
       each callback. */
    renderSongLinks(song);

    /* Fetch + render this song's tags (#496). Async — the chip list
       shows "Loading…" until the fetch resolves; failures keep the
       list empty rather than blocking the whole editor. */
    loadSongTags(song);

    /* Render the copyright textarea. */
    setVal('edit-copyright', song.copyright || '');
    /* Reflect the PD-disables-Copyright rule (#594) on initial render
       so a fully-PD song shows a disabled, placeholder-styled textarea
       even before the user toggles a checkbox. */
    updateCopyrightFieldState();

    /* Render the live preview pane. */
    renderPreview(song);

    /* Re-render the song list so the active highlight updates. */
    renderSongList();

    /* Update the status bar. */
    updateStatusBar();
}

/* ========================================================================
 *  SECTION 4 — Metadata Editing (#54, #57)
 * ======================================================================== */

/**
 * bindMetadataListeners()
 * -----------------------
 * Attaches 'input' event listeners to the metadata form fields so that
 * every keystroke is immediately saved back into the song object (live binding).
 */
function bindMetadataListeners() {
    /* List of text/select field IDs mapped to their corresponding song-object keys. */
    var fields = [
        { elId: 'edit-title',     key: 'title' },
        { elId: 'edit-number',    key: 'number' },
        { elId: 'edit-songbook',  key: 'songbook' },
        { elId: 'edit-ccli',      key: 'ccli' },
        { elId: 'edit-iswc',      key: 'iswc' },        /* #497 */
        { elId: 'edit-tune-name', key: 'tuneName' },    /* #497 */
        { elId: 'edit-origin-city', key: 'originCity' },     /* places adoption */
        /* Hidden sidecar — driven by the place-search module's
           synthetic change event, not by user typing. Same listener
           pattern still applies. */
        { elId: 'edit-origin-city-id', key: 'originCityId',
          coerce: function (v) { return v ? Number(v) : null; } },
        { elId: 'edit-copyright', key: 'copyright' }
    ];

    /* Attach a listener to each text/select field. */
    fields.forEach(function (field) {
        var el = document.getElementById(field.elId);
        if (!el) return; // guard

        /* Listen for both 'input' (typing) and 'change' (dropdowns). */
        ['input', 'change'].forEach(function (eventType) {
            el.addEventListener(eventType, function () {
                /* Only act if a song is currently selected. */
                if (!currentSongId) return;

                /* Find the song in the data array. */
                var song = findSongById(currentSongId);
                if (!song) return;

                /* Write the new value back to the song object —
                   running it through field.coerce when one is set
                   (e.g. originCityId converts the hidden input's
                   string into Number / null so the JSON body the
                   save_song endpoint receives is correctly typed). */
                song[field.key] = (typeof field.coerce === 'function')
                    ? field.coerce(el.value)
                    : el.value;

                /* Sync songbookName when songbook changes (#245). */
                if (field.key === 'songbook') {
                    var sb = songData.songbooks.find(function (s) { return s.id === el.value; });
                    song.songbookName = sb ? sb.name : el.value;
                    /* Re-evaluate the Song Number `*` requirement (#392).
                       Switching from an official songbook to an unofficial
                       one (or vice-versa) should immediately update the
                       label so the user sees the rule applied to them. */
                    updateNumberLabelRequiredness(el.value);
                }

                /* Mark this song as modified. */
                markModified(song.id);
            });
        });
    });

    /* Boolean checkbox fields (#222, #225). */
    var checkboxFields = [
        { elId: 'edit-verified',           key: 'verified' },
        { elId: 'edit-lyricsPublicDomain', key: 'lyricsPublicDomain' },
        { elId: 'edit-musicPublicDomain',  key: 'musicPublicDomain' }
    ];

    checkboxFields.forEach(function (field) {
        var el = document.getElementById(field.elId);
        if (!el) return;

        el.addEventListener('change', function () {
            if (!currentSongId) return;
            var song = findSongById(currentSongId);
            if (!song) return;

            /* Write the boolean value back to the song object. */
            song[field.key] = el.checked;
            markModified(song.id);

            /* PD checkboxes drive the Copyright textarea state (#594).
               When both are ticked the field becomes disabled — and if
               it had content, we offer to clear it. */
            if (field.key === 'lyricsPublicDomain' || field.key === 'musicPublicDomain') {
                updateCopyrightFieldState({ song: song, fromCheckboxClick: true });
            }
        });
    });

    /* Language picker (#687) — the shared module composes the tag and
       writes it into the hidden #edit-language field on every change.
       We just watch that one hidden field and propagate to song.language.
       Listening on the wrapper's three visible inputs gives us the
       compose-on-blur behaviour the picker provides; listening on the
       hidden field would only fire if some external code set its value
       directly. */
    var pickerRoot = document.querySelector('.ietf-picker[data-ietf-picker-id="edit-song"]');
    if (pickerRoot) {
        var commitLanguage = function () {
            if (!currentSongId) return;
            var song = findSongById(currentSongId);
            if (!song) return;
            var hidden = document.getElementById('edit-language');
            if (!hidden) return;
            song.language = hidden.value || 'en';
            markModified(song.id);
        };
        ['input', 'change', 'blur'].forEach(function (eventType) {
            pickerRoot.addEventListener(eventType, commitLanguage, true);
        });
    }
}

/* ========================================================================
 *  SECTION 5 — Structure / Arrangement Editing (#55)
 * ======================================================================== */

/**
 * Allowed component type values for the type dropdown.
 * Order matches common hymnal convention.
 */
var COMPONENT_TYPES = [
    'verse',
    'chorus',
    'bridge',
    'pre-chorus',
    'tag',
    'coda',
    'intro',
    'outro',
    'interlude',
    'vamp',
    'ad-lib'
];

/**
 * renderComponents(song)
 * ----------------------
 * Builds the list of editable component cards inside the #components-container.
 * Each card has: type dropdown, number input, lyrics textarea, and action buttons.
 *
 * @param {Object} song - The song object whose components should be rendered.
 */
function renderComponents(song) {
    /* Grab the container element. */
    var container = document.getElementById('componentList');
    if (!container) return;

    /* Clear any existing cards. */
    container.innerHTML = '';

    /* Ensure the song has a components array. */
    if (!song.components) {
        song.components = [];
    }

    /* Toggle the "no components" empty state. */
    var compEmpty = document.getElementById('componentListEmpty');
    if (compEmpty) {
        compEmpty.style.display = song.components.length > 0 ? 'none' : '';
    }

    /* Build one card per component. */
    song.components.forEach(function (comp, index) {
        /* Create the card wrapper. */
        var card = document.createElement('div');
        card.className = 'card mb-3 component-card';
        card.dataset.index = index; // store the position for move/remove logic

        /* ---- Card header with type & number ---- */
        var header = document.createElement('div');
        header.className = 'card-header d-flex align-items-center gap-2 flex-wrap';

        /* Type dropdown */
        var typeSelect = document.createElement('select');
        typeSelect.className = 'form-select form-select-sm';
        typeSelect.style.width = '160px';
        COMPONENT_TYPES.forEach(function (t) {
            var opt = document.createElement('option');
            opt.value = t;
            opt.textContent = t.charAt(0).toUpperCase() + t.slice(1); // capitalise
            if (t === comp.type) opt.selected = true;
            typeSelect.appendChild(opt);
        });
        /* Live-bind the type dropdown change back to the data. */
        typeSelect.addEventListener('change', function () {
            comp.type = typeSelect.value;
            markModified(song.id);
            renderArrangement(song); // refresh arrangement chips (#161)
            renderPreview(song); // refresh preview
        });

        /* Number input — optional (#491). Empty / 0 stores as null so
           a single-instance component reads as "Chorus" (no number). */
        var numInput = document.createElement('input');
        numInput.type = 'number';
        numInput.className = 'form-control form-control-sm';
        numInput.style.width = '110px';
        numInput.placeholder = '(optional)';
        numInput.min = '0';
        numInput.value = (comp.number != null && comp.number > 0) ? comp.number : '';
        /* Live-bind number changes. */
        numInput.addEventListener('input', function () {
            comp.number = numInput.value ? parseInt(numInput.value, 10) : null;
            markModified(song.id);
            /* Update the header label in place so the user sees the
               effect of their edit without re-rendering every card. */
            typeLabel.textContent = componentHeaderLabel(comp);
            renderArrangement(song); // refresh arrangement chips (#161)
            renderPreview(song);
        });

        /* Header label — type + optional number, not the generic
           "Component N" position we used to show (#491). */
        var typeLabel = document.createElement('span');
        typeLabel.className = 'fw-semibold me-auto';
        typeLabel.textContent = componentHeaderLabel(comp);

        /* Keep label in sync when the user changes the type dropdown. */
        typeSelect.addEventListener('change', function () {
            typeLabel.textContent = componentHeaderLabel(comp);
        });

        /* ---- Action buttons (move up, move down, remove) ---- */
        var btnGroup = document.createElement('div');
        btnGroup.className = 'btn-group btn-group-sm';

        /* Move Up button. */
        var btnUp = document.createElement('button');
        btnUp.type = 'button';
        btnUp.className = 'btn btn-outline-secondary';
        btnUp.innerHTML = '&uarr;';
        btnUp.title = 'Move Up';
        btnUp.disabled = (index === 0); // cannot move the first item up
        btnUp.addEventListener('click', function () {
            moveComponent(song, index, -1); // move up by one position
        });

        /* Move Down button. */
        var btnDown = document.createElement('button');
        btnDown.type = 'button';
        btnDown.className = 'btn btn-outline-secondary';
        btnDown.innerHTML = '&darr;';
        btnDown.title = 'Move Down';
        btnDown.disabled = (index === song.components.length - 1); // last item
        btnDown.addEventListener('click', function () {
            moveComponent(song, index, 1); // move down by one position
        });

        /* Remove button. */
        var btnRemove = document.createElement('button');
        btnRemove.type = 'button';
        btnRemove.className = 'btn btn-outline-danger';
        btnRemove.innerHTML = '&times;';
        btnRemove.title = 'Remove Component';
        btnRemove.addEventListener('click', function () {
            removeComponent(song, index); // remove with confirmation
        });

        /* Assemble the button group. */
        btnGroup.appendChild(btnUp);
        btnGroup.appendChild(btnDown);
        btnGroup.appendChild(btnRemove);

        /* #858 / #1253 — per-component language override. A TEXT input with a
           shared languages datalist (not a fixed <select>) so a curator can enter
           a FULL BCP 47 tag — base language plus optional script / region /
           variant (pt-BR, zh-Hans-CN, es-419) — not just a seeded base language.
           Empty = inherit from the parent song's language. The datalist offers
           the active languages as suggestions; any valid tag may be typed
           directly and is validated server-side on save (_ietfBcp47Validate).
           A previously-saved tag that isn't in the suggestion list is preserved
           because it's simply the input's current value, never silently reverted. */
        ensureComponentLangDatalist();
        var langSelect = document.createElement('input');
        langSelect.type = 'text';
        langSelect.className = 'form-control form-control-sm component-language-select';
        langSelect.style.maxWidth = '160px';
        langSelect.setAttribute('list', 'component-lang-datalist');
        langSelect.setAttribute('autocomplete', 'off');
        langSelect.placeholder = 'Same as song';
        langSelect.title = 'Language override (optional) — a BCP 47 tag like en, pt-BR, zh-Hans. Blank = same as the song.';
        langSelect.value = comp.language ? String(comp.language) : '';
        langSelect.addEventListener('input', function () {
            comp.language = langSelect.value.trim() || null;
            markModified(song.id);
        });

        /* #1345 — a structured-picker affordance beside the free-text input so a
           curator can compose language + script + region (→ ko-Latn) by picking,
           instead of knowing the raw tag. Directly answers "where do I set Verse 2 =
           Korean in Latin script?". The text input stays for quick entry / power
           users; the picker writes its composed tag back into it on Save. */
        var compLangPickBtn = document.createElement('button');
        compLangPickBtn.type = 'button';
        compLangPickBtn.className = 'btn btn-sm btn-outline-secondary';
        compLangPickBtn.innerHTML = '<i class="bi bi-translate" aria-hidden="true"></i>';
        compLangPickBtn.title = 'Pick language with script / region (e.g. ko-Latn)';
        compLangPickBtn.setAttribute('aria-label', 'Pick component language with script / region');
        compLangPickBtn.addEventListener('click', function () {
            /* Toggle: a second click (or Cancel) closes the open form. */
            var existing = body.querySelector('.cp-complang-form');
            if (existing) { existing.remove(); return; }
            var form = document.createElement('div');
            form.className = 'cp-complang-form mb-2 p-2 border rounded bg-body-tertiary';
            var picker = buildInlineIetfPicker(comp.language || '');
            form.appendChild(picker.el);
            var btnRow = document.createElement('div');
            btnRow.className = 'mt-1 d-flex gap-1';
            var saveBtn = document.createElement('button');
            saveBtn.type = 'button';
            saveBtn.className = 'btn btn-sm btn-primary py-0 px-2';
            saveBtn.textContent = 'Save';
            /* Editing an existing tag: block Save until the async pre-fill resolves,
               so a fast click can't read '' and clear it (mirrors the per-line form). */
            if (comp.language) {
                saveBtn.disabled = true;
                picker.ready.then(function () { saveBtn.disabled = false; });
            }
            saveBtn.addEventListener('click', function () {
                comp.language = picker.getTag() || null;
                langSelect.value = comp.language || '';
                markModified(song.id);
                form.remove();
            });
            var cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.className = 'btn btn-sm btn-outline-secondary py-0 px-2';
            cancelBtn.textContent = 'Cancel';
            cancelBtn.addEventListener('click', function () { form.remove(); });
            btnRow.appendChild(saveBtn);
            btnRow.appendChild(cancelBtn);
            form.appendChild(btnRow);
            body.insertBefore(form, body.firstChild);
        });

        /* Assemble the header. */
        header.appendChild(typeLabel);
        header.appendChild(typeSelect);
        header.appendChild(numInput);
        header.appendChild(langSelect);
        header.appendChild(compLangPickBtn);
        header.appendChild(btnGroup);

        /* ---- Card body with lyrics textarea ---- */
        var body = document.createElement('div');
        body.className = 'card-body';

        var textarea = document.createElement('textarea');
        textarea.className = 'form-control component-lyrics';
        textarea.rows = 1;  /* Seed at minimum; autoResizeTextarea grows it to content (#490). */
        textarea.placeholder = 'Enter lyrics here...';
        /* Convert lines array to newline-separated string for editing (#244). */
        textarea.value = Array.isArray(comp.lines) ? comp.lines.join('\n') : '';
        /* Live-bind lyrics changes — split back into lines array on every edit. */
        textarea.addEventListener('input', function () {
            comp.lines = textarea.value.split('\n');
            markModified(song.id);
            autoResizeTextarea(textarea); // re-fit height (cheap, keep immediate)
            schedulePreview(song);        // debounced full preview rebuild (#1180-A3)
        });

        body.appendChild(textarea);

        /* #1094 — optional manual per-line chords (collapsible). One line of
           space-separated chord symbols per lyric line; saved to ChordsJson
           (parallel to the lyrics). Hidden until the curator opens it, or shown
           when the component already has chords. */
        var chordsWrap = document.createElement('div');
        chordsWrap.className = 'mt-2';
        var hasChords = Array.isArray(comp.chords) && comp.chords.some(function (c) {
            return c && (Array.isArray(c) ? c.length : String(c).trim());
        });
        var chordsToggle = document.createElement('button');
        chordsToggle.type = 'button';
        chordsToggle.className = 'btn btn-sm btn-link p-0 text-decoration-none';
        chordsToggle.innerHTML = '<i class="bi bi-music-note-beamed me-1"></i>Chords';
        var chordsBox = document.createElement('div');
        chordsBox.className = 'mt-1';
        chordsBox.style.display = hasChords ? '' : 'none';
        var chordsArea = document.createElement('textarea');
        chordsArea.className = 'form-control form-control-sm component-chords font-monospace';
        chordsArea.rows = 2;
        chordsArea.placeholder = 'One line of chords per lyric line, e.g.  C    G    Am';
        chordsArea.value = componentChordsToText(comp);
        chordsArea.addEventListener('input', function () {
            comp.chords = chordsArea.value.split('\n').map(function (l) { return l.trim(); });
            markModified(song.id);
        });
        chordsToggle.addEventListener('click', function () {
            chordsBox.style.display = (chordsBox.style.display === 'none') ? '' : 'none';
            if (chordsBox.style.display !== 'none') { chordsArea.focus(); }
        });
        var chordsHint = document.createElement('div');
        chordsHint.className = 'form-text small';
        chordsHint.textContent = 'Optional. Each chord line lines up with the lyric line above it.';
        chordsBox.appendChild(chordsArea);
        chordsBox.appendChild(chordsHint);
        chordsWrap.appendChild(chordsToggle);
        chordsWrap.appendChild(chordsBox);
        body.appendChild(chordsWrap);

        /* #1253 → #1345 — per-line language overrides MOVED out of this card body.
           The old free-text monospace textarea ("one BCP 47 tag per line") was hard
           to use; per-line language is now an INLINE control inside the per-line
           translations/annotations panel below (a 🌐 chip + a structured IETF picker
           per line, mirroring the translations UX). Storage is unchanged —
           comp.languages[i] (parallel to comp.lines) → LanguagesJson on save. */

        /* #1235 P3 / #1088 — per-line translations + annotations editor
           (collapsible). Keys off the saved line Ids (comp.lineIds); writes to
           the CSRF-protected v2 API. */
        body.appendChild(buildEnrichmentPanel(song, comp));

        /* Assemble the full card. */
        card.appendChild(header);
        card.appendChild(body);

        /* Add the card to the container. */
        container.appendChild(card);
    });

    /* Auto-size every textarea we just inserted (#490).
       Must be called AFTER the cards are in the DOM — `scrollHeight`
       on a detached or `display:none` textarea returns 0, which left
       the boxes stuck at one line on initial load even when they held
       several lines of lyrics. The tab-shown hook installed once at
       boot (see below) re-fits them when the Structure tab is opened
       from a hidden state. */
    fitAllComponentTextareas(container);
}

/**
 * componentHeaderLabel(comp)
 * --------------------------
 * Render the human-friendly heading for a component card (#491):
 *   { type: "chorus", number: 0|null }   → "Chorus"
 *   { type: "verse",  number: 2 }        → "Verse 2"
 *   { type: "bridge", number: 1 }        → "Bridge 1"   (kept — author explicitly set it)
 *
 * A null / empty / non-positive number is treated as "no number" and
 * suppressed. We no longer prefix every card with "Component N"
 * where N was just the row position — the type already names the
 * row, and the row's vertical order already encodes the position.
 *
 * @param {{type:string, number:(number|null|string)}} comp
 * @returns {string}
 */
/**
 * componentChordsToText(comp) — render a component's ChordsJson back into the
 * editable textarea: one line per lyric line, chords space-separated. Loaded
 * chords are arrays (["C","G"]); freshly-typed lines are strings ("C G"). (#1094)
 * @param {{chords?:Array}} comp
 * @returns {string}
 */
function componentChordsToText(comp) {
    if (!Array.isArray(comp.chords)) { return ''; }
    return comp.chords.map(function (c) {
        if (Array.isArray(c)) { return c.join(' '); }
        return (c == null) ? '' : String(c);
    }).join('\n');
}

/* componentLanguagesToText() removed in #1345 — per-line language is no longer a
   newline-joined textarea; it's edited inline per line via the IETF picker
   (buildInlineIetfPicker) in the translations/annotations panel. */

/**
 * ensureComponentLangDatalist() — create (once) a shared <datalist> of the active
 * languages that every per-component language input references via list=. Built
 * from window.iHymnsLanguageOptions (pre-loaded at editor boot from
 * /api?action=languages). Idempotent — returns the existing node on re-render so
 * we don't rebuild it per component card. (#1253)
 * @returns {HTMLDataListElement}
 */
function ensureComponentLangDatalist() {
    var dl = document.getElementById('component-lang-datalist');
    if (dl) { return dl; }
    dl = document.createElement('datalist');
    dl.id = 'component-lang-datalist';
    var opts = Array.isArray(window.iHymnsLanguageOptions) ? window.iHymnsLanguageOptions : [];
    opts.forEach(function (opt) {
        var o = document.createElement('option');
        o.value = opt.code;
        o.label = opt.name + ' (' + opt.code + ')';
        dl.appendChild(o);
    });
    document.body.appendChild(dl);
    return dl;
}

/* Monotonic id source so every dynamically-built IETF picker gets unique
   datalist ids (the module resolves its lists by `getElementById(input.list)`,
   so two instances sharing an id would clobber each other's suggestions). */
var _ed2IetfPickerSeq = 0;

/**
 * buildInlineIetfPicker(initialTag) — construct a compact, structured IETF BCP 47
 * picker (Language / Script / Region) on demand and boot it via the shared module
 * (window.bootIetfLanguagePicker, exposed by editor/index.php). Used for per-line
 * language overrides and the per-line translation language field (#1345), so a
 * curator picks "Korean" + "Latin" → ko-Latn instead of typing a raw tag.
 *
 * Returns { el, booted, getTag() } where el is the picker root (append it into a
 * form) and getTag() reads the composed BCP 47 tag. If the ES module hasn't loaded
 * (it's deferred; pickers are only built on user interaction, long after) the
 * helper DEGRADES to a single free-text input with the languages datalist so the
 * field still works.
 *
 * @param {string} initialTag  saved BCP 47 tag to pre-fill (or '')
 * @returns {{el:HTMLElement, booted:boolean, getTag:function():string}}
 */
function buildInlineIetfPicker(initialTag) {
    var tag = (initialTag == null) ? '' : String(initialTag);

    /* Graceful fallback — module not available: a single BCP 47 text input. */
    if (typeof window.bootIetfLanguagePicker !== 'function') {
        ensureComponentLangDatalist();
        var fb = document.createElement('input');
        fb.type = 'text';
        fb.className = 'form-control form-control-sm';
        fb.setAttribute('list', 'component-lang-datalist');
        fb.setAttribute('autocomplete', 'off');
        fb.placeholder = 'BCP 47 tag (e.g. ko-Latn)';
        fb.style.maxWidth = '160px';
        if (tag) { fb.value = tag; }
        return { el: fb, booted: false, ready: Promise.resolve(), getTag: function () { return (fb.value || '').trim(); } };
    }

    var id = 'ed2-ietf-' + (++_ed2IetfPickerSeq);
    var wrap = document.createElement('div');
    wrap.className = 'ietf-picker';
    wrap.setAttribute('data-ietf-picker-id', id);
    /* Deliberately NOT setting data-initial-tag: we pre-fill via ctl.setTag(tag)
       below so we can expose its async completion as `ready` (#1345 review). The
       module's setTag awaits the languages/script/region fetches before it
       populates the inputs + hidden output; reading getTag() before that resolves
       would mis-report '' and a fast Save could blank an existing tag.
       Static structure mirroring partials/ietf-language-picker.php — the only
       interpolation is `id` (generated, no user data) so innerHTML is XSS-safe. */
    wrap.innerHTML =
        '<div class="row g-1">'
      +   '<div class="col"><input type="text" class="form-control form-control-sm ietf-picker-language" list="ietf-lang-list-' + id + '" autocomplete="off" placeholder="Language"></div>'
      +   '<div class="col"><input type="text" class="form-control form-control-sm ietf-picker-script" list="ietf-script-list-' + id + '" autocomplete="off" placeholder="Script (e.g. Latin)"></div>'
      +   '<div class="col"><input type="text" class="form-control form-control-sm ietf-picker-region" list="ietf-region-list-' + id + '" autocomplete="off" placeholder="Region"></div>'
      + '</div>'
      + '<div class="form-text small mt-1">IETF tag: <code class="ietf-tag-preview">—</code> <span class="ietf-tag-display fst-italic ms-1"></span></div>'
      + '<input type="hidden" class="ietf-tag-output" value="">'
      + '<datalist id="ietf-lang-list-' + id + '"></datalist>'
      + '<datalist id="ietf-script-list-' + id + '"></datalist>'
      + '<datalist id="ietf-region-list-' + id + '"></datalist>';

    var ctl = window.bootIetfLanguagePicker(wrap);
    /* Pre-fill the seed tag and expose the async completion. Callers gate Save on
       this so a too-fast click can't read '' and clear an existing tag (#1345 review). */
    var ready = (ctl && tag && typeof ctl.setTag === 'function')
        ? Promise.resolve(ctl.setTag(tag)).catch(function () { /* degrade silently */ })
        : Promise.resolve();
    return {
        el: wrap,
        booted: !!ctl,
        ready: ready,
        getTag: function () {
            if (ctl && typeof ctl.getTag === 'function') { return (ctl.getTag() || '').trim(); }
            var o = wrap.querySelector('.ietf-tag-output');
            return o ? (o.value || '').trim() : '';
        }
    };
}

/* ====================================================================
 * Per-line translation + annotation editor (#1235 P3 / #1088)
 *
 * The legacy editor edits lyrics as a textarea, so per-line enrichment is keyed
 * off the SAVED line Ids (tblLyricLines.Id), exposed parallel to the lines as
 * comp.lineIds. Existing enrichment arrives on song.lineTranslations /
 * song.lineAnnotations (api.php load_song). Writes go to the v2 API (api2.php),
 * which validates the CSRF token — the legacy api.php deliberately hosts no
 * enrichment endpoints. A line with no saved Id (freshly typed, or edited since
 * the last save) shows a "save first" hint instead of add controls; the
 * Id-preserving diff keeps a line's Id (and its enrichment) across later edits.
 * ==================================================================== */

var ED2_TRANSLATION_KINDS = ['translation', 'transliteration'];
var ED2_ANNOTATION_TYPES  = ['explanation', 'reference', 'scripture', 'history', 'translation', 'trivia'];

/* POST to the v2 API with the CSRF header. Resolves to the parsed JSON or
   rejects with a useful message. */
function ed2EnrichApi(action, body) {
    var base = window.IHYMNS_EDITOR_API2 || '/manage/editor/api2';
    return fetch(base + '?action=' + encodeURIComponent(action), {
        method:      'POST',
        headers:     {
            'Content-Type':     'application/json',
            'X-CSRF-Token':     window.IHYMNS_EDITOR_CSRF || '',
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin',
        body:        JSON.stringify(body || {})
    }).then(function (r) {
        return r.json().catch(function () { return null; }).then(function (d) {
            if (!r.ok || !d || d.ok === false) {
                throw new Error((d && d.error) ? d.error : ('HTTP ' + r.status));
            }
            return d;
        });
    });
}

/**
 * buildEnrichmentPanel(song, comp) — a collapsible per-component panel listing
 * each lyric line with its translations + annotations and add/delete controls.
 * Returns the wrapper element. (#1235 P3 / #1088)
 */
function buildEnrichmentPanel(song, comp) {
    if (!Array.isArray(song.lineTranslations)) { song.lineTranslations = []; }
    if (!Array.isArray(song.lineAnnotations))  { song.lineAnnotations  = []; }

    var wrap = document.createElement('div');
    wrap.className = 'mt-2';
    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'btn btn-sm btn-link p-0 text-decoration-none';
    toggle.innerHTML = '<i class="bi bi-card-text me-1"></i>Per-line language, translations &amp; annotations';
    var box = document.createElement('div');
    box.className = 'mt-1';
    box.style.display = 'none';
    toggle.addEventListener('click', function () {
        var show = (box.style.display === 'none');
        box.style.display = show ? '' : 'none';
        if (show) { renderBody(); }
    });

    function lineIdAt(i) {
        return (Array.isArray(comp.lineIds) && comp.lineIds[i] != null) ? Number(comp.lineIds[i]) : 0;
    }
    function forLine(list, key, lineId) {
        return list.filter(function (e) { return Number(e[key]) === lineId; });
    }
    function chip(label, onRemove) {
        var c = document.createElement('span');
        c.className = 'badge bg-secondary-subtle text-secondary-emphasis me-1 mb-1 fw-normal';
        c.style.whiteSpace = 'normal';
        c.textContent = label + '  ';
        var x = document.createElement('button');
        x.type = 'button';
        x.className = 'btn-close btn-close-sm align-middle';
        x.style.fontSize = '0.6rem';
        x.title = 'Remove';
        x.addEventListener('click', onRemove);
        c.appendChild(x);
        return c;
    }
    function addBtn(label, onClick) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-sm btn-outline-secondary py-0 px-1 me-1';
        b.textContent = label;
        b.addEventListener('click', onClick);
        return b;
    }

    function deleteEnrichment(action, id, listKey) {
        ed2EnrichApi(action, { songId: song.id, id: id }).then(function () {
            song[listKey] = song[listKey].filter(function (e) { return Number(e.id) !== Number(id); });
            renderBody();
        }).catch(function (err) { showToast('Delete failed: ' + err.message, 'danger'); });
    }

    /* #1345 — per-line LANGUAGE override (index-keyed comp.languages[i], NOT a
       lineId enrichment row). Overrides the component language, which overrides the
       song language. Pad the parallel array so a later line can be set without
       filling the earlier ones. */
    function setLineLang(i, tag) {
        if (!Array.isArray(comp.languages)) { comp.languages = []; }
        while (comp.languages.length <= i) { comp.languages.push(''); }
        comp.languages[i] = tag || '';
        markModified(song.id);
    }

    /* Inline structured-picker form for one line's language override (#1345). */
    function showLineLangForm(host, i) {
        var cur = (Array.isArray(comp.languages) && comp.languages[i] != null)
            ? String(comp.languages[i]).trim() : '';
        var form = document.createElement('div');
        form.className = 'mt-1 p-2 border rounded bg-body-tertiary';
        var picker = buildInlineIetfPicker(cur);
        form.appendChild(picker.el);
        var btnRow = document.createElement('div');
        btnRow.className = 'mt-1';
        var save = addBtn('Save', function () {
            setLineLang(i, picker.getTag());
            renderBody();
        });
        save.className = 'btn btn-sm btn-primary py-0 px-2';
        /* Editing an EXISTING tag: the picker pre-fills asynchronously, so block Save
           until it's ready — otherwise a fast click reads '' and clears the tag
           (#1345 review). A fresh (empty) seed has nothing to lose, so leave it live. */
        if (cur) {
            save.disabled = true;
            picker.ready.then(function () { save.disabled = false; });
        }
        var cancel = addBtn('Cancel', function () { renderBody(); });
        btnRow.appendChild(save);
        btnRow.appendChild(cancel);
        form.appendChild(btnRow);
        host.appendChild(form);
    }

    function showTranslationForm(host, lineId) {
        /* #1345 — structured IETF picker for the translation's language (full BCP 47:
           ko-Latn, zh-Hans-CN). Multiple translations per line already work — each
           "+ Translation" adds another row (the table is keyed per language+kind). */
        var form = document.createElement('div');
        form.className = 'mt-1 p-2 border rounded bg-body-tertiary';
        var kindRow = document.createElement('div');
        kindRow.className = 'd-flex gap-1 align-items-center mb-1';
        var kindLbl = document.createElement('span');
        kindLbl.className = 'small text-muted'; kindLbl.textContent = 'Kind:';
        var kind = document.createElement('select');
        kind.className = 'form-select form-select-sm w-auto';
        ED2_TRANSLATION_KINDS.forEach(function (k) {
            var o = document.createElement('option'); o.value = k; o.textContent = k; kind.appendChild(o);
        });
        kindRow.appendChild(kindLbl); kindRow.appendChild(kind);
        var picker = buildInlineIetfPicker('');
        var text = document.createElement('input');
        text.type = 'text'; text.className = 'form-control form-control-sm mt-1';
        text.placeholder = 'Translated / romanized line text';
        var save = addBtn('Save', function () {
            var payload = { lineId: lineId, kind: kind.value, targetLanguage: picker.getTag(), text: text.value.trim() };
            if (!payload.targetLanguage || !payload.text) { showToast('Language and text are required.', 'warning'); return; }
            ed2EnrichApi('line_translation_upsert', { songId: song.id, translation: payload }).then(function (d) {
                if (d.translation) { song.lineTranslations.push(d.translation); }
                renderBody();
            }).catch(function (err) { showToast('Save failed: ' + err.message, 'danger'); });
        });
        save.className = 'btn btn-sm btn-primary py-0 px-2';
        var cancel = addBtn('Cancel', function () { renderBody(); });
        var btnRow = document.createElement('div');
        btnRow.className = 'mt-1';
        btnRow.appendChild(save); btnRow.appendChild(cancel);
        form.appendChild(kindRow); form.appendChild(picker.el); form.appendChild(text); form.appendChild(btnRow);
        host.appendChild(form);
    }

    function showAnnotationForm(host, lineId) {
        var form = document.createElement('div');
        form.className = 'd-flex flex-wrap gap-1 align-items-start mt-1';
        var type = document.createElement('select');
        type.className = 'form-select form-select-sm w-auto';
        ED2_ANNOTATION_TYPES.forEach(function (t) {
            var o = document.createElement('option'); o.value = t; o.textContent = t; type.appendChild(o);
        });
        var body = document.createElement('textarea');
        body.className = 'form-control form-control-sm'; body.rows = 2; body.placeholder = 'Annotation (markdown)';
        body.style.minWidth = '220px';
        var save = addBtn('Save', function () {
            var payload = { startLineId: lineId, annotationType: type.value, body: body.value.trim() };
            if (!payload.body) { showToast('Annotation body is required.', 'warning'); return; }
            ed2EnrichApi('line_annotation_upsert', { songId: song.id, annotation: payload }).then(function (d) {
                if (d.annotation) { song.lineAnnotations.push(d.annotation); }
                renderBody();
            }).catch(function (err) { showToast('Save failed: ' + err.message, 'danger'); });
        });
        save.className = 'btn btn-sm btn-primary py-0 px-2';
        var cancel = addBtn('Cancel', function () { renderBody(); });
        form.appendChild(type); form.appendChild(body); form.appendChild(save); form.appendChild(cancel);
        host.appendChild(form);
        body.focus();
    }

    function renderBody() {
        box.innerHTML = '';
        var lines = Array.isArray(comp.lines) ? comp.lines : [];
        if (!lines.length) {
            box.innerHTML = '<div class="text-muted small fst-italic">No lines yet.</div>';
            return;
        }
        lines.forEach(function (lineText, i) {
            var lineId = lineIdAt(i);
            var row = document.createElement('div');
            row.className = 'border-start border-2 ps-2 mb-2 small';
            var txt = document.createElement('div');
            txt.className = 'text-muted';
            txt.textContent = (lineText && String(lineText).trim()) ? String(lineText) : '(blank line)';
            row.appendChild(txt);

            /* #1345 — per-line LANGUAGE override (index-keyed comp.languages[i], so it
               works even before the song is saved — unlike translations/annotations,
               which need the saved lineId). 🔤 chip shows the current tag with a remove
               control; the button opens the structured IETF picker. Distinct glyph from
               the 🌐 translation chip / 📝 annotation chip (#1345 review). Skipped on
               blank separator lines unless one already carries a tag. */
            var curLang = (Array.isArray(comp.languages) && comp.languages[i] != null)
                ? String(comp.languages[i]).trim() : '';
            var lineHasText = !!(lineText && String(lineText).trim());
            if (lineHasText || curLang) {
                var langWrap = document.createElement('div');
                langWrap.className = 'mb-1';
                if (curLang) {
                    langWrap.appendChild(chip('🔤 ' + curLang, function () { setLineLang(i, ''); renderBody(); }));
                }
                langWrap.appendChild(addBtn(curLang ? '🔤 Change language' : '🔤 Set language',
                    function () { showLineLangForm(langWrap, i); }));
                row.appendChild(langWrap);
            }

            if (!lineId) {
                var note = document.createElement('div');
                note.className = 'fst-italic text-secondary';
                note.textContent = 'Save the song first to add translations or annotations to this line.';
                row.appendChild(note);
                box.appendChild(row);
                return;
            }

            forLine(song.lineTranslations, 'lineId', lineId).forEach(function (t) {
                row.appendChild(chip('🌐 ' + t.kind + ' · ' + t.targetLanguage + ': ' + t.text,
                    function () { deleteEnrichment('line_translation_delete', t.id, 'lineTranslations'); }));
            });
            forLine(song.lineAnnotations, 'startLineId', lineId).forEach(function (a) {
                row.appendChild(chip('📝 ' + a.annotationType + ': ' + a.body,
                    function () { deleteEnrichment('line_annotation_delete', a.id, 'lineAnnotations'); }));
            });

            var btns = document.createElement('div');
            btns.className = 'mt-1';
            btns.appendChild(addBtn('+ Translation', function () { showTranslationForm(row, lineId); }));
            btns.appendChild(addBtn('+ Annotation',  function () { showAnnotationForm(row, lineId); }));
            row.appendChild(btns);
            box.appendChild(row);
        });
    }

    wrap.appendChild(toggle);
    wrap.appendChild(box);
    return wrap;
}

function componentHeaderLabel(comp) {
    var type = (comp && comp.type) ? String(comp.type) : 'verse';
    var cap = type.charAt(0).toUpperCase() + type.slice(1);
    var n = comp && comp.number;
    var num = (n != null && n !== '' && !isNaN(n) && parseInt(n, 10) > 0)
        ? parseInt(n, 10)
        : null;
    return num != null ? (cap + ' ' + num) : cap;
}

/**
 * fitAllComponentTextareas(scope)
 * -------------------------------
 * Run autoResizeTextarea() on every `.component-lyrics` textarea
 * inside `scope` (defaults to the document). Safe to call at any
 * time — no-op on nodes whose computed size reports 0 (still hidden).
 */
function fitAllComponentTextareas(scope) {
    var root = scope || document;
    root.querySelectorAll('.component-lyrics').forEach(function (ta) {
        autoResizeTextarea(ta);
    });
}

/**
 * addComponent()
 * --------------
 * Appends a new empty component to the currently selected song and re-renders.
 */
function addComponent() {
    /* Only act if a song is selected. */
    if (!currentSongId) {
        showToast('Select a song first.', 'warning');
        return;
    }

    /* Find the song. */
    var song = findSongById(currentSongId);
    if (!song) return;

    /* Ensure the components array exists. */
    if (!song.components) song.components = [];

    /* Push a new blank component with sensible defaults. */
    song.components.push({
        type: 'verse',   // default type
        number: song.components.length + 1, // auto-increment number
        lines: ['']      // empty lines array (#244)
    });

    /* Mark as modified. */
    markModified(song.id);

    /* Re-render the component cards, arrangement, and preview. */
    renderComponents(song);
    renderArrangement(song);
    renderPreview(song);
}

/**
 * removeComponent(song, index)
 * ----------------------------
 * Removes the component at the given index after user confirmation.
 * Also updates the arrangement array to account for shifted indexes.
 *
 * @param {Object} song  - The parent song object.
 * @param {number} index - Zero-based index of the component to remove.
 */
function removeComponent(song, index) {
    /* Ask the user to confirm the destructive action. */
    if (!confirm('Remove component ' + (index + 1) + '?')) {
        return; // user cancelled
    }

    /* Splice the component out of the array. */
    song.components.splice(index, 1);

    /* Update arrangement indexes: remove references to the deleted component,
       and decrement any indexes that were above it (#161). */
    if (song.arrangement && Array.isArray(song.arrangement)) {
        song.arrangement = song.arrangement
            .filter(function (i) { return i !== index; })
            .map(function (i) { return i > index ? i - 1 : i; });

        /* Clear arrangement if it's now empty. */
        if (song.arrangement.length === 0) {
            song.arrangement = null;
        }
    }

    /* Mark as modified. */
    markModified(song.id);

    /* Re-render. The component cards + arrangement must rebuild now (the deleted
       card has to disappear), but the PREVIEW is debounced (#1180) — rebuilding
       the whole slide preview synchronously on every delete is what made a
       single delete feel like it took seconds. */
    renderComponents(song);
    renderArrangement(song);
    schedulePreview(song);
}

/**
 * moveComponent(song, index, direction)
 * -------------------------------------
 * Swaps the component at `index` with its neighbour in the given direction.
 * Also updates the arrangement array to reflect the new positions.
 *
 * @param {Object} song      - The parent song object.
 * @param {number} index     - Current zero-based position.
 * @param {number} direction - -1 for up, +1 for down.
 */
function moveComponent(song, index, direction) {
    /* Calculate the target index. */
    var target = index + direction;

    /* Bounds check. */
    if (target < 0 || target >= song.components.length) return;

    /* Swap the two components in the array. */
    var temp = song.components[index];
    song.components[index] = song.components[target];
    song.components[target] = temp;

    /* Update arrangement indexes to reflect the swap (#161). */
    if (song.arrangement && Array.isArray(song.arrangement)) {
        song.arrangement = song.arrangement.map(function (i) {
            if (i === index) return target;
            if (i === target) return index;
            return i;
        });
    }

    /* Mark as modified. */
    markModified(song.id);

    /* Re-render so the new order is visible. */
    renderComponents(song);
    renderArrangement(song);
    renderPreview(song);
}

/**
 * autoResizeTextarea(el)
 * ----------------------
 * Sets the textarea's height to fit its content, eliminating scroll bars.
 *
 * @param {HTMLTextAreaElement} el - The textarea to resize.
 */
function autoResizeTextarea(el) {
    /* Reset to auto so scrollHeight reflects actual content height. */
    el.style.height = 'auto';
    /* Set height to the scroll height (content height). */
    el.style.height = el.scrollHeight + 'px';
}

/* ========================================================================
 *  SECTION 5B — Arrangement Editor (#161)
 *
 *  Allows customisation of the song arrangement (display order) using
 *  human-readable component labels (e.g. "Verse 1, Chorus") instead of
 *  raw component indexes.
 * ======================================================================== */

/**
 * getComponentLabel(comp)
 * -----------------------
 * Returns a human-readable label for a component, e.g. "Verse 1", "Chorus", "Bridge".
 *
 * @param {Object} comp - A component object with `type` and optional `number`.
 * @returns {string} Human-readable label.
 */
function getComponentLabel(comp) {
    /* Refrain is an alias for Chorus in the UI; otherwise delegate
       to the shared componentHeaderLabel helper so every chip /
       card / preview heading says the same thing (#491). */
    var normalised = Object.assign({}, comp, {
        type: comp.type === 'refrain' ? 'chorus' : comp.type,
    });
    return componentHeaderLabel(normalised);
}

/**
 * Component type colour + text colour lookup.
 * Mirrors COMPONENT_TYPES from components.js (which this non-module file cannot import).
 */
var COMP_COLORS = {
    'verse':       { bg: '#3b82f6', text: '#ffffff' },
    'chorus':      { bg: '#f59e0b', text: '#1a1a1a' },
    'refrain':     { bg: '#f59e0b', text: '#1a1a1a' },
    'pre-chorus':  { bg: '#ec4899', text: '#ffffff' },
    'bridge':      { bg: '#8b5cf6', text: '#ffffff' },
    'tag':         { bg: '#6b7280', text: '#ffffff' },
    'coda':        { bg: '#6b7280', text: '#ffffff' },
    'intro':       { bg: '#10b981', text: '#ffffff' },
    'outro':       { bg: '#ef4444', text: '#ffffff' },
    'interlude':   { bg: '#06b6d4', text: '#ffffff' },
    'vamp':        { bg: '#f97316', text: '#ffffff' },
    'ad-lib':      { bg: '#84cc16', text: '#1a1a1a' },
};

/**
 * findComponentIndex(song, label)
 * -------------------------------
 * Finds the index of a component matching the given human-readable label.
 * Matching is case-insensitive. Supports labels like "Verse 1", "Chorus", "Refrain".
 * If a type has only one component and no number is specified, it matches.
 *
 * @param {Object}  song  - The song with components array.
 * @param {string}  label - Human-readable label to search for.
 * @returns {number} Component index, or -1 if not found.
 */
function findComponentIndex(song, label) {
    var trimmed = label.trim().toLowerCase();
    if (!trimmed || !song.components) return -1;

    /* Try exact match first (type + number). */
    for (var i = 0; i < song.components.length; i++) {
        var comp = song.components[i];
        var compLabel = getComponentLabel(comp).toLowerCase();
        if (compLabel === trimmed) return i;
    }

    /* If no number was given, match the first component of that type. */
    for (var j = 0; j < song.components.length; j++) {
        var c = song.components[j];
        if (c.type.toLowerCase() === trimmed) return j;
    }

    return -1;
}

/**
 * arrangementToLabels(song)
 * -------------------------
 * Converts the song's arrangement index array into a comma-separated
 * string of human-readable labels.
 *
 * @param {Object} song - The song with components and arrangement arrays.
 * @returns {string} Comma-separated labels, or empty string if no arrangement.
 */
function arrangementToLabels(song) {
    if (!song.arrangement || !Array.isArray(song.arrangement)) return '';
    if (!song.components) return '';

    return song.arrangement.map(function (idx) {
        var comp = song.components[idx];
        if (!comp) return '?';
        return getComponentLabel(comp);
    }).join(', ');
}

/**
 * labelsToArrangement(song, labelsStr)
 * -------------------------------------
 * Parses a comma-separated string of human-readable component labels
 * into an arrangement index array. Returns an object with:
 *   - arrangement: array of valid indexes (null if input is empty)
 *   - errors: array of unrecognised label strings
 *
 * @param {Object} song      - The song with components array.
 * @param {string} labelsStr - Comma-separated labels string.
 * @returns {{ arrangement: number[]|null, errors: string[] }}
 */
function labelsToArrangement(song, labelsStr) {
    var trimmed = labelsStr.trim();
    if (!trimmed) return { arrangement: null, errors: [] };

    var labels = trimmed.split(',');
    var arrangement = [];
    var errors = [];

    labels.forEach(function (label) {
        var l = label.trim();
        if (!l) return; /* skip empty entries from double commas */

        var idx = findComponentIndex(song, l);
        if (idx === -1) {
            errors.push(l);
        } else {
            arrangement.push(idx);
        }
    });

    return {
        arrangement: arrangement.length > 0 ? arrangement : null,
        errors: errors
    };
}

/**
 * autoGenerateArrangement(song)
 * -----------------------------
 * Generates an arrangement that inserts the chorus/refrain after each verse.
 * Same logic as the parser (tools/parse-songs.js).
 *
 * @param {Object} song - The song with components array.
 * @returns {number[]|null} Arrangement index array, or null if no chorus/refrain found.
 */
function autoGenerateArrangement(song) {
    if (!song.components || song.components.length === 0) return null;

    /* Find the first chorus or refrain. */
    var refrainIndex = -1;
    for (var i = 0; i < song.components.length; i++) {
        var type = song.components[i].type;
        if (type === 'chorus' || type === 'refrain') {
            refrainIndex = i;
            break;
        }
    }

    if (refrainIndex === -1) return null;

    /* Detect the SDAH-93-style "verse-1-acts-as-chorus" pattern (#598):
       when the refrain comes BEFORE any verse in the components list,
       the song convention is to open on the refrain too — not just
       repeat it after each verse. Most ProPresenter / hymnal renderings
       of this pattern (e.g. "All Things Bright and Beautiful") lead
       with the refrain. */
    var firstVerseIndex = -1;
    for (var k = 0; k < song.components.length; k++) {
        if (song.components[k].type === 'verse') { firstVerseIndex = k; break; }
    }
    var leadWithRefrain = (firstVerseIndex !== -1 && refrainIndex < firstVerseIndex);

    /* Build arrangement: optional leading refrain, then chorus/refrain
       after each verse, other components in place. */
    var arrangement = [];
    if (leadWithRefrain) arrangement.push(refrainIndex);
    for (var j = 0; j < song.components.length; j++) {
        var comp = song.components[j];
        if (comp.type === 'verse') {
            arrangement.push(j);
            arrangement.push(refrainIndex);
        } else if (j !== refrainIndex) {
            arrangement.push(j);
        }
    }

    return arrangement;
}

/**
 * renderArrangement(song)
 * -----------------------
 * Renders the arrangement editor UI: chips display, text input, and feedback.
 * Called when a song is loaded and whenever components change.
 *
 * @param {Object} song - The song to render arrangement for.
 */
function renderArrangement(song) {
    var input    = document.getElementById('arrangement-input');
    var feedback = document.getElementById('arrangement-feedback');
    var pool     = document.getElementById('arrangement-pool');
    var strip    = document.getElementById('arrangement-strip');

    if (!input || !feedback) return;

    /* Clear previous state. */
    if (pool)  pool.innerHTML  = '';
    if (strip) strip.innerHTML = '';
    feedback.style.display = 'none';
    feedback.textContent = '';

    if (!song) {
        input.value = '';
        return;
    }

    /* Advanced text-mode mirror (kept for paste-in / power users). */
    input.value = arrangementToLabels(song);

    /* Drag-drop builder (#492). The legacy `arrangement-chips`
       summary row was removed in #597 — the strip below already
       renders the playback order as draggable chips, and the
       summary row was a non-interactive duplicate. */
    if (pool && strip) {
        renderArrangementPool(song, pool, strip);
        renderArrangementStrip(song, strip);
    }

    /* Re-evaluate quick-action buttons for the song's component set (#493). */
    refreshArrangementPresetAvailability(song);
}

/**
 * Render the component pool (source chips). Clicking a pool chip
 * appends that component to the arrangement strip.
 */
function renderArrangementPool(song, pool, strip) {
    if (!Array.isArray(song.components) || song.components.length === 0) {
        pool.innerHTML = '<span class="text-muted small">No components defined.</span>';
        return;
    }

    song.components.forEach(function (comp, idx) {
        var chip = makeArrangementChip(comp, /* includeRemove */ false);
        chip.style.cursor = 'pointer';
        chip.title = getComponentLabel(comp) + ' — click to add';
        chip.addEventListener('click', function () {
            appendToArrangement(song, idx);
        });
        pool.appendChild(chip);
    });
}

/**
 * Render the sequence strip. Each chip has a remove × and the whole
 * strip is SortableJS-reorderable. Lazy-loads SortableJS on first use.
 */
function renderArrangementStrip(song, strip) {
    /* Resolve the effective arrangement: explicit or sequential fallback. */
    var indices = effectiveArrangement(song);

    if (indices.length === 0) {
        strip.innerHTML = '<span class="text-muted small">Sequence is empty — click a component above to add.</span>';
        return;
    }

    indices.forEach(function (idx, posIdx) {
        var comp = song.components[idx];
        if (!comp) return;
        var chip = makeArrangementChip(comp, /* includeRemove */ true);
        chip.dataset.position = String(posIdx);
        chip.dataset.compIdx  = String(idx);
        /* Remove handler — takes the chip's live DOM position, not the
           closed-over posIdx, so it stays accurate after a drag. */
        chip.querySelector('.arr-chip-remove')?.addEventListener('click', function (e) {
            e.stopPropagation();
            var pos = Array.prototype.indexOf.call(strip.children, chip);
            removeFromArrangement(song, pos);
        });
        strip.appendChild(chip);
    });

    /* SortableJS for drag-reorder. Lazy-loaded on first use. */
    ensureSortable().then(function (Sortable) {
        if (strip._sortable) return;
        strip._sortable = Sortable.create(strip, {
            animation: 160,
            ghostClass: 'arr-chip-ghost',
            onEnd: function () {
                /* Rebuild song.arrangement from the strip's new order. */
                var newOrder = [];
                Array.prototype.forEach.call(strip.children, function (el) {
                    if (el.dataset && el.dataset.compIdx != null) {
                        newOrder.push(parseInt(el.dataset.compIdx, 10));
                    }
                });
                var curSong = findSongById(currentSongId);
                if (!curSong) return;
                curSong.arrangement = newOrder;
                markModified(curSong.id);
                renderArrangement(curSong);
                renderPreview(curSong);
            },
        });
    }).catch(function (err) {
        console.error('[arrangement] failed to load SortableJS:', err);
    });
}

/**
 * Build a coloured chip DOM node for a component. Optionally includes
 * a ×-remove button in the right corner.
 */
function makeArrangementChip(comp, includeRemove) {
    var chip = document.createElement('span');
    chip.className = 'badge rounded-pill d-inline-flex align-items-center gap-1 arr-chip';
    var colors = COMP_COLORS[comp.type] || { bg: '#6b7280', text: '#ffffff' };
    chip.style.backgroundColor = colors.bg;
    chip.style.color = colors.text;
    chip.style.padding = '0.35rem 0.6rem';

    var label = document.createElement('span');
    label.textContent = getComponentLabel(comp);
    chip.appendChild(label);

    if (includeRemove) {
        var x = document.createElement('button');
        x.type = 'button';
        x.className = 'btn-close btn-close-white arr-chip-remove';
        x.setAttribute('aria-label', 'Remove from arrangement');
        x.style.fontSize = '0.55rem';
        x.style.marginLeft = '0.25rem';
        chip.appendChild(x);
    }

    return chip;
}

function effectiveArrangement(song) {
    if (Array.isArray(song.arrangement) && song.arrangement.length > 0) {
        return song.arrangement.slice();
    }
    if (Array.isArray(song.components)) {
        return song.components.map(function (_, i) { return i; });
    }
    return [];
}

function appendToArrangement(song, compIdx) {
    var current = effectiveArrangement(song);
    current.push(compIdx);
    song.arrangement = current;
    markModified(song.id);
    renderArrangement(song);
    renderPreview(song);
}

function removeFromArrangement(song, pos) {
    var current = effectiveArrangement(song);
    if (pos < 0 || pos >= current.length) return;
    current.splice(pos, 1);
    /* Empty list means "fall back to component order" — represent it
       as `null` so the existing downstream logic keeps working. */
    song.arrangement = current.length === 0 ? null : current;
    markModified(song.id);
    renderArrangement(song);
    renderPreview(song);
}

/* ------------------------------------------------------------------
 * SortableJS lazy loader — same pattern as /js/modules/card-layout.js,
 * duplicated here because editor.js is a classic script and can't
 * import the ES-module version.
 * ------------------------------------------------------------------ */
var _arrSortablePromise = null;
function ensureSortable() {
    if (window.Sortable) return Promise.resolve(window.Sortable);
    if (_arrSortablePromise) return _arrSortablePromise;
    _arrSortablePromise = new Promise(function (resolve, reject) {
        var s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js';
        s.crossOrigin = 'anonymous';
        s.onload  = function () { resolve(window.Sortable); };
        s.onerror = function () { reject(new Error('SortableJS load failed')); };
        document.head.appendChild(s);
    });
    return _arrSortablePromise;
}

/**
 * applyArrangementFromInput(song)
 * --------------------------------
 * Reads the arrangement text input, parses labels, validates, and applies
 * the arrangement to the song. Shows feedback on errors.
 *
 * @param {Object} song - The song to update.
 */
function applyArrangementFromInput(song) {
    var input = document.getElementById('arrangement-input');
    var feedback = document.getElementById('arrangement-feedback');
    if (!input || !feedback || !song) return;

    var result = labelsToArrangement(song, input.value);

    if (result.errors.length > 0) {
        /* Show error feedback for unrecognised labels. */
        feedback.style.display = 'block';
        feedback.className = 'small mb-2 text-danger';
        feedback.textContent = 'Unrecognised: ' + result.errors.join(', ')
            + '. Available: ' + song.components.map(getComponentLabel).join(', ');
        return;
    }

    /* Apply the arrangement (or clear it). */
    song.arrangement = result.arrangement;

    /* Clear error feedback. */
    feedback.style.display = 'none';

    /* Show success feedback briefly. */
    if (result.arrangement) {
        feedback.style.display = 'block';
        feedback.className = 'small mb-2 text-success';
        feedback.textContent = 'Arrangement applied (' + result.arrangement.length + ' items).';
    } else {
        feedback.style.display = 'block';
        feedback.className = 'small mb-2 text-info';
        feedback.textContent = 'Arrangement cleared — using sequential order.';
    }

    /* Mark as modified and re-render. */
    markModified(song.id);
    renderArrangement(song);
    renderPreview(song);

    /* Auto-hide feedback after 3 seconds. */
    setTimeout(function () {
        feedback.style.display = 'none';
    }, 3000);
}

/**
 * bindArrangementListeners()
 * --------------------------
 * Attaches event listeners to the arrangement editor UI elements.
 * Called once on page load from bindGlobalEventListeners().
 */
function bindArrangementListeners() {
    /* Apply button. */
    var btnApply = document.getElementById('btnApplyArrangement');
    if (btnApply) {
        btnApply.addEventListener('click', function () {
            if (!currentSongId) return;
            var song = findSongById(currentSongId);
            if (song) applyArrangementFromInput(song);
        });
    }

    /* Enter key in input also applies. */
    var input = document.getElementById('arrangement-input');
    if (input) {
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (!currentSongId) return;
                var song = findSongById(currentSongId);
                if (song) applyArrangementFromInput(song);
            }
        });
    }

    /* Preset quick-action buttons (#493). Delegated click handler on
       any `.arrangement-preset` so adding new presets is a markup-only
       change — each button carries its preset name in data-preset. */
    document.querySelectorAll('.arrangement-preset').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!currentSongId) return;
            var song = findSongById(currentSongId);
            if (!song) return;
            applyArrangementPreset(song, btn.dataset.preset);
        });
    });

    /* Sequential / clear button. */
    var btnSeq = document.getElementById('btnArrangementSequential');
    if (btnSeq) {
        btnSeq.addEventListener('click', function () {
            if (!currentSongId) return;
            var song = findSongById(currentSongId);
            if (!song) return;

            song.arrangement = null;
            markModified(song.id);
            renderArrangement(song);
            renderPreview(song);
            showToast('Arrangement cleared — using component order.', 'info');
        });
    }
}

/* ========================================================================
 *  Arrangement presets (#493)
 *  --------------------------------------------------------------------
 *  Each preset consumes the song's components array and returns an
 *  index array. Presets must NEVER throw — they're responsible for
 *  checking their own prerequisites (which also feed the UI's
 *  enable/disable logic via the data-requires attribute). Returning
 *  null means "can't apply — show a toast explaining why".
 * ======================================================================== */
function applyArrangementPreset(song, presetName) {
    var fn = ARRANGEMENT_PRESETS[presetName];
    if (!fn) {
        showToast('Unknown arrangement preset: ' + presetName, 'warning');
        return;
    }
    var arrangement = fn(song);
    if (!arrangement) {
        showToast('This song is missing a component type needed for that pattern.', 'warning');
        return;
    }
    song.arrangement = arrangement;
    markModified(song.id);
    renderArrangement(song);
    renderPreview(song);
    showToast('Arrangement set: ' + presetName.replace(/-/g, ' ') + '.', 'success');
}

var ARRANGEMENT_PRESETS = {
    'chorus-after-each-verse': function (song) {
        return autoGenerateArrangement(song);
    },

    'verses-only': function (song) {
        var verses = componentIdxByType(song, 'verse');
        return verses.length > 0 ? verses : null;
    },

    'verse-prechorus-chorus': function (song) {
        var verses  = componentIdxByType(song, 'verse');
        var preIdx  = firstIndexOfType(song, 'pre-chorus');
        var choIdx  = firstIndexOfType(song, 'chorus', /* alias */ 'refrain');
        if (!verses.length || preIdx < 0 || choIdx < 0) return null;
        var arr = [];
        verses.forEach(function (v) { arr.push(v); arr.push(preIdx); arr.push(choIdx); });
        return arr;
    },

    'verse-bridge-verse': function (song) {
        var verses = componentIdxByType(song, 'verse');
        var brIdx  = firstIndexOfType(song, 'bridge');
        if (verses.length < 2 || brIdx < 0) return null;
        /* Common shape: all-but-last verse · bridge · final verse. */
        var arr = verses.slice(0, -1);
        arr.push(brIdx);
        arr.push(verses[verses.length - 1]);
        return arr;
    },

    'intro-verses-outro': function (song) {
        var intro  = firstIndexOfType(song, 'intro');
        var outro  = firstIndexOfType(song, 'outro');
        var verses = componentIdxByType(song, 'verse');
        if (intro < 0 || outro < 0 || !verses.length) return null;
        return [intro].concat(verses).concat([outro]);
    },
};

function componentIdxByType(song, type) {
    var out = [];
    (song.components || []).forEach(function (c, i) { if (c.type === type) out.push(i); });
    return out;
}
function firstIndexOfType(song, type, aliasType) {
    var comps = song.components || [];
    for (var i = 0; i < comps.length; i++) {
        if (comps[i].type === type || (aliasType && comps[i].type === aliasType)) return i;
    }
    return -1;
}

/**
 * Enable / disable each preset button based on whether its data-requires
 * component types are all present in the current song (#493). Disabled
 * buttons get a tooltip explaining which type is missing, so the UI
 * never silently no-ops.
 */
function refreshArrangementPresetAvailability(song) {
    var types = new Set((song.components || []).map(function (c) {
        /* Refrain is an alias for chorus everywhere else. */
        return c.type === 'refrain' ? 'chorus' : c.type;
    }));
    document.querySelectorAll('.arrangement-preset').forEach(function (btn) {
        var req = (btn.dataset.requires || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
        var missing = req.filter(function (t) { return !types.has(t); });
        if (missing.length === 0) {
            btn.disabled = false;
            btn.title = btn.dataset.origTitle || btn.title;
            if (!btn.dataset.origTitle) btn.dataset.origTitle = btn.title;
        } else {
            if (!btn.dataset.origTitle) btn.dataset.origTitle = btn.title;
            btn.disabled = true;
            btn.title = 'Needs: ' + missing.join(', ') + ' (not in this song).';
        }
    });
}

/* ========================================================================
 *  SECTION 6 — Writers / Composers (#56)
 * ======================================================================== */

/**
 * renderWriters(song)
 * -------------------
 * Populates the #writers-container with a dynamic list of text inputs,
 * one per writer entry, plus an "Add Writer" button.
 *
 * @param {Object} song - The song object.
 */
/* Writers + Composers collapsed onto the shared chip-list helper
   that also drives Arrangers/Adaptors/Translators (#497). All five
   credit collections now get the #495 cross-collection autocomplete
   for free — createDynamicInputRow() attaches a live-search popover
   whenever a `creditKind` is passed through. */
function renderWriters(song) {
    renderCreditChipList(song, 'writers',   'writers-container',   'Add Writer');
}
function renderComposers(song) {
    renderCreditChipList(song, 'composers', 'composers-container', 'Add Composer');
}

/* ----------------------------------------------------------------------
 * Arrangers / Adaptors / Translators (#497)
 *
 * Three sibling credit collections that follow the same rendering
 * pattern as writers/composers. We factor the per-collection logic
 * through a tiny helper so adding future credit types (e.g. producers)
 * is a one-line change.
 *
 * Note on naming: `renderTranslators` (here) drives the chip list of
 * the *people* who translated this song's lyrics. `renderTranslations`
 * (below) drives the #352 cross-song link list — different feature.
 * ---------------------------------------------------------------------- */

/* Map the song-object key to the credit_search `kind` (#495). Used
   by renderCreditChipList to decorate chip inputs with the right
   live-search popover — the endpoint unions across all five tables
   and uses the kind only as the primary sort bias. */
var CREDIT_KIND_FOR_KEY = {
    writers:     'writer',
    composers:   'composer',
    arrangers:   'arranger',
    adaptors:    'adaptor',
    translators: 'translator',
    artists:     'artist',     /* #587 */
};

/* #960 — Each credit is now stored in-memory as a structured-parts
   object `{first, surname, suffix}`. Legacy entries that come in as
   bare strings (from /api?action=load) are normalised on first
   render and the song[key] slot is rewritten in place. The wire
   format on save (autoSaveSongsPerSong → save_song) is upgraded to
   send `{name, first, surname, suffix}` — the server accepts both
   shapes for back-compat. */
function renderCreditChipList(song, key, containerId, addLabel) {
    var container = document.getElementById(containerId);
    if (!container) return;
    if (!Array.isArray(song[key])) song[key] = [];
    container.innerHTML = '';

    var kind = CREDIT_KIND_FOR_KEY[key] || null;

    song[key].forEach(function (entry, i) {
        /* Normalise once on render — converts a legacy
           string-credit into {first, surname, suffix} in place so
           every subsequent read sees the structured shape. */
        var parts = normaliseCreditParts(entry);
        song[key][i] = parts;

        var row = createCreditNameRow(
            parts,
            function (newParts) {
                song[key][i] = newParts;
                markModified(song.id);
            },
            function () {
                song[key].splice(i, 1);
                markModified(song.id);
                renderCreditChipList(song, key, containerId, addLabel);
            },
            kind
        );
        container.appendChild(row);
    });

    var addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'btn btn-sm btn-outline-primary mt-2';
    addBtn.textContent = '+ ' + addLabel;
    addBtn.addEventListener('click', function () {
        song[key].push({ first: '', surname: '', suffix: '' });
        markModified(song.id);
        renderCreditChipList(song, key, containerId, addLabel);
    });
    container.appendChild(addBtn);
}

function renderArrangers(song) {
    renderCreditChipList(song, 'arrangers',   'arrangers-container',   'Add Arranger');
}

function renderAdaptors(song) {
    renderCreditChipList(song, 'adaptors',    'adaptors-container',    'Add Adaptor');
}

function renderTranslators(song) {
    renderCreditChipList(song, 'translators', 'translators-container', 'Add Translator');
}

function renderArtists(song) {
    renderCreditChipList(song, 'artists',     'artists-container',     'Add Artist');
}

/* ----------------------------------------------------------------------
 * Tags tab (#496)
 *
 * Fetches the current song's assigned tags from /api?action=song_tags,
 * renders them as a chip list with × remove buttons, and wires up a
 * live-search / create input via /api?action=tag_search. Adds/removes
 * hit /api?action=bulk_tag with a single-songId payload — the same
 * endpoint used by the bulk tagging tool elsewhere.
 *
 * Tag writes are immediate (no "Save" needed) because the editor save
 * contract is per-song for tblSongs + its direct children; tag maps
 * live in tblSongTagMap, outside the save_song flow, and should
 * persist the moment the admin clicks.
 * ---------------------------------------------------------------------- */

/**
 * Fetch + render tags for the given song. Idempotent. Prefers the
 * song.tags array already attached by the bulk ?action=load (#496
 * follow-up); only round-trips when the bulk load didn't include
 * them (e.g. older servers, or after a tag mutation that invalidated
 * the local copy).
 */
function loadSongTags(song) {
    var container = document.getElementById('song-tags-container');
    if (!container || !song || !song.id) return;

    /* Fast path — the bulk load already attached tags. */
    if (Array.isArray(song.tags)) {
        song._tags = song.tags;
        renderSongTagsChips(song, song.tags);
        return;
    }

    container.innerHTML = '<span class="text-muted small">Loading…</span>';
    fetch(EDITOR_API_URL + '?action=song_tags&id=' + encodeURIComponent(song.id))
        .then(function (r) { return r.json(); })
        .then(function (data) {
            var tags = Array.isArray(data.tags) ? data.tags : [];
            song.tags = tags;
            song._tags = tags;
            renderSongTagsChips(song, tags);
        })
        .catch(function (err) {
            console.error('[tags] load failed', err);
            container.innerHTML = '<span class="text-danger small">Failed to load tags.</span>';
        });
}

function renderSongTagsChips(song, tags) {
    var container = document.getElementById('song-tags-container');
    if (!container) return;
    container.innerHTML = '';

    if (!tags.length) {
        container.innerHTML = '<span class="text-muted small">No tags yet — add one below.</span>';
        return;
    }

    tags.forEach(function (tag) {
        var chip = document.createElement('span');
        chip.className = 'badge rounded-pill d-inline-flex align-items-center gap-1';
        chip.style.backgroundColor = '#6366f1';  /* accent-solid */
        chip.style.color = '#ffffff';
        chip.style.padding = '0.35rem 0.6rem';
        chip.title = tag.description || tag.name;

        var label = document.createElement('span');
        label.textContent = tag.name;
        chip.appendChild(label);

        var x = document.createElement('button');
        x.type = 'button';
        x.className = 'btn-close btn-close-white';
        x.setAttribute('aria-label', 'Remove tag');
        x.style.fontSize = '0.55rem';
        x.style.marginLeft = '0.25rem';
        x.addEventListener('click', function (e) {
            e.stopPropagation();
            removeSongTag(song, tag.name);
        });
        chip.appendChild(x);

        container.appendChild(chip);
    });
}

/**
 * POST bulk_tag to add a single tag to the current song.
 */
function addSongTag(song, tagName) {
    if (!song || !song.id || !tagName || !tagName.trim()) return;
    tagName = tagName.trim();

    /* Pre-flight guard removed (#788). The earlier version refused
       any song whose id started with "song-" on the assumption it
       was a brand-new in-memory id from Add. That assumption is
       wrong — songs in unofficial / Misc songbooks have a synthetic
       `song-<ts>-<rand>` SongId as their CANONICAL persisted id
       (#392 / PR #740), not a "this isn't saved yet" marker. The
       server-side detection below (data.added === 0 → friendly
       "Save the song first" toast) is the correct way to spot a
       genuinely-unsaved song; the prefix check was just blocking
       every saved Misc song from being tagged. */

    /* Optimistic update: append the chip locally so the UI feels
       snappy, then re-fetch on completion to pick up the real row
       (so the ×-remove later uses the canonical spelling the DB
       ended up with, not the user's input casing). */
    var existing = song._tags || [];
    if (existing.some(function (t) { return t.name.toLowerCase() === tagName.toLowerCase(); })) {
        showToast('Already tagged with "' + tagName + '".', 'info');
        return;
    }

    fetch(EDITOR_API_URL + '?action=bulk_tag', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ songIds: [song.id], add: [tagName], remove: [] }),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        /* Treat added===0 as failure even when the response is shaped
           as success — that means the server's INSERT IGNORE was a
           no-op (FK rejected, duplicate, or similar) so the chip
           wouldn't appear in the assigned list anyway and a "success"
           toast would mislead. (#770) */
        if (data && data.added > 0) {
            /* Invalidate the bulk-load cache so loadSongTags re-fetches
               with the authoritative row set (#496 follow-up). */
            delete song.tags;
            loadSongTags(song);
            showToast('Added tag "' + tagName + '".', 'success');
        } else if (data && data.added === 0) {
            /* Tell apart the three "0 added" failure modes using the
               server's new diagnostic fields (#960 follow-up). */
            var missing = Array.isArray(data.missingSongs)  ? data.missingSongs  : [];
            var dupes   = Array.isArray(data.alreadyTagged) ? data.alreadyTagged : [];
            if (missing.length > 0) {
                showToast(
                    'Tag couldn\'t be applied — this song isn\'t in the catalogue (id: "' +
                    missing[0] + '"). If you just added the song, click Save first; ' +
                    'otherwise refresh the editor to pick up the persisted id.',
                    'warning'
                );
            } else if (dupes.length > 0) {
                /* Refresh the tag list — the assigned-tags panel is
                   showing a stale view; the tag actually IS attached. */
                delete song.tags;
                loadSongTags(song);
                showToast(
                    'Tag "' + tagName + '" was already attached — refreshed the list to show it.',
                    'info'
                );
            } else {
                showToast(
                    'Tag couldn\'t be applied (server returned 0 added). ' +
                    'Check the network tab for details.',
                    'warning'
                );
            }
        } else {
            showToast((data && data.error) || 'Failed to add tag.', 'danger');
        }
    })
    .catch(function (err) {
        console.error('[tags] add failed', err);
        showToast('Failed to add tag.', 'danger');
    });
}

function removeSongTag(song, tagName) {
    if (!song || !song.id || !tagName) return;
    fetch(EDITOR_API_URL + '?action=bulk_tag', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ songIds: [song.id], add: [], remove: [tagName] }),
    })
    .then(function (r) { return r.json(); })
    .then(function () {
        /* Invalidate the cached bulk-load copy — see addSongTag. */
        delete song.tags;
        loadSongTags(song);
        showToast('Removed tag "' + tagName + '".', 'info');
    })
    .catch(function (err) {
        console.error('[tags] remove failed', err);
        showToast('Failed to remove tag.', 'danger');
    });
}

/**
 * Wire the tag-search autocomplete input. Idempotent — safe to call
 * multiple times; a `_tagsWired` flag on the input prevents duplicate
 * listeners.
 */
function bindTagSearchInput() {
    var input = document.getElementById('song-tag-input');
    var panel = document.getElementById('song-tag-suggestions');
    if (!input || !panel || input._tagsWired) return;
    input._tagsWired = true;

    var debounceTimer = null;
    var activeIdx = -1;
    var currentSuggestions = [];

    function closePanel() {
        panel.classList.add('d-none');
        panel.innerHTML = '';
        activeIdx = -1;
        currentSuggestions = [];
    }

    function renderSuggestions(suggestions, q) {
        panel.innerHTML = '';
        currentSuggestions = suggestions;
        activeIdx = -1;

        /* "Create new tag" affordance when the query doesn't exactly
           match an existing name. */
        var exact = suggestions.some(function (s) {
            return s.name.toLowerCase() === q.toLowerCase();
        });
        if (q && !exact) {
            var createItem = document.createElement('button');
            createItem.type = 'button';
            createItem.className = 'list-group-item list-group-item-action d-flex align-items-center gap-2';
            createItem.innerHTML = '<i class="bi bi-plus-circle"></i> Create new tag: <strong>' +
                escapeHtmlSafe(q) + '</strong>';
            createItem.addEventListener('click', function () {
                var song = findSongById(currentSongId);
                if (song) addSongTag(song, q);
                input.value = '';
                closePanel();
            });
            panel.appendChild(createItem);
        }

        suggestions.forEach(function (s) {
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
            /* #958 — s.usage is a count from the tag_search API but
               it's still an untrusted string from the server's
               perspective. Coerce to a non-negative integer before
               interpolation; defends against a stored-XSS path where
               a malicious tag-row could carry a string usage value. */
            var usageNum = parseInt(s.usage, 10);
            if (!Number.isFinite(usageNum) || usageNum < 0) usageNum = 0;
            item.innerHTML =
                '<span>' + escapeHtmlSafe(s.name) + '</span>' +
                '<span class="badge bg-secondary">' + usageNum + '</span>';
            item.addEventListener('click', function () {
                var song = findSongById(currentSongId);
                if (song) addSongTag(song, s.name);
                input.value = '';
                closePanel();
            });
            panel.appendChild(item);
        });

        panel.classList.toggle('d-none', panel.children.length === 0);
    }

    function fetchSuggestions(q) {
        fetch(EDITOR_API_URL + '?action=tag_search&q=' + encodeURIComponent(q || ''))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                renderSuggestions(Array.isArray(data.suggestions) ? data.suggestions : [], q);
            })
            .catch(function (err) {
                console.error('[tags] search failed', err);
                closePanel();
            });
    }

    input.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        var q = input.value.trim();
        debounceTimer = setTimeout(function () { fetchSuggestions(q); }, 180);
    });

    input.addEventListener('focus', function () {
        if (!input.value) fetchSuggestions('');
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            /* Plain Enter on a non-empty input creates/adds the tag. */
            var q = input.value.trim();
            if (!q) return;
            var song = findSongById(currentSongId);
            if (song) addSongTag(song, q);
            input.value = '';
            closePanel();
        } else if (e.key === 'Escape') {
            closePanel();
        }
    });

    /* Click outside to dismiss. */
    document.addEventListener('click', function (e) {
        if (!panel.contains(e.target) && e.target !== input) {
            closePanel();
        }
    });
}

/** Tiny HTML-escape helper for inline suggestion markup. */
function escapeHtmlSafe(s) {
    return String(s || '').replace(/[&<>"']/g, function (c) {
        return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
    });
}

/**
 * renderTranslations(song)
 * ------------------------
 * Populates the #translations-container with linked translation rows.
 * Each row shows the translated song ID, language, and title with a remove button.
 * Also populates the datalist for the add-translation input with all songs (#352).
 *
 * @param {Object} song - The song object.
 */
function renderTranslations(song) {
    var container = document.getElementById('translations-container');
    if (!container) return;

    container.innerHTML = '';

    /* Ensure the song has a translations array. */
    if (!song.translations) song.translations = [];

    /* Render each translation link */
    song.translations.forEach(function (tr, i) {
        var targetSong = songData.songs.find(function (s) { return s.id === tr.songId; });
        var displayTitle = targetSong ? targetSong.title : '(unknown)';
        var displayLang = tr.language || '?';

        var row = document.createElement('div');
        row.className = 'd-flex align-items-center gap-2 mb-1';

        var badge = document.createElement('span');
        badge.className = 'badge bg-secondary';
        badge.textContent = displayLang;

        var info = document.createElement('span');
        info.className = 'flex-grow-1 small';
        var strong = document.createElement('strong');
        strong.textContent = tr.songId;
        info.appendChild(strong);
        info.appendChild(document.createTextNode(' \u2014 ' + displayTitle));

        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn btn-sm btn-outline-danger';
        removeBtn.title = 'Remove translation link';
        removeBtn.innerHTML = '<i class="bi bi-x-lg"></i>';

        row.appendChild(badge);
        row.appendChild(info);
        row.appendChild(removeBtn);

        removeBtn.addEventListener('click', function () {
            song.translations.splice(i, 1);
            markModified(song.id);
            renderTranslations(song);
        });

        container.appendChild(row);
    });

    if (song.translations.length === 0) {
        var empty = document.createElement('div');
        empty.className = 'text-muted small';
        empty.textContent = 'No translations linked.';
        container.appendChild(empty);
    }

    /* Populate the datalist with all songs for autocomplete */
    var datalist = document.getElementById('translation-song-list');
    if (datalist) {
        datalist.innerHTML = '';
        songData.songs.forEach(function (s) {
            if (s.id === song.id) return; /* skip self */
            var opt = document.createElement('option');
            opt.value = s.id;
            opt.textContent = s.title + ' (' + (s.language || 'en') + ')';
            datalist.appendChild(opt);
        });
    }
}

/**
 * Initialise the "Add Translation" button event handler (#352).
 * Called once after DOM is ready.
 */
function initTranslationControls() {
    var addBtn = document.getElementById('add-translation-btn');
    if (!addBtn) return;

    addBtn.addEventListener('click', function () {
        if (!currentSongId) {
            showToast('Select a song first.', 'warning');
            return;
        }
        var input = document.getElementById('add-translation-songid');
        var targetId = (input.value || '').trim();
        if (!targetId) {
            showToast('Enter a target Song ID.', 'warning');
            return;
        }

        var song = songData.songs.find(function (s) { return s.id === currentSongId; });
        if (!song) return;

        /* Check target song exists */
        var targetSong = songData.songs.find(function (s) { return s.id === targetId; });
        if (!targetSong) {
            showToast('Song "' + targetId + '" not found in the database.', 'danger');
            return;
        }

        /* Check not a duplicate */
        if (!song.translations) song.translations = [];
        if (song.translations.some(function (t) { return t.songId === targetId; })) {
            showToast('Translation link already exists.', 'warning');
            return;
        }

        /* Check not self */
        if (targetId === currentSongId) {
            showToast('A song cannot be a translation of itself.', 'warning');
            return;
        }

        song.translations.push({
            songId: targetId,
            language: targetSong.language || 'en'
        });
        markModified(song.id);
        renderTranslations(song);
        input.value = '';
        showToast('Translation link added.', 'success');
    });
}

/* ============================================================================
 * Cross-book counterparts (#807) — same hymn in different songbooks.
 *
 * Differs from the Translations panel in two ways:
 *   1. Counterparts are typically same-language, different-songbook.
 *   2. Persistence is server-side immediate (POST /api?action=add_song_link)
 *      rather than batched into the song's save payload — the relationship
 *      is independent of the song's other metadata, so deferring to save
 *      would break the "linked songs appear on each other's page" promise
 *      until the second song was also saved.
 * ============================================================================ */

/* Latest fetch result kept in-memory so render-only re-runs don't refetch. */
var songLinksCache = { songId: null, links: [] };

/* ==========================================================================
 *  SECTION — External website links per song (#833)
 *
 *  Backed by tblSongExternalLinks. Renders the shared editor module from
 *  /js/modules/external-links-editor.js into the Links tab. We mount the
 *  editor once (idempotent — repeat selectSong calls just re-hydrate the
 *  rows), wire one delegated change listener that mirrors row state into
 *  song.links so the save_song payload stays in sync with the form, and
 *  flag the song as modified whenever a row is added / removed / edited.
 * ========================================================================== */

var _songExtLinksEditor = null;

/**
 * Capture the current external-links rows from the DOM. Used both for
 * mirroring into the open song's `links` array on input/change events,
 * and for the pre-switch capture in selectSong().
 *
 * @returns {Array<{typeId:number,url:string,note:string,verified:boolean}>}
 */
function captureSongExternalLinksFromDom() {
    var rowsEl = document.getElementById('edit-song-ext-links-rows');
    var out    = [];
    if (!rowsEl) return out;
    var rows = rowsEl.querySelectorAll('.ihymns-ext-link-row');
    rows.forEach(function (card) {
        var sel  = card.querySelector('select[name="ext_link_type_ids[]"]');
        var url  = card.querySelector('input[name="ext_link_urls[]"]');
        var note = card.querySelector('input[name="ext_link_notes[]"]');
        var ver  = card.querySelector('input[name="ext_link_verified[]"]');
        var typeId = Number(sel ? sel.value : 0);
        var u      = String(url ? url.value : '').trim();
        if (!typeId || !u) return;
        out.push({
            typeId:   typeId,
            url:      u,
            note:     String(note ? note.value : '').trim(),
            verified: !!(ver && ver.checked),
        });
    });
    return out;
}

/**
 * Mirror the current DOM state into the open song's `.links` array and
 * mark the song as modified so the auto-save flow ships the change.
 */
function syncSongExternalLinksFromDom() {
    /* Key off the RENDERED song, not currentSongId (WS-D #1016): during an
       async open, currentSongId may already point at the song being
       fetched while the DOM still shows the previous one — keying off
       currentSongId would write the displayed links into the wrong song. */
    if (typeof _renderedSongId === 'undefined' || !_renderedSongId) return;
    var song = (songData && songData.songs || []).find(function (s) { return s.id === _renderedSongId; });
    if (!song) return;
    song.links = captureSongExternalLinksFromDom();
    if (typeof modifiedSongIds !== 'undefined' && modifiedSongIds && typeof modifiedSongIds.add === 'function') {
        modifiedSongIds.add(_renderedSongId);
    }
    if (typeof scheduleAutoSave === 'function') scheduleAutoSave();
    if (typeof updateStatusBar === 'function') updateStatusBar();
}

/**
 * Render (mount + hydrate) the External Links section in the Links tab.
 * The shared module owns the row template / Add / Remove / auto-detect;
 * this function just feeds it the current song's saved links.
 */
function renderSongExternalLinks(song) {
    var rowsEl = document.getElementById('edit-song-ext-links-rows');
    var addBtn = document.getElementById('edit-song-ext-link-add-btn');
    if (!rowsEl || !window.iHymnsExtLinksEditor) return;

    if (!_songExtLinksEditor) {
        _songExtLinksEditor = window.iHymnsExtLinksEditor.mount({
            rowsEl:   rowsEl,
            addBtnEl: addBtn,
        });
        /* Delegated change listener — every row built by the shared
           module lives inside rowsEl, so one listener catches every
           input / change. Each change mirrors back into the song
           object and re-arms auto-save. */
        rowsEl.addEventListener('input',  syncSongExternalLinksFromDom);
        rowsEl.addEventListener('change', syncSongExternalLinksFromDom);
        /* Add-row + Remove-row trigger DOM mutations rather than
           input events on the existing inputs. Wrap the editor's
           public addRow + a delegated click handler so the song
           object stays in sync. */
        if (addBtn) {
            addBtn.addEventListener('click', function () {
                /* Defer one tick so the new row's nodes exist when we capture. */
                setTimeout(syncSongExternalLinksFromDom, 0);
            });
        }
        rowsEl.addEventListener('click', function (ev) {
            if (ev.target.closest('[data-action="remove-ext-link"]')) {
                setTimeout(syncSongExternalLinksFromDom, 0);
            }
        });
    }

    var prefill = (Array.isArray(song.links) ? song.links : []).map(function (l) {
        return {
            typeId:   Number(l.typeId   || l.type_id   || 0),
            url:      String(l.url      || ''),
            note:     String(l.note     || ''),
            verified: !!(l.verified),
        };
    });
    _songExtLinksEditor.setRows(prefill);
}


/**
 * fetchSongLinks(songId, cb)
 * --------------------------
 * Reads the current cross-book counterparts for one song from
 * /api?action=get_song_links and invokes cb({groupId, links}).
 */
function fetchSongLinks(songId, cb) {
    fetch('api.php?action=get_song_links&id=' + encodeURIComponent(songId))
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.error) { cb({ groupId: 0, links: [] }); return; }
            cb({
                groupId: data.groupId || 0,
                links:   Array.isArray(data.links) ? data.links : [],
            });
        })
        .catch(function () { cb({ groupId: 0, links: [] }); });
}

/**
 * renderSongLinks(song)
 * ---------------------
 * Populates #song-links-container with one row per cross-book counterpart.
 * The dataset is loaded asynchronously; while the fetch is in flight the
 * container shows a small loading message.
 *
 * @param {Object} song - The currently-open song.
 */
function renderSongLinks(song) {
    var container = document.getElementById('song-links-container');
    if (!container) return;

    /* Songs that haven't been persisted yet (synthetic ID prefix
       "song-") have no FK target in tblSongs, so the API would 404
       any link attempt. Surface that explicitly rather than letting
       the user click Link and watch it fail. */
    var unsaved = (typeof song.id === 'string' && song.id.indexOf('song-') === 0);
    if (unsaved) {
        container.innerHTML = '';
        var hint = document.createElement('span');
        hint.className = 'text-muted small';
        hint.textContent = 'Save the song first, then add counterparts.';
        container.appendChild(hint);
        var datalist0 = document.getElementById('song-link-song-list');
        if (datalist0) datalist0.innerHTML = '';
        var sugBox = document.getElementById('song-link-suggestions');
        if (sugBox) sugBox.style.display = 'none';
        return;
    }

    container.innerHTML = '';
    var loading = document.createElement('span');
    loading.className = 'text-muted small';
    loading.textContent = 'Loading cross-book counterparts…';
    container.appendChild(loading);

    /* Populate the datalist eagerly — the user might start typing
       before the fetch returns. */
    var datalist = document.getElementById('song-link-song-list');
    if (datalist) {
        datalist.innerHTML = '';
        songData.songs.forEach(function (s) {
            if (s.id === song.id) return;
            var opt = document.createElement('option');
            opt.value = s.id;
            opt.textContent = s.title + ' (' + (s.songbook || '?') + ')';
            datalist.appendChild(opt);
        });
    }

    fetchSongLinks(song.id, function (data) {
        /* Guard against an out-of-order fetch: the user may have
           switched songs while the network call was in flight. */
        if (currentSongId !== song.id) return;

        songLinksCache = { songId: song.id, links: data.links };
        container.innerHTML = '';

        if (!data.links.length) {
            var empty = document.createElement('span');
            empty.className = 'text-muted small';
            empty.textContent = 'No cross-book counterparts linked yet.';
            container.appendChild(empty);
        } else {
            data.links.forEach(function (ln) {
                var row = document.createElement('div');
                row.className = 'd-flex align-items-center gap-2 mb-1';

                var badge = document.createElement('span');
                badge.className = 'badge bg-secondary';
                badge.textContent = ln.songbook || '?';

                var info = document.createElement('span');
                info.className = 'flex-grow-1 small';
                var strong = document.createElement('strong');
                strong.textContent = ln.songId;
                info.appendChild(strong);
                var suffix = ' — ' + (ln.title || '(unknown)');
                if (ln.number !== null && ln.number !== undefined) {
                    suffix += ' (#' + ln.number + ')';
                }
                info.appendChild(document.createTextNode(suffix));

                var removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'btn btn-sm btn-outline-danger';
                removeBtn.title = 'Unlink this counterpart';
                removeBtn.innerHTML = '<i class="bi bi-x-lg"></i>';
                removeBtn.addEventListener('click', function () {
                    removeSongLink(song.id, ln.songId);
                });

                row.appendChild(badge);
                row.appendChild(info);
                row.appendChild(removeBtn);
                container.appendChild(row);
            });
        }

        /* Suggestions panel refresh runs alongside the link list. */
        renderSongLinkSuggestions(song);
    });
}

/**
 * addSongLinkClickHandler()
 * -------------------------
 * Wired to the "Link" button below the counterparts list. POSTs to
 * /api?action=add_song_link, then re-renders both the list and the
 * suggestions panel on success.
 */
function addSongLinkClickHandler() {
    if (!currentSongId) {
        showToast('Select a song first.', 'warning');
        return;
    }
    var input = document.getElementById('add-song-link-songid');
    var targetId = (input.value || '').trim();
    if (!targetId) {
        showToast('Enter a target Song ID.', 'warning');
        return;
    }
    if (targetId === currentSongId) {
        showToast('A song cannot be a counterpart of itself.', 'warning');
        return;
    }
    var targetSong = songData.songs.find(function (s) { return s.id === targetId; });
    if (!targetSong) {
        showToast('Song "' + targetId + '" not found in the database.', 'danger');
        return;
    }

    fetch('api.php?action=add_song_link', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body:    JSON.stringify({ sourceSongId: currentSongId, targetSongId: targetId }),
    })
        .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
        .then(function (resp) {
            if (resp.status >= 400 || (resp.body && resp.body.error)) {
                showToast(resp.body && resp.body.error
                    ? resp.body.error
                    : 'Failed to add counterpart link.', 'danger');
                return;
            }
            input.value = '';
            var song = songData.songs.find(function (s) { return s.id === currentSongId; });
            if (song) renderSongLinks(song);
            showToast('Cross-book counterpart linked.', 'success');
        })
        .catch(function () {
            showToast('Network error while linking counterpart.', 'danger');
        });
}

/**
 * removeSongLink(currentId, targetSongId)
 * ---------------------------------------
 * Drops the link for `targetSongId` from the current group. The server
 * also drops the remaining row if removing leaves a singleton group.
 */
function removeSongLink(currentId, targetSongId) {
    fetch('api.php?action=remove_song_link', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body:    JSON.stringify({ songId: targetSongId }),
    })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.error) {
                showToast(data.error, 'danger');
                return;
            }
            var song = songData.songs.find(function (s) { return s.id === currentId; });
            if (song) renderSongLinks(song);
            showToast('Cross-book counterpart unlinked.', 'success');
        })
        .catch(function () {
            showToast('Network error while unlinking counterpart.', 'danger');
        });
}

/**
 * renderSongLinkSuggestions(song)
 * -------------------------------
 * Populates #song-link-suggestions with up to 5 high-similarity pairs
 * involving the open song. Hidden when no suggestions are available.
 *
 * Server-side endpoint also returns `tableMissing: true` when the
 * suggestion table doesn't exist yet (migration not applied) — in
 * that case we silently keep the panel hidden.
 */
function renderSongLinkSuggestions(song) {
    var box   = document.getElementById('song-link-suggestions');
    var list  = document.getElementById('song-link-suggestions-list');
    if (!box || !list) return;

    fetch('api.php?action=suggest_song_links&id=' + encodeURIComponent(song.id))
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (currentSongId !== song.id) return;
            var suggestions = (data && Array.isArray(data.suggestions)) ? data.suggestions : [];
            if (!suggestions.length) {
                box.style.display = 'none';
                list.innerHTML = '';
                return;
            }
            list.innerHTML = '';
            suggestions.forEach(function (sg) {
                var row = document.createElement('div');
                row.className = 'd-flex align-items-center gap-2 mb-1 small';

                var scoreBadge = document.createElement('span');
                scoreBadge.className = 'badge bg-info-subtle text-info-emphasis';
                scoreBadge.textContent = Math.round(sg.score * 100) + '%';
                scoreBadge.title = 'Title score ' + Math.round(sg.titleScore * 100)
                                 + '% · lyrics score ' + Math.round(sg.lyricsScore * 100) + '%';

                var info = document.createElement('span');
                info.className = 'flex-grow-1';
                var idChip = document.createElement('strong');
                idChip.textContent = sg.other.songId;
                info.appendChild(idChip);
                var label = ' — ' + (sg.other.title || '(untitled)');
                if (sg.other.number !== null && sg.other.number !== undefined) {
                    label += ' (' + (sg.other.songbook || '?') + ' #' + sg.other.number + ')';
                } else if (sg.other.songbook) {
                    label += ' (' + sg.other.songbook + ')';
                }
                info.appendChild(document.createTextNode(label));

                var linkBtn = document.createElement('button');
                linkBtn.type = 'button';
                linkBtn.className = 'btn btn-sm btn-outline-success';
                linkBtn.title = 'Link as the same hymn';
                linkBtn.innerHTML = '<i class="bi bi-link-45deg"></i>';
                linkBtn.addEventListener('click', function () {
                    fetch('api.php?action=add_song_link', {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body:    JSON.stringify({
                            sourceSongId: song.id,
                            targetSongId: sg.other.songId,
                        }),
                    })
                        .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
                        .then(function (resp) {
                            if (resp.status >= 400 || (resp.body && resp.body.error)) {
                                showToast(resp.body && resp.body.error
                                    ? resp.body.error
                                    : 'Failed to link from suggestion.', 'danger');
                                return;
                            }
                            showToast('Linked as same hymn.', 'success');
                            renderSongLinks(song);
                        })
                        .catch(function () {
                            showToast('Network error.', 'danger');
                        });
                });

                var dismissBtn = document.createElement('button');
                dismissBtn.type = 'button';
                dismissBtn.className = 'btn btn-sm btn-outline-secondary';
                dismissBtn.title = 'Dismiss — different hymns';
                dismissBtn.innerHTML = '<i class="bi bi-x-lg"></i>';
                dismissBtn.addEventListener('click', function () {
                    fetch('api.php?action=dismiss_song_link_suggestion', {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body:    JSON.stringify({
                            songIdA: song.id,
                            songIdB: sg.other.songId,
                        }),
                    })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (data && data.error) {
                                showToast(data.error, 'danger');
                                return;
                            }
                            showToast('Suggestion dismissed.', 'info');
                            renderSongLinkSuggestions(song);
                        })
                        .catch(function () {
                            showToast('Network error.', 'danger');
                        });
                });

                row.appendChild(scoreBadge);
                row.appendChild(info);
                row.appendChild(linkBtn);
                row.appendChild(dismissBtn);
                list.appendChild(row);
            });
            box.style.display = '';
        })
        .catch(function () {
            box.style.display = 'none';
        });
}

/**
 * initSongLinksControls()
 * -----------------------
 * Wires the "Link" button. Idempotent: safe to call from init().
 */
function initSongLinksControls() {
    var addBtn = document.getElementById('add-song-link-btn');
    if (!addBtn) return;
    addBtn.addEventListener('click', addSongLinkClickHandler);

    /* Enter on the input field also triggers Link — matches the
       interaction model on the Translations input. */
    var input = document.getElementById('add-song-link-songid');
    if (input) {
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                addSongLinkClickHandler();
            }
        });
    }
}

/* ------------------------------------------------------------------
 * #960 — Structured-name helpers (JS mirror of composePersonName /
 * decomposePersonName in includes/credit_people_helpers.php).
 *
 * The Credits-tab chip lists render three inputs per credit
 * (FirstNames / Surname / Suffix) but the on-disk wire format and
 * tblSongWriters/Composers/... rows continue to store a single
 * composed `Name`. These two helpers are the single source of
 * truth for going between those shapes on the client.
 *
 * Behaviour MUST stay byte-equal to the PHP implementation so a
 * name typed in the editor round-trips identically through save →
 * load → re-edit.
 * ------------------------------------------------------------------ */

/* Suffix tokens recognised by decomposePersonNameJs. Mirror of the
   regex literal in PHP decomposePersonName(). Extend in lockstep. */
var CREDIT_NAME_SUFFIX_PATTERN = /^(?:Jr|Sr|II|III|IV|V|VI|VII|VIII|PhD|Ph\.D\.|MD|M\.D\.|Esq|Esq\.|D\.D\.|D\.Min\.|MA|M\.A\.|BA|B\.A\.)$/i;

function composePersonNameJs(first, surname, suffix) {
    var parts = [];
    [first, surname, suffix].forEach(function (p) {
        var t = (p == null ? '' : String(p)).trim();
        if (t !== '') parts.push(t);
    });
    return parts.join(' ').replace(/\s+/g, ' ');
}

function decomposePersonNameJs(name) {
    var s = (name == null ? '' : String(name)).replace(/\s+/g, ' ').trim();
    if (s === '') return { first: '', surname: '', suffix: '' };
    if (s.indexOf(',') !== -1) {
        var idx   = s.indexOf(',');
        var lhs   = s.slice(0, idx).trim();
        var rhs   = s.slice(idx + 1).trim();
        if (lhs !== '' && rhs !== '') s = rhs + ' ' + lhs;
    }
    var tokens = s.split(/\s+/).filter(function (t) { return t !== ''; });
    if (tokens.length === 0) return { first: '', surname: '', suffix: '' };
    var suffixParts = [];
    while (tokens.length > 1) {
        var tail     = tokens[tokens.length - 1];
        var tailNorm = tail.replace(/[.,]+$/, '');
        if (CREDIT_NAME_SUFFIX_PATTERN.test(tail) || CREDIT_NAME_SUFFIX_PATTERN.test(tailNorm)) {
            suffixParts.unshift(tokens.pop());
            continue;
        }
        break;
    }
    var suffix = suffixParts.join(' ');
    if (tokens.length === 1) return { first: '', surname: tokens[0], suffix: suffix };
    var surname = tokens.pop();
    var first   = tokens.join(' ');
    return { first: first, surname: surname, suffix: suffix };
}

/* Normalise any per-credit value into a structured-parts object.
   Accepts: legacy string ("John Newton"), already-structured object
   ({first, surname, suffix}), or null/undefined. Used everywhere the
   chip list reads the in-memory song.<credits>[i] entry. */
function normaliseCreditParts(entry) {
    if (entry && typeof entry === 'object' && !Array.isArray(entry)) {
        return {
            first:   (entry.first   != null ? String(entry.first)   : '').trim(),
            surname: (entry.surname != null ? String(entry.surname) : '').trim(),
            suffix:  (entry.suffix  != null ? String(entry.suffix)  : '').trim(),
        };
    }
    if (typeof entry === 'string') return decomposePersonNameJs(entry);
    return { first: '', surname: '', suffix: '' };
}

/* Shorthand for "render this credit entry as a single string" —
   handles both legacy string entries (pre-#960 in-flight data, CSV
   export when reading the live array directly) and the new
   structured-parts shape. The non-credit dynamic lists elsewhere
   in the editor never call this. */
function creditEntryAsString(entry) {
    if (typeof entry === 'string') return entry;
    var p = normaliseCreditParts(entry);
    return composePersonNameJs(p.first, p.surname, p.suffix);
}

/* ------------------------------------------------------------------
 * createCreditNameRow(parts, onChange, onRemove, creditKind)  — #960
 *
 * Per-credit row used by the Credits tab. Renders three inputs:
 * FirstNames / Surname / Suffix. Fires onChange({first, surname,
 * suffix}) on every keystroke. Surname is the discriminative field
 * for the live-search popover (mirroring how curators search the
 * People page); selecting a suggestion overwrites all three inputs
 * from the matched registry row.
 *
 * The legacy `createDynamicInputRow` single-input factory remains
 * for any non-credit dynamic-list use; this helper is the shape
 * Writers / Composers / Arrangers / Adaptors / Translators /
 * Artists all use post-#960.
 * ------------------------------------------------------------------ */
function createCreditNameRow(parts, onChange, onRemove, creditKind) {
    var row = document.createElement('div');
    row.className = 'input-group input-group-sm mb-1 position-relative credit-chip-row';

    function mkInput(field, placeholder) {
        var el = document.createElement('input');
        el.type = 'text';
        el.className = 'form-control credit-name-' + field + '-input';
        el.placeholder = placeholder;
        el.autocomplete = 'off';
        el.value = parts[field] || '';
        el.setAttribute('aria-label', placeholder);
        el.addEventListener('input', function () {
            parts[field] = el.value;
            onChange({
                first:   parts.first   || '',
                surname: parts.surname || '',
                suffix:  parts.suffix  || '',
            });
        });
        return el;
    }

    var firstEl   = mkInput('first',   'First names');
    var surnameEl = mkInput('surname', 'Surname');
    var suffixEl  = mkInput('suffix',  'Suffix');

    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'btn btn-outline-danger';
    removeBtn.innerHTML = '&times;';
    removeBtn.title = 'Remove';
    removeBtn.addEventListener('click', function () { onRemove(); });

    row.appendChild(firstEl);
    row.appendChild(surnameEl);
    row.appendChild(suffixEl);
    row.appendChild(removeBtn);

    /* Typeahead — the popover is owned by the row, fed by whichever
       of First/Surname input currently has focus. Suffix doesn't
       trigger search (rarely discriminative on its own). A pick
       overwrites all three inputs from the matched registry row
       so a curated "John Newton" with structured columns lands
       cleanly into the chip. */
    if (creditKind) {
        attachStructuredCreditAutocomplete(row, parts, firstEl, surnameEl, creditKind, onChange);
    }

    return row;
}

/* Live-search popover for the structured Credit row. Mirrors
   attachCreditAutocomplete (the legacy single-input one) but:
     - Listens on TWO inputs (first + surname).
     - Sends q=<focused input value> to /api?action=credit_search.
     - Updates all three parts inputs from the chosen suggestion.
     - When credit_search returns `first`/`surname`/`suffix` for a
       registry-matched row, prefers those over a client-side
       decompose of the composed Name. Pre-enhancement servers
       (no parts in response) fall back to decomposePersonNameJs(). */
function attachStructuredCreditAutocomplete(row, parts, firstEl, surnameEl, kind, onChange) {
    var popover = document.createElement('div');
    popover.className = 'list-group position-absolute w-100 shadow d-none credit-suggestions-popover';
    popover.style.zIndex = '1050';
    popover.style.top = '100%';
    popover.style.left = '0';
    popover.style.maxHeight = '220px';
    popover.style.overflowY = 'auto';
    row.appendChild(popover);

    var debounceTimer = null;

    function close() {
        popover.classList.add('d-none');
        popover.innerHTML = '';
    }

    function applyPick(s) {
        /* Prefer structured parts from the server when present;
           otherwise decompose the composed name client-side. The
           outer onChange call updates the song object once, after
           all three field values are in place. */
        var picked;
        if (s.first != null || s.surname != null || s.suffix != null) {
            picked = {
                first:   s.first   != null ? String(s.first)   : '',
                surname: s.surname != null ? String(s.surname) : '',
                suffix:  s.suffix  != null ? String(s.suffix)  : '',
            };
        } else {
            picked = decomposePersonNameJs(s.name || '');
        }
        parts.first   = picked.first;
        parts.surname = picked.surname;
        parts.suffix  = picked.suffix;
        firstEl.value   = picked.first;
        surnameEl.value = picked.surname;
        /* The suffix input is the third <input> inside row. */
        var suffixEl = row.querySelector('.credit-name-suffix-input');
        if (suffixEl) suffixEl.value = picked.suffix;
        onChange({ first: parts.first, surname: parts.surname, suffix: parts.suffix });
    }

    function render(suggestions) {
        popover.innerHTML = '';
        if (!suggestions.length) { close(); return; }
        suggestions.forEach(function (s) {
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center py-1';
            var kindsBadge = (s.kinds && s.kinds.length)
                ? '<small class="text-muted">' + escapeHtmlSafe(s.kinds.join(' · ')) + '</small>'
                : '';
            var creditUsageNum = parseInt(s.usage, 10);
            if (!Number.isFinite(creditUsageNum) || creditUsageNum < 0) creditUsageNum = 0;
            item.innerHTML =
                '<span><strong>' + escapeHtmlSafe(s.name || '') + '</strong> ' + kindsBadge + '</span>' +
                '<span class="badge bg-secondary">' + creditUsageNum + '</span>';
            item.addEventListener('click', function (e) {
                e.preventDefault();
                applyPick(s);
                close();
            });
            popover.appendChild(item);
        });
        popover.classList.remove('d-none');
    }

    function fetchSuggestions(q) {
        var url = EDITOR_API_URL + '?action=credit_search' +
                  '&q='    + encodeURIComponent(q) +
                  '&kind=any&limit=12';
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) {
                    return r.text().then(function (body) {
                        throw new Error('credit_search ' + r.status + ': ' + body.slice(0, 200));
                    });
                }
                return r.json();
            })
            .then(function (data) {
                render(Array.isArray(data.suggestions) ? data.suggestions : []);
            })
            .catch(function (err) {
                console.warn('[editor] credit_search failed:', err && err.message);
                close();
            });
    }

    function wire(inputEl) {
        inputEl.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            var q = inputEl.value.trim();
            if (q.length < 1) { close(); return; }
            debounceTimer = setTimeout(function () { fetchSuggestions(q); }, 180);
        });
        inputEl.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') close();
        });
    }
    wire(firstEl);
    wire(surnameEl);

    document.addEventListener('click', function (e) {
        if (!row.contains(e.target)) close();
    });
}

/**
 * createDynamicInputRow(value, onChange, onRemove)
 * ------------------------------------------------
 * Factory function that builds a single input-group row used for both
 * writers and composers lists.
 *
 * @param {string}   value    - The current text value.
 * @param {Function} onChange - Called with the new value on every keystroke.
 * @param {Function} onRemove - Called when the remove button is clicked.
 * @returns {HTMLElement} The assembled input-group div.
 */
function createDynamicInputRow(value, onChange, onRemove, creditKind) {
    /* Wrapper div styled as a Bootstrap input group. */
    var row = document.createElement('div');
    row.className = 'input-group input-group-sm mb-1 position-relative credit-chip-row';

    /* Text input. */
    var input = document.createElement('input');
    input.type = 'text';
    input.className = 'form-control';
    input.value = value;
    input.autocomplete = 'off';
    /* Live-bind every keystroke back to the data. */
    input.addEventListener('input', function () {
        onChange(input.value);
    });

    /* Remove button. */
    var removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'btn btn-outline-danger';
    removeBtn.innerHTML = '&times;';
    removeBtn.title = 'Remove';
    removeBtn.addEventListener('click', function () {
        onRemove();
    });

    /* Assemble the row. */
    row.appendChild(input);
    row.appendChild(removeBtn);

    /* Attach the live-search popover (#495) when a credit kind is
       passed. The popover queries /api?action=credit_search which
       unions across all five credit tables so a "Fanny Crosby"
       already used as a Writer surfaces when typing in Composers,
       avoiding the dedupe drift problem described in the issue.

       No-op when called without a kind (e.g. for non-credit dynamic
       list uses), so the helper stays general. */
    if (creditKind) {
        attachCreditAutocomplete(input, row, creditKind, onChange);
    }

    return row;
}

/**
 * attachCreditAutocomplete(input, row, kind, onChange)
 * ----------------------------------------------------
 * Wire a chip input to the /api?action=credit_search endpoint so
 * typing surfaces a popover of matching stored-canonical spellings.
 * Clicking one rewrites the input to the exact stored form and fires
 * onChange so the song object picks it up. Escape / click-outside
 * dismiss; popover is constrained within the input-group row via
 * absolute positioning so long credit lists stay readable.
 */
function attachCreditAutocomplete(input, row, kind, onChange) {
    var popover = document.createElement('div');
    popover.className = 'list-group position-absolute w-100 shadow d-none credit-suggestions-popover';
    popover.style.zIndex = '1050';
    popover.style.top = '100%';
    popover.style.left = '0';
    popover.style.maxHeight = '220px';
    popover.style.overflowY = 'auto';
    row.appendChild(popover);

    var debounceTimer = null;

    function close() {
        popover.classList.add('d-none');
        popover.innerHTML = '';
    }

    function render(suggestions) {
        popover.innerHTML = '';
        if (!suggestions.length) { close(); return; }
        suggestions.forEach(function (s) {
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center py-1';
            var kindsBadge = (s.kinds && s.kinds.length)
                ? '<small class="text-muted">' + escapeHtmlSafe(s.kinds.join(' · ')) + '</small>'
                : '';
            /* #958 — same coerce-to-integer pattern as the tag-search
               sink above. `s.usage` from the credit_search API is a
               count, but defence-in-depth: coerce before interpolation
               so a stored-XSS via a string `usage` is impossible. */
            var creditUsageNum = parseInt(s.usage, 10);
            if (!Number.isFinite(creditUsageNum) || creditUsageNum < 0) creditUsageNum = 0;
            item.innerHTML =
                '<span><strong>' + escapeHtmlSafe(s.name) + '</strong> ' + kindsBadge + '</span>' +
                '<span class="badge bg-secondary">' + creditUsageNum + '</span>';
            item.addEventListener('click', function (e) {
                e.preventDefault();
                input.value = s.name;
                onChange(s.name);
                close();
                input.focus();
            });
            popover.appendChild(item);
        });
        popover.classList.remove('d-none');
    }

    function fetchSuggestions(q) {
        /* `kind=any` unions all five tables so the same spelling
           surfaces no matter which chip list the admin is editing. */
        var url = EDITOR_API_URL + '?action=credit_search' +
                  '&q='    + encodeURIComponent(q) +
                  '&kind=any&limit=12';
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) {
                /* Surface non-2xx so a 401/404 doesn't silently look
                   identical to an empty result set. (#593) */
                if (!r.ok) {
                    return r.text().then(function (body) {
                        throw new Error('credit_search ' + r.status + ': ' + body.slice(0, 200));
                    });
                }
                return r.json();
            })
            .then(function (data) {
                render(Array.isArray(data.suggestions) ? data.suggestions : []);
            })
            .catch(function (err) {
                console.warn('[editor] credit_search failed:', err && err.message);
                close();
            });
    }

    input.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        var q = input.value.trim();
        if (q.length < 1) { close(); return; }
        debounceTimer = setTimeout(function () { fetchSuggestions(q); }, 180);
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') close();
    });

    /* Dismiss on click outside this specific row. We register a
       delegated handler only once per row — listener is cleaned up
       implicitly when the row is removed from the DOM. */
    document.addEventListener('click', function (e) {
        if (!row.contains(e.target)) close();
    });
}

/* ========================================================================
 *  SECTION 7 — JSON Validation & Save (#58)
 * ======================================================================== */

/* #961 — validateSongsByIds() returns { errors: [...], warnings: [...] }.
   Hard errors (missing id / title / songbook / components) block the save;
   soft cautions (e.g. a missing number on an Official songbook, which the
   server stores as NULL) surface as non-blocking toasts. The whole-corpus
   validateSongData() was removed in WS-D (#1016): with the catalogue in
   MySQL the DB enforces these constraints, /manage/data-health owns
   catalogue-wide checks, and the editor only ever holds the songs it has
   actually opened — so there is no in-memory corpus to scan. */

/**
 * validateSongsByIds(ids)
 * -----------------------
 * Validation rules for the per-song Save, scoped to a specific list of
 * song IDs. Used by the manual Save button so that missing fields on a
 * song the admin never touched don't block a save of the song they
 * actually edited.
 *
 * @param {string[]} ids Song IDs to validate
 * @returns {string[]} Human-readable error messages; empty if all valid
 */
function validateSongsByIds(ids) {
    var wanted = new Set(ids);
    var errors = [];
    var warnings = [];
    /* IsOfficial map by songbook abbreviation — number is required
       only when the song's songbook is flagged IsOfficial=1. (#392) */
    var officialByAbbr = (songData.songbooks || []).reduce(function (map, b) {
        if (b && typeof b.id === 'string') map[b.id] = !!b.isOfficial;
        return map;
    }, Object.create(null));
    (songData.songs || []).forEach(function (song, i) {
        if (!wanted.has(song.id)) return;
        var label = 'Song ' + (song.id || '[' + i + ']') +
                    ' (' + (song.title || 'no title') + ')';
        if (!song.id || typeof song.id !== 'string' || song.id.trim() === '') {
            errors.push(label + ': missing or empty "id".');
        }
        if (!song.title || typeof song.title !== 'string' || song.title.trim() === '') {
            errors.push(label + ': missing or empty "title".');
        }
        if (!song.songbook || typeof song.songbook !== 'string' || song.songbook.trim() === '') {
            /* NOT a hard error any more: the server coerces a blank songbook to the
               generic "Misc" collection on save (and new drafts now default to Misc
               client-side), so a missing songbook must never BLOCK the save and lose
               the curator's edits. Surface it as a non-blocking warning, like the
               number (#961) — the save still proceeds, filing the song under Misc. */
            warnings.push(label + ': no songbook chosen — saved under "Misc". Pick a songbook to file it correctly.');
        }
        /* #961 — a missing number on an Official songbook is a
           non-blocking warning, not a hard error. The server accepts
           NULL, so the save still proceeds. */
        var isOfficial = !!officialByAbbr[(song.songbook || '').trim()];
        if (isOfficial && (song.number == null || String(song.number).trim() === '')) {
            warnings.push(label + ': no "number" supplied. The songbook is flagged Official — saved as NULL.');
        }
        if (!Array.isArray(song.components) || song.components.length === 0) {
            errors.push(label + ': must have at least one component.');
        }
    });
    return { errors: errors, warnings: warnings };
}

/* ========================================================================
 *  SECTION 8 — Bulk Import / Export (#59)
 * ======================================================================== */

/**
 * exportCurrentSong()
 * -------------------
 * Downloads the currently-open song as a single-song JSON document
 * using the shared filename convention (#883 follow-up):
 *   "<padded#> (<ABBR>) - <Title>[ (<Tune>)].json"
 *
 * Padding width is derived from the maximum song number in the
 * song's own songbook (floor 3) so files sort numerically when the
 * curator drops a directory of exports into Finder / Explorer /
 * iTunes / etc.
 *
 * Payload shape mirrors Bulk Export — `{ songs: [<song>] }` — so
 * the same Import flow ingests an individual-song file without a
 * special case.
 */
function exportCurrentSong() {
    if (!currentSongId) {
        showToast('No song is open to export.', 'warning');
        return;
    }
    var song = findSongById(currentSongId);
    if (!song) {
        showToast('Could not find the open song to export.', 'danger');
        return;
    }

    var bookSongs = (songData.songs || []).filter(function (s) {
        return s.songbook === song.songbook;
    });

    import('/js/modules/export-filename.js?v=' + Date.now())
        .then(function (m) {
            var name = m.songExportFilename(song, bookSongs);
            downloadBlob(
                JSON.stringify({ songs: [song] }, null, 2),
                name + '.json',
                'application/json'
            );
            showToast('Exported "' + (song.title || song.id) + '".', 'success');
        })
        .catch(function (err) {
            try { console.warn('[export_song] filename helper load failed:', err); } catch (_e) {}
            /* Last-resort fallback so the export still works if the
               helper module is unavailable. Uses a safe-but-ugly
               filename rather than throwing. */
            downloadBlob(
                JSON.stringify({ songs: [song] }, null, 2),
                (song.id || 'song') + '.json',
                'application/json'
            );
            showToast('Exported (fallback filename — see console).', 'warning');
        });
}

/**
 * importJSON()
 * ------------
 * Opens a file picker for a server-side bulk import, then routes the
 * picked file:
 *
 *   .zip  — Streamed straight to /manage/editor/api.php?action=
 *           bulk_import_zip, which inserts directly into MySQL.
 *           INSERT-ONLY semantics: existing songbook + song rows are
 *           NEVER overwritten. After a successful zip import, the
 *           editor reloads its (slim) song index so newly inserted rows
 *           appear in the list immediately. (#664)
 *
 *   .json — VideoPsalm whole-hymnal payload (top-level `Songs[]`),
 *           uploaded to the bulk-import endpoint. The historic iHymns
 *           full-corpus JSON merge was retired in WS-D (#1016) — songs
 *           are edited live in the database now.
 *
 * Anything else surfaces a "use .json or .zip" toast.
 */
function importJSON() {
    var input = document.createElement('input');
    input.type = 'file';
    input.accept = '.json,.zip,.xml,.pro6,.show,.db,.rtf,.txt,.pptx,.ppt,.cho,.chopro,.crd,.chord,.pro,application/json,application/zip,text/xml,application/xml,application/rtf,text/plain,application/vnd.openxmlformats-officedocument.presentationml.presentation';

    input.addEventListener('change', function () {
        if (!input.files || !input.files[0]) return; // user cancelled
        var file = input.files[0];
        var lower = (file.name || '').toLowerCase();

        if (lower.endsWith('.zip')) {
            importBulkZip(file);
        } else if (lower.endsWith('.json')) {
            importJsonCorpus(file);
        } else if (lower.endsWith('.xml')) {
            /* OpenLyrics / OpenLP single-song export (#1052). A folder of
               these exported as a .zip uses the ZIP path above instead. */
            importOpenLp(file);
        } else if (lower.endsWith('.pro6')) {
            /* ProPresenter 6 single-song export (#1057). A folder of .pro6
               files exported as a .zip uses the ZIP path above instead. */
            importPro6(file);
        } else if (lower.endsWith('.db')) {
            /* EasyWorship Songs.db (#1058). A two-file EW export (Songs.db +
               SongWords.db) is zipped and uses the ZIP path above, which the
               server auto-routes to EasyWorship on detecting a Songs.db. */
            importEasyWorship(file);
        } else if (lower.endsWith('.show')) {
            /* FreeShow single-song export (#884). A folder of .show files
               exported as a .zip uses the ZIP path above instead. */
            importFreeShow(file);
        } else if (lower.endsWith('.cho') || lower.endsWith('.chopro') || lower.endsWith('.crd') || lower.endsWith('.chord') || lower.endsWith('.pro')) {
            /* ChordPro single-song import (#1264) — .cho/.chopro/.crd/.chord/.pro.
               Covers WorshipTools' hand-copied-from-Chords-tab export shape +
               OnSong/OpenSong/SongBeamer interop. (.pro6 is matched earlier.) */
            importChordPro(file);
        } else if (lower.endsWith('.rtf') || lower.endsWith('.txt')) {
            /* Proclaim text/RTF single-song export (#1062). */
            importProclaim(file);
        } else if (lower.endsWith('.pptx')) {
            /* PowerPoint worship deck (#1095). Slides are segmented into songs
               by their "# <num>-<Songbook>" title slides; existing songs dedup. */
            importPptx(file);
        } else if (lower.endsWith('.ppt')) {
            /* Legacy binary .ppt is a different (OLE) format — guide, don't upload. */
            showToast(
                'Legacy .ppt files aren’t supported. Re-save the deck as .pptx ' +
                '(PowerPoint / Keynote / Google Slides → export as .pptx) and try again.',
                'warning'
            );
        } else {
            showToast(
                'Unsupported file type. Choose a .json corpus, a .zip archive ' +
                '(.SourceSongData / OpenSong / OpenLyrics / ProPresenter 6 / ' +
                'FreeShow / EasyWorship), an OpenLyrics .xml, a ProPresenter ' +
                '.pro6, a FreeShow .show, an EasyWorship Songs.db, a ' +
                'Proclaim .txt/.rtf, or a ChordPro .cho/.pro/.chopro/.crd/.chord.',
                'danger'
            );
        }
    });

    input.click();
}

/**
 * importJsonCorpus(file)
 * ----------------------
 * Inspects a picked .json file:
 *
 *   - VideoPsalm songbook (top-level `Songs[]` with capital S, #883):
 *     uploaded straight to the bulk-import endpoint, which writes
 *     directly to MySQL.
 *
 *   - Anything else (the historic iHymns lowercase `songs[]` corpus):
 *     rejected with a notice — the whole-corpus in-memory merge was
 *     retired in WS-D (#1016).
 */
function importJsonCorpus(file) {
    var reader = new FileReader();
    reader.onload = function (event) {
        try {
            var imported = JSON.parse(event.target.result);

            /* VideoPsalm detection: top-level Songs[] (capital S),
               which the iHymns corpus shape never carries (it uses
               lowercase songs[] alongside songbooks[] / meta).
               Hand off to the bulk-import endpoint and bail out of
               the in-memory merge path. */
            if (imported && Array.isArray(imported.Songs) && !Array.isArray(imported.songs)) {
                showToast('VideoPsalm songbook detected — uploading for bulk import.', 'info');
                importVideoPsalmSongbook(file);
                return;
            }

            /* WS-D #1016 — the historic iHymns whole-corpus JSON merge is
               retired. Songs are edited live in the database; there is no
               in-memory corpus to merge into. Bulk imports go through the
               server: a VideoPsalm .json (handled above) or a .zip archive,
               both of which INSERT directly into MySQL. */
            showToast(
                'Importing a full songs.json corpus is no longer supported — ' +
                'songs are edited live in the database. For bulk import use a ' +
                '.zip archive or a VideoPsalm .json songbook.',
                'warning'
            );
        } catch (err) {
            showToast('Import failed: ' + err.message, 'danger');
        }
    };
    reader.readAsText(file);
}

/**
 * importSingleFileFormat(file, opts)
 * ----------------------------------
 * Shared single-file server-side import used by the VideoPsalm (#883)
 * and OpenLyrics / OpenLP (#1052) paths. Streams `file` as multipart to
 * `opts.action` under field `opts.field`, attaches the #1051 dedupe flag
 * from the toolbar checkbox, and surfaces the standard summary toast.
 * Insert-only by contract — existing rows report as "skipped (existing)".
 *
 * These single-song / single-songbook files are tiny, so the endpoints
 * run synchronously and return the summary in one round-trip; no async /
 * progress-widget machinery needed (that's reserved for large ZIPs).
 *
 * opts: { action, field, consoleTag }
 */
function importSingleFileFormat(file, opts) {
    var fd = new FormData();
    fd.append(opts.field, file, file.name);
    /* #1051 — opt-in title dedupe from the toolbar checkbox. */
    var _dd = document.getElementById('import-dedupe-title');
    fd.append('dedupeMode', (_dd && _dd.checked) ? 'skip-title' : 'off');

    fetch(EDITOR_API_URL + '?action=' + encodeURIComponent(opts.action), {
        method:      'POST',
        headers:     { 'X-Requested-With': 'XMLHttpRequest' },
        body:        fd,
        credentials: 'same-origin',
    }).then(function (res) {
        return res.json().catch(function () {
            return { error: 'Server returned an unparseable response (status ' + res.status + ').' };
        }).then(function (data) {
            return { ok: res.ok, data: data };
        });
    }).then(function (out) {
        if (!out.ok || !out.data || out.data.ok !== true) {
            var msg = (out.data && out.data.error) || 'Import failed.';
            showToast('Import failed: ' + msg, 'danger');
            return;
        }
        var d  = out.data;
        var sb = (d.songbooks_created  || []).length;
        var sx = (d.songbooks_existing || []).length;
        showToast(
            'Imported ' + d.songs_created + ' new song' + (d.songs_created === 1 ? '' : 's') +
            ' (' + d.songs_skipped_existing + ' already in DB, skipped). ' +
            sb + ' new songbook' + (sb === 1 ? '' : 's') + ', ' +
            sx + ' existing.',
            'success'
        );
        if (d.songs_failed > 0 || (d.errors && d.errors.length > 0)) {
            showToast(
                'Note: ' + d.songs_failed + ' song' + (d.songs_failed === 1 ? '' : 's') +
                ' failed during import — see browser console for details.',
                'warning'
            );
            try { console.warn('[' + opts.consoleTag + '] errors:', d.errors); } catch (_e) {}
        }
        if (typeof loadSongsFromURL === 'function' && typeof SONGS_URL_CANDIDATES !== 'undefined') {
            loadSongsFromURL(SONGS_URL_CANDIDATES[0]);
        }
    }).catch(function (err) {
        showToast('Import failed: ' + (err && err.message ? err.message : err), 'danger');
    });
}

/**
 * importVideoPsalmSongbook(file) — VideoPsalm single-file import (#883).
 * Thin wrapper over importSingleFileFormat().
 */
function importVideoPsalmSongbook(file) {
    importSingleFileFormat(file, {
        action:     'bulk_import_videopsalm',
        field:      'videopsalm',
        consoleTag: 'bulk_import_videopsalm',
    });
}

/**
 * importPptx(file) — PowerPoint worship-deck import (#1095). The server
 * segments slides into songs by their "Title + # <num>-<Songbook>" title
 * slides and resolves each song's songbook from that reference (existing
 * catalogue songs are skipped, not duplicated). Decks that don't use that
 * layout come back with a warning so they can be submitted for analysis (#1109).
 */
function importPptx(file) {
    importSingleFileFormat(file, {
        action:     'bulk_import_pptx',
        field:      'pptx',
        consoleTag: 'bulk_import_pptx',
    });
}

/**
 * importOpenLp(file) — OpenLyrics / OpenLP single-song .xml import (#1052).
 * Each OpenLyrics file carries its own <songbook> metadata, so the server
 * derives the songbook from the file. A whole exported FOLDER of OpenLyrics
 * files goes through importBulkZip() instead (the ZIP path content-sniffs
 * .xml entries and routes OpenLyrics ones to the same parser).
 */
function importOpenLp(file) {
    importSingleFileFormat(file, {
        action:     'bulk_import_openlp',
        field:      'openlp',
        consoleTag: 'bulk_import_openlp',
    });
}

/**
 * importPro6(file) — ProPresenter 6 single-song .pro6 import (#1057).
 * A .pro6 is one presentation/song with no songbook, so the server files it
 * under a "ProPresenter Import" (PP6) songbook. A folder of .pro6 files
 * exported as a .zip goes through importBulkZip() instead (the ZIP path
 * recognises .pro6 entries and groups them by their containing folder).
 */
function importPro6(file) {
    importSingleFileFormat(file, {
        action:     'bulk_import_pro6',
        field:      'pro6',
        consoleTag: 'bulk_import_pro6',
    });
}

/**
 * importEasyWorship(file) — EasyWorship 6/7 SQLite import (#1058).
 * Accepts a Songs.db directly. An EW export that ships Songs.db +
 * SongWords.db as a .zip goes through importBulkZip() instead — the server
 * detects a Songs.db inside the archive and routes it here automatically.
 * Lyrics are RTF inside the SQLite db; songs file under an "EasyWorship
 * Import" (EW) songbook.
 */
function importEasyWorship(file) {
    importSingleFileFormat(file, {
        action:     'bulk_import_easyworship',
        field:      'easyworship',
        consoleTag: 'bulk_import_easyworship',
    });
}

/**
 * importProclaim(file) — Proclaim text/RTF single-song import (#1062).
 * Proclaim has no rich structured export, so one .txt/.rtf file = one song
 * (RTF is decoded server-side); files under a "Proclaim Import" (PC) songbook.
 */
function importProclaim(file) {
    importSingleFileFormat(file, {
        action:     'bulk_import_proclaim',
        field:      'proclaim',
        consoleTag: 'bulk_import_proclaim',
    });
}

/**
 * importChordPro(file) — single ChordPro (.cho/.pro/.chopro/.crd/.chord) import
 * (#1264). One ChordPro document is one song; it files under a "ChordPro Import"
 * (CHORDPRO) songbook unless the filename uses the "<#> (<ABBR>) - <Title>"
 * convention. Lyrics + section structure + header metadata; inline [chord]
 * markers are parsed out server-side (per-line chord storage deferred to
 * #299/#1094). Round-trips with the lyrics-only ChordPro exporter (PR #1277).
 */
function importChordPro(file) {
    importSingleFileFormat(file, {
        action:     'bulk_import_chordpro',
        field:      'chordpro',
        consoleTag: 'bulk_import_chordpro',
    });
}

/**
 * importFreeShow(file) — FreeShow .show single-song import (#884).
 * One .show is one song with no songbook, so the server files it under a
 * "FreeShow Import" (FS) songbook. A folder of .show files exported as a .zip
 * goes through importBulkZip() instead (the ZIP path recognises .show entries
 * and groups them by their containing folder). Round-trips with the FreeShow
 * exporter (#1056).
 */
function importFreeShow(file) {
    importSingleFileFormat(file, {
        action:     'bulk_import_freeshow',
        field:      'freeshow',
        consoleTag: 'bulk_import_freeshow',
    });
}

/**
 * importBulkZip(file)
 * -------------------
 * Server-side bulk import. Streams the picked file as multipart to
 * the bulk_import_zip endpoint and surfaces the per-import summary
 * the server returns. Insert-only by contract (#664) — existing
 * songs are reported as "skipped (existing)" and the database row
 * stays untouched.
 */
function importBulkZip(file) {
    /* #907 — mount the progress widget IMMEDIATELY (before the upload
       starts) so the curator sees byte-level feedback during the
       upload phase. Pre-#907 the curator saw nothing for the entire
       duration of the upload (could be 60+s on a 38 MB ZIP) — the
       persistent widget only mounted AFTER the server returned the
       job_id, which itself happens AFTER the full upload completes.
       The widget is loaded as a dynamic ES-module import; if it fails
       (broken deploy / offline / etc.) we fall through to a toast. */
    var fd = new FormData();
    fd.append('zip', file, file.name);
    /* #1051 — opt-in title dedupe from the toolbar checkbox. */
    var _ddZip = document.getElementById('import-dedupe-title');
    fd.append('dedupeMode', (_ddZip && _ddZip.checked) ? 'skip-title' : 'off');

    /* Hand the widget a synthetic "uploading" job up-front so it can
       mount, start showing progress, and switch to real polling once
       the server returns its job_id. */
    var widgetReady = import('/js/modules/bulk-import-progress.js?v=' + Date.now())
        .then(function (m) {
            try {
                m.startUploadTracking({ filename: file.name, sizeBytes: file.size });
            } catch (_e) { /* startUploadTracking is opt-in; older bundles fall through */ }
            return m;
        })
        .catch(function (err) {
            try { console.warn('[bulk_import_zip] progress widget load failed:', err); } catch (_e) {}
            showToast('Uploading ' + file.name + '…', 'info');
            return null;
        });

    /* XMLHttpRequest replaces fetch here SOLELY because fetch has no
       upload-progress event in any production browser as of 2026.
       xhr.upload.onprogress fires every ~50ms with byte counts. */
    var uploadXhr = new XMLHttpRequest();
    uploadXhr.open('POST', EDITOR_API_URL + '?action=bulk_import_zip', true);
    uploadXhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    uploadXhr.withCredentials = true;
    uploadXhr.upload.onprogress = function (ev) {
        if (!ev.lengthComputable) return;
        widgetReady.then(function (m) {
            if (m && typeof m.updateUploadProgress === 'function') {
                m.updateUploadProgress({ loaded: ev.loaded, total: ev.total });
            }
        });
    };
    /* Promise wrapper so the rest of the existing flow stays
       fetch-shaped. Resolves with `{ok, data}` like the prior code. */
    var uploadPromise = new Promise(function (resolve) {
        uploadXhr.onload = function () {
            var data;
            try { data = JSON.parse(uploadXhr.responseText); }
            catch (_e) { data = { error: 'Server returned an unparseable response (status ' + uploadXhr.status + ').' }; }
            resolve({ ok: uploadXhr.status >= 200 && uploadXhr.status < 300, data: data });
        };
        uploadXhr.onerror = function () {
            resolve({ ok: false, data: { error: 'Network error while uploading.' } });
        };
        uploadXhr.send(fd);
    });

    uploadPromise.then(function (out) {
        if (!out.ok || !out.data || out.data.ok !== true) {
            var msg = (out.data && out.data.error) || 'Import failed.';
            showToast('Import failed: ' + msg, 'danger');
            /* Tear down the upload-phase widget — server rejected the
               upload, no job to track. */
            widgetReady.then(function (m) {
                if (m && typeof m.dismiss === 'function') m.dismiss();
            });
            return;
        }
        var d = out.data;

        /* Async path (#676) — the server returned a job_id; transition
           the already-mounted widget from upload-tracking to job-
           tracking. The first poll fires immediately inside
           startTracking() so the curator sees server-side state
           within ~100ms of upload completion (#907). */
        if (d.async && d.job_id) {
            widgetReady.then(function (m) {
                if (!m) {
                    /* Widget failed to load — surface a fallback toast
                       and let the user refresh later to see results. */
                    showToast('Import is running in the background. Refresh the page in a minute to see results.', 'info');
                    return;
                }
                m.startTracking({
                    jobId:    d.job_id,
                    filename: file.name,
                    pollUrl:  d.poll_url
                            || ('/manage/editor/api?action=bulk_import_status&job_id=' + d.job_id),
                });
            });
            return;
        }

        /* Synchronous fallback (#664 contract — kept for deployments
           that haven't run migration card 3p). Surfaces the summary
           toast straight away. */
        var sb = (d.songbooks_created || []).length;
        var sx = (d.songbooks_existing || []).length;
        showToast(
            'Imported ' + d.songs_created + ' new song' + (d.songs_created === 1 ? '' : 's') +
            ' (' + d.songs_skipped_existing + ' already in DB, skipped). ' +
            sb + ' new songbook' + (sb === 1 ? '' : 's') + ', ' +
            sx + ' existing.',
            'success'
        );
        if (d.songs_failed > 0 || (d.errors && d.errors.length > 0)) {
            showToast(
                'Note: ' + d.songs_failed + ' file' + (d.songs_failed === 1 ? '' : 's') +
                ' failed during import — see browser console for details.',
                'warning'
            );
            try { console.warn('[bulk_import_zip] errors:', d.errors); } catch (_e) {}
        }
        if (typeof loadSongsFromURL === 'function' && typeof SONGS_URL_CANDIDATES !== 'undefined') {
            loadSongsFromURL(SONGS_URL_CANDIDATES[0]);
        }
    }).catch(function (err) {
        showToast('Import failed: ' + (err && err.message ? err.message : err), 'danger');
    });
}

/* ========================================================================
 *  SECTION 9 — Preview (#53)
 * ======================================================================== */

/**
 * renderPreview(song)
 * -------------------
 * Builds a read-only, formatted preview of the given song inside the
 * #preview-container element. Mimics the main app's layout:
 *   - Verses rendered flush-left
 *   - Choruses / refrains indented
 *   - A heading label for each component (e.g. "Verse 1", "Chorus")
 *
 * @param {Object} song - The song to preview.
 */
function renderPreview(song) {
    /* Grab the preview container. */
    var container = document.getElementById('preview-container');
    if (!container) return;

    /* Clear any previous preview content. */
    container.innerHTML = '';

    /* If no song is provided, show a placeholder. */
    if (!song) {
        container.innerHTML = '<p class="text-muted">Select a song to preview.</p>';
        return;
    }

    /* Song title heading. */
    var titleEl = document.createElement('h4');
    titleEl.className = 'mb-1';
    titleEl.textContent = song.title || 'Untitled';
    container.appendChild(titleEl);

    /* Subtitle line: number + songbook. */
    var subtitle = document.createElement('p');
    subtitle.className = 'text-muted small mb-3';
    subtitle.textContent = '#' + (song.number || '?') + ' \u2014 ' + (song.songbook || '');
    container.appendChild(subtitle);

    /* Horizontal rule to separate header from content. */
    container.appendChild(document.createElement('hr'));

    /* Determine render order: use arrangement if present, otherwise sequential (#161). */
    var components = song.components || [];
    var renderOrder;
    if (song.arrangement && Array.isArray(song.arrangement) && song.arrangement.length > 0) {
        renderOrder = song.arrangement
            .map(function (idx) { return components[idx] || null; })
            .filter(function (c) { return c !== null; });
    } else {
        renderOrder = components;
    }

    /* Render each component in the determined order. */
    renderOrder.forEach(function (comp) {
        /* Component label, e.g. "Verse 1" or "Chorus". Refrain → Chorus alias. */
        var heading = document.createElement('h6');
        heading.className = 'mt-3 mb-1 fw-bold text-uppercase small';
        /* Use the shared labelling helper so the Preview heading
           matches the Structure-tab card heading exactly (#491):
           no generic "Component N"; number is suppressed when
           unset or zero. */
        var previewComp = Object.assign({}, comp, {
            type: comp.type === 'refrain' ? 'chorus' : comp.type,
        });
        var headingText = componentHeaderLabel(previewComp);
        heading.textContent = headingText;

        /* Lyrics block. */
        var lyricsEl = document.createElement('pre');
        lyricsEl.className = 'mb-2';
        lyricsEl.style.whiteSpace = 'pre-wrap';
        lyricsEl.style.fontFamily = 'inherit';
        /* Join lines array for display (#244). */
        lyricsEl.textContent = Array.isArray(comp.lines) ? comp.lines.join('\n') : '';

        /* Indent choruses and refrains to visually distinguish them. */
        var isIndented = ['chorus', 'refrain'].indexOf(comp.type) !== -1;
        if (isIndented) {
            lyricsEl.style.paddingLeft = '2rem';
            heading.style.paddingLeft = '2rem';
        }

        /* Append both elements. */
        container.appendChild(heading);
        container.appendChild(lyricsEl);
    });

    /* Copyright / writers footer. */
    if (song.writers && song.writers.length > 0) {
        var writersEl = document.createElement('p');
        writersEl.className = 'text-muted small mt-3';
        /* "; " separator (#495); creditEntryAsString handles the
           post-#960 structured-parts shape. */
        writersEl.textContent = 'Writers: ' + song.writers.map(creditEntryAsString).join('; ');
        container.appendChild(writersEl);
    }
    if (song.copyright) {
        var copyrightEl = document.createElement('p');
        copyrightEl.className = 'text-muted small';
        copyrightEl.textContent = song.copyright;
        container.appendChild(copyrightEl);
    }
}

/* ========================================================================
 *  SECTION 10 — Status Bar
 * ======================================================================== */

/**
 * updateStatusBar()
 * -----------------
 * Refreshes the status bar at the bottom of the page with:
 *   - Total number of songs
 *   - Number of modified (unsaved) songs
 *   - Last save timestamp
 *   - Unsaved-changes warning
 */
function updateStatusBar() {
    /* Total songs count. */
    var totalEl = document.getElementById('status-total');
    if (totalEl) {
        totalEl.textContent = songData.songs.length;
    }

    /* Modified count. */
    var modifiedEl = document.getElementById('status-modified');
    if (modifiedEl) {
        modifiedEl.textContent = modifiedSongIds.size;
    }

    /* Last save time. */
    var saveTimeEl = document.getElementById('status-save-time');
    if (saveTimeEl) {
        saveTimeEl.textContent = lastSaveTime
            ? lastSaveTime.toLocaleTimeString()
            : 'never';
    }

    /* Unsaved changes warning — show or hide based on modification count. */
    var warningEl = document.getElementById('status-unsaved-warning');
    if (warningEl) {
        if (modifiedSongIds.size > 0) {
            warningEl.style.display = 'inline'; // show warning
            warningEl.textContent = 'Unsaved changes';
        } else {
            warningEl.style.display = 'none';   // hide warning
        }
    }

    /* Toolbar buttons (#590) — Save / Validate / History reflect the
       current selection + load state. Piggybacking on updateStatusBar
       ensures every meaningful state change (load, save, select,
       deselect, add, delete) refreshes the buttons too. */
    updateHistoryButtonState();
    updateSaveUiState();   /* #1180 — Save button enabled/label + status text */
}

/* ========================================================================
 *  SECTION 11 — Utility Helpers
 * ======================================================================== */

/**
 * findSongById(id)
 * ----------------
 * Searches songData.songs for a song with the given ID.
 *
 * @param {string} id - The song ID to search for.
 * @returns {Object|undefined} The matching song object, or undefined.
 */
/* O(1) id→song index (perf #1180-A1) — replaces the O(N) linear scan that ran
   on every interaction over the ~16k-song corpus (17 call sites). Rebuilt on
   load + bulk mutations, updated incrementally on add, and SELF-HEALING on any
   miss / stale id, so it can never return a wrong or ghost row. */
var songById = new Map();
function rebuildSongIndex() {
    songById = new Map();
    (songData.songs || []).forEach(function (s) {
        if (s && s.id != null) { songById.set(s.id, s); }
    });
}
function findSongById(id) {
    var hit = songById.get(id);
    if (hit !== undefined && hit.id === id) { return hit; }
    /* Map miss or a mutated/removed id — fall back to a scan + reindex. */
    var s = songData.songs.find(function (x) { return x.id === id; });
    if (s) { songById.set(id, s); }
    return s;
}

/* Shared debounce (#1180-A2/A3/A4) — coalesce burst events (keystrokes) into a
   single trailing call so the heavy re-renders don't fire on every key. */
function debounce(fn, ms) {
    var t = null;
    return function () {
        var ctx = this, args = arguments;
        if (t) { clearTimeout(t); }
        t = setTimeout(function () { t = null; fn.apply(ctx, args); }, ms);
    };
}
/* Debounced wrappers for the per-keystroke hot paths (renderPreview rebuilds the
   whole preview; updateStatusBar repaints the footer). Both are non-authoritative,
   so a short trailing delay is invisible to the user but removes O(components)
   work from every keypress. */
var schedulePreview   = debounce(function (song) { renderPreview(song); }, 350);
var scheduleStatusBar = debounce(function () { updateStatusBar(); }, 350);

/**
 * markModified(songId)
 * --------------------
 * Adds a song ID to the modified set and refreshes the status bar.
 *
 * @param {string} songId - The ID of the song that was modified.
 */
function markModified(songId) {
    /* Add the ID to the Set (duplicates are automatically ignored). */
    modifiedSongIds.add(songId);

    /* Enable the Save button + flip the status to "Unsaved changes" IMMEDIATELY
       (not debounced) so the editor feels responsive the instant you type (#1180). */
    updateSaveUiState();

    /* Refresh the rest of the status bar — debounced (#1180-A4) so it doesn't
       repaint on every keystroke. */
    scheduleStatusBar();

    /* Schedule a debounced auto-save (#394). */
    scheduleAutoSave();
}

/* ============================================================================
 *  AUTO-SAVE (#394)
 *  --------------------------------------------------------------------------
 *  Debounced save: 3 s after the last edit we run saveSongs() to persist the
 *  full songData via the existing server-side save endpoint. Manual Save is
 *  unchanged; this is purely a safety net so an admin can't lose work by
 *  forgetting to click the button.
 *
 *  TODO (follow-up): add a /api?action=save_song per-song endpoint so we're
 *  not rewriting every row on every edit. Tracked in #394.
 * ========================================================================== */

var _autoSaveTimer   = null;
var _autoSaveDelayMs = 3000;
var _autoSaveRunning = false;

/* #1178 — SINGLE serial save queue shared by BOTH the manual Save button and
   the auto-save timer. The corruption root cause was a race: clicking Save while
   an auto-save was in flight fired two concurrent save_song POSTs for the SAME
   song, whose server-side DELETE-then-INSERT transactions interleaved and left
   BOTH sets of child rows (duplicated components/credits). Routing every save
   through this promise chain guarantees they run strictly one-at-a-time, so each
   save's DELETE always clears the previous save's rows before its INSERT. */
var _saveQueue = Promise.resolve();
var _saveBusy  = 0;   /* >0 while any save (auto or manual) is queued or running */
function enqueueSave(ids) {
    /* Both fulfil + reject handlers re-arm the chain so one failed save can't
       wedge every subsequent save. */
    var run = function () { return autoSaveSongsPerSong(ids); };
    var next = _saveQueue.then(run, run);
    _saveQueue = next.catch(function () { /* swallow so the chain survives */ });
    /* Drive the Save-button + status-bar state off an in-flight count so the
       button disables while saving and the status text never sticks (#1180). */
    _saveBusy++;
    updateSaveUiState();
    next.then(function () {}, function () {}).then(function () {
        _saveBusy = Math.max(0, _saveBusy - 1);
        updateSaveUiState();
    });
    return next;
}

/**
 * updateSaveUiState()
 * -------------------
 * Single source of truth for the Save button + the status-bar text (#1180).
 * Auto-save is the primary persistence path, so the Save button is a manual
 * BACKUP — disabled while a save is in flight OR when there's nothing to save
 * (the owner's request). The status text reflects the real state instead of
 * getting stuck on "Auto-saving…".
 */
function updateSaveUiState() {
    var dirty  = modifiedSongIds.size > 0;
    var saving = _saveBusy > 0;
    var saveBtn = document.getElementById('btn-save');
    if (saveBtn) {
        saveBtn.disabled = saving || !dirty;
        var lbl = saveBtn.querySelector('.btn-save-label');
        if (lbl) { lbl.textContent = saving ? 'Saving…' : 'Save'; }
        saveBtn.title = saving ? 'Saving…'
                      : (dirty ? 'Save changes now (auto-save also runs)'
                               : 'All changes saved');
    }
    var statusEl = document.getElementById('status-text');
    if (statusEl) {
        statusEl.textContent = saving ? 'Auto-saving…'
                             : (dirty ? 'Unsaved changes' : 'All changes saved');
    }
}

function scheduleAutoSave() {
    /* Drop any pending save; each edit resets the timer. */
    if (_autoSaveTimer) clearTimeout(_autoSaveTimer);

    _autoSaveTimer = setTimeout(function () {
        _autoSaveTimer = null;
        if (_autoSaveRunning)      return;
        if (modifiedSongIds.size === 0) return;
        /* #961 — auto-save only blocks on hard errors; warnings are
           informational (and we don't surface them on auto-save to
           avoid toast spam when the user pauses on an in-progress
           edit). The user gets the warning on manual Save instead.
           Scoped to the modified set (WS-D #1016) — there is no whole
           corpus in memory to validate. */
        if (validateSongsByIds(Array.from(modifiedSongIds)).errors.length > 0) return;

        _autoSaveRunning = true;
        /* Status text is driven centrally by updateSaveUiState() via the
           _saveBusy counter (in enqueueSave) — no manual set here, so it can no
           longer get stuck on "Auto-saving…" after the save completes (#1180). */

        /* Per-song endpoint (#394). Walk the modified set and POST each
           song individually to /api?action=save_song. Much cheaper than
           the full-corpus `save`, and writes one row to tblSongRevisions
           per actual edit (#400). Falls back to saveSongs() (full save)
           if the per-song endpoint returns a non-ok status. */
        var ids = Array.from(modifiedSongIds);
        enqueueSave(ids).then(function (summary) {
            if (summary.failed.length > 0) {
                /* Something wasn't UPSERT-friendly — fall back to full save so
                   the user never ends up stuck with un-persistable diffs. */
                saveSongs();
            } else {
                lastSaveTime = new Date();
                summary.saved.forEach(function (id) { modifiedSongIds.delete(id); });
                renderSongList();
                updateStatusBar();
            }
        }).finally(function () {
            _autoSaveRunning = false;
        });
    }, _autoSaveDelayMs);
}

/* #960 — Pre-save serialiser. The Credits-tab chip lists store
   each credit as a structured-parts object {first, surname,
   suffix}; the save_song endpoint accepts either a bare string
   (legacy) or {name, first, surname, suffix} (post-#960). We
   send the richer shape so the server can populate
   tblCreditPeople.FirstNames/Surname/Suffix on auto-promote.
   Non-credit fields pass through untouched. */
function serialiseSongForSave(song) {
    var creditKeys = ['writers', 'composers', 'arrangers', 'adaptors', 'translators', 'artists'];
    var out = {};
    Object.keys(song || {}).forEach(function (k) { out[k] = song[k]; });

    /* #833 — for the currently-open song, prefer the live DOM state of
       the External Links editor over the stale `song.links` value. The
       'input' listener syncs them eagerly on every keystroke, but
       capturing here too is cheap and protects against any race where
       a save fires before the listener has flushed. Off-screen songs
       send whatever was already in their `.links`. Keyed off the RENDERED
       song (WS-D #1016), not currentSongId, so we only ever read live DOM
       for the song actually displayed — never capture it into a different
       song that happens to be mid-load. */
    if (typeof _renderedSongId !== 'undefined' && song && song.id === _renderedSongId) {
        try {
            out.externalLinks = captureSongExternalLinksFromDom();
        } catch (_e) {
            out.externalLinks = Array.isArray(song.links) ? song.links : [];
        }
    } else {
        out.externalLinks = Array.isArray(song.links) ? song.links.map(function (l) {
            return {
                typeId:   Number(l.typeId || l.type_id || 0),
                url:      String(l.url    || ''),
                note:     String(l.note   || ''),
                verified: !!(l.verified),
            };
        }) : [];
    }

    creditKeys.forEach(function (k) {
        if (!Array.isArray(out[k])) return;
        out[k] = out[k].map(function (entry) {
            if (typeof entry === 'string') {
                var parts = decomposePersonNameJs(entry);
                return {
                    name:    entry.trim(),
                    first:   parts.first,
                    surname: parts.surname,
                    suffix:  parts.suffix,
                };
            }
            var p = normaliseCreditParts(entry);
            return {
                name:    composePersonNameJs(p.first, p.surname, p.suffix),
                first:   p.first,
                surname: p.surname,
                suffix:  p.suffix,
            };
        }).filter(function (entry) {
            /* Drop completely-empty credit rows (no name parts
               typed) — keeps the existing "skip blank chip"
               behaviour from the legacy string path. */
            return entry.name !== '';
        });
    });
    return out;
}

/**
 * autoSaveSongsPerSong(ids)
 * -------------------------
 * POST each song in `ids` to the v2 editor API (?action=save_song)
 * sequentially. Returns a summary { saved: [ids], failed: [{id, error}] }.
 *
 * #1200 — the whole-song save was relocated from the legacy
 * /manage/editor/api.php to the v2 /manage/editor/api2.php. Both endpoints
 * call the SAME shared editorSaveSongCore() (no forked save logic), so the
 * wire request/response is identical; the only client change is the base URL
 * and the v2 same-origin CSRF defence (the X-Requested-With header the v2 API
 * requires on every POST). serialiseSongForSave() output is unchanged.
 *
 * Sequential (not Promise.all) so we stay polite to the DB and can
 * short-circuit on the first persistent error.
 */
function autoSaveSongsPerSong(ids) {
    var saved  = [];
    var failed = [];
    var chain  = Promise.resolve();

    ids.forEach(function (id) {
        chain = chain.then(function () {
            var song = (songData.songs || []).find(function (s) { return s.id === id; });
            if (!song) { failed.push({ id: id, error: 'not found locally' }); return; }
            return fetch((window.IHYMNS_EDITOR_API2 || '/manage/editor/api2') + '?action=save_song', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(serialiseSongForSave(song)),
            }).then(function (res) {
                return res.json().then(function (data) {
                    if (res.ok && data.ok) {
                        /* #1380 — the server may have promoted a client draft id
                           (song-XXXX) to a canonical <Abbr>-NNNN id on this first
                           save. Relabel the in-memory copy so subsequent saves /
                           navigation use the real id and the sidebar shows it,
                           WITHOUT a full reload. assignedId/previousId are present
                           only when a rename happened. */
                        if (data.assignedId && data.previousId) {
                            var renamed = (songData.songs || []).find(function (s) {
                                return s.id === data.previousId;
                            });
                            if (renamed) { renamed.id = data.assignedId; }
                            if (currentSongId === data.previousId) {
                                currentSongId = data.assignedId;
                            }
                            /* #1380 FIX 2 — re-key the two OTHER module-scope refs that
                               still point at the now-dead draft id, or they silently rot:
                               (a) modifiedSongIds — if the draft id is still in the dirty
                                   set, delete it and add the canonical id. Otherwise the
                                   draft id is NEVER cleared (the save loop only deletes
                                   ids it was asked to save), so the song shows a stuck
                                   "modified" badge AND every later autosave that looks up
                                   the draft id finds nothing → falls back to a full-corpus
                                   save. */
                            if (modifiedSongIds.has(data.previousId)) {
                                modifiedSongIds.delete(data.previousId);
                                modifiedSongIds.add(data.assignedId);
                            }
                            /* (b) _renderedSongId — the id serialiseSongForSave /
                               syncSongExternalLinksFromDom resolve against. If the
                               just-renamed song is the one on screen and this still
                               holds the draft id, a subsequent external-link DOM edit
                               would mismatch and be dropped. Re-point it at the
                               canonical id. */
                            if (_renderedSongId === data.previousId) {
                                _renderedSongId = data.assignedId;
                            }
                            renderSongList();
                            saved.push(data.assignedId);
                        } else {
                            saved.push(id);
                        }
                    } else {
                        /* Compose a maximally-useful error string. The
                           API ships error_detail (and optionally
                           mysqli_code / error_class) for admin /
                           global_admin users — surface those inline so
                           the failure self-diagnoses without a server-
                           shell trip. (#759) */
                        var msg = data.error || ('HTTP ' + res.status);
                        if (data.error_detail) {
                            msg += ' — ' + data.error_detail;
                            if (data.mysqli_code) {
                                msg += ' [mysqli ' + data.mysqli_code + ']';
                            }
                        }
                        failed.push({ id: id, error: msg });
                    }
                });
            }).catch(function (err) {
                failed.push({ id: id, error: err.message });
            });
        });
    });

    return chain.then(function () { return { saved: saved, failed: failed }; });
}

/**
 * setVal(elementId, value)
 * ------------------------
 * Safely sets the .value property of a form element by its ID.
 *
 * @param {string} elementId - The DOM ID of the element.
 * @param {*}      value     - The value to assign.
 */
function setVal(elementId, value) {
    var el = document.getElementById(elementId);
    if (el) {
        el.value = value;
    }
}

/**
 * setChecked()
 * ------------
 * Sets the checked state of a checkbox element by its DOM ID.
 *
 * @param {string}  elementId - The ID of the checkbox element.
 * @param {boolean} checked   - Whether the box should be checked.
 */
function setChecked(elementId, checked) {
    var el = document.getElementById(elementId);
    if (el) {
        el.checked = !!checked;
    }
}

/**
 * getVal(elementId)
 * -----------------
 * Returns the value of a form element by ID, or empty string if not found.
 */
function getVal(elementId) {
    var el = document.getElementById(elementId);
    return el ? el.value : '';
}

/**
 * updateCopyrightFieldState({ song, fromCheckboxClick })
 * ------------------------------------------------------
 * Mirror the "fully Public Domain → no copyright" rule on the
 * Credits tab's Copyright textarea (#594):
 *
 *   - When BOTH "Lyrics — Public Domain" and "Music — Public Domain"
 *     are ticked, the Copyright textarea is disabled and shows a
 *     "Public Domain — no copyright statement applies." placeholder.
 *
 *   - If the textarea had content at the moment the second box was
 *     ticked (i.e. the user just toggled into the fully-PD state via
 *     a checkbox click), confirm before clearing the existing text —
 *     prevents accidentally losing a manually-typed statement.
 *
 *   - Untick either box → field re-enables empty. Prior content is
 *     not restored (it was deliberately cleared).
 *
 * Called from selectSong() (initial render) and from the PD checkbox
 * change handlers. Idempotent and safe to call when no song is
 * selected.
 */
function updateCopyrightFieldState(opts) {
    var song = (opts && opts.song) || (currentSongId ? findSongById(currentSongId) : null);
    if (!song) return;

    var lyricsPdEl = document.getElementById('edit-lyricsPublicDomain');
    var musicPdEl  = document.getElementById('edit-musicPublicDomain');
    var copyEl     = document.getElementById('edit-copyright');
    if (!copyEl) return;

    var lyricsPd = !!(lyricsPdEl && lyricsPdEl.checked);
    var musicPd  = !!(musicPdEl  && musicPdEl.checked);
    var fullyPd  = lyricsPd && musicPd;

    if (fullyPd) {
        var hasText = (copyEl.value || '').trim() !== '';
        if (hasText && opts && opts.fromCheckboxClick) {
            /* User just ticked the second PD box on a song that already
               carries a copyright string. Confirm before wiping. */
            var preview = copyEl.value.length > 60
                ? copyEl.value.slice(0, 57) + '…'
                : copyEl.value;
            var ok = window.confirm(
                'Both Public-Domain flags are now ticked.\n\n' +
                'Existing copyright text will be cleared:\n  "' + preview + '"\n\n' +
                'Continue?'
            );
            if (!ok) {
                /* User backed out — un-tick the most recently changed box.
                   We don't know which one fired the change handler, so undo
                   the smaller-priority assumption: if music PD was just
                   ticked, untick it; otherwise untick lyrics. Either way the
                   data stays consistent. */
                if (musicPdEl && musicPdEl.checked) {
                    musicPdEl.checked = false;
                    song.musicPublicDomain = false;
                } else if (lyricsPdEl && lyricsPdEl.checked) {
                    lyricsPdEl.checked = false;
                    song.lyricsPublicDomain = false;
                }
                markModified(song.id);
                return;
            }
            copyEl.value = '';
            song.copyright = '';
            markModified(song.id);
        }
        copyEl.disabled = true;
        copyEl.placeholder = 'Public Domain — no copyright statement applies.';
    } else {
        copyEl.disabled = false;
        /* Restore the original placeholder set in the markup. */
        copyEl.placeholder = 'e.g. Copyright 2024 Hillsong Music Publishing';
    }
}

/**
 * toTitleCase(str)
 * ----------------
 * Converts a string to Title Case. Common small words (a, an, the, and,
 * but, or, for, nor, in, on, at, to, by, of, with) stay lowercase unless
 * they are the first or last word.
 *
 * Skips leading non-letter characters before capitalising — so titles
 * starting with an apostrophe-prefix abbreviation ('Twas, 'Tis, 'Mid)
 * capitalise the first *letter* rather than the apostrophe (#596).
 * Mirrors the behaviour of the shared js/utils/text.js helper and the
 * PHP toTitleCase() in includes/SongData.php so the editor produces
 * the same output as the public song view.
 *
 * @param {string} str - The input string.
 * @returns {string} The title-cased string.
 */
function toTitleCase(str) {
    var smallWords = /^(a|an|the|and|but|or|for|nor|in|on|at|to|by|of|with)$/i;
    var words = str.replace(/\s+/g, ' ').trim().split(' ');

    /* Capitalise the first Unicode letter in a word, skipping any
       leading quotes or punctuation (e.g. 'twas → 'Twas, "come →
       "Come). \p{L} matches any Unicode letter. */
    function capFirstLetter(word) {
        var lower = word.toLowerCase();
        var m = lower.match(/^([^\p{L}]*)(\p{L})(.*)$/u);
        return m ? m[1] + m[2].toUpperCase() + m[3] : lower;
    }

    return words.map(function (word, i, arr) {
        /* Always capitalise first and last word; minor words use the
           same word-wide check (which includes their leading punct
           via the regex below). */
        var lower = word.toLowerCase();
        var bareWord = lower.replace(/^[^\p{L}]+/u, '');
        if (i === 0 || i === arr.length - 1 || !smallWords.test(bareWord)) {
            return capFirstLetter(word);
        }
        return lower;
    }).join(' ');
}

/**
 * clearEditForm()
 * ---------------
 * Resets all edit form fields to empty / default values.
 * Called when no song is selected or data is freshly loaded.
 */
function clearEditForm() {
    /* No song rendered any more — clear the displayed-song tracker so a
       stray external-links flush can't target a now-blank form (WS-D #1016). */
    _renderedSongId = null;

    /* Hide the editor form, show the empty state (#246). */
    var editorEmpty = document.getElementById('editorEmpty');
    var editorForm = document.getElementById('editorForm');
    if (editorEmpty) editorEmpty.style.display = '';
    if (editorForm) editorForm.style.display = 'none';

    /* Clear metadata fields. */
    setVal('edit-title', '');
    setVal('edit-number', '');
    setVal('edit-songbook', '');
    /* Reset the Song Number `*` indicator now that no songbook is
       selected (#392). */
    updateNumberLabelRequiredness('');
    setVal('edit-ccli', '');
    setVal('edit-iswc', '');           /* #497 */
    setVal('edit-tune-name', '');      /* #497 */
    setVal('edit-origin-city', '');
    setVal('edit-origin-city-id', '');
    /* Reset the IETF picker to a blank state (#687). The shared module
       resolves "" → empty inputs and a "—" preview. */
    if (window.editSongIetfPicker && typeof window.editSongIetfPicker.setTag === 'function') {
        window.editSongIetfPicker.setTag('');
    }
    setVal('edit-copyright', '');

    /* Clear boolean checkboxes (#222, #225). */
    setChecked('edit-verified', false);
    setChecked('edit-lyricsPublicDomain', false);
    setChecked('edit-musicPublicDomain', false);

    /* Clear components container. */
    var compContainer = document.getElementById('componentList');
    if (compContainer) compContainer.innerHTML = '';

    /* Clear arrangement editor (#161). */
    renderArrangement(null);

    /* Clear writers container. */
    var writersContainer = document.getElementById('writers-container');
    if (writersContainer) writersContainer.innerHTML = '';

    /* Clear composers container. */
    var composersContainer = document.getElementById('composers-container');
    if (composersContainer) composersContainer.innerHTML = '';

    /* Clear preview. */
    renderPreview(null);
}

/**
 * downloadBlob(content, filename, mimeType)
 * ------------------------------------------
 * Generic helper that triggers a browser download for any string content.
 *
 * @param {string} content  - The file body.
 * @param {string} filename - The suggested download filename.
 * @param {string} mimeType - The MIME type (e.g. 'text/csv').
 */
function downloadBlob(content, filename, mimeType) {
    /* Create a Blob from the content string. */
    var blob = new Blob([content], { type: mimeType });

    /* Create a temporary object URL. */
    var url = URL.createObjectURL(blob);

    /* Build and click a hidden anchor. */
    var a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);

    /* Release the object URL. */
    URL.revokeObjectURL(url);
}

/**
 * showToast(message, type)
 * ------------------------
 * Displays a Bootstrap 5 toast notification. Requires a #toast-container
 * element in the page. Falls back to a basic alert if the container or
 * Bootstrap's Toast class is unavailable.
 *
 * @param {string} message - The text to display.
 * @param {string} [type]  - Bootstrap colour class: 'success', 'danger', 'warning', 'info'.
 */
function showToast(message, type) {
    /* Default type to 'info' if not specified. */
    type = type || 'info';

    /* Attempt to find the toast container. */
    var container = document.getElementById('toast-container');

    /* If the container doesn't exist, fall back to console + alert for errors. */
    if (!container || typeof bootstrap === 'undefined' || !bootstrap.Toast) {
        console.log('[Toast ' + type + '] ' + message);
        if (type === 'danger') {
            alert(message); // ensure critical errors are visible
        }
        return;
    }

    /* Build the toast HTML structure. */
    var toastEl = document.createElement('div');
    toastEl.className = 'toast align-items-center text-bg-' + type + ' border-0';
    toastEl.setAttribute('role', 'alert');
    toastEl.setAttribute('aria-live', 'assertive');
    toastEl.setAttribute('aria-atomic', 'true');

    /* Inner layout: message + close button. */
    var inner = document.createElement('div');
    inner.className = 'd-flex';

    var body = document.createElement('div');
    body.className = 'toast-body';
    body.textContent = message;

    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'btn-close btn-close-white me-2 m-auto';
    closeBtn.setAttribute('data-bs-dismiss', 'toast');
    closeBtn.setAttribute('aria-label', 'Close');

    /* Assemble. */
    inner.appendChild(body);
    inner.appendChild(closeBtn);
    toastEl.appendChild(inner);

    /* Append to the container. */
    container.appendChild(toastEl);

    /* Instantiate and show using Bootstrap's Toast API. */
    var bsToast = new bootstrap.Toast(toastEl, { delay: 4000 });
    bsToast.show();

    /* Remove the DOM node after it hides to prevent memory leaks. */
    toastEl.addEventListener('hidden.bs.toast', function () {
        toastEl.remove();
    });
}

/**
 * addNewSong()
 * ------------
 * Creates a brand-new song object with a generated UUID, adds it to
 * songData.songs, and selects it in the editor.
 */
function addNewSong() {
    /* Generate a unique draft ID that fits tblSongs.SongId (VARCHAR(20)) (#1180).
       The old 'song-' + Date.now() + '-' + 6-random form was ~25 chars, so MySQL
       SILENTLY TRUNCATED it on save — dropping most of the random suffix, risking
       UNIQUE-key collisions (a clash → ON DUPLICATE KEY UPDATE could overwrite a
       different draft) and producing the ugly ids the owner spotted
       (song-1780869822259-d). base36 timestamp (~8 chars) + 4 random fits with
       room; the 'song-' prefix is preserved (it's how unsaved drafts are
       detected). #1380 — the server NOW assigns a canonical <Abbr>-NNNN SongId on
       the first save (save_song_core.php) and returns assignedId/previousId;
       autoSaveSongsPerSong() relabels this in-memory draft to the canonical id
       on success, so the ugly 'song-XXXX' id is transient (one save) not permanent. */
    var newId = ('song-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6)).slice(0, 20);

    /* Build the blank song object with all required fields. The
       Song Number starts as null rather than (songs.length + 1) —
       the curator may be adding a song to an unofficial / Misc
       songbook where there's no per-songbook number, or simply
       hasn't picked a songbook yet. selectSong() coerces null →
       empty string when populating the input, so the placeholder
       'e.g. 42' shows in greyed form and the curator has to enter
       a real value (which the validator then enforces only when
       the chosen songbook is IsOfficial=1, per #392 / PR #740). */
    /* Default a new draft's songbook to the generic "Misc" collection (when it
       exists) so auto-save works IMMEDIATELY — otherwise a draft with no songbook
       silently fails to save (the SongbookAbbr is mandatory) and the curator loses
       their edits believing auto-save worked. The server already coerces a blank
       songbook to Misc on save; mirroring it here means the dropdown shows Misc,
       BOTH the 3-second auto-save and the Save-button validator pass, and the
       curator can switch to the real songbook at any time (the next save just
       reassigns SongbookAbbr). */
    var _miscBook = (songData.songbooks || []).find(function (b) {
        return b && typeof b.id === 'string' && b.id.toLowerCase() === 'misc';
    });
    var newSong = {
        id: newId,
        title: 'New Song',
        number: null,
        songbook: _miscBook ? _miscBook.id : '',
        songbookName: _miscBook ? (_miscBook.name || _miscBook.id) : '',
        language: 'en',
        ccli: '',
        iswc: '',           /* #497 */
        tuneName: '',       /* #497 */
        originCity: '',
        originCityId: null,
        copyright: '',
        verified: false,
        lyricsPublicDomain: false,
        musicPublicDomain: false,
        hasAudio: false,
        hasSheetMusic: false,
        writers: [],
        composers: [],
        arrangers: [],      /* #497 */
        adaptors: [],       /* #497 */
        translators: [],    /* #497 */
        components: [
            {
                type: 'verse',
                number: 1,
                lines: ['']
            }
        ],
        /* WS-D #1016 — a new draft is fully in memory; mark it so
           selectSong() doesn't try to fetch (and overwrite) it. */
        _full: true
    };

    /* Append to the songs array. */
    songData.songs.push(newSong);
    songById.set(newSong.id, newSong);   /* keep the O(1) id index in sync (#1180-A1) */

    /* Mark the new song as modified (it hasn't been saved yet). */
    markModified(newId);

    /* Refresh the songbook dropdown in case it changed. */
    populateSongbookFilterDropdown();

    /* Re-render the sidebar and select the new song. */
    renderSongList();
    selectSong(newId);

    /* Notify the user. */
    showToast('New song created.', 'info');
}

/**
 * deleteSong()
 * ------------
 * Removes the currently selected song from songData.songs after confirmation.
 */
function deleteSong() {
    /* Guard: must have a song selected. */
    if (!currentSongId) {
        showToast('No song selected.', 'warning');
        return;
    }

    /* Find the song to show its title in the confirmation dialog. */
    var song  = findSongById(currentSongId);
    var title = song ? song.title : currentSongId;

    /* Ask for confirmation. */
    if (!confirm('Delete "' + title + '"?\n\nThis permanently removes the song and ALL its data (components, lyric lines, credits, links, media) from the database. This cannot be undone.')) {
        return;
    }

    var idToDelete = currentSongId;

    /* #1343 — keep the shared permalink alive. Offer to redirect the deleted
       song's old link to another song; blank/Cancel leaves a friendly "removed"
       page (the server validates the target and tombstones a blank/unknown id).
       For a genuine duplicate, Merge on Duplicate & Counterpart Review is better —
       it auto-redirects without this prompt. */
    var redirectTo = (window.prompt(
        'Optional: keep this song’s shared link working by redirecting it to another song.\n\n' +
        'Enter the Song ID to redirect to (e.g. MP-0001), or leave blank for a “removed” page.',
        ''
    ) || '').trim();

    /* SERVER-BACKED delete (#1200 / #1290). The legacy implementation only
       filtered the in-memory songData.songs array and toasted "deleted" — the
       DB row was never touched, so the song reappeared on reload (and still
       loaded in Song View). We now POST delete_song to the v2 API (cascade
       delete + CSRF) and ONLY mutate local state + toast once the server
       confirms. On failure nothing is removed locally and the error is shown,
       so the toast can never lie again. */
    var btn = document.getElementById('btn-delete-song');
    if (btn) { btn.disabled = true; }

    ed2EnrichApi('delete_song', { songId: idToDelete, redirectTo: redirectTo })
        .then(function (res) {
            /* Server confirmed the cascade delete — sync local state now. */
            songData.songs = songData.songs.filter(function (s) { return s.id !== idToDelete; });
            rebuildSongIndex();           /* keep the O(1) id index in sync (#1180-A1) */
            modifiedSongIds.delete(idToDelete);
            if (currentSongId === idToDelete) {
                currentSongId = null;
                clearEditForm();
            }
            renderSongList();
            updateStatusBar();
            var n = (res && typeof res.deleted === 'number') ? res.deleted : null;
            showToast(
                'Deleted "' + title + '" from the database'
                    + (n !== null ? ' (' + n + ' row' + (n === 1 ? '' : 's') + ' removed).' : '.'),
                'success'
            );
        })
        .catch(function (err) {
            showToast(
                'Delete failed: ' + (err && err.message ? err.message : 'unknown error')
                    + '. The song was NOT removed.',
                'danger'
            );
        })
        .finally(function () {
            if (btn) { btn.disabled = false; }
        });
}

/* ========================================================================
 *  SECTION 12 — Event Binding & Initialisation
 * ======================================================================== */

/**
 * bindGlobalEventListeners()
 * --------------------------
 * Attaches click/change/input handlers to all toolbar buttons and sidebar
 * controls. Called once on DOMContentLoaded.
 */
function bindGlobalEventListeners() {
    /* ---- Sidebar search input ---- */
    var searchEl = document.getElementById('song-search');
    if (searchEl) {
        /* Re-render the song list as the user types — debounced (#1180-A2) so a
           burst of keystrokes runs ONE sort+filter pass over the ~16k-song
           corpus instead of one per key. */
        searchEl.addEventListener('input', debounce(function () {
            renderSongList();
        }, 200));
    }

    /* ---- Sort order dropdown (#251) ---- */
    var sortEl = document.getElementById('song-sort');
    if (sortEl) {
        sortEl.addEventListener('change', function () {
            currentSortMode = sortEl.value;
            renderSongList();
        });
    }

    /* ---- Songbook filter dropdown ---- */
    var filterEl = document.getElementById('songbook-filter');
    if (filterEl) {
        filterEl.addEventListener('change', function () {
            renderSongList();
        });
    }

    /* Load JSON / Load URL click handlers removed alongside the buttons
       in #589. The editor auto-loads from MySQL via `?action=load` on
       init (see loadSongsFromURL(DEFAULT_SONGS_URL) at the bottom of
       this section), so a curator never needs to load from a file or
       remote URL. The Import button below still uses importJSON() for
       merging external data when an admin really needs to. */

    /* ---- Save button — writes to MySQL, falls back to JSON download ---- */
    var saveBtn = document.getElementById('btn-save');
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            saveSongs();
        });
    }

    /* ---- Import button (server-side bulk import: .zip / VideoPsalm) ---- */
    var importBtn = document.getElementById('btn-import');
    if (importBtn) {
        importBtn.addEventListener('click', function () {
            importJSON();
        });
    }

    /* ---- Add New Song button ---- */
    var addSongBtn = document.getElementById('btn-add-song');
    if (addSongBtn) {
        addSongBtn.addEventListener('click', function () {
            addNewSong();
        });
    }

    /* ---- Delete Song button ---- */
    var deleteSongBtn = document.getElementById('btn-delete-song');
    if (deleteSongBtn) {
        deleteSongBtn.addEventListener('click', function () {
            deleteSong();
        });
    }

    /* ---- Export Song button — exports the currently-open song using the
       shared filename convention (#883 / export-filename.js). Stays
       disabled until a song is actually loaded; loadSongIntoEditor() flips
       its disabled state as part of the existing select-song flow. */
    var exportSongBtn = document.getElementById('btn-export-song');
    if (exportSongBtn) {
        exportSongBtn.addEventListener('click', function (e) {
            /* Now a dropdown <a href="#"> inside the consolidated Export menu —
               stop the hash navigation (#1166 polish). */
            if (e && e.preventDefault) { e.preventDefault(); }
            exportCurrentSong();
        });
    }

    /* ---- Add Component button ---- */
    var addCompBtn = document.getElementById('btn-add-component');
    if (addCompBtn) {
        addCompBtn.addEventListener('click', function () {
            addComponent();
        });
    }

    /* ---- Paste & Reflow modal controls (#1043) ---- */
    initReflowControls();

    /* ---- Bind metadata form field live-sync listeners ---- */
    bindMetadataListeners();
}

/* ========================================================================
 *  SECTION — Paste & Reflow (ProPresenter-style bulk section entry) (#1043)
 *
 *  Lets a curator paste a WHOLE lyrics block and split it into song
 *  components in one pass, instead of adding Verse 1 / Chorus / … one card
 *  at a time. Pure client-side text processing; "Apply" appends real
 *  components ({type, number, lines}) onto the current song and re-renders
 *  through the normal renderComponents() / save_song path — no new endpoint.
 *  Inspired by ProPresenter's Reflow editor.
 *  https://support.renewedvision.com/hc/en-us/articles/34513457866899
 * ======================================================================== */

/* Working set of parsed sections while the modal is open. */
var REFLOW_BLOCKS = [];

/* A line that is ONLY a section label — e.g. "Chorus", "Verse 2",
 * "Pre-Chorus:", "Bridge 1." — optionally a number and a trailing
 * separator. Never matches a real lyric line (which has more words). */
var REFLOW_LABEL_RE = /^[ \t]*(verses?|chorus(?:es)?|refrains?|bridges?|pre[ \t-]?chorus|tags?|coda|intro|outro|interlude|vamp|ad[ \t-]?lib)\.?[ \t]*(\d+)?[ \t]*[:.)\-–]?[ \t]*$/i;

/* Map a detected label word to a canonical COMPONENT_TYPES value (the type
 * dropdown only offers those, so anything else falls back sensibly). */
function reflowNormalizeType(raw) {
    var t = String(raw || '').toLowerCase().trim().replace(/[ \t]+/g, '-');
    if (t === 'verses') { t = 'verse'; }
    if (t === 'choruses') { t = 'chorus'; }
    if (t === 'bridges') { t = 'bridge'; }
    if (t === 'tags') { t = 'tag'; }
    if (t === 'prechorus') { t = 'pre-chorus'; }
    if (t === 'adlib') { t = 'ad-lib'; }
    if (t === 'refrain' || t === 'refrains') { t = 'chorus'; } /* no distinct 'refrain' component type */
    return (COMPONENT_TYPES.indexOf(t) !== -1) ? t : 'verse';
}

/* Whitespace-normalised text key, for detecting repeated (chorus) blocks. */
function reflowBlockKey(block) {
    return block.lines.join('\n').toLowerCase().replace(/\s+/g, ' ').trim();
}

/* Split raw pasted text into classified section blocks. */
function reflowParseText(rawText) {
    var text = String(rawText || '').replace(/\r\n?/g, '\n');
    /* One or more blank lines separate sections. */
    var chunks = text.split(/\n[ \t]*\n+/);
    var blocks = [];
    chunks.forEach(function (chunk) {
        var lines = chunk.split('\n');
        while (lines.length && lines[0].trim() === '') { lines.shift(); }
        while (lines.length && lines[lines.length - 1].trim() === '') { lines.pop(); }
        if (!lines.length) { return; }

        var type = null;
        var number = null;
        var m = lines[0].match(REFLOW_LABEL_RE);
        /* Only treat the first line as a label when there's a lyric body after
           it — a one-line block that merely looks like a label is kept as
           lyrics, not silently dropped. */
        if (m && lines.length > 1) {
            type = reflowNormalizeType(m[1]);
            number = m[2] ? parseInt(m[2], 10) : null;
            lines = lines.slice(1);
            while (lines.length && lines[0].trim() === '') { lines.shift(); }
        }
        blocks.push({ type: type, number: number, lines: lines });
    });
    reflowApplySmartDefaults(blocks);
    return blocks;
}

/* Fill in the types/numbers the paste didn't label explicitly. */
function reflowApplySmartDefaults(blocks) {
    /* Repeated identical blocks are almost always the chorus. */
    var counts = {};
    blocks.forEach(function (b) {
        var k = reflowBlockKey(b);
        counts[k] = (counts[k] || 0) + 1;
    });
    blocks.forEach(function (b) {
        if (!b.type && counts[reflowBlockKey(b)] > 1) { b.type = 'chorus'; }
    });
    /* Anything still unclassified is a verse. */
    blocks.forEach(function (b) { if (!b.type) { b.type = 'verse'; } });
    reflowAutoNumber(blocks);
}

/* Auto-number repeated types (Verse 1, Verse 2, …); single-instance types
 * stay unnumbered. Respects a number already set from an explicit label. */
function reflowAutoNumber(blocks) {
    var totals = {};
    blocks.forEach(function (b) { totals[b.type] = (totals[b.type] || 0) + 1; });
    var seen = {};
    blocks.forEach(function (b) {
        if (totals[b.type] > 1) {
            seen[b.type] = (seen[b.type] || 0) + 1;
            if (b.number == null) { b.number = seen[b.type]; }
        }
    });
}

/* Render the parsed sections as editable cards inside the modal. */
function reflowRender() {
    var container = document.getElementById('reflow-blocks');
    var applyBtn = document.getElementById('reflow-apply-btn');
    var countEl = document.getElementById('reflow-count');
    if (!container) { return; }
    container.innerHTML = '';

    if (!REFLOW_BLOCKS.length) {
        container.innerHTML = '<p class="text-muted small mb-0">Paste lyrics above and click '
                            + '<strong>Parse into sections</strong>.</p>';
        if (applyBtn) { applyBtn.disabled = true; }
        if (countEl) { countEl.textContent = ''; }
        return;
    }
    if (applyBtn) { applyBtn.disabled = false; }
    if (countEl) {
        countEl.textContent = REFLOW_BLOCKS.length + ' section'
                            + (REFLOW_BLOCKS.length === 1 ? '' : 's') + ' detected';
    }

    REFLOW_BLOCKS.forEach(function (block, index) {
        var card = document.createElement('div');
        card.className = 'card mb-2 reflow-block-card';
        card.style.backgroundColor = 'var(--ih-bg-card)';
        card.style.borderColor = 'var(--ih-border)';

        var header = document.createElement('div');
        header.className = 'card-header d-flex align-items-center gap-2 flex-wrap py-2';

        /* The label badge updates live as the type/number change. */
        var label = document.createElement('span');
        label.className = 'badge bg-secondary reflow-label';
        label.textContent = componentHeaderLabel(block);

        /* Type picker — reuses the canonical COMPONENT_TYPES list. */
        var typeSel = document.createElement('select');
        typeSel.className = 'form-select form-select-sm reflow-type';
        typeSel.style.width = 'auto';
        typeSel.setAttribute('aria-label', 'Section type');
        COMPONENT_TYPES.forEach(function (t) {
            var opt = document.createElement('option');
            opt.value = t;
            opt.textContent = t.charAt(0).toUpperCase() + t.slice(1);
            if (t === block.type) { opt.selected = true; }
            typeSel.appendChild(opt);
        });
        typeSel.addEventListener('change', function () {
            block.type = typeSel.value;
            label.textContent = componentHeaderLabel(block);
        });

        var numInput = document.createElement('input');
        numInput.type = 'number';
        numInput.min = '1';
        numInput.className = 'form-control form-control-sm reflow-number';
        numInput.style.width = '5rem';
        numInput.placeholder = '#';
        numInput.setAttribute('aria-label', 'Section number');
        numInput.value = (block.number != null && block.number > 0) ? block.number : '';
        numInput.addEventListener('input', function () {
            block.number = numInput.value ? parseInt(numInput.value, 10) : null;
            label.textContent = componentHeaderLabel(block);
        });

        var actions = document.createElement('div');
        actions.className = 'ms-auto d-flex gap-1';

        var mergeBtn = document.createElement('button');
        mergeBtn.type = 'button';
        mergeBtn.className = 'btn btn-sm btn-outline-secondary';
        mergeBtn.title = 'Merge into the previous section';
        mergeBtn.innerHTML = '<i class="bi bi-arrow-up-square" aria-hidden="true"></i>';
        mergeBtn.disabled = (index === 0);
        mergeBtn.addEventListener('click', function () { reflowMergeUp(index); });

        var bodyTextarea = document.createElement('textarea');

        var splitBtn = document.createElement('button');
        splitBtn.type = 'button';
        splitBtn.className = 'btn btn-sm btn-outline-secondary';
        splitBtn.title = 'Split this section at the cursor';
        splitBtn.innerHTML = '<i class="bi bi-scissors" aria-hidden="true"></i>';
        splitBtn.addEventListener('click', function () { reflowSplitAtCursor(index, bodyTextarea); });

        var delBtn = document.createElement('button');
        delBtn.type = 'button';
        delBtn.className = 'btn btn-sm btn-outline-danger';
        delBtn.title = 'Remove this section';
        delBtn.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
        delBtn.addEventListener('click', function () { reflowRemove(index); });

        actions.appendChild(mergeBtn);
        actions.appendChild(splitBtn);
        actions.appendChild(delBtn);

        header.appendChild(typeSel);
        header.appendChild(numInput);
        header.appendChild(label);
        header.appendChild(actions);

        var body = document.createElement('div');
        body.className = 'card-body py-2';
        bodyTextarea.className = 'form-control form-control-sm bg-dark text-light border-secondary reflow-text';
        bodyTextarea.rows = Math.min(Math.max(block.lines.length, 2), 12);
        bodyTextarea.value = block.lines.join('\n');
        bodyTextarea.setAttribute('aria-label', 'Section lyrics');
        bodyTextarea.addEventListener('input', function () {
            block.lines = bodyTextarea.value.split('\n');
        });
        body.appendChild(bodyTextarea);

        card.appendChild(header);
        card.appendChild(body);
        container.appendChild(card);
    });
}

/* Merge a section into the one before it (a blank line between). */
function reflowMergeUp(index) {
    if (index <= 0 || index >= REFLOW_BLOCKS.length) { return; }
    var prev = REFLOW_BLOCKS[index - 1];
    var cur = REFLOW_BLOCKS[index];
    prev.lines = prev.lines.concat(['']).concat(cur.lines);
    REFLOW_BLOCKS.splice(index, 1);
    reflowAutoNumber(REFLOW_BLOCKS);
    reflowRender();
}

/* Split a section at the textarea cursor (or, failing that, at its first
 * internal blank line). The new lower half inherits the type. */
function reflowSplitAtCursor(index, textarea) {
    var block = REFLOW_BLOCKS[index];
    var text = textarea ? textarea.value : block.lines.join('\n');
    var pos = textarea ? textarea.selectionStart : null;
    var before;
    var after;
    if (pos != null && pos > 0 && pos < text.length) {
        before = text.slice(0, pos);
        after = text.slice(pos);
    } else {
        var bi = text.indexOf('\n\n');
        if (bi === -1) { return; } /* nothing sensible to split on */
        before = text.slice(0, bi);
        after = text.slice(bi + 2);
    }
    var beforeLines = before.replace(/\s+$/, '').split('\n');
    var afterLines = after.replace(/^\s+/, '').split('\n');
    if (!beforeLines.join('').trim() || !afterLines.join('').trim()) { return; }
    block.lines = beforeLines;
    REFLOW_BLOCKS.splice(index + 1, 0, { type: block.type, number: null, lines: afterLines });
    reflowAutoNumber(REFLOW_BLOCKS);
    reflowRender();
}

/* Drop a section. */
function reflowRemove(index) {
    REFLOW_BLOCKS.splice(index, 1);
    reflowAutoNumber(REFLOW_BLOCKS);
    reflowRender();
}

/* Apply the parsed sections to the current song as real components. */
function reflowApply() {
    if (!currentSongId) { showToast('Select or create a song first.', 'warning'); return; }
    var song = findSongById(currentSongId);
    if (!song) { return; }
    if (!REFLOW_BLOCKS.length) { return; }
    if (!song.components) { song.components = []; }

    /* Drop pre-existing EMPTY components (e.g. the blank default verse a fresh
       song starts with) so reflow lands clean; keep any with real text. */
    song.components = song.components.filter(function (c) {
        return Array.isArray(c.lines) && c.lines.join('').trim() !== '';
    });

    var added = 0;
    REFLOW_BLOCKS.forEach(function (b) {
        var lines = b.lines.slice();
        while (lines.length > 1 && lines[lines.length - 1].trim() === '') { lines.pop(); }
        if (!lines.join('').trim()) { return; } /* skip an empty section */
        song.components.push({
            type: (COMPONENT_TYPES.indexOf(b.type) !== -1) ? b.type : 'verse',
            number: (b.number != null && b.number > 0) ? parseInt(b.number, 10) : null,
            lines: lines
        });
        added++;
    });

    markModified(song.id);
    renderComponents(song);
    renderArrangement(song);
    renderPreview(song);
    fitAllComponentTextareas();

    var modalEl = document.getElementById('reflow-modal');
    if (modalEl && window.bootstrap) {
        window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
    }
    /* Belt-and-suspenders (#1180): Bootstrap removes the .modal-backdrop on the
       modal's transitionend, which can be SKIPPED when the DOM is mutated
       mid-transition (the heavy renderComponents/renderPreview above) — leaving
       the page dimmed + every click blocked (the bug the owner hit applying
       reflow). This time-based sweep runs regardless of whether the transition
       fired, and only when no modal is genuinely still open. */
    _sweepOrphanModalBackdrop();
    REFLOW_BLOCKS = [];
    showToast('Added ' + added + ' section' + (added === 1 ? '' : 's') + ' from reflow.', 'success');
}

/* Remove any leftover Bootstrap modal backdrop + body lock once the close
   transition would have finished — guards against the "stuck dimmed page"
   state. No-op while a modal is legitimately open. (#1180) */
function _sweepOrphanModalBackdrop() {
    setTimeout(function () {
        if (document.querySelector('.modal.show')) { return; }
        document.querySelectorAll('.modal-backdrop').forEach(function (b) { b.remove(); });
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    }, 350);
}

/* Wire the modal's Parse / Apply controls + reset on open/close.
 * Called once from the editor's listener setup. */
function initReflowControls() {
    var parseBtn = document.getElementById('reflow-parse-btn');
    var applyBtn = document.getElementById('reflow-apply-btn');
    var input = document.getElementById('reflow-input');
    var modalEl = document.getElementById('reflow-modal');

    if (parseBtn && input) {
        parseBtn.addEventListener('click', function () {
            REFLOW_BLOCKS = reflowParseText(input.value);
            reflowRender();
            if (!REFLOW_BLOCKS.length) {
                showToast('No sections found — paste some lyrics first.', 'warning');
            }
        });
    }
    if (applyBtn) {
        applyBtn.addEventListener('click', reflowApply);
    }
    if (modalEl) {
        modalEl.addEventListener('shown.bs.modal', function () {
            if (input) { input.focus(); }
        });
        modalEl.addEventListener('hidden.bs.modal', function () {
            REFLOW_BLOCKS = [];
            if (input) { input.value = ''; }
            reflowRender();
        });
    }
    /* Global safety net (#1180): when ANY editor modal finishes hiding, sweep a
       leftover backdrop so a leaked overlay can never strand the page dimmed +
       unclickable. Idempotent + a no-op while a modal is still open. */
    document.addEventListener('hidden.bs.modal', function () { _sweepOrphanModalBackdrop(); });
}

/**
 * warnBeforeUnload(event)
 * -----------------------
 * Attached to the window 'beforeunload' event to warn the user if there
 * are unsaved changes when they try to navigate away or close the tab.
 *
 * @param {BeforeUnloadEvent} event
 */
function warnBeforeUnload(event) {
    /* Only warn if there are modifications. */
    if (modifiedSongIds.size > 0) {
        /* Standard approach: set returnValue and return a string. */
        event.preventDefault();
        event.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
        return event.returnValue;
    }
}

/**
 * init()
 * ------
 * Master initialisation function. Called on DOMContentLoaded.
 * Sets up event listeners, then attempts to auto-load songs.json from the
 * default relative URL.
 */
function init() {
    /* Bind all UI event listeners. */
    bindGlobalEventListeners();

    /* Bind arrangement editor listeners (#161). */
    bindArrangementListeners();

    /* Bind translation controls (#352). */
    initTranslationControls();

    /* Bind cross-book counterparts controls (#807). */
    initSongLinksControls();

    /* Structure tab is display:none until its Bootstrap tab is opened,
       so scrollHeight reads 0 on any component textarea fitted while
       the tab is hidden. Listen for shown.bs.tab and re-fit once the
       browser actually lays the panel out (#490). */
    var structureTab = document.getElementById('tab-structure');
    if (structureTab) {
        structureTab.addEventListener('shown.bs.tab', function () {
            fitAllComponentTextareas();
        });
    }

    /* Wire the Tags tab autocomplete input (#496). Idempotent. */
    bindTagSearchInput();

    /* Register the beforeunload handler for unsaved-changes protection. */
    window.addEventListener('beforeunload', warnBeforeUnload);

    /* Populate the songbook filter (will be empty until data loads). */
    populateSongbookFilterDropdown();

    /* Render an empty song list and status bar. */
    renderSongList();
    updateStatusBar();

    /* Clear the edit form to its default empty state. */
    clearEditForm();

    /* Auto-try loading the songs.json from the default relative URL. */
    loadSongsFromURL(DEFAULT_SONGS_URL).then(function () {
        /* After successful load, populate the songbook dropdown with real data. */
        populateSongbookFilterDropdown();

        /* Deep-link support.

           #407 — /manage/editor/?song=<SongId> opens directly on the
                   named song when it exists in songData. Used by the
                   Edit button on the public song view.

           Missing-Numbers prefill — /manage/editor/?songbook=<ABBR>
                   with either ?number=<N> or #number=<N> opens a
                   blank new-song draft pre-filled with the songbook
                   abbreviation, songbook display name, and the song
                   number. Used by the "Open in editor" button on
                   /manage/missing-numbers so the curator lands ready
                   to type lyrics instead of facing an empty list and
                   having to re-discover what to enter. */
        try {
            var qs   = new URLSearchParams(window.location.search);
            var hash = String(window.location.hash || '').replace(/^#/, '');
            var sid  = qs.get('song');
            if (sid && Array.isArray(songData.songs)
                && songData.songs.some(function (s) { return s.id === sid; })) {
                selectSong(sid);
                return;
            }
            var prefillBook = qs.get('songbook');
            /* Number can arrive either as a query param (?number=N) or
               as the legacy fragment (#number=N) the missing-numbers
               page emits. Both shapes work. */
            var prefillNum  = qs.get('number');
            if (!prefillNum && hash.indexOf('number=') === 0) {
                prefillNum = hash.slice('number='.length);
            }
            if (prefillBook && prefillNum && /^\d+$/.test(prefillNum)) {
                var prefillNumInt = parseInt(prefillNum, 10);
                /* If the (songbook, number) combo already exists in
                   songData, jump to that song instead of creating a
                   duplicate draft — protects against double-clicks
                   on the missing-numbers link and from a curator
                   navigating back to the editor with stale data. */
                var existing = (Array.isArray(songData.songs) ? songData.songs : []).find(function (s) {
                    return s.songbook === prefillBook
                        && parseInt(s.number, 10) === prefillNumInt;
                });
                if (existing) {
                    selectSong(existing.id);
                    return;
                }
                /* Resolve the songbook display name from songData.songbooks
                   so the new draft carries both abbreviation and full
                   name (the editor displays the full name in the
                   header chip; abbreviation drives the SongId). */
                var bookRow = (Array.isArray(songData.songbooks) ? songData.songbooks : []).find(function (b) {
                    return (b.id || b.abbreviation) === prefillBook;
                });
                var bookName = bookRow ? (bookRow.name || prefillBook) : prefillBook;

                /* Spawn a draft via the same addNewSong() entry point
                   the toolbar uses, then patch the songbook + number
                   fields. addNewSong() runs selectSong() internally,
                   so after the patches we mark the song modified +
                   re-render so the sidebar label shows the new number
                   instead of "New Song". */
                addNewSong();
                var draft = songData.songs[songData.songs.length - 1];
                if (draft) {
                    draft.songbook     = prefillBook;
                    draft.songbookName = bookName;
                    draft.number       = prefillNumInt;
                    /* Title stays "New Song" — the curator types the
                       real title themselves. Pre-filling a stub
                       title would obscure what still needs entering. */
                    if (typeof markModified === 'function') markModified(draft.id);
                    if (typeof renderSongList === 'function') renderSongList();
                    selectSong(draft.id);
                    if (typeof showToast === 'function') {
                        showToast('Pre-filled new song for ' + bookName + ' #' + prefillNumInt + ' — enter lyrics + metadata to save.', 'info');
                    }
                }
            }
        } catch (_e) { /* malformed URL — ignore */ }
    });
}

/* ============================================================================
 *  MULTI-SELECT MODE (#399)
 *  --------------------------------------------------------------------------
 *  Lightweight multi-select in the sidebar for bulk-delete. Not as rich as
 *  the originally-scoped "bulk tag / move / export" toolbar — the delete
 *  path alone covers the most-requested curator need; richer actions can
 *  be added later without the sidebar surgery needed for multi-select.
 * ========================================================================== */

function updateBulkActionsBar() {
    var bar = document.getElementById('bulk-actions-bar');
    var countEl = document.getElementById('bulk-selected-count');
    if (!bar || !countEl) return;
    var count = (window._selectedIds && window._selectedIds.size) || 0;
    countEl.textContent = count;
    /* All bulk-action buttons enable only when something is selected. */
    ['btn-bulk-delete', 'btn-bulk-verify', 'btn-bulk-tag',
     'btn-bulk-move', 'btn-bulk-export'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.disabled = count === 0;
    });
}

function toggleSelectMode(on) {
    window._selectMode = !!on;
    window._selectedIds = window._selectedIds || new Set();
    if (!on) window._selectedIds.clear();

    var bar    = document.getElementById('bulk-actions-bar');
    var toggle = document.getElementById('btn-select-mode');
    if (bar) bar.classList.toggle('d-none', !on);
    if (toggle) {
        toggle.setAttribute('aria-pressed', on ? 'true' : 'false');
        toggle.classList.toggle('btn-amber-solid', on);
        toggle.classList.toggle('btn-outline-secondary', !on);
    }
    renderSongList();
    updateBulkActionsBar();
}

function bindMultiSelectListeners() {
    var toggle = document.getElementById('btn-select-mode');
    if (toggle) toggle.addEventListener('click', function () {
        toggleSelectMode(!window._selectMode);
    });

    var all = document.getElementById('btn-bulk-select-all');
    if (all) all.addEventListener('click', function () {
        window._selectedIds = new Set(
            (songData.songs || []).map(function (s) { return s.id; })
        );
        renderSongList();
        updateBulkActionsBar();
    });

    var none = document.getElementById('btn-bulk-select-none');
    if (none) none.addEventListener('click', function () {
        window._selectedIds = new Set();
        renderSongList();
        updateBulkActionsBar();
    });

    var del = document.getElementById('btn-bulk-delete');
    if (del) del.addEventListener('click', function () {
        var ids = Array.from(window._selectedIds || []);
        if (!ids.length) return;
        var threshold = 10;
        if (ids.length >= threshold) {
            var typed = prompt(
                'About to delete ' + ids.length + ' songs. Type DELETE to confirm.'
            );
            if (typed !== 'DELETE') return;
        } else if (!confirm('Delete ' + ids.length + ' selected song(s)? This cannot be undone until you Save.')) {
            return;
        }

        var idSet = new Set(ids);
        songData.songs = (songData.songs || []).filter(function (s) { return !idSet.has(s.id); });
        rebuildSongIndex();   /* keep the O(1) id index in sync (#1180-A1) */
        ids.forEach(function (id) { modifiedSongIds.delete(id); });
        if (currentSongId && idSet.has(currentSongId)) {
            currentSongId = null;
            clearEditForm();
        }
        window._selectedIds = new Set();
        renderSongList();
        updateStatusBar();
        updateBulkActionsBar();
        showToast('Deleted ' + ids.length + ' songs. Click Save to persist.', 'warning');
    });

    /* Bulk Verify (#399) — sets verified = true on every selected song
       and marks each as modified so the existing Save flow persists it. */
    var verify = document.getElementById('btn-bulk-verify');
    if (verify) verify.addEventListener('click', function () {
        var ids = Array.from(window._selectedIds || []);
        if (!ids.length) return;
        /* WS-D #1016 — load full records first so Save persists COMPLETE
           songs; mutating a slim stub would make save_song wipe its
           credits/components. */
        _loadSongsFull(ids).then(function () {
            var idSet = new Set(ids);
            var changed = 0;
            (songData.songs || []).forEach(function (s) {
                if (!idSet.has(s.id)) return;
                if (!s.verified) {
                    s.verified = true;
                    modifiedSongIds.add(s.id);
                    changed++;
                }
            });
            renderSongList();
            updateStatusBar();
            updateBulkActionsBar();
            showToast(
                changed > 0
                    ? 'Marked ' + changed + ' song(s) verified. Click Save to persist.'
                    : 'Nothing to change — all selected songs were already verified.',
                changed > 0 ? 'success' : 'info'
            );
        }).catch(function (err) {
            showToast('Could not load songs for bulk verify: ' +
                      (err && err.message ? err.message : err), 'danger');
        });
    });

    /* Bulk Move (#399) — relocates every selected song to a different
       songbook. Clears Number on each (set to NULL) because re-numbering
       to avoid collisions is per-book and needs a proper UI. The user
       can renumber per-song afterwards. */
    var move = document.getElementById('btn-bulk-move');
    if (move) move.addEventListener('click', function () {
        var ids = Array.from(window._selectedIds || []);
        if (!ids.length) return;
        var choices = (songData.songbooks || []).map(function (sb) { return sb.id; }).join(', ');
        var target = prompt(
            'Move ' + ids.length + ' selected song(s) to which songbook?\n\n' +
            'Available: ' + choices + '\n\n' +
            'Number will be cleared (NULL) — renumber individually afterwards.'
        );
        if (!target) return;
        var targetId = target.trim();
        var sb = (songData.songbooks || []).find(function (s) { return s.id === targetId; });
        if (!sb) { showToast('No songbook "' + targetId + '".', 'warning'); return; }

        /* WS-D #1016 — load full records first (see bulk verify). */
        _loadSongsFull(ids).then(function () {
            var idSet = new Set(ids);
            (songData.songs || []).forEach(function (s) {
                if (!idSet.has(s.id)) return;
                s.songbook = sb.id;
                s.songbookName = sb.name;
                s.number = null;
                modifiedSongIds.add(s.id);
            });
            renderSongList();
            updateStatusBar();
            updateBulkActionsBar();
            showToast('Moved ' + ids.length + ' song(s) to ' + sb.id + '. Click Save to persist.', 'success');
        }).catch(function (err) {
            showToast('Could not load songs for bulk move: ' +
                      (err && err.message ? err.message : err), 'danger');
        });
    });

    /* Bulk Export (#399) — downloads the selected songs as JSON.
       Filename follows the shared #883 / export-filename convention:
         - 1 song selected            → "<padded#> (<ABBR>) - <Title>[ (<Tune>)].json"
         - N songs in one songbook    → "<Songbook> (<ABBR>) [Bundle].json"
         - mixed / no obvious bundle  → "songs-export-YYYY-MM-DD.json" (fallback) */
    var exp = document.getElementById('btn-bulk-export');
    if (exp) exp.addEventListener('click', function () {
        var ids = Array.from(window._selectedIds || []);
        if (!ids.length) return;
        /* WS-D #1016 — load full records first so the export file holds
           COMPLETE songs (components, credits, …), not slim sidebar stubs. */
        _loadSongsFull(ids).then(function () {
            var idSet = new Set(ids);
            var subset = (songData.songs || []).filter(function (s) { return idSet.has(s.id); });

            return import('/js/modules/export-filename.js?v=' + Date.now())
                .then(function (m) {
                    var filenameBase;
                    if (subset.length === 1) {
                        var only = subset[0];
                        var bookSongs = (songData.songs || []).filter(function (s) {
                            return s.songbook === only.songbook;
                        });
                        filenameBase = m.songExportFilename(only, bookSongs);
                    } else {
                        /* All in one songbook? Use the bundle convention. */
                        var firstAbbr = subset[0].songbook;
                        var allSameBook = subset.every(function (s) { return s.songbook === firstAbbr; });
                        if (allSameBook) {
                            var sb = (songData.songbooks || []).find(function (b) { return b.id === firstAbbr; });
                            filenameBase = m.songbookExportFilename({
                                id:   firstAbbr,
                                name: (sb && sb.name) || subset[0].songbookName || firstAbbr,
                            });
                        } else {
                            filenameBase = 'songs-export-' + new Date().toISOString().slice(0, 10);
                        }
                    }
                    downloadBlob(
                        JSON.stringify({ songs: subset }, null, 2),
                        filenameBase + '.json',
                        'application/json'
                    );
                    showToast('Exported ' + subset.length + ' song(s) to JSON.', 'success');
                })
                .catch(function (err) {
                    /* Helper module unavailable (broken deploy / offline) — fall
                       back to the legacy date-stamped filename so the export
                       still works. */
                    try { console.warn('[bulk_export] filename helper load failed:', err); } catch (_e) {}
                    downloadBlob(
                        JSON.stringify({ songs: subset }, null, 2),
                        'songs-export-' + new Date().toISOString().slice(0, 10) + '.json',
                        'application/json'
                    );
                    showToast('Exported ' + subset.length + ' song(s) to JSON.', 'success');
                });
        }).catch(function (err) {
            showToast('Could not load songs for export: ' +
                      (err && err.message ? err.message : err), 'danger');
        });
    });

    /* Bulk Tag (#399) — adds and/or removes tags on every selected song.
       Posts to /api?action=bulk_tag; tag membership lives server-side
       in tblSongTagMap, outside the save_song flow. */
    var tag = document.getElementById('btn-bulk-tag');
    if (tag) tag.addEventListener('click', function () {
        var ids = Array.from(window._selectedIds || []);
        if (!ids.length) return;
        var toAdd = prompt(
            'Tags to ADD on ' + ids.length + ' selected song(s)?\n' +
            'Comma-separated (e.g. "Easter, Communion"). Leave blank to skip.'
        );
        if (toAdd === null) return; /* user cancelled */
        var toRemove = prompt(
            'Tags to REMOVE on ' + ids.length + ' selected song(s)?\n' +
            'Comma-separated. Leave blank to skip.'
        );
        if (toRemove === null) return;

        var add = toAdd.split(',').map(function (t) { return t.trim(); }).filter(Boolean);
        var rem = toRemove.split(',').map(function (t) { return t.trim(); }).filter(Boolean);
        if (!add.length && !rem.length) {
            showToast('No tag changes specified.', 'info');
            return;
        }

        fetch(EDITOR_API_URL + '?action=bulk_tag', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: JSON.stringify({ songIds: ids, add: add, remove: rem })
        })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, data: j }; }); })
            .then(function (res) {
                if (!res.ok) {
                    showToast('Bulk tag failed: ' + (res.data.error || 'Unknown error.'), 'danger');
                    return;
                }
                showToast(
                    'Tagged ' + res.data.songsAffected + ' song(s) ' +
                    '(+' + res.data.added + ', -' + res.data.removed + ').',
                    'success'
                );
            })
            .catch(function (err) {
                showToast('Bulk tag request failed: ' + err.message, 'danger');
            });
    });
}

/* ============================================================================
 *  REVISION HISTORY (#400)
 *  --------------------------------------------------------------------------
 *  Toolbar "History" button opens a modal that lists revisions for the
 *  currently-selected song (newest first), with a Restore button per row
 *  and a side-by-side JSON diff on click.
 * ========================================================================== */

function bindHistoryListener() {
    var btn = document.getElementById('btn-history');
    if (!btn) return;
    btn.addEventListener('click', function () {
        if (!currentSongId) {
            showToast('Select a song first.', 'warning');
            return;
        }
        openHistoryModal(currentSongId);
    });
}

/**
 * updateHistoryButtonState()
 * --------------------------
 * Toggle Save / Validate / History buttons in step with the current
 * selection + load state (#590). Called from every place currentSongId
 * changes plus after the auto-load completes so the buttons accurately
 * reflect what the curator can actually do.
 *
 *   Save      — needs a selected song
 *   History   — needs a selected song (revisions are per-song)
 *   Validate  — needs at least one song loaded; catalogue-wide, no
 *               selection required.
 */
function updateHistoryButtonState() {
    var hasSong  = !!currentSongId;
    var hasSongs = (typeof songData !== 'undefined' && songData
        && Array.isArray(songData.songs) && songData.songs.length > 0);

    var saveBtn = document.getElementById('btn-save');
    if (saveBtn) {
        saveBtn.disabled = !hasSong;
        saveBtn.title = hasSong
            ? 'Save all changes to the database'
            : 'Select a song to enable Save';
    }

    var historyBtn = document.getElementById('btn-history');
    if (historyBtn) {
        historyBtn.disabled = !hasSong;
        /* Renamed History → Revisions (#591) for consistency with the
           /manage/revisions admin menu entry. ID stays for back-compat. */
        historyBtn.title = hasSong
            ? 'Show revision history for the selected song'
            : 'Select a song to enable Revisions';
    }
}

function openHistoryModal(songId) {
    var listEl = document.getElementById('history-list');
    var detailEl = document.getElementById('history-detail');
    var titleEl = document.getElementById('history-modal-title');
    if (!listEl || !detailEl) return;

    if (titleEl) titleEl.innerHTML = '<i class="bi bi-clock-history me-2"></i>Revision history — ' + escapeHtml(songId);
    listEl.innerHTML = '<div class="text-center p-3"><i class="bi bi-hourglass-split me-1"></i>Loading…</div>';
    detailEl.innerHTML = '';

    var modalEl = document.getElementById('history-modal');
    if (modalEl && window.bootstrap) {
        var modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }

    fetch(EDITOR_API_URL + '?action=list_revisions&songId=' + encodeURIComponent(songId), {
        credentials: 'same-origin',
    })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, data: j }; }); })
        .then(function (res) {
            if (!res.ok) {
                listEl.innerHTML = '<div class="text-danger p-3">Failed to load revisions: ' +
                    (res.data.error || 'unknown error') + '</div>';
                return;
            }
            renderHistoryList(res.data.revisions || [], listEl, detailEl);
        })
        .catch(function (err) {
            listEl.innerHTML = '<div class="text-danger p-3">Request failed: ' + escapeHtml(err.message) + '</div>';
        });
}

function renderHistoryList(revisions, listEl, detailEl) {
    if (!revisions.length) {
        listEl.innerHTML = '<div class="text-muted p-3">No revisions recorded for this song yet.</div>';
        return;
    }
    listEl.innerHTML = '';
    revisions.forEach(function (rev) {
        var item = document.createElement('button');
        item.type = 'button';
        item.className = 'list-group-item list-group-item-action bg-dark text-light border-secondary d-flex justify-content-between align-items-center';
        var badgeClass = rev.action === 'create' ? 'bg-success'
            : rev.action === 'restore' ? 'bg-info'
            : 'bg-secondary';
        item.innerHTML =
            '<div>' +
                '<span class="badge ' + badgeClass + ' me-2">' + rev.action + '</span>' +
                '<span class="small text-muted">' + rev.createdAt + '</span>' +
                '<span class="ms-2">by ' + (rev.username || '—') + '</span>' +
            '</div>' +
            '<div class="d-flex gap-2">' +
                '<button class="btn btn-sm btn-outline-info" data-rev-id="' + rev.id + '">View diff</button>' +
                (rev.previousData
                    ? '<button class="btn btn-sm btn-outline-warning btn-restore-rev" data-rev-id="' + rev.id + '">Restore</button>'
                    : '') +
            '</div>';
        item.querySelector('[data-rev-id]').addEventListener('click', function (ev) {
            ev.stopPropagation();
            renderRevisionDiff(rev, detailEl);
        });
        var restore = item.querySelector('.btn-restore-rev');
        if (restore) {
            restore.addEventListener('click', function (ev) {
                ev.stopPropagation();
                triggerRevisionRestore(rev);
            });
        }
        listEl.appendChild(item);
    });
    /* Show the first diff by default so the modal never looks empty. */
    renderRevisionDiff(revisions[0], detailEl);
}

function renderRevisionDiff(rev, detailEl) {
    var beforeJson = rev.previousData ? JSON.stringify(rev.previousData, null, 2) : '(none — initial create)';
    var afterJson  = rev.newData      ? JSON.stringify(rev.newData,      null, 2) : '(none)';
    detailEl.innerHTML =
        '<div class="row g-2 small">' +
            '<div class="col-md-6">' +
                '<h6 class="text-muted">Before</h6>' +
                '<pre class="bg-black p-2 rounded" style="max-height:400px;overflow:auto;">' +
                    escapeHtml(beforeJson) +
                '</pre>' +
            '</div>' +
            '<div class="col-md-6">' +
                '<h6 class="text-muted">After</h6>' +
                '<pre class="bg-black p-2 rounded" style="max-height:400px;overflow:auto;">' +
                    escapeHtml(afterJson) +
                '</pre>' +
            '</div>' +
        '</div>';
}

function triggerRevisionRestore(rev) {
    if (!confirm('Restore the song to the state BEFORE revision #' + rev.id + '? ' +
                 'This will create a new "restore" revision row and overwrite the current tblSongs row.')) {
        return;
    }
    fetch(EDITOR_API_URL + '?action=restore_revision', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: JSON.stringify({ revisionId: rev.id }),
    })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, data: j }; }); })
        .then(function (res) {
            if (!res.ok) {
                showToast('Restore failed: ' + (res.data.error || 'unknown error'), 'danger');
                return;
            }
            showToast('Restored. Reload the editor to see the current state.', 'success');
            /* Close the modal; the user can manually reload to see results. */
            var modalEl = document.getElementById('history-modal');
            if (modalEl && window.bootstrap) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            }
        })
        .catch(function (err) {
            showToast('Restore request failed: ' + err.message, 'danger');
        });
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

/* ---- Kick everything off once the DOM is ready ---- */
document.addEventListener('DOMContentLoaded', function () {
    init();
    bindMultiSelectListeners();
    bindHistoryListener();
});
