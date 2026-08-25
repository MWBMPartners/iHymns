/**
 * IETF BCP 47 language picker (#681, live-search rework: BCP 47 registry
 * plan §4, M2/M3)
 *
 * One module, four surfaces: the songbook editor's create-form + edit-modal,
 * the v1 song editor's Metadata tab + per-line inline pickers, and the v2
 * editor's Metadata tab + enrichment panel. Renders four text inputs
 * (Language, Script, Region, Variant) — each backed by a LIVE-SEARCH
 * suggestion panel, never a `<datalist>` — plus a live "IETF tag:" preview
 * and a hidden `<input>` that holds the composed tag for the caller's save
 * flow. The variant subtag is optional (added once `tblLanguageVariants`,
 * #738, shipped).
 *
 * #1907 (2026-08-25) HISTORY — datalists, then live-search:
 * -----------------------------------------------------------------------
 * This module used to render a `<datalist>` per subtag and resolve it via
 * `document.getElementById(input.list)`. Two of this module's FOUR dynamic
 * callers boot on a still-DETACHED subtree (`enrichment-panel.js`'s
 * `buildIetfPicker()`, `editor.js`'s `buildInlineIetfPicker()`) —
 * `getElementById()` only ever searches the LIVE document (MDN: "must be
 * part of the document tree"), so a `<datalist>` inside a detached
 * `document.createElement()` subtree was invisible to it. Resolving that
 * lookup ONCE, at boot, into a closure-captured `const` meant those two
 * callers' suggestion lists were permanently `null` — the picker LOOKED
 * alive (typing worked, Save worked) but no suggestion EVER rendered. A
 * same-day fix made every datalist lookup lazy (re-resolved at USE time
 * instead of boot time), which papered over the symptom.
 *
 * The BCP 47 registry plan (`.claude/bcp47-language-registry-plan.md` §4)
 * went further and removed the ROOT CAUSE instead: `<datalist>` itself.
 * Two structural problems with it, even lazily-resolved: (1) the FULL
 * `action=languages` dump was ~8,273 rows post-#738 — building an
 * 8,273-`<option>` datalist per picker instance (up to 4 live at once on an
 * enrichment card) is real DOM cost for zero benefit over server-side
 * search; (2) a native `<datalist>` has no ARIA semantics, no loading
 * state, no "nothing matched" signal, and no reliable commit hook — the
 * in-file precedent (script/region already used server PREFIX search
 * rather than a bulk datalist) was the more scalable shape all along, just
 * not applied to every subtag.
 *
 * So every subtag input is now wired via the SHARED live-search module,
 * `window.iHymnsPlaceSearch.attach()` (`js/modules/place-search.js`,
 * generalised for exactly this kind of "registry lookup, not a place" case
 * by `pickMode:'value'` — CLAUDE.md rule #43: reuse the ONE typeahead, never
 * fork a second one), backed by four new public search actions
 * (`?action=language_search|script_search|region_search|variant_search` on
 * `/api` — `includes/language_names.php`'s `bcp47SubtagSearch()`). This
 * ALSO fixes the detached-boot bug at its structural root: `attach()` never
 * calls `getElementById()` at all — it builds its OWN suggestion panel and
 * appends it straight to `document.body` (always live), and wires listeners
 * directly onto the input element itself (which works whether or not that
 * element's own subtree is attached yet). Belt-and-braces on top of that
 * structural fix: every `iHymnsPlaceSearch.attach()` call is still made
 * lazily, inside a one-time `focusin` listener on `rootEl` (see
 * `bindLiveSearch()` below) rather than at boot — a real user interaction
 * (focus) always happens strictly after the caller has attached the
 * picker's markup to the document (a detached element cannot receive
 * focus), so this also sidesteps any script-load-order race against
 * `place-search.js`'s classic `<script>` tag finishing execution.
 * Mutation-tested guard: tests/test-ietf-picker-live-dom.js (no top-level
 * `document.getElementById` capture, no `<datalist>` fingerprint anywhere
 * in this module or its consumer files).
 *
 * Free text remains allowed (CLAUDE.md rule #21 — a script subtag must
 * never fail ingest) but is never SILENT: `updateUnknownWarning()` below
 * renders an inline amber note the moment a subtag was neither picked nor
 * matched exactly against the last search results, and additionally warns
 * BEFORE the server ever rejects a grammatically-malformed tag (mirroring
 * `includes/song_importers.php`'s `_ietfBcp47Validate()` regex — see
 * `isGrammaticallyValidBcp47()` below; kept byte-identical to the PHP
 * source by `tests/test-ietf-picker-live-dom.js`, never just a "keep these
 * in sync" comment, rule #35).
 *
 * Markup contract — caller renders something like:
 *
 *   <div class="ietf-picker" data-ietf-picker-id="edit" data-initial-tag="pt-BR">
 *     <input class="ietf-picker-language">
 *     <input type="hidden" class="ietf-picker-language-code">
 *     <input class="ietf-picker-script">
 *     <input type="hidden" class="ietf-picker-script-code">
 *     <input class="ietf-picker-region">
 *     <input type="hidden" class="ietf-picker-region-code">
 *     <input class="ietf-picker-variant">              <!-- optional -->
 *     <input type="hidden" class="ietf-picker-variant-code"> <!-- optional -->
 *     <code class="ietf-tag-preview">…</code>
 *     <span class="ietf-tag-display"></span>
 *     <input type="hidden" class="ietf-tag-output" name="language">
 *     <div class="ietf-picker-unknown-warning form-text d-none"></div>
 *   </div>
 *
 * The `-code` hidden siblings hold the CANONICAL registry code once a
 * suggestion is picked (place-search.js's `hiddenIdInput` contract); they
 * are cleared automatically the moment the visible input is hand-edited
 * (free-typing), which is exactly the "is this subtag a real pick, or did
 * the curator just type something?" signal `resolveCode()` below needs. No
 * `<datalist>` elements and no `list="…"` attribute anywhere in this
 * contract any more.
 */

