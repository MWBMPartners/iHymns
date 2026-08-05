<?php

declare(strict_types=1);

/**
 * iHymns — restrictions Effect-honesty + gating-noop gate guard (#1769 P4 Commit E)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * content_access.php IGNORES the Effect column for every require_* restriction
 * (licence found → pass, absent → deny — never an allow/deny toggle). So the
 * Content Restrictions page must (a) NORMALISE Effect to 'deny' server-side for
 * require_* rows, so the store never claims a policy the engine can't honour, and
 * (b) hide the Effect control for those types. This is engine-dead — a provably
 * zero behaviour change (the value was already ignored). This guard pins both,
 * plus the create/delete activity logging and the gating-noop-verify entitlement
 * gate swap (D9), all mutation-proven.
 *
 * DB-free comment-stripped source scan.  php tests/php/test-restrictions-effect-honesty.php
 *
 * @see appWeb/public_html/manage/restrictions.php
 * @see appWeb/public_html/manage/gating-noop-verify.php
 * @see appWeb/public_html/includes/content_access.php  (Effect ignored for require_*)
 * @see .claude/gating-p4-design.md §"Commit E"
 */

$repoRoot = dirname(__DIR__, 2);
$pub      = $repoRoot . '/appWeb/public_html';

function rehPhpCode(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                $out .= str_repeat("\n", substr_count($tok[1], "\n"));
                continue;
            }
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

$failures = [];
$mut = [];

/* mutation self-test — keep HTML (T_INLINE_HTML passes through) so the JS-toggle
   check can see the form's inline <script>; only PHP comments are stripped. */
$mf = "<?php\n// require_ NeedleComment\n\$x='NeedleCode';\n?>\n<div>NeedleHtml</div>";
$ms = rehPhpCode($mf);
if (strpos($ms, 'NeedleCode') === false || strpos($ms, 'NeedleHtml') === false) { $mut[] = 'rehPhpCode FAILS-HIGH'; }
if (strpos($ms, 'NeedleComment') !== false) { $mut[] = 'rehPhpCode FAILS-LOW: kept a PHP comment'; }

$restr = rehPhpCode((string)file_get_contents($pub . '/manage/restrictions.php'));
$noop  = rehPhpCode((string)file_get_contents($pub . '/manage/gating-noop-verify.php'));

/* (a) server-side Effect normalisation for require_* (the load-bearing line). */
if (!preg_match('/str_starts_with\(\$restrictionType,\s*\'require_\'\)/', $restr)
    || !preg_match('/\$effect\s*=\s*\'deny\'/', $restr)) {
    $failures[] = "restrictions.php does not normalise Effect to 'deny' for require_* types server-side (D10) — a require_* row could store a misleading Effect the engine ignores";
}

/* (b) the Effect control is hidden for require_* in the form JS. */
if (strpos($restr, 'rx-effect-group') === false
    || strpos($restr, "indexOf('require_')") === false) {
    $failures[] = "restrictions.php's form does not hide the Effect control for require_* types (the JS toggle on rx-effect-group)";
}

/* (c) create + delete are activity-logged (owner directive). */
if (strpos($restr, "logActivity('admin.restrictions.create'") === false) {
    $failures[] = "restrictions.php create does not logActivity('admin.restrictions.create', …)";
}
if (strpos($restr, "logActivity('admin.restrictions.delete'") === false) {
    $failures[] = "restrictions.php delete does not logActivity('admin.restrictions.delete', …)";
}

/* (d) gating-noop-verify gates on manage_configuration, not requireGlobalAdmin. */
if (strpos($noop, "userHasEntitlement('manage_configuration'") === false) {
    $failures[] = "gating-noop-verify.php does not gate on userHasEntitlement('manage_configuration', …) (D9)";
}
if (preg_match('/^\s*requireGlobalAdmin\(\);/m', $noop)) {
    $failures[] = "gating-noop-verify.php still calls requireGlobalAdmin() — swap it for the manage_configuration entitlement check so the page + its nav entry agree (#1587)";
}

if ($failures || $mut) {
    if ($mut) { fwrite(STDERR, "FAIL: mutation self-test(s):\n"); foreach ($mut as $m) { fwrite(STDERR, "  - {$m}\n"); } fwrite(STDERR, "\n"); }
    if ($failures) {
        fwrite(STDERR, "FAIL: restrictions Effect-honesty + gating-noop gate guard (#1769 P4 Commit E):\n\n");
        foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
        fwrite(STDERR, "\n");
    }
    exit(1);
}
echo "PASS: require_* restrictions normalise Effect to 'deny' server-side + hide the control; create/delete are "
   . "activity-logged; gating-noop-verify gates on manage_configuration. Mutation self-tests behaved as expected.\n";
exit(0);
