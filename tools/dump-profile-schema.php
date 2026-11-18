<?php

declare(strict_types=1);

/**
 * tools/dump-profile-schema.php - print (or write) the public profile registry as JSON
 * ====================================================================================
 * Account/profile rewrite step C6 (epic #2180).
 *
 * ELI5
 * ----
 * The server's list of personal settings (includes/profile_registry.php) is also
 * kept as a plain JSON file inside the Apple code, so the app can read it on its
 * very first launch with no network. This tool makes that file. A test then
 * checks the file still equals what this tool prints, so the two can never drift.
 *
 * Usage
 * -----
 *   php tools/dump-profile-schema.php            print the JSON to the screen
 *   php tools/dump-profile-schema.php --write    rewrite the Apple snapshot file
 *
 * The JSON is exactly the `data` the `profile_schema` endpoint returns (the public
 * projection, with the three server-only fields already removed), written with a
 * stable layout and a final newline so a regenerated file differs only when the
 * registry does.
 *
 * @see includes/profile_registry.php
 * @see tests/test-profile-registry-lockstep.js
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}

$repo = dirname(__DIR__);
require_once $repo . '/appWeb/public_html/includes/profile_registry.php';

$data = profileRegistryPublic();
/* The same shaping the endpoint does: an empty alias map is an object, not a list. */
if ($data['aliases'] === []) {
    $data['aliases'] = new \stdClass();
}
$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

if (in_array('--write', $argv ?? [], true)) {
    $target = $repo . '/appApple/Packages/iHymnsKit/Sources/IHModels/Resources/profile-schema.json';
    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
        fwrite(STDERR, "Cannot create the folder for $target\n");
        exit(1);
    }
    file_put_contents($target, $json);
    fwrite(STDERR, "Wrote $target\n");
    exit(0);
}
echo $json;
