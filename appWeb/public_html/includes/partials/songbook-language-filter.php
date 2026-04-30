<?php

declare(strict_types=1);

/**
 * iHymns — Songbook language filter (#679)
 *
 * Renders a small <select> above the songbook tile grid that lets a
 * user narrow the visible tiles by IETF BCP 47 language. Used by:
 *   - /         (home page, above the Songbooks section)
 *   - /songbooks (full listing page, above the grid)
 *
 * Caller contract:
 *
 *   // $songbooks comes from $songData->getSongbooks(); each entry
 *   // already carries the optional `language` field (IETF BCP 47
 *   // tag from #673 / #681).
 *   require __DIR__ . '/partials/songbook-language-filter.php';
 *
 * Filter rule (per #679):
 *   - "All languages" → every tile visible.
 *   - Specific language picked → tiles whose `data-songbook-language`
 *     starts with the picked subtag stay visible. Tiles WITHOUT a
 *     `data-songbook-language` attribute (i.e. songbooks with no
 *     Language set) ALWAYS stay visible — the absence is treated as
 *     "multi-lingual / not specified" so a curated grouping isn't
 *     hidden when the user filters to one language.
 *
 * The options list is computed server-side from the unique language
 * subtags currently in tblSongbooks — only languages a curator has
 * actually used appear in the dropdown, so a fresh install with one
 * English songbook doesn't show 28 unused languages.
 *
 * Filter UX is pure client-side; the JS module
 * /js/modules/songbook-language-filter.js wires the <select> change
 * event to walk the tiles and toggle their `display`. State persists
 * in localStorage per device so a user who picked "Spanish" once
 * doesn't see English tiles on every reload.
 */

if (!isset($songbooks) || !is_array($songbooks)) {
    $songbooks = [];
}

/* Build the de-duplicated language list. Each tblSongbooks row's
   Language is a full BCP 47 tag (`pt-BR`, `zh-Hans-CN`, …); the
   filter operates on the language subtag (the first component
   before the hyphen) so "pt" matches both "pt-BR" and "pt-PT". The
   keys of the map are the lowercase subtags, the values are the
   display labels (uppercase code so the dropdown reads cleanly). */
$languageOptions = [];
foreach ($songbooks as $book) {
    $tag = (string)($book['language'] ?? '');
    if ($tag === '') continue;
    if (!preg_match('/^([a-z]{2,3})/i', $tag, $m)) continue;
    $sub = strtolower($m[1]);
    /* Display label: ISO code uppercased + (optionally) the friendly
       language name from the same row's data. We don't have the
       resolved name here so we just show the code; the JS module
       can later swap it for a friendly label fetched from
       /api?action=languages if the host page wants that. */
    if (!isset($languageOptions[$sub])) {
        $languageOptions[$sub] = strtoupper($m[1]);
    }
}
ksort($languageOptions);

/* Don't render the filter at all if every songbook is in the same
   language (or none have a Language set). The filter is only useful
   when the catalogue actually spans multiple languages. */
if (count($languageOptions) <= 1) {
    return;
}
?>
<div class="songbook-language-filter mb-3 d-flex align-items-center gap-2"
     data-songbook-language-filter
     aria-label="Filter songbooks by language">
    <label class="form-label small mb-0 me-1" for="songbook-language-filter-select">
        <i class="bi bi-translate me-1" aria-hidden="true"></i>
        Filter by language:
    </label>
    <select class="form-select form-select-sm js-songbook-language-filter"
            id="songbook-language-filter-select"
            style="max-width: 14rem;">
        <option value="">All languages</option>
        <?php foreach ($languageOptions as $sub => $label): ?>
            <option value="<?= htmlspecialchars($sub, ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
            </option>
        <?php endforeach; ?>
    </select>
    <small class="text-muted">
        Songbooks without a language set always remain visible.
    </small>
</div>
