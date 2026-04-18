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

    <script>
    (function () {
        const form = document.getElementById('request-form');
        if (!form) return;

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const okEl  = document.getElementById('request-success');
            const errEl = document.getElementById('request-error');
            const btn   = document.getElementById('request-submit-btn');
            okEl.classList.add('d-none');
            errEl.classList.add('d-none');
            btn.disabled = true;

            const payload = {
                title:    form.elements['title'].value.trim(),
                songbook: form.elements['songbook'].value.trim(),
                details:  form.elements['details'].value.trim(),
                email:    form.elements['email'].value.trim(),
                website:  form.elements['website'].value, /* honeypot */
            };

            try {
                const res = await fetch('/api?action=song_request_submit', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify(payload),
                });
                const data = await res.json();
                if (!res.ok || !data.ok) {
                    throw new Error(data.error || 'Something went wrong.');
                }
                okEl.querySelector('#request-tracking-id').textContent = data.trackingId ? `Reference: #${data.trackingId}` : '';
                okEl.classList.remove('d-none');
                form.reset();
            } catch (err) {
                errEl.textContent = err.message || 'Could not submit — please try again later.';
                errEl.classList.remove('d-none');
            } finally {
                btn.disabled = false;
            }
        });
    })();
    </script>

</section>
