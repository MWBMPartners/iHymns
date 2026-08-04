<?php

declare(strict_types=1);

/**
 * iHymns — Shared songbook editable-fields partial (Songbook/Catalogue
 * Enhancements epic, #1765, commit 4 of 7 — rule #22 modularity)
 *
 * ELI5
 * ----
 * `/manage/songbooks` has two places a curator types a songbook's
 * metadata: the "Add a songbook" form at the bottom of the page, and
 * the "Edit songbook" modal opened from each row. Before this file
 * existed those were two hand-typed copies of the same ~25 fields —
 * the owner's exact complaint (the plan's "Add/Edit form parity"
 * item): a field added to one and forgotten in the other is a bug
 * nobody notices until a curator asks "why can't I set X on a new
 * book?". This file is the ONE copy; both the create `<form>` and the
 * edit `<form>` inside the modal `require` it, so they can never
 * drift again. `tests/php/test-songbook-form-parity.php` is the CI
 * guard that fails the build if a future field is ever added to only
 * one caller instead of here.
 *
 * DETAILED / WHAT STAYS OUTSIDE THIS PARTIAL AND WHY
 * ----------------------------------------------------------------------
 * Everything a curator can set for a songbook that EXISTS is here.
 * Deliberately NOT here — because each needs a real `tblSongbooks.Id`
 * to attach child rows to, which a brand-new songbook doesn't have
 * until the CREATE request has already round-tripped:
 *   - Abbreviation (create: a plain required input; edit: a separate
 *     "New abbreviation" rename control with its own song-reference
 *     checkbox — different enough in shape/semantics to stay
 *     per-caller, not a shared field).
 *   - The "Apply this language to all songs" bulk action (edit only —
 *     there are no songs in a book that doesn't exist yet).
 *   - Parent songbook picker, series memberships, compilers, alt
 *     names, external links (#782/#831/#832/#833 — all FK to
 *     `tblSongbooks.Id` via a separate reconciliation query keyed on
 *     the row id; a create-time equivalent would need the create
 *     handler to do a two-phase insert-then-attach, out of scope for
 *     this commit).
 *   - Disable/enable (Feature 1) — a toggle on an EXISTING row's
 *     public-visibility flag; a brand-new songbook always starts
 *     enabled (the migration's `DEFAULT 0`), so there is nothing to
 *     toggle at create time. The edit modal shows a read-only
 *     reflection of the current state (populated by
 *     `openEditModal()`); the actual toggle is a dedicated list-row
 *     action (`?action=toggle_disable`) so the one reversible,
 *     audited action lives in exactly one code path.
 *
 * Caller contract — set these BEFORE `require`ing this file:
 *
 *   $sbfMode               'create' | 'edit' — drives every element's
 *                          id as "{$sbfMode}-fieldname" so the two
 *                          forms never collide when both are present
 *                          in the same page (the create form always
 *                          is; the edit modal's markup is emitted
 *                          once too, reused for whichever row was
 *                          clicked via `openEditModal()`'s JS
 *                          population — see that function in
 *                          songbooks.php). MUST match the ids the
 *                          edit-modal JS already depends on exactly —
 *                          'edit-name', 'edit-order', etc. predate
 *                          this partial and are preserved verbatim.
 *   $sbfShowPickColourBtn  bool — render the "Pick distinctive
 *                          colour" button (#772) next to the Colour
 *                          label. True only for the edit modal — the
 *                          button reads `#editModal`'s
 *                          `data-edit-abbr` to know which row it's
 *                          running for, a concept that doesn't exist
 *                          on the create form. Its own script block
 *                          (still in songbooks.php, unchanged) wires
 *                          the click handler once.
 *   $sbfHasPubDomainCol    bool — whether `tblSongbooks.IsPublicDomain`
 *                          exists on this install (probed by the
 *                          caller via `placeColumnExists()`, cached
 *                          per request). Gates the Public Domain
 *                          checkbox so a pre-migration install never
 *                          shows a control that can't be saved.
 *   $sbfHasOpenLibraryCols bool — same gate for the paired
 *                          `OpenLibraryWorkId`/`OpenLibraryEditionId`
 *                          columns.
 *
 * Nothing here submits on its own — every field posts as part of
 * whichever surrounding `<form method="POST">` the caller wraps this
 * partial in (the create form's `action=create`, the edit modal's
 * `action=update`). The create/update POST handlers in songbooks.php
 * read the exact same `name="…"` attributes either way.
 *
 * @link .claude/songbook-catalogue-enhancements-plan.md   the epic plan (Feature 1/2/3 + "Add/Edit form parity")
 * @link appWeb/public_html/manage/songbooks.php            both call sites + the create/update POST handlers
 * @link appWeb/public_html/includes/media_identifiers.php  PUBLICATION_IDENTIFIER_TYPES — the ark / openlibrary-work / openlibrary-edition / isbn / issn vocabulary these fields validate against on save
 * @see  tests/php/test-songbook-form-parity.php             the add/edit parity CI guard
 */

