<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Print Templates (#1350 Phase 2)
 *
 * Curator CRUD + WYSIWYG-ish editor for the block-based song-print
 * layouts stored in tblPrintTemplates (Scope='song', OwnerId NULL =
 * curated/global). The schema, the block model and the renderer all
 * already exist:
 *   - tblPrintTemplates           — storage (BlocksJson + PageOptionsJson)
 *   - js/modules/print.js         — PRINT_BLOCK_TYPES (registry),
 *                                   PRINT_SAMPLE_SONG, renderTemplateBodyHtml()
 *
 * This page is JUST the editor surface that writes those rows and
 * previews them through the SAME renderer the print path uses, so the
 * live preview is byte-identical to the printed page (one source of
 * truth — rule "modularity").
 *
 * Gated by `manage_songbooks` (curator-level). Pre-migration safe —
 * probes for tblPrintTemplates on every page load (STRICT mode would
 * otherwise throw on a SELECT against a missing table; CLAUDE.md
 * red-flag "treat query() as returning false").
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_songbooks', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_songbooks required</h1></body></html>';
    exit;
}
$activePage = 'print-templates';

$error   = '';
$success = '';
$db      = getDbMysqli();
$csrf    = csrfToken();

/* The CANONICAL block-type allow-list — mirrors PRINT_BLOCK_TYPES in
   js/modules/print.js. A POSTed block whose `type` isn't here is
   dropped, and only the option keys declared here are kept (so a
   crafted POST cannot persist arbitrary JSON). The value is the
   per-type option schema: key => coercion kind. Keeping this beside
   the JS registry is deliberate — the JS drives the editor UI, this
   drives the server-side gate; both enumerate the same 9 types. */
$BLOCK_SCHEMA = [
    'title'       => [],
    'subtitle'    => ['showBook' => 'bool', 'showNumber' => 'bool', 'bookAbbr' => 'bool'],   // #1767 B
    'credits'     => [],
    'lyrics'      => ['showLabels' => 'bool', 'showChords' => 'bool', 'columns' => 'cols', 'align' => 'align', 'size' => 'size'],  // #1767 A
    'copyright'   => [],
    'identifiers' => ['ccli' => 'bool', 'iswc' => 'bool'],
    'scripture'   => [],                          // #1767 N
    'tune'        => ['showMetre' => 'bool'],     // #1767 O
    'themes'      => [],                          // #1767 P
    'text'        => ['content' => 'str'],
    'permalink'   => [],
    'qr'          => ['size' => 'size'],          // #1767 R
    'spacer'      => ['size' => 'size'],
    'pagebreak'   => [],
];

/* The CANONICAL conditional-visibility vocabulary (#1767 Y) — a UNIVERSAL block
   property `showIf` any block may carry. Mirrors PRINT_SHOWIF_CONDITIONS in
   js/modules/print.js, held in lockstep by the registry guard (rule #35). A
   posted showIf not in this list (or 'always', the default) is dropped. */
$SHOWIF_CONDITIONS = ['always', 'hasChords', 'hasCopyright', 'hasCcli', 'hasScripture', 'hasThemes', 'hasTune'];

/* The CANONICAL page-option allow-list (#1767 G/V/AB/AM/F) — mirrors
   PRINT_PAGE_OPTIONS in js/modules/print.js, held in lockstep by
   tests/php/test-print-block-registry.php (rule #35). Each entry's `kind` drives
   coercion in ptSanitisePageOptions(); an option not listed here is DROPPED. */
$PAGE_OPTION_SCHEMA = [
    'fontPt'      => ['kind' => 'int',  'min' => 6, 'max' => 72],
    'pageSize'    => ['kind' => 'enum', 'choices' => ['A4', 'Letter', 'Legal']],
    'lineHeight'  => ['kind' => 'enum', 'choices' => ['tight', 'normal', 'relaxed']],
    'contrast'    => ['kind' => 'enum', 'choices' => ['normal', 'high']],
    'accentColor' => ['kind' => 'color'],
    'inkSaver'    => ['kind' => 'bool'],
];

/* JSON-encode flags that make a value safe to drop into an inline
   <script> (no </script> break-out, no quote break-out). #1350 req #5. */
$JSON_SAFE = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

/* ---- Schema probe (pre-migration safe) ---- */
$hasSchema = false;
try {
    $r = $db->query(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'tblPrintTemplates' LIMIT 1"
    );
    $hasSchema = $r && $r->fetch_row() !== null;
    if ($r) { $r->close(); }
} catch (\Throwable $e) {
    error_log('[print-templates] schema probe failed: ' . $e->getMessage());
}

/**
 * Sanitise a decoded blocks array against $BLOCK_SCHEMA.
 *
 * Drops unknown types and unknown option keys; coerces every kept
 * value to its declared kind. Returns a clean array safe to persist.
 *
 * @param array $raw               The json_decode'd POST blocks (assoc).
 * @param array $schema            $BLOCK_SCHEMA.
 * @param array $showIfConditions  $SHOWIF_CONDITIONS (universal visibility vocab).
 * @return array                   Sanitised ordered block list.
 */
function ptSanitiseBlocks(array $raw, array $schema, array $showIfConditions = []): array
{
    $clean = [];
    foreach ($raw as $block) {
        if (!is_array($block)) { continue; }                 // not an object — drop
        $type = (string)($block['type'] ?? '');
        if (!isset($schema[$type])) { continue; }            // unknown type — drop
        $row = ['type' => $type];
        /* #1767 Y — a UNIVERSAL showIf on ANY block. Kept only when it names a
           known condition and isn't the 'always' default (which we omit to keep
           the stored JSON minimal — absent showIf == always visible). */
        if (isset($block['showIf'])
            && in_array($block['showIf'], $showIfConditions, true)
            && $block['showIf'] !== 'always') {
            $row['showIf'] = (string)$block['showIf'];
        }
        foreach ($schema[$type] as $key => $kind) {
            if (!array_key_exists($key, $block)) { continue; } // option not posted — use renderer default
            $v = $block[$key];
            switch ($kind) {
                case 'bool':
                    $row[$key] = (bool)$v;
                    break;
                case 'cols':
                    $row[$key] = ((int)$v === 2) ? 2 : 1;       // lyrics columns: only 1 or 2
                    break;
                case 'size':
                    $row[$key] = in_array($v, ['sm', 'md', 'lg'], true) ? $v : 'md';
                    break;
                case 'align':                                    // #1767 A
                    $row[$key] = in_array($v, ['left', 'center', 'right'], true) ? $v : 'left';
                    break;
                case 'str':
                default:
                    $row[$key] = mb_substr((string)$v, 0, 2000); // custom text — cap length
                    break;
            }
        }
        $clean[] = $row;
    }
    return $clean;
}

