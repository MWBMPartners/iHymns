<?php

declare(strict_types=1);

/**
 * iHymns — Admin: Credit People (#545, read-only list slice)
 *
 * Catalogue-wide view of every individual credited on songs, unioned
 * across tblSongWriters / tblSongComposers / tblSongArrangers /
 * tblSongAdaptors / tblSongTranslators, plus the registry rows in
 * tblCreditPeople (which may exist without any current song citing
 * them — pre-registered names for upcoming songs, or names whose
 * usage was cleaned up but the metadata is being kept).
 *
 * This first slice is READ-ONLY:
 *   - Sorts the table client-side.
 *   - Filters by role + free-text search client-side.
 *   - Surfaces the same data the eventual rename / merge / detail
 *     workflows will operate on.
 *   - Adds NO mutations (no POST handlers, no CSRF token usage yet).
 *
 * The Add / Rename / Merge / Person-detail-drawer / Delete actions
 * land in a follow-up PR against the same #545. Splitting like this
 * lets the schema (#553) and the listing query bed in on alpha
 * before we layer mutating endpoints on top.
 *
 * Database access uses mysqli prepared statements throughout
 * (project policy, set 2026-04-27). The two queries on this page
 * are static SQL, so they don't strictly need bound parameters, but
 * we run them via `prepare()` + `execute()` anyway to match the
 * pattern that the follow-up POST handlers will use.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';

if (!isAuthenticated()) {
    header('Location: /manage/login');
    exit;
}
$currentUser = getCurrentUser();
if (!$currentUser || !userHasEntitlement('manage_credit_people', $currentUser['role'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><h1>403 — manage_credit_people required</h1></body></html>';
    exit;
}
$activePage = 'credit-people';

$error = '';

/* ----------------------------------------------------------------------
 * Data load
 *
 * Two queries, merged in PHP. Cleaner than a hand-rolled FULL OUTER
 * JOIN simulation, and the row count on these tables is small enough
 * that the merge cost is negligible.
 *
 *   Q1 — every distinct name across the five song-credit tables,
 *        with per-role counts and a total. Names not currently in
 *        any registry row also surface here.
 *   Q2 — every registry row in tblCreditPeople plus its biographical
 *        metadata + child-table counts. Registry rows that no song
 *        currently cites also surface here.
 * ---------------------------------------------------------------------- */

$people = [];

