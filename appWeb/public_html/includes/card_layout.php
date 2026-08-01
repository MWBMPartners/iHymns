<?php

declare(strict_types=1);

/**
 * iHymns — Card Layout Helper (#448)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Single place to compute the effective card order / hidden set for a
 * given viewer on a given surface ("dashboard" or "home"). The resolver
 * layers:
 *   1. baseline  — every known card ID on this surface, in the
 *                  template-author's preferred default order
 *   2. system    — admin override in tblAppSettings
 *   3. user      — per-user override in tblUsers.Settings JSON
 *
 * Missing IDs are appended (so shipping a new card never breaks anyone
 * who saved their layout yesterday); removed IDs drop (so a card pulled
 * from the codebase doesn't leave a ghost entry in saved layouts).
 *
 * ---------------------------------------------------------------------
 * THE ASYMMETRY THAT SHAPES THIS FILE — TWO HYDRATION MODES (rule #6)
 * ---------------------------------------------------------------------
 * The same feature is delivered two completely different ways, and which
 * way depends entirely on whether the surface's HTML is per-viewer or
 * shared. Getting this backwards is the mistake the rule exists to stop.
 *
 *   dashboard (/manage/)  — SERVER-SIDE. `manage/index.php` is rendered
 *       fresh for one authenticated admin per request, so it may safely
 *       call `cardLayoutResolve()` and emit the cards already in the
 *       viewer's order, already `d-none`'d. No flash of default order.
 *
 *   home (/)              — CLIENT-SIDE. `includes/pages/home.php` is
 *       served through `/api?page=home`, which is in api.php's
 *       `$_cacheablePages` list: one ETag'd body handed to every
 *       visitor, signed-in or not, and to the service worker. A shared
 *       response CANNOT carry a per-user order — personalising it would
 *       leak one user's layout to the next requester (or force a
 *       per-user ETag, killing the cache). So the server emits the
 *       canonical default and `applyCardLayout()` in
 *       `js/modules/card-layout.js` re-orders/hides the already-rendered
 *       nodes after the fragment lands, reading the viewer's layout from
 *       `/api?action=card_layout_get` over the same-origin `ihymns_auth`
 *       cookie.
 *
 * Consequence worth stating out loud: on the `home` surface NOTHING in
 * this file runs during page render. `cardLayoutResolve()` has exactly
 * one caller (`manage/index.php`); the other functions here are reached
 * from home only indirectly, via the `card_layout_*` endpoints in
 * api.php. `cardLayoutMerge()` therefore exists TWICE — here and as
 * `mergeLayout()` in card-layout.js — with nothing mechanically holding
 * the two in step (rule #35). Change one, change the other, or the same
 * saved layout resolves differently on the two surfaces.
 *
 * ---------------------------------------------------------------------
 * WHERE THE STATE LIVES
 * ---------------------------------------------------------------------
 *   system default → tblAppSettings, key `dashboard_card_order_default`
 *                    / `home_card_order_default`, value = a JSON
 *                    `{order:[], hidden:[]}`. Neither key is seeded by
 *                    schema.sql or any migration — "no row" is the
 *                    normal, healthy state and simply means "use the
 *                    template order". A row only appears when an admin
 *                    presses "Save as site default".
 *   user override  → tblUsers.Settings (a JSON column), under the
 *                    top-level key `cardLayouts.<surface>`.
 *
 * ---------------------------------------------------------------------
 * TWO CONTRACTS CALLERS MUST HONOUR
 * ---------------------------------------------------------------------
 * 1. ENTITLEMENTS ARE NOT CHECKED BY THE WRITERS. `cardLayoutSaveDefault()`
 *    and `cardLayoutSaveUserOverride()` write whatever they are handed;
 *    the `manage_default_card_layout` / `customise_own_card_layout` gates
 *    live at the call sites (the `card_layout_save_*` cases in api.php).
 *    Only `cardLayoutUserCanCustomise()` and `cardLayoutResolve()` consult
 *    entitlements at all.
 * 2. THIS FILE REQUIRES `db_mysql.php` BUT *NOT* `entitlements.php`, even
 *    though `cardLayoutUserCanCustomise()` calls `userHasEntitlement()`.
 *    It relies on the caller having loaded it already — api.php does at
 *    its top, and `manage/includes/auth.php` does for every admin page.
 *    A new caller that includes only this file will fatal on an undefined
 *    function.
 *
 * ---------------------------------------------------------------------
 * FAIL-SOFT, EVERYWHERE, ON PURPOSE
 * ---------------------------------------------------------------------
 * Every function here wraps its DB work in `try { … } catch (\Throwable)`.
 * That is not defensive noise: mysqli runs under
 * MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT (`includes/db_mysql.php`), so
 * a missing table/column THROWS rather than returning false — and
 * migrations on this project are web-run, not applied on deploy, so an
 * install can legitimately be running this code before
 * `tblUserGroups.AllowCardReorder` exists. Cosmetic card ordering must
 * never be able to white-screen the admin dashboard or the public home
 * (the `/manage/duplicate-songs` blanking, #1228 → #1229, is the worked
 * example of what happens when it can). Readers degrade to "no saved
 * layout" and writers report `false`.
 *
 * @see appWeb/public_html/js/modules/card-layout.js  the client half
 * @see appWeb/public_html/manage/index.php           the dashboard caller
 * @see appWeb/public_html/includes/pages/home.php    the home markup
 * @see appWeb/public_html/api.php                    `card_layout_*` endpoints
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';

/* The complete set of card grids that support reorder/hide.
 *
 * ELI5: the only two screens that have draggable cards, named.
 *
 * Deliberately a public constant rather than a private array: api.php's four
 * `card_layout_*` endpoints validate the client-supplied `surface` against it
 * (`in_array(..., CARD_LAYOUT_SURFACES, true)` → HTTP 400) before it reaches
 * any function here, and every function re-checks it as a belt-and-braces
 * guard. That matters because `$surface` chooses a tblAppSettings key and
 * indexes into the user's settings JSON — an unvalidated value would let a
 * caller write arbitrary keys into someone's preference blob. Adding a third
 * surface means adding its key to the two ternaries below as well. */
