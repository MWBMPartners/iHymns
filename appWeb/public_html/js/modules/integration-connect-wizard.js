/* ============================================================================
 * iHymns — "Connect a service" guided integration wizard driver (#2003, epic #2002)
 *
 * ELI5
 * ----
 * `/manage/configuration` has three cards (IntAppsAPI, CueRCode, CAPTCHA)
 * that each need a few pieces of information before they do anything. This
 * file is the ONE engine behind a "Set up with a guide" button on each of
 * those cards: it reads a small description of that integration (sent down
 * from PHP as JSON — never a secret value, only "is one saved?"), builds a
 * step-by-step modal from it, saves through the SAME action the card's own
 * "Save" button already posts to, and then asks the server "does this
 * actually work?" before saying "done".
 *
 * DETAILED
 * --------
 * ONE GENERIC DRIVER, NOT SIX (rule #1 / plan §D2): this module never
 * hardcodes a field name, a setting key, or a status word for any ONE
 * integration. Everything it renders comes from the `registry` object
 * passed to `initIntegrationConnectWizard()` — the JSON projection built by
 * `includes/integration_registry.php::integrationClientProjection()`.
 *
 * #2004 STALE-CLAIM CORRECTION (rule #26): this sentence used to claim
 * "adding a Phase-2 integration is new PHP registry data plus a new
 * launcher button; this file needs no change." That was Phase 1's
 * optimistic sketch, and it was WRONG the moment email/SIWA/webhooks
 * actually needed building — their field shapes (a plain `<select>`, a
 * `<textarea>`, a single checkbox, a field whose visibility depends on
 * ANOTHER field's value, a field that must be posted but never rendered)
 * did not exist in Phase 1's driver at all. The corrected claim: adding a
 * NEW integration whose fields are already expressible in the SIX generic
 * field shapes this driver understands (`text` incl. `email`/`number` via
 * `inputType`, `select`, `textarea`, `checkbox`, `checkbox-group`, plus the
 * `secret`/`showWhen`/`carry` modifiers any of them can carry) is registry
 * data only. A field shape THIS driver has never seen before is the one
 * case that still needs a driver change — and even then, the change is a
 * new GENERIC renderer branch keyed on `field.type`, never a per-integration
 * `if (entry.key === '…')` special case (that is exactly what guard check
 * (f)'s literal-ban scan exists to keep true).
 *
 * BUILT ON THE SHARED STEPPER `./admin-wizard.js` (`createWizard()`, #1992):
 * because that module derives its steps from whichever `[data-wiz-step]`
 * panes exist in the DOM at construction time, THIS driver builds a fresh
 * set of panes into `[data-icw-panes]` every time the modal opens (so
 * CueRCode — no provider step — gets 4 steps and CAPTCHA — with one — gets
 * 5, with no conditional-step machinery anywhere) and calls `createWizard()`
 * fresh each time too, destroying the previous instance on close. This is
 * the SAME "steps are fixed at construction" accommodation
 * `manage/venues.php`'s Live-Service wizard already makes.
 *
 * SECRET FIELD RENDERING: a `secret:true` field in the registry (inferred
 * client-side by the PRESENCE of a `set` key rather than a `value` key —
 * see `includes/integration_registry.php::integrationClientProjection()`'s
 * own doc-block) always renders as `<input type="password" autocomplete="off">`
 * NEVER prefilled — the exact "key on file — leave blank to keep" idiom
 * every classic card on this page already uses. This module has no code
 * path that could put a secret VALUE into the DOM: the projection never
 * sends one (see the PHP file's own guarantee) and this driver only ever
 * reads a secret field's `set` boolean, never a `value` key that does not
 * exist for it.
 *
 * SAVE THROUGH THE EXISTING ACTION, NEVER A FORKED WRITE PATH (rule #22,
 * plan §D3): the "Save & test" step POSTs the registry's own `saveAction`
 * (`save_intappsapi` / `save_cuercode` / `save_captcha`) with an additive
 * `respond=json` field — the SAME handler the classic form on this page
 * runs, byte-for-byte. `X-Requested-With` is set on every POST so the
 * server's `validateCsrfRequest()` gate (rule #29) accepts it even on a
 * long-open modal whose baked session token may have rotated.
 *
 * QR PROOF USES THE SAME `/qr` DOOR EVERY OTHER SURFACE USES: the CueRCode
 * done-pane's live proof image is `<img src="/qr?data=...">` — the
 * EXTENSIONLESS alias (`.htaccess`'s `^qr$` rule), never `/qr.php?...`,
 * which a real browser's request line would 404 on (the "block direct
 * access to PHP files" rule reads the client's ORIGINAL request text — see
 * `.htaccess`'s own `/qr → qr.php` comment). `js/modules/print.js` and
 * `manage/service-projection.php` are the two existing consumers this
 * mirrors exactly; `tests/test-qr-cuercode.js` bans the `/qr.php` shape
 * reappearing in any surface's emitted `<img src>`.
 *
 * @see appWeb/public_html/manage/configuration.php   the one consumer (launcher buttons + modal shell)
 * @see appWeb/public_html/includes/integration_registry.php  the server-side registry this reads
 * @see appWeb/public_html/js/modules/admin-wizard.js  the shared stepper this is built on
 * @see appWeb/public_html/manage/external-link-types.php  the JSON-branch + save-error-routing precedent
 * @see appWeb/public_html/manage/venues.php           the HYBRID (existing-endpoints, DONE-pane) precedent
 * @see tests/php/test-integration-connect-wizard.php  the standing guard
 * @link https://getbootstrap.com/docs/5.3/components/modal/  Bootstrap modal events (show.bs.modal / hidden.bs.modal)
 * #2003 (epic #2002)
 * ========================================================================== */

import { createWizard } from './admin-wizard.js';
import { apiFetch } from '../utils/api-client.js';

/* apiFetch(), NOT a bare fetch() (rule #31, #1624's standing
   tests/test-api-client-usage.js guard — it scans every file under js/,
   with no PWA-vs-/manage distinction: unlike the OTHER wizards' inline
   <script type="module"> blocks written directly inside their .php page
   (venues.php, external-link-types.php — outside the js/ tree and so
   outside that guard's scope), THIS file is a genuine js/modules/*.js
   module, so it is squarely inside it). apiFetch() already attaches
   X-Requested-With: XMLHttpRequest on every same-origin request (see that
   file's own buildHeaders(), citing this exact rule #29 need) — so this
   driver never sets that header by hand, and a future apiFetch() change
   cannot leave it stale here. */
