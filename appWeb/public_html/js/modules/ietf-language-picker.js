/**
 * IETF BCP 47 language picker (#681)
 *
 * One module, two surfaces: the songbook editor's create-form +
 * edit-modal, and the song editor's Metadata tab. Renders four
 * text inputs (Language, Script, Region, Variant) — each with its
 * own datalist — plus a live "IETF tag:" preview and a hidden
 * <input> that holds the composed tag for the form's existing
 * save handler. The variant subtag is optional and was added as a
 * follow-up to #681 once tblLanguageVariants (#738) shipped — see
 * the variant block in bootIetfLanguagePicker() below.
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
 *
 * #1907 (2026-08-25) — bootIetfLanguagePicker() no longer requires being
 * called AFTER the caller has attached `rootEl` to the live document. It
 * used to resolve each `<datalist>` via `document.getElementById(...)`
 * exactly ONCE, at boot, into closure-captured `const`s — which permanently
 * returned `null` for a picker booted on a still-detached subtree (two of
 * this module's dynamic callers do exactly that: enrichment-panel.js's
 * `buildIetfPicker()` and editor.js's `buildInlineIetfPicker()`), so no
 * suggestion EVER rendered for those callers, silently, forever. Every
 * datalist lookup is now re-resolved lazily, at the point it's actually
 * used (see the `resolveList()` helper and its doc-comment inside
 * `bootIetfLanguagePicker()` below), so the module self-heals the moment
 * the caller attaches the subtree — no boot-order contract for callers to
 * remember. Mutation-tested guard: tests/test-ietf-picker-detached-boot.js.
 */

import { apiFetch } from '../utils/api-client.js';

/* Debounce so we don't fire one fetch per keystroke. 200ms matches
   the affiliation typeahead (#670) and the editor's tag/credit
   searches — feels instant to a curator, coalesces typing bursts
   into a single request. */
const DEBOUNCE_MS = 200;

/* The endpoints that back the four inputs. The first three are on
   /manage/songbooks; the variant endpoint is on the public /api
   since the variant catalogue is small (~140 rows) and admins
   already use it from the editor surface. */
const LANG_URL    = '/api?action=languages';   // public — already exists
const SCRIPT_URL  = '/manage/songbooks?action=script_search';
const REGION_URL  = '/manage/songbooks?action=region_search';
const VARIANT_URL = '/api?action=variants';    // public — added with #738

/**
 * Tokenise a BCP 47 tag into its four subtags.
 * Examples:
 *   "en"                  → { lang: "en",  script: "",     region: "",   variants: [] }
 *   "pt-BR"               → { lang: "pt",  script: "",     region: "BR", variants: [] }
 *   "zh-Hans"             → { lang: "zh",  script: "Hans", region: "",   variants: [] }
 *   "zh-Hans-CN"          → { lang: "zh",  script: "Hans", region: "CN", variants: [] }
 *   "de-1996"             → { lang: "de",  script: "",     region: "",   variants: ["1996"] }
 *   "ca-ES-valencia"      → { lang: "ca",  script: "",     region: "ES", variants: ["valencia"] }
 *   "fr-CA-1694acad"      → { lang: "fr",  script: "",     region: "CA", variants: ["1694acad"] }
 *   "419"                 → invalid → falls through with lang=""
 *
 * The script subtag is uniquely 4 chars Title Case; the region
 * subtag is uniquely 2 chars upper or 3-digit. Variant subtags are
 * 5-8 alphanumeric chars OR 4 chars starting with a digit (e.g.
 * "1996"). Multiple variants are allowed per IANA grammar.
 * Extensions and private-use are still out of scope.
 */
export function decomposeTag(tag) {
    const parts = (tag || '').trim().split('-');
    if (!parts.length || !/^[a-z]{2,3}$/i.test(parts[0])) {
        return { lang: '', script: '', region: '', variants: [] };
    }
    let lang = parts[0].toLowerCase();
    let script = '';
    let region = '';
    const variants = [];
    for (let i = 1; i < parts.length; i++) {
        const p = parts[i];
        if (!script && !region && variants.length === 0 && /^[A-Za-z]{4}$/.test(p)) {
            /* Title-case the script subtag (Latn, Cyrl, …). Only
               recognise it before any variant has appeared so a
               4-char variant later in the tag (e.g. an alphanum
               variant) doesn't accidentally promote into Script. */
            script = p.charAt(0).toUpperCase() + p.slice(1).toLowerCase();
        } else if (!region && variants.length === 0 && (/^[A-Za-z]{2}$/.test(p) || /^[0-9]{3}$/.test(p))) {
            region = /^[0-9]+$/.test(p) ? p : p.toUpperCase();
        } else if (/^[a-zA-Z0-9]{5,8}$/.test(p) || /^[0-9][a-zA-Z0-9]{3}$/.test(p)) {
            /* Variant subtag — IANA grammar allows multiple. */
            variants.push(p.toLowerCase());
        }
    }
    return { lang, script, region, variants };
}

