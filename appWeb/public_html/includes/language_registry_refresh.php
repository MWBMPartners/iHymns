<?php

declare(strict_types=1);

/**
 * iHymns — IANA + CLDR language registry refresh core (#738 follow-on)
 *
 * ELI5: one shared "go get the latest official list of world languages,
 * scripts, regions and spelling-variant subtags, and teach the database
 * about it" button. Two different callers press it: a human clicking
 * "Refresh from IANA + CLDR (live)" on `/manage/setup-database`, and a
 * monthly robot (a GitHub Action poking a keyed endpoint) that runs the
 * same refresh silently, unattended.
 *
 * DETAIL:
 * -------
 * Hoisted VERBATIM (rule #35 — one mechanism, not two divergent copies)
 * out of `api.php`'s `admin_refresh_iana_cldr` handler, which is now a
 * THIN delegate calling `languageRegistryRefreshCore()` below. This file
 * fetches the 5 upstream IANA/CLDR files over outbound HTTPS, sanity-
 * checks each (>=100 bytes — the original handler's own floor),
 * overwrites the bundled git-tracked snapshots in `appWeb/.sql/data/`,
 * then re-runs the idempotent `migrate-iana-language-subtag-registry.php`
 * import so the live tables pick up anything new. That migration's own
 * contract (INSERT IGNORE + selective UPDATE, doc-blocked in the file
 * itself) means a curator-edited row is never clobbered and nothing is
 * ever deleted — IANA "retiring" a subtag is handled by a curator
 * flipping `IsActive` on `/manage/languages`, never by this refresh.
 *
 * TWO CALLERS, ONE CORE (so a bug fixed here is fixed for both):
 *   - `api.php`'s `admin_refresh_iana_cldr` case — POST, global_admin
 *     session, `X-Requested-With` CSRF — the human "Refresh from IANA +
 *     CLDR (live)" button on the #738 setup-database card.
 *   - `language-registry-refresh.php` (this repo's new keyed endpoint,
 *     the `webhook-drain.php` shape) — the monthly GitHub Action's Leg B
 *     (`.github/workflows/language-registry-refresh.yml`).
 *
 * WHY A SEPARATE `languageRegistrySchemaReady()` GATE EXISTS: the
 * scheduled/keyed refresh must NEVER be the thing that first runs the
 * #738 DDL (the table rename + new columns + new table) on the shared
 * DB — that stays a deliberate, one-time HUMAN press of the existing
 * card (the plan's Activation Runbook step 2). An unattended endpoint
 * silently altering schema on a robot's cron is exactly the surprise
 * CLAUDE.md rule #19's migration discipline exists to prevent. Once a
 * human has pressed the card once, this probe is true forever after (the
 * #738 migration only adds; it never drops), so every scheduled run's
 * schema step is a no-op and the refresh really is "data only, silent".
 *
 * @see appWeb/.sql/migrate-iana-language-subtag-registry.php  the import this re-runs (#738)
 * @see appWeb/public_html/language-registry-refresh.php       the keyed endpoint caller (Leg B)
 * @see appWeb/public_html/api.php                              the admin-button caller (admin_refresh_iana_cldr)
 * @see .claude/bcp47-language-registry-plan.md §3               the plan this implements
 */

/* Belt-and-braces (matches every other `includes/*.php` in this codebase,
   e.g. `language_names.php`): this file assumes a lot of pre-loaded
   context (getDbMysqli(), etc.) and is never meant to be hit directly by
   a browser — `.htaccess` already blocks the whole `includes/` directory
   outright, this is just a second, cheap layer. */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';

/**
 * Is the #738 schema live on THIS shared DB — i.e. has a human pressed
 * the "Run IANA + CLDR Import" card at least once? A small, independent
 * re-implementation of the EXACT probe
 * `manage/includes/migration-registry.php`'s 'iana-language-subtag-
 * registry' card uses (inverted: that probe answers "is it still
 * pending?"; this answers "is it ready?") — kept independent rather than
 * requiring the whole migration-registry.php file (which pulls in the
 * full setup-database card/probe map and is not meant to be loaded by a
 * standalone endpoint) so this file has the smallest possible dependency
 * footprint. Two INFORMATION_SCHEMA columns, no user input, memoised per
 * request the same way every other schema probe in this codebase is.
 *
 * @return bool true once tblLanguageVariants exists AND tblLanguages.Scope exists.
 */