function postForm(action, csrfToken, fields) {
    const body = new URLSearchParams();
    body.set('action', action);
    body.set('csrf_token', csrfToken);
    if (fields) {
        fields.forEach(([key, value]) => { body.append(key, value); });
    }
    return apiFetch('/manage/configuration', {
        method: 'POST',
        credentials: 'same-origin',
        body,
    }).then((res) => res.json().catch(() => ({})).then((data) => ({ status: res.status, data })));
}

/** Does this field's PROJECTED shape mark it secret? Inferred structurally —
 *  never a re-typed integration-specific list — from which key the PHP
 *  projection chose to emit (see integration_registry.php's own doc-block
 *  on why exactly one of `set`/`value` is ever present). */
function isSecretField(field) {
    return Object.prototype.hasOwnProperty.call(field, 'set');
}

const CHANNEL_TOKENS = ['alpha', 'beta', 'production', 'all'];
const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
/* #2004 — mirrors save_apple's OWN server-side shape check
   (`preg_match('/^[A-Z0-9]{10}$/', ...)` after an uppercase, applied to
   apple_team_id / apple_siwa_key_id, manage/configuration.php) — client
   convenience only; the server stays the authority, case-insensitive here
   since save_apple itself uppercases before checking. */
const TEN_CHAR_ID_RE = /^[A-Za-z0-9]{10}$/;

/**
 * Build (or update) one field's status BADGE + PLACEHOLDER for a secret
 * input — the "(unchanged — leave blank to keep)" idiom every classic card
 * on this page already uses.
 */
function secretBadgeHtml(set) {
    return set
        ? '<span class="badge bg-success">set</span>'
        : '<span class="badge bg-secondary">not set</span>';
}

/**
 * Render ONE credential field's markup into a wrapper <div>. Generic over
 * every field shape the registry can describe — never a per-integration
 * branch on `field.post` (that is exactly what
 * tests/php/test-integration-connect-wizard.php check (f) bans: a
 * hardcoded field-name literal here instead of iterating `entry.fields`).
 *
 * #2004 (epic #2002) grew this from ONE generic text/password renderer to
 * SIX generic shapes (`checkbox-group` unchanged, plus `select`/`textarea`/
 * `checkbox`, and the `showWhen`/`carry` MODIFIERS any non-checkbox-group
 * field can carry) — every branch below still keys off `field.type` /
 * `field.secret`-inferred-via-`isSecretField()` / registry data, never an
 * integration key or a post-name literal.
 *
 * @param {object} entry  the registry entry (for `formMeta` on a checkbox-group)
 * @param {object} field  one projected field
 * @returns {HTMLElement}
 */
function renderField(entry, field) {
    const id = 'icw-f-' + field.post;
    const wrap = document.createElement('div');
    wrap.className = 'mb-3';
    /* Generic markers EVERY field wrapper carries, regardless of shape —
       collectFields()/validateCredentialsFields() look a field's wrapper up
       by `data-icw-field` to answer "is this currently showWhen-hidden?"
       without caring what kind of control lives inside; applyShowWhen()
       re-derives `hidden` from `data-icw-showwhen` (JSON-encoded conditions)
       on every change. Neither attribute does anything by itself — a field
       with no `showWhen` simply never gets hidden by this mechanism. */
    wrap.dataset.icwField = field.post;
    if (field.showWhen) {
        wrap.dataset.icwShowwhen = JSON.stringify(field.showWhen);
    }

    if (field.type === 'checkbox-group') {
        const legend = document.createElement('div');
        legend.className = 'form-label mb-1';
        legend.id = id + '-legend';
        legend.textContent = field.label;
        wrap.appendChild(legend);

        const grid = document.createElement('div');
        grid.className = 'row g-2';
        /* a11y audit A15 (2026-08-30) — the visible "legend" above is a
           plain <div>, so nothing tied it to the checkboxes below it
           programmatically; a screen-reader user tabbing into the first
           checkbox heard only its own option label, never the group's
           overall question. role="group" + aria-labelledby is the ARIA
           equivalent of a real <fieldset>/<legend> pair (gating.php's own
           songbook picker, :736, already uses this exact shape). */
        grid.setAttribute('role', 'group');
        grid.setAttribute('aria-labelledby', legend.id);
        const formMeta = entry.formMeta || {};
        const ticked = new Set(field.values || []);
        Object.keys(formMeta).forEach((formKey) => {
            const meta = formMeta[formKey] || {};
            const col = document.createElement('div');
            col.className = 'col-md-6';
            const check = document.createElement('div');
            check.className = 'form-check';
            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.className = 'form-check-input';
            cb.id = id + '-' + formKey;
            cb.value = formKey;
            cb.dataset.icwCbgroup = field.post;
            cb.checked = ticked.has(formKey);
            const lab = document.createElement('label');
            lab.className = 'form-check-label';
            lab.htmlFor = cb.id;
            lab.textContent = meta.label || formKey;
            check.appendChild(cb);
            check.appendChild(lab);
            if (meta.caption) {
                const cap = document.createElement('div');
                cap.className = 'form-text small';
                cap.textContent = meta.caption;
                check.appendChild(cap);
            }
            col.appendChild(check);
            grid.appendChild(col);
        });
        wrap.appendChild(grid);
        return wrap;
    }

    if (field.type === 'checkbox') {
        /* A SINGLE boolean tick (e.g. "allow loopback targets", "regenerate
           the drain key now") — its OWN label/help live beside the checkbox
           itself, unlike checkbox-group's per-option formMeta lookup. */
        const check = document.createElement('div');
        check.className = 'form-check';
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.className = 'form-check-input';
        cb.id = id;
        cb.checked = !!field.checked;
        const lab = document.createElement('label');
        lab.className = 'form-check-label';
        lab.htmlFor = id;
        lab.textContent = field.label;
        check.appendChild(cb);
        check.appendChild(lab);
        wrap.appendChild(check);
        if (field.help) {
            const help = document.createElement('div');
            help.className = 'form-text small';
            help.textContent = field.help;
            wrap.appendChild(help);
        }
        return wrap;
    }

    const label = document.createElement('label');
    label.className = 'form-label';
    label.htmlFor = id;
    label.textContent = field.label + ' ';
    if (isSecretField(field)) {
        const badge = document.createElement('span');
        badge.innerHTML = secretBadgeHtml(field.set);
        label.appendChild(badge.firstChild);
    }
    wrap.appendChild(label);

    const secret = isSecretField(field);
    /* A `type:'select'` field that ISN'T secret draws a real <select> from
       its own `options` map — the ONE select field Phase 1 had
       (`captcha_provider`) is the PROVIDER field, rendered on its own
       dedicated "Choose provider" pane instead (renderProviderSelect()), so
       it never reaches this generic path; a non-provider select (e.g.
       `email_smtp_secure`) does. (No registry field is ever BOTH
       `type:'select'` AND `secret:true` — a secret always renders as the
       password `<input>` branch below regardless of its declared `type`,
       matching how a `type:'password'` model row is expressed in the
       registry as `type:'text', secret:true` in the first place.) */
    const useSelect = field.type === 'select' && !secret;
    /* A `type:'textarea'` field (e.g. the .p8 keys, the Gmail service-account
       JSON) always renders as a multi-line control, secret or not — only
       whether it PREFILLS differs, mirroring every other secret field's
       "never echo, always show the (unchanged — leave blank to keep)
       placeholder" idiom. */
    const useTextarea = field.type === 'textarea';

    let input;
    if (useSelect) {
        input = document.createElement('select');
        input.className = 'form-select';
        const opts = field.options || {};
        Object.keys(opts).forEach((val) => {
            const opt = document.createElement('option');
            opt.value = val;
            opt.textContent = opts[val];
            input.appendChild(opt);
        });
        /* Preselect the current value, falling back to the registry's own
           default (plan §2.2) — and if NEITHER resolves to a real <option>
           (an unrecognised stored value), fall through to whatever the
           browser's own default selection is rather than forcing one. */
        const want = field.value || field.default || '';
        if (Object.prototype.hasOwnProperty.call(opts, want)) {
            input.value = want;
        }
    } else if (useTextarea) {
        input = document.createElement('textarea');
        input.className = 'form-control font-monospace';
        input.rows = 4;
        input.spellcheck = false;
        if (secret) {
            input.autocomplete = 'off';
            input.placeholder = field.set ? '(unchanged — leave blank to keep)' : (field.placeholder || '');
        } else {
            input.value = field.value || '';
            if (field.placeholder) { input.placeholder = field.placeholder; }
        }
    } else {
        input = document.createElement('input');
        input.className = 'form-control';
        if (secret) {
            input.type = 'password';
            input.autocomplete = 'off';
            input.placeholder = field.set ? '(unchanged — leave blank to keep)' : (field.placeholder || '');
        } else {
            /* `inputType` is a cosmetic passthrough ('email'/'number') —
               falls back to plain 'text' when the registry doesn't set one. */
            input.type = field.inputType || 'text';
            input.value = field.value || '';
            if (field.placeholder) { input.placeholder = field.placeholder; }
        }
    }
    input.id = id;
    wrap.appendChild(input);

    if (field.help) {
        const help = document.createElement('div');
        help.className = 'form-text small';
        help.textContent = field.help;
        wrap.appendChild(help);
    }
    return wrap;
}