/**
 * Compose four subtags back into a BCP 47 tag. Empties drop out.
 * Variants accept either a single string or an array of strings;
 * empty / falsy entries are filtered out so callers can pass an
 * unparsed user input without pre-processing.
 *
 *   compose("en", "",     "GB",  "")          → "en-GB"
 *   compose("pt", "",     "BR",  "")          → "pt-BR"
 *   compose("zh", "Hans", "",    "")          → "zh-Hans"
 *   compose("de", "",     "",    "1996")      → "de-1996"
 *   compose("ca", "",     "ES",  "valencia")  → "ca-ES-valencia"
 *   compose("",   "Latn", "GB",  "")          → ""    (no language → no tag)
 */
export function composeTag(lang, script, region, variants) {
    if (!lang) return '';
    /* Normalise the variants argument so callers can pass either
       a string ("valencia"), a hyphen-joined string ("valencia-1901"),
       or an array (["valencia", "1901"]). */
    let variantList = [];
    if (Array.isArray(variants)) {
        variantList = variants;
    } else if (typeof variants === 'string' && variants.trim() !== '') {
        variantList = variants.trim().split(/[\s,-]+/);
    }
    variantList = variantList
        .map(v => (v || '').trim().toLowerCase())
        .filter(Boolean);

    return [
        lang.toLowerCase(),
        script ? script.charAt(0).toUpperCase() + script.slice(1).toLowerCase() : '',
        region ? (/^[0-9]+$/.test(region) ? region : region.toUpperCase()) : '',
        ...variantList,
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
        const r = await apiFetch(url, { credentials: 'same-origin' });
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

    const langInput    = rootEl.querySelector('.ietf-picker-language');
    const scriptInput  = rootEl.querySelector('.ietf-picker-script');
    const regionInput  = rootEl.querySelector('.ietf-picker-region');
    /* Variant input is optional in the markup — older callers that
       haven't bumped to the new partial don't render it, and the
       module degrades to the historical 3-input behaviour. */
    const variantInput = rootEl.querySelector('.ietf-picker-variant');
    const tagPreview   = rootEl.querySelector('.ietf-tag-preview');
    const tagDisplay   = rootEl.querySelector('.ietf-tag-display');
    const tagOutput    = rootEl.querySelector('.ietf-tag-output');

    if (!langInput || !scriptInput || !regionInput || !tagOutput) return null;

    /* ---- detached-boot fix (#1907, sibling of #1849) ---------------------
     * ELI5: don't go hunting for a subtag's suggestion box the INSTANT the
     * picker switches on — look it up fresh each time it's actually needed,
     * because the box might not be plugged into the page yet.
     *
     * Detail: `rootEl` is only a LIVE part of the document when the caller
     * has already attached it under <body> before calling this function.
     * Two of the four dynamic builders in this codebase instead build a
     * bare `document.createElement('div')`, call `bootIetfLanguagePicker()`
     * on it immediately, and attach it to the document only LATER — once a
     * curator opens the inline form: v1's `buildInlineIetfPicker()`
     * (manage/editor/editor.js, called from the per-line language/
     * translation inline forms) and v2's `buildIetfPicker()`
     * (manage/editor/v2/enrichment-panel.js:116-150, called from
     * `showLineLangForm()`/`showTranslationForm()`). Each subtag's
     * <datalist> lives INSIDE that same detached subtree, addressed by
     * `id=` — and `document.getElementById()` only ever searches the
     * document (MDN: "must be part of the document tree" —
     * https://developer.mozilla.org/en-US/docs/Web/API/Document/getElementById),
     * never a detached subtree. The PRE-#1907 code resolved each datalist
     * exactly once, right here at boot, into a `const`:
     *   `const langList = document.getElementById(langInput.getAttribute('list'));`
     * — on a detached `rootEl` that lookup permanently returns `null`, and
     * a `const` can never be reassigned once the element is later attached.
     * `rebuildDatalist(null, …)` then no-ops forever and `resolveCode()`
     * (which reads `datalistEl?.options`) always falls through to raw typed
     * text — so the picker LOOKS alive (the tag preview updates, Save
     * works) but no suggestion EVER renders, and a malformed tag like
     * "engli" saves unflagged. This is the owner's "no auto/live-search"
     * report. The exact same class was already diagnosed and fixed for ONE
     * site (the v2 Metadata tab) in #1849 — see the comment at
     * manage/editor/v2/metadata-tab.js:1563-1578, which predicted the
     * misread almost verbatim ("the picker works, it just never suggests
     * anything"). That fix reordered the ONE caller (boot the picker only
     * after `container.append()`); this fix instead makes the MODULE
     * itself immune to call order, so every caller — past, present, and
     * future — is covered without a boot-order contract to remember
     * (CLAUDE.md rule #34: derive the fix from the class of bug, not a
     * typed list of sites).
     *
     * The mechanism: `resolveList()` below does the SAME `getElementById`
     * lookup the old code did, but on EVERY call instead of once at boot.
     * The very first call made AFTER `rootEl` gets attached — in practice
     * the user's first `focus`/`input` on one of the visible text inputs,
     * since a detached element cannot receive real focus or typing in the
     * first place — now succeeds where a boot-time capture never could.
     * `setTag()` (used for saved-value pre-fill, and itself sometimes
     * invoked synchronously right after boot while still detached — see
     * enrichment-panel.js:142-144's `ctl.setTag(tag)`) routes through this
     * same helper, so a pre-attach prefill degrades no worse than before
     * (it shows the raw code instead of the friendly name until the next
     * lookup — cosmetic only) while every lookup made once the user is
     * actually interacting now works. Callers that already boot AFTER
     * attaching — `metadata-tab.js`'s #1849 fix, `songbooks.php`'s two
     * `document.querySelector(...)`-then-boot call sites, and
     * `editor/index.php`'s static `edit-song` picker (its markup comes
     * from the server-rendered `partials/ietf-language-picker.php`, so
     * it's already live when this module boots it) — are unaffected:
     * `resolveList()` finds the exact same element a boot-time
     * `getElementById` would have, just resolved a little later. */
    function resolveList(inputEl) {
        if (!inputEl) return null;
        const listId = inputEl.getAttribute('list');
        return listId ? document.getElementById(listId) : null;
    }

    const lookup = cachedLookup();

    /* The full languages list comes from /api?action=languages — no
       prefix typing needed since there are only ~14 active rows. */
    const loadLanguages = async () => {
        const data = await lookup('all-languages',
            () => fetchJson(LANG_URL));
        /* resolveList(langInput), not a captured `langList` — see the
           detached-boot comment above bootIetfLanguagePicker(). */
        rebuildDatalist(resolveList(langInput), data?.languages || [],
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
            rebuildDatalist(resolveList(scriptInput), data?.suggestions || [],
                'code', 'name', 'nativeName');
        }, DEBOUNCE_MS);
    };

    let regionTimer = null;
    const lookupRegions = (q) => {
        clearTimeout(regionTimer);
        regionTimer = setTimeout(async () => {
            const url = `${REGION_URL}&q=${encodeURIComponent(q)}&limit=20`;
            const data = await fetchJson(url);
            rebuildDatalist(resolveList(regionInput), data?.suggestions || [],
                'code', 'name');
        }, DEBOUNCE_MS);
    };

    /* Variants — the public /api?action=variants endpoint returns
       the full active list (small, ~140 rows on the IANA registry)
       so we load once on first focus and don't re-fetch per
       keystroke. Same shape as loadLanguages above. */
    const loadVariants = async () => {
        /* No `!variantList` early-out any more — a detached picker's
           datalist can't be resolved yet, but the fetch is cheap
           (cachedLookup() dedups it) and rebuildDatalist() itself
           already no-ops safely on a null element (see its own
           `if (!datalistEl) return;`), so there is nothing to guard
           against here beyond "does this picker even have a variant
           input at all". */
        if (!variantInput) return;
        const data = await lookup('all-variants',
            () => fetchJson(VARIANT_URL));
        rebuildDatalist(resolveList(variantInput), data?.variants || [],
            'code', 'name');
    };

    /* Compose a human-readable display from whatever's typed into
       the four inputs. The values ARE the human names (the
       datalist <option value="..."> stores the friendly name and
       data-code holds the code). So the input.value IS the display
       string for that subtag — we just compose them with the
       right punctuation. (#738) */
    const composeHumanDisplay = () => {
        const lang    = (langInput.value    || '').trim();
        const script  = (scriptInput.value  || '').trim();
        const region  = (regionInput.value  || '').trim();
        const variant = (variantInput?.value || '').trim();
        if (!lang) return '';
        const qualifiers = [script, region, variant].filter(Boolean);
        if (qualifiers.length === 0) return lang;
        return `${lang} (${qualifiers.join(', ')})`;
    };

    /* Update the live preview + hidden form field whenever any of
       the four inputs change. Reads the canonical code from the
       datalist's selected <option>; falls through to typed text. */
    const refreshTag = () => {
        /* resolveList(...) per input, freshly, rather than the three/four
           closure-captured consts the pre-#1907 code used — see the
           detached-boot comment above bootIetfLanguagePicker(). */
        const langCode    = resolveCode(langInput,    resolveList(langInput));
        const scriptCode  = resolveCode(scriptInput,  resolveList(scriptInput));
        const regionCode  = resolveCode(regionInput,  resolveList(regionInput));
        const variantCode = variantInput
            ? resolveCode(variantInput, resolveList(variantInput))
            : '';
        const tag = composeTag(langCode, scriptCode, regionCode, variantCode);
        tagOutput.value = tag;
        if (tagPreview) tagPreview.textContent = tag || '—';
        /* Human-readable composed form: "Spanish (Mexico)" /
           "Chinese (Simplified, China)" / "Catalan (Spain, valencia)".
           Empty when no language is selected. (#738) */
        if (tagDisplay) {
            const human = composeHumanDisplay();
            tagDisplay.textContent = human ? `→ ${human}` : '';
        }
    };

    /* Wire input + blur events on every input so the preview tracks
       typing AND the eventual canonical-code resolution after the
       user picks from the datalist (which fires `input` not
       `change`). */
    [langInput, scriptInput, regionInput, variantInput]
        .filter(Boolean)
        .forEach(input => {
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
    if (variantInput) {
        variantInput.addEventListener('focus', loadVariants, { once: true });
    }

    /**
     * Decompose a saved BCP 47 tag and pre-fill the inputs. Used
     * by openEditModal when the user clicks Edit on a row — the
     * partial is shared between rows in the modal.
     */
    const setTag = async (tag) => {
        const { lang, script, region, variants } = decomposeTag(tag);

        /* Preload the languages list so we can resolve the code →
           name BEFORE the user opens the dropdown. */
        await loadLanguages();

        /* Resolve language code → name. The languages endpoint
           returns the full list, so we match against the datalist
           options we just built. */
        const langName = (() => {
            if (!lang) return '';
            /* resolveList(langInput) — if setTag() runs before rootEl is
               attached (enrichment-panel.js's buildIetfPicker() does this),
               this returns null and falls through to the raw `lang` code
               below, same as the pre-#1907 behaviour; the difference is
               that EVERY OTHER lookup in this module now self-heals once
               attachment happens, instead of staying broken forever. */
            const opt = Array.from(resolveList(langInput)?.options || []).find(
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
            rebuildDatalist(resolveList(scriptInput), data?.suggestions || [],
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
            rebuildDatalist(resolveList(regionInput), data?.suggestions || [], 'code', 'name');
        } else {
            regionInput.value = '';
        }

        /* Variants: load the full list once so the typeahead is
           populated; pre-fill the input with the first variant's
           friendly name (or the raw code if the list misses). The UI
           is single-input so we surface only the first variant — IANA
           grammar allows multiples but they're vanishingly rare and a
           single-input keeps the form compact. The hidden tagOutput
           still preserves any extra variants from the saved tag via
           refreshTag()'s call to composeTag(), which accepts an array. */
        if (variantInput) {
            if (variants.length > 0) {
                await loadVariants();
                const first = variants[0];
                const opt = Array.from(resolveList(variantInput)?.options || []).find(
                    o => (o.dataset.code || '').toLowerCase() === first
                );
                variantInput.value = opt ? opt.value : first;
            } else {
                variantInput.value = '';
            }
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
