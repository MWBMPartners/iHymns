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
 *   6 orVisibleIf  OPTIONAL data-driven "…OR" sentinel (#1667). Some
 *                  pages admit a user the flat entitlement column can't
 *                  name — an org admin, whose status is per-organisation
 *                  DATA, not a role. `'org_admin'` means "also visible
 *                  when userIsOrgAdminOf() is non-empty", evaluated by
 *                  visibleAdminLinks($role, $userId). Absent on every row
 *                  that gates on its entitlement alone. The nav-gate
 *                  parity guard verifies each sentinel page really does
 *                  check userIsOrgAdminOf (rule #1587 / #1667).
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* Sidebar group layout (#819; plain-English relabel + reorg #1822).
   Groups render (in first-appearance order) as collapsible accordion
   sections in admin-sidebar.php and the mobile offcanvas:
     Songs · Song Library · Live Services · People ·
     Access & Permissions · System & Reports · Help
   The #1822 pass moved the live-service surfaces (Venues / Projector
   Screen / Lead a Service) OUT of People into their own Live Services
   group, and rewrote every developer-jargon label into plain English
   for non-technical admins (owner directive 2026-08-11). Labels and
   group headings are DISPLAY COPY ONLY — ids, hrefs and entitlements
   are unchanged, so the #1587 nav-gate parity guard and every deep
   link still hold. */
$_adminLinks = [
    /* id                    href                            icon                    label                       entitlement                    group                  */
    ['dashboard',            '/manage/',                     'bi-speedometer2',      'Dashboard',                null,                          ''                    ],

    /* Songs — everything about individual songs */
    ['editor',               '/manage/editor/',              'bi-pencil-square',     'Song Editor',              'edit_songs',                  'Songs'               ],
    ['requests',             '/manage/requests',             'bi-lightbulb',         'Song Requests',            'review_song_requests',        'Songs'               ],
    ['revisions',            '/manage/revisions',            'bi-clock-history',     'Edit History',             'verify_songs',                'Songs'               ],
    ['missing-numbers',      '/manage/missing-numbers',      'bi-binoculars',        'Missing Numbers',          'edit_songs',                  'Songs'               ],
    /* Find Duplicates absorbed the old Song Link Suggestions page (#1215): one
       unified duplicate + counterpart review surface. Curator-visible
       (edit_songs) for Link/Dismiss; the destructive Merge is gated per-action
       in-page (manage_duplicate_songs). Icon fixed #1822 — bi-git-compare is a
       GitHub Octicon, not a Bootstrap Icon, so it rendered as a blank box. */
    ['duplicate-songs',      '/manage/duplicate-songs',      'bi-files',             'Find Duplicates',          'edit_songs',                  'Songs'               ],
    /* Voice-part suggestions (#2073 commit 15) — the review queue for the
       "WOMEN" / "MEN: You are holy," / "(echo)" style markers the #2073
       commit-14 backfill batch finds sitting in old lyric TEXT. A curator
       says yes (Accept — really assigns the voice part and tidies the
       marker) or no (Dismiss) per row; Undo reverses an Accept exactly.
       Curator-visible (edit_songs) — same gate the page itself checks, so
       test-admin-gate-parity.php's derived pairing holds (#1587 class). */
    ['vocal-parts-review',   '/manage/vocal-parts-review',   'bi-people',            'Voice-part suggestions',   'edit_songs',                  'Songs'               ],
    /* Deleted Songs (#1694) — the soft-delete queue: restore or (admin-only,
       per-action purge_songs gate in-page) permanently remove. Nav entitlement
       matches the page's own gate — test-admin-gate-parity.php derives this
       pairing and fails the build if they drift (#1587 class). */
    ['deleted-songs',        '/manage/deleted-songs',        'bi-trash3',            'Deleted Songs',            'delete_songs',                'Songs'               ],

    /* Song Library — songbooks and the reference lists behind songs */
    ['songbooks',            '/manage/songbooks',            'bi-book',              'Songbooks',                'manage_songbooks',            'Song Library'        ],
    ['songbook-series',      '/manage/songbook-series',      'bi-collection',        'Songbook Series',          'manage_songbooks',            'Song Library'        ],
    /* User-facing label is "Collections" (#1223); the route + table + entitlement
       stay 'catalogue(s)' internally (owner decision — keep tblCatalogues). */
    ['catalogues',           '/manage/catalogues',           'bi-collection-fill',   'Collections',              'manage_songbooks',            'Song Library'        ],
    ['works',                '/manage/works',                'bi-diagram-3',         'Works',                    'manage_works',                'Song Library'        ],
    /* Tunes (#1748) — tblTunes registry CRUD; shares the tuneFindOrCreateByName()
       funnel with Works. */
    ['tunes',                '/manage/tunes',                'bi-music-note-beamed', 'Tunes',                    'manage_tunes',                'Song Library'        ],
    /* Publishers (#93) — tblPublishers registry CRUD. Gate MUST equal
       manage/publishers.php's own gate (rule #1587). */
    ['publishers',           '/manage/publishers',           'bi-building',          'Publishers',               'manage_publishers',           'Song Library'        ],
    ['musicians',            '/manage/musicians',            'bi-person-badge',      'Musicians',                'manage_musicians',            'Song Library'        ],
    ['languages',            '/manage/languages',            'bi-translate',         'Languages',                'manage_languages',            'Song Library'        ],
    ['tags',                 '/manage/tags',                 'bi-tags',              'Tags & Themes',            'manage_tags',                 'Song Library'        ],
    ['external-link-types',  '/manage/external-link-types',  'bi-link-45deg',        'Link Types',               'manage_external_link_types',  'Song Library'        ],
    /* Print Templates (#1350 Phase 2) — curator-authored block-based song-print
       layouts (tblPrintTemplates). Curator-level, same entitlement as the other
       Song Library metadata surfaces. */
    ['print-templates',      '/manage/print-templates',      'bi-printer',           'Print Templates',          'manage_songbooks',            'Song Library'        ],
    /* Scan Import (#94 Phase 1) — read-only archive.org OCR audit, a per-songbook
       tool. Gate MUST equal manage/ia-reconcile.php's own check (rule #1587;
       test-admin-gate-parity.php derives this pairing from the tree). */
    ['ia-reconcile',         '/manage/ia-reconcile',         'bi-archive',           'Scan Import',              'edit_songs',                  'Song Library'        ],

    /* Live Services — running a live / projected worship service (#1822 — moved
       out of People; these are the where/when/how of a service, not people). */
    /* Venues & service times (#1325) — the where/when of an org; foundation for
       Service Mode (#1323). Same entitlement as Organisations. */
    ['venues',               '/manage/venues',               'bi-geo-alt',           'Venues',                   'manage_organisations',        'Live Services'       ],
    /* Projector Screen (#1335) — the projector page that runs a live service +
       shows the rotating join code. Page self-gates to manage_organisations OR
       an org admin (userIsOrgAdminOf), so the row carries the 'org_admin' sentinel
       (#1667) — otherwise an org admin who runs services could never find it. */
    ['service-projection',   '/manage/service-projection',   'bi-projector',         'Projector Screen',         'manage_organisations',        'Live Services',        'org_admin'],
    /* Lead a Service (#1335) — the second broadcaster front-end: a handheld the
       worship leader uses to drive the songs of a running service (the projector
       shows the code; this drives the songs). Same self-gate + 'org_admin'
       sentinel as the projector page (#1667). */
    ['service-lead',         '/manage/service-lead',         'bi-music-note-list',   'Lead a Service',           'manage_organisations',        'Live Services',        'org_admin'],

    /* People — user accounts and organisations */
    ['users',                '/manage/users',                'bi-people',            'Users',                    'view_users',                  'People'              ],
    ['groups',               '/manage/groups',               'bi-people-fill',       'User Groups',              'manage_user_groups',          'People'              ],
    ['organisations',        '/manage/organisations',        'bi-building',          'Organisations',            'manage_organisations',        'People'              ],
    /* My Organisations (#707) — the entitlement is open to every signed-in
       role; admin-nav.php applies a data-driven hide via
       userHasOwnOrganisation() so non-admins only see this link when they
       hold an admin/owner row in tblOrganisationMembers. */
    ['my-organisations',     '/manage/my-organisations',     'bi-buildings',         'My Organisations',         'manage_own_organisation',     'People'              ],
    /* My CCLI Report (#1861) — org-facing sibling of ccli-report (which stays
       system-wide under view_ccli_report). Entitlement open to every signed-in
       role; admin-nav.php applies the same data-driven userHasOwnOrganisation()
       hide as my-organisations above. */
    ['my-ccli-report',       '/manage/my-ccli-report',       'bi-receipt-cutoff',    'My CCLI Report',           'view_org_ccli_report',        'People'              ],

    /* Access & Permissions — who can see and do what */
    /* Content Access (#1769 P6 / #1778) — the family overview + activation-
       readiness checklist + master-switch STATE (the flip itself stays on
       Settings — one write path, no duplicate control). manage_configuration. */
    ['gating',               '/manage/gating',               'bi-shield-shaded',     'Content Access',           'manage_configuration',        'Access & Permissions'],
    ['restrictions',         '/manage/restrictions',         'bi-shield-lock',       'Content Restrictions',     'manage_content_restrictions', 'Access & Permissions'],
    ['tiers',                '/manage/tiers',                'bi-stars',             'Membership Tiers',         'manage_access_tiers',         'Access & Permissions'],
    /* Licence Types (#459 / #1769 P4) — the licence vocabulary (CCLI, MRL, …),
       what each covers + any tier it confers (tblLicenceTypes). */
    ['licence-types',        '/manage/licence-types',        'bi-patch-check',       'Licence Types',            'manage_licence_types',        'Access & Permissions'],
    /* Feature Access (#1481 P1) — defines ADDITIONAL admin-configurable
       capabilities (tblGatingCapabilities), which then auto-grow a column on the
       Membership Tiers matrix above. Deliberately Global-Admin-only
       (manage_feature_gating), narrower than manage_access_tiers. */
    ['feature-gating',       '/manage/feature-gating',       'bi-toggles',           'Feature Access',           'manage_feature_gating',       'Access & Permissions'],
    ['entitlements',         '/manage/entitlements',         'bi-key',               'Role Permissions',         'manage_entitlements',         'Access & Permissions'],

    /* System & Reports — reports, maintenance, infrastructure */
    ['analytics',            '/manage/analytics',            'bi-graph-up',          'Analytics',                'view_analytics',              'System & Reports'    ],
    ['ccli-report',          '/manage/ccli-report',          'bi-receipt',           'CCLI Usage Report',        'view_ccli_report',            'System & Reports'    ],
    ['data-health',          '/manage/data-health',          'bi-activity',          'Data Health',              'drop_legacy_tables',          'System & Reports'    ],
    ['activity-log',         '/manage/activity-log',         'bi-journal-text',      'Activity Log',             'view_activity_log',           'System & Reports'    ],
    ['schema-audit',         '/manage/schema-audit',         'bi-clipboard2-data',   'Database Structure Check', 'drop_legacy_tables',          'System & Reports'    ],
    ['diagnostics',          '/manage/diagnostics',          'bi-terminal',          'Database Query Tool',      'view_diagnostics',            'System & Reports'    ],
    ['setup-database',       '/manage/setup-database',       'bi-database-gear',     'Database Setup',           'run_db_install',              'System & Reports'    ],
    ['configuration',        '/manage/configuration',        'bi-sliders',           'Settings',                 'manage_configuration',        'System & Reports'    ],
    /* #1725/#1732 — status/snapshot viewer for the (dormant-by-default)
       IntAppsAPI gateway integration. Gated on the SAME entitlement as the
       credentials card (manage_configuration) — a page's own gate and its nav
       entry must agree (#1587). */
    ['intapps-status',       '/manage/intapps-status',       'bi-broadcast-pin',     'Connected Apps',           'manage_configuration',        'System & Reports'    ],
    /* Content Lock Safety Check (#1769) — the OFF-baseline / equivalence harness
       for content-gating: proves turning content locking on would change nothing
       yet. Gated on manage_configuration, the SAME check the page itself uses
       (#1587 — page gate and nav entry must agree). */
    ['gating-noop-verify',   '/manage/gating-noop-verify',   'bi-shield-check',      'Content Lock Safety Check','manage_configuration',        'System & Reports'    ],
    ['notifications',        '/manage/notifications',        'bi-bell',              'Notifications',            'manage_notifications',        'System & Reports'    ],
    ['api-keys',             '/manage/api-keys',             'bi-key',               'API Keys',                 'request_api_keys',            'System & Reports'    ],
    ['webhooks',             '/manage/webhooks',             'bi-broadcast',         'Webhooks',                 'manage_webhooks',             'System & Reports'    ],

    /* Help */
    ['help',                 '/manage/help',                 'bi-life-preserver',    'Help / Guides',            null,                          'Help'                ],
    ['api-docs',             '/manage/api-docs',             'bi-file-earmark-code', 'API Docs',                 'view_api_docs',               'Help'                ],
];

/**
 * Entitlement-gated view of the link registry for a given role.
 *
 * `userHasEntitlement()` lives in /includes/entitlements.php and is
 * already required by admin pages via the auth bootstrap; the caller
 * doesn't need to pull it in separately.
 *
 * @param string|null $role   The user's role (e.g. 'global_admin').
 * @param int|null    $userId  The user's id, for the optional data-driven
 *                             `orVisibleIf` sentinel (#1667). Omit (null) to
 *                             evaluate the entitlement column alone — a caller
 *                             that can't resolve a user id degrades to the
 *                             role-only view, never an error.
 * @return array               Links the user is entitled to see.
 */
function visibleAdminLinks(?string $role, ?int $userId = null): array
{
    global $_adminLinks;
    return array_values(array_filter(
        $_adminLinks,
        static function (array $l) use ($role, $userId): bool {
            /* Primary gate: the entitlement column (null = every admin user). */
            if ($l[4] === null || userHasEntitlement($l[4], $role)) {
                return true;
            }
            /* Optional data-driven "…OR" (#1667). Index 6 is a sentinel naming a
               membership check the flat entitlement column can't express. The
               ONLY sentinel today is 'org_admin' — the page admits an org admin
               (userIsOrgAdminOf non-empty) as well as its entitlement holder, so
               the row must be visible to them too or the feature is undiscoverable
               to exactly the people it was built for. userIsOrgAdminOf() is the
               SAME lookup those pages gate on (rule #22); function_exists-guarded
               so a nav render before entitlements.php loads degrades to the
               entitlement-only view rather than throwing. */
            if (($l[6] ?? null) === 'org_admin'
                && $userId !== null
                && function_exists('userIsOrgAdminOf')
                && !empty(userIsOrgAdminOf($userId))) {
                return true;
            }
            return false;
        }
    ));
}
