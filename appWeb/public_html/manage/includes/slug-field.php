<?php

declare(strict_types=1);

/**
 * iHymns — Shared "slug (advanced)" collapsed field partial (#1870, rule #44)
 *
 * ELI5
 * ----
 * Eleven admin forms across six pages (Collections, Organisations,
 * Publishers, Songbook Series, Tunes, Works) each showed an always-visible
 * "Slug" text box next to Name/Title. The server already derives a slug
 * from the name/title on every one of these forms whenever the box is left
 * blank — so the box was a vanity control nobody needed to touch 99% of the
 * time (rule #44: derive or omit the manual control). This ONE function
 * renders that same `<input name="slug">` tucked inside a native
 * `<details>` disclosure — a curator who genuinely wants to override the
 * derived slug clicks "Edit slug (advanced)" to reveal it; everyone else
 * never sees it.
 *
 * Detailed
 * --------
 * `<details>`/`<summary>` needs no Bootstrap JS and carries built-in
 * keyboard (Enter/Space toggles the same as every native disclosure
 * widget) and screen-reader semantics for free — no `aria-expanded`
 * bookkeeping, no click handler to wire or fail silently. Six call sites
 * (11 inputs) share this ONE partial per the modularity rule instead of
 * pasting the same collapse markup 11 times.
 *
 * PRESERVE-ON-EDIT (rule #33): edit forms pass the row's CURRENT slug as
 * `value`; the panel starts closed, so a save that never opens it submits
 * the slug byte-identical to what's stored in the database — every
 * existing `/…/<slug>` URL keeps resolving. Create forms pass
 * `value: ''`; a closed + empty field submits empty, and every one of the
 * six create handlers already derives a slug from the name/title when the
 * posted value is blank (re-verified per-page in #1870's Wave-3 C4 commit
 * — this file changes NO server-side behaviour, only where the control
 * lives on screen).
 *
 * IDS ARE LOAD-BEARING: several call sites have JS that targets the input
 * by id — songbook-series.php's create-form auto-slugify-as-you-type
 * writes into `#create-slug` (and shows the *derived* value the moment a
 * curator opens the now-collapsed panel); the publishers/tunes/works edit
 * modals prefill `#edit-publisher-slug` / `#edit-tune-slug` /
 * `#edit-work-slug` via `document.getElementById(id).value = row.slug`.
 * Passing the SAME id the site has always used keeps that JS working
 * against the (now hidden-until-opened) input with zero JS changes.
 *
 * @param array{
 *   id?: ?string,          Element id (and its `<label for>`). Preserve the
 *                          site's existing id EXACTLY — several are read by
 *                          JS (see above). Omit/null when the original site
 *                          had none (a few create forms never had one).
 *   value?: string,        Current field value. '' for create (closed +
 *                          empty -> server derives on submit); the row's
 *                          stored slug for edit (closed + prefilled ->
 *                          preserved unchanged on submit).
 *   maxlength?: int,       Same maxlength the site's own <input> carried.
 *   pattern?: ?string,     Same `pattern=` the site's own <input> carried
 *                          (most are '[a-z0-9-]+'); omit/null to skip the
 *                          attribute entirely (e.g. catalogues/organisations
 *                          never had one).
 *   placeholder?: ?string, Same placeholder text, or omit/null to skip it.
 *   small?: bool,          true adds `form-control-sm` — every CREATE-form
 *                          site plus organisations' inline (non-modal) edit
 *                          form use the -sm sizing; the publishers/tunes/
 *                          works edit MODALS use the larger default size,
 *                          so pass false there to match what was already
 *                          on screen (verified per-site, not assumed).
 *   help?: ?string,        Pre-escaped HTML for the `form-text` hint under
 *                          the input (publishers'/tunes' existing "changing
 *                          this changes /x/<slug>…" copy, which itself
 *                          contains a <code> tag — hence pre-escaped rather
 *                          than htmlspecialchars()'d here, which would
 *                          mangle that markup). Omit/null to skip the hint.
 * } $o
 * @return string  The rendered <details class="slug-advanced">…</details> HTML.
 *
 * @link https://developer.mozilla.org/en-US/docs/Web/HTML/Element/details  MDN — <details>
 * @link appWeb/public_html/manage/catalogues.php        create slug only (no edit slug field — don't add one)
 * @link appWeb/public_html/manage/organisations.php     create + edit slug (edit dropped the client `required` — the server's own 'Slug is required.' answers empty)
 * @link appWeb/public_html/manage/publishers.php        create + edit slug (+ alias-fallback help text)
 * @link appWeb/public_html/manage/songbook-series.php   create (#create-slug, JS auto-slugify-as-you-type) + edit (#edit-slug)
 * @link appWeb/public_html/manage/tunes.php              create + edit (#edit-tune-slug)
 * @link appWeb/public_html/manage/works.php              create (#create-slug) + edit (#edit-work-slug)
 * @link appWeb/public_html/css/admin.css                 .slug-advanced summary/spacing rules
 * @link tests/php/test-slug-field-partial.php            tree-derived + mutation-proven guard
 * @see #1870
 */

