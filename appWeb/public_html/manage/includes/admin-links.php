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
    ['song-link-suggestions','/manage/song-link-suggestions',  'bi-link-45deg',      'Song Link Suggestions', 'edit_songs',                  'Songs'      ],

    /* Catalogue — collection / metadata surfaces (#819) */
    ['songbooks',            '/manage/songbooks',              'bi-book',            'Songbooks',             'manage_songbooks',            'Catalogue'  ],
    ['songbook-series',      '/manage/songbook-series',        'bi-collection',      'Songbook Series',       'manage_songbooks',            'Catalogue'  ],
    ['works',                '/manage/works',                  'bi-diagram-3',       'Works',                 'manage_works',                'Catalogue'  ],
    ['external-link-types',  '/manage/external-link-types',    'bi-link-45deg',      'External-Link Types',   'manage_external_link_types',  'Catalogue'  ],
    ['credit-people',        '/manage/credit-people',          'bi-person-badge',    'Credit People',         'manage_credit_people',        'Catalogue'  ],
    ['languages',            '/manage/languages',              'bi-translate',       'Languages',             'manage_languages',            'Catalogue'  ],
    ['tags',                 '/manage/tags',                   'bi-tags',            'Tags & Themes',         'manage_tags',                 'Catalogue'  ],

    /* Access — gating + permission surfaces (#819) */
    ['restrictions',         '/manage/restrictions',           'bi-shield-lock',     'Content Restrictions',  'manage_content_restrictions', 'Access'     ],
    ['tiers',                '/manage/tiers',                  'bi-stars',           'Access Tiers',          'manage_access_tiers',         'Access'     ],
    ['entitlements',         '/manage/entitlements',           'bi-key',             'Entitlements',          'manage_entitlements',         'Access'     ],

    /* People */
    ['users',                '/manage/users',                  'bi-people',          'Users',                 'view_users',                  'People'     ],
    ['groups',               '/manage/groups',                 'bi-people-fill',     'User Groups',           'manage_user_groups',          'People'     ],
    ['organisations',        '/manage/organisations',          'bi-building',        'Organisations',         'manage_organisations',        'People'     ],
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
    ['setup-database',       '/manage/setup-database',         'bi-database-gear',   'Database Setup',        'run_db_install',              'Operations' ],
    ['configuration',        '/manage/configuration',          'bi-sliders',         'Configuration',         'manage_configuration',        'Operations' ],
    ['notifications',        '/manage/notifications',          'bi-bell',            'Notifications',         'manage_notifications',        'Operations' ],

    ['help',                 '/manage/help',                   'bi-life-preserver',  'Help / Guides',         null,                          'Help'       ],
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
