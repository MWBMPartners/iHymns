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
