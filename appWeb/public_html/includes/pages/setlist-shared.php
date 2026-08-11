<?php

/**
 * iHymns — Shared Set List Page Template (#147, playlist-first #1790)
 *
 * PURPOSE:
 * The page a recipient lands on from a shared set-list link. It is
 * playlist-first (#1790): tapping any song — or the "Start set list"
 * button — arms the Prev/Next bar from this list with NO sign-in and NO
 * Import (setlist.js initSharedSetListPage); saving a personal copy is a
 * secondary action. This template is the shell that JS populates.
 *
 * Data comes from the server-side share store (`setlist_get`, keyed by the
 * short share id in the URL; a legacy base64-in-URL payload is still parsed
 * as a fallback). Loaded via AJAX: api.php?page=setlist-shared
 */

declare(strict_types=1);

?>

<!-- ================================================================
     SHARED SET LIST PAGE — Read-only view of a shared set list
     ================================================================ -->
<section class="page-setlist-shared" aria-label="Shared set list">

    <!-- Loading state (replaced by JS) -->
    <div id="shared-setlist-loading" class="text-center py-5">
        <div class="spinner-border text-primary mb-3" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
        <p class="text-muted">Loading shared set list...</p>
    </div>

    <!-- Error state (hidden by default) -->
    <div id="shared-setlist-error" class="d-none">
        <div class="alert alert-warning" role="alert">
            <i class="fa-solid fa-triangle-exclamation me-2" aria-hidden="true"></i>
            <strong>Invalid link</strong> — This shared set list link appears to be broken or expired.
        </div>
        <a href="/setlist" class="btn btn-outline-primary btn-sm" data-navigate="setlist">
            <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>
            Go to My Set Lists
        </a>
    </div>

    <!-- Unavailable state (#1380) — a LIVE-linked share whose underlying set list
         the owner has since deleted (server returns 410). Distinct from the
         "broken link" error above so a revoked share reads as intentional. -->
    <div id="shared-setlist-unavailable" class="d-none">
        <div class="alert alert-secondary" role="alert">
            <i class="fa-solid fa-circle-info me-2" aria-hidden="true"></i>
            <strong>No longer shared</strong> — This set list is no longer shared or available.
            The person who shared it may have removed it.
        </div>
        <a href="/setlist" class="btn btn-outline-primary btn-sm" data-navigate="setlist">
            <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>
            Go to My Set Lists
        </a>
    </div>

    <!-- Shared set list content (hidden by default, populated by JS) -->
    <div id="shared-setlist-content" class="d-none">

        <!-- Shared indicator banner — hidden by JS for the OWNER (#1535), who
             sees the owner note below instead of a "shared with you" message. -->
        <div id="shared-setlist-banner" class="alert alert-info d-flex align-items-center gap-2 mb-3" role="status">
            <i class="fa-solid fa-share-nodes" aria-hidden="true"></i>
            <div>
                <strong>Shared set list</strong>
                <!-- #1790 — playlist framing. Tapping any song already arms the
                     Prev/Next bar from this list (setlist.js initSharedSetListPage),
                     with no sign-in and no Import — so the copy now leads with that
                     instead of instructing the user to Import first. -->
                <small class="d-block">Tap any song to start &mdash; Prev/Next will follow this list. No sign-in needed.</small>
                <!-- Live-link note (#1380) — shown by JS only when the share resolves
                     the owner's CURRENT set list (data.live === true), so edits the
                     owner makes flow through to this view. -->
                <small id="shared-setlist-live-note" class="d-none d-block text-info-emphasis mt-1">
                    <i class="fa-solid fa-rotate me-1" aria-hidden="true"></i>
                    This set list updates live — you&rsquo;ll always see the latest version.
                </small>
            </div>
        </div>

        <!-- #1535 — Owner-viewing-own-share note. Shown by JS only when the
             server reports data.isOwner (the signed-in viewer IS this share's
             owner), replacing the "shared with you" banner + Import buttons.
             Also reinforces the #1380 live-share model for the owner. -->
        <div id="shared-setlist-owner-note" class="alert alert-secondary d-flex align-items-center gap-2 mb-3 d-none" role="status">
            <i class="fa-solid fa-user-check" aria-hidden="true"></i>
            <div>
                <strong>This is your set list</strong>
                <small class="d-block">You&rsquo;re viewing your own shared link — there&rsquo;s nothing to import. Anyone you shared it with always sees your latest changes.</small>
            </div>
        </div>

        <!-- Set list header -->
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h1 class="h4 mb-0" id="shared-setlist-name">
                <i class="fa-solid fa-list-ol me-2" aria-hidden="true"></i>
                <span id="shared-setlist-title"></span>
            </h1>
            <!-- #1790 — primary action is now PLAY, not Import. Arms the Prev/Next
                 bar from this list and jumps to song 1 (wired in setlist.js
                 initSharedSetListPage). Shown to everyone, owner included — you can
                 play your own shared list. Import is demoted to a secondary
                 "Save a copy" below. -->
            <button type="button" class="btn btn-primary btn-sm" id="shared-setlist-start-btn">
                <i class="fa-solid fa-play me-1" aria-hidden="true"></i>
                Start set list
            </button>
        </div>

        <p class="text-muted small mb-3">
            <span id="shared-setlist-count">0</span> song<span id="shared-setlist-plural">s</span>
        </p>

        <!-- #1791 G3 — "Shared by <name>" byline. Hidden by default; shown by
             JS only when the owner opted in for THIS link AND a live owner
             is known (shared-page privacy invariant, #1535/#1791). -->
        <small id="shared-setlist-byline" class="d-none d-block text-muted mb-2"></small>

        <!-- #1791 — sign-in prompt for an EDIT link whose EditAudience
             requires sign-in and the viewer isn't signed in. Read-only
             browsing (the song list + tap-to-play) is UNCHANGED — only the
             edit affordance is withheld until sign-in. -->
        <div id="shared-setlist-signin-prompt" class="alert alert-primary d-flex align-items-center justify-content-between gap-2 mb-3 d-none" role="status">
            <div>
                <strong>Sign in to edit this set list</strong>
                <small class="d-block">This link&rsquo;s owner requires sign-in to make changes. You can still view it and follow along.</small>
            </div>
            <button type="button" class="btn btn-sm btn-primary" id="shared-setlist-signin-btn">Sign in</button>
        </div>

        <!-- #1698/#1791 — owner-account-lock note: the edit link's OWNER
             account is disabled/deleted, so editing is frozen (viewing is
             not). Distinct from the sign-in prompt above — this one no
             sign-in can fix. -->
        <div id="shared-setlist-locked-note" class="alert alert-warning d-flex align-items-center gap-2 mb-3 d-none" role="status">
            <i class="fa-solid fa-lock" aria-hidden="true"></i>
            <span>Editing is currently unavailable for this set list. You can still view it and follow along.</span>
        </div>

        <!-- Song list (populated by JS) -->
        <div id="shared-setlist-songs" class="list-group" role="list" aria-label="Songs in shared set list">
            <!-- Songs rendered by JS -->
        </div>

        <!-- #1791 — aria-live save status for the token-edit surface (shown
             only when the server grants canWrite on this link). -->
        <div id="shared-setlist-edit-status" class="small mt-2 d-none" aria-live="polite"></div>

        <!-- #1790 — Import demoted to ONE secondary "Save a copy" button (was two
             primary "Import" buttons that steered every recipient into the wrong
             flow). The id stays `shared-setlist-import-btn-bottom` so the #1535
             owner-hide + the import handler keep working unchanged. -->
        <div class="text-center mt-4">
            <button type="button" class="btn btn-outline-secondary" id="shared-setlist-import-btn-bottom">
                <i class="fa-solid fa-download me-1" aria-hidden="true"></i>
                Save a copy to My Set Lists
            </button>
            <div class="mt-2">
                <a href="/setlist" class="btn btn-outline-secondary btn-sm" data-navigate="setlist">
                    <i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>
                    Go to My Set Lists
                </a>
            </div>
        </div>

    </div>

</section>
