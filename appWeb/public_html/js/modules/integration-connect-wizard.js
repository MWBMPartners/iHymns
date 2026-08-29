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
 * ONE GENERIC DRIVER, NOT THREE (rule #1 / plan §D2): this module never
 * hardcodes a field name, a setting key, or a status word for any ONE
 * integration. Everything it renders comes from the `registry` object
 * passed to `initIntegrationConnectWizard()` — the JSON projection built by
 * `includes/integration_registry.php::integrationClientProjection()`. Adding
 * a Phase-2 integration (plan §12: email/SIWA/webhooks) is new PHP registry
 * data plus a new launcher button; this file needs no change.
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
 * @param {object} entry  the registry entry (for `formMeta` on a checkbox-group)
 * @param {object} field  one projected field
 * @returns {HTMLElement}
 */
function renderField(entry, field) {
    const id = 'icw-f-' + field.post;
    const wrap = document.createElement('div');
    wrap.className = 'mb-3';

    if (field.type === 'checkbox-group') {
        const legend = document.createElement('div');
        legend.className = 'form-label mb-1';
        legend.textContent = field.label;
        wrap.appendChild(legend);

        const grid = document.createElement('div');
        grid.className = 'row g-2';
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

    const input = document.createElement('input');
    input.id = id;
    input.className = 'form-control';
    if (isSecretField(field)) {
        input.type = 'password';
        input.autocomplete = 'off';
        input.placeholder = field.set ? '(unchanged — leave blank to keep)' : (field.placeholder || '');
    } else {
        /* Every Phase-1 non-secret credential field is plain text — the ONE
           select field (captcha_provider) is rendered on its own dedicated
           "Choose provider" pane instead (see renderProviderSelect()), so
           it never reaches this generic renderer. A future non-provider
           select field would need this branch extended; nothing in Phase 1
           exercises it. */
        input.type = 'text';
        input.value = field.value || '';
        if (field.placeholder) { input.placeholder = field.placeholder; }
    }
    wrap.appendChild(input);

    if (field.help) {
        const help = document.createElement('div');
        help.className = 'form-text small';
        help.textContent = field.help;
        wrap.appendChild(help);
    }
    return wrap;
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

/** Every non-secret + secret field this integration's SAVE handler reads
 *  unconditionally, PLUS every ticked checkbox-group value — collected
 *  generically by iterating `entry.fields` (never a hardcoded field-name
 *  list; test-integration-connect-wizard.php check (f) mutation-proves
 *  this). This is the carry-safety table from the plan (§8) made
 *  mechanical: every field the registry describes is always posted, so a
 *  classic handler's "unconditionally overwritten from $_POST" fields can
 *  never be silently wiped by an incomplete wizard POST. */
function collectFields(entry) {
    const out = [];
    entry.fields.forEach((field) => {
        if (field.type === 'checkbox-group') {
            document.querySelectorAll('input[type=checkbox][data-icw-cbgroup="' + field.post + '"]:checked')
                .forEach((cb) => { out.push([field.post + '[]', cb.value]); });
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
 *  remains the authority and its 422 routes straight back to this step. */
function validateCredentialsFields(entry) {
    for (const field of entry.fields) {
        if (field.type === 'checkbox-group' || !field.validate) { continue; }
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
       on its own pane above — see renderField()'s own note). */
    const credPane = pane('credentials', 'Paste your credentials');
    const fieldsWrap = document.createElement('div');
    entry.fields.forEach((field) => {
        if (field.post === entry.providerField) { return; }
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
    testPane.appendChild(statusEl);
    refs.statusEl = statusEl;

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
 * Build the DONE pane's content — success line, the registry's own
 * `surfaces` list, and (CueRCode only) the live `/qr` proof image (owner
 * sub-decision O5, plan §14 — "the most honest confirmation possible").
 */
function renderDonePane(entry, doneBodyEl, warning) {
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
            return postForm('integration_test', csrfToken, [['integration', entry.key]]).then((testResult) => {
                inFlight = false;
                setBusy(false);
                if (testResult.status === 403) {
                    renderFailureStatus(refs.statusEl, 'Session expired — refresh the page and try again.', () => {});
                    return;
                }
                if (!testResult.data || typeof testResult.data.status !== 'string') {
                    renderFailureStatus(refs.statusEl, 'Saved, but the connection test could not run. You can try again, or close this and check the card\'s own status.', () => {});
                    return;
                }
                const status = testResult.data.status;
                const message = statusCopy(entry.key, status);
                if (testResult.data.ok === true) {
                    renderOkStatus(refs.statusEl, message, saveWarning);
                    showDone(entry, saveWarning);
                } else {
                    renderFailureStatus(refs.statusEl, message, () => showStepAlertAndGo(credIndex, message));
                }
            });
        }).catch(() => {
            inFlight = false;
            setBusy(false);
            renderFailureStatus(refs.statusEl, 'Could not reach the server. Please check your connection and try again.', () => {});
        });
    }

    function showDone(entryArg, warning) {
        renderDonePane(entryArg, dom.doneBodyEl, warning);
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
        currentWizard = openWizard(entry, csrfToken, dom);
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
        /* Reset to a clean slate every time the modal is opened again — the
           external-link-types.php precedent. Destroy first (removes this
           instance's own nav-button listeners) so a stale wizard can never
           double-handle the next open()'s events. */
        if (currentWizard) { currentWizard.destroy(); currentWizard = null; }
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