/* Prevent direct access — same convention as every other partial
   under manage/includes/partials/. */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* Defensive defaults so a forgetful caller renders something sane
   (blank/create-shaped) rather than crashing — mirrors the other
   shared partials' (colour-picker.php, ietf-language-picker.php)
   own defensive-default convention. */
$sbfMode               = isset($sbfMode) && $sbfMode === 'edit' ? 'edit' : 'create';
$sbfShowPickColourBtn  = isset($sbfShowPickColourBtn)  ? (bool)$sbfShowPickColourBtn  : false;
$sbfHasPubDomainCol    = isset($sbfHasPubDomainCol)    ? (bool)$sbfHasPubDomainCol    : false;
$sbfHasOpenLibraryCols = isset($sbfHasOpenLibraryCols) ? (bool)$sbfHasOpenLibraryCols : false;
?>
<div class="mb-3">
    <label class="form-label<?= $sbfMode === 'create' ? ' small' : '' ?>" for="<?= $sbfMode ?>-name">Name</label>
    <input type="text" class="form-control<?= $sbfMode === 'create' ? ' form-control-sm' : '' ?>"
           name="name" id="<?= $sbfMode ?>-name" maxlength="255" required
           <?= $sbfMode === 'create' ? 'placeholder="e.g. Church Praise"' : '' ?>>
</div>

<!-- #1332 — optional free-text display label (shown in place of the
     abbreviation; the abbreviation stays the letters/numbers song-URL code). -->
<div class="mb-3">
    <label class="form-label<?= $sbfMode === 'create' ? ' small' : '' ?>" for="<?= $sbfMode ?>-display-abbr">
        Display label <span class="text-muted">(optional)</span>
    </label>
    <input type="text" class="form-control<?= $sbfMode === 'create' ? ' form-control-sm' : '' ?>"
           name="display_abbr" id="<?= $sbfMode ?>-display-abbr" maxlength="30"
           placeholder="e.g. AH-OLD<?= $sbfMode === 'edit' ? ' — shown instead of the abbreviation' : '' ?>">
    <?php if ($sbfMode === 'edit'): ?>
    <div class="form-text">Free text shown to users in place of the abbreviation (any characters). The abbreviation itself stays the song-URL code (letters/numbers only). Leave blank to show the abbreviation.</div>
    <?php else: ?>
    <div class="form-text small">Shown to users instead of the abbreviation; any characters allowed.</div>
    <?php endif; ?>
</div>