/**
 * Read field `post`'s CURRENT effective value for a `showWhen` condition.
 *
 * ELI5: "what does this OTHER field say right now?" — read straight off its
 * live control when one exists in the DOM (hidden or not: a hidden
 * `<select>`/`<input>` still holds whatever it was seeded with, so this
 * naturally reproduces "a field with no selector at all defaults to the
 * registry's `default`" WITHOUT a special hidden-vs-visible branch — see
 * the note below). Falls back to the registry's own `default` only when the
 * control genuinely isn't in the DOM.
 *
 * WHY NO EXPLICIT "is the wrapper hidden?" CHECK: every field this driver
 * can render is built ONCE per wizard-open (buildPanes()) and stays in the
 * DOM for the WHOLE open (only its wrapper's `hidden` attribute toggles) —
 * so a conditionally-hidden control (e.g. `email_auth_method`, hidden
 * whenever the chosen provider has no auth-method choice) is STILL seeded
 * with `field.value || field.default` at render time (renderField()'s own
 * `useSelect` branch) and keeps that value in the DOM whether its wrapper
 * is shown or not. Reading `.value` directly already gives the exact answer
 * "the registry's default, unless something else was explicitly chosen" —
 * the same rule expressed either way, with less code and one fewer state to
 * keep in sync.
 *
 * @param {object} entry
 * @param {string} post
 * @returns {string}
 */
function fieldEffectiveValue(entry, post) {
    const input = document.getElementById('icw-f-' + post);
    if (input) {
        return input.type === 'checkbox' ? (input.checked ? '1' : '') : input.value;
    }
    const field = (entry.fields || []).find((f) => f.post === post);
    return (field && field.default) || '';
}

/** Do EVERY condition in a field's `showWhen` list hold, given the CURRENT
 *  form state? Conditions are ANDed (plan §2.3) — generic over any number
 *  of conditions referencing any other field, never a per-integration
 *  branch. */
function showWhenConditionsMet(entry, showWhen) {
    return (showWhen || []).every((cond) => {
        const val = fieldEffectiveValue(entry, cond.field);
        return Array.isArray(cond.in) && cond.in.includes(val);
    });
}

/**
 * Re-derive EVERY conditional field wrapper's visibility from the CURRENT
 * form state — called once right after buildPanes() (so the credentials
 * pane opens already correct) and again on every `change`/`input` inside
 * the panes (the provider select included, since it lives in the SAME
 * `panesEl` subtree — see openWizard()'s single delegated listener).
 *
 * Generic over ANY field's `showWhen` (registry data) — the one entry using
 * this today (`email`) is not special-cased here in any way; a future
 * integration's conditional field works by naming a `showWhen` in its OWN
 * registry entry, nothing else.
 */
function applyShowWhen(entry, panesEl) {
    panesEl.querySelectorAll('[data-icw-showwhen]').forEach((wrap) => {
        let conditions = [];
        try {
            conditions = JSON.parse(wrap.dataset.icwShowwhen || '[]');
        } catch (_e) {
            conditions = [];
        }
        wrap.hidden = !showWhenConditionsMet(entry, conditions);
    });
}