const CARD_LAYOUT_SURFACES = ['dashboard', 'home'];

/**
 * Read the system-wide default layout for a surface.
 *
 * ELI5: "what order did an admin decide these cards should be in for
 * everybody?" — returns two empty lists if nobody has ever decided.
 *
 * @return array{order: string[], hidden: string[]}
 */
function cardLayoutDefault(string $surface): array
{
    if (!in_array($surface, CARD_LAYOUT_SURFACES, true)) {
        return ['order' => [], 'hidden' => []];
    }

    /* Two literal keys chosen by a ternary rather than "{$surface}_card_order_
       default" — the interpolated form would be shorter but would put a
       request-shaped value into a settings key name, and this file's whole
       job is to keep untrusted card/surface identifiers out of storage. */
    $key = $surface === 'dashboard'
        ? 'dashboard_card_order_default'
        : 'home_card_order_default';

    try {
        $db = getDbMysqli();
        $stmt = $db->prepare('SELECT SettingValue FROM tblAppSettings WHERE SettingKey = ?');
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        /* fetch_row() returns null when there is no such setting — which is
           the DEFAULT state of a fresh install, since nothing seeds these two
           keys. `?? ''` folds "no row", "NULL value" and "empty string" into
           the one meaning: no admin default, fall through to the template's
           own card order.
           @see https://www.php.net/manual/en/mysqli-result.fetch-row.php */
        $raw = (string)($row[0] ?? '');
        if ($raw === '') return ['order' => [], 'hidden' => []];
        $decoded = json_decode($raw, true);
        /* Anything that isn't a JSON object/array — a scalar, or corruption
           from a hand-edited row — is treated as "no default" rather than
           allowed to reach the `$decoded['order']` index below. */
        if (!is_array($decoded)) return ['order' => [], 'hidden' => []];
        return [
            'order'  => cardLayoutSanitiseIds($decoded['order']  ?? []),
            'hidden' => cardLayoutSanitiseIds($decoded['hidden'] ?? []),
        ];
    } catch (\Throwable $_e) {
        /* DB down / table absent → "no default". See the FAIL-SOFT note in the
           file doc-block: mysqli is in STRICT mode so these throw, and a
           cosmetic ordering preference must never take a page down with it. */
        return ['order' => [], 'hidden' => []];
    }
}

