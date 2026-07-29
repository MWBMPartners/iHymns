/**
 * iHymns — Shared assistive-technology announcer (#1645)
 * ======================================================
 *
 * ELI5: a tiny invisible box that screen readers watch. Put a short sentence in
 * it and the screen reader says that sentence, without interrupting whatever it
 * was already reading.
 *
 * WHY THIS MODULE EXISTS
 * ----------------------
 * This SPA swaps page content without a document load, so nothing announces the
 * new page by default: a sighted user sees it instantly, a screen-reader user
 * gets silence.
 *
 * The site's first answer to that was to mark the WHOLE `<main>` element
 * `aria-live="polite"` and inject every fragment inside it. That inverts what a
 * live region is for. `aria-live` is for STATUS MESSAGES — short, specific,
 * "your changes were saved". Marking an entire page region live means that on
 * every navigation the breadcrumb, the toolbar and **every lyric line** are
 * queued for announcement, unprompted. Announcing everything is functionally
 * the same as announcing nothing useful, except far more annoying — and it
 * collided with the focus move `router.js` performs onto that same element, so
 * the user got two competing streams of speech and had to silence them on every
 * single navigation.
 *
 * The tell that it never worked: `setlist.js` had already built its own private
 * announcer, with a comment noting that "nothing announces the new song by
 * default". A functioning live `<main>` would have made that unnecessary. The
 * author hit the gap, worked around it locally, and the broken global region
 * stayed. That private helper is the code this module is extracted from — one
 * shared announcer instead of one per feature, per the modularity rule.
 *
 * WCAG 4.1.3 Status Messages (AA): satisfied by a small dedicated region that
 * says what changed, not by a large one that re-reads what didn't.
 * https://www.w3.org/WAI/WCAG21/Understanding/status-messages.html
 */

/**
 * The id of the single shared region.
 *
 * Deliberately generic. The extracted original called it
 * `playlist-live-region`, which was accurate when only setlist playback used
 * it and is misleading now that route changes and Service Mode do too.
 */
const REGION_ID = 'ihymns-live-region';

/**
 * Announce a short message to assistive technology.
 *
 * @param {string} message  One short sentence. Not a page's worth of content —
 *                          that is the mistake this module exists to undo.
 */
export function announce(message) {
    /* An empty announcement is a no-op rather than a cleared region: clearing
       would discard a message the AT may not have read out yet. */
    if (!message) { return; }

    let region = document.getElementById(REGION_ID);

    if (!region) {
        region = document.createElement('div');
        region.id = REGION_ID;
        /* Bootstrap's screen-reader-only utility: present to assistive tech,
           invisible and zero-size for everyone else. Not `display:none` and not
           `hidden`, either of which would remove it from the accessibility tree
           and make the whole thing silent.
           https://getbootstrap.com/docs/5.3/helpers/visually-hidden/ */
        region.className = 'visually-hidden';
        region.setAttribute('aria-live', 'polite');
        /* atomic: read the whole message, not just the changed words. */
        region.setAttribute('aria-atomic', 'true');
        document.body.appendChild(region);
    }

    /* LOAD-BEARING, and the single easiest thing here to "simplify" into
       silence: clear the region, then fill it on the NEXT frame.
     *
     * ELI5: the screen reader only notices the box when it CHANGES, so we have
     * to empty it and refill it rather than just setting it.
     *
     * Detail: assistive technology reports MUTATIONS to a live region it is
     * already observing. Two things follow. A region inserted into the DOM with
     * its text already in place is frequently not announced at all, because
     * from the AT's point of view nothing mutated after it started watching.
     * And setting the same text twice in a row is not a mutation either, so a
     * repeated message (moving a chip left, then left again) would be announced
     * once and then never again. Emptying first guarantees a real change; doing
     * the refill on the next frame guarantees the two writes are separate
     * mutations rather than being coalesced into one before the AT sees them.
     *
     * If you replace this with a plain assignment, nothing errors, no test
     * fails at runtime, and announcements simply stop happening for the users
     * who cannot see that they stopped.
     * https://developer.mozilla.org/en-US/docs/Web/Accessibility/ARIA/Attributes/aria-live
     */
    region.textContent = '';
    requestAnimationFrame(() => { region.textContent = message; });
}