<div class="row g-2 mb-3">
    <div class="col-sm-6">
        <label class="form-label<?= $sbfMode === 'create' ? ' small' : '' ?> d-flex align-items-center justify-content-between">
            <span>Colour (hex)</span>
            <?php if ($sbfShowPickColourBtn): ?>
            <!-- Per-songbook auto-colour button (#772). Calls
                 /manage/songbooks?action=pick_colour&abbr=<row>, which
                 invokes pickAutoSongbookColour() to choose a hex not
                 already used by any other songbook. Click handler lives
                 in songbooks.php's own <script> block (unchanged by
                 this partial). -->
            <button type="button" class="btn btn-sm btn-outline-info py-0 px-2"
                    id="edit-pick-colour-btn"
                    title="Pick a random distinctive colour, avoiding ones already in use"
                    style="font-size: 0.75rem;">
                <i class="bi bi-magic me-1" aria-hidden="true"></i>Pick distinctive
            </button>
            <?php endif; ?>
        </label>
        <?php
            /* Shared colour picker partial (#715). Text input gets id
               "{$sbfMode}-songbook-colour-text" via the partial's own
               "{idPrefix}-text" convention. */
            $name        = 'colour';
            $value       = '';
            $idPrefix    = $sbfMode . '-songbook-colour';
            $placeholder = '#1a73e8';
            require __DIR__ . DIRECTORY_SEPARATOR
                . 'partials' . DIRECTORY_SEPARATOR
                . 'colour-picker.php';
            unset($name, $value, $idPrefix, $placeholder);
        ?>
    </div>
    <div class="col-sm-6">
        <label class="form-label<?= $sbfMode === 'create' ? ' small' : '' ?>" for="<?= $sbfMode ?>-order">Display order</label>
        <input type="number" class="form-control<?= $sbfMode === 'create' ? ' form-control-sm' : '' ?>"
               name="display_order" id="<?= $sbfMode ?>-order" min="0" <?= $sbfMode === 'create' ? 'value="0"' : '' ?>>
    </div>
</div>

<!-- #502 metadata -->
<div class="form-check mb-3">
    <input class="form-check-input" type="checkbox" name="is_official" id="<?= $sbfMode ?>-is-official" value="1">
    <label class="form-check-label<?= $sbfMode === 'create' ? ' small' : '' ?>" for="<?= $sbfMode ?>-is-official">
        Official published hymnal
    </label>
    <div class="form-text small">
        Unticked <?= $sbfMode === 'create' ? 'by default —' : 'means' ?> tick for a published hymnal; leave unticked for a curated grouping.
    </div>
</div>
<div class="mb-3">
    <label class="form-label<?= $sbfMode === 'create' ? ' small' : '' ?>" for="<?= $sbfMode ?>-publisher">Publisher</label>
    <input type="text" class="form-control<?= $sbfMode === 'create' ? ' form-control-sm' : '' ?>"
           name="publisher" id="<?= $sbfMode ?>-publisher" maxlength="255" placeholder="e.g. Praise Trust">
</div>
<div class="mb-3">
    <label class="form-label<?= $sbfMode === 'create' ? ' small' : '' ?>" for="<?= $sbfMode ?>-publication-year">Publication year / edition</label>
    <input type="text" class="form-control<?= $sbfMode === 'create' ? ' form-control-sm' : '' ?>"
           name="publication_year" id="<?= $sbfMode ?>-publication-year" maxlength="50"
           placeholder="e.g. 1986, 1986–2003, 2nd edition 2011">
</div>
<div class="mb-3">
    <label class="form-label<?= $sbfMode === 'create' ? ' small' : '' ?>" for="<?= $sbfMode ?>-publication-city">Publication city</label>
    <input type="text" class="form-control<?= $sbfMode === 'create' ? ' form-control-sm' : '' ?> js-place-search"
           name="publication_city" id="<?= $sbfMode ?>-publication-city"
           maxlength="255" placeholder="Start typing — e.g. London, England">
    <input type="hidden" name="publication_city_id" id="<?= $sbfMode ?>-publication-city-id" value="">
</div>
<div class="mb-3">
    <label class="form-label<?= $sbfMode === 'create' ? ' small' : '' ?>" for="<?= $sbfMode ?>-copyright">Copyright</label>
    <input type="text" class="form-control<?= $sbfMode === 'create' ? ' form-control-sm' : '' ?>"
           name="copyright" id="<?= $sbfMode ?>-copyright" maxlength="500"
           placeholder="e.g. © 2012 Praise Trust, All Rights Reserved">
