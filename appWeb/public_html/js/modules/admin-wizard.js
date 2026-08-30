/**
 * admin-wizard.js — shared multi-step "wizard" stepper framework (#1992)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * A "wizard" is a form split across several screens with Next/Back buttons
 * and a little "you are here" progress trail at the top — think "set up a
 * new thing in 4 steps". This file is the ONE reusable engine for that
 * shape of UI on `/manage/*` admin pages: it knows how to show one step at
 * a time, move focus sensibly when the step changes, stop you going
 * forward until the current step is valid, and draw the progress trail —
 * and NOTHING about what any particular wizard's steps actually contain.
 * The External Link Types "Add provider (guided)" wizard (#1992) is the
 * FIRST thing built on top of this; several more are planned, which is why
 * this file carries zero domain knowledge of any one of them.
 *
 * DETAILED — WHY A SHARED MODULE (CLAUDE.md modularity rule)
 * ----------------------------------------------------------------------------
 * Every "wizard" in this codebase would otherwise reinvent the same five
 * things: which pane is visible, where keyboard focus goes when the pane
 * changes, how Next is blocked until the pane is valid, how a progress
 * trail is drawn, and how a Bootstrap-modal host and a bespoke overlay
 * host each supply (or must NOT supply twice) a focus trap. Getting any
 * one of those wrong is a silent, easy-to-miss accessibility regression —
 * exactly CLAUDE.md's red-flag class of "looks alive, fails on the exact
 * path a keyboard/screen-reader user takes". Building it once, here, means
 * every future wizard inherits a11y correctness instead of re-deriving it.
 *
 * MARKUP CONTRACT — steps are DERIVED FROM THE DOM, never a JS-side list
 * (rule #35: a maintained JS array of "the 4 steps" and the HTML pane order
 * are two facts nothing keeps in sync; the #1581 event-name class of bug).
 * Under `rootEl`:
 *   [data-wiz-step]            one per step, in DOM order — that order IS
 *                               the step order. Each MAY carry:
 *     data-wiz-label="…"         short label used in the progress trail.
 *     [data-wiz-heading]         the step's own heading element — given
 *                                 tabindex="-1" and focused on every step
 *                                 change (WCAG 2.4.3 focus order).
 *     [data-wiz-alert]           an (initially hidden) element this module
 *                                 fills with a validation failure's message
 *                                 and may move focus to — host should give
 *                                 it role="alert" so it self-announces.
 *   [data-wiz-progress]        (optional) filled with a generated <ol>
 *                               progress trail; earlier steps become click-
 *                               to-go-back buttons, the current step gets
 *                               aria-current="step", later steps are inert
 *                               text (never a forward-skip control — a
 *                               step that hasn't passed validation yet
 *                               cannot be jumped to from the trail).
 *   [data-wiz-next]            (optional) the host's own "Next" / "Finish"
 *                               button — this module wires its click and
 *                               nothing else (label/text is the host's).
 *   [data-wiz-back]            (optional) the host's own "Back" button —
 *                               hidden automatically on the first step.
 *
 * WHAT'S DELIBERATELY *OUT* OF THIS MODULE (the seam future wizards use):
 * field rendering, any suggestion/autofill/live-test behaviour, how a step
 * is actually validated (that's `opts.validateStep`, supplied by the
 * host), the save/submit transport, and all user-facing wording beyond the
 * generic fallback validation message. Domain logic belongs in the
 * consuming page/module, never here.
 *
 * @see appWeb/public_html/manage/external-link-types.php   first consumer (#1992 guided wizard)
 * @see appWeb/public_html/js/utils/dialog-a11y.js           the 'overlay' host's focus-trap/Escape/restore
 * @see https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/  WAI-ARIA dialog pattern this composes with
 * @see https://developer.mozilla.org/docs/Web/API/HTMLElement/focus  Element.focus() — silently a no-op on a non-renderable target
 */

let _wizHeadingIdSeq = 0;

/**
 * @typedef {Object} WizardValidationFailure
 * @property {false} ok
 * @property {string} message                    Shown in the step's [data-wiz-alert] slot.
 * @property {string|HTMLElement} [focus]         Where to move focus instead of the alert
 *                                                  itself — a CSS selector (resolved inside
 *                                                  the step's pane) or an element reference.
 */

