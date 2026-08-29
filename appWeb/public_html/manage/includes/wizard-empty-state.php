<?php

declare(strict_types=1);

/**
 * iHymns — Shared "Get started" empty-state launcher partial (#1999)
 *
 * ELI5
 * ----
 * Four admin lists (External-Link Types, Songbooks, Venues, Organisations)
 * already got a guided, step-by-step wizard each (#1992/#1993/#1995/#1996)
 * — but the ONLY way to find it is a small button up in the page header.
 * A curator who lands on a brand-new install with an empty list sees a
 * bare "No X yet" row and has no reason to look up at the header for
 * help. This ONE function renders a friendly "Get started" card in the
 * empty spot itself — an icon, a one-line explanation, and a button that
 * opens the SAME guided-wizard modal the header trigger already opens —
 * so the empty state doubles as a launcher instead of a dead end.
 *
 * Detailed
 * --------
 * This is presentation only: it adds NO new server action, NO new
 * entitlement gate, and NO new JavaScript. The button it renders carries
 * `data-bs-toggle="modal" data-bs-target="#<modalId>"` — the exact same
 * Bootstrap data attributes the page's own header trigger already carries
 * — so it opens the identical `<div class="modal fade" id="<modalId>">`
 * the page already renders further down, wired by the page's own existing
 * wizard script. Bootstrap's modal plugin binds every element sharing a
 * `data-bs-target` to the same modal instance, so a second trigger is
 * inert to add and needs no JS change (rule #1 — reuse, don't fork).
 *
 * `type="button"` on the launcher is LOAD-BEARING, not decorative: the
 * songbooks.php call site renders inside `<form id="songbook-list-form">`
 * (the reorder-save form), and a `<button>` with no explicit `type`
 * defaults to `type="submit"` — which would submit that form instead of
 * opening the modal the moment a curator clicks "Get started" on an empty
 * list. Every call site gets the safe behaviour by construction.
 *
 * Every value that reaches an HTML attribute or text node goes through
 * `htmlspecialchars()` at the point of use — none of the six inputs are
 * trusted as pre-escaped, unlike `slug-field.php`'s `help` (this partial
 * has no equivalent "pre-escaped HTML" parameter).
 *
 * `wrap: 'card'` (default) renders its own `card-admin p-4 text-center`
 * frame — used when the empty state is the ONLY thing in its region (e.g.
 * External-Link Types, where the whole "types" area is empty). `wrap:
 * 'bare'` renders just `text-center py-4` with no card chrome — used when
 * the caller already sits inside a `<tr><td>` (a table's empty row) or an
 * existing `card-admin`/`card` frame the caller doesn't want doubled.
 *
 * `data-wizard-empty-state="<modalId>"` on the outer element is a pure
 * markup breadcrumb — nothing reads it at runtime — kept as the guard
 * anchor `tests/php/test-wizard-empty-state.php` uses to find every
 * render site and its declared `modalId` without needing to re-parse this
 * function's own PHP source at every call site.
 *
 * @param array{
 *   icon: string,           Bootstrap Icons class suffix WITHOUT the
 *                            leading "bi " (e.g. 'bi-link-45deg'). Rendered
 *                            large + `text-primary` above the heading;
 *                            always `aria-hidden="true"` — it is decorative,
 *                            the heading carries the meaning.
 *   heading: string,        Short "Get started" headline, e.g. "No link
 *                            types yet".
 *   body: string,           One line explaining what the wizard does.
 *   modalId: string,        The EXISTING wizard modal's `id` attribute,
 *                            WITHOUT the leading '#' (e.g.
 *                            'linkTypeWizardModal'). Must match
 *                            `[A-Za-z0-9_-]+` — anything else throws,
 *                            because it would either break the
 *                            `data-bs-target` selector or (worse) silently
 *                            build a selector that targets nothing, which
 *                            is exactly the "dead launcher" this partial
 *                            exists to prevent (rule #33).
 *   buttonLabel: string,    Visible button text. Convention across all
 *                            four call sites: reuse the SAME label the
 *                            page's own header trigger already uses for
 *                            this modal, so a curator sees one consistent
 *                            name for "the guided way" wherever they meet
 *                            it.
 *   wrap?: 'card'|'bare',   Outer frame — see Detailed above. Default
 *                            'card'.
 *   hint?: ?string,         Optional second line under the button, e.g.
 *                            pointing at the manual fallback form further
 *                            down the page. Omit/null to skip it.
 *   headingTag?: string,    Heading element — 'h2' (default) at top-level
 *                            page regions, 'h3' when the card nests under
 *                            a caller-rendered `<h2>` already on screen
 *                            (organisations.php's "All organisations" card
 *                            heading) — keeps the page's own heading
 *                            outline sequential (WCAG 1.3.1/2.4.6) instead
 *                            of skipping a level.
 * } $o
 * @return string  The rendered empty-state HTML. Never renders a modal —
 *                 the caller's page already owns and renders that; this
 *                 partial only ever emits a second trigger for it.
 *
 * @link https://getbootstrap.com/docs/5.3/components/modal/#multiple-modals  Bootstrap docs — one modal, many triggers via data-bs-target
 * @link https://www.w3.org/WAI/WCAG21/Understanding/headings-and-labels.html  WCAG 2.4.6 — heading levels reflect structure
 * @link appWeb/public_html/manage/external-link-types.php  call site — schema-ready region, wrap 'card'
 * @link appWeb/public_html/manage/songbooks.php             call site — table empty row, wrap 'bare'
 * @link appWeb/public_html/manage/venues.php                 call site — $orgs-gated (rule #33 dead-launcher guard), wrap 'bare'
 * @link appWeb/public_html/manage/organisations.php          call site — list-view-only, headingTag 'h3', wrap 'bare'
 * @link tests/php/test-wizard-empty-state.php                tree-derived + mutation-proven guard, incl. the modalId<->real-modal contract
 * @see #1999
 */

