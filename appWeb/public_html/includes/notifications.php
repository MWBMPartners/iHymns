<?php

declare(strict_types=1);

/**
 * notifications.php — the ONE in-app notification writer (#1638)
 * ============================================================================
 *
 * ELI5: this is the single place that puts a "you've got mail" row in front of
 * a user. Everything that wants to tell someone something writes it here.
 *
 * In detail: the `INSERT INTO tblNotifications (...)` statement had been
 * copy-pasted into three separate files before this helper existed —
 *
 *   - manage/editor/api2.php   (bulk-import completion)
 *   - manage/editor/api.php    (bulk-import completion, the v1 twin)
 *   - manage/notifications.php (the admin broadcast fan-out)
 *
 * — and #1638 needed a FOURTH (setlist collaboration invites). Four copies of
 * one INSERT is exactly what the CLAUDE.md modularity rule forbids, and the
 * copies had already drifted: only the admin broadcast knew that the
 * Environment / ExpiresAt columns are migration-gated (#1238) and that
 * ActionUrl is `NOT NULL DEFAULT ''` so binding NULL throws. A new caller
 * copying the wrong twin inherits a 500 nobody notices until a user reports it.
 *
 * ---------------------------------------------------------------------------
 * BEST-EFFORT IS THE CONTRACT, NOT A CONVENIENCE
 * ---------------------------------------------------------------------------
 *
 * ELI5: if the doorbell is broken, the parcel still gets delivered.
 *
 * The precedent is manage/editor/api.php's own comment — "a tblNotifications
 * failure must not poison the import". A notification is a courtesy attached
 * to some other operation that has ALREADY succeeded (an import finished, a
 * collaborator was added to a setlist). If the courtesy fails, the operation
 * must still report success, because the durable state change already
 * happened. So every path in here is wrapped: nothing thrown from this file
 * ever reaches the caller, and the return value is a plain bool the caller may
 * ignore.
 *
 * That matters doubly under this codebase's mysqli settings: db_mysql.php runs
 * mysqli with MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT, so prepare()/execute()
 * THROW rather than returning false. An unguarded notification INSERT against
 * an un-migrated column would therefore 500 the whole request.
 * @see https://www.php.net/manual/en/class.mysqli-sql-exception.php
 *
 * @package iHymns
 */

/**
 * Do the migration-gated notification-scope columns exist? (#1238)
 *
 * ELI5: checks once whether this database has the newer "which site / when
 * does it expire" fields, and remembers the answer.
 *
 * Migrations are web-run, never auto-applied on deploy (CLAUDE.md rule #19),
 * and the three docroots share ONE MySQL, so "it is in schema.sql" is NOT the
 * same as "it exists on this install". A caller that blindly binds seven
 * columns against a five-column table throws under STRICT. Probing
 * INFORMATION_SCHEMA is the house pattern for exactly this
 * (cf. `$notifHasScope` in manage/notifications.php, which this replaces for
 * new callers).
 *
 * Cached in a static for the life of the request — the schema cannot change
 * mid-request, and a fan-out broadcast would otherwise re-probe per recipient.
 *
 * @param  \mysqli $db Live connection from getDbMysqli().
 * @return bool True when tblNotifications.Environment exists.
 */
function notificationsHasScopeColumns(\mysqli $db): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        $res = $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblNotifications'
                AND COLUMN_NAME  = 'Environment'
              LIMIT 1"
        );
        $has = ($res !== false && $res->num_rows > 0);
        if ($res !== false) {
            $res->close();
        }
    } catch (\Throwable $_e) {
        /* A probe failure is not a reason to lose the notification — fall back
           to the five-column INSERT, which every install has. */
        $has = false;
    }
    return $has;
}

/**
 * Write one in-app notification. Never throws.
 *
 * ELI5: "tell this user this thing, and if that fails, shrug and carry on."
 *
 * Every value is bound via bind_param — never interpolated into the SQL
 * string (CLAUDE.md rule #5). Strings are clipped to their column widths with
 * mb_substr rather than substr so a multi-byte title is cut on a character
 * boundary and not mid-codepoint; MySQL in STRICT mode rejects an over-length
 * value outright rather than silently truncating it, so clipping here is what
 * keeps a long song title from turning a courtesy into a 500.
 * @see https://www.php.net/manual/en/function.mb-substr.php
 *
 * @param \mysqli     $db          Live connection from getDbMysqli().
 * @param int         $userId      Recipient tblUsers.Id. Non-positive = no-op.
 * @param string      $type        Short machine key, e.g. 'setlist_collab_invite'
 *                                 (tblNotifications.Type, VARCHAR(50)).
 * @param string      $title       Headline shown in the bell menu (VARCHAR(255)).
 * @param string      $body        Longer text (TEXT). '' is valid.
 * @param string      $actionUrl   Deep link. MUST be a /-rooted local path or an
 *                                 https:// URL — anything else is dropped to ''
 *                                 so a caller can never smuggle `javascript:`
 *                                 into a link the recipient will click.
 *                                 Column is NOT NULL DEFAULT '', so '' (never
 *                                 NULL) means "no link".
 * @param string|null $environment 'alpha'|'beta'|'production', or null for all
 *                                 three. Ignored on an un-migrated install.
 * @param string|null $expiresAt   'Y-m-d H:i:s', or null for never. Ignored on
 *                                 an un-migrated install.
 * @return bool True when a row was written; false on any failure (already logged).
 */
function notifyUser(
    \mysqli $db,
    int $userId,
    string $type,
    string $title,
    string $body = '',
    string $actionUrl = '',
    ?string $environment = null,
    ?string $expiresAt = null
): bool {
    if ($userId <= 0 || $type === '' || $title === '') {
        return false;
    }

    try {
        $type  = mb_substr($type, 0, 50);
        $title = mb_substr($title, 0, 255);

        /* Link allow-list. The recipient clicks this, so an attacker-influenced
           value must not be able to become a `javascript:` or `data:` URI. The
           two accepted shapes mirror the admin broadcast form's own validation
           in manage/notifications.php: a site-local path, or explicit https. */
        $url = mb_substr($actionUrl, 0, 500);
        if ($url !== ''
            && !preg_match('#^/[^/]#', $url)
            && !preg_match('#^https://#i', $url)) {
            $url = '';
        }

        if (notificationsHasScopeColumns($db)) {
            $stmt = $db->prepare(
                'INSERT INTO tblNotifications
                    (UserId, Type, Title, Body, ActionUrl, Environment, ExpiresAt)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $env = ($environment !== null && $environment !== '') ? $environment : null;
            $exp = ($expiresAt   !== null && $expiresAt   !== '') ? $expiresAt   : null;
            $stmt->bind_param('issssss', $userId, $type, $title, $body, $url, $env, $exp);
        } else {
            $stmt = $db->prepare(
                'INSERT INTO tblNotifications
                    (UserId, Type, Title, Body, ActionUrl)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('issss', $userId, $type, $title, $body, $url);
        }
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (\Throwable $e) {
        /* Server-side breadcrumb only. The caller's operation already
           succeeded; surfacing this to the user would be a lie about what
           failed. */
        error_log('[notifyUser] type=' . $type . ' user=' . $userId . ': ' . $e->getMessage());
        return false;
    }
}
