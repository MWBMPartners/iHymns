<?php

declare(strict_types=1);

/**
 * iHymns — Shared "publication authority identifiers" fieldset partial
 * (Songbook/Catalogue Enhancements epic, #1765, commit 4 — rule #22)
 *
 * ELI5
 * ----
 * Two admin pages — `/manage/songbook-series` and `/manage/catalogues`
 * (Collections) — both let a curator record the same library "authority
 * identifiers" for a publication: an ARK, an OpenLibrary Work id and an
 * OpenLibrary Edition id (series additionally records ISBN + ISSN). Rather
 * than hand-type that same block of `<input>`s twice on each page (once in
 * the create form, once in the edit form) across two pages — four copies
 * that would drift — this file is the ONE copy. Each caller `require`s it
 * inside its own `<form>`.
 *
 * The songbook page has its OWN, richer identifier block inside
 * `songbook-form-fields.php` (it also carries WikiData/OCLC/OCN/LCP/VIAF/
 * LCCN/LC-class/ISNI); that is deliberately a superset and stays there —
 * this partial covers only the fields series + catalogues share, so a
 * future field added to the series/catalogue identifier set is added here
 * once, not in four places.
 *
 * Caller contract — set these BEFORE `require`ing this file:
 *
 *   $pifMode               'create' | 'edit' — drives every element id as
 *                          "{$pifMode}-fieldname" so a page carrying both a
 *                          create form and an edit form never collides.
 *   $pifShowIsbnIssn       bool — render the ISBN + ISSN inputs. True for
 *                          series (`tblSongbookSeries` has Isbn/Issn),
 *                          false for catalogues (which record only ARK +
 *                          OpenLibrary — see migrate-publication-metadata.php).
 *   $pifHasOpenLibraryCols bool — whether the OpenLibrary Work/Edition
 *                          columns exist on THIS install (caller probes via
 *                          placeColumnExists()); gates those two inputs so a
 *                          pre-migration install never shows a control that
 *                          can't be saved.
 *   $pifOpen               bool (optional, default false) — render the
 *                          <details> expanded.
 *   $pifValues             array (optional) — server-side pre-fill values
 *                          keyed by field name (isbn/issn/ark_id/
 *                          openlibrary_work_id/openlibrary_edition_id).
 *                          Used by the catalogues page's per-row inline edit
 *                          form (which pre-fills server-side); the songbook-
 *                          series edit modal leaves this empty and populates
 *                          via JS instead (`openSeriesEditModal()`), and every
 *                          create form leaves it empty.
 *
 * Every value is validated on save by the ONE shared validator
 * `mediaIdentifierPublicationClean()` (includes/media_identifiers.php) —
 * this partial only renders the inputs; it never validates or persists.
 *
 * @link appWeb/public_html/includes/media_identifiers.php  the ONE validator these fields are checked against on save
 * @link appWeb/public_html/manage/songbook-series.php      caller (create form + edit modal)
 * @link appWeb/public_html/manage/catalogues.php           caller (create form + per-row edit form)
 */

/* Prevent direct access — same convention as every other manage partial. */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* Defensive defaults so a forgetful caller renders something sane. */
$pifMode               = isset($pifMode) && $pifMode === 'edit' ? 'edit' : 'create';
$pifShowIsbnIssn       = isset($pifShowIsbnIssn)       ? (bool)$pifShowIsbnIssn       : false;
$pifHasOpenLibraryCols = isset($pifHasOpenLibraryCols) ? (bool)$pifHasOpenLibraryCols : false;
$pifOpen               = isset($pifOpen)               ? (bool)$pifOpen               : false;
$pifValues             = (isset($pifValues) && is_array($pifValues)) ? $pifValues     : [];
/* Optional per-instance id suffix (#1765 review). Appended to every element
   id + its <label for>. Default '' keeps the single-instance ids the create
   forms and the JS-driven songbook-series/songbook edit modals depend on
   (openSeriesEditModal() looks up 'edit-ark-id' etc. by exact id). The
   catalogues page renders THIS partial once per Collection row (an in-flow
   edit form, not a shared modal), so it passes $pifIdSuffix = '-<Id>' to keep
   each row's ids unique and its <label for> correctly associated. */
$pifIdSuffix           = isset($pifIdSuffix) ? (string)$pifIdSuffix : '';
/* Local helper: HTML-escaped pre-fill value for a field (empty when none). */
$pifVal = static fn(string $k): string => htmlspecialchars((string)($pifValues[$k] ?? ''), ENT_QUOTES);
/* Local helper: an element id / label-for, mode-prefixed + optionally suffixed. */
$pifId = static fn(string $base): string => $pifMode . '-' . $base . $pifIdSuffix;
?>
<details class="mb-2"<?= $pifOpen ? ' open' : '' ?>>
    <summary class="form-label small text-muted" style="cursor:pointer;">
        <i class="bi bi-card-list me-1" aria-hidden="true"></i>Authority identifiers (optional)
    </summary>
    <div class="row g-2 mt-1">
        <?php if ($pifShowIsbnIssn): ?>
        <div class="col-sm-6">
            <label class="form-label small" for="<?= $pifId('isbn') ?>">ISBN</label>
            <input type="text" class="form-control form-control-sm"
                   name="isbn" id="<?= $pifId('isbn') ?>" value="<?= $pifVal('isbn') ?>"
                   maxlength="20" placeholder="978-0-86065-654-1">
        </div>
        <div class="col-sm-6">
            <label class="form-label small" for="<?= $pifId('issn') ?>">ISSN</label>
            <input type="text" class="form-control form-control-sm"
                   name="issn" id="<?= $pifId('issn') ?>" value="<?= $pifVal('issn') ?>"
                   maxlength="20" placeholder="1234-5678">
        </div>
        <?php endif; ?>
        <div class="col-sm-6">
            <label class="form-label small" for="<?= $pifId('ark-id') ?>">ARK ID</label>
            <input type="text" class="form-control form-control-sm"
                   name="ark_id" id="<?= $pifId('ark-id') ?>" value="<?= $pifVal('ark_id') ?>"
                   maxlength="80" placeholder="ark:/13960/t8jf3w89z">
        </div>
        <?php if ($pifHasOpenLibraryCols): ?>
        <div class="col-sm-6">
            <label class="form-label small" for="<?= $pifId('openlibrary-work-id') ?>">OpenLibrary Work ID</label>
            <input type="text" class="form-control form-control-sm"
                   name="openlibrary_work_id" id="<?= $pifId('openlibrary-work-id') ?>" value="<?= $pifVal('openlibrary_work_id') ?>"
                   maxlength="20" placeholder="OL102749W">
        </div>
        <div class="col-sm-6">
            <label class="form-label small" for="<?= $pifId('openlibrary-edition-id') ?>">OpenLibrary Edition ID</label>
            <input type="text" class="form-control form-control-sm"
                   name="openlibrary_edition_id" id="<?= $pifId('openlibrary-edition-id') ?>" value="<?= $pifVal('openlibrary_edition_id') ?>"
                   maxlength="20" placeholder="OL7357422M">
        </div>
        <?php endif; ?>
    </div>
</details>