function languageRegistrySchemaReady(\mysqli $db): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $stmt = $db->prepare(
            "SELECT
                (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblLanguageVariants') AS has_variants,
                (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblLanguages' AND COLUMN_NAME = 'Scope') AS has_scope"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $ready = ($row !== null && (int)$row['has_variants'] > 0 && (int)$row['has_scope'] > 0);
    } catch (\Throwable $e) {
        error_log('[language_registry_refresh] schema-ready probe failed: ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}

/**
 * Fetch the 5 upstream IANA/CLDR files, overwrite the bundled snapshots
 * under `appWeb/.sql/data/`, then re-run the idempotent import. HOISTED
 * VERBATIM (same fetch loop, same 30s timeout, same >=100-byte sanity
 * floor, same snapshot filenames/URLs, same migration re-run shape) from
 * the pre-hoist `admin_refresh_iana_cldr` handler body in `api.php` — see
 * that case, now a thin wrapper around this function. Deliberately never
 * throws for an ORDINARY failure (a bad fetch, a write failure) — every
 * failure mode is reported in the returned array so both callers (a JSON
 * admin response, a 200/502 keyed-endpoint response) can render it their
 * own way; a genuinely unexpected exception (e.g. the DB connection
 * itself failing) is left to propagate, matching how the original inline
 * handler behaved (it ran inside api.php's own top-level try/catch).
 *
 * Callers MUST check `languageRegistrySchemaReady()` themselves before
 * calling this — this function does not gate itself, so the admin
 * button's own "the card is right there, of course a human can press it"
 * UX is unaffected; only the KEYED/unattended callers need the dormancy
 * gate (see `language-registry-refresh.php`).
 *
 * @return array{ok:bool,fetched:string[],failed:string[],migrationLog:string,error:?string}
 */
function languageRegistryRefreshCore(): array
{
    /* From includes/, appWeb/.sql/data is TWO levels up (includes ->
       public_html -> appWeb) then down into .sql/data — the plan's own
       §3.3 confirms this exact resolution. This is NOT the rule-#41 trap
       (a `.sql/`-resident script reaching back into a renamed docroot):
       this file lives permanently INSIDE the docroot at a fixed depth
       (appWeb/public_html/includes/), so `dirname(__DIR__, 2)` always
       resolves to the CURRENT docroot's parent (`appWeb/`) regardless of
       which channel-specific docroot name (`public_html`,
       `public_html_dev`, `public_html_beta`) this file is actually
       running from — no literal `/public_html/` is ever spelled out. */
    $dataDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.sql' . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dataDir) || !is_writable($dataDir)) {
        return [
            'ok' => false, 'fetched' => [], 'failed' => [],
            'migrationLog' => '', 'error' => "Snapshot directory not writable: {$dataDir}",
        ];
    }

    /* Live URLs — identical to the original inline handler. The CLDR base
       hits the unicode-org/cldr-json GitHub mirror raw content; IANA
       serves the registry as a stable URL. Both are publicly fetchable,
       no auth. */
    $sources = [
        'iana-language-subtag-registry.txt'
            => 'https://www.iana.org/assignments/language-subtag-registry',
        'cldr-en-languages.json'
            => 'https://raw.githubusercontent.com/unicode-org/cldr-json/main/cldr-json/cldr-localenames-full/main/en/languages.json',
        'cldr-en-scripts.json'
            => 'https://raw.githubusercontent.com/unicode-org/cldr-json/main/cldr-json/cldr-localenames-full/main/en/scripts.json',
        'cldr-en-territories.json'
            => 'https://raw.githubusercontent.com/unicode-org/cldr-json/main/cldr-json/cldr-localenames-full/main/en/territories.json',
        'cldr-en-variants.json'
            => 'https://raw.githubusercontent.com/unicode-org/cldr-json/main/cldr-json/cldr-localenames-full/main/en/variants.json',
    ];

    $fetched = [];
    $failed  = [];
    foreach ($sources as $filename => $url) {
        $ctx = stream_context_create([
            'http' => [
                'timeout'         => 30,
                'follow_location' => 1,
                'header'          => "User-Agent: iHymns-IANA-Refresh/1.0\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false || strlen($body) < 100) {
            $failed[] = $filename;
            continue;
        }
        $target = $dataDir . DIRECTORY_SEPARATOR . $filename;
        if (@file_put_contents($target, $body) === false) {
            $failed[] = $filename . ' (write failed)';
            continue;
        }
        $fetched[] = $filename . ' (' . number_format(strlen($body)) . ' bytes)';
    }

    if (!empty($failed)) {
        return [
            'ok' => false, 'fetched' => $fetched, 'failed' => $failed,
            'migrationLog' => '',
            'error' => 'One or more source fetches failed. Check server outbound HTTPS'
                . ' connectivity. The bundled snapshots remain in place; pre-existing data is untouched.',
        ];
    }

    /* Snapshots refreshed. Re-run the migration so the DB picks up new
       rows. Run as a sub-process include so the migration's "echo" output
       is captured in our response instead of streaming through. Guarded
       define() — a caller that already set this (the setup-database
       runner itself, were this ever invoked from there) is left alone. */
    if (!defined('IHYMNS_SETUP_DASHBOARD')) {
        define('IHYMNS_SETUP_DASHBOARD', true);
    }
    ob_start();
    try {
        require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.sql' . DIRECTORY_SEPARATOR . 'migrate-iana-language-subtag-registry.php';
        $migOutput = ob_get_clean();
    } catch (\Throwable $e) {
        ob_end_clean();
        return [
            'ok' => false, 'fetched' => $fetched, 'failed' => [],
            'migrationLog' => '',
            'error' => 'Snapshots refreshed but migration re-run failed: ' . $e->getMessage(),
        ];
    }

    return [
        'ok' => true, 'fetched' => $fetched, 'failed' => [],
        'migrationLog' => $migOutput, 'error' => null,
    ];
}
