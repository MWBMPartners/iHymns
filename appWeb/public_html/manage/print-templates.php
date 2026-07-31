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
    'subtitle'    => ['showBook' => 'bool', 'showNumber' => 'bool'],
    'credits'     => [],
    'lyrics'      => ['showLabels' => 'bool', 'showChords' => 'bool', 'columns' => 'cols'],
    'copyright'   => [],
    'identifiers' => ['ccli' => 'bool', 'iswc' => 'bool'],
    'text'        => ['content' => 'str'],
    'permalink'   => [],
    'spacer'      => ['size' => 'size'],
    'pagebreak'   => [],
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
 * @param array $raw          The json_decode'd POST blocks (assoc).
 * @param array $schema       $BLOCK_SCHEMA.
 * @return array              Sanitised ordered block list.
 */
function ptSanitiseBlocks(array $raw, array $schema): array
{
    $clean = [];
    foreach ($raw as $block) {
        if (!is_array($block)) { continue; }                 // not an object — drop
        $type = (string)($block['type'] ?? '');
        if (!isset($schema[$type])) { continue; }            // unknown type — drop
        $row = ['type' => $type];
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
 * Sanitise decoded page options. Keeps only fontPt (clamped 6–72) and
 * columns (1|2). Returns null when nothing valid was supplied.
 */
function ptSanitisePageOptions($raw): ?array
{
    if (!is_array($raw)) { return null; }
    $out = [];
    if (isset($raw['fontPt'])) {
        $out['fontPt'] = max(6, min(72, (int)$raw['fontPt']));
    }
    /* NB: no page-level `columns` here (#1350 Phase 2 review) — the renderer
       (print.js) only reads pageOptions.fontPt, and column layout is a per-LYRICS
       block option, so a page-level columns key would be unreachable + unused.
       Re-add here only alongside an editor control + renderer support. */
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
                $blocks = ptSanitiseBlocks($rawBlocks, $BLOCK_SCHEMA);
                if ($blocks === []) {
                    $error = 'None of the submitted blocks were recognised.';
                    break;
                }
                $blocksJson = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                /* Page options decode → sanitise → JSON or NULL. */
                $rawPageOpts = json_decode((string)($_POST['page_options_json'] ?? ''), true);
                $pageOpts    = ptSanitisePageOptions($rawPageOpts);
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
        }
    } catch (\Throwable $e) {
        /* Log the detail; show a generic message so raw SQL/table names never
           surface in the curator UI (#1350 Phase 2 review). */
        error_log('[print-templates POST] ' . $e->getMessage());
        $error = 'Could not save changes — please try again.';
    }
}