/**
 * Sanitise decoded page options against $PAGE_OPTION_SCHEMA (#1767 G/V/AB/AM/F).
 *
 * Drops unknown keys; coerces each kept value to its declared kind (int clamp,
 * enum membership, #rgb/#rrggbb colour, bool). Returns null when nothing valid
 * was supplied. We persist the RE-ENCODED clean options, never the raw POST.
 *
 * @param mixed $raw      The json_decode'd POST page_options (assoc), or non-array.
 * @param array $schema   $PAGE_OPTION_SCHEMA.
 * @return array|null     Sanitised page options, or null when empty.
 */
function ptSanitisePageOptions($raw, array $schema): ?array
{
    if (!is_array($raw)) { return null; }
    $out = [];
    foreach ($schema as $key => $def) {
        if (!array_key_exists($key, $raw)) { continue; }   // not posted — renderer default
        $v = $raw[$key];
        switch ($def['kind']) {
            case 'int':
                $out[$key] = max((int)$def['min'], min((int)$def['max'], (int)$v));
                break;
            case 'enum':
                if (in_array($v, $def['choices'], true)) { $out[$key] = $v; }  // else drop
                break;
            case 'color':
                $s = (string)$v;
                if ($s !== '' && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $s)) { $out[$key] = $s; }
                break;
            case 'bool':
                $out[$key] = (bool)$v;
                break;
        }
    }
    return $out ?: null;
}

