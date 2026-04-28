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
var EDITOR_API_URL = 'api';

/**
 * Fallback relative paths for loading songs.json when the PHP API
 * is not available (e.g., running the editor without PHP).
 */
var SONGS_URL_CANDIDATES = [
    'api?action=load',                        /* Primary: PHP API reads from data_share/ */
    '../data/songs.json',                     /* Deployed server: data/ inside private_html/ */
    '../../data/songs.json',                  /* Alternative: data/ one up from private_html/ */
    '../../../data/songs.json',               /* Local dev: relative to appWeb/private_html/editor/ → appWeb/data/ */
    '../../../../data/songs.json',            /* Local dev: relative to editor/ → project root data/ */
    'data/songs.json'                         /* Fallback: same directory */
];

/** Default URL shown in the manual load prompt. */
var DEFAULT_SONGS_URL = SONGS_URL_CANDIDATES[0];

/** ID of the song currently loaded into the edit form (null when nothing selected). */
var currentSongId = null;

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
 * loadSongsFromFile(file)
 * -----------------------
 * Reads a File object (from an <input type="file">) as text, parses it as JSON,
 * and stores the result in the global `songData` object.
 *
 * @param {File} file - A File reference chosen by the user.
 * @returns {Promise<void>}
 */
function loadSongsFromFile(file) {
    return new Promise(function (resolve, reject) {
        /* Create a FileReader to read the file contents as a UTF-8 string. */
        var reader = new FileReader();

        /* When the read completes successfully, parse the JSON string. */
        reader.onload = function (event) {
            try {
                /* Parse the raw text into a JavaScript object. */
                var parsed = JSON.parse(event.target.result);

                /* Store each top-level key into the global songData. */
                songData.meta      = parsed.meta      || {};
                songData.songbooks = parsed.songbooks  || [];
                songData.songs     = parsed.songs      || [];

                /* Reset modification tracking because we just loaded fresh data. */
                modifiedSongIds.clear();
                currentSongId = null;

                /* Re-render the UI to reflect the newly loaded data. */
                renderSongList();      // rebuild sidebar
                clearEditForm();       // nothing selected yet
                updateStatusBar();     // refresh counts

                /* Notify the user that loading succeeded. */
                showToast('Loaded ' + songData.songs.length + ' song(s) from file.', 'success');
                resolve();
            } catch (err) {
                /* JSON parsing failed — inform the user. */
                showToast('Failed to parse JSON: ' + err.message, 'danger');
                reject(err);
            }
        };

        /* If the FileReader itself errors, reject the promise. */
        reader.onerror = function () {
            showToast('Failed to read file.', 'danger');
            reject(reader.error);
        };

        /* Start reading the file as a text string. */
        reader.readAsText(file);
    });
}

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

    function tryNext() {
        if (candidates.length === 0) {
            showToast('Could not load songs.json from any path. Use "Load JSON" to load manually.', 'warning');
            return Promise.resolve();
        }
        var candidate = candidates.shift();
        return _fetchAndParseSongs(candidate).catch(function () {
            /* This path failed — try the next one */
            return tryNext();
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
            /* If the HTTP status indicates failure, throw so we land in .catch(). */
            if (!response.ok) {
                throw new Error('HTTP ' + response.status + ' ' + response.statusText);
            }
            /* Parse the response body as JSON. */
            return response.json();
        })
        .then(function (parsed) {
            /* Store each top-level key into the global songData. */
            songData.meta      = parsed.meta      || {};
            songData.songbooks = parsed.songbooks  || [];
            songData.songs     = parsed.songs      || [];

            /* Reset tracking state. */
            modifiedSongIds.clear();
            currentSongId = null;

            /* Re-render everything. */
            renderSongList();
            clearEditForm();
            updateStatusBar();

            /* Notify the user. */
            showToast('Loaded ' + songData.songs.length + ' song(s) from URL.', 'success');
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
       they never opened) is missing a field. */
    var errors = validateSongsByIds(ids);
    if (errors.length > 0) {
        errors.forEach(function (msg) { showToast(msg, 'danger'); });
        return;
    }

    /* Refresh the generatedAt timestamp so any subsequent export
       reflects the save time. */
    songData.meta.generatedAt = new Date().toISOString();

    /* Stream each song to the per-song endpoint. */
    autoSaveSongsPerSong(ids).then(function (summary) {
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

/* Backwards-compatible alias for the old function name. */
var saveSongsToFile = saveSongs;

/**
 * downloadSongsJson(jsonString)
 * -----------------------------
 * Fallback: triggers a browser download of the songs.json data when the
 * server-side save is not available.
 *
 * @param {string} jsonString - The JSON string to download.
 */
function downloadSongsJson(jsonString) {
    /* Create a Blob (binary large object) from the JSON string. */
    var blob = new Blob([jsonString], { type: 'application/json' });

    /* Build a temporary object URL that points to the Blob. */
    var url = URL.createObjectURL(blob);

    /* Create a hidden <a> element, set its href to the blob URL, and click it. */
    var anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = 'songs.json';      // suggested file name
    document.body.appendChild(anchor);    // must be in DOM for Firefox
    anchor.click();                       // trigger the download
    document.body.removeChild(anchor);    // clean up the DOM
    URL.revokeObjectURL(url);             // free the blob memory

    /* Record the save time and clear modification flags. */
    lastSaveTime = new Date();
    modifiedSongIds.clear();

    /* Refresh UI to reflect saved state. */
    renderSongList();
    updateStatusBar();

    /* Confirm to the user. */
    showToast('songs.json downloaded (manual placement required).', 'info');
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

    /* Count how many songs pass the filters (for the badge). */
    var visibleCount = 0;

    /* Sort songs according to the current sort mode (#251). */
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

    /* Iterate over sorted songs. */
    sortedSongs.forEach(function (song) {
        /* ---- Songbook filter ---- */
        if (songbookFilter && song.songbook !== songbookFilter) {
            return; // skip songs not in the selected songbook
        }

        /* ---- Text search filter ---- */
        if (searchTerm) {
            /* Match against title and number (both lowercased). */
            var titleMatch  = (song.title  || '').toLowerCase().indexOf(searchTerm) !== -1;
            var numberMatch = String(song.number || '').toLowerCase().indexOf(searchTerm) !== -1;
            if (!titleMatch && !numberMatch) {
                return; // skip songs that don't match the search
            }
        }

        /* This song passed all filters — render it. */
        visibleCount++;

        /* Create the list-group-item element. */
        var li = document.createElement('a');
        li.href = '#';
        li.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';

        /* Multi-select mode (#399): prepend a checkbox that mirrors
           the selected state and clicking it toggles selection without
           navigating to the song. */
        if (window._selectMode) {
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.className = 'form-check-input me-2 flex-shrink-0';
            cb.dataset.songId = song.id;
            cb.checked = window._selectedIds && window._selectedIds.has(song.id);
            cb.addEventListener('click', function (e) {
                e.stopPropagation();
            });
            cb.addEventListener('change', function () {
                if (!window._selectedIds) window._selectedIds = new Set();
                if (cb.checked) window._selectedIds.add(song.id);
                else window._selectedIds.delete(song.id);
                updateBulkActionsBar();
            });
            li.appendChild(cb);
        }

        /* Highlight the currently selected song. */
        if (song.id === currentSongId) {
            li.classList.add('active');
        }

        /* Build the display text: "number - Title Case Title" (#249). */
        var label = document.createElement('span');
        label.textContent = (song.number || '?') + ' - ' + toTitleCase(song.title || 'Untitled');

        /* Right-side badges: songbook abbreviation + modified indicator (#249). */
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

        /* When the user clicks this item, load the song into the editor. */
        li.addEventListener('click', function (e) {
            e.preventDefault(); // prevent default anchor behaviour
            selectSong(song.id);
        });

        /* Add the item to the list. */
        listEl.appendChild(li);
    });

    /* Update the song-count badge in the sidebar header. */
    var countEl = document.getElementById('song-count');
    if (countEl) {
        countEl.textContent = visibleCount + ' / ' + songData.songs.length;
    }
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
    /* Find the song object in the data array. */
    var song = songData.songs.find(function (s) { return s.id === songId; });
    if (!song) {
        showToast('Song not found: ' + songId, 'danger');
        return;
    }

    /* Store the selection globally. */
    currentSongId = songId;
    updateHistoryButtonState();

    /* Show the editor form, hide the empty state (#246). */
    var editorEmpty = document.getElementById('editorEmpty');
    var editorForm = document.getElementById('editorForm');
    if (editorEmpty) editorEmpty.style.display = 'none';
    if (editorForm) editorForm.style.display = '';

    /* Populate the metadata fields. */
    setVal('edit-title', song.title || '');
    setVal('edit-number', song.number || '');
    setVal('edit-songbook', song.songbook || '');
    setVal('edit-ccli', song.ccli || '');
    setVal('edit-iswc', song.iswc || '');            /* #497 */
    setVal('edit-tune-name', song.tuneName || '');   /* #497 */
    /* Parse IETF BCP 47 tag into sub-fields (#240). */
    var ietf = parseIetfTag(song.language || 'en');
    setVal('edit-lang-language', ietf.language);
    setVal('edit-lang-script', ietf.script);
    setVal('edit-lang-region', ietf.region);
    composeIetfTag();

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

    /* Render the translations (cross-song link) panel (#352). */
    renderTranslations(song);

    /* Fetch + render this song's tags (#496). Async — the chip list
       shows "Loading…" until the fetch resolves; failures keep the
       list empty rather than blocking the whole editor. */
    loadSongTags(song);

    /* Render the copyright textarea. */
    setVal('edit-copyright', song.copyright || '');

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

                /* Write the new value back to the song object. */
                song[field.key] = el.value;

                /* Sync songbookName when songbook changes (#245). */
                if (field.key === 'songbook') {
                    var sb = songData.songbooks.find(function (s) { return s.id === el.value; });
                    song.songbookName = sb ? sb.name : el.value;
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
        });
    });

    /* Language sub-fields — compose IETF BCP 47 tag on change (#240). */
    ['edit-lang-language', 'edit-lang-script', 'edit-lang-region'].forEach(function (elId) {
        var el = document.getElementById(elId);
        if (!el) return;

        ['input', 'change'].forEach(function (eventType) {
            el.addEventListener(eventType, function () {
                if (!currentSongId) return;
                var song = findSongById(currentSongId);
                if (!song) return;

                /* Compose the three fields into a single IETF tag and store it. */
                song.language = composeIetfTag();
                markModified(song.id);
            });
        });
    });
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

        /* Assemble the header. */
        header.appendChild(typeLabel);
        header.appendChild(typeSelect);
        header.appendChild(numInput);
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
            autoResizeTextarea(textarea); // re-fit height
            renderPreview(song);
        });

        body.appendChild(textarea);

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

    /* Re-render. */
    renderComponents(song);
    renderArrangement(song);
    renderPreview(song);
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

    /* Build arrangement: chorus/refrain after each verse, other components in place. */
    var arrangement = [];
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
};