/** The dedicated provider <select> for the "Choose provider" pane — built
 *  from `entry.providers` (already secret-free; portal links live beside
 *  each option, read back by renderNeedPane()). Uses the SAME id convention
 *  (`icw-f-<post>`) as renderField() so collectFields() finds it without
 *  caring which pane it physically lives in. */
function renderProviderSelect(entry) {
    const field = entry.fields.find((f) => f.post === entry.providerField);
    const id = 'icw-f-' + entry.providerField;
    const wrap = document.createElement('div');
    wrap.className = 'mb-3';
    const label = document.createElement('label');
    label.className = 'form-label';
    label.htmlFor = id;
    label.textContent = (field && field.label) || 'Provider';
    wrap.appendChild(label);

    const select = document.createElement('select');
    select.id = id;
    select.className = 'form-select';
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Choose a provider…';
    select.appendChild(placeholder);
    const providers = entry.providers || {};
    const currentValue = (field && field.value) || '';
    Object.keys(providers).forEach((key) => {
        const opt = document.createElement('option');
        opt.value = key;
        opt.textContent = providers[key].label || key;
        select.appendChild(opt);
    });
    select.value = Object.prototype.hasOwnProperty.call(providers, currentValue) ? currentValue : '';
    wrap.appendChild(select);
    return wrap;
}

/** Rebuild the "what you'll need" pane's need-list + portal link. Called
 *  once at pane-build time and again every time the wizard ENTERS this
 *  step (onStepChange) — provider-specific portal links (CAPTCHA) can only
 *  be known once a provider is chosen on the previous step, so this must be
 *  re-resolved on every arrival, not computed once at open(). */
function renderNeedPane(entry, needListEl, portalEl) {
    needListEl.innerHTML = '';
    (entry.need || []).forEach((line) => {
        const li = document.createElement('li');
        li.textContent = line;
        needListEl.appendChild(li);
    });

    let portal = entry.portal;
    if (entry.providerField) {
        const sel = document.getElementById('icw-f-' + entry.providerField);
        const chosen = sel && sel.value;
        const providerEntry = chosen && entry.providers ? entry.providers[chosen] : null;
        portal = providerEntry && providerEntry.portalUrl
            ? { url: providerEntry.portalUrl, label: providerEntry.portalLabel || providerEntry.label }
            : null;
    }
    portalEl.innerHTML = '';
    if (portal && portal.url) {
        const a = document.createElement('a');
        a.href = portal.url;
        a.target = '_blank';
        a.rel = 'noopener';
        a.textContent = portal.label || portal.url;
        portalEl.appendChild(document.createTextNode('Go to: '));
        portalEl.appendChild(a);
    } else if (entry.providerField) {
        portalEl.textContent = 'Choose a provider on the previous step to see where to sign up.';
    }
}

/** Is field `post`'s wrapper CURRENTLY showWhen-hidden? A field with no
 *  `data-icw-showwhen` wrapper at all (most fields; every Phase-1 field)
 *  never matches this, so it's always treated as visible — this helper only
 *  ever SKIPS something that opted into the mechanism via its OWN registry
 *  `showWhen`. */
function isFieldCurrentlyHidden(post) {
    const wrap = document.querySelector('[data-icw-field="' + post + '"]');
    return !!(wrap && wrap.hidden);
}

/** Every non-secret + secret field this integration's SAVE handler reads
 *  unconditionally, PLUS every ticked checkbox-group value — collected
 *  generically by iterating `entry.fields` (never a hardcoded field-name
 *  list; test-integration-connect-wizard.php check (f) mutation-proves
 *  this). This is the carry-safety table from the plan (§8) made
 *  mechanical: every field the registry describes is always posted, so a
 *  classic handler's "unconditionally overwritten from $_POST" fields can
 *  never be silently wiped by an incomplete wizard POST.
 *
 * #2004 additions, still fully generic over registry data: a showWhen-
 * HIDDEN field is skipped entirely (mirrors the classic email card's own
 * "disable inputs in a hidden group so the form doesn't submit stale
 * values" convention — safe here because the ONE entry using `showWhen`
 * today, `email`, has an `array_key_exists`-carry-safe save handler, so an
 * omitted key is preserved, never zeroed); a `checkbox` field posts `'1'`
 * ONLY when ticked (absent-when-unticked, matching every classic checkbox
 * handler's `!empty()` read); a `carry` field posts its PROJECTED value
 * directly — it is never rendered, so there is no DOM input to read. */
function collectFields(entry) {
    const out = [];
    entry.fields.forEach((field) => {
        if (isFieldCurrentlyHidden(field.post)) { return; }

        if (field.type === 'checkbox-group') {
            document.querySelectorAll('input[type=checkbox][data-icw-cbgroup="' + field.post + '"]:checked')
                .forEach((cb) => { out.push([field.post + '[]', cb.value]); });
            return;
        }
        if (field.type === 'checkbox') {
            const cb = document.getElementById('icw-f-' + field.post);
            if (cb && cb.checked) { out.push([field.post, '1']); }
            return;
        }
        if (field.carry) {
            out.push([field.post, field.value != null ? String(field.value) : '']);
            return;
        }
        const input = document.getElementById('icw-f-' + field.post);
        if (input) { out.push([field.post, input.value]); }
    });
    return out;
}

/** Client-side MIRRORS of the cheap server-side validations (plan §7.2 item
 *  4) — convenience only; a mismatch here costs one extra round trip, never
 *  a security gap, because the server's own save handler (unchanged)
 *  remains the authority and its 422 routes straight back to this step.
 *  #2004: a showWhen-hidden field is never validated either (mirrors
 *  collectFields()'s own skip — a hidden field's stale/blank value is never
 *  posted, so validating it would only ever produce a confusing false
 *  positive), and a `carry` field is skipped outright (it has no rendered
 *  input to read a value FROM). */