/* Prevent direct access — same convention as every other manage partial
   (mirrors publication-identifiers-fields.php's guard). */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * Render one collapsed "Edit slug (advanced)" field. See the file doc-block
 * above for the full option contract.
 */
function ihymns_slug_advanced_field(array $o): string
{
    // ELI5: pull each option out with a safe fallback so a caller that
    // forgets a key gets sane, empty-ish behaviour instead of a PHP notice.
    // Detailed: every value that lands inside an HTML attribute is escaped
    // with htmlspecialchars(ENT_QUOTES) at the point of use below — EXCEPT
    // `help`, which is documented (both here and in the param doc-block
    // above) as PRE-ESCAPED by the caller, because the two real callers
    // that pass it (publishers.php / tunes.php's "old links still resolve"
    // copy) need an embedded <code> tag that htmlspecialchars() would
    // otherwise turn into literal angle-bracket text.
    $id          = isset($o['id']) ? (string)$o['id'] : null;
    $value       = (string)($o['value'] ?? '');
    $maxlength   = (int)($o['maxlength'] ?? 255);
    $pattern     = isset($o['pattern']) ? (string)$o['pattern'] : null;
    $placeholder = isset($o['placeholder']) ? (string)$o['placeholder'] : null;
    $small       = (bool)($o['small'] ?? false);
    $help        = isset($o['help']) ? (string)$o['help'] : null; // pre-escaped by design — see doc-block above

    $idAttr          = $id !== null ? ' id="' . htmlspecialchars($id, ENT_QUOTES) . '"' : '';
    $forAttr         = $id !== null ? ' for="' . htmlspecialchars($id, ENT_QUOTES) . '"' : '';
    $patternAttr     = $pattern !== null ? ' pattern="' . htmlspecialchars($pattern, ENT_QUOTES) . '"' : '';
    $placeholderAttr = $placeholder !== null ? ' placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES) . '"' : '';
    $sizeClass       = $small ? ' form-control-sm' : '';

    $html  = '<details class="slug-advanced">';
    $html .= '<summary class="small text-muted">Edit slug (advanced)</summary>';
    $html .= '<div class="mt-1">';
    $html .= '<label class="form-label small mb-0"' . $forAttr . '>Slug</label>';
    $html .= '<input type="text" name="slug"' . $idAttr
           . ' class="form-control' . $sizeClass . '"'
           . ' maxlength="' . $maxlength . '"'
           . $patternAttr . $placeholderAttr
           . ' value="' . htmlspecialchars($value, ENT_QUOTES) . '">';
    if ($help !== null) {
        $html .= '<div class="form-text small">' . $help . '</div>'; // pre-escaped, see doc-block
    }
    $html .= '</div>';
    $html .= '</details>';

    return $html;
}