/* ---- POST actions ---- */
if ($hasSchema && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo 'Invalid CSRF token';
        exit;
    }
    $action = (string)($_POST['action'] ?? '');
    try {
        switch ($action) {

            case 'save': {
                $id       = (int)($_POST['id'] ?? 0);
                $name     = trim((string)($_POST['name'] ?? ''));
                $name     = mb_substr($name, 0, 120);
                $isActive = !empty($_POST['is_active']) ? 1 : 0;

                if ($name === '') { $error = 'A template name is required.'; break; }

                /* Decode + sanitise the block list. Must be a non-empty
                   array of recognised blocks AFTER sanitisation, else
                   reject (req #2). We persist the RE-ENCODED clean JSON,
                   never the raw POST string. */
                $rawBlocks = json_decode((string)($_POST['blocks_json'] ?? ''), true);
                if (!is_array($rawBlocks) || $rawBlocks === []) {
                    $error = 'Add at least one block before saving.';
                    break;
                }
                $blocks = ptSanitiseBlocks($rawBlocks, $BLOCK_SCHEMA, $SHOWIF_CONDITIONS);
                if ($blocks === []) {
                    $error = 'None of the submitted blocks were recognised.';
                    break;
                }
                $blocksJson = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                /* Page options decode → sanitise → JSON or NULL. */
                $rawPageOpts = json_decode((string)($_POST['page_options_json'] ?? ''), true);
                $pageOpts    = ptSanitisePageOptions($rawPageOpts, $PAGE_OPTION_SCHEMA);
                $pageOptsJson = $pageOpts !== null
                    ? json_encode($pageOpts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null; // bound as SQL NULL below

                if ($id > 0) {
                    /* UPDATE — scoped to 'song' so this page can never
                       touch another scope's rows. */
                    $stmt = $db->prepare(
                        'UPDATE tblPrintTemplates
                            SET Name = ?, BlocksJson = ?, PageOptionsJson = ?, IsActive = ?
                          WHERE Id = ? AND Scope = ?'
                    );
                    $scope = 'song';
                    $stmt->bind_param('sssiis', $name, $blocksJson, $pageOptsJson, $isActive, $id, $scope);
                    $stmt->execute();
                    $stmt->close();
                    if (function_exists('logActivity')) {
                        logActivity('print_template.update', 'print_template', (string)$id, [
                            'name' => $name, 'blocks' => count($blocks), 'is_active' => (bool)$isActive,
                        ]);
                    }
                } else {
                    /* INSERT — Scope='song', OwnerId NULL (curated),
                       SortOrder appended to the end of the list. */
                    $sortRes  = $db->query("SELECT COALESCE(MAX(SortOrder),0)+1 AS n FROM tblPrintTemplates WHERE Scope='song'");
                    $sortRow  = $sortRes ? $sortRes->fetch_assoc() : null;
                    if ($sortRes) { $sortRes->close(); }
                    $sortOrder = (int)($sortRow['n'] ?? 0);
                    $createdBy = (int)($currentUser['id'] ?? 0);

                    $stmt = $db->prepare(
                        "INSERT INTO tblPrintTemplates
                            (Name, Scope, OwnerId, BlocksJson, PageOptionsJson, IsActive, IsDefault, SortOrder, CreatedBy)
                         VALUES (?, 'song', NULL, ?, ?, ?, 0, ?, ?)"
                    );
                    $stmt->bind_param('sssiii', $name, $blocksJson, $pageOptsJson, $isActive, $sortOrder, $createdBy);
                    $stmt->execute();
                    $newId = (int)$db->insert_id;
                    $stmt->close();
                    if (function_exists('logActivity')) {
                        logActivity('print_template.create', 'print_template', (string)$newId, [
                            'name' => $name, 'blocks' => count($blocks),
                        ]);
                    }
                }

                /* PRG — redirect after a successful write so a refresh
                   doesn't re-submit. */
                header('Location: /manage/print-templates?saved=1');
                exit;
            }

            case 'delete': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    $scope = 'song';
                    $stmt = $db->prepare('DELETE FROM tblPrintTemplates WHERE Id = ? AND Scope = ?');
                    $stmt->bind_param('is', $id, $scope);
                    $stmt->execute();
                    $stmt->close();
                    if (function_exists('logActivity')) {
                        logActivity('print_template.delete', 'print_template', (string)$id, []);
                    }
                }
                header('Location: /manage/print-templates?deleted=1');
                exit;
            }

            /* #1767 Z — clone an existing template into a fresh independent row
               (blocks + page options copied verbatim; the copy starts active,
               never default). */
            case 'clone': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    $src = $db->prepare(
                        "SELECT Name, BlocksJson, PageOptionsJson FROM tblPrintTemplates
                          WHERE Id = ? AND Scope = 'song' LIMIT 1"
                    );
                    $src->bind_param('i', $id);
                    $src->execute();
                    $srcRow = $src->get_result()->fetch_assoc();
                    $src->close();
                    if ($srcRow) {
                        $cloneName = mb_substr(trim((string)$srcRow['Name']) . ' (copy)', 0, 120);
                        $sortRes   = $db->query("SELECT COALESCE(MAX(SortOrder),0)+1 AS n FROM tblPrintTemplates WHERE Scope='song'");
                        $sortRow   = $sortRes ? $sortRes->fetch_assoc() : null;
                        if ($sortRes) { $sortRes->close(); }
                        $sortOrder = (int)($sortRow['n'] ?? 0);
                        $createdBy = (int)($currentUser['id'] ?? 0);
                        $ins = $db->prepare(
                            "INSERT INTO tblPrintTemplates
                                (Name, Scope, OwnerId, BlocksJson, PageOptionsJson, IsActive, IsDefault, SortOrder, CreatedBy)
                             VALUES (?, 'song', NULL, ?, ?, 1, 0, ?, ?)"
                        );
                        $ins->bind_param('sssii', $cloneName, $srcRow['BlocksJson'], $srcRow['PageOptionsJson'], $sortOrder, $createdBy);
                        $ins->execute();
                        $newId = (int)$db->insert_id;
                        $ins->close();
                        if (function_exists('logActivity')) {
                            logActivity('print_template.clone', 'print_template', (string)$newId, ['source' => $id, 'name' => $cloneName]);
                        }
                    }
                }
                header('Location: /manage/print-templates?cloned=1');
                exit;
            }

            /* #1767 J — make ONE template the system default. A single default is
               the invariant, so this clears IsDefault on every other song
               template first, in a transaction, and forces the chosen one active
               (a hidden default would be pointless). */
            case 'set_default': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    $db->begin_transaction();
                    try {
                        $db->query("UPDATE tblPrintTemplates SET IsDefault = 0 WHERE Scope = 'song' AND IsDefault = 1");
                        $s = $db->prepare("UPDATE tblPrintTemplates SET IsDefault = 1, IsActive = 1 WHERE Id = ? AND Scope = 'song'");
                        $s->bind_param('i', $id);
                        $s->execute();
                        $s->close();
                        $db->commit();
                        if (function_exists('logActivity')) {
                            logActivity('print_template.set_default', 'print_template', (string)$id, []);
                        }
                    } catch (\Throwable $e) {
                        $db->rollback();
                        throw $e;
                    }
                }
                header('Location: /manage/print-templates?default_set=1');
                exit;
            }

            /* #1767 Z — import a template from pasted JSON (the round-trip
               partner of the ?export= download). The blocks/page-options go
               through the SAME sanitiser the editor's save uses, so an imported
               file can never introduce a block type or option the renderer
               doesn't understand — a hostile or hand-edited JSON is reduced to
               its recognised subset or rejected. */
            case 'import': {
                $raw = json_decode((string)($_POST['import_json'] ?? ''), true);
                if (!is_array($raw)) {
                    $error = 'Import failed: that is not valid template JSON.';
                    break;
                }
                $name = mb_substr(trim((string)($raw['name'] ?? '')), 0, 120);
                if ($name === '') { $name = 'Imported template'; }
                $rawBlocks = $raw['blocks'] ?? null;
                if (!is_array($rawBlocks) || $rawBlocks === []) {
                    $error = 'Import failed: the JSON has no blocks.';
                    break;
                }
                $blocks = ptSanitiseBlocks($rawBlocks, $BLOCK_SCHEMA, $SHOWIF_CONDITIONS);
                if ($blocks === []) {
                    $error = 'Import failed: none of the blocks in the JSON were recognised.';
                    break;
                }
                $blocksJson  = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $pageOpts    = ptSanitisePageOptions($raw['pageOptions'] ?? null, $PAGE_OPTION_SCHEMA);
                $pageOptsJson = $pageOpts !== null
                    ? json_encode($pageOpts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null;

                $sortRes   = $db->query("SELECT COALESCE(MAX(SortOrder),0)+1 AS n FROM tblPrintTemplates WHERE Scope='song'");
                $sortRow   = $sortRes ? $sortRes->fetch_assoc() : null;
                if ($sortRes) { $sortRes->close(); }
                $sortOrder = (int)($sortRow['n'] ?? 0);
                $createdBy = (int)($currentUser['id'] ?? 0);
                $ins = $db->prepare(
                    "INSERT INTO tblPrintTemplates
                        (Name, Scope, OwnerId, BlocksJson, PageOptionsJson, IsActive, IsDefault, SortOrder, CreatedBy)
                     VALUES (?, 'song', NULL, ?, ?, 1, 0, ?, ?)"
                );
                $ins->bind_param('sssii', $name, $blocksJson, $pageOptsJson, $sortOrder, $createdBy);
                $ins->execute();
                $newId = (int)$db->insert_id;
                $ins->close();
                if (function_exists('logActivity')) {
                    logActivity('print_template.import', 'print_template', (string)$newId, ['name' => $name, 'blocks' => count($blocks)]);
                }
                header('Location: /manage/print-templates?imported=1');
                exit;
            }
        }
    } catch (\Throwable $e) {
        /* Log the detail; show a generic message so raw SQL/table names never
           surface in the curator UI (#1350 Phase 2 review). */
        error_log('[print-templates POST] ' . $e->getMessage());
        $error = 'Could not save changes — please try again.';
    }
}

/* Flash from the PRG redirect. */
if (isset($_GET['saved']))       { $success = 'Template saved.'; }
if (isset($_GET['deleted']))     { $success = 'Template deleted.'; }
if (isset($_GET['cloned']))      { $success = 'Template cloned.'; }
if (isset($_GET['default_set'])) { $success = 'Default template set.'; }
if (isset($_GET['imported']))    { $success = 'Template imported.'; }

/* #1767 Z — export a template as a downloadable JSON file (the round-trip
   partner of the `import` POST action). GET so a plain link works; streamed
   before any HTML. The shape { name, blocks, pageOptions } is exactly what
   `import` reads back. */
