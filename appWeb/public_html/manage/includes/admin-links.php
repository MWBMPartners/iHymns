<?php

declare(strict_types=1);

/**
 * iHymns — Shared Admin Link Registry (#460)
 *
 * Single source of truth for every /manage/* destination. Both the
 * top-bar hamburger offcanvas (< lg) and the pinned sidebar (>= lg)
 * iterate this registry, so adding a new admin page means editing
 * one array.
 *
 * Entry shape (positional for tightness — array_map-style consumers
 * can unpack with `[$id, $href, $icon, $label, $ent, $group] = $l;`):
 *
 *   0 id           matches $activePage on the page; drives highlight.
 *   1 href         destination URL.
 *   2 icon         bi-* class (Bootstrap Icons).
 *   3 label        menu text.
 *   4 entitlement  entitlement key required to see this link; null =
 *                  visible to every authenticated admin surface user.
 *   5 group        sidebar section heading; '' = top-level (shown
 *                  above the first group). Groups are rendered in the
 *                  order they first appear in the array.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* Sidebar group layout (#819). The Content group had grown to 12
   items and dwarfed every other section — split into three more
   meaningful groups (Songs / Catalogue / Access) and consolidated
   Entitlements alongside the other gating concerns under Access.
   The new groups render as collapsible accordion sections in
   admin-sidebar.php and the mobile offcanvas. */
