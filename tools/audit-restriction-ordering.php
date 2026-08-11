<?php

declare(strict_types=1);

/**
 * iHymns — content-restriction equal-priority tiebreak audit (#1769 P0)
 * =====================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `includes/content_access.php` decides who may see a restricted song. When
 * two rules for the same song sit at the SAME priority, one saying "allow" and
 * one saying "deny", something has to break the tie. For years the code broke
 * it the WRONG way (allow won) even though the file header, the schema comment
 * and the admin page all promised "deny wins". #1769 P0 corrected the SQL tie-
 * break to `Effect DESC` so deny wins, matching the promise.
 *
 * That correction changes a real verdict for EXACTLY ONE kind of data: a song
 * (or wildcard) that has both an allow AND a deny rule at the same priority. If
 * no such data exists on a database, the fix is a pure no-op there. This script
 * is the check: it prints every equal-priority allow+deny cluster it can find,
 * so an operator can confirm "none — safe to enable" or eyeball the handful
 * that exist before flipping content gating on.
 *
 * DETAILED / WHY A CONSERVATIVE OVER-APPROXIMATION
 * ------------------------------------------------
 * The genuinely-affected set is "rules that (a) tie on Priority, (b) carry
 * opposing Effect, AND (c) could both match the SAME viewer" — condition (c)
 * depends on the viewer (platform / user / org / licence) and on the wildcard
 * union (`EntityId = '*'` competes with every specific entity of its type), so
 * it cannot be decided from the rows alone. This script therefore reports a
 * deliberate SUPERSET: any (EntityType, Priority) bucket that contains both an
 * allow and a deny row. Consequences:
 *   - EMPTY result  ⇒ the tiebreak fix is provably a no-op for every possible
 *                     viewer on this database. This is the "safe to enable"
 *                     signal the #1769 readiness checklist wants.
 *   - NON-EMPTY     ⇒ the printed rows MIGHT change verdict once gating is on;
 *                     a human reviews them (many will be false positives — e.g.
 *                     a specific-song allow and a different-song deny that never
 *                     tie for one entity, or a require_* rule whose Effect the
 *                     engine ignores anyway). Better to over-report than to miss
 *                     one (rule #34: a scanner that under-reports is worse than
 *                     none).
 *
 * MUST be run against EACH of the three shared-host docroots' databases before
 * content gating is enabled there (they share one MySQL today, but that is an
 * operational fact, not a guarantee — run it per docroot). It is READ-ONLY:
 * a single SELECT, no writes, safe to run on production.
 *
 * Usage:  php tools/audit-restriction-ordering.php
 * Exit:   0 = no equal-priority allow+deny clusters (safe);
 *         2 = clusters found (printed for review);
 *         1 = could not run (no DB / no table).
 *
 * @link appWeb/public_html/includes/content_access.php  the ORDER BY this audits (#1769 P0)
 * @see  tests/php/test-content-access-ordering.php       the behavioural guard that deny wins
 */

require_once dirname(__DIR__) . '/appWeb/public_html/includes/db_mysql.php';

try {
    $db = getDbMysqli();
} catch (\Throwable $e) {
    fwrite(STDERR, "Could not connect to the database: {$e->getMessage()}\n");
    exit(1);
}

/* Optional table — a minimal install may not have it (content_access.php's own
   note). Absence means "no restrictions, nothing to audit" = safe. */
$exists = $db->query(
    "SELECT 1 FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblContentRestrictions' LIMIT 1"
);
if ($exists === false || $exists->num_rows === 0) {
    echo "tblContentRestrictions is not present on this database — nothing to audit (safe).\n";
    exit(0);
}

/* The superset: (EntityType, Priority) buckets carrying BOTH an allow and a
   deny. Hardcoded constant SQL, no user input (rule #5a). */
$sql = "SELECT EntityType, Priority,
               SUM(Effect = 'allow') AS Allows,
               SUM(Effect = 'deny')  AS Denies,
               COUNT(*)              AS Total
        FROM tblContentRestrictions
        GROUP BY EntityType, Priority
        HAVING Allows > 0 AND Denies > 0
        ORDER BY EntityType, Priority DESC";
$res = $db->query($sql);
$buckets = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

if ($buckets === []) {
    echo "No equal-priority allow+deny clusters found in tblContentRestrictions.\n";
    echo "The #1769 P0 tiebreak fix (Effect DESC — deny wins at equal priority) is a\n";
    echo "no-op on this database: safe to enable content gating here.\n";
    exit(0);
}

fwrite(STDERR, "FOUND equal-priority allow+deny cluster(s) — review before enabling gating:\n\n");
foreach ($buckets as $b) {
    fwrite(STDERR, "  EntityType={$b['EntityType']}  Priority={$b['Priority']}"
        . "  (allow={$b['Allows']}, deny={$b['Denies']}, total={$b['Total']})\n");
    /* Print the individual rows so a human can judge whether they actually tie
       for a single viewer. Constant SQL + bound params. */
    $stmt = $db->prepare(
        "SELECT Id, EntityId, RestrictionType, TargetType, TargetId, Effect, Reason
         FROM tblContentRestrictions
         WHERE EntityType = ? AND Priority = ?
         ORDER BY Effect DESC, EntityId"
    );
    $stmt->bind_param('si', $b['EntityType'], $b['Priority']);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        fwrite(STDERR, sprintf(
            "      #%s  entity=%s  %s  target=%s:%s  effect=%s  %s\n",
            $r['Id'], $r['EntityId'], $r['RestrictionType'],
            $r['TargetType'], $r['TargetId'], $r['Effect'],
            $r['Reason'] !== '' ? "reason=\"{$r['Reason']}\"" : ''
        ));
    }
    $stmt->close();
    fwrite(STDERR, "\n");
}
fwrite(STDERR, "Note: this is a conservative superset (see the file doc-block). Many rows here\n");
fwrite(STDERR, "may never tie for one viewer (different EntityId, or a require_* rule whose\n");
fwrite(STDERR, "Effect the engine ignores). Confirm each real tie now resolves the way you want\n");
fwrite(STDERR, "with deny winning, then it is safe to enable.\n");
exit(2);