</div>
<div class="mb-3">
    <label class="form-label<?= $sbfMode === 'create' ? ' small' : '' ?>" for="<?= $sbfMode ?>-affiliation">Affiliation</label>
    <input type="text"
           class="form-control<?= $sbfMode === 'create' ? ' form-control-sm' : '' ?> js-affiliation-input"
           name="affiliation" id="<?= $sbfMode ?>-affiliation"
           list="affiliations-datalist"
           autocomplete="off"
           maxlength="120"
           placeholder="e.g. Seventh-day Adventist, Non-denominational">
    <?php if ($sbfMode === 'edit'): ?>
    <div class="form-text small">
        Type to search existing affiliations or enter a new one — it
        will be added to the registry on save (#670).
    </div>
    <?php endif; ?>
</div>

<!-- #681 — IETF BCP 47 composite picker. Both instances start blank;
     openEditModal() calls window.editIetfPicker.setTag(row.language)
     to pre-fill the edit instance on open (songbooks.php, unchanged
     by this partial). -->
<?php
    $idPrefix = $sbfMode . '-songbook';
    $name     = 'language';
    $tag      = '';
    $label    = 'Language (IETF BCP 47, optional)';
    $help     = 'Empty = "not specified" (multi-lingual collection).';
    require __DIR__ . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'ietf-language-picker.php';
    unset($idPrefix, $name, $tag, $label, $help);
?>

<?php if ($sbfHasPubDomainCol): ?>
<!-- Feature 2 (#1765) — informational only, never a content gate (mirrors
     the tblSongs LyricsPublicDomain/MusicPublicDomain precedent). -->
<div class="form-check mb-3">
    <input class="form-check-input" type="checkbox" name="is_public_domain" id="<?= $sbfMode ?>-is-public-domain" value="1">
    <label class="form-check-label<?= $sbfMode === 'create' ? ' small' : '' ?>" for="<?= $sbfMode ?>-is-public-domain">
        Public domain
    </label>
    <div class="form-text small">
        The songbook itself (as a published work) is in the public domain.
        Informational only — it does not change what's gated or downloadable.
    </div>
</div>
<?php endif; ?>

<!-- #672 (+ Feature 7 IA-column removal, #1765) — collapsed by default.
     Internet Archive links now go through the External links card-list
     below (edit-only — a brand-new songbook has no Id to attach one to
     until after create). -->
<details class="mb-3<?= $sbfMode === 'create' ? ' mt-3' : '' ?>">
    <summary class="form-label small text-muted" style="cursor:pointer;">
        <i class="bi bi-link-45deg me-1"></i>Online links (optional)
    </summary>
    <div class="<?= $sbfMode === 'create' ? 'row g-2 mt-1' : 'mt-2' ?>">
        <div class="<?= $sbfMode === 'create' ? 'col-sm-6' : 'mb-2' ?>">
            <label class="form-label small" for="<?= $sbfMode ?>-website-url">Official website</label>
            <input type="url" class="form-control form-control-sm"
                   name="website_url" id="<?= $sbfMode ?>-website-url"
                   maxlength="500" placeholder="https://www.example.com">
        </div>
        <div class="<?= $sbfMode === 'create' ? 'col-sm-6' : 'mb-2' ?>">
            <label class="form-label small" for="<?= $sbfMode ?>-wikipedia-url">Wikipedia URL</label>
            <input type="url" class="form-control form-control-sm"
                   name="wikipedia_url" id="<?= $sbfMode ?>-wikipedia-url"
                   maxlength="500" placeholder="https://en.wikipedia.org/wiki/…">
        </div>
    </div>
</details>

<details class="mb-3">
    <summary class="form-label small text-muted" style="cursor:pointer;">
        <i class="bi bi-card-list me-1"></i>Authority identifiers (optional)
    </summary>
    <div class="row g-2 mt-1<?= $sbfMode === 'edit' ? '' : '' ?>">
        <div class="col-sm-6">
            <label class="form-label small" for="<?= $sbfMode ?>-wikidata-id">WikiData ID</label>
            <input type="text" class="form-control form-control-sm"
                   name="wikidata_id" id="<?= $sbfMode ?>-wikidata-id"
                   maxlength="20" placeholder="Q12345">
        </div>
        <div class="col-sm-6">
            <label class="form-label small" for="<?= $sbfMode ?>-oclc-number">OCLC number</label>
            <input type="text" class="form-control form-control-sm"
                   name="oclc_number" id="<?= $sbfMode ?>-oclc-number"
                   maxlength="30" placeholder="12345678">
        </div>
        <div class="col-sm-6">
            <label class="form-label small" for="<?= $sbfMode ?>-ocn-number">OCN number</label>
            <input type="text" class="form-control form-control-sm"
                   name="ocn_number" id="<?= $sbfMode ?>-ocn-number"
                   maxlength="30" placeholder="ocn123456789">
        </div>
        <div class="col-sm-6">
            <label class="form-label small" for="<?= $sbfMode ?>-lcp-number">LCP number</label>
            <input type="text" class="form-control form-control-sm"
                   name="lcp_number" id="<?= $sbfMode ?>-lcp-number"
                   maxlength="30" placeholder="LC2018012345">
        </div>
        <div class="col-sm-6">
            <label class="form-label small" for="<?= $sbfMode ?>-isbn">ISBN</label>
            <input type="text" class="form-control form-control-sm"
                   name="isbn" id="<?= $sbfMode ?>-isbn"
                   maxlength="20" placeholder="978-0-86065-654-1">
        </div>
        <div class="col-sm-6">
            <label class="form-label small" for="<?= $sbfMode ?>-ark-id">ARK ID</label>
            <input type="text" class="form-control form-control-sm"
                   name="ark_id" id="<?= $sbfMode ?>-ark-id"
                   maxlength="80" placeholder="ark:/13960/t8jf3w89z">
        </div>
        <?php if ($sbfHasOpenLibraryCols): ?>
        <!-- Feature 3 (#1765) — new columns; validated on save via the
             ONE shared validator, mediaIdentifierPublicationValidate()
             (includes/media_identifiers.php) — never a hand-rolled regex. -->
        <div class="col-sm-6">
            <label class="form-label small" for="<?= $sbfMode ?>-openlibrary-work-id">OpenLibrary Work ID</label>
            <input type="text" class="form-control form-control-sm"
                   name="openlibrary_work_id" id="<?= $sbfMode ?>-openlibrary-work-id"
                   maxlength="20" placeholder="OL102749W">
        </div>
        <div class="col-sm-6">
            <label class="form-label small" for="<?= $sbfMode ?>-openlibrary-edition-id">OpenLibrary Edition ID</label>
            <input type="text" class="form-control form-control-sm"
                   name="openlibrary_edition_id" id="<?= $sbfMode ?>-openlibrary-edition-id"
                   maxlength="20" placeholder="OL7357422M">
        </div>
        <?php endif; ?>
        <div class="col-sm-6">
            <label class="form-label small" for="<?= $sbfMode ?>-isni-id">ISNI ID</label>
            <input type="text" class="form-control form-control-sm"
                   name="isni_id" id="<?= $sbfMode ?>-isni-id"
                   maxlength="25" placeholder="0000 0001 2345 6789">
        </div>
        <div class="col-sm-6">
            <label class="form-label small" for="<?= $sbfMode ?>-viaf-id">VIAF ID</label>
            <input type="text" class="form-control form-control-sm"
                   name="viaf_id" id="<?= $sbfMode ?>-viaf-id"
                   maxlength="20" placeholder="123456789">
        </div>
        <div class="col-sm-6">
            <label class="form-label small" for="<?= $sbfMode ?>-lccn">LCCN</label>
            <input type="text" class="form-control form-control-sm"
                   name="lccn" id="<?= $sbfMode ?>-lccn"
                   maxlength="20" placeholder="n79123456">
        </div>
        <div class="col-sm-9">
            <label class="form-label small" for="<?= $sbfMode ?>-lc-class">LC Classification</label>
            <input type="text" class="form-control form-control-sm"
                   name="lc_class" id="<?= $sbfMode ?>-lc-class"
                   maxlength="50" placeholder="M2117 .M5 1990">
        </div>
    </div>
</details>