function renderCreditChipList(song, key, containerId, addLabel) {
    var container = document.getElementById(containerId);
    if (!container) return;
    if (!Array.isArray(song[key])) song[key] = [];
    container.innerHTML = '';

    var kind = CREDIT_KIND_FOR_KEY[key] || null;

    song[key].forEach(function (name, i) {
        var row = createDynamicInputRow(
            name,
            function (newVal) { song[key][i] = newVal; markModified(song.id); },
            function ()       { song[key].splice(i, 1); markModified(song.id);
                                renderCreditChipList(song, key, containerId, addLabel); },
            kind
        );
        container.appendChild(row);
    });

    var addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'btn btn-sm btn-outline-primary mt-2';
    addBtn.textContent = '+ ' + addLabel;
    addBtn.addEventListener('click', function () {
        song[key].push('');
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
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ songIds: [song.id], add: [tagName], remove: [] }),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data && data.added != null) {
            /* Invalidate the bulk-load cache so loadSongTags re-fetches
               with the authoritative row set (#496 follow-up). */
            delete song.tags;
            loadSongTags(song);
            showToast('Added tag "' + tagName + '".', 'success');
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
        headers: { 'Content-Type': 'application/json' },
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
            item.innerHTML =
                '<span>' + escapeHtmlSafe(s.name) + '</span>' +
                '<span class="badge bg-secondary">' + s.usage + '</span>';
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
            item.innerHTML =
                '<span><strong>' + escapeHtmlSafe(s.name) + '</strong> ' + kindsBadge + '</span>' +
                '<span class="badge bg-secondary">' + (s.usage || 0) + '</span>';
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
            .then(function (r) { return r.json(); })
            .then(function (data) {
                render(Array.isArray(data.suggestions) ? data.suggestions : []);
            })
            .catch(function () { close(); });
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

/**
 * validateSongData()
 * ------------------
 * Checks every song in songData.songs for required fields:
 *   - id (non-empty string)
 *   - title (non-empty string)
 *   - number (must exist)
 *   - songbook (non-empty string)
 *   - components (must be an array with at least one entry)
 *
 * @returns {string[]} An array of human-readable error messages. Empty = valid.
 */
function validateSongData() {
    /* Accumulator for error messages. */
    var errors = [];

    /* Iterate over every song. */
    songData.songs.forEach(function (song, i) {
        /* Build a label for this song to make errors identifiable. */
        var label = 'Song [' + i + '] (' + (song.title || 'no title') + ')';

        /* id is required. */
        if (!song.id || typeof song.id !== 'string' || song.id.trim() === '') {
            errors.push(label + ': missing or empty "id".');
        }

        /* title is required. */
        if (!song.title || typeof song.title !== 'string' || song.title.trim() === '') {
            errors.push(label + ': missing or empty "title".');
        }

        /* number is required (can be string or number, but must exist). */
        if (song.number == null || String(song.number).trim() === '') {
            errors.push(label + ': missing "number".');
        }

        /* songbook is required. */
        if (!song.songbook || typeof song.songbook !== 'string' || song.songbook.trim() === '') {
            errors.push(label + ': missing or empty "songbook".');
        }

        /* components must be a non-empty array. */
        if (!Array.isArray(song.components) || song.components.length === 0) {
            errors.push(label + ': must have at least one component.');
        }
    });

    /* Return the collected errors (empty array means everything is valid). */
    return errors;
}

/**
 * validateSongsByIds(ids)
 * -----------------------
 * Same validation rules as validateSongData(), but scoped to a specific
 * list of song IDs. Used by the manual Save button so that missing
 * fields on a song the admin never touched don't block a save of the
 * song they actually edited.
 *
 * @param {string[]} ids Song IDs to validate
 * @returns {string[]} Human-readable error messages; empty if all valid
 */
function validateSongsByIds(ids) {
    var wanted = new Set(ids);
    var errors = [];
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
        if (song.number == null || String(song.number).trim() === '') {
            errors.push(label + ': missing "number".');
        }
        if (!song.songbook || typeof song.songbook !== 'string' || song.songbook.trim() === '') {
            errors.push(label + ': missing or empty "songbook".');
        }
        if (!Array.isArray(song.components) || song.components.length === 0) {
            errors.push(label + ': must have at least one component.');
        }
    });
    return errors;
}

