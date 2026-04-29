<?php

/**
 * iHymns — Song Request Submission (#403)
 *
 * Public-facing form that writes to tblSongRequests. Admins can
 * later triage submissions via the admin dashboard (follow-up).
 */

declare(strict_types=1);

?>
<section class="page-request-a-song" aria-label="Request a song">

    <h1 class="h4 mb-3">
        <i class="fa-solid fa-lightbulb me-2" aria-hidden="true"></i>
        Request a Song
    </h1>

    <p class="text-muted small mb-4">
        Can't find a hymn you're looking for? Let us know and we'll do our best
        to add it. Only the details below are sent to our curators — no other
        personal data.
    </p>

    <div id="request-success" class="alert alert-success d-none" role="alert">
        <i class="fa-solid fa-check-circle me-1" aria-hidden="true"></i>
        Thank you — your request has been received.
        <span id="request-tracking-id" class="text-muted small"></span>
    </div>
    <div id="request-queued" class="alert alert-info d-none" role="status">
        <i class="fa-solid fa-cloud-arrow-up me-1" aria-hidden="true"></i>
        Saved offline — we'll send this request as soon as you're back online.
        <span id="request-queued-count" class="text-muted small ms-1"></span>
    </div>
    <div id="request-error" class="alert alert-danger d-none" role="alert"></div>

    <form id="request-form" novalidate>
        <div class="row g-3">
            <div class="col-md-8">
                <label for="request-title" class="form-label">Song title <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="request-title" name="title"
                       required maxlength="500" autocomplete="off">
            </div>
            <div class="col-md-4">
                <label for="request-songbook" class="form-label">Songbook <span class="text-muted">(optional)</span></label>
                <input type="text" class="form-control" id="request-songbook" name="songbook"
                       maxlength="100" placeholder="e.g. Mission Praise" autocomplete="off">
            </div>
            <div class="col-md-12">
                <label for="request-details" class="form-label">Any extra details? <span class="text-muted">(first line of lyrics, writer name, etc.)</span></label>
                <textarea class="form-control" id="request-details" name="details"
                          rows="3" maxlength="2000"></textarea>
            </div>
            <div class="col-md-12">
                <label for="request-email" class="form-label">
                    Your email <span class="text-muted">(optional — only if you'd like us to follow up)</span>
                </label>
                <input type="email" class="form-control" id="request-email" name="email"
                       maxlength="255" autocomplete="email">
            </div>
            <!-- Honeypot field for spam bots — real users never see it. -->
            <input type="text" name="website" tabindex="-1" autocomplete="off"
                   style="position:absolute; left:-9999px; top:-9999px;" aria-hidden="true">
        </div>

        <div class="mt-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary" id="request-submit-btn">
                <i class="fa-solid fa-paper-plane me-1" aria-hidden="true"></i>
                Send Request
            </button>
            <a href="/" data-navigate="home" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>

    <?php
        /* Cache-buster for the offline-queue module import below.
           filemtime() returns false if the file is missing/unreadable;
           in that case render `?v=false` would still parse but break
           cache-busting — fall back to the app version stamp instead
           so a deploy still busts the cache. (#526) */
        $_offlineQueuePath = dirname(__DIR__, 2) . '/public_html/js/modules/offline-queue.js';
        $_offlineQueueVer  = @filemtime($_offlineQueuePath);
        if ($_offlineQueueVer === false) {
            $_offlineQueueVer = $app['Application']['Version']['Number'] ?? '0';
            error_log('[request-a-song] filemtime fallback for offline-queue.js — file missing or unreadable at ' . $_offlineQueuePath);
        }
    ?>
    <script type="module">
    /* Offline queue wiring (#337). The queue module is lazy-loaded so
       a network hiccup during module fetch doesn't break the page —
       if the import fails we fall back to plain online-only submission. */
    import { offlineQueue } from '/js/modules/offline-queue.js?v=<?= urlencode((string)$_offlineQueueVer) ?>';

    const form = document.getElementById('request-form');
    if (form) {
        const okEl     = document.getElementById('request-success');
        const queuedEl = document.getElementById('request-queued');
        const errEl    = document.getElementById('request-error');
        const btn      = document.getElementById('request-submit-btn');
        const countEl  = document.getElementById('request-queued-count');

        /* Prefill from query string (#660) — used by the editor's
           missing-numbers panel and the standalone missing-numbers
           page when an editor wants to log a request for a numbered
           gap. Only writes into a field if the param is present and
           non-empty; cap lengths to match the input maxlengths so a
           crafted URL can't bypass the form's own caps. */
        const qp = new URLSearchParams(window.location.search);
        const prefillSongbook = (qp.get('songbook') || '').trim().slice(0, 100);
        const prefillNumber   = (qp.get('number')   || '').trim().slice(0, 500);
        if (prefillSongbook) {
            form.elements['songbook'].value = prefillSongbook;
        }
        if (prefillNumber) {
            /* The missing-numbers tools pass the song number; populate
               the title field with it so the editor sees what number
               they're requesting and only has to add the actual song
               title. */
            form.elements['title'].value = prefillNumber;
        }

        const hideAll = () => {
            okEl.classList.add('d-none');
            queuedEl.classList.add('d-none');
            errEl.classList.add('d-none');
        };

        /* Single send function — reused by live submit AND by the
           drain handler once connectivity returns. Returns the
           fetch Response so the caller can inspect status. */
        const send = async (payload) => {
            const res = await fetch('/api?action=song_request_submit', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body:    JSON.stringify(payload),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.ok) {
                throw new Error(data.error || 'Request failed.');
            }
            return data;
        };

        /* Drain handler used by bindAutoDrain. Returns truthy so the
           queue deletes the row on success, throws to keep it for retry. */
        const drainSend = async (payload) => {
            await send(payload);
            return true;
        };

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            hideAll();
            btn.disabled = true;

            const payload = {
                title:    form.elements['title'].value.trim(),
                songbook: form.elements['songbook'].value.trim(),
                details:  form.elements['details'].value.trim(),
                email:    form.elements['email'].value.trim(),
                website:  form.elements['website'].value, /* honeypot */
            };

            /* Offline → enqueue directly, don't even try the network.
               The `navigator.onLine` signal is advisory (some browsers
               lie), so we also catch fetch TypeErrors below as a
               secondary offline signal. */
            if (!navigator.onLine) {
                try {
                    const id = await offlineQueue.enqueue('song-requests', payload);
                    countEl.textContent = `Reference: offline-#${id}`;
                    queuedEl.classList.remove('d-none');
                    form.reset();
                } catch (err) {
                    errEl.textContent = 'Could not save your request offline. Please try again when connected.';
                    errEl.classList.remove('d-none');
                }
                btn.disabled = false;
                return;
            }

            try {
                const data = await send(payload);
                okEl.querySelector('#request-tracking-id').textContent =
                    data.trackingId ? `Reference: #${data.trackingId}` : '';
                okEl.classList.remove('d-none');
                form.reset();
            } catch (err) {
                /* TypeError from fetch usually means the request never
                   left the device (DNS fail, offline between the
                   navigator.onLine check and the socket). Queue it. */
                if (err instanceof TypeError) {
                    try {
                        const id = await offlineQueue.enqueue('song-requests', payload);
                        countEl.textContent = `Reference: offline-#${id}`;
                        queuedEl.classList.remove('d-none');
                        form.reset();
                    } catch (_qerr) {
                        errEl.textContent = 'Could not reach the server and could not save offline.';
                        errEl.classList.remove('d-none');
                    }
                } else {
                    errEl.textContent = err.message || 'Could not submit — please try again later.';
                    errEl.classList.remove('d-none');
                }
            } finally {
                btn.disabled = false;
            }
        });

        /* Replay any queued requests from a prior offline visit. The
           queue module handles the online + SW sync triggers; we just
           tell it what to do with each payload. */
        offlineQueue.bindAutoDrain('song-requests', drainSend, (r) => {
            if (r && r.sent > 0) {
                countEl.textContent = `Flushed ${r.sent} offline request${r.sent === 1 ? '' : 's'}.`;
                queuedEl.classList.remove('d-none');
            }
        });
    }
    </script>

</section>
