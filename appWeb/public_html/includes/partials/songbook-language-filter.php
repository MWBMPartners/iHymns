<?php

declare(strict_types=1);

/**
 * iHymns — Songbook + song language filter (#679, picker UX #1149)
 *
 * Renders ONE compact dropdown trigger ("Languages: All ▾") above the
 * songbook tile grid. The trigger opens a searchable, name-labelled,
 * multi-select panel that lets a user narrow the visible tiles + songs
 * by IETF BCP 47 language. Replaces the earlier flat wall of bare
 * 2–3-letter ISO codes (AF, BEM, BG…), which didn't scale or read
 * professionally as the catalogue grew. Used by:
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
 *   - Specific language(s) picked → tiles whose `data-songbook-language`
 *     starts with a picked subtag stay visible. Tiles WITHOUT a
 *     `data-songbook-language` attribute (i.e. songbooks with no
 *     Language set) ALWAYS stay visible — the absence is treated as
 *     "multi-lingual / not specified" so a curated grouping isn't
 *     hidden when the user filters to one language.
 *
 * The option list is computed server-side from the unique language
 * subtags currently in tblSongbooks + tblSongs — only languages a
 * curator has actually used appear, so a fresh install with one English
 * songbook doesn't show 28 unused languages. Each option renders its
 * full English name primary, with the native endonym + code as muted
 * secondary text (#1149), resolved via resolveLanguageMeta().
 *
 * Filter UX is pure client-side; the JS module
 * /js/modules/songbook-language-filter.js wires the checkbox `change`
 * events to walk the tiles and toggle their `display`, keeps the
 * trigger label + count badge in sync, and filters the panel rows from
 * the search box. State persists in localStorage per device (key
 * `songbook-language-filter`) and syncs to the signed-in account via
 * `user_preferred_languages_save`. The `.js-songbook-language-filter-all`
 * / `.js-songbook-language-filter-option` (value = subtag) hooks are a
 * stable contract the module depends on — preserve them on any redesign.
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

/* #1149 — resolve each used subtag to its full display metadata
   (English name + native endonym + text direction) so the picker can
   show "Afrikaans · Afrikaans (AF)" instead of a bare code. The helper
   is request-scoped + static-cached, so this is one extra DB query for
   the whole partial regardless of how many languages there are. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'language_names.php';

$languageList = [];
foreach ($languageOptions as $sub => $upper) {
    $meta = resolveLanguageMeta($sub);
    $languageList[] = [
        'sub'    => $sub,
        'code'   => $upper,
        'name'   => $meta['name'] !== '' ? $meta['name'] : $upper,
        'native' => $meta['nativeName'],
        'dir'    => $meta['dir'] === 'rtl' ? 'rtl' : 'ltr',
    ];
}
/* Alphabetical by English name reads far more professionally than the
   raw subtag order — "Afrikaans, Bemba, Bulgarian…" not "AF, BEM, BG…". */
usort($languageList, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
$languageCount = count($languageList);
?>
<div class="songbook-language-filter mb-3"
     data-songbook-language-filter
     role="group"
     aria-label="Filter songbooks and songs by language">
    <div class="dropdown">
        <button type="button"
                class="btn btn-outline-secondary btn-sm dropdown-toggle js-lang-filter-trigger"
                data-bs-toggle="dropdown" data-bs-auto-close="outside"
                aria-haspopup="true" aria-expanded="false">
            <i class="bi bi-translate me-1" aria-hidden="true"></i>
            <span class="js-lang-filter-trigger-label">Languages: All</span>
            <span class="badge rounded-pill text-bg-info ms-1 d-none js-lang-filter-count"
                  aria-hidden="true">0</span>
        </button>
        <div class="dropdown-menu dropdown-menu-end p-0 shadow lang-filter-panel"
             role="group" aria-label="Choose which languages to show">
            <div class="lang-filter-search-wrap p-2 border-bottom">
                <label class="visually-hidden" for="lang-filter-search">Search languages</label>
                <input type="search" id="lang-filter-search"
                       class="form-control form-control-sm js-lang-filter-search"
                       placeholder="Search <?= (int)$languageCount ?> languages&hellip;"
                       autocomplete="off" aria-controls="lang-filter-list">
            </div>
            <div class="lang-filter-scroll" id="lang-filter-list">
                <label class="lang-filter-row lang-filter-row--all d-flex align-items-center gap-2 px-3 py-2 mb-0">
                    <input type="checkbox" class="form-check-input m-0 flex-shrink-0 js-songbook-language-filter-all"
                           id="songbook-language-filter-all" checked>
                    <span class="lang-filter-name fw-semibold">All languages</span>
                </label>
                <?php foreach ($languageList as $l): ?>
                    <?php
                        $id     = 'songbook-language-filter-' . htmlspecialchars($l['sub'], ENT_QUOTES, 'UTF-8');
                        $search = strtolower($l['name'] . ' ' . $l['native'] . ' ' . $l['code'] . ' ' . $l['sub']);
                        $showNative = $l['native'] !== '' && strcasecmp($l['native'], $l['name']) !== 0;
                    ?>
                    <label class="lang-filter-row d-flex align-items-center gap-2 px-3 py-2 mb-0"
                           data-search="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="checkbox" class="form-check-input m-0 flex-shrink-0 js-songbook-language-filter-option"
                               id="<?= $id ?>"
                               value="<?= htmlspecialchars($l['sub'], ENT_QUOTES, 'UTF-8') ?>"
                               data-lang-name="<?= htmlspecialchars($l['name'], ENT_QUOTES, 'UTF-8') ?>">
                        <span class="d-flex flex-column lh-sm">
                            <span class="lang-filter-name"><?= htmlspecialchars($l['name'], ENT_QUOTES, 'UTF-8') ?></span>
                            <small class="text-muted lang-filter-meta">
                                <?php if ($showNative): ?>
                                    <span dir="<?= $l['dir'] ?>"><?= htmlspecialchars($l['native'], ENT_QUOTES, 'UTF-8') ?></span>
                                    &middot; <?= htmlspecialchars($l['code'], ENT_QUOTES, 'UTF-8') ?>
                                <?php else: ?>
                                    <?= htmlspecialchars($l['code'], ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                            </small>
                        </span>
                    </label>
                <?php endforeach; ?>
                <p class="lang-filter-empty text-muted small text-center mb-0 py-3 d-none js-lang-filter-empty">
                    No languages match your search.
                </p>
            </div>
            <div class="lang-filter-foot small text-muted px-3 py-2 border-top">
                Songbooks and songs without a language set always remain visible.
                <?php if (!empty($currentUser)): ?>
                    Your selection is saved to your account and syncs across devices.
                <?php else: ?>
                    Sign in to save your selection across devices.
                <?php endif; ?>
            </div>
        </div>
    </div>
    <span class="visually-hidden" aria-live="polite" role="status" data-lang-filter-status></span>
</div>