function validateCredentialsFields(entry) {
    for (const field of entry.fields) {
        if (field.type === 'checkbox-group' || field.type === 'checkbox' || field.carry || !field.validate) { continue; }
        if (isFieldCurrentlyHidden(field.post)) { continue; }
        const input = document.getElementById('icw-f-' + field.post);
        if (!input) { continue; }
        const val = input.value.trim();
        if (val === '') { continue; } /* blank = optional/keep — the server decides what that means */
        if (field.validate === 'https_url' && !val.startsWith('https://')) {
            return { ok: false, message: field.label + ' must start with https://.', focus: input };
        }
        if (field.validate === 'uuid' && !UUID_RE.test(val)) {
            return { ok: false, message: field.label + ' must be a standard UUID, or left blank.', focus: input };
        }
        if (field.validate === 'channel_tokens') {
            const bad = val.split(',').map((t) => t.trim()).filter((t) => t !== '')
                .find((t) => !CHANNEL_TOKENS.includes(t.toLowerCase()));
            if (bad) {
                return { ok: false, message: '"' + bad + '" is not a valid channel — use alpha, beta, production, or all.', focus: input };
            }
        }
        if (field.validate === 'ten_char_id' && !TEN_CHAR_ID_RE.test(val)) {
            return { ok: false, message: field.label + ' must be exactly 10 letters/digits, or left blank.', focus: input };
        }
    }
    return true;
}

/* Per-integration, per-status copy — never regex-matched from the server's
   OWN prose (rule #35): the server sends a STRUCTURAL `status` key and this
   map turns it into a sentence. A status this map doesn't recognise still
   renders SOMETHING (the generic fallback below), never a blank pane. */
const STATUS_COPY = {
    intapps: {
        ok: 'Connected — the gateway answered the heartbeat check.',
        unconfigured: 'Saved — but this channel/credential set is still incomplete. The integration will behave exactly as if disabled until every field is set. This is the documented fail-open default, not an error.',
        error: 'Saved — but the gateway did not answer as expected. Check the base URL, app UUID and keys, then try again.',
    },
    cuercode: {
        ok: 'Connected — CueRCode drew a live QR code just now (see below).',
        unconfigured: 'Saved — but no key is set yet, so QR codes still fall back to plain URL/code text.',
        rejected: 'CueRCode answered and refused this API key — paste the correct key and try again.',
        unreachable: 'Saved — but CueRCode could not be reached from this server. This may be a temporary outage; try again shortly.',
        bad_response: 'Saved — but CueRCode answered with something unexpected. Try again shortly.',
    },
    captcha: {
        up: "Connected — the provider answered and accepted this site/secret key pair.",
        unconfigured: 'CAPTCHA is not fully configured yet (or the CAPTCHA_DISABLED break-glass file is present), so there was nothing to check.',
        misconfig: 'The provider answered and REJECTED the secret key — paste the correct secret key and try again.',
        down: 'Saved — but the provider is not reachable from this server right now. Gated forms fall back to the ordinary rate limits until it answers again; this is not a lockout.',
        unobservable: 'Saved — but this server has no way to check right now (no outbound HTTP client). Nothing was recorded.',
    },
    email: {
        ok: 'A test email is on its way to your own admin address — check that inbox to confirm delivery.',
        no_admin_email: 'Your admin account has no valid email address on file, so there was nowhere to send the test. Set one under Users, then retry.',
        unconfigured: 'Saved — but no provider is selected yet, so email features stay off.',
        send_failed: 'The provider refused the send. The full error is recorded in the Activity Log (the "email.send" row) — adjust the credentials and retry.',
    },
    siwa: {
        ok: 'Credentials check out — the .p8 key, Key ID and Team ID fit together and can sign Apple sign-in requests. Apple itself is only contacted during a real sign-in.',
        unconfigured: 'Saved — but the Team ID, Key ID and .p8 key are not all set yet, so there was nothing to check. Sign-in itself keeps working regardless.',
        invalid_key: 'The saved key, Key ID and Team ID do not fit together — most often the wrong .p8 was pasted (the App Store Connect deploy key is a different key). Paste the Sign in with Apple .p8 and retry.',
    },
    webhooks: {
        ok: 'Webhooks are switched on for this environment. Nothing is sent until a partner subscription exists — add one on the Webhooks page.',
        ok_elsewhere: 'Saved and switched on for the ticked environments — note this environment itself is not one of them, so nothing sends from here.',
        unconfigured: 'Saved — with no environment ticked, webhooks stay fully dormant. That is a valid choice, not an error.',
        schema_missing: 'The webhook tables are not installed on this database yet — run the webhook set-up card on the Database Setup page, then retry.',
    },
};

function statusCopy(key, status) {
    const perKey = STATUS_COPY[key] || {};
    return perKey[status] || ('Saved. Status: ' + status + '.');
}

/**
 * Build (once per modal open) the whole set of `[data-wiz-step]` panes for
 * `entry` into `panesEl`, and return `{ stepNames, refs }` — `stepNames` is
 * the logical name for each pane IN THE SAME ORDER `createWizard()` will
 * derive them from the DOM (index-aligned), and `refs` carries the handful
 * of elements later logic needs to reach directly (need-list/portal nodes,
 * the test-status container).
 */
