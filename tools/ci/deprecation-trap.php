<?php

declare(strict_types=1);

/**
 * tools/ci/deprecation-trap.php — write down every "deprecated" warning PHP raises
 *
 * PLAIN-ENGLISH SUMMARY
 * "Deprecated" means "this still works today but PHP plans to remove it". The
 * PHP 8.6 CI run loads this file before every PHP process it starts (setup-php's
 * `auto_prepend_file` setting) so that each such warning is written as one line
 * to a log file. tools/ci/deprecation-report.php reads that log afterwards and
 * fails the check if the warning came from OUR code.
 *
 * DETAILS
 *  - It only records. The handler returns false, so PHP carries on exactly as
 *    it would have, and nothing about a test's own behaviour changes.
 *  - It does nothing unless the environment variable IHYMNS_DEPRECATION_LOG
 *    names a file, so loading it by accident is harmless.
 *  - It writes to a FILE and never to stderr: tools/run-php-tests.php reads a
 *    test's stdout to the end before its stderr, so more than ~64 KB of stderr
 *    could freeze the runner.
 *  - Each line is JSON: {"file": ..., "line": ..., "message": ...}.
 *  - Stated gap: a test that installs its OWN error handler hides warnings
 *    from this one while its handler is active. And a warning raised while PHP
 *    is still compiling a file (before this handler exists for it) is not seen
 *    here; the 8.6 lint step catches those separately.
 *
 * @see tools/ci/deprecation-report.php
 * @see https://www.php.net/manual/en/function.set-error-handler.php
 * @see https://www.php.net/manual/en/ini.core.php#ini.auto-prepend-file
 */

(static function (): void {
    $log = getenv('IHYMNS_DEPRECATION_LOG');
    if (!is_string($log) || $log === '') {
        return;
    }
    set_error_handler(static function (int $no, string $message, string $file = '', int $line = 0) use ($log): bool {
        /* Detailed: only the two "deprecated" levels are of interest. Anything
           else is passed on untouched (false = "carry on as normal"). */
        if (($no & (E_DEPRECATED | E_USER_DEPRECATED)) !== 0) {
            $row = json_encode(['file' => $file, 'line' => $line, 'message' => $message], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($row !== false) {
                /* LOCK_EX: many test processes may append at the same moment. */
                @file_put_contents($log, $row . "\n", FILE_APPEND | LOCK_EX);
            }
        }
        return false;
    }, E_DEPRECATED | E_USER_DEPRECATED);
})();