/* ========================================================================
 *  SECTION 8 — Bulk Import / Export (#59)
 * ======================================================================== */

/**
 * exportJSON()
 * ------------
 * Downloads the full songs.json file. This is essentially the same as
 * saveSongsToFile() but exposed under a distinct name for the toolbar.
 */
function exportJSON() {
    saveSongsToFile(); // delegate to the existing save logic
}

/**
 * exportCSV()
 * -----------
 * Builds a CSV string with the columns:
 *   id, number, title, songbook, writers, composers, ccli, componentCount
 * and triggers a file download.
 */
function exportCSV() {
    /* CSV header row. */
    var rows = ['id,number,title,songbook,writers,composers,ccli,componentCount'];

    /* One row per song. */
    songData.songs.forEach(function (song) {
        var cols = [
            csvEscape(song.id || ''),
            csvEscape(String(song.number || '')),
            csvEscape(song.title || ''),
            csvEscape(song.songbook || ''),
            csvEscape((song.writers || []).join('; ')),
            csvEscape((song.composers || []).join('; ')),
            csvEscape(song.ccli || ''),
            String((song.components || []).length)
        ];
        rows.push(cols.join(','));
    });

    /* Join all rows with newlines. */
    var csvString = rows.join('\r\n');

    /* Trigger download. */
    downloadBlob(csvString, 'songs.csv', 'text/csv');

    /* Notify the user. */
    showToast('CSV exported (' + songData.songs.length + ' songs).', 'success');
}