function buildPanes(entry, panesEl) {
    panesEl.innerHTML = '';
    const stepNames = [];
    const refs = {};

    function pane(name, heading) {
        const section = document.createElement('section');
        section.setAttribute('data-wiz-step', '');
        section.setAttribute('data-wiz-label', heading);
        const h = document.createElement('h3');
        h.setAttribute('data-wiz-heading', '');
        h.className = 'h6 mb-3';
        h.textContent = heading;
        section.appendChild(h);
        const alert = document.createElement('div');
        alert.setAttribute('role', 'alert');
        alert.setAttribute('data-wiz-alert', '');
        alert.className = 'alert alert-danger py-2';
        alert.hidden = true;
        section.appendChild(alert);
        panesEl.appendChild(section);
        stepNames.push(name);
        return section;
    }

    /* 1 — About */
    const aboutPane = pane('about', 'About ' + entry.label);
    const badge = document.createElement('span');
    badge.className = 'badge ' + (entry.active ? 'bg-success' : 'bg-secondary');
    badge.textContent = entry.active ? 'Currently active' : 'Currently dormant';
    const badgeP = document.createElement('p');
    badgeP.className = 'mb-2';
    badgeP.appendChild(badge);
    aboutPane.appendChild(badgeP);
    const introP = document.createElement('p');
    introP.className = 'text-secondary';
    introP.textContent = entry.intro;
    aboutPane.appendChild(introP);

    /* 2 — Choose provider (only when this integration has one) */
    if (entry.providerField && entry.providers) {
        const providerPane = pane('provider', 'Choose a provider');
        providerPane.appendChild(renderProviderSelect(entry));
    }

    /* 3 — What you'll need */
    const needPane = pane('need', "What you'll need");
    const needList = document.createElement('ul');
    needPane.appendChild(needList);
    const portalP = document.createElement('p');
    portalP.className = 'mb-0';
    needPane.appendChild(portalP);
    refs.needList = needList;
    refs.portalP = portalP;
    renderNeedPane(entry, needList, portalP);

    /* 4 — Credentials (every field EXCEPT the provider field, which lives
       on its own pane above — see renderField()'s own note — and EXCEPT any
       `carry` field, §2.4: a value the save handler must receive back but
       that has no business being shown as an editable box — see the SIWA
       APNs-Key-ID carry field's own registry comment). */
    const credPane = pane('credentials', 'Paste your credentials');
    const fieldsWrap = document.createElement('div');
    entry.fields.forEach((field) => {
        if (field.post === entry.providerField || field.carry) { return; }
        fieldsWrap.appendChild(renderField(entry, field));
    });
    credPane.appendChild(fieldsWrap);

    /* 5 — Save & test (the LAST step — createWizard() calls opts.onFinish()
       when Next is activated while already here; this driver ALSO
       auto-runs the same sequence the moment this step is first entered —
       see wireWizard()'s onStepChange). */
    const testPane = pane('save', 'Save & test');
    const statusEl = document.createElement('div');
    statusEl.setAttribute('data-icw-test-status', '');
    /* a11y audit A7 (2026-08-30) — "Saving and testing…" -> a verdict is an
       async content swap with no page navigation; role="status" (implicit
       aria-live="polite" + aria-atomic="true") means a screen-reader user
       hears each new state without having to keep re-checking the pane.
       tabIndex is set so runSaveAndTest() below can move focus HERE when
       disabling the button the user just clicked (see setBusy()). */
    statusEl.setAttribute('role', 'status');
    statusEl.tabIndex = -1;
    testPane.appendChild(statusEl);
    refs.statusEl = statusEl;

    /* #2004 — every conditional field's initial visibility, resolved ONCE
       right after every field/select exists in the DOM (so the credentials
       pane opens already correct rather than flashing then settling) —
       re-applied again on every change (openWizard()'s delegated listener). */
    applyShowWhen(entry, panesEl);

    return { stepNames, refs };
}

function renderBusyStatus(statusEl) {
    statusEl.innerHTML = '';
    const p = document.createElement('p');
    p.className = 'text-secondary';
    const spinner = document.createElement('span');
    spinner.className = 'spinner-border spinner-border-sm me-2';
    spinner.setAttribute('aria-hidden', 'true');
    p.appendChild(spinner);
    p.appendChild(document.createTextNode('Saving and testing…'));
    statusEl.appendChild(p);
}

/** Render a failed/erroring verdict into the Save & test pane, with a
 *  "Back to credentials" affordance (plan §7.2 item 5c). */
function renderFailureStatus(statusEl, message, onBackToCredentials) {
    statusEl.innerHTML = '';
    const p = document.createElement('p');
    p.className = 'text-danger mb-2';
    const icon = document.createElement('i');
    icon.className = 'bi bi-exclamation-triangle me-1';
    icon.setAttribute('aria-hidden', 'true');
    p.appendChild(icon);
    p.appendChild(document.createTextNode(message));
    statusEl.appendChild(p);
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-sm btn-outline-secondary';
    btn.textContent = 'Back to credentials';
    btn.addEventListener('click', onBackToCredentials);
    statusEl.appendChild(btn);
}

/** Render an in-progress "connected" verdict PLUS any envelope warning
 *  (the intapps incomplete-channel notice / captcha both-doors notice)
 *  before the driver swaps to the DONE pane. */
function renderOkStatus(statusEl, message, warning) {
    statusEl.innerHTML = '';
    const p = document.createElement('p');
    p.className = 'text-success mb-1';
    const icon = document.createElement('i');
    icon.className = 'bi bi-check-circle me-1';
    icon.setAttribute('aria-hidden', 'true');
    p.appendChild(icon);
    p.appendChild(document.createTextNode(message));
    statusEl.appendChild(p);
    if (warning) {
        const w = document.createElement('p');
        w.className = 'text-warning-emphasis small mb-0';
        w.textContent = warning;
        statusEl.appendChild(w);
    }
}

/**
 * Render the show-once secret-key reveal block (#2004, §2.5 — today only
 * the webhooks drain key, minted by `save_webhooks` on a "regenerate now"
 * tick). The key was minted by the SAVE, not the TEST that follows it, so
 * this is rendered — verbatim, from the SAME `oneTimeKey` value — into BOTH
 * the Save & test status area (regardless of the test's own verdict) AND
 * the DONE pane (renderDonePane() below), never decided by whether the
 * connection test passed.
 */
function renderOneTimeKeyWarning(oneTimeKey) {
    const wrap = document.createElement('div');
    wrap.className = 'alert alert-warning mt-2 mb-0';
    wrap.setAttribute('role', 'alert');
    const strong = document.createElement('strong');
    strong.textContent = 'New key — copy it now. It is shown only once.';
    wrap.appendChild(strong);
    const code = document.createElement('code');
    code.className = 'user-select-all d-block mt-1';
    code.textContent = oneTimeKey;
    wrap.appendChild(code);
    const p = document.createElement('p');
    p.className = 'small mb-0 mt-1';
    p.textContent = 'Use it as ?key=… on the webhook drain endpoint (the cron command is on the Partner webhooks card).';
    wrap.appendChild(p);
    return wrap;
}

/**
 * Build the DONE pane's content — success line, the registry's own
 * `surfaces` list, the show-once key reveal when one was minted (#2004),
 * and (CueRCode only) the live `/qr` proof image (owner sub-decision O5,
 * plan §14 — "the most honest confirmation possible").
 */