import { apiFetch } from '../utils/api-client.js';

/* Debounce so we don't fire one fetch per keystroke — 200ms matches the
   picker's own long-documented parity with the affiliation typeahead
   (#670) and the editor's tag/credit searches, and rule #43's own worked
   example. Subtags are short (2-4 chars), so 2 is the natural minChars
   floor — "en"/"es"/"de" are already complete ISO 639-1 codes. */
const DEBOUNCE_MS = 200;
const MIN_CHARS   = 2;

/* One search action per subtag kind, all on the SAME public /api endpoint
   (BCP 47 registry plan §4.3) — bcp47SubtagSearch()'s ONE shared core in
   includes/language_names.php serves all four. Never the admin-only
   /manage/songbooks?action=script_search|region_search endpoints any
   more — those stay live as ALIASES for whoever else still links to them
   (rule #33 — links outlive code), but this picker no longer depends on
   an admin URL from editor surfaces that aren't always admin-authenticated
   the same way.
   Deliberately FOUR LITERAL query-string builders (one `limit` parameter,
   reused by both the live typeahead and setTag()'s exact-code lookup
   below), never one built by string-concatenating `kind` into the action
   name — tests/test-bcp47-search-endpoints.js greps the tree for literal
   `action=<name>` occurrences (rule #34: a guard can only verify what
   actually appears in SOURCE TEXT), so a dynamically-built action name
   here would be invisible to it and silently uncheckable. */
const SEARCH_URL_BUILDER = {
    language: (q, limit) => '/api?action=language_search&q=' + encodeURIComponent(q) + '&limit=' + limit,
    script:   (q, limit) => '/api?action=script_search&q='   + encodeURIComponent(q) + '&limit=' + limit,
    region:   (q, limit) => '/api?action=region_search&q='   + encodeURIComponent(q) + '&limit=' + limit,
    variant:  (q, limit) => '/api?action=variant_search&q='  + encodeURIComponent(q) + '&limit=' + limit,
};
function searchUrlFor(kind) {
    return (q) => SEARCH_URL_BUILDER[kind](q, 12);
}