/**
 * @typedef {Object} WizardOptions
 * @property {(stepIndex: number, panelEl: HTMLElement) => (true|string|WizardValidationFailure)} [validateStep]
 *     Called before advancing PAST `stepIndex` (never on Back, never on a
 *     progress-trail click, which only ever goes backward). Return `true`
 *     to allow the move; a string or a `{ok:false,...}` object blocks it
 *     and is shown in that step's [data-wiz-alert] slot.
 * @property {(fromIndex: number, toIndex: number) => void} [onStepChange]
 *     Fires after the DOM has updated for a successful step change (any
 *     direction) — the hook for a host to relabel its own Next button
 *     ("Next" -> "Create", say) on reaching the last step.
 * @property {() => void} [onFinish]
 *     Fires instead of advancing when Next is activated on the LAST step.
 *     This module does not know what "finish" means for any given wizard
 *     (save via fetch, submit a form, …) — that is entirely the host's.
 * @property {'bootstrap-modal'|'overlay'} [host='bootstrap-modal']
 *     Which DIALOG-LEVEL a11y layer applies (see module doc-block).
 *     'bootstrap-modal': the host's own Bootstrap Modal instance already
 *     supplies the focus trap, Escape-to-close and focus-restore — this
 *     module deliberately does NOT add a second trap on top of it.
 *     'overlay': this module lazily imports openModalDialog() from
 *     '../utils/dialog-a11y.js' and applies it to `rootEl` itself.
 * @property {Object} [overlayOptions]  Passed through to openModalDialog()
 *     when `host === 'overlay'` (e.g. `{ onClose, initialFocus }`).
 */

/**
 * @typedef {Object} Wizard
 * @property {() => void} next     Validate (if `opts.validateStep` supplied)
 *                                  and advance one step; on the last step,
 *                                  calls `opts.onFinish()` instead.
 * @property {() => void} back     Move back one step. Never validated.
 * @property {(index: number) => void} goTo
 *     Jump directly to `index`, no validation — the mechanism a host uses
 *     for save-error routing (e.g. a 409 response -> `wizard.goTo(0)` to
 *     surface a duplicate-slug error back on the identity step). Also what
 *     the generated progress trail's back-links call internally.
 * @property {number} currentStep  Read-only-in-spirit current step index (0-based).
 * @property {() => void} destroy  Removes this wizard's own event listeners
 *                                  (nav buttons, progress trail, the lazy
 *                                  overlay teardown). Does not touch the
 *                                  step panes' visibility/DOM — a host that
 *                                  wants a clean slate for next time calls
 *                                  `goTo(0)` and resets its own fields.
 */

/**
 * Build a step-by-step wizard over the [data-wiz-step] panes found under
 * `rootEl`. See the module doc-block for the full markup contract.
 *
 * @param {HTMLElement} rootEl  Container holding the [data-wiz-step] panes
 *                               (and, optionally, [data-wiz-progress] /
 *                               [data-wiz-next] / [data-wiz-back]).
 * @param {WizardOptions} [opts]
 * @returns {Wizard}
 */