if ($hasSchema && isset($_GET['export'])) {
    $exportId = (int)$_GET['export'];
    if ($exportId > 0) {
        try {
            $stmt = $db->prepare(
                "SELECT Name, BlocksJson, PageOptionsJson FROM tblPrintTemplates
                  WHERE Id = ? AND Scope = 'song' LIMIT 1"
            );
            $stmt->bind_param('i', $exportId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $payload = [
                    'name'        => (string)$row['Name'],
                    'blocks'      => json_decode((string)$row['BlocksJson'], true) ?: [],
                    'pageOptions' => $row['PageOptionsJson'] !== null
                        ? (json_decode((string)$row['PageOptionsJson'], true) ?: null)
                        : null,
                    '_exportedFrom' => 'iHymns print template',
                ];
                $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$row['Name'])) ?: 'template';
                header('Content-Type: application/json; charset=UTF-8');
                header('Content-Disposition: attachment; filename="print-template-' . trim($slug, '-') . '.json"');
                header('X-Content-Type-Options: nosniff');
                echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
        } catch (\Throwable $e) {
            error_log('[print-templates export] ' . $e->getMessage());
        }
    }
    header('Location: /manage/print-templates?export_missing=1');
    exit;
}
if (isset($_GET['export_missing'])) { $error = 'That template could not be exported.'; }