/* Deliberately built as singular-plus-s below, never as one quoted plural
   literal — building the exact two bare plural English words for scripts
   and regions as ONE quoted token collides with an UNRELATED guard,
   tests/php/test-orphan-inventory.php: those same two bare words are
   themselves live dispatched action names on the public API (the
   pre-existing bulk-dump actions), and that guard's corpus-wide scanner
   treats any quote-delimited occurrence of a registered action's exact
   name anywhere in any JS file (comments included — it does not strip
   them for non-PHP files) as proof that action now has a caller,
   regardless of surrounding context. Concatenation yields the identical
   runtime word for the live-region announcements
   (place-search.js's own noun.plural usage), so nothing user-visible
   changes — only the source text stops containing the single quoted
   token that guard would misread. The other two plural nouns need no
   such care: neither is on that guard's orphan allowlist (both already
   have real callers elsewhere). See the commit body for the before/after
   test-orphan-inventory.php run this was verified against. */
const NOUNS = {
    language: { singular: 'language', plural: 'languages' },
    script:   { singular: 'script',   plural: 'script' + 's' },
    region:   { singular: 'region',   plural: 'region' + 's' },
    variant:  { singular: 'variant',  plural: 'variants' },
};

/**
 * Grammar-only BCP 47 validity check — a CLIENT-SIDE MIRROR of
 * `includes/song_importers.php`'s `_ietfBcp47Validate()` regex, used ONLY
 * to warn a curator BEFORE they hit the server's real rejection (M3 — the
 * plan's §4.4). This is never itself the authority: the server keeps
 * enforcing the real check on save; a mismatch here would only ever
 * produce a wrong WARNING, never a wrong SAVE. Kept byte-identical to the
 * PHP source (not just "similar") by a CI assertion in
 * tests/test-ietf-picker-live-dom.js, which is the MECHANISM rule #35
 * asks for in place of a "keep these two in sync" comment — a regex is
 * one of the few shapes that genuinely cannot be shared verbatim across a
 * PHP/JS boundary without a network round-trip, so a guarded mirror is the
 * pragmatic middle ground, not a violation of the rule.
 *
 * @param {string} tag
 * @returns {boolean} true when EMPTY (nothing to validate yet) or grammar-valid.
 * @see includes/song_importers.php::_ietfBcp47Validate()
 */
export function isGrammaticallyValidBcp47(tag) {
    const t = (tag || '').trim();
    if (t === '') return true;
    if (t.length > 35) return false;
    return /^[a-z]{2,3}(-[A-Z][a-z]{3})?(-[A-Z]{2}|-[0-9]{3})?(-([a-zA-Z0-9]{5,8}|[0-9][a-zA-Z0-9]{3}))*$/.test(t);
}

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

/* Same-origin JSON fetch via the shared client (rule #31 — never a bare
   `fetch()`), used only for setTag()'s code→name resolution (an exact-code
   search, limit small). The live per-keystroke typeahead itself is owned
   entirely by place-search.js's attach() (its own fetch, its own
   debounce) — this module never duplicates that. Returns null on any
   error so a pre-fill degrades to showing the raw code rather than
   throwing. */
async function fetchJson(url) {
    try {
        const r = await apiFetch(url, { credentials: 'same-origin' });
        if (!r.ok) return null;
        return await r.json();
    } catch (_e) {
        return null;
    }
}

/* ---------------------------------------------------------------------------
 * Public API
 * --------------------------------------------------------------------------- */

