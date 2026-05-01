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

/* Build the de-duplicated language list. Each row's Language is a
   full BCP 47 tag (`pt-BR`, `zh-Hans-CN`, …); the filter operates on
   the primary subtag (the first component before the hyphen) so
   "pt" matches both "pt-BR" and "pt-PT". The keys of the map are
   the lowercase subtags, the values are the display labels
   (uppercase code so the dropdown reads cleanly). */
$languageOptions = [];

/* Source 1 — songbook-level Language (the original #679 set). */
foreach ($songbooks as $book) {
    $tag = (string)($book['language'] ?? '');
    if ($tag === '') continue;
    if (!preg_match('/^([a-z]{2,3})/i', $tag, $m)) continue;
    $sub = strtolower($m[1]);
    if (!isset($languageOptions[$sub])) {
        $languageOptions[$sub] = strtoupper($m[1]);
    }
}

/* Source 2 — song-level Language (#734). A song carries its own
   Language independent of its songbook (e.g. a Spanish translation
   of an English-primary songbook), so the catalogue can be
   multilingual even when every tblSongbooks row is in one language.
   Best-effort: probe column existence first so a pre-#673 deployment
   doesn't 500 on the SELECT. Cheap because the SELECT pulls only
   distinct primary subtags via SUBSTRING_INDEX. */
try {
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'db_mysql.php';
    $db = getDbMysqli();
    $probe = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'tblSongs'
            AND COLUMN_NAME  = 'Language' LIMIT 1"
    );
    $probe->execute();
    $hasSongLangCol = $probe->get_result()->fetch_row() !== null;
    $probe->close();
    if ($hasSongLangCol) {
        $stmt = $db->prepare(
            "SELECT DISTINCT LOWER(SUBSTRING_INDEX(Language, '-', 1)) AS sub
               FROM tblSongs
              WHERE Language IS NOT NULL AND Language <> ''"
        );
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_row()) {
            $sub = (string)$row[0];
            if (!preg_match('/^[a-z]{2,3}$/', $sub)) continue;
            if (!isset($languageOptions[$sub])) {
                $languageOptions[$sub] = strtoupper($sub);
            }
        }
        $stmt->close();
    }
} catch (\Throwable $_e) {
    /* Best-effort. If the DB read fails (pre-migration deployment,
       missing tblSongs, etc.) just fall back to the songbook-only
       set computed above. */
}

ksort($languageOptions);

/* Don't render the filter at all if every songbook+song is in the
   same language (or none have a Language set). The filter is only
   useful when the catalogue actually spans multiple languages. */
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
