/**
 * iHymns Song Editor — Developer Tool
 * ====================================
 * Plain JavaScript (no ES modules). Relies on Bootstrap 5.3 loaded globally via CDN.
 *
 * Copyright (c) 2026 MWBM Partners Ltd. All rights reserved.
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

/** Default relative URL used for auto-loading the song data file. */
var DEFAULT_SONGS_URL = '../../public_html_beta/data/songs.json';

/** ID of the song currently loaded into the edit form (null when nothing selected). */
var currentSongId = null;

/** Set of song IDs that have been modified since last save. */
var modifiedSongIds = new Set();

/** Timestamp (Date object) of the most recent save/download action, or null. */
var lastSaveTime = null;

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
    /* Use the default URL if none is supplied. */
    var target = url || DEFAULT_SONGS_URL;

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
 * saveSongsToFile()
 * -----------------
 * Serialises the current `songData` to a pretty-printed JSON string and triggers
 * a browser download of the resulting file as "songs.json".
 */
function saveSongsToFile() {
    /* Run validation first; abort if the data is not valid. */
    var errors = validateSongData();
    if (errors.length > 0) {
        /* Show each validation error as a toast notification. */
        errors.forEach(function (msg) {
            showToast(msg, 'danger');
        });
        return; // do not proceed with save
    }

    /* Update the generatedAt timestamp so consumers know when data was last built. */
    songData.meta.generatedAt = new Date().toISOString();

    /* Serialise with 2-space indentation for human readability. */
    var jsonString = JSON.stringify(songData, null, 2);

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
    showToast('songs.json downloaded.', 'success');
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

    /* Iterate over every song in the data set. */
    songData.songs.forEach(function (song) {
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

        /* Highlight the currently selected song. */
        if (song.id === currentSongId) {
            li.classList.add('active');
        }

        /* Build the display text: "number — title". */
        var label = document.createElement('span');
        label.textContent = (song.number || '?') + ' \u2014 ' + (song.title || 'Untitled');

        /* Append a small badge if the song has been modified. */
        var badges = document.createElement('span');
        if (modifiedSongIds.has(song.id)) {
            var badge = document.createElement('span');
            badge.className = 'badge bg-warning text-dark ms-2';
            badge.textContent = 'modified';
            badges.appendChild(badge);
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

    /* Populate the metadata fields. */
    setVal('edit-title', song.title || '');
    setVal('edit-number', song.number || '');
    setVal('edit-songbook', song.songbook || '');
    setVal('edit-ccli', song.ccli || '');

    /* Render the structure / arrangement components. */
    renderComponents(song);

    /* Render the writers list. */
    renderWriters(song);

    /* Render the composers list. */
    renderComposers(song);

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
    /* List of field IDs mapped to their corresponding song-object keys. */
    var fields = [
        { elId: 'edit-title',    key: 'title' },
        { elId: 'edit-number',   key: 'number' },
        { elId: 'edit-songbook', key: 'songbook' },
        { elId: 'edit-ccli',     key: 'ccli' },
        { elId: 'edit-copyright', key: 'copyright' }
    ];

    /* Attach a listener to each field. */
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

                /* Mark this song as modified. */
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
    'refrain',
    'bridge',
    'pre-chorus',
    'tag',
    'coda',
    'intro',
    'outro',
    'interlude'
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
    var container = document.getElementById('components-container');
    if (!container) return;

    /* Clear any existing cards. */
    container.innerHTML = '';

    /* Ensure the song has a components array. */
    if (!song.components) {
        song.components = [];
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
            renderPreview(song); // refresh preview
        });

        /* Number input (e.g. verse 1, verse 2). */
        var numInput = document.createElement('input');
        numInput.type = 'number';
        numInput.className = 'form-control form-control-sm';
        numInput.style.width = '80px';
        numInput.placeholder = '#';
        numInput.value = comp.number != null ? comp.number : '';
        /* Live-bind number changes. */
        numInput.addEventListener('input', function () {
            comp.number = numInput.value ? parseInt(numInput.value, 10) : null;
            markModified(song.id);
            renderPreview(song);
        });

        /* Label for clarity. */
        var typeLabel = document.createElement('span');
        typeLabel.className = 'fw-semibold me-auto';
        typeLabel.textContent = 'Component ' + (index + 1);

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
        textarea.rows = 4;
        textarea.placeholder = 'Enter lyrics here...';
        textarea.value = comp.lyrics || '';
        /* Auto-resize: adjust height to content. */
        autoResizeTextarea(textarea);
        /* Live-bind lyrics changes. */
        textarea.addEventListener('input', function () {
            comp.lyrics = textarea.value;
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
        lyrics: ''       // empty lyrics
    });

    /* Mark as modified. */
    markModified(song.id);

    /* Re-render the component cards and preview. */
    renderComponents(song);
    renderPreview(song);
}

/**
 * removeComponent(song, index)
 * ----------------------------
 * Removes the component at the given index after user confirmation.
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

    /* Mark as modified. */
    markModified(song.id);

    /* Re-render. */
    renderComponents(song);
    renderPreview(song);
}

/**
 * moveComponent(song, index, direction)
 * -------------------------------------
 * Swaps the component at `index` with its neighbour in the given direction.
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

    /* Mark as modified. */
    markModified(song.id);

    /* Re-render so the new order is visible. */
    renderComponents(song);
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
function renderWriters(song) {
    /* Grab the container. */
    var container = document.getElementById('writers-container');
    if (!container) return;

    /* Clear previous content. */
    container.innerHTML = '';

    /* Ensure the song has a writers array. */
    if (!song.writers) song.writers = [];

    /* Render one input per writer. */
    song.writers.forEach(function (writer, i) {
        var row = createDynamicInputRow(
            writer,                              // current value
            function (newVal) {                   // on change callback
                song.writers[i] = newVal;
                markModified(song.id);
            },
            function () {                         // on remove callback
                song.writers.splice(i, 1);
                markModified(song.id);
                renderWriters(song);              // re-render the list
            }
        );
        container.appendChild(row);
    });

    /* "Add Writer" button. */
    var addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'btn btn-sm btn-outline-primary mt-2';
    addBtn.textContent = '+ Add Writer';
    addBtn.addEventListener('click', function () {
        song.writers.push('');       // add an empty entry
        markModified(song.id);
        renderWriters(song);         // re-render
    });
    container.appendChild(addBtn);
}

/**
 * renderComposers(song)
 * ---------------------
 * Same pattern as renderWriters but for the composers array.
 *
 * @param {Object} song - The song object.
 */
function renderComposers(song) {
    /* Grab the container. */
    var container = document.getElementById('composers-container');
    if (!container) return;

    /* Clear previous content. */
    container.innerHTML = '';

    /* Ensure the song has a composers array. */
    if (!song.composers) song.composers = [];

    /* Render one input per composer. */
    song.composers.forEach(function (composer, i) {
        var row = createDynamicInputRow(
            composer,                             // current value
            function (newVal) {                   // on change callback
                song.composers[i] = newVal;
                markModified(song.id);
            },
            function () {                         // on remove callback
                song.composers.splice(i, 1);
                markModified(song.id);
                renderComposers(song);            // re-render
            }
        );
        container.appendChild(row);
    });

    /* "Add Composer" button. */
    var addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'btn btn-sm btn-outline-primary mt-2';
    addBtn.textContent = '+ Add Composer';
    addBtn.addEventListener('click', function () {
        song.composers.push('');     // add an empty entry
        markModified(song.id);
        renderComposers(song);       // re-render
    });
    container.appendChild(addBtn);
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
function createDynamicInputRow(value, onChange, onRemove) {
    /* Wrapper div styled as a Bootstrap input group. */
    var row = document.createElement('div');
    row.className = 'input-group input-group-sm mb-1';

    /* Text input. */
    var input = document.createElement('input');
    input.type = 'text';
    input.className = 'form-control';
    input.value = value;
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

    return row;
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

    /* Render each component. */
    (song.components || []).forEach(function (comp) {
        /* Component label, e.g. "Verse 1" or "Chorus". */
        var heading = document.createElement('h6');
        heading.className = 'mt-3 mb-1 fw-bold text-uppercase small';
        var headingText = comp.type || 'Section';
        if (comp.number != null) {
            headingText += ' ' + comp.number;
        }
        heading.textContent = headingText;

        /* Lyrics block. */
        var lyricsEl = document.createElement('pre');
        lyricsEl.className = 'mb-2';
        lyricsEl.style.whiteSpace = 'pre-wrap';
        lyricsEl.style.fontFamily = 'inherit';
        lyricsEl.textContent = comp.lyrics || '';

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
        writersEl.textContent = 'Writers: ' + song.writers.join(', ');
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
 * clearEditForm()
 * ---------------
 * Resets all edit form fields to empty / default values.
 * Called when no song is selected or data is freshly loaded.
 */
function clearEditForm() {
    /* Clear metadata fields. */
    setVal('edit-title', '');
    setVal('edit-number', '');
    setVal('edit-songbook', '');
    setVal('edit-ccli', '');
    setVal('edit-copyright', '');

    /* Clear components container. */
    var compContainer = document.getElementById('components-container');
    if (compContainer) compContainer.innerHTML = '';

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
        ccli: '',
        copyright: '',
        writers: [],
        composers: [],
        components: [
            {
                type: 'verse',
                number: 1,
                lyrics: ''
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

    /* ---- Save / Export JSON button ---- */
    var saveBtn = document.getElementById('btn-save');
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            saveSongsToFile();
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
    });
}

/* ---- Kick everything off once the DOM is ready ---- */
document.addEventListener('DOMContentLoaded', init);
