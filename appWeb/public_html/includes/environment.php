<?php

declare(strict_types=1);

/**
 * iHymns — canonical deploy-environment detector (#1233)
 *
 * Returns 'alpha' | 'beta' | 'production'. All branches deploy from one source
 * tree, so the environment is the SFTP *destination* directory — detected from
 * DOCUMENT_ROOT / SCRIPT_FILENAME (mirrors infoAppVer.php's Development.Status
 * logic), with a CI-injected `.env-channel` file as the fallback. Cached per
 * request.
 *
 * This matters because all three environments share ONE database, so anything
 * that must behave per-environment (e.g. per-env maintenance mode) keys off this
 * rather than a single shared flag.
 *
 * @return string One of 'alpha', 'beta', 'production'.
 */
function ihymns_environment(): string
{
    static $env = null;
    if ($env !== null) {
        return $env;
    }
    $path = (string)($_SERVER['DOCUMENT_ROOT'] ?? $_SERVER['SCRIPT_FILENAME'] ?? '');
    if (str_contains($path, 'public_html_dev')) {
        return $env = 'alpha';
    }
    if (str_contains($path, 'public_html_beta')) {
        return $env = 'beta';
    }
    /* CI/CD-injected channel file fallback (public_html/.env-channel). */
    $channelFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env-channel';
    if (is_file($channelFile)) {
        $ch = trim((string)@file_get_contents($channelFile));
        if ($ch === 'alpha') { return $env = 'alpha'; }
        if ($ch === 'beta')  { return $env = 'beta'; }
    }
    return $env = 'production';
}

/**
 * iHymns — shared "which channels?" CSV parser (search-visibility feature,
 * #2024/#2025, extracted from includes/webhooks.php's own
 * `webhookParseChannelsCsv()` by rule #22 — the same pure fold was about to
 * be needed by a SECOND feature, so it moved here rather than being copied)
 *
 * ELI5: several settings in this app are "which of the three iHymns copies —
 * alpha, beta, production — does this apply to?", stored as a single row of
 * comma-separated text (e.g. "alpha,beta" or even messily-typed
 * "Alpha, BETA  production"). This is the ONE place that turns that raw text
 * into a clean list of the real channel names it actually means, throwing
 * away anything that isn't one of the three. Every feature that stores a
 * channel list this way (outbound webhooks, the IntAppsAPI gateway allow-list
 * convention, and now search-engine visibility) calls this SAME function, so
 * the parsing rule can never quietly differ between two features that both
 * think they're doing "the same CSV thing".
 *
 * WHY IT LIVES HERE, NOT IN webhooks.php: this file (`environment.php`) is
 * the natural channel-domain home — it already defines `ihymns_environment()`
 * above, has no dependencies of its own, and is loaded on effectively every
 * request (via `maintenance.php`). `webhooks.php` pulls in the whole outbound
 * webhooks platform (curl SSRF guards, HMAC signing, a delivery/retry
 * schedule) that a caller who only wants "parse this CSV" has no reason to
 * load. `webhookParseChannelsCsv()` itself now just calls this function and
 * returns its answer (a one-line delegate, not a second copy) — see that
 * function's own doc-block in includes/webhooks.php.
 *
 * @param string|null $csv Raw setting value — comma and/or whitespace
 *                          separated, any mix of case, e.g. "alpha, Beta".
 * @return array<int,string> The validated, de-duplicated subset of
 *                            {alpha, beta, production} the text names —
 *                            an unrecognised word is silently dropped, never
 *                            an error (a curator mistyping a channel name
 *                            should never break the save).
 * @see includes/webhooks.php::webhookParseChannelsCsv()  the original, now a delegate
 * @see includes/search_visibility.php                    the new consumer this was extracted for
 * @link https://www.php.net/manual/en/function.preg-split.php
 */
function ihymns_parse_channels_csv(?string $csv): array
{
    $raw = (string)$csv;
    $out = [];
    foreach (preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $c) {
        $c = strtolower(trim($c));
        if (in_array($c, ['alpha', 'beta', 'production'], true)) {
            $out[] = $c;
        }
    }
    return array_values(array_unique($out));
}