try {
    $db = getDbMysqli();

    /* Q1 — usage aggregate across the five song-credit tables. */
    $usageSql = "
        SELECT Name,
               SUM(IF(kindLabel = 'writer',     cnt, 0)) AS WriterCount,
               SUM(IF(kindLabel = 'composer',   cnt, 0)) AS ComposerCount,
               SUM(IF(kindLabel = 'arranger',   cnt, 0)) AS ArrangerCount,
               SUM(IF(kindLabel = 'adaptor',    cnt, 0)) AS AdaptorCount,
               SUM(IF(kindLabel = 'translator', cnt, 0)) AS TranslatorCount,
               SUM(cnt) AS TotalUsage
          FROM (
              SELECT Name, 'writer'     AS kindLabel, COUNT(*) AS cnt FROM tblSongWriters     GROUP BY Name
              UNION ALL
              SELECT Name, 'composer'   AS kindLabel, COUNT(*) AS cnt FROM tblSongComposers   GROUP BY Name
              UNION ALL
              SELECT Name, 'arranger'   AS kindLabel, COUNT(*) AS cnt FROM tblSongArrangers   GROUP BY Name
              UNION ALL
              SELECT Name, 'adaptor'    AS kindLabel, COUNT(*) AS cnt FROM tblSongAdaptors    GROUP BY Name
              UNION ALL
              SELECT Name, 'translator' AS kindLabel, COUNT(*) AS cnt FROM tblSongTranslators GROUP BY Name
          ) u
         GROUP BY Name
    ";
    $stmt = $db->prepare($usageSql);
    $stmt->execute();
    $usageRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    /* Q2 — registry rows + child-table counts (links, IPI). The
       sub-selects keep the query a single round-trip; if either child
       table grows large enough that the per-row sub-select cost shows
       up, swap to a GROUP BY join. Not now — current row counts make
       this trivially fast. */
    $registrySql = "
        SELECT p.Id,
               p.Name,
               p.Notes,
               p.BirthPlace,
               p.BirthDate,
               p.DeathPlace,
               p.DeathDate,
               p.UpdatedAt,
               (SELECT COUNT(*) FROM tblCreditPersonLinks l WHERE l.CreditPersonId = p.Id) AS LinkCount,
               (SELECT COUNT(*) FROM tblCreditPersonIPI   i WHERE i.CreditPersonId = p.Id) AS IPICount
          FROM tblCreditPeople p
    ";
    $stmt = $db->prepare($registrySql);
    $stmt->execute();
    $registryRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    /* Merge — keyed by Name. A name may appear in usage only,
       registry only, or both. */
    $byName = [];
    foreach ($usageRows as $u) {
        $name = (string)$u['Name'];
        $byName[$name] = [
            'name'        => $name,
            'writers'     => (int)$u['WriterCount'],
            'composers'   => (int)$u['ComposerCount'],
            'arrangers'   => (int)$u['ArrangerCount'],
            'adaptors'    => (int)$u['AdaptorCount'],
            'translators' => (int)$u['TranslatorCount'],
            'total'       => (int)$u['TotalUsage'],
            'registry_id' => null,
            'notes'       => null,
            'birth_place' => null,
            'birth_date'  => null,
            'death_place' => null,
            'death_date'  => null,
            'updated_at'  => null,
            'link_count'  => 0,
            'ipi_count'   => 0,
        ];
    }
    foreach ($registryRows as $r) {
        $name = (string)$r['Name'];
        if (!isset($byName[$name])) {
            /* Registry-only — no song currently cites this person. */
            $byName[$name] = [
                'name'        => $name,
                'writers'     => 0,
                'composers'   => 0,
                'arrangers'   => 0,
                'adaptors'    => 0,
                'translators' => 0,
                'total'       => 0,
                'registry_id' => null,
                'notes'       => null,
                'birth_place' => null,
                'birth_date'  => null,
                'death_place' => null,
                'death_date'  => null,
                'updated_at'  => null,
                'link_count'  => 0,
                'ipi_count'   => 0,
            ];
        }
        $byName[$name]['registry_id'] = (int)$r['Id'];
        $byName[$name]['notes']       = $r['Notes'];
        $byName[$name]['birth_place'] = $r['BirthPlace'];
        $byName[$name]['birth_date']  = $r['BirthDate'];
        $byName[$name]['death_place'] = $r['DeathPlace'];
        $byName[$name]['death_date']  = $r['DeathDate'];
        $byName[$name]['updated_at']  = $r['UpdatedAt'];
        $byName[$name]['link_count']  = (int)$r['LinkCount'];
        $byName[$name]['ipi_count']   = (int)$r['IPICount'];
    }

    /* Sort: highest-usage first, then alphabetical. Registry-only
       entries (total = 0) bubble to the bottom inside their alpha
       block — easy for the curator to spot which pre-registered
       names are still waiting on a song to cite them. */
    uasort($byName, static function (array $a, array $b): int {
        if ($a['total'] !== $b['total']) {
            return $b['total'] <=> $a['total'];
        }
        return strcasecmp($a['name'], $b['name']);
    });

    $people = array_values($byName);
} catch (\Throwable $e) {
    error_log('[manage/credit-people.php] load failed: ' . $e->getMessage());
    $error = 'Could not load credit people — check server logs for details.';
}

