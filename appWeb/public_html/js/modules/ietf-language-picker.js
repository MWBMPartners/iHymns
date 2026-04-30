/**
 * IETF BCP 47 language picker (#681)
 *
 * One module, two surfaces: the songbook editor's create-form +
 * edit-modal, and the song editor's Metadata tab. Renders three
 * text inputs (Language, Script, Region) — each with its own
 * datalist — plus a live "IETF tag:" preview and a hidden
 * <input> that holds the composed tag for the form's existing
 * save handler.
 *
 * The picker degrades gracefully:
 *   - If the typeahead endpoints fail, the user can still type a
 *     valid value into each input directly; the compose still
 *     fires on blur.
 *   - If the saved value doesn't decompose cleanly (legacy
 *     ISO 639-1 like "en" — already a valid BCP 47 tag) the
 *     Language input gets the language name and the other two
 *     stay empty.
 *
 * Markup contract — caller renders something like:
 *
 *   <div class="ietf-picker" data-ietf-picker-id="edit">
 *     <input class="ietf-picker-language" list="ietf-lang-list-edit">
 *     <input class="ietf-picker-script"   list="ietf-script-list-edit">
 *     <input class="ietf-picker-region"   list="ietf-region-list-edit">
 *     <code class="ietf-tag-preview">…</code>
 *     <input type="hidden" class="ietf-tag-output" name="language">
 *     <datalist id="ietf-lang-list-edit"></datalist>
 *     <datalist id="ietf-script-list-edit"></datalist>
 *     <datalist id="ietf-region-list-edit"></datalist>
 *   </div>
 */

/* Debounce so we don't fire one fetch per keystroke. 200ms matches
   the affiliation typeahead (#670) and the editor's tag/credit
   searches — feels instant to a curator, coalesces typing bursts
   into a single request. */
const DEBOUNCE_MS = 200;

/* The endpoints that back the three inputs. All on /manage/songbooks
   today; the editor surface (B6) reuses the same endpoints rather
   than duplicating onto /manage/editor/api.php. */
const LANG_URL   = '/api?action=languages';   // public — already exists
const SCRIPT_URL = '/manage/songbooks?action=script_search';
const REGION_URL = '/manage/songbooks?action=region_search';

/**
 * Tokenise a BCP 47 tag into its three subtags.
 * Examples:
 *   "en"          → { lang: "en",  script: "",     region: "" }
 *   "pt-BR"       → { lang: "pt",  script: "",     region: "BR" }
 *   "zh-Hans"     → { lang: "zh",  script: "Hans", region: "" }
 *   "zh-Hans-CN"  → { lang: "zh",  script: "Hans", region: "CN" }
 *   "419"         → invalid → falls through with lang=""
 *
 * The script subtag is uniquely 4 chars Title Case; the region
 * subtag is uniquely 2 chars upper or 3-digit. Anything else after
 * the language is ignored for v1 (variants, extensions, private-use
 * — out of scope per #681).
 */
export function decomposeTag(tag) {
    const parts = (tag || '').trim().split('-');
    if (!parts.length || !/^[a-z]{2,3}$/i.test(parts[0])) {
        return { lang: '', script: '', region: '' };
    }
    let lang = parts[0].toLowerCase();
    let script = '';
    let region = '';
    for (let i = 1; i < parts.length; i++) {
        const p = parts[i];
        if (!script && /^[A-Za-z]{4}$/.test(p)) {
            /* Title-case the script subtag (Latn, Cyrl, …). */
            script = p.charAt(0).toUpperCase() + p.slice(1).toLowerCase();
        } else if (!region && (/^[A-Za-z]{2}$/.test(p) || /^[0-9]{3}$/.test(p))) {
            region = /^[0-9]+$/.test(p) ? p : p.toUpperCase();
        }
    }
    return { lang, script, region };
}

/**
 * Compose three subtags back into a BCP 47 tag. Empties drop out:
 *   compose("en", "",     "GB")  → "en-GB"
 *   compose("pt", "",     "BR")  → "pt-BR"
 *   compose("zh", "Hans", "")    → "zh-Hans"
 *   compose("",   "Latn", "GB")  → ""    (no language → no tag)
 */
export function composeTag(lang, script, region) {
    if (!lang) return '';
    return [
        lang.toLowerCase(),
        script ? script.charAt(0).toUpperCase() + script.slice(1).toLowerCase() : '',
        region ? (/^[0-9]+$/.test(region) ? region : region.toUpperCase()) : '',
    ].filter(Boolean).join('-');
}