/**
 * csvEscape(value)
 * ----------------
 * Wraps a value in double-quotes and escapes internal double-quotes,
 * making it safe for inclusion in a CSV cell.
 *
 * @param {string} value - The raw cell value.
 * @returns {string} The escaped CSV cell.
 */
function csvEscape(value) {
    /* Replace any double-quote with two double-quotes (CSV escaping rule). */
    var escaped = String(value).replace(/"/g, '""');
    /* Wrap the whole thing in double-quotes. */
    return '"' + escaped + '"';
}

/**
 * importJSON()
 * ------------
 * Opens a file picker, reads a JSON file, and MERGES its songs into the
 * current songData. Songs with matching IDs are updated; new IDs are appended.
 */
function importJSON() {
    /* Create a hidden file input element to open the system file picker. */
    var input = document.createElement('input');
    input.type = 'file';
    input.accept = '.json,application/json';

    /* When the user picks a file, process it. */
    input.addEventListener('change', function () {
        if (!input.files || !input.files[0]) return; // user cancelled

        var reader = new FileReader();
        reader.onload = function (event) {
            try {
                /* Parse the imported file. */
                var imported = JSON.parse(event.target.result);
                var importedSongs = imported.songs || [];

                /* Counters for user feedback. */
                var added = 0;
                var updated = 0;

                /* Process each imported song. */
                importedSongs.forEach(function (incoming) {
                    /* Try to find an existing song with the same ID. */
                    var existingIndex = songData.songs.findIndex(function (s) {
                        return s.id === incoming.id;
                    });

                    if (existingIndex !== -1) {
                        /* Update existing song by replacing it entirely. */
                        songData.songs[existingIndex] = incoming;
                        markModified(incoming.id);
                        updated++;
                    } else {
                        /* Append as a new song. */
                        songData.songs.push(incoming);
                        markModified(incoming.id);
                        added++;
                    }
                });

                /* Also merge songbooks if the imported file has them. */
                if (imported.songbooks && Array.isArray(imported.songbooks)) {
                    imported.songbooks.forEach(function (sb) {
                        /* Only add songbooks that don't already exist. */
                        var exists = songData.songbooks.some(function (existing) {
                            return existing.id === sb.id;
                        });
                        if (!exists) {
                            songData.songbooks.push(sb);
                        }
                    });
                }

                /* Refresh all UI. */
                populateSongbookFilterDropdown();
                renderSongList();
                updateStatusBar();

                /* Inform the user. */
                showToast('Import complete: ' + added + ' added, ' + updated + ' updated.', 'success');
            } catch (err) {
                showToast('Import failed: ' + err.message, 'danger');
            }
        };
        reader.readAsText(input.files[0]);
    });

    /* Programmatically click the hidden input to open the file dialog. */
    input.click();
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
        /* "; " separator (#495). */
        writersEl.textContent = 'Writers: ' + song.writers.join('; ');
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
function findSongById(id) {
    return songData.songs.find(function (s) {
        return s.id === id;
    });
}

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

    /* Refresh the status bar to reflect the new count. */
    updateStatusBar();

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

function scheduleAutoSave() {
    /* Drop any pending save; each edit resets the timer. */
    if (_autoSaveTimer) clearTimeout(_autoSaveTimer);

    _autoSaveTimer = setTimeout(function () {
        _autoSaveTimer = null;
        if (_autoSaveRunning)      return;
        if (modifiedSongIds.size === 0) return;
        if (validateSongData().length > 0) return;

        _autoSaveRunning = true;
        var text = document.getElementById('status-text');
        if (text) text.textContent = 'Auto-saving…';

        /* Per-song endpoint (#394). Walk the modified set and POST each
           song individually to /api?action=save_song. Much cheaper than
           the full-corpus `save`, and writes one row to tblSongRevisions
           per actual edit (#400). Falls back to saveSongs() (full save)
           if the per-song endpoint returns a non-ok status. */
        var ids = Array.from(modifiedSongIds);
        autoSaveSongsPerSong(ids).then(function (summary) {
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

/**
 * autoSaveSongsPerSong(ids)
 * -------------------------
 * POST each song in `ids` to /api?action=save_song sequentially.
 * Returns a summary { saved: [ids], failed: [{id, error}] }.
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
            return fetch(EDITOR_API_URL + '?action=save_song', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(song),
            }).then(function (res) {
                return res.json().then(function (data) {
                    if (res.ok && data.ok) saved.push(id);
                    else failed.push({ id: id, error: data.error || ('HTTP ' + res.status) });
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

/* ----------------------------------------------------------------------
 * Language / Script / Region lookup tables (#489)
 *
 * The editor's three language inputs now display **full names** by
 * default — the average admin doesn't know that "Latn" is Latin or
 * that "cy" is Welsh. Each table is keyed by ISO code (which is what
 * MySQL actually stores, as part of the composed IETF BCP 47 tag) and
 * maps to the human-friendly name shown in the input.
 *
 * Keep in lock-step with the <datalist> options in index.php — an
 * option in the markup but absent from this table would resolve back
 * to its code on save (harmless but uglier).
 * ---------------------------------------------------------------------- */
var LANG_CODE_TO_NAME = {
    en:'English', fr:'French', de:'German', es:'Spanish', it:'Italian',
    pt:'Portuguese', la:'Latin', cy:'Welsh', gd:'Scottish Gaelic',
    ga:'Irish', nl:'Dutch', sv:'Swedish', no:'Norwegian', da:'Danish',
    fi:'Finnish', pl:'Polish', cs:'Czech', hu:'Hungarian', ro:'Romanian',
    ko:'Korean', ja:'Japanese', zh:'Chinese', ar:'Arabic', he:'Hebrew',
    hi:'Hindi', sw:'Swahili', zu:'Zulu', xh:'Xhosa', af:'Afrikaans',
    tl:'Tagalog',
};
var SCRIPT_CODE_TO_NAME = {
    Latn:'Latin', Cyrl:'Cyrillic', Arab:'Arabic', Hebr:'Hebrew',
    Deva:'Devanagari', Hans:'Simplified Chinese', Hant:'Traditional Chinese',
    Hang:'Hangul', Kana:'Katakana', Grek:'Greek', Geor:'Georgian',
    Armn:'Armenian', Thai:'Thai', Ethi:'Ethiopic',
};
var REGION_CODE_TO_NAME = {
    GB:'United Kingdom', US:'United States', AU:'Australia', NZ:'New Zealand',
    CA:'Canada', IE:'Ireland', ZA:'South Africa', FR:'France', DE:'Germany',
    AT:'Austria', CH:'Switzerland', ES:'Spain', MX:'Mexico', IT:'Italy',
    PT:'Portugal', BR:'Brazil', NL:'Netherlands', SE:'Sweden', NO:'Norway',
    DK:'Denmark', FI:'Finland', PL:'Poland', CZ:'Czechia', HU:'Hungary',
    RO:'Romania', KR:'South Korea', JP:'Japan', CN:'China', TW:'Taiwan',
    IN:'India', PH:'Philippines', KE:'Kenya', NG:'Nigeria', GH:'Ghana',
};

/* Pre-compute reverse maps (lowercased name → code) for lookup. */
var _LANG_NAME_TO_CODE = _flipLangMap(LANG_CODE_TO_NAME);
var _SCRIPT_NAME_TO_CODE = _flipLangMap(SCRIPT_CODE_TO_NAME);
var _REGION_NAME_TO_CODE = _flipLangMap(REGION_CODE_TO_NAME);

function _flipLangMap(src) {
    var out = {};
    Object.keys(src).forEach(function (code) {
        out[src[code].toLowerCase()] = code;
    });
    return out;
}

/**
 * Resolve a free-text input value to its canonical ISO code.
 * Accepts either the full name ("English", case-insensitive) or the
 * bare code ("en"). Returns the input unchanged if it can't be
 * resolved — we don't want to silently discard a value the admin
 * deliberately typed for a language we don't yet list.
 *
 * @param {string} value   Raw value from a language/script/region input
 * @param {"language"|"script"|"region"} dim
 * @returns {string} ISO code (or original value if not recognised)
 */
function resolveLangCode(value, dim) {
    var v = (value || '').trim();
    if (!v) return '';
    var lower = v.toLowerCase();
    var nameMap = dim === 'language' ? _LANG_NAME_TO_CODE
                : dim === 'script'   ? _SCRIPT_NAME_TO_CODE
                : dim === 'region'   ? _REGION_NAME_TO_CODE
                : {};
    if (nameMap[lower] != null) return nameMap[lower];

    /* Fall through to code-normalisation. The datalist no longer
       offers raw codes, but power users may still type them. */
    if (dim === 'language') return lower.toLowerCase();
    if (dim === 'region')   return v.toUpperCase();
    if (dim === 'script') {
        return v.length > 0
            ? v.charAt(0).toUpperCase() + v.slice(1).toLowerCase()
            : '';
    }
    return v;
}

/**
 * Convert an ISO code back to its full display name, falling back to
 * the code itself (useful for inputs populated from existing songs
 * with codes the lookup table doesn't yet know).
 */
function resolveLangName(code, dim) {
    var map = dim === 'language' ? LANG_CODE_TO_NAME
            : dim === 'script'   ? SCRIPT_CODE_TO_NAME
            : dim === 'region'   ? REGION_CODE_TO_NAME
            : {};
    return map[code] != null ? map[code] : (code || '');
}

/**
 * parseIetfTag(tag)
 * -----------------
 * Splits an IETF BCP 47 language tag into its constituent parts,
 * mapping each code to its human-friendly display name (#489). The
 * returned values are what the three editor inputs should show.
 *
 * @param {string} tag - e.g. "en", "en-GB", "zh-Hant-TW"
 * @returns {{ language: string, script: string, region: string }}
 *          display names (e.g. "English", "Latin", "United Kingdom")
 */
function parseIetfTag(tag) {
    var parts = (tag || 'en').split('-');
    var langCode = parts[0] || 'en';
    var scriptCode = '';
    var regionCode = '';

    for (var i = 1; i < parts.length; i++) {
        var p = parts[i];
        if (p.length === 4 && /^[A-Z][a-z]{3}$/.test(p)) {
            scriptCode = p;
        } else if (p.length === 2 && /^[A-Z]{2}$/.test(p)) {
            regionCode = p;
        }
    }

    return {
        language: resolveLangName(langCode,  'language'),
        script:   resolveLangName(scriptCode,'script'),
        region:   resolveLangName(regionCode,'region'),
    };
}

/**
 * composeIetfTag()
 * ----------------
 * Reads the three language sub-fields from the DOM (which carry full
 * names after #489), resolves each back to an ISO code, and composes
 * the IETF BCP 47 tag. Updates the hidden edit-language field and
 * the preview element.
 *
 * @returns {string} The composed IETF tag (e.g. "en-Latn-GB")
 */
function composeIetfTag() {
    var langCode   = resolveLangCode(getVal('edit-lang-language'), 'language') || 'en';
    var scriptCode = resolveLangCode(getVal('edit-lang-script'),   'script');
    var regionCode = resolveLangCode(getVal('edit-lang-region'),   'region');

    var tag = langCode;
    if (scriptCode) tag += '-' + scriptCode;
    if (regionCode) tag += '-' + regionCode;

    /* Update the hidden field and preview. */
    setVal('edit-language', tag);
    var preview = document.getElementById('edit-lang-preview');
    if (preview) preview.textContent = tag;

    return tag;
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
 * toTitleCase(str)
 * ----------------
 * Converts a string to Title Case. Common small words (a, an, the, and,
 * but, or, for, nor, in, on, at, to, by, of, with) stay lowercase unless
 * they are the first or last word.
 *
 * @param {string} str - The input string.
 * @returns {string} The title-cased string.
 */
function toTitleCase(str) {
    var smallWords = /^(a|an|the|and|but|or|for|nor|in|on|at|to|by|of|with)$/i;
    var words = str.replace(/\s+/g, ' ').trim().split(' ');

    return words.map(function (word, i, arr) {
        /* Always capitalise first and last word */
        if (i === 0 || i === arr.length - 1 || !smallWords.test(word)) {
            return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
        }
        return word.toLowerCase();
    }).join(' ');
}

/**
 * clearEditForm()
 * ---------------
 * Resets all edit form fields to empty / default values.
 * Called when no song is selected or data is freshly loaded.
 */
function clearEditForm() {
    /* Hide the editor form, show the empty state (#246). */
    var editorEmpty = document.getElementById('editorEmpty');
    var editorForm = document.getElementById('editorForm');
    if (editorEmpty) editorEmpty.style.display = '';
    if (editorForm) editorForm.style.display = 'none';

    /* Clear metadata fields. */
    setVal('edit-title', '');
    setVal('edit-number', '');
    setVal('edit-songbook', '');
    setVal('edit-ccli', '');
    setVal('edit-iswc', '');           /* #497 */
    setVal('edit-tune-name', '');      /* #497 */
    /* Default to the display name not the raw code (#489). */
    setVal('edit-lang-language', resolveLangName('en', 'language'));
    setVal('edit-lang-script', '');
    setVal('edit-lang-region', '');
    composeIetfTag();
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
    /* Generate a simple unique ID (timestamp + random suffix). */
    var newId = 'song-' + Date.now() + '-' + Math.random().toString(36).substring(2, 8);

    /* Build the blank song object with all required fields. */
    var newSong = {
        id: newId,
        title: 'New Song',
        number: songData.songs.length + 1,
        songbook: '',
        songbookName: '',
        language: 'en',
        ccli: '',
        iswc: '',           /* #497 */
        tuneName: '',       /* #497 */
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
        ]
    };

    /* Append to the songs array. */
    songData.songs.push(newSong);

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
    var song = findSongById(currentSongId);
    var title = song ? song.title : currentSongId;

    /* Ask for confirmation. */
    if (!confirm('Delete "' + title + '"? This cannot be undone.')) {
        return;
    }

    /* Remove the song from the array. */
    songData.songs = songData.songs.filter(function (s) {
        return s.id !== currentSongId;
    });

    /* Remove from modified set if present. */
    modifiedSongIds.delete(currentSongId);

    /* Clear the selection. */
    currentSongId = null;

    /* Refresh UI. */
    clearEditForm();
    renderSongList();
    updateStatusBar();

    /* Notify. */
    showToast('Song deleted.', 'info');
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
        /* Re-render the song list on every keystroke for instant filtering. */
        searchEl.addEventListener('input', function () {
            renderSongList();
        });
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

    /* ---- Load from file button ---- */
    var loadFileBtn = document.getElementById('btn-load-file');
    if (loadFileBtn) {
        loadFileBtn.addEventListener('click', function () {
            /* Create a temporary file input and trigger it. */
            var input = document.createElement('input');
            input.type = 'file';
            input.accept = '.json,application/json';
            input.addEventListener('change', function () {
                if (input.files && input.files[0]) {
                    loadSongsFromFile(input.files[0]);
                }
            });
            input.click();
        });
    }

    /* ---- Load from URL button ---- */
    var loadURLBtn = document.getElementById('btn-load-url');
    if (loadURLBtn) {
        loadURLBtn.addEventListener('click', function () {
            /* Prompt the user for a URL, pre-filling the default. */
            var url = prompt('Enter songs.json URL:', DEFAULT_SONGS_URL);
            if (url) {
                loadSongsFromURL(url);
            }
        });
    }

    /* ---- Save button — writes to MySQL, falls back to JSON download ---- */
    var saveBtn = document.getElementById('btn-save');
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            saveSongs();
        });
    }

    /* ---- Export JSON button (#235) ---- */
    var jsonExportBtn = document.getElementById('btn-export-json');
    if (jsonExportBtn) {
        jsonExportBtn.addEventListener('click', function () {
            exportJSON();
        });
    }

    /* ---- Export CSV button ---- */
    var csvBtn = document.getElementById('btn-export-csv');
    if (csvBtn) {
        csvBtn.addEventListener('click', function () {
            exportCSV();
        });
    }

    /* ---- Import / Merge JSON button ---- */
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

    /* ---- Add Component button ---- */
    var addCompBtn = document.getElementById('btn-add-component');
    if (addCompBtn) {
        addCompBtn.addEventListener('click', function () {
            addComponent();
        });
    }

    /* ---- Validate button (optional standalone trigger) ---- */
    var validateBtn = document.getElementById('btn-validate');
    if (validateBtn) {
        validateBtn.addEventListener('click', function () {
            var errors = validateSongData();
            if (errors.length === 0) {
                showToast('All songs are valid.', 'success');
            } else {
                errors.forEach(function (msg) {
                    showToast(msg, 'danger');
                });
            }
        });
    }

    /* ---- Bind metadata form field live-sync listeners ---- */
    bindMetadataListeners();
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

        /* Deep-link support (#407): /manage/editor/?song=<SongId> opens
           directly on that song when it exists in songData. Used by the
           Edit button on the public song view. */
        try {
            var sid = new URLSearchParams(window.location.search).get('song');
            if (sid && Array.isArray(songData.songs)
                && songData.songs.some(function (s) { return s.id === sid; })) {
                selectSong(sid);
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
    });

    /* Bulk Export (#399) — downloads the selected songs as JSON. */
    var exp = document.getElementById('btn-bulk-export');
    if (exp) exp.addEventListener('click', function () {
        var ids = Array.from(window._selectedIds || []);
        if (!ids.length) return;
        var idSet = new Set(ids);
        var subset = (songData.songs || []).filter(function (s) { return idSet.has(s.id); });
        downloadBlob(
            JSON.stringify({ songs: subset }, null, 2),
            'songs-export-' + new Date().toISOString().slice(0, 10) + '.json',
            'application/json'
        );
        showToast('Exported ' + subset.length + ' song(s) to JSON.', 'success');
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
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
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

function updateHistoryButtonState() {
    var btn = document.getElementById('btn-history');
    if (btn) btn.disabled = !currentSongId;
}

function openHistoryModal(songId) {
    var listEl = document.getElementById('history-list');
    var detailEl = document.getElementById('history-detail');
    var titleEl = document.getElementById('history-modal-title');
    if (!listEl || !detailEl) return;

    if (titleEl) titleEl.innerHTML = '<i class="bi bi-clock-history me-2"></i>Revision history — ' + songId;
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
            listEl.innerHTML = '<div class="text-danger p-3">Request failed: ' + err.message + '</div>';
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
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
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