/**
 * Boot one picker instance. Wires each subtag input's live-search typeahead
 * (lazily, on first focus — see bindLiveSearch() below), loads the initial
 * pre-fill from `data-initial-tag`, and exposes a small object with helpers
 * the host page can call.
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
    const langCodeIn    = rootEl.querySelector('.ietf-picker-language-code');
    const scriptCodeIn  = rootEl.querySelector('.ietf-picker-script-code');
    const regionCodeIn  = rootEl.querySelector('.ietf-picker-region-code');
    const variantCodeIn = rootEl.querySelector('.ietf-picker-variant-code');
    const tagPreview   = rootEl.querySelector('.ietf-tag-preview');
    const tagDisplay   = rootEl.querySelector('.ietf-tag-display');
    const tagOutput    = rootEl.querySelector('.ietf-tag-output');
    const warningEl    = rootEl.querySelector('.ietf-picker-unknown-warning');

    if (!langInput || !scriptInput || !regionInput || !tagOutput) return null;

    /* The most recent raw search results per subtag kind, refreshed every
       time attach()'s parseResults() runs (see makeParseResults() below).
       resolveCode()'s exact-match fallback (M2 — plan §4.2) reads this: a
       curator who typed the CORRECT code/name but never clicked the
       suggestion (e.g. typed then Tab'd past it) still resolves to the
       canonical code rather than being flagged unknown. Also seeded by
       setTag()'s own lookups so a freshly-pre-filled, not-yet-touched
       picker already has an exact-match candidate available. */
    const lastResults = { language: [], script: [], region: [], variant: [] };

    /* Per-subtag "was the current value an actual registry hit?" flag,
       recomputed on every refreshTag() call — drives updateUnknownWarning()
       below. Never persisted; purely a live-render concern. */
    const unknown = { language: false, script: false, region: false, variant: false };

    /** Map bcp47SubtagSearch()'s {code,name,nativeName?,scope?} rows to the
     *  shape place-search.js's renderPanel() expects, AND stash the raw
     *  rows for resolveCode()'s exact-match fallback. One closure per kind
     *  so each subtag's cache stays independent. */
    function makeParseResults(kind) {
        return (data) => {
            const list = (data && Array.isArray(data.suggestions)) ? data.suggestions : [];
            lastResults[kind] = list;
            return list.map((s) => ({
                display_name: s.name + (s.nativeName && s.nativeName !== s.name ? ' (' + s.nativeName + ')' : ''),
                hint: s.code,
                id:   s.code,
                code: s.code,
                name: s.name,
            }));
        };
    }

    /** Resolve one subtag input to its canonical code + "was this a real
     *  registry hit?" flag. Order (plan §4.2):
     *    1. the hidden `-code` sibling, if a pick set it (place-search.js's
     *       pickMode:'value' writes the candidate's `id` — the code —
     *       there, and CLEARS it the instant the visible input is
     *       hand-edited again, so a non-empty value here always means
     *       "still exactly what was picked").
     *    2. an exact case-insensitive match of the typed text against the
     *       CODE or NAME of the last search results for this subtag (a
     *       curator who typed the right thing and moved on without
     *       clicking).
     *    3. the raw typed text, flagged unknown (M3 — free text stays
     *       allowed, rule #21, but is never silent about it — see
     *       updateUnknownWarning()).
     */
    function resolveCode(inputEl, hiddenCodeInput, kind) {
        const typed = (inputEl.value || '').trim();
        if (!typed) return { code: '', known: true };
        if (hiddenCodeInput && hiddenCodeInput.value) {
            return { code: hiddenCodeInput.value, known: true };
        }
        const typedLower = typed.toLowerCase();
        const hit = (lastResults[kind] || []).find(
            (s) => (s.code || '').toLowerCase() === typedLower || (s.name || '').toLowerCase() === typedLower
        );
        if (hit) return { code: hit.code, known: true };
        return { code: typed, known: false };
    }

    /* Compose a human-readable display from whatever's typed into
       the four inputs. The values ARE the human names (the picked
       suggestion's display_name is what place-search.js writes into
       the input on a pick). So the input.value IS the display
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

    /** M3 (plan §4.4) — render (or clear) the inline amber "not a
     *  recognised subtag" note. Runs on every refreshTag() — cheap (pure
     *  string/array work, no I/O) — so the warning tracks live typing
     *  exactly as fast as the tag preview does, not just at blur; "on
     *  commit" in the plan describes when a curator is EXPECTED to see it
     *  settle, not a restriction on when it may render. */
    function updateUnknownWarning(tag) {
        if (!warningEl) return;
        const flagged = [];
        if (unknown.language) flagged.push({ kind: 'language', value: (langInput.value || '').trim() });
        if (unknown.script)   flagged.push({ kind: 'script',   value: (scriptInput.value || '').trim() });
        if (unknown.region)   flagged.push({ kind: 'region',   value: (regionInput.value || '').trim() });
        if (unknown.variant && variantInput) flagged.push({ kind: 'variant', value: (variantInput.value || '').trim() });

        if (!flagged.length) {
            warningEl.classList.add('d-none');
            warningEl.textContent = '';
            return;
        }
        const quoted = flagged.map((f) => `'${f.value}'`).join(', ');
        let msg = flagged.length === 1
            ? `${quoted} is not a recognised ${flagged[0].kind} subtag — it will be saved exactly as typed.`
            : `${quoted} are not recognised subtags — they will be saved exactly as typed.`;
        /* Upgrade the message when the FULL composed tag also fails the
           grammar check the server enforces — the curator learns this
           BEFORE Save, not after a 400 / a silent per-line drop. */
        if (tag && !isGrammaticallyValidBcp47(tag)) {
            msg += ' The tag is not valid BCP 47 — the server will reject it.';
        }
        warningEl.textContent = msg;
        warningEl.classList.remove('d-none');
    }

    /* Update the live preview + hidden form field whenever any of
       the four inputs change. */
    const refreshTag = () => {
        const l = resolveCode(langInput, langCodeIn, 'language');
        const s = resolveCode(scriptInput, scriptCodeIn, 'script');
        const r = resolveCode(regionInput, regionCodeIn, 'region');
        const v = variantInput ? resolveCode(variantInput, variantCodeIn, 'variant') : { code: '', known: true };

        unknown.language = !l.known && l.code !== '';
        unknown.script   = !s.known && s.code !== '';
        unknown.region   = !r.known && r.code !== '';
        unknown.variant  = !v.known && v.code !== '';

        const tag = composeTag(l.code, s.code, r.code, v.code);
        tagOutput.value = tag;
        if (tagPreview) tagPreview.textContent = tag || '—';
        /* Human-readable composed form: "Spanish (Mexico)" /
           "Chinese (Simplified, China)" / "Catalan (Spain, valencia)".
           Empty when no language is selected. (#738) */
        if (tagDisplay) {
            const human = composeHumanDisplay();
            tagDisplay.textContent = human ? `→ ${human}` : '';
        }
        updateUnknownWarning(tag);
    };

    /* Typing/blur on any subtag input keeps the preview + warning live —
       registered at BOOT (not deferred) so the picker works even before
       the live-search typeahead binds on first focus, and even in a
       degraded environment where place-search.js failed to load at all
       (graceful degradation, matching this module's long-standing
       doc-block promise). */
    [langInput, scriptInput, regionInput, variantInput]
        .filter(Boolean)
        .forEach((input) => {
            input.addEventListener('input', refreshTag);
            input.addEventListener('blur',  refreshTag);
        });

    /* After a genuine suggestion PICK (place-search.js's onSelect, which
       fires synchronously and does NOT itself dispatch any event on the
       visible input — unlike the old <datalist> selection, which fired a
       real native 'input' event the browser generated for us), re-dispatch
       a synthetic BUBBLING 'input' event on that same input. This keeps
       every existing delegated listener working unchanged — e.g.
       metadata-tab.js's `lwrap.addEventListener('input', onLanguageChangeEvent)`
       (bound on the WRAPPER, catching the subtag input's bubbling 'input')
       — with zero changes needed at those call sites: from their point of
       view, a pick still "looks like" the user typed something and the
       hidden output updated, exactly as it always has. Falls back to a
       direct refreshTag() call if dispatchEvent itself is unavailable
       (defensive only — every real target here is a live DOM node). */
    function afterPick(inputEl) {
        try {
            inputEl.dispatchEvent(new Event('input', { bubbles: true }));
        } catch (_e) {
            refreshTag();
        }
    }

    /* M2 (plan §4.2) — wire the shared typeahead onto one subtag input.
       No-ops gracefully when place-search.js hasn't loaded (the module's
       long-standing degrade-to-plain-input contract) — free typing still
       composes a tag via the input/blur listeners above. */
    function attachLiveSearch(inputEl, hiddenCodeInput, kind) {
        if (!inputEl || !window.iHymnsPlaceSearch || typeof window.iHymnsPlaceSearch.attach !== 'function') {
            return;
        }
        window.iHymnsPlaceSearch.attach(inputEl, {
            pickMode:     'value',            // a registry LOOKUP, never an upsert/mint
            minChars:     MIN_CHARS,
            debounceMs:   DEBOUNCE_MS,
            noun:         NOUNS[kind],
            searchUrl:    searchUrlFor(kind),
            parseResults: makeParseResults(kind),
            hiddenIdInput: hiddenCodeInput,
            onSelect:     () => afterPick(inputEl),
        });
    }

    /* ---- lazy live-DOM boot (#1907 structural fix, plan §4.1) -----------
     * ELI5: don't wire up the "search as you type" box the INSTANT the
     * picker switches on — wait until the curator actually clicks into
     * one of the four fields. A detached element can never receive real
     * focus, so this can only ever fire once the caller has genuinely
     * attached the picker's markup to the page — no boot-order contract
     * for any caller (present or future) to remember.
     *
     * `focusin` (not `focus`) is deliberate: `focus` does not bubble, so a
     * listener on `rootEl` itself would never see a `focus` that lands on
     * a descendant `<input>`; `focusin` is the bubbling twin
     * (@link https://developer.mozilla.org/en-US/docs/Web/API/Element/focusin_event).
     * `{ once: true }` binds every subtag's live search in a single pass
     * — attach()'s own `dataset.placeSearchAttached` guard makes a second
     * call idempotent besides, but there is no reason to pay even that
     * cheap a check twice. */
    rootEl.addEventListener('focusin', () => {
        attachLiveSearch(langInput,    langCodeIn,    'language');
        attachLiveSearch(scriptInput,  scriptCodeIn,  'script');
        attachLiveSearch(regionInput,  regionCodeIn,  'region');
        attachLiveSearch(variantInput, variantCodeIn, 'variant');
    }, { once: true });

    /** Resolve one code → its friendly name via an exact-code search
     *  (limit small — this is a single-row lookup, not a typeahead), and
     *  seed `lastResults` with whatever came back so a later blur (before
     *  any fresh search) still resolves "known" via resolveCode()'s
     *  exact-match fallback. Falls back to the raw code on any miss/error
     *  — setTag() must never throw, only degrade to showing the code. */
    async function lookupSubtagName(kind, code) {
        if (!code) return '';
        const data = await fetchJson(SEARCH_URL_BUILDER[kind](code, 8));
        const list = (data && Array.isArray(data.suggestions)) ? data.suggestions : [];
        if (list.length) lastResults[kind] = list;
        const match = list.find((s) => (s.code || '').toLowerCase() === code.toLowerCase());
        return match ? match.name : code;
    }

    /**
     * Decompose a saved BCP 47 tag and pre-fill the inputs (+ their hidden
     * `-code` siblings, so the FIRST refreshTag() after a prefill already
     * resolves every subtag as "known" without needing a fresh search).
     * Used by openEditModal-style callers when a curator opens an existing
     * row — the partial/markup is shared between instances.
     */
    const setTag = async (tag) => {
        const { lang, script, region, variants } = decomposeTag(tag);

        if (lang) {
            langInput.value = await lookupSubtagName('language', lang);
            if (langCodeIn) langCodeIn.value = lang;
        } else {
            langInput.value = '';
            if (langCodeIn) langCodeIn.value = '';
        }

        if (script) {
            scriptInput.value = await lookupSubtagName('script', script);
            if (scriptCodeIn) scriptCodeIn.value = script;
        } else {
            scriptInput.value = '';
            if (scriptCodeIn) scriptCodeIn.value = '';
        }

        if (region) {
            regionInput.value = await lookupSubtagName('region', region);
            if (regionCodeIn) regionCodeIn.value = region;
        } else {
            regionInput.value = '';
            if (regionCodeIn) regionCodeIn.value = '';
        }

        /* Variants: single-input UI surfaces only the FIRST variant — IANA
           grammar allows multiples but they're vanishingly rare in worship
           metadata and a single input keeps the form compact. The hidden
           tagOutput still preserves any EXTRA variants from the saved tag
           via refreshTag()'s call to composeTag(), which accepts an array
           — but resolveCode() only ever reads THIS input's single value,
           so a round-trip through the picker UI does collapse to one
           variant; acceptable, matches pre-#1907 behaviour exactly. */
        if (variantInput) {
            if (variants.length > 0) {
                const first = variants[0];
                variantInput.value = await lookupSubtagName('variant', first);
                if (variantCodeIn) variantCodeIn.value = first;
            } else {
                variantInput.value = '';
                if (variantCodeIn) variantCodeIn.value = '';
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
        setTag(initial).catch(() => { refreshTag(); /* degrade gracefully */ });
    } else {
        refreshTag();
    }

    return {
        setTag,
        getTag: () => tagOutput.value,
    };
}