/**
 * Write the system-wide default layout. Caller must have confirmed the
 * `manage_default_card_layout` entitlement first.
 *
 * ELI5: an admin presses "Save as site default" and this is what stores the
 * arrangement everybody else will see from now on.
 *
 * ⚠ The entitlement is NOT checked here — the gate lives in api.php's
 * `card_layout_save_default` case. Any new caller must do the same check
 * itself; this function will happily rewrite the site-wide default for
 * whoever asks.
 */
function cardLayoutSaveDefault(string $surface, array $layout): bool
{
    if (!in_array($surface, CARD_LAYOUT_SURFACES, true)) return false;

    /* Sanitise BEFORE encoding, not on read: the values arrive straight off a
       POST body (api.php passes `$body['order']` through untouched), so this
       is the boundary where a hostile or malformed card ID is dropped. Storing
       clean data means every reader can trust the blob.

       JSON_UNESCAPED_SLASHES keeps the stored value readable when someone
       inspects tblAppSettings by hand — no functional effect, card IDs contain
       no slashes. @see https://www.php.net/manual/en/json.constants.php */
    $payload = json_encode([
        'order'  => cardLayoutSanitiseIds($layout['order']  ?? []),
        'hidden' => cardLayoutSanitiseIds($layout['hidden'] ?? []),
    ], JSON_UNESCAPED_SLASHES);

    $key = $surface === 'dashboard'
        ? 'dashboard_card_order_default'
        : 'home_card_order_default';

    try {
        $db = getDbMysqli();
        /* Upsert rather than SELECT-then-INSERT-or-UPDATE: the key may or may
           not exist (nothing seeds it — see the doc-block), and this collapses
           both cases into one statement with no read-modify-write race between
           two admins pressing the button at the same moment. It relies on
           SettingKey carrying a PRIMARY/UNIQUE index, which schema.sql gives it.
           @see https://dev.mysql.com/doc/refman/8.0/en/insert-on-duplicate.html */
        $stmt = $db->prepare(
            'INSERT INTO tblAppSettings (SettingKey, SettingValue) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)'
        );
        /* bind_param() takes its arguments BY REFERENCE, so the value has to be
           a variable — `(string)` on the json_encode() result inline would not
           be bindable. @see https://www.php.net/manual/en/mysqli-stmt.bind-param.php */
        $value = (string)$payload;
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (\Throwable $_e) {
        return false;
    }
}

/**
 * Read a user's layout override for a surface from tblUsers.Settings.
 *
 * ELI5: "has THIS person rearranged this screen?" — two empty lists if not.
 *
 * Note what this deliberately does NOT do: it does not ask whether the user is
 * still ALLOWED to customise. `cardLayoutResolve()` makes that check before
 * calling here; api.php's `card_layout_get` does not, and returns the stored
 * override to the client regardless.
 *
 * @return array{order: string[], hidden: string[]}
 */
function cardLayoutUserOverride(int $userId, string $surface): array
{
    if ($userId <= 0 || !in_array($surface, CARD_LAYOUT_SURFACES, true)) {
        return ['order' => [], 'hidden' => []];
    }
    try {
        $db = getDbMysqli();
        $stmt = $db->prepare('SELECT Settings FROM tblUsers WHERE Id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        $raw = (string)($row[0] ?? '');
        if ($raw === '') return ['order' => [], 'hidden' => []];
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return ['order' => [], 'hidden' => []];
        /* tblUsers.Settings is a SHARED blob — theme, font size, accessibility
           prefs and (since #1671 F5) other products' namespaces all live in the
           same JSON column. Card layouts occupy exactly one top-level key,
           `cardLayouts`, sub-keyed by surface, so the two surfaces can be saved
           independently and neither trips over a sibling setting.
           @see appWeb/public_html/includes/user_settings.php */
        $section = $decoded['cardLayouts'][$surface] ?? null;
        if (!is_array($section)) return ['order' => [], 'hidden' => []];
        return [
            'order'  => cardLayoutSanitiseIds($section['order']  ?? []),
            'hidden' => cardLayoutSanitiseIds($section['hidden'] ?? []),
        ];
    } catch (\Throwable $_e) {
        return ['order' => [], 'hidden' => []];
    }
}

/**
 * Write a user's layout override to tblUsers.Settings, merging with any
 * existing settings JSON (theme etc.) rather than replacing it.
 *
 * ELI5: save just this person's card arrangement, without disturbing the rest
 * of their preferences that live in the same JSON blob.
 *
 * The read-modify-write below is NOT transactional, and that is a deliberate
 * match for how `user_settings` treats the same column: the blob is per-user,
 * so the only possible race is one person's own two devices saving at once, and
 * last-write-wins is the accepted outcome there too.
 *
 * ⚠ The merge protects siblings only in THIS direction. The legacy
 * un-namespaced `?action=user_settings` write path (used today by
 * `js/modules/settings.js`) replaces the whole blob, so a save from the
 * Settings screen drops `cardLayouts` entirely — the corollary spelled out in
 * `includes/user_settings.php`'s doc-block, not something this function can
 * defend against.
 *
 * ⚠ Entitlement gate lives at the call site (api.php `card_layout_save_user`
 * calls `cardLayoutUserCanCustomise()` first), not here.
 */
function cardLayoutSaveUserOverride(int $userId, string $surface, array $layout): bool
{
    if ($userId <= 0 || !in_array($surface, CARD_LAYOUT_SURFACES, true)) return false;

    try {
        $db = getDbMysqli();
        $stmt = $db->prepare('SELECT Settings FROM tblUsers WHERE Id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        $raw = (string)($row[0] ?? '');
        $settings = $raw === '' ? [] : (json_decode($raw, true) ?: []);
        /* Rebuild the container if it's missing OR present-but-not-an-array —
           the second half matters because a hand-edited or foreign-written blob
           could leave `cardLayouts` as a string, and indexing into that would
           throw (caught below, but the user's save would silently fail forever
           instead of self-healing). */
        if (!isset($settings['cardLayouts']) || !is_array($settings['cardLayouts'])) {
            $settings['cardLayouts'] = [];
        }
        $settings['cardLayouts'][$surface] = [
            'order'  => cardLayoutSanitiseIds($layout['order']  ?? []),
            'hidden' => cardLayoutSanitiseIds($layout['hidden'] ?? []),
        ];
        $json = (string)json_encode($settings, JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare('UPDATE tblUsers SET Settings = ?, UpdatedAt = CURRENT_TIMESTAMP WHERE Id = ?');
        $stmt->bind_param('si', $json, $userId);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (\Throwable $_e) {
        return false;
    }
}

/**
 * Clear a user's override for a surface, reverting them to the system default.
 *
 * ELI5: the "Reset to default" button — forget what this person rearranged and
 * put them back on whatever everyone else sees.
 *
 * Idempotent, and reports success when there was nothing to clear (empty
 * Settings → early `true`): "you are now on the default" is true either way,
 * and the client's only reaction to `false` is an error toast the user could do
 * nothing about.
 *
 * Note this removes ONLY `cardLayouts[$surface]`, so resetting the home does
 * not disturb a saved dashboard arrangement — and, unlike the writers above,
 * it needs no entitlement at the call site: reverting to the default is always
 * permissible, including for a user whose customisation right was just revoked.
 */
function cardLayoutClearUserOverride(int $userId, string $surface): bool
{
    if ($userId <= 0 || !in_array($surface, CARD_LAYOUT_SURFACES, true)) return false;
    try {
        $db = getDbMysqli();
        $stmt = $db->prepare('SELECT Settings FROM tblUsers WHERE Id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        $raw = (string)($row[0] ?? '');
        if ($raw === '') return true;
        $settings = json_decode($raw, true) ?: [];
        if (isset($settings['cardLayouts'][$surface])) {
            unset($settings['cardLayouts'][$surface]);
        }
        $json = (string)json_encode($settings, JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare('UPDATE tblUsers SET Settings = ?, UpdatedAt = CURRENT_TIMESTAMP WHERE Id = ?');
        $stmt->bind_param('si', $json, $userId);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (\Throwable $_e) {
        return false;
    }
}

/**
 * Is the given user allowed to customise their own layout? Requires BOTH
 * the role-level entitlement AND (if they belong to a group) the group's
 * AllowCardReorder flag to be set. Groupless users get the role-level
 * answer alone.
 *
 * ELI5: two independent switches have to be on — "this KIND of account may
 * rearrange cards" and "this particular group of people may rearrange cards".
 * Either one off means no.
 *
 * The two layers answer different questions and deliberately do not collapse
 * into one: `customise_own_card_layout` is granted per ROLE for everyone on the
 * site (`includes/entitlements.php` — user/editor/admin/global_admin), whereas
 * `tblUserGroups.AllowCardReorder` lets an organisation pin a fixed layout for
 * its own members without demoting their role. AND, not OR, so the group's veto
 * genuinely bites.
 *
 * ⚠ Depends on `userHasEntitlement()` from `includes/entitlements.php`, which
 * this file does not require — see the doc-block's caller contract.
 *
 * @param array|null $user Loosely-shaped viewer: `role` plus `group_id` (the
 *                         dashboard's snake_case) or `GroupId` (the raw
 *                         tblUsers column name, as api.php passes it).
 */
function cardLayoutUserCanCustomise(?array $user): bool
{
    if (!$user) return false;
    $role = $user['role'] ?? null;
    if (!userHasEntitlement('customise_own_card_layout', $role)) return false;

    /* No group (0 / NULL / absent) → nothing can veto, so the role answer
       stands and we skip the query entirely. This is the common case for
       ordinary public accounts, so it also keeps the home page's
       `card_layout_get` down to the two reads it actually needs. */
    $groupId = (int)($user['group_id'] ?? $user['GroupId'] ?? 0);
    if ($groupId <= 0) return true;

    try {
        $db = getDbMysqli();
        $stmt = $db->prepare('SELECT AllowCardReorder FROM tblUserGroups WHERE Id = ?');
        $stmt->bind_param('i', $groupId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        /* UNKNOWN MEANS ALLOWED — the veto has to be set deliberately.
           ELI5: if we can't find out whether this group forbids reordering,
           we assume it doesn't, rather than silently taking the feature away.

           Three "don't know" shapes, all folded to true:
             - `$row === null`  — fetch_row() found no such group, e.g. the
               user's GroupId points at a deleted row.
             - `$row[0] === null` — a NULL flag. Defensive rather than
               reachable today: schema.sql and
               `.sql/migrate-user-features-catchup.php` both declare
               AllowCardReorder as `TINYINT(1) NOT NULL DEFAULT 1`, so existing
               groups were backfilled to 1 rather than NULL when #448 landed.
             - the catch below — on an install where the migration has not been
               run, the column does not exist and mysqli's STRICT reporting
               throws rather than returning false.

           Fail-OPEN is the right default for this particular flag because the
           thing being protected is card ORDER. Failing closed would mean a
           mid-rollout install quietly disabling personalisation for everyone in
           a group, with no error to explain it — a silent no-op, the failure
           class this codebase keeps paying for. Note also that this only
           controls the SAVE path; nothing sensitive is exposed either way. */
        if ($row === null || $row[0] === null) return true;
        return (int)$row[0] === 1;
    } catch (\Throwable $_e) {
        return true;
    }
}

/**
 * Merge baseline card IDs with a saved order / hidden set.
 *
 * - IDs in the baseline but missing from `order` append at the end
 *   (new cards become visible for existing users).
 * - IDs in `order` but missing from the baseline drop (removed cards
 *   don't stick around as ghosts).
 * - `hidden` IDs that aren't in the baseline drop for the same reason.
 *
 * ELI5: the saved arrangement is a WISH, not a contract. This reconciles it
 * with the cards that actually exist today, so a layout saved months ago still
 * renders sensibly after cards have been added and removed.
 *
 * The baseline is itself viewer-dependent on the dashboard: `manage/index.php`
 * filters the card list by entitlement BEFORE calling here, so a card the
 * viewer may not see is simply absent from the baseline and is dropped from
 * their saved order by the same rule that drops deleted cards. No separate
 * permission logic is needed in the merge.
 *
 * ⚠ MIRRORED IN `mergeLayout()` (js/modules/card-layout.js), which is what runs
 * for the public home. The two must produce identical results for identical
 * inputs, and nothing enforces that (rule #35) — if you change the append/drop
 * semantics here, change them there in the same commit.
 *
 * @param string[] $baseline          The template's canonical list of IDs
 * @param array{order: string[], hidden: string[]} $saved
 * @return array{order: string[], hidden: string[]}
 */
function cardLayoutMerge(array $baseline, array $saved): array
{
    /* array_flip turns the ID list into a hash-keyed set so membership is O(1)
       instead of an in_array() scan per saved entry — the grids are small, but
       the same helper runs on every dashboard render.
       @see https://www.php.net/manual/en/function.array-flip.php */
    $baselineSet = array_flip($baseline);
    $order = [];
    $seen  = [];

    /* Pass 1 — honour the saved order, keeping only IDs that still exist.
       `$seen` also de-dupes: a repeated ID in a saved blob would otherwise
       render the same card twice (the DOM nodes are keyed by ID, so on the
       client the second copy would just steal the first's node). */
    foreach (($saved['order'] ?? []) as $id) {
        if (isset($baselineSet[$id]) && !isset($seen[$id])) {
            $order[] = $id;
            $seen[$id] = true;
        }
    }
    /* Pass 2 — anything the saved order never mentioned goes on the END, in
       template order. This is why shipping a new card is non-breaking: every
       existing user gets it appended and VISIBLE (it isn't in their `hidden`
       list either), rather than the new card being invisible to everyone who
       ever pressed "Done" — which is what a strict "saved order is the whole
       truth" reading would give you. */
    foreach ($baseline as $id) {
        if (!isset($seen[$id])) {
            $order[] = $id;
            $seen[$id] = true;
        }
    }
    /* `hidden` is filtered but NOT de-duplicated or ordered — both callers only
       ever use it as a set (array_flip on the dashboard, `new Set()` on the
       client), so duplicates are harmless. */
    $hidden = [];
    foreach (($saved['hidden'] ?? []) as $id) {
        if (isset($baselineSet[$id])) $hidden[] = $id;
    }
    return ['order' => $order, 'hidden' => $hidden];
}

/**
 * Resolve the effective layout for a viewer on a surface. Pure function —
 * just reads state, no side effects.
 *
 * ELI5: given the cards this viewer is allowed to see, work out the order and
 * the hidden set they should actually get — their own arrangement if they have
 * one and are still permitted it, otherwise the admin default, otherwise the
 * order the template shipped with.
 *
 * ⚠ THIS IS THE SERVER-SIDE HYDRATION PATH, AND IT HAS EXACTLY ONE CALLER:
 * `manage/index.php` (the `dashboard` surface). The public home does NOT and
 * MUST NOT call it — `/api?page=home` is a shared-cache fragment, so a
 * per-viewer order baked into the HTML would be served to the next visitor.
 * The home's equivalent runs in `applyCardLayout()` client-side. See the
 * two-hydration-modes section of the file doc-block and CLAUDE.md rule #6.
 *
 * @param string[] $baseline The IDs the viewer may see, in template order —
 *                           already entitlement-filtered by the caller.
 */
function cardLayoutResolve(array $baseline, string $surface, ?array $user): array
{
    $default = cardLayoutDefault($surface);
    $effective = cardLayoutMerge($baseline, $default);

    /* Re-checking the entitlement HERE, on the read path, is what makes the
       group/role veto retroactive: revoke `customise_own_card_layout` (or a
       group's AllowCardReorder) and the saved row stays in tblUsers.Settings
       untouched but stops being applied, so the user snaps back to the site
       default and gets their arrangement back intact if the right is restored.
       Deleting the override on revocation would be destructive and irreversible.

       ⚠ The client-side path does not currently reproduce this: api.php's
       `card_layout_get` returns the stored override to any authenticated
       caller and `applyCardLayout()` applies it without consulting the
       `canCustomiseOwn` flag it is handed alongside — so a revoked user keeps
       their personalised HOME while their DASHBOARD reverts. */
    if ($user && cardLayoutUserCanCustomise($user)) {
        /* `id` (dashboard) or `Id` (raw tblUsers column, as api.php passes it)
           — same loose shape as cardLayoutUserCanCustomise() accepts. */
        $override = cardLayoutUserOverride((int)($user['id'] ?? $user['Id'] ?? 0), $surface);
        /* ALL-OR-NOTHING, not a layered merge: a non-empty user override
           REPLACES the admin default rather than being merged on top of it.
           That is the only coherent reading — the user's saved order was
           produced by dragging the cards they were looking at, so treating it
           as a delta against a default that may since have changed would give
           an arrangement neither party asked for. The emptiness test is also
           how "I pressed Reset" (cleared subtree → both lists empty) falls
           through to the default with no separate sentinel value.
           `mergeLayout()` in card-layout.js mirrors this same choice. */
        if (!empty($override['order']) || !empty($override['hidden'])) {
            $effective = cardLayoutMerge($baseline, $override);
        }
    }
    return $effective;
}

/**
 * Reject anything that doesn't look like a card ID. IDs are short,
 * lowercase, alnum-plus-dash tokens (matches the template-author-chosen
 * data-card-id values throughout the PHP templates).
 *
 * ELI5: the browser can POST any JSON it likes here, so before anything is
 * stored we throw away every entry that doesn't look like one of our own card
 * names.
 *
 * This is the ONE input-validation choke point for the whole feature — both
 * writers and both readers pass their lists through it, so no card ID reaches
 * storage, `htmlspecialchars()` in the dashboard template, or the client's
 * `data-card-id` lookup without matching this pattern. An ALLOW-list, not a
 * deny-list: an unknown ID is dropped rather than escaped, and
 * `cardLayoutMerge()` then discards anything that isn't a real card anyway, so
 * a hostile payload is inert twice over.
 *
 * ⚠ The character class is deliberately narrow, which makes it a rule for
 * template authors as much as a filter: a `data-card-id` containing an
 * uppercase letter, a dot or a space would be silently dropped here, and the
 * only symptom would be "reordering that card never sticks". Existing IDs
 * (`ccli-report`, `song-of-the-day`, `quick-actions`) all comply.
 *
 * @param mixed $value Untrusted — typically `$body['order']` straight off a
 *                     JSON request body, so it may not be an array at all.
 * @return string[]
 */
function cardLayoutSanitiseIds($value): array
{
    if (!is_array($value)) return [];
    $out = [];
    foreach ($value as $v) {
        /* Non-strings (numbers, nested arrays, null from a sparse JSON array)
           are skipped rather than cast — `(string)[1,2]` would warn, and a
           numeric ID is not a card ID. */
        if (!is_string($v)) continue;
        $v = trim($v);
        if ($v === '') continue;
        /* Anchored both ends, so no newline-smuggling: PHP's `$` also matches
           before a trailing newline, but a leading `[a-z0-9]` plus the bounded
           `{0,63}` body means the total length is capped at 64 characters and
           the class itself excludes every whitespace character.
           @see https://www.php.net/manual/en/regexp.reference.anchors.php */
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $v)) continue;
        $out[] = $v;
    }
    /* array_unique preserves the FIRST occurrence and keeps original keys, so
       array_values() is needed to re-index — otherwise the gaps would make
       json_encode() emit a JSON object (`{"0":…,"2":…}`) instead of an array,
       and the client's `saved.order` would no longer be iterable as a list.
       @see https://www.php.net/manual/en/function.array-unique.php */
    return array_values(array_unique($out));
}