/* Flash from the PRG redirect. */
if (isset($_GET['saved']))   { $success = 'Template saved.'; }
if (isset($_GET['deleted'])) { $success = 'Template deleted.'; }

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
        .pt-preview-paper .print-component { margin: 0 0 .9em; }
        .pt-preview-paper .print-label    { font-weight: bold; font-size: .85em; color: #444; margin-bottom: .2em; }
        .pt-preview-paper .lyric-chorus .print-line,
        .pt-preview-paper .lyric-refrain .print-line { font-style: italic; }
        .pt-preview-paper .print-line     { margin: .05em 0; }
        .pt-preview-paper .print-chord    { font-family: 'Courier New', monospace; font-weight: bold; color: #555; white-space: pre-wrap; margin: .15em 0 0; }
        .pt-preview-paper .print-text     { margin: 0 0 .8em; }
        .pt-preview-paper .print-footer   { margin-top: 1em; font-size: .8em; color: #777; }
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
                                        <button type="button" class="btn btn-sm btn-outline-info pt-edit" data-id="<?= (int)$t['id'] ?>">
                                            <i class="bi bi-pencil"></i> Edit
                                        </button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this template?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-trash"></i> Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
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
                            <div class="col-sm-8">
                                <label class="form-label small mb-1" for="pt-name">Name</label>
                                <input type="text" class="form-control form-control-sm" id="pt-name" name="name" maxlength="120" required>
                            </div>
                            <div class="col-sm-4">
                                <label class="form-label small mb-1" for="pt-fontpt">Base font (pt)</label>
                                <input type="number" class="form-control form-control-sm" id="pt-fontpt" min="6" max="72" value="12">
                            </div>
                        </div>

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="pt-active" checked>
                            <label class="form-check-label small" for="pt-active">Active — offer this template in the print dialog</label>
                        </div>

                        <label class="form-label small mb-1">Add a block</label>
                        <div class="d-flex flex-wrap gap-2 mb-3" id="pt-palette"><!-- buttons injected by JS --></div>

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
        import { PRINT_BLOCK_TYPES, PRINT_SAMPLE_SONG, renderTemplateBodyHtml }
            from '/js/modules/print.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/print.js') ?>';
        import { bootSortableTables }
            from '/js/modules/admin-table-sort.js?v=<?= filemtime(dirname(__DIR__) . '/js/modules/admin-table-sort.js') ?>';

        bootSortableTables(); // sortable list headers (#844)

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

        const editorEl  = $('#pt-editor');
        const listEl    = $('#pt-block-list');
        const paletteEl = $('#pt-palette');
        const previewEl = $('#pt-preview');
        const nameEl    = $('#pt-name');
        const fontEl    = $('#pt-fontpt');
        const activeEl  = $('#pt-active');
        const idEl      = $('#pt-id');
        const titleEl   = $('#pt-editor-title');

        // The working template the editor mutates; serialised to hidden inputs on submit.
        let working = freshTemplate();

        function freshTemplate() {
            return { id: null, name: '', isActive: true, pageOptions: { fontPt: 12 }, blocks: [] };
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

                const row = document.createElement('div');
                row.className = 'pt-block-row p-2 bg-body-tertiary';
                row.dataset.index = String(i);
                row.innerHTML =
                    `<div class="d-flex align-items-center gap-2">`
                  +   `<span class="badge bg-secondary-subtle text-secondary-emphasis">${i + 1}</span>`
                  +   `<strong class="small">${esc(reg.label || block.type)}</strong>`
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
        function renderPreview() {
            previewEl.style.fontSize = (parseInt(working.pageOptions.fontPt, 10) || 12) + 'pt';
            previewEl.innerHTML = renderTemplateBodyHtml(
                PRINT_SAMPLE_SONG,
                { blocks: working.blocks, pageOptions: working.pageOptions }
            );
        }

        // ---- delegated handlers on the block list (option edits + reorder/remove) ----
        function onBlockMutate(ev) {
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

        // font-size input feeds pageOptions
        fontEl.addEventListener('input', () => {
            working.pageOptions.fontPt = Math.max(6, Math.min(72, parseInt(fontEl.value, 10) || 12));
            renderPreview();
        });

        // ---- open / close the editor ----
        function openEditor(tpl) {
            working = tpl
                ? {
                    id: tpl.id,
                    name: tpl.name,
                    isActive: tpl.isActive !== false,
                    pageOptions: Object.assign({ fontPt: 12 }, tpl.pageOptions || {}),
                    blocks: JSON.parse(JSON.stringify(Array.isArray(tpl.blocks) ? tpl.blocks : [])),
                  }
                : freshTemplate();

            idEl.value     = working.id ? String(working.id) : '';
            nameEl.value   = working.name || '';
            fontEl.value   = String(parseInt(working.pageOptions.fontPt, 10) || 12);
            activeEl.checked = working.isActive;
            titleEl.innerHTML = '<i class="bi bi-pencil-square me-2"></i>' + (working.id ? 'Edit template' : 'New template');

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
            working.pageOptions.fontPt = Math.max(6, Math.min(72, parseInt(fontEl.value, 10) || 12));
            $('#pt-blocks-json').value        = JSON.stringify(working.blocks);
            $('#pt-page-options-json').value   = JSON.stringify(working.pageOptions);
            // name + is_active submit as native form fields.
        });

        buildPalette();
    </script>
    <?php endif; ?>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