/* ----------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/** Render a compact "b. 1725 · d. 1807" lifespan, or '' if neither known. */
$lifespan = static function (?string $birth, ?string $death): string {
    $bYear = $birth ? substr($birth, 0, 4) : '';
    $dYear = $death ? substr($death, 0, 4) : '';
    if ($bYear === '' && $dYear === '') return '';
    if ($bYear !== '' && $dYear === '') return 'b. ' . htmlspecialchars($bYear);
    if ($bYear === '' && $dYear !== '') return 'd. ' . htmlspecialchars($dYear);
    return 'b. ' . htmlspecialchars($bYear) . ' · d. ' . htmlspecialchars($dYear);
};

/** Source badge: registry-only, in-use only, or both. */
$sourceBadge = static function (array $p): string {
    $inRegistry = $p['registry_id'] !== null;
    $inUse      = $p['total'] > 0;
    if ($inRegistry && $inUse) return '<span class="badge bg-success-subtle text-success-emphasis">Both</span>';
    if ($inRegistry)           return '<span class="badge bg-info-subtle text-info-emphasis">Registry</span>';
    return '<span class="badge bg-secondary-subtle text-secondary-emphasis">In use</span>';
};

$totalNames           = count($people);
$totalInRegistry      = count(array_filter($people, static fn($p) => $p['registry_id'] !== null));
$totalInUse           = count(array_filter($people, static fn($p) => $p['total'] > 0));
$totalRegistryOnly    = $totalNames - $totalInUse;
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credit People — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
    <style>
        /* Per-role badge colours. Picked to match the editor's chip
           colours so an admin who jumps between this page and the
           Song Editor has visual continuity. */
        .role-pill            { font-variant-numeric: tabular-nums; }
        .role-pill.role-w     { background-color: #1f6feb33; color: #79b8ff; }
        .role-pill.role-c     { background-color: #6f42c133; color: #b392f0; }
        .role-pill.role-ar    { background-color: #fb950033; color: #ffab40; }
        .role-pill.role-ad    { background-color: #2ea04333; color: #56d364; }
        .role-pill.role-t     { background-color: #d73a4933; color: #ff7b72; }
        .role-pill[data-zero] { opacity: 0.25; }

        /* Person-name column gets a slightly larger, slightly bolder
           treatment so the eye lands on it first. */
        td.person-name        { font-weight: 500; }
        td.person-name code   { font-size: 0.85em; opacity: 0.6; }

        /* Lifespan + link/IPI badge alignment. */
        td.meta-col           { white-space: nowrap; font-size: 0.85em; opacity: 0.85; }
        .badge-icon-count     { font-variant-numeric: tabular-nums; }

        /* Active filter button + search-empty placeholder row. */
        .filter-btn.active    { background-color: var(--bs-primary); color: #fff; border-color: var(--bs-primary); }
        tr.no-match           { display: none; }
        .empty-row td         { text-align: center; padding: 2rem; opacity: 0.6; }
    </style>
</head>
<body>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">

        <h1 class="h4 mb-2"><i class="bi bi-person-badge me-2"></i>Credit People</h1>
        <p class="text-secondary small mb-3">
            Every individual credited as a writer, composer, arranger, adaptor or
            translator across the catalogue, plus every pre-registered name in
            <code>tblCreditPeople</code>. Use this view to spot duplicates that
            need merging (e.g. <code>J. Newton</code> vs <code>John Newton</code>)
            or registry-only names that are waiting on a song to cite them.
        </p>

        <!-- Slice scope notice -->
        <div class="alert alert-info py-2 small mb-3">
            <i class="bi bi-info-circle me-1"></i>
            <strong>Read-only view (v1).</strong>
            Add / Rename / Merge / Edit-detail / Delete actions land in the next PR
            against #545. The data here is the same data those mutations will
            operate on, so you can use this view today to plan which names need
            cleanup.
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Summary tiles -->
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-3">
                <div class="card bg-dark border-secondary p-2">
                    <div class="small text-secondary">Total distinct names</div>
                    <div class="h5 mb-0"><?= number_format($totalNames) ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card bg-dark border-secondary p-2">
                    <div class="small text-secondary">Cited by &ge; 1 song</div>
                    <div class="h5 mb-0"><?= number_format($totalInUse) ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card bg-dark border-secondary p-2">
                    <div class="small text-secondary">In registry</div>
                    <div class="h5 mb-0"><?= number_format($totalInRegistry) ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card bg-dark border-secondary p-2">
                    <div class="small text-secondary">Registry-only (not yet used)</div>
                    <div class="h5 mb-0"><?= number_format($totalRegistryOnly) ?></div>
                </div>
            </div>
        </div>

        <!-- Search + filters -->
        <div class="card bg-dark border-secondary p-3 mb-3">
            <div class="row g-2 align-items-center">
                <div class="col-md-5">
                    <label for="cp-search" class="visually-hidden">Search names</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="search" class="form-control" id="cp-search"
                               placeholder="Filter by name, lifespan, notes…" autocomplete="off">
                        <button class="btn btn-outline-secondary" type="button" id="cp-search-clear" title="Clear">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="btn-group btn-group-sm flex-wrap" role="group" aria-label="Filter by role">
                        <button type="button" class="btn btn-outline-secondary filter-btn active" data-filter="all">All</button>
                        <button type="button" class="btn btn-outline-secondary filter-btn" data-filter="writer">Writers</button>
                        <button type="button" class="btn btn-outline-secondary filter-btn" data-filter="composer">Composers</button>
                        <button type="button" class="btn btn-outline-secondary filter-btn" data-filter="arranger">Arrangers</button>
                        <button type="button" class="btn btn-outline-secondary filter-btn" data-filter="adaptor">Adaptors</button>
                        <button type="button" class="btn btn-outline-secondary filter-btn" data-filter="translator">Translators</button>
                        <button type="button" class="btn btn-outline-secondary filter-btn" data-filter="registry-only">Registry-only</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- People table -->
        <div class="card bg-dark border-secondary p-2 mb-3">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="text-muted small">
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col" class="text-center">Roles</th>
                            <th scope="col" class="text-end">Total uses</th>
                            <th scope="col">Source</th>
                            <th scope="col">Lifespan</th>
                            <th scope="col" class="text-end">Meta</th>
                        </tr>
                    </thead>
                    <tbody id="cp-tbody">
                        <?php foreach ($people as $p):
                            $writers     = $p['writers'];
                            $composers   = $p['composers'];
                            $arrangers   = $p['arrangers'];
                            $adaptors    = $p['adaptors'];
                            $translators = $p['translators'];
                            $rolesCsv    = implode(',', array_filter([
                                $writers     ? 'writer'     : '',
                                $composers   ? 'composer'   : '',
                                $arrangers   ? 'arranger'   : '',
                                $adaptors    ? 'adaptor'    : '',
                                $translators ? 'translator' : '',
                            ]));
                            $isRegistryOnly = $p['total'] === 0;
                            $haystack = strtolower(implode(' ', array_filter([
                                $p['name'],
                                $lifespan($p['birth_date'], $p['death_date']),
                                (string)$p['birth_place'],
                                (string)$p['death_place'],
                                (string)$p['notes'],
                            ])));
                        ?>
                            <tr data-roles="<?= htmlspecialchars($rolesCsv) ?>"
                                data-registry-only="<?= $isRegistryOnly ? '1' : '0' ?>"
                                data-haystack="<?= htmlspecialchars($haystack) ?>">
                                <td class="person-name">
                                    <?= htmlspecialchars($p['name']) ?>
                                    <?php if ($p['notes']): ?>
                                        <span class="text-secondary small ms-2" title="<?= htmlspecialchars($p['notes']) ?>">
                                            <i class="bi bi-sticky"></i>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge role-pill role-w"  <?= $writers     ? '' : 'data-zero' ?>>W&middot;<?= $writers ?></span>
                                    <span class="badge role-pill role-c"  <?= $composers   ? '' : 'data-zero' ?>>C&middot;<?= $composers ?></span>
                                    <span class="badge role-pill role-ar" <?= $arrangers   ? '' : 'data-zero' ?>>Ar&middot;<?= $arrangers ?></span>
                                    <span class="badge role-pill role-ad" <?= $adaptors    ? '' : 'data-zero' ?>>Ad&middot;<?= $adaptors ?></span>
                                    <span class="badge role-pill role-t"  <?= $translators ? '' : 'data-zero' ?>>T&middot;<?= $translators ?></span>
                                </td>
                                <td class="text-end"><strong><?= number_format($p['total']) ?></strong></td>
                                <td><?= $sourceBadge($p) ?></td>
                                <td class="meta-col">
                                    <?php $life = $lifespan($p['birth_date'], $p['death_date']);
                                          echo $life !== '' ? $life : '<span class="text-secondary">—</span>'; ?>
                                </td>
                                <td class="text-end meta-col">
                                    <?php if ($p['link_count'] > 0): ?>
                                        <span class="badge bg-secondary-subtle text-secondary-emphasis badge-icon-count"
                                              title="<?= (int)$p['link_count'] ?> external link(s)">
                                            <i class="bi bi-link-45deg"></i> <?= (int)$p['link_count'] ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($p['ipi_count'] > 0): ?>
                                        <span class="badge bg-secondary-subtle text-secondary-emphasis badge-icon-count"
                                              title="<?= (int)$p['ipi_count'] ?> IPI number(s)">
                                            IPI <?= (int)$p['ipi_count'] ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($p['link_count'] === 0 && $p['ipi_count'] === 0): ?>
                                        <span class="text-secondary">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="empty-row" id="cp-empty-row" style="display:none;">
                            <td colspan="6">No names match the current search / filter.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>

    <script>
        /* Client-side search + filter. The list is small enough that
           re-applying both predicates over every row on every keystroke
           is comfortably under a frame, no debouncing needed. If the
           list grows past ~5k rows, swap to a server-side endpoint. */
        (function () {
            const input    = document.getElementById('cp-search');
            const clearBtn = document.getElementById('cp-search-clear');
            const tbody    = document.getElementById('cp-tbody');
            const empty    = document.getElementById('cp-empty-row');
            const buttons  = document.querySelectorAll('.filter-btn');
            if (!input || !tbody) return;

            let activeFilter = 'all';

            function apply() {
                const q = input.value.trim().toLowerCase();
                let visibleCount = 0;
                const rows = tbody.querySelectorAll('tr:not(.empty-row)');
                rows.forEach(row => {
                    const haystack    = row.dataset.haystack || '';
                    const roles       = (row.dataset.roles || '').split(',').filter(Boolean);
                    const isRegOnly   = row.dataset.registryOnly === '1';
                    let matchRole = true;
                    if (activeFilter === 'registry-only') {
                        matchRole = isRegOnly;
                    } else if (activeFilter !== 'all') {
                        matchRole = roles.includes(activeFilter);
                    }
                    const matchSearch = q === '' || haystack.includes(q);
                    const show = matchRole && matchSearch;
                    row.classList.toggle('no-match', !show);
                    if (show) visibleCount++;
                });
                empty.style.display = visibleCount === 0 ? '' : 'none';
            }

            input.addEventListener('input', apply);
            clearBtn.addEventListener('click', () => { input.value = ''; input.focus(); apply(); });
            buttons.forEach(b => b.addEventListener('click', () => {
                buttons.forEach(x => x.classList.remove('active'));
                b.classList.add('active');
                activeFilter = b.dataset.filter || 'all';
                apply();
            }));
        })();
    </script>

</body>
</html>