function renderDonePane(entry, doneBodyEl, warning, oneTimeKey) {
    doneBodyEl.innerHTML = '';

    const p = document.createElement('p');
    const icon = document.createElement('i');
    icon.className = 'bi bi-check-circle-fill text-success me-1';
    icon.setAttribute('aria-hidden', 'true');
    p.appendChild(icon);
    p.appendChild(document.createTextNode(entry.label + ' is set up and tested.'));
    doneBodyEl.appendChild(p);

    if (warning) {
        const w = document.createElement('p');
        w.className = 'text-warning-emphasis small';
        w.textContent = warning;
        doneBodyEl.appendChild(w);
    }

    if (oneTimeKey) {
        doneBodyEl.appendChild(renderOneTimeKeyWarning(oneTimeKey));
    }

    if (entry.surfaces && entry.surfaces.length > 0) {
        const label = document.createElement('p');
        label.className = 'mb-1 fw-semibold small';
        label.textContent = 'Where this shows up:';
        doneBodyEl.appendChild(label);
        const ul = document.createElement('ul');
        ul.className = 'small';
        entry.surfaces.forEach((s) => {
            const li = document.createElement('li');
            if (s.href) {
                const a = document.createElement('a');
                a.href = s.href;
                a.textContent = s.label;
                li.appendChild(a);
            } else {
                li.textContent = s.label;
            }
            ul.appendChild(li);
        });
        doneBodyEl.appendChild(ul);
    }

    if (entry.key === 'cuercode') {
        const proofP = document.createElement('p');
        proofP.className = 'mt-2';
        const img = document.createElement('img');
        /* The SAME extensionless /qr door every consumer surface uses (see
           this module's own file doc-block) — never /qr.php. */
        img.src = '/qr?data=' + encodeURIComponent('https://ihymns.app/') + '&format=svg&size=160';
        img.alt = 'Live QR test image';
        img.width = 160;
        img.height = 160;
        img.className = 'border rounded p-1 bg-white';
        const fallback = document.createElement('span');
        fallback.className = 'text-secondary small';
        fallback.textContent = 'The live QR image could not load — the always-present URL/code text on a printed page or the Projector Screen is the fallback.';
        fallback.hidden = true;
        img.addEventListener('error', () => {
            img.hidden = true;
            fallback.hidden = false;
        });
        proofP.appendChild(img);
        proofP.appendChild(fallback);
        doneBodyEl.appendChild(proofP);
    }
}

/**
 * Wire up ONE opened wizard instance: builds the panes, constructs
 * `createWizard()`, and returns a `{ wizard, teardown }` pair.
 */
function openWizard(entry, csrfToken, dom) {
    const { stepNames, refs } = buildPanes(entry, dom.panesEl);
    const credIndex = stepNames.indexOf('credentials');
    const needIndex = stepNames.indexOf('need');
    const saveIndex = stepNames.length - 1; /* 'save' is always last */

    let inFlight = false;

    function setBusy(busy) {
        /* a11y audit A7 (2026-08-30) — clicking "Retry test" (dom.nextBtn on
           the last step) disables the very button that just had focus;
           disabling a focused element silently drops focus to <body> with
           no announcement of what happened next. Move focus into the
           (role="status", tabIndex=-1) verdict area FIRST, so a keyboard/
           screen-reader user keeps a sensible focus position and hears the
           "Saving and testing…" update land, matching the DONE pane's own
           already-correct focus-to-heading convention. */
        if (busy && dom.nextBtn && document.activeElement === dom.nextBtn && refs.statusEl) {
            refs.statusEl.focus();
        }
        if (dom.nextBtn) { dom.nextBtn.disabled = busy; }
        if (dom.backBtn) { dom.backBtn.disabled = busy; }
    }

    function showStepAlertAndGo(index, message) {
        wizard.goTo(index);
        const stepEl = dom.panesEl.querySelectorAll('[data-wiz-step]')[index];
        const alertEl = stepEl && stepEl.querySelector('[data-wiz-alert]');
        if (alertEl) {
            alertEl.hidden = false;
            alertEl.textContent = message;
            alertEl.focus();
        }
    }

    function runSaveAndTest() {
        if (inFlight) { return; }
        inFlight = true;
        setBusy(true);
        renderBusyStatus(refs.statusEl);

        const saveFields = collectFields(entry).concat([['respond', 'json']]);
        postForm(entry.saveAction, csrfToken, saveFields).then((saveResult) => {
            if (!(saveResult.data && saveResult.data.ok)) {
                inFlight = false;
                setBusy(false);
                if (saveResult.status === 403) {
                    renderFailureStatus(refs.statusEl, 'Session expired — refresh the page and try again.', () => {});
                    return;
                }
                const message = (saveResult.data && saveResult.data.error) || 'Could not save. Please try again.';
                renderFailureStatus(refs.statusEl, message, () => showStepAlertAndGo(credIndex, message));
                return;
            }
            const saveWarning = saveResult.data.warning || null;
            /* #2004 §2.5 — the show-once drain key, captured from the SAVE
               response, NEVER the test response that follows: the key was
               already minted and persisted the instant the save succeeded,
               so whether the admin ever sees it must not depend on the
               connection test's verdict. Rendered into the status area
               below REGARDLESS of that verdict, and again on the done pane. */
            const oneTimeKey = (saveResult.data && typeof saveResult.data.drainKey === 'string' && saveResult.data.drainKey !== '')
                ? saveResult.data.drainKey
                : null;
            function revealOneTimeKeyIfAny() {
                if (oneTimeKey) { refs.statusEl.appendChild(renderOneTimeKeyWarning(oneTimeKey)); }
            }

            return postForm('integration_test', csrfToken, [['integration', entry.key]]).then((testResult) => {
                inFlight = false;
                setBusy(false);
                if (testResult.status === 403) {
                    renderFailureStatus(refs.statusEl, 'Session expired — refresh the page and try again.', () => {});
                    revealOneTimeKeyIfAny();
                    return;
                }
                if (!testResult.data || typeof testResult.data.status !== 'string') {
                    renderFailureStatus(refs.statusEl, 'Saved, but the connection test could not run. You can try again, or close this and check the card\'s own status.', () => {});
                    revealOneTimeKeyIfAny();
                    return;
                }
                const status = testResult.data.status;
                const message = statusCopy(entry.key, status);
                if (testResult.data.ok === true) {
                    renderOkStatus(refs.statusEl, message, saveWarning);
                    revealOneTimeKeyIfAny();
                    showDone(entry, saveWarning, oneTimeKey);
                } else {
                    renderFailureStatus(refs.statusEl, message, () => showStepAlertAndGo(credIndex, message));
                    revealOneTimeKeyIfAny();
                }
            });
        }).catch(() => {
            inFlight = false;
            setBusy(false);
            renderFailureStatus(refs.statusEl, 'Could not reach the server. Please check your connection and try again.', () => {});
        });
    }

    function showDone(entryArg, warning, oneTimeKey) {
        renderDonePane(entryArg, dom.doneBodyEl, warning, oneTimeKey);
        dom.stepsWrapEl.hidden = true;
        dom.doneEl.hidden = false;
        if (dom.backBtn) { dom.backBtn.hidden = true; }
        if (dom.nextBtn) { dom.nextBtn.hidden = true; }
        if (dom.doneCloseBtn) { dom.doneCloseBtn.hidden = false; }
        if (dom.doneHeadingEl) { dom.doneHeadingEl.focus(); }
    }

    const wizard = createWizard(dom.modalContentEl, {
        host: 'bootstrap-modal',
        validateStep(stepIndex) {
            const name = stepNames[stepIndex];
            if (name === 'provider') {
                const sel = document.getElementById('icw-f-' + entry.providerField);
                const chosen = sel && sel.value;
                if (!chosen || !(entry.providers && entry.providers[chosen])) {
                    return { ok: false, message: 'Choose a provider to continue.', focus: sel || undefined };
                }
                return true;
            }
            if (name === 'credentials') {
                return validateCredentialsFields(entry);
            }
            return true;
        },
        onStepChange(_from, to) {
            const name = stepNames[to];
            if (name === 'need') {
                renderNeedPane(entry, refs.needList, refs.portalP);
            }
            if (dom.nextBtn) {
                dom.nextBtn.textContent = (to === saveIndex) ? 'Retry test' : 'Next';
            }
            if (to === saveIndex) {
                runSaveAndTest();
            }
        },
        onFinish() {
            /* Reached only by clicking Next/"Retry test" while ALREADY on
               the last step (createWizard()'s own next() semantics) — the
               FIRST arrival at this step is handled by onStepChange above,
               so this is purely the manual-retry path after a failure. */
            runSaveAndTest();
        },
    });

    /* First render — 'need' is index 0's neighbour, already rendered by
       buildPanes(); nothing else to prime before the wizard shows step 0. */
    void needIndex; /* (kept for readability at the call sites above) */

    return wizard;
}