export function createWizard(rootEl, opts = {}) {
    if (!rootEl) {
        throw new Error('createWizard: rootEl is required');
    }
    const steps = Array.from(rootEl.querySelectorAll('[data-wiz-step]'));
    if (steps.length === 0) {
        throw new Error('createWizard: no [data-wiz-step] panes found under rootEl');
    }

    const progressHost = rootEl.querySelector('[data-wiz-progress]');
    const nextBtn = rootEl.querySelector('[data-wiz-next]');
    const backBtn = rootEl.querySelector('[data-wiz-back]');
    const hostMode = opts.host === 'overlay' ? 'overlay' : 'bootstrap-modal';

    let current = 0;
    let destroyed = false;
    let overlayClose = null;
    /* a11y audit A21 (2026-08-30) — which form control (if any) is currently
       marked aria-invalid because of a validateStep() failure, keyed by its
       PANE so clearStepError() (called for that same pane on the next
       successful move) knows exactly what to undo. A WeakMap rather than a
       DOM attribute on the pane itself — nothing here needs to survive a
       page reload or be visible to anyone but this module. */
    const invalidTargets = new WeakMap();

    /* One-time per-pane setup: role/aria wiring + heading tabindex. Doing
       this once at construction (rather than on every render()) keeps
       render() cheap and idempotent. */
    steps.forEach((pane) => {
        pane.setAttribute('role', 'group');
        const heading = pane.querySelector('[data-wiz-heading]');
        if (heading) {
            if (!heading.id) {
                heading.id = `wiz-heading-${++_wizHeadingIdSeq}`;
            }
            heading.tabIndex = -1;
            pane.setAttribute('aria-labelledby', heading.id);
        }
        const alertEl = pane.querySelector('[data-wiz-alert]');
        if (alertEl) {
            alertEl.tabIndex = -1;
            if (!alertEl.hasAttribute('hidden')) {
                alertEl.hidden = true;
            }
        }
    });

    function stepLabel(pane, index) {
        return pane.getAttribute('data-wiz-label') || `Step ${index + 1}`;
    }

    function renderProgress() {
        if (!progressHost) { return; }
        progressHost.textContent = '';
        const ol = document.createElement('ol');
        ol.className = 'admin-wizard-progress list-unstyled d-flex flex-wrap gap-2 small mb-0';
        /* a11y audit F9 — the trail had no accessible name of its own (a
           screen reader landing on it just hears "list"), so name it
           directly rather than requiring every consumer page to wrap
           progressHost in its own <nav aria-label>. */
        ol.setAttribute('aria-label', 'Steps');
        steps.forEach((pane, i) => {
            const li = document.createElement('li');
            const label = stepLabel(pane, i);
            if (i === current) {
                li.setAttribute('aria-current', 'step');
                li.className = 'fw-semibold';
                li.textContent = `${i + 1}. ${label}`;
            } else if (i < current) {
                /* Visited — clickable, BACK ONLY. A step ahead of `current`
                   is never rendered as a control here, which is what makes
                   "visited clickable, back only" true structurally rather
                   than by a runtime guard someone could bypass. */
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn btn-link btn-sm p-0 text-decoration-none';
                btn.textContent = `${i + 1}. ${label}`;
                btn.addEventListener('click', () => goTo(i));
                li.appendChild(btn);
            } else {
                /* a11y audit F9 — aria-disabled is only valid on a widget
                   role (button, link, …); a plain <li> (implicit role
                   listitem) does not accept it, so it was previously
                   dropped by the accessibility tree entirely. This future
                   step is genuinely inert (no control, no click handler,
                   nothing to disable) — that inertness is already conveyed
                   structurally (no button here at all) and visually
                   (text-muted), so the fix is simply to stop asserting an
                   invalid attribute rather than to find a valid one. */
                li.className = 'text-muted';
                li.textContent = `${i + 1}. ${label}`;
            }
            ol.appendChild(li);
        });
        progressHost.appendChild(ol);
    }

    function renderNav() {
        if (backBtn) {
            backBtn.hidden = (current === 0);
        }
    }

    function renderPanes() {
        steps.forEach((pane, i) => {
            pane.hidden = (i !== current);
        });
    }

    function render() {
        renderPanes();
        renderProgress();
        renderNav();
    }

    /* a11y audit A21 — is `el` a real form control? Only these ever get
       aria-invalid/aria-describedby: marking the alert itself "invalid"
       would be meaningless (it isn't a form control), and a link/button
       focus target (e.g. ICW's "choose a provider" <select> IS a control,
       but a plain informational focus target wouldn't be) has no invalid
       STATE to carry. */
    function isFormControl(el) {
        return !!el && ['INPUT', 'SELECT', 'TEXTAREA'].includes(el.tagName);
    }

    /* Undo whatever showStepError() last marked invalid for THIS pane, if
       anything — shared by clearStepError() (the normal "error resolved"
       path) and by showStepError() itself (so marking a NEW target first
       un-marks a stale one, rather than leaving two controls both flagged
       invalid). */
    function clearInvalidMarking(pane) {
        const prevTarget = invalidTargets.get(pane);
        if (!prevTarget) { return; }
        prevTarget.removeAttribute('aria-invalid');
        const alertEl = pane.querySelector('[data-wiz-alert]');
        if (alertEl && prevTarget.getAttribute('aria-describedby') === alertEl.id) {
            prevTarget.removeAttribute('aria-describedby');
        }
        invalidTargets.delete(pane);
    }

    function clearStepError(pane) {
        const alertEl = pane.querySelector('[data-wiz-alert]');
        if (alertEl) {
            alertEl.hidden = true;
            alertEl.textContent = '';
        }
        clearInvalidMarking(pane);
    }

    function showStepError(pane, message, focusTarget) {
        const alertEl = pane.querySelector('[data-wiz-alert]');
        if (alertEl) {
            alertEl.hidden = false;
            alertEl.textContent = message;
        }
        let target = null;
        if (focusTarget) {
            target = (typeof focusTarget === 'string') ? pane.querySelector(focusTarget) : focusTarget;
        }
        if (!target) { target = alertEl; }

        /* a11y audit A21 (2026-08-30) — when the failure names a REAL form
           control (not just the generic alert), tie it to the alert's text
           via aria-invalid + aria-describedby, so a screen-reader user who
           tabs back to that control hears WHY it's flagged, not just that
           it is. https://www.w3.org/WAI/ARIA/apg/practices/form-hints/ */
        clearInvalidMarking(pane);
        if (target && target !== alertEl && isFormControl(target)) {
            target.setAttribute('aria-invalid', 'true');
            if (alertEl) {
                if (!alertEl.id) { alertEl.id = `wiz-alert-${++_wizHeadingIdSeq}`; }
                target.setAttribute('aria-describedby', alertEl.id);
            }
            invalidTargets.set(pane, target);
        }

        if (target && typeof target.focus === 'function') {
            target.focus();
        }
    }

    function focusHeading(pane) {
        const heading = pane.querySelector('[data-wiz-heading]');
        /* Element.focus() is a documented no-op when the target isn't
           currently focusable (e.g. the wizard's host modal is itself
           still display:none) — safe to call unconditionally, nothing to
           guard here. */
        if (heading) { heading.focus(); }
    }

    /**
     * Internal step-change core. `validate` is true only for a forward
     * move via next() — back()/goTo() never validate (see Wizard.goTo's
     * doc-block: it exists specifically so a host can route a save-time
     * server error to any step unconditionally).
     */
    function moveTo(index, validate) {
        if (destroyed) { return; }
        if (index < 0 || index >= steps.length || index === current) { return; }

        const fromPane = steps[current];
        if (validate && typeof opts.validateStep === 'function') {
            const result = opts.validateStep(current, fromPane);
            if (result !== true) {
                const message = (typeof result === 'string')
                    ? result
                    : (result && typeof result.message === 'string' && result.message)
                        ? result.message
                        : 'Please fix the highlighted issue before continuing.';
                const focusTarget = (result && typeof result === 'object') ? result.focus : null;
                showStepError(fromPane, message, focusTarget);
                return;
            }
        }

        clearStepError(fromPane);
        const from = current;
        current = index;
        render();
        focusHeading(steps[current]);
        if (typeof opts.onStepChange === 'function') {
            opts.onStepChange(from, current);
        }
    }

    function next() {
        if (destroyed) { return; }
        if (current === steps.length - 1) {
            if (typeof opts.onFinish === 'function') { opts.onFinish(); }
            return;
        }
        moveTo(current + 1, true);
    }

    function back() {
        moveTo(current - 1, false);
    }

    function goTo(index) {
        moveTo(index, false);
    }

    function onNextClick(e) { e.preventDefault(); next(); }
    function onBackClick(e) { e.preventDefault(); back(); }

    if (nextBtn) { nextBtn.addEventListener('click', onNextClick); }
    if (backBtn) { backBtn.addEventListener('click', onBackClick); }

    if (hostMode === 'overlay') {
        /* Lazy import so a 'bootstrap-modal' wizard (Bootstrap already
           supplies the trap) never pays for dialog-a11y.js at all. */
        import('../utils/dialog-a11y.js').then(({ openModalDialog }) => {
            if (destroyed) { return; }
            overlayClose = openModalDialog(rootEl, opts.overlayOptions || {});
        });
    }
    /* 'bootstrap-modal': deliberately nothing here — the host's own
       bootstrap.Modal instance owns the trap/Escape/restore. Adding a
       second trap on top would double-handle Escape and Tab (module
       doc-block's "MUST NOT double-trap"). */

    render();

    return {
        next,
        back,
        goTo,
        get currentStep() { return current; },
        destroy() {
            destroyed = true;
            if (nextBtn) { nextBtn.removeEventListener('click', onNextClick); }
            if (backBtn) { backBtn.removeEventListener('click', onBackClick); }
            if (typeof overlayClose === 'function') { overlayClose(); }
        },
    };
}