/* ---------------------------------------------------------------------------
 * Internal helpers
 * --------------------------------------------------------------------------- */

/* Cache the resolved name → code lookups per picker instance so a
   curator who picks "Latin" twice doesn't trigger two fetches. */
function cachedLookup() {
    const cache = new Map();
    return async (key, fetcher) => {
        if (cache.has(key)) return cache.get(key);
        const value = await fetcher();
        cache.set(key, value);
        return value;
    };
}

/* Generic JSON fetch with same-origin credentials. Returns [] on any
   error so the typeahead silently degrades to "no suggestions" rather
   than spamming the console. */
async function fetchJson(url) {
    try {
        const r = await fetch(url, { credentials: 'same-origin' });
        if (!r.ok) return null;
        return await r.json();
    } catch (_e) {
        return null;
    }
}

/* Rebuild a datalist from a list of {code, name} suggestions. The
   <option> value is what the input gets; we use the human Name
   so the input shows "United Kingdom" not "GB". The Code is
   tucked into the option's data-code so the compose step can
   resolve it back. */
function rebuildDatalist(datalistEl, suggestions, codeKey, nameKey, nativeKey) {
    if (!datalistEl) return;
    datalistEl.innerHTML = (suggestions || []).map(s => {
        const code = (s[codeKey] || '').replace(/"/g, '&quot;');
        const name = (s[nameKey] || '').replace(/"/g, '&quot;');
        const native = (nativeKey && s[nativeKey] && s[nativeKey] !== s[nameKey])
            ? ` (${s[nativeKey].replace(/"/g, '&quot;')})`
            : '';
        return `<option value="${name}" data-code="${code}" label="${name}${native} — ${code}"></option>`;
    }).join('');
}

/* Resolve the input's typed value back to its canonical code by
   matching against the current datalist's <option> elements. If
   the user typed a name that isn't in the list, we fall through to
   the typed text (so a freshly-added language/script/region the
   typeahead hasn't surfaced yet still composes into a sensible
   tag — they may have to type the canonical code directly). */
function resolveCode(inputEl, datalistEl) {
    const typed = (inputEl.value || '').trim();
    if (!typed) return '';
    const opt = Array.from(datalistEl?.options || []).find(
        o => o.value.toLowerCase() === typed.toLowerCase()
    );
    return opt ? (opt.dataset.code || opt.value) : typed;
}

/* ---------------------------------------------------------------------------
 * Public API
 * --------------------------------------------------------------------------- */

/**
 * Boot one picker instance. Rebinds the inputs' input/blur events,
 * loads the initial datalist contents, and exposes a small object
 * with helpers the host page can call.
 *
 * Returns:
 *   {
 *     setTag(tag): replace the inputs to match a new saved tag
 *     getTag():    read the currently composed tag
 *   }
 */
export function bootIetfLanguagePicker(rootEl) {
    if (!rootEl || rootEl.dataset.ietfPickerBooted === '1') return null;
    rootEl.dataset.ietfPickerBooted = '1';

    const langInput   = rootEl.querySelector('.ietf-picker-language');
    const scriptInput = rootEl.querySelector('.ietf-picker-script');
    const regionInput = rootEl.querySelector('.ietf-picker-region');
    const tagPreview  = rootEl.querySelector('.ietf-tag-preview');
    const tagOutput   = rootEl.querySelector('.ietf-tag-output');
    const langList    = document.getElementById(langInput?.getAttribute('list'));
    const scriptList  = document.getElementById(scriptInput?.getAttribute('list'));
    const regionList  = document.getElementById(regionInput?.getAttribute('list'));

    if (!langInput || !scriptInput || !regionInput || !tagOutput) return null;

    const lookup = cachedLookup();

    /* The full languages list comes from /api?action=languages — no
       prefix typing needed since there are only ~14 active rows. */
    const loadLanguages = async () => {
        const data = await lookup('all-languages',
            () => fetchJson(LANG_URL));
        rebuildDatalist(langList, data?.languages || [],
            'code', 'name', 'nativeName');
    };

    /* Scripts + regions DO use prefix typing — the lists are bigger
       (28 + 255 entries). */
    let scriptTimer = null;
    const lookupScripts = (q) => {
        clearTimeout(scriptTimer);
        scriptTimer = setTimeout(async () => {
            const url = `${SCRIPT_URL}&q=${encodeURIComponent(q)}&limit=20`;
            const data = await fetchJson(url);
            rebuildDatalist(scriptList, data?.suggestions || [],
                'code', 'name', 'nativeName');
        }, DEBOUNCE_MS);
    };

    let regionTimer = null;
    const lookupRegions = (q) => {
        clearTimeout(regionTimer);
        regionTimer = setTimeout(async () => {
            const url = `${REGION_URL}&q=${encodeURIComponent(q)}&limit=20`;
            const data = await fetchJson(url);
            rebuildDatalist(regionList, data?.suggestions || [],
                'code', 'name');
        }, DEBOUNCE_MS);
    };

    /* Update the live preview + hidden form field whenever any of
       the three inputs change. Reads the canonical code from the
       datalist's selected <option>; falls through to typed text. */
    const refreshTag = () => {
        const langCode   = resolveCode(langInput,   langList);
        const scriptCode = resolveCode(scriptInput, scriptList);
        const regionCode = resolveCode(regionInput, regionList);
        const tag = composeTag(langCode, scriptCode, regionCode);
        tagOutput.value = tag;
        if (tagPreview) tagPreview.textContent = tag || '—';
    };

    /* Wire input + blur events on every input so the preview tracks
       typing AND the eventual canonical-code resolution after the
       user picks from the datalist (which fires `input` not
       `change`). */
    [langInput, scriptInput, regionInput].forEach(input => {
        input.addEventListener('input', refreshTag);
        input.addEventListener('blur',  refreshTag);
    });

    /* Lazy-load each list on first focus so opening a row that
       doesn't exercise the picker doesn't pay the network cost. */
    langInput.addEventListener('focus', loadLanguages, { once: true });
    scriptInput.addEventListener('input', () => {
        if (scriptInput.value.trim()) lookupScripts(scriptInput.value.trim());
    });
    regionInput.addEventListener('input', () => {
        if (regionInput.value.trim()) lookupRegions(regionInput.value.trim());
    });

    /**
     * Decompose a saved BCP 47 tag and pre-fill the inputs. Used
     * by openEditModal when the user clicks Edit on a row — the
     * partial is shared between rows in the modal.
     */
    const setTag = async (tag) => {
        const { lang, script, region } = decomposeTag(tag);

        /* Preload the languages list so we can resolve the code →
           name BEFORE the user opens the dropdown. */
        await loadLanguages();

        /* Resolve language code → name. The languages endpoint
           returns the full list, so we match against the datalist
           options we just built. */
        const langName = (() => {
            if (!lang) return '';
            const opt = Array.from(langList?.options || []).find(
                o => (o.dataset.code || '').toLowerCase() === lang.toLowerCase()
            );
            return opt ? opt.value : lang;
        })();
        langInput.value = langName;

        /* Script: load the matching row by exact code (limit=1) so
           we get the friendly name. Empty if no script subtag. */
        if (script) {
            const data = await fetchJson(
                `${SCRIPT_URL}&q=${encodeURIComponent(script)}&limit=10`
            );
            const match = (data?.suggestions || []).find(
                s => (s.code || '').toLowerCase() === script.toLowerCase()
            );
            scriptInput.value = match ? match.name : script;
            rebuildDatalist(scriptList, data?.suggestions || [],
                'code', 'name', 'nativeName');
        } else {
            scriptInput.value = '';
        }

        /* Region: same pattern. */
        if (region) {
            const data = await fetchJson(
                `${REGION_URL}&q=${encodeURIComponent(region)}&limit=10`
            );
            const match = (data?.suggestions || []).find(
                s => (s.code || '').toLowerCase() === region.toLowerCase()
            );
            regionInput.value = match ? match.name : region;
            rebuildDatalist(regionList, data?.suggestions || [], 'code', 'name');
        } else {
            regionInput.value = '';
        }

        refreshTag();
    };

    /* Pre-populate from the data-initial-tag attribute (server-side
       could not resolve the human names without paying the lookup
       cost; cleaner to hand off via a single attribute and let the
       JS resolve). */
    const initial = rootEl.dataset.initialTag || '';
    if (initial) {
        setTag(initial).catch(() => { /* swallow — degrade gracefully */ });
    } else {
        refreshTag();
    }

    return {
        setTag,
        getTag: () => tagOutput.value,
    };
}