/**
 * Entry point — wires the ONE shared modal to every "Set up with a guide"
 * button on the page (Bootstrap's one-modal-many-triggers mechanic: each
 * button carries `data-bs-target="#integrationConnectModal"` plus its own
 * `data-integration="<key>"`, read off `event.relatedTarget`).
 *
 * @param {{modalEl:HTMLElement, registry:object, csrfToken:string}} opts
 */
export function initIntegrationConnectWizard({ modalEl, registry, csrfToken }) {
    if (!modalEl) { return; }

    const dom = {
        modalContentEl: modalEl.querySelector('.modal-content'),
        stepsWrapEl:    modalEl.querySelector('[data-icw-steps]'),
        panesEl:        modalEl.querySelector('[data-icw-panes]'),
        doneEl:         modalEl.querySelector('[data-icw-done]'),
        doneBodyEl:     modalEl.querySelector('[data-icw-done-body]'),
        doneHeadingEl:  modalEl.querySelector('[data-icw-done-heading]'),
        nextBtn:        modalEl.querySelector('[data-wiz-next]'),
        backBtn:        modalEl.querySelector('[data-wiz-back]'),
        doneCloseBtn:   modalEl.querySelector('[data-icw-done-close]'),
    };
    if (!dom.modalContentEl || !dom.panesEl) { return; }

    let currentWizard = null;
    let currentEntry = null;

    /* #2004 §2.3 — showWhen re-evaluation on every change/input, delegated
       ONCE on the persistent `panesEl` node (not re-attached per modal
       open — buildPanes() clears and rebuilds panesEl's CHILDREN on every
       open, but panesEl itself is the SAME long-lived element across
       repeated opens of this ONE shared modal, so a listener added inside
       openWizard()/buildPanes() would stack a fresh copy on every re-open).
       Covers the credentials pane's own inputs AND the separate "Choose
       provider" pane's <select> — both are descendants of the SAME
       panesEl, so one delegated listener reaches both. No-ops harmlessly
       when no wizard is currently open (currentEntry null). */
    dom.panesEl.addEventListener('change', () => { if (currentEntry) { applyShowWhen(currentEntry, dom.panesEl); } });
    dom.panesEl.addEventListener('input', () => { if (currentEntry) { applyShowWhen(currentEntry, dom.panesEl); } });

    modalEl.addEventListener('show.bs.modal', (e) => {
        const trigger = e.relatedTarget;
        const key = trigger && trigger.dataset ? trigger.dataset.integration : null;
        const entry = key ? registry[key] : null;
        if (!entry) {
            /* No known integration for this trigger — nothing to build.
               Logged rather than silently doing nothing, so a future
               launcher button with a typo'd data-integration is loud in
               the console instead of opening an empty modal. */
            console.error('integration-connect-wizard: unknown integration key', key);
            return;
        }
        currentEntry = entry;
        currentWizard = openWizard(entry, csrfToken, dom);
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
        /* Reset to a clean slate every time the modal is opened again — the
           external-link-types.php precedent. Destroy first (removes this
           instance's own nav-button listeners) so a stale wizard can never
           double-handle the next open()'s events. */
        if (currentWizard) { currentWizard.destroy(); currentWizard = null; }
        currentEntry = null;
        dom.panesEl.innerHTML = '';
        if (dom.doneBodyEl) { dom.doneBodyEl.innerHTML = ''; }
        if (dom.stepsWrapEl) { dom.stepsWrapEl.hidden = false; }
        if (dom.doneEl) { dom.doneEl.hidden = true; }
        if (dom.backBtn) { dom.backBtn.hidden = false; dom.backBtn.disabled = false; }
        if (dom.nextBtn) { dom.nextBtn.hidden = false; dom.nextBtn.disabled = false; dom.nextBtn.textContent = 'Next'; }
        if (dom.doneCloseBtn) { dom.doneCloseBtn.hidden = true; }
    });

    /* After the DONE pane's Close button dismisses the modal, reload so the
       card badges/health strip reflect the newly-saved state (the
       external-link-types.php precedent — a DOM-patch alternative would
       fork the cards' own rendering, rule #1). */
    if (dom.doneCloseBtn) {
        dom.doneCloseBtn.addEventListener('click', () => { window.location.reload(); });
    }
}