$_adminLinks = [
    /* id                    href                              icon                  label                    entitlement                    group         */
    ['dashboard',            '/manage/',                       'bi-speedometer2',    'Dashboard',             null,                          ''           ],

    /* Songs — per-row content surfaces (#819) */
    ['editor',               '/manage/editor/',                'bi-pencil-square',   'Song Editor',           'edit_songs',                  'Songs'      ],
    ['requests',             '/manage/requests',               'bi-lightbulb',       'Song Requests',         'review_song_requests',        'Songs'      ],
    ['revisions',            '/manage/revisions',              'bi-clock-history',   'Revisions Audit',       'verify_songs',                'Songs'      ],
    ['missing-numbers',      '/manage/missing-numbers',        'bi-binoculars',      'Missing Numbers',       'edit_songs',                  'Songs'      ],
    /* Duplicate Songs absorbed the old Song Link Suggestions page (#1215): one
       unified Duplicate & Counterpart Review surface. Curator-visible
       (edit_songs) for Link/Dismiss; the destructive Merge is gated per-action
       in-page (manage_duplicate_songs). */
    ['duplicate-songs',      '/manage/duplicate-songs',        'bi-git-compare',     'Duplicates & Links',    'edit_songs',                  'Songs'      ],
    /* Deleted songs (#1694) — the soft-delete queue: restore or (admin-only,
       per-action purge_songs gate in-page) permanently purge. Nav entitlement
       matches the page's own gate — test-admin-gate-parity.php derives this
       pairing and fails the build if they drift (#1587 class). */
    ['deleted-songs',        '/manage/deleted-songs',          'bi-trash3',          'Deleted Songs',         'delete_songs',                'Songs'      ],

    /* Catalogue — collection / metadata surfaces (#819) */
    ['songbooks',            '/manage/songbooks',              'bi-book',            'Songbooks',             'manage_songbooks',            'Catalogue'  ],
    ['songbook-series',      '/manage/songbook-series',        'bi-collection',      'Songbook Series',       'manage_songbooks',            'Catalogue'  ],
    /* User-facing label is "Collections" (#1223); the route + table + entitlement
       stay 'catalogue(s)' internally (owner decision — keep tblCatalogues). */
    ['catalogues',           '/manage/catalogues',             'bi-collection-fill', 'Collections',           'manage_songbooks',            'Catalogue'  ],
    ['works',                '/manage/works',                  'bi-diagram-3',       'Works',                 'manage_works',                'Catalogue'  ],
    /* Tunes (#1748) — tblTunes registry CRUD; directly under Works, the
       page it shares the tuneFindOrCreateByName() funnel with. */
    ['tunes',                '/manage/tunes',                  'bi-music-note-beamed', 'Tunes',               'manage_tunes',                'Catalogue'  ],
    ['external-link-types',  '/manage/external-link-types',    'bi-link-45deg',      'External-Link Types',   'manage_external_link_types',  'Catalogue'  ],
    /* Print templates (#1350 Phase 2) — curator-authored block-based song-print
       layouts (tblPrintTemplates). Curator-level, same entitlement as the other
       Catalogue metadata surfaces. */
    ['print-templates',      '/manage/print-templates',        'bi-printer',         'Print templates',       'manage_songbooks',            'Catalogue'  ],
    ['musicians',        '/manage/musicians',          'bi-person-badge',    'Musicians',             'manage_musicians',        'Catalogue'  ],
    ['languages',            '/manage/languages',              'bi-translate',       'Languages',             'manage_languages',            'Catalogue'  ],
    ['tags',                 '/manage/tags',                   'bi-tags',            'Tags & Themes',         'manage_tags',                 'Catalogue'  ],

    /* Access — gating + permission surfaces (#819) */
    ['restrictions',         '/manage/restrictions',           'bi-shield-lock',     'Content Restrictions',  'manage_content_restrictions', 'Access'     ],
    ['tiers',                '/manage/tiers',                  'bi-stars',           'Access Tiers',          'manage_access_tiers',         'Access'     ],
    /* Licence Types (#459 / #1769 P4) — the licence vocabulary (CCLI, MRL, …),
       what each covers + any tier it confers (tblLicenceTypes). */
    ['licence-types',        '/manage/licence-types',          'bi-patch-check',     'Licence Types',         'manage_licence_types',        'Access'     ],
    /* Feature Gating (#1481 P1) — defines ADDITIONAL admin-configurable
       capabilities (tblGatingCapabilities), which then auto-grow a column
       on the Access Tiers matrix above. Deliberately Global-Admin-only
       (manage_feature_gating), narrower than manage_access_tiers. */
    ['feature-gating',       '/manage/feature-gating',         'bi-toggles',         'Feature Gating',       'manage_feature_gating',       'Access'     ],
    ['entitlements',         '/manage/entitlements',           'bi-key',             'Entitlements',          'manage_entitlements',         'Access'     ],

    /* People */
    ['users',                '/manage/users',                  'bi-people',          'Users',                 'view_users',                  'People'     ],
    ['groups',               '/manage/groups',                 'bi-people-fill',     'User Groups',           'manage_user_groups',          'People'     ],
    ['organisations',        '/manage/organisations',          'bi-building',        'Organisations',         'manage_organisations',        'People'     ],
    /* Venues & service times (#1325) — the where/when of an org; foundation for
       Service Mode (#1323). Same entitlement as Organisations. */
    ['venues',               '/manage/venues',                 'bi-geo-alt',         'Venues',                'manage_organisations',        'People'     ],
    /* Service projection (#1335) — the projector page that runs a live service +
       shows the rotating join code. Page self-gates to org-admins; nav visible to
       manage_organisations like Venues. */
    ['service-projection',   '/manage/service-projection',     'bi-projector',       'Service Projection',    'manage_organisations',        'People'     ],
    /* Lead a service (#1335) — the second broadcaster front-end: a handheld the
       worship leader uses to drive the songs of a running service (the projection
       laptop shows the code; this drives the songs). Same self-gate + entitlement. */
    ['service-lead',         '/manage/service-lead',           'bi-music-note-list', 'Lead a Service',        'manage_organisations',        'People'     ],
    /* My Organisations (#707) — the entitlement is open to every signed-in
       role; admin-nav.php applies a data-driven hide via
       userHasOwnOrganisation() so non-admins only see this link when they
       hold an admin/owner row in tblOrganisationMembers. */
    ['my-organisations',     '/manage/my-organisations',       'bi-buildings',       'My Organisations',      'manage_own_organisation',     'People'     ],

    /* Operations — reports, maintenance, infrastructure */
    ['analytics',            '/manage/analytics',              'bi-graph-up',        'Analytics',             'view_analytics',              'Operations' ],
    ['ccli-report',          '/manage/ccli-report',            'bi-receipt',         'CCLI Usage Report',     'view_ccli_report',            'Operations' ],
    ['data-health',          '/manage/data-health',            'bi-activity',        'Data Health',           'drop_legacy_tables',          'Operations' ],
    ['activity-log',         '/manage/activity-log',           'bi-journal-text',    'Activity Log',          'view_activity_log',           'Operations' ],
    ['schema-audit',         '/manage/schema-audit',           'bi-clipboard2-data', 'Schema Audit',          'drop_legacy_tables',          'Operations' ],
    ['diagnostics',          '/manage/diagnostics',            'bi-terminal',        'SQL Diagnostics',       'view_diagnostics',            'Operations' ],
    ['setup-database',       '/manage/setup-database',         'bi-database-gear',   'Database Setup',        'run_db_install',              'Operations' ],
    ['configuration',        '/manage/configuration',          'bi-sliders',         'Configuration',         'manage_configuration',        'Operations' ],
    /* #1725/#1732 — status/snapshot viewer for the (dormant-by-default)
       IntAppsAPI gateway integration. Gated on the SAME entitlement as
       the credentials card above (manage_configuration) — rule: a page's
       own gate and its nav entry must agree (#1587). */
    ['intapps-status',       '/manage/intapps-status',          'bi-broadcast-pin',   'IntApps Gateway',       'manage_configuration',        'Operations' ],
    ['notifications',        '/manage/notifications',          'bi-bell',            'Notifications',         'manage_notifications',        'Operations' ],
    ['api-keys',             '/manage/api-keys',               'bi-key',             'API Keys',              'request_api_keys',            'Operations' ],

    ['help',                 '/manage/help',                   'bi-life-preserver',  'Help / Guides',         null,                          'Help'       ],
    ['api-docs',             '/manage/api-docs',               'bi-file-earmark-code', 'API Docs (Swagger UI)', 'view_api_docs',             'Help'       ],
];

/**
 * Entitlement-gated view of the link registry for a given role.
 *
 * `userHasEntitlement()` lives in /includes/entitlements.php and is
 * already required by admin pages via the auth bootstrap; the caller
 * doesn't need to pull it in separately.
 *
 * @param string|null $role The user's role (e.g. 'global_admin').
 * @return array            Links the role is entitled to see.
 */
function visibleAdminLinks(?string $role): array
{
    global $_adminLinks;
    return array_values(array_filter(
        $_adminLinks,
        static fn(array $l): bool => $l[4] === null || userHasEntitlement($l[4], $role)
    ));
}