/* ---- Read the scope='song' template list ---- */
$templates  = [];   // for the table render
$editorData = [];   // id => {…} for the JS editor pre-load (decoded blocks)
if ($hasSchema) {
    try {
        $res = $db->query(
            "SELECT Id, Name, BlocksJson, PageOptionsJson, IsActive, IsDefault, SortOrder
               FROM tblPrintTemplates
              WHERE Scope = 'song'
              ORDER BY SortOrder ASC, Name ASC"
        );
        while ($res && ($row = $res->fetch_assoc())) {
            $id     = (int)$row['Id'];
            $blocks = json_decode((string)$row['BlocksJson'], true);
            if (!is_array($blocks)) { $blocks = []; }
            $pageOpts = json_decode((string)($row['PageOptionsJson'] ?? ''), true);
            if (!is_array($pageOpts)) { $pageOpts = new stdClass(); } // {} not [] for JS

            $templates[] = [
                'id'         => $id,
                'name'       => (string)$row['Name'],
                'blockCount' => count($blocks),
                'isActive'   => (int)$row['IsActive'],
                'isDefault'  => (int)$row['IsDefault'],
            ];
            $editorData[$id] = [
                'id'          => $id,
                'name'        => (string)$row['Name'],
                'isActive'    => (int)$row['IsActive'] === 1,
                'blocks'      => $blocks,
                'pageOptions' => $pageOpts,
            ];
        }
        if ($res) { $res->close(); }
    } catch (\Throwable $e) {
        error_log('[print-templates read] ' . $e->getMessage());
        $error = $error ?: 'Could not load templates — please try again.';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
    <title>Print Templates — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
    <style>
        /* Representative preview skin. The HTML structure of the preview
           is byte-identical to the printed page (it comes from the SAME
           renderTemplateBodyHtml() the print path uses); the canonical
           print CSS lives in print.js's printCss() (not exported), so
           this is a faithful-enough visual approximation for the editor.
           Base font-size is set inline by JS from pageOptions.fontPt and
           all sizes below are em-relative so the size slider is honoured. */
        .pt-preview-paper {
            background: #fff; color: #000;
            font-family: Georgia, 'Times New Roman', serif; line-height: 1.35;
            padding: 1.4em; border: 1px solid var(--bs-border-color, #ccc);
            border-radius: .25rem; max-height: 32rem; overflow: auto;
        }
        .pt-preview-paper .print-title    { font-size: 1.5em; font-weight: bold; margin: 0 0 .1em; }
        .pt-preview-paper .print-subtitle { font-size: .85em; color: #555; margin: 0 0 .6em; }
        .pt-preview-paper .print-credits  { font-size: .85em; color: #444; margin: 0 0 .8em; }
        .pt-preview-paper .print-scripture { font-size: .85em; color: #444; font-style: italic; margin: 0 0 .5em; }
        .pt-preview-paper .print-tune      { font-size: .85em; color: #444; margin: 0 0 .5em; }
        .pt-preview-paper .print-themes    { font-size: .85em; color: #555; margin: 0 0 .6em; }
        .pt-preview-paper .print-component { margin: 0 0 .9em; }
        .pt-preview-paper .print-label    { font-weight: bold; font-size: .85em; color: #444; margin-bottom: .2em; }
        .pt-preview-paper .lyric-chorus .print-line,
        .pt-preview-paper .lyric-refrain .print-line { font-style: italic; }
        .pt-preview-paper .print-line     { margin: .05em 0; }
        .pt-preview-paper .print-chord    { font-family: 'Courier New', monospace; font-weight: bold; color: #555; white-space: pre-wrap; margin: .15em 0 0; }
        .pt-preview-paper .print-text     { margin: 0 0 .8em; }
        .pt-preview-paper .print-qr       { margin: .9em 0; text-align: center; }
        .pt-preview-paper .print-qr-img   { display: inline-block; }
        .pt-preview-paper .print-qr-img svg { width: 100%; height: 100%; display: block; }
        .pt-preview-paper .print-qr-caption { font-family: 'Courier New', monospace; font-size: .7em; color: #444; margin-top: .3em; word-break: break-all; }
        .pt-preview-paper .print-permalink-url { font-family: 'Courier New', monospace; font-weight: bold; }
        .pt-preview-paper .print-footer   { margin-top: 1em; font-size: .8em; color: #777; }
        /* #1767 V/AB/AM — preview reflections of the page colour options. */
        .pt-preview-paper.pt-high-contrast .print-subtitle,
        .pt-preview-paper.pt-high-contrast .print-credits,
        .pt-preview-paper.pt-high-contrast .print-scripture,
        .pt-preview-paper.pt-high-contrast .print-tune,
        .pt-preview-paper.pt-high-contrast .print-themes,
        .pt-preview-paper.pt-high-contrast .print-label,
        .pt-preview-paper.pt-high-contrast .print-chord,
        .pt-preview-paper.pt-high-contrast .print-footer { color: #000; }
        .pt-preview-paper.pt-mono, .pt-preview-paper.pt-mono * { color: #000 !important; }
        .pt-preview-paper.pt-mono .print-qr-img svg { filter: grayscale(1); }
        .pt-preview-paper.pt-accent .print-title { color: var(--pt-accent); }
        .pt-preview-paper.pt-accent .print-label { color: var(--pt-accent); }
        .pt-block-row { border: 1px solid var(--bs-border-color, #444); border-radius: .375rem; }
    </style>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">
        <h1 class="h4 mb-3"><i class="bi bi-printer me-2"></i>Print Templates</h1>
        <p class="text-secondary small mb-4">
            Compose the printer-friendly song layouts curators can choose from the
            <strong>Print song</strong> dialog. A template is an ordered list of
            <em>blocks</em> (title, lyrics, copyright …); the live preview on the right
            renders through the exact same engine as the printed page, so what you see
            is what prints. The three built-in layouts ship in the app for offline use —
            these custom ones are merged in alongside them.
        </p>

        <?php if ($success): ?>
            <div class="alert alert-success py-2"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if (!$hasSchema): ?>
            <div class="card-admin p-4 text-center">
                <p class="mb-2">
                    <i class="bi bi-database-exclamation text-warning fs-1" aria-hidden="true"></i>
                </p>
                <h2 class="h6 mb-2">Schema not yet installed</h2>
                <p class="text-muted small mb-3">
                    The <code>tblPrintTemplates</code> table (#1350) isn't present yet on this
                    database. Run the migration, then return here to author templates.
                </p>
                <a href="/manage/setup-database" class="btn btn-amber btn-sm">
                    <i class="bi bi-database-gear me-1"></i>Run the Print Templates migration (#1350)
                </a>
            </div>
        <?php else: ?>

        <!-- List of existing scope='song' templates -->
        <div class="card-admin p-3 mb-4">
            <div class="d-flex align-items-center mb-3">
                <h2 class="h6 mb-0"><i class="bi bi-list-ul me-2"></i>Templates</h2>
                <button type="button" class="btn btn-amber btn-sm ms-auto" id="pt-new">
                    <i class="bi bi-plus-lg me-1"></i>New template
                </button>
            </div>

            <?php if ($templates === []): ?>
                <p class="text-muted small mb-0">No custom templates yet — use <strong>New template</strong> to create one.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle cp-sortable mb-0 admin-table-responsive">
                        <thead>
                            <tr>
                                <th data-col-priority="primary"   data-sort-key="name"   data-sort-type="text">Name</th>
                                <th data-col-priority="secondary" data-sort-key="blocks" data-sort-type="number" class="text-center">Blocks</th>
                                <th data-col-priority="primary"   data-sort-key="active" data-sort-type="text"   class="text-center">Active</th>
                                <th data-col-priority="primary"   class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($templates as $t): ?>
                                <tr>
                                    <td data-col-priority="primary" data-sort-value="<?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php if ($t['isDefault']): ?>
                                            <span class="badge bg-info-subtle text-info-emphasis ms-1">default</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-col-priority="secondary" class="text-center" data-sort-value="<?= (int)$t['blockCount'] ?>">
                                        <?= (int)$t['blockCount'] ?>
                                    </td>
                                    <td data-col-priority="primary" class="text-center" data-sort-value="<?= $t['isActive'] ? '1' : '0' ?>">
                                        <span class="badge <?= $t['isActive'] ? 'bg-success-subtle text-success-emphasis' : 'bg-secondary-subtle text-secondary-emphasis' ?>">
                                            <?= $t['isActive'] ? 'active' : 'inactive' ?>
                                        </span>
                                    </td>
                                    <td data-col-priority="primary" class="text-end">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button type="button" class="btn btn-outline-info pt-edit" data-id="<?= (int)$t['id'] ?>">
                                                <i class="bi bi-pencil"></i> Edit
                                            </button>
                                            <!-- #1767 J — make this the system default (single-default invariant enforced server-side). -->
                                            <?php if (!$t['isDefault']): ?>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="action" value="set_default">
                                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                                <button type="submit" class="btn btn-outline-secondary" title="Make this the default template">
                                                    <i class="bi bi-star"></i> Set default
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                            <!-- #1767 Z — clone this template into a fresh row. -->
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="action" value="clone">
                                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                                <button type="submit" class="btn btn-outline-secondary" title="Duplicate this template">
                                                    <i class="bi bi-files"></i> Clone
                                                </button>
                                            </form>
                                            <!-- #1767 Z — export this template as JSON (import partner below). -->
                                            <a class="btn btn-outline-secondary" href="/manage/print-templates?export=<?= (int)$t['id'] ?>" title="Download as JSON">
                                                <i class="bi bi-download"></i> Export
                                            </a>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this template?');">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger">
                                                    <i class="bi bi-trash"></i> Delete
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- #1767 Z — import a template from pasted JSON (round-trip partner
                 of the per-row Export). The paste goes through the SAME server
                 sanitiser as a normal save, so an unrecognised block/option is
                 dropped, never trusted. Collapsed by default to stay out of the
                 way. -->
            <?php if ($hasSchema): ?>
            <details class="mt-3">
                <summary class="text-info" style="cursor:pointer;"><i class="bi bi-upload me-1"></i>Import a template from JSON</summary>
                <form method="POST" class="mt-2">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="import">
                    <label for="pt-import-json" class="form-label small">Paste a previously-exported template JSON:</label>
                    <textarea name="import_json" id="pt-import-json" class="form-control font-monospace" rows="4"
                              placeholder='{"name":"My template","blocks":[…],"pageOptions":{…}}'></textarea>
                    <button type="submit" class="btn btn-sm btn-outline-info mt-2">
                        <i class="bi bi-upload me-1"></i>Import template
                    </button>
                </form>
            </details>
            <?php endif; ?>
        </div>

        <!-- Editor panel (hidden until New / Edit) -->
        <div class="card-admin p-3 mb-4 d-none" id="pt-editor">
            <form method="POST" id="pt-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="pt-id" value="">
                <input type="hidden" name="blocks_json" id="pt-blocks-json" value="">
                <input type="hidden" name="page_options_json" id="pt-page-options-json" value="">

                <div class="d-flex align-items-center mb-3">
                    <h2 class="h6 mb-0" id="pt-editor-title"><i class="bi bi-pencil-square me-2"></i>New template</h2>
                    <button type="button" class="btn btn-outline-secondary btn-sm ms-auto" id="pt-cancel">Cancel</button>
                </div>

                <div class="row g-3">
                    <!-- Left column: meta + palette + block list -->
                    <div class="col-lg-7">
                        <div class="row g-2 mb-3">
                            <div class="col-12">
                                <label class="form-label small mb-1" for="pt-name">Name</label>
                                <input type="text" class="form-control form-control-sm" id="pt-name" name="name" maxlength="120" required>
                            </div>
                        </div>

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="pt-active" checked>
                            <label class="form-check-label small" for="pt-active">Active — offer this template in the print dialog</label>
                        </div>

                        <label class="form-label small mb-1">Page options</label>
                        <div class="row g-2 align-items-end mb-3" id="pt-page-options"><!-- controls injected by JS from PRINT_PAGE_OPTIONS --></div>

                        <label class="form-label small mb-1">Add a block</label>
                        <div class="d-flex flex-wrap gap-2 mb-2" id="pt-palette"><!-- buttons injected by JS --></div>
                        <div class="alert alert-info py-2 px-3 small mb-3" role="note">
                            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                            <strong>Songbook &amp; number blocks are context-aware.</strong>
                            The <em>Songbook&nbsp;+&nbsp;number</em> block prints <strong>nothing</strong> for a
                            song that isn&rsquo;t in a published (official) songbook &mdash; e.g. a song in an
                            unofficial songbook or a catalogue, which has no song number. Both the songbook name
                            and the number are hidden for such songs, because they aren&rsquo;t relevant to that
                            song. Official-hymnal songs print their book and number as normal.
                        </div>

                        <label class="form-label small mb-1">Blocks (in print order)</label>
                        <div class="vstack gap-2" id="pt-block-list"><!-- rows injected by JS --></div>
                    </div>

                    <!-- Right column: live preview -->
                    <div class="col-lg-5">
                        <label class="form-label small mb-1">
                            <i class="bi bi-eye me-1"></i>Live preview <span class="text-muted">(sample song)</span>
                        </label>
                        <div class="pt-preview-paper" id="pt-preview"><!-- renderTemplateBodyHtml output --></div>
                    </div>
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-amber btn-sm ms-auto">
                        <i class="bi bi-save me-1"></i>Save template
                    </button>
                </div>
            </form>
        </div>

        <?php endif; ?>
    </div>

    <?php if ($hasSchema): ?>
    <script type="module">
        // The renderer + registry + sample song come from the SAME module the
        // print path uses, so the preview is byte-identical to the printout.
        import { PRINT_BLOCK_TYPES, PRINT_PAGE_OPTIONS, PRINT_SHOWIF_CONDITIONS, PRINT_SAMPLE_SONG, renderTemplateBodyHtml }
            from '/js/modules/print.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/print.js') ?>';
        import { bootSortableTables }
            from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';

        bootSortableTables(); // sortable list headers (#844)

        // #1767 R — a `qr` block previews as an image element pointing at the
        // same-origin /qr.php (CueRCode-backed) endpoint, emitted client-side by
        // print.js renderBlock('qr'); no client-side QR library is loaded here.

        // Existing templates for the Edit pre-load. JSON_HEX_* flags applied
        // server-side so this inline literal can't break out of the script.
        const TEMPLATES = <?= json_encode($editorData, $JSON_SAFE) ?>;

        // ---- small DOM helpers ----
        const $ = (sel) => document.querySelector(sel);
        function esc(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        const editorEl    = $('#pt-editor');
        const listEl      = $('#pt-block-list');
        const paletteEl   = $('#pt-palette');
        const pageOptsEl  = $('#pt-page-options');   // #1767 — page-options panel host
        const previewEl   = $('#pt-preview');
        const nameEl      = $('#pt-name');
        const activeEl    = $('#pt-active');
        const idEl        = $('#pt-id');
        const titleEl     = $('#pt-editor-title');

        // The working template the editor mutates; serialised to hidden inputs on submit.
        let working = freshTemplate();

        function freshTemplate() {
            return { id: null, name: '', isActive: true, pageOptions: defaultPageOptions(), blocks: [] };
        }

        // Seed pageOptions with every registry default, so a fresh template
        // starts explicit and the controls all have a value to bind to.
        function defaultPageOptions() {
            const po = {};
            Object.keys(PRINT_PAGE_OPTIONS).forEach((k) => { po[k] = PRINT_PAGE_OPTIONS[k].def; });
            return po;
        }

        // A new block, seeded with the registry's default options (deep-cloned).
        function freshBlock(type) {
            const reg  = PRINT_BLOCK_TYPES[type] || { options: {} };
            const opts = reg.options ? JSON.parse(JSON.stringify(reg.options)) : {};
            return Object.assign({ type }, opts);
        }

        // ---- palette: one "+ Add" button per registry entry ----
        function buildPalette() {
            paletteEl.innerHTML = '';
            Object.keys(PRINT_BLOCK_TYPES).forEach((type) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn btn-outline-info btn-sm';
                btn.innerHTML = '<i class="bi bi-plus-lg me-1"></i>' + esc(PRINT_BLOCK_TYPES[type].label || type);
                btn.addEventListener('click', () => {
                    working.blocks.push(freshBlock(type));
                    renderBlocks();
                    renderPreview();
                });
                paletteEl.appendChild(btn);
            });
        }

        // ---- page-options panel, driven by PRINT_PAGE_OPTIONS (#1767 G/V/AB/AM/F) ----
        function buildPageOptions() {
            pageOptsEl.innerHTML = '';
            Object.keys(PRINT_PAGE_OPTIONS).forEach((key) => {
                const def = PRINT_PAGE_OPTIONS[key];
                const cur = (working.pageOptions[key] !== undefined && working.pageOptions[key] !== null)
                    ? working.pageOptions[key] : def.def;
                const id  = 'pt-po-' + key;
                const col = document.createElement('div');
                col.className = 'col-auto';
                if (def.kind === 'bool') {
                    col.innerHTML = `<div class="form-check mt-4"><input class="form-check-input" type="checkbox" id="${id}" data-page-opt="${esc(key)}"${cur ? ' checked' : ''}>`
                        + `<label class="form-check-label small" for="${id}">${esc(def.label)}</label></div>`;
                } else if (def.kind === 'int') {
                    col.innerHTML = `<label class="form-label small mb-0" for="${id}">${esc(def.label)}</label>`
                        + `<input type="number" class="form-control form-control-sm" id="${id}" data-page-opt="${esc(key)}" min="${def.min}" max="${def.max}" value="${esc(cur)}" style="width:6rem">`;
                } else if (def.kind === 'enum') {
                    const opts = def.choices.map(c => `<option value="${esc(c)}"${String(cur) === String(c) ? ' selected' : ''}>${esc(c)}</option>`).join('');
                    col.innerHTML = `<label class="form-label small mb-0" for="${id}">${esc(def.label)}</label>`
                        + `<select class="form-select form-select-sm" id="${id}" data-page-opt="${esc(key)}">${opts}</select>`;
                } else if (def.kind === 'color') {
                    /* An <input type=color> can't represent "no accent", so pair it
                       with an enable checkbox; unchecked = '' (renderer default). */
                    const has = typeof cur === 'string' && cur !== '';
                    const val = has ? cur : '#333333';
                    col.innerHTML = `<div class="d-flex align-items-center gap-2 mt-4">`
                        + `<div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="${id}-on" data-page-opt-enable="${esc(key)}"${has ? ' checked' : ''}>`
                        + `<label class="form-check-label small" for="${id}-on">${esc(def.label)}</label></div>`
                        + `<input type="color" class="form-control form-control-color form-control-sm" id="${id}" data-page-opt="${esc(key)}" value="${esc(val)}"${has ? '' : ' disabled'} aria-label="${esc(def.label)}">`
                        + `</div>`;
                }
                pageOptsEl.appendChild(col);
            });
        }

        function onPageOptMutate(ev) {
            // Colour enable/disable toggle (the accent checkbox).
            const enableCtl = ev.target.closest('[data-page-opt-enable]');
            if (enableCtl) {
                const key = enableCtl.getAttribute('data-page-opt-enable');
                const colorInput = pageOptsEl.querySelector(`[data-page-opt="${key}"]`);
                if (enableCtl.checked) {
                    if (colorInput) { colorInput.disabled = false; working.pageOptions[key] = colorInput.value; }
                } else {
                    if (colorInput) { colorInput.disabled = true; }
                    working.pageOptions[key] = '';
                }
                renderPreview();
                return;
            }
            const ctl = ev.target.closest('[data-page-opt]');
            if (!ctl) { return; }
            const key = ctl.getAttribute('data-page-opt');
            const def = PRINT_PAGE_OPTIONS[key];
            if (!def) { return; }
            let val;
            if (def.kind === 'bool') { val = ctl.checked; }
            else if (def.kind === 'int') { val = Math.max(def.min, Math.min(def.max, parseInt(ctl.value, 10) || def.def)); }
            else { val = ctl.value; }
            working.pageOptions[key] = val;
            renderPreview();
        }
        pageOptsEl.addEventListener('input', onPageOptMutate);
        pageOptsEl.addEventListener('change', onPageOptMutate);

        // ---- option input for a single block option, driven by the registry default's type ----
        function optionFieldHtml(type, key, value) {
            const id = `pt-opt-${type}-${key}-${Math.random().toString(36).slice(2, 8)}`;
            const lbl = key.replace(/([A-Z])/g, ' $1').replace(/^./, c => c.toUpperCase());
            if (key === 'columns') {
                return `<div class="col-auto"><label class="form-label small mb-0" for="${id}">${esc(lbl)}</label>`
                    + `<select class="form-select form-select-sm" id="${id}" data-opt="${esc(key)}">`
                    + `<option value="1"${Number(value) === 1 ? ' selected' : ''}>1</option>`
                    + `<option value="2"${Number(value) === 2 ? ' selected' : ''}>2</option>`
                    + `</select></div>`;
            }
            if (key === 'size') {
                const sizes = [['sm', 'Small'], ['md', 'Medium'], ['lg', 'Large']];
                const opts = sizes.map(([v, t]) => `<option value="${v}"${value === v ? ' selected' : ''}>${t}</option>`).join('');
                return `<div class="col-auto"><label class="form-label small mb-0" for="${id}">${esc(lbl)}</label>`
                    + `<select class="form-select form-select-sm" id="${id}" data-opt="${esc(key)}">${opts}</select></div>`;
            }
            if (key === 'align') {   // #1767 A — text alignment select
                const aligns = [['left', 'Left'], ['center', 'Centre'], ['right', 'Right']];
                const opts = aligns.map(([v, t]) => `<option value="${v}"${value === v ? ' selected' : ''}>${t}</option>`).join('');
                return `<div class="col-auto"><label class="form-label small mb-0" for="${id}">${esc(lbl)}</label>`
                    + `<select class="form-select form-select-sm" id="${id}" data-opt="${esc(key)}">${opts}</select></div>`;
            }
            if (key === 'content') {
                return `<div class="col-12"><label class="form-label small mb-0" for="${id}">${esc(lbl)}</label>`
                    + `<input type="text" class="form-control form-control-sm" id="${id}" data-opt="${esc(key)}" maxlength="2000" value="${esc(value)}"></div>`;
            }
            // boolean default → checkbox
            return `<div class="col-auto"><div class="form-check small mt-3">`
                + `<input class="form-check-input" type="checkbox" id="${id}" data-opt="${esc(key)}"${value ? ' checked' : ''}>`
                + `<label class="form-check-label" for="${id}">${esc(lbl)}</label></div></div>`;
        }

        // ---- render the whole block list from working.blocks ----
        function renderBlocks() {
            listEl.innerHTML = '';
            if (!working.blocks.length) {
                listEl.innerHTML = '<p class="text-muted small mb-0">No blocks yet — add one from the palette above.</p>';
                return;
            }
            working.blocks.forEach((block, i) => {
                const reg = PRINT_BLOCK_TYPES[block.type] || { label: block.type, options: {} };
                const optKeys = Object.keys(reg.options || {});
                const optsHtml = optKeys.length
                    ? `<div class="row g-2 align-items-end mt-1">${optKeys.map(k => optionFieldHtml(block.type, k, block[k])).join('')}</div>`
                    : '<div class="small text-muted mt-1">No options.</div>';

                /* #1767 Y — universal conditional-visibility select. */
                const curShowIf = block.showIf || 'always';
                const showIfHtml = `<select class="form-select form-select-sm w-auto" data-showif aria-label="When to show the ${esc(reg.label || block.type)} block">`
                    + Object.keys(PRINT_SHOWIF_CONDITIONS).map(c =>
                        `<option value="${esc(c)}"${c === curShowIf ? ' selected' : ''}>${esc(PRINT_SHOWIF_CONDITIONS[c].label)}</option>`).join('')
                    + `</select>`;

                const row = document.createElement('div');
                row.className = 'pt-block-row p-2 bg-body-tertiary';
                row.dataset.index = String(i);
                row.innerHTML =
                    `<div class="d-flex align-items-center gap-2 flex-wrap">`
                  +   `<span class="badge bg-secondary-subtle text-secondary-emphasis">${i + 1}</span>`
                  +   `<strong class="small">${esc(reg.label || block.type)}</strong>`
                  +   showIfHtml
                  +   `<div class="ms-auto btn-group btn-group-sm">`
                  +     `<button type="button" class="btn btn-outline-secondary" data-act="up"${i === 0 ? ' disabled' : ''} title="Move up" aria-label="Move ${esc(reg.label || block.type)} block up"><i class="bi bi-arrow-up" aria-hidden="true"></i></button>`
                  +     `<button type="button" class="btn btn-outline-secondary" data-act="down"${i === working.blocks.length - 1 ? ' disabled' : ''} title="Move down" aria-label="Move ${esc(reg.label || block.type)} block down"><i class="bi bi-arrow-down" aria-hidden="true"></i></button>`
                  +     `<button type="button" class="btn btn-outline-danger" data-act="remove" title="Remove" aria-label="Remove ${esc(reg.label || block.type)} block"><i class="bi bi-x-lg" aria-hidden="true"></i></button>`
                  +   `</div>`
                  + `</div>`
                  + optsHtml;
                listEl.appendChild(row);
            });
        }

        // ---- live preview via the shared renderer ----
        // Reflect the page options the print CSS applies (fontPt, line spacing,
        // contrast/ink-saver, accent) so the preview stays faithful. Colour is
        // applied via preview-skin classes + a --pt-accent variable rather than
        // printCss (which isn't exported); structure comes from the shared renderer.
        function renderPreview() {
            const po = working.pageOptions || {};
            previewEl.style.fontSize   = (parseInt(po.fontPt, 10) || 12) + 'pt';
            previewEl.style.lineHeight = po.lineHeight === 'tight' ? '1.2'
                                       : po.lineHeight === 'relaxed' ? '1.6' : '1.35';
            const ink  = po.inkSaver === true;
            const high = po.contrast === 'high';
            previewEl.classList.toggle('pt-mono', ink);
            previewEl.classList.toggle('pt-high-contrast', high && !ink);
            const accent = (!ink && typeof po.accentColor === 'string'
                && /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(po.accentColor)) ? po.accentColor : '';
            previewEl.classList.toggle('pt-accent', !!accent);
            if (accent) { previewEl.style.setProperty('--pt-accent', accent); }
            previewEl.innerHTML = renderTemplateBodyHtml(
                PRINT_SAMPLE_SONG,
                { blocks: working.blocks, pageOptions: working.pageOptions }
            );
        }

        // ---- delegated handlers on the block list (option edits + reorder/remove) ----
        function onBlockMutate(ev) {
            // #1767 Y — universal showIf select (any block).
            const showIfCtl = ev.target.closest('[data-showif]');
            if (showIfCtl) {
                const row = showIfCtl.closest('[data-index]');
                if (!row) { return; }
                const block = working.blocks[parseInt(row.dataset.index, 10)];
                if (!block) { return; }
                const v = showIfCtl.value;
                if (v && v !== 'always') { block.showIf = v; } else { delete block.showIf; }
                renderPreview();
                return;
            }
            const ctl = ev.target.closest('[data-opt]');
            if (!ctl) { return; }
            const row = ctl.closest('[data-index]');
            if (!row) { return; }
            const i = parseInt(row.dataset.index, 10);
            const key = ctl.getAttribute('data-opt');
            const block = working.blocks[i];
            if (!block) { return; }
            if (ctl.type === 'checkbox') {
                block[key] = ctl.checked;
            } else if (key === 'columns') {
                block[key] = parseInt(ctl.value, 10) === 2 ? 2 : 1;
            } else {
                block[key] = ctl.value;
            }
            renderPreview();
        }
        listEl.addEventListener('input', onBlockMutate);
        listEl.addEventListener('change', onBlockMutate);
        listEl.addEventListener('click', (ev) => {
            const btn = ev.target.closest('[data-act]');
            if (!btn) { return; }
            const row = btn.closest('[data-index]');
            const i = parseInt(row.dataset.index, 10);
            const act = btn.getAttribute('data-act');
            if (act === 'remove') {
                working.blocks.splice(i, 1);
            } else if (act === 'up' && i > 0) {
                [working.blocks[i - 1], working.blocks[i]] = [working.blocks[i], working.blocks[i - 1]];
            } else if (act === 'down' && i < working.blocks.length - 1) {
                [working.blocks[i + 1], working.blocks[i]] = [working.blocks[i], working.blocks[i + 1]];
            } else {
                return;
            }
            renderBlocks();
            renderPreview();
        });

        // (page-option inputs feed working.pageOptions via onPageOptMutate, wired above.)

        // ---- open / close the editor ----
        function openEditor(tpl) {
            working = tpl
                ? {
                    id: tpl.id,
                    name: tpl.name,
                    isActive: tpl.isActive !== false,
                    /* Registry defaults first, then the saved template's values on
                       top — so an older template missing a newer option still gets
                       a sensible default and every control has a value to bind. */
                    pageOptions: Object.assign(defaultPageOptions(), tpl.pageOptions || {}),
                    blocks: JSON.parse(JSON.stringify(Array.isArray(tpl.blocks) ? tpl.blocks : [])),
                  }
                : freshTemplate();

            idEl.value     = working.id ? String(working.id) : '';
            nameEl.value   = working.name || '';
            activeEl.checked = working.isActive;
            titleEl.innerHTML = '<i class="bi bi-pencil-square me-2"></i>' + (working.id ? 'Edit template' : 'New template');

            buildPageOptions();
            renderBlocks();
            renderPreview();
            editorEl.classList.remove('d-none');
            editorEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        $('#pt-new').addEventListener('click', () => openEditor(null));
        $('#pt-cancel').addEventListener('click', () => editorEl.classList.add('d-none'));
        document.querySelectorAll('.pt-edit').forEach((btn) => {
            btn.addEventListener('click', () => {
                const id = btn.getAttribute('data-id');
                const tpl = TEMPLATES[id];
                if (tpl) { openEditor(tpl); }
            });
        });

        // ---- serialise working state into hidden inputs on submit ----
        $('#pt-form').addEventListener('submit', (ev) => {
            if (!nameEl.value.trim()) {
                ev.preventDefault();
                alert('Please give the template a name.');
                nameEl.focus();
                return;
            }
            if (!working.blocks.length) {
                ev.preventDefault();
                alert('Add at least one block before saving.');
                return;
            }
            // working.pageOptions is kept current by onPageOptMutate; just serialise.
            $('#pt-blocks-json').value        = JSON.stringify(working.blocks);
            $('#pt-page-options-json').value   = JSON.stringify(working.pageOptions);
            // name + is_active submit as native form fields.
        });

        buildPalette();
        /* #1767 R — the QR block previews via an <img> to /qr.php (CueRCode-backed);
           renderTemplateBodyHtml emits it directly, so no QR pre-pass is needed. */
    </script>
    <?php endif; ?>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
