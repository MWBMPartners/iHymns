/**
 * iHymns — Shared admin-side toast utility
 *
 * Single source of truth for non-blocking "your action was noticed"
 * notifications across every admin surface. Before this module the
 * codebase carried two independent implementations (editor/editor.js
 * showToast(), js/app.js this.showToast()) and most admin pages had
 * no toast at all — silent UX feedback was the norm.
 *
 * Exposed on `window.iHymnsToast`:
 *   show(message, type, duration)  → bootstrap.Toast instance | null
 *
 * Parameters:
 *   message  — plain text (rendered as textContent, no HTML injection risk)
 *   type     — 'info' (default) | 'success' | 'warning' | 'error'
 *              Maps to Bootstrap's text-bg-{info,success,warning,danger}.
 *   duration — ms before autohide. 0 keeps the toast pinned. Default 4000.
 *
 * Container: looks up `#toast-container`. If absent (most admin pages
 * don't ship one yet) it's lazily created and pinned bottom-right with
 * a z-index that beats Bootstrap's modal backdrop (1090). Pages that
 * already declare a #toast-container (notably manage/editor/index.php)
 * keep using theirs unchanged.
 *
 * Falls back to console.warn() if Bootstrap's JS hasn't loaded yet —
 * never throws.
 */

(function () {
    'use strict';

    function ensureContainer() {
        var c = document.getElementById('toast-container');
        if (c) return c;
        c = document.createElement('div');
        c.id = 'toast-container';
        c.className = 'toast-container position-fixed bottom-0 end-0 p-3';
        /* 11000 sits above Bootstrap's modal (1055) + modal-backdrop
           (1050) so the toast remains visible when fired from an
           open edit drawer. */
        c.style.zIndex = '11000';
        document.body.appendChild(c);
        return c;
    }

    function variantClass(type) {
        switch (type) {
            case 'success': return 'text-bg-success';
            case 'warning': return 'text-bg-warning';
            case 'error':
            case 'danger':  return 'text-bg-danger';
            case 'info':
            default:        return 'text-bg-info';
        }
    }

    function show(message, type, duration) {
        if (typeof bootstrap === 'undefined' || !bootstrap.Toast) {
            /* JS bundle didn't load — degrade silently rather than
               throwing, since callers (dedup detection, save handlers)
               shouldn't crash a save flow over a missing notification. */
            if (window && window.console) console.warn('[iHymnsToast]', message);
            return null;
        }
        var container = ensureContainer();
        var dur = (duration === 0) ? 0 : (Number(duration) || 4000);
        var toastEl = document.createElement('div');
        toastEl.className = 'toast align-items-center ' + variantClass(type) + ' border-0';
        toastEl.setAttribute('role', 'alert');
        toastEl.setAttribute('aria-live', 'polite');
        toastEl.setAttribute('aria-atomic', 'true');

        var flex = document.createElement('div');
        flex.className = 'd-flex';

        var body = document.createElement('div');
        body.className = 'toast-body';
        body.textContent = String(message == null ? '' : message);

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'btn-close btn-close-white me-2 m-auto';
        closeBtn.setAttribute('data-bs-dismiss', 'toast');
        closeBtn.setAttribute('aria-label', 'Close');

        flex.appendChild(body);
        flex.appendChild(closeBtn);
        toastEl.appendChild(flex);
        container.appendChild(toastEl);

        var instance = new bootstrap.Toast(toastEl, {
            delay: dur > 0 ? dur : 0,
            autohide: dur > 0,
        });
        toastEl.addEventListener('hidden.bs.toast', function () {
            toastEl.remove();
        });
        instance.show();
        return instance;
    }

    window.iHymnsToast = { show: show };
})();