/* Prevent direct access — same convention as every other manage partial
   (mirrors slug-field.php's / publication-identifiers-fields.php's guard). */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * Render one "Get started" empty-state launcher. See the file doc-block
 * above for the full option contract.
 */
function ihymns_wizard_empty_state(array $o): string
{
    // ELI5: pull each option out with a safe fallback, same shape as
    // slug-field.php's ihymns_slug_advanced_field(), so a caller that
    // forgets an optional key gets sane behaviour instead of a notice.
    // Detailed: modalId is the one value that is NOT merely escaped —
    // it is validated, because an unexpected character here doesn't just
    // render oddly, it can break the `data-bs-target="#<modalId>"`
    // selector Bootstrap parses, silently producing a button that opens
    // nothing (the exact dead-launcher failure mode rule #33 warns about).
    $icon        = (string)($o['icon'] ?? 'bi-magic');
    $heading     = (string)($o['heading'] ?? '');
    $body        = (string)($o['body'] ?? '');
    $modalId     = (string)($o['modalId'] ?? '');
    $buttonLabel = (string)($o['buttonLabel'] ?? '');
    $wrap        = (string)($o['wrap'] ?? 'card');
    $hint        = isset($o['hint']) ? (string)$o['hint'] : null;
    $headingTag  = (string)($o['headingTag'] ?? 'h2');

    if ($modalId === '' || preg_match('/^[A-Za-z0-9_-]+$/', $modalId) !== 1) {
        throw new \InvalidArgumentException(
            "ihymns_wizard_empty_state(): 'modalId' must match [A-Za-z0-9_-]+ and be non-empty, got "
            . var_export($modalId, true) . '.'
        );
    }
    // Only the three heading levels this app's admin pages actually nest
    // wizard cards under are accepted; anything else falls back to 'h2'
    // rather than emitting an arbitrary/unsafe tag name.
    if (!in_array($headingTag, ['h2', 'h3', 'h4'], true)) {
        $headingTag = 'h2';
    }

    $wrapClass = $wrap === 'bare' ? 'text-center py-4' : 'card-admin p-4 text-center';

    $modalIdEsc = htmlspecialchars($modalId, ENT_QUOTES);

    $html  = '<div class="' . $wrapClass . '" data-wizard-empty-state="' . $modalIdEsc . '">';
    $html .= '<p class="mb-2"><i class="bi ' . htmlspecialchars($icon, ENT_QUOTES) . ' text-primary fs-1" aria-hidden="true"></i></p>';
    $html .= '<' . $headingTag . ' class="h6 mb-2">' . htmlspecialchars($heading) . '</' . $headingTag . '>';
    $html .= '<p class="text-muted small mb-3 mx-auto" style="max-width:48ch">' . htmlspecialchars($body) . '</p>';
    $html .= '<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#' . $modalIdEsc . '">';
    $html .= '<i class="bi bi-magic me-1" aria-hidden="true"></i>' . htmlspecialchars($buttonLabel);
    $html .= '</button>';
    if ($hint !== null && $hint !== '') {
        $html .= '<p class="text-muted small mt-2">' . htmlspecialchars($hint) . '</p>';
    }
    $html .= '</div>';

    return $html;
}
