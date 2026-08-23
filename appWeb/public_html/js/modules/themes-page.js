/**
 * iHymns — /themes A–Z index behaviour (#1148)
 *
 * ELI5: turns the plain server-rendered A–Z theme list into a browsable index —
 * a type-to-filter box and a letter jump bar — WITHOUT any network calls. A
 * visitor with no JS still gets the complete list; this just adds the
 * conveniences on top (progressive enhancement).
 *
 * DETAIL:
 * -------
 * Imported by router.js's afterPageLoad() when page === 'themes' (the rule-#30
 * pattern — the shared-cache fragment carries no inline script). Reads every
 * input DOM-first from the data-* the fragment (includes/pages/themes.php)
 * already emits; idempotent no-op when the filter host is absent (navigated
 * away mid-import, or the empty-state card rendered). Touches ONLY the DOM — no
 * fetch, so nothing here needs apiFetch and nothing is a shared-state concern.
 *
 * The jump bar derives its letters from the RENDERED `[data-themes-letter]`
 * sections (never a typed A–Z list — a letter with no section has no button),
 * and uses buttons (not hash anchors) exactly as js/modules/songbook-index.js
 * (#111) chose: no history entries, no popstate interaction with the SPA
 * router. Nothing here is position:fixed, so rule #32's teardown does not
 * engage — the listeners die with the fragment on the next navigation and the
 * module re-inits next visit.
 *
 * @see includes/pages/themes.php
 * @see js/modules/songbook-index.js (the #111 jump-bar precedent)
 * @see .claude/browse-by-theme-1148-plan.md §3.3
 * @link https://developer.mozilla.org/docs/Web/API/Element/scrollIntoView
 */

/** NFD-fold: lowercase + strip combining marks, so "Café" matches "cafe". */
function foldText(s) {
    return (s || '').toLowerCase().normalize('NFD').replace(/\p{M}/gu, '');
}

/** Prefers reduced motion? (the site-wide body flag the rest of the app uses) */
function reduceMotion() {
    return document.body.classList.contains('reduce-motion');
}

export function initThemesPage() {
    const root = document.querySelector('.page-themes');
    if (!root) return;

    const filterBlock = root.querySelector('#themes-filter-block');
    const jumpHost    = root.querySelector('#themes-jump-bar');
    const filterInput = root.querySelector('#themes-filter');
    const filterCount = root.querySelector('#themes-filter-count');
    const sections    = Array.from(root.querySelectorAll('[data-themes-letter]'));

    /* Empty-state card / navigated away — nothing to enhance. */
    if (!filterBlock || sections.length === 0) return;

    /* Reveal the progressive-enhancement controls (useful only with JS). */
    filterBlock.hidden = false;
    if (jumpHost) jumpHost.hidden = false;

    const scrollToSection = (section) => {
        if (!section) return;
        section.scrollIntoView({ behavior: reduceMotion() ? 'auto' : 'smooth', block: 'start' });
    };

    /* --- Jump bar: a sticky button strip (>=sm) + a <select> (<sm) --- */
    let jumpLetterEls = [];
    if (jumpHost) {
        const letters = sections.map((s) => s.getAttribute('data-themes-letter'));

        const strip = document.createElement('div');
        strip.className = 'd-none d-sm-flex flex-wrap gap-1';
        letters.forEach((letter, i) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm btn-outline-secondary themes-jump-letter';
            btn.textContent = letter;
            btn.dataset.jumpLetter = letter;
            /* Scroll only — never move focus (that would trap a keyboard user
               mid-list; the #111 module made the same call). */
            btn.addEventListener('click', () => scrollToSection(sections[i]));
            strip.appendChild(btn);
        });

        const select = document.createElement('select');
        select.className = 'form-select form-select-sm d-sm-none';
        select.setAttribute('aria-label', 'Jump to letter');
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Jump to…';
        select.appendChild(placeholder);
        letters.forEach((letter, i) => {
            const opt = document.createElement('option');
            opt.value = String(i);
            opt.textContent = letter;
            select.appendChild(opt);
        });
        select.addEventListener('change', () => {
            const idx = parseInt(select.value, 10);
            if (!Number.isNaN(idx)) scrollToSection(sections[idx]);
            select.value = '';   /* reset so re-picking the same letter re-fires */
        });

        jumpHost.appendChild(strip);
        jumpHost.appendChild(select);
        jumpLetterEls = Array.from(jumpHost.querySelectorAll('[data-jump-letter]'));
    }

    /* --- Filter: pure DOM work over a few hundred rows, no fetch/debounce --- */
    if (filterInput) {
        const rows = Array.from(root.querySelectorAll('.theme-index-row'));
        /* Re-fold each row's data-theme-fold once through the SAME JS fold, so
           diacritics match from both sides (the attribute is emitted lowercased
           but not NFD-normalised). */
        rows.forEach((r) => r.setAttribute('data-theme-fold', foldText(r.getAttribute('data-theme-fold'))));

        const applyFilter = () => {
            const q = foldText(filterInput.value.trim());
            const total = rows.length;
            let shown = 0;
            rows.forEach((r) => {
                const match = q === '' || r.getAttribute('data-theme-fold').includes(q);
                r.hidden = !match;
                if (match) shown++;
            });
            /* Hide a section when all its rows hide; collect the live letters. */
            const activeLetters = new Set();
            sections.forEach((section) => {
                const anyVisible = Array.from(section.querySelectorAll('.theme-index-row')).some((r) => !r.hidden);
                section.hidden = !anyVisible;
                if (anyVisible) activeLetters.add(section.getAttribute('data-themes-letter'));
            });
            /* Dim jump letters with no matches. */
            jumpLetterEls.forEach((el) => {
                const on = activeLetters.has(el.getAttribute('data-jump-letter'));
                el.disabled = !on;
                el.classList.toggle('disabled', !on);
            });
            /* Announce match count politely; clear when idle so it doesn't chatter. */
            if (filterCount) {
                filterCount.textContent = q === '' ? '' : `${shown} of ${total} themes shown`;
            }
        };

        filterInput.addEventListener('input', applyFilter);
    }
}
