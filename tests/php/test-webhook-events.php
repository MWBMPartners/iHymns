<?php

declare(strict_types=1);

/**
 * iHymns — Webhook vocabulary, selector matching + payload-shape test (#1909)
 *
 * ELI5: proves three things about the webhook "dictionary": (1) the event
 * registry is well-formed; (2) a subscription's event selectors match the right
 * events and reject typos; (3) the little JSON blob each event carries is
 * identity + metadata ONLY — never lyrics, media, tokens or join codes (the ONE
 * hard payload rule, design §3.3 / §A.7).
 *
 * DETAIL:
 * `includes/webhook_events.php` is PURE (no DB, no network). The identity-not-
 * content assertions feed each builder RICH facts that DELIBERATELY include the
 * banned keys and assert they never appear in the output — so a future edit that
 * started leaking content would fail here.
 *
 *   php tests/php/test-webhook-events.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

$failures = 0;
function check(string $label, bool $cond): void
{
    global $failures;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) { $failures++; }
}
/** Recursively flatten every string key + string value for a "no banned token" scan. */
function _flatten($v): string
{
    if (is_array($v)) {
        $s = '';
        foreach ($v as $k => $vv) { $s .= '|' . $k . '=' . _flatten($vv); }
        return $s;
    }
    return (string)$v;
}

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/webhook_events.php';

echo "1 — the registry is well-formed\n";
check('IHYMNS_WEBHOOK_EVENTS is a non-empty array', is_array(IHYMNS_WEBHOOK_EVENTS) && count(IHYMNS_WEBHOOK_EVENTS) > 0);
$wellFormed = true;
foreach (IHYMNS_WEBHOOK_EVENTS as $type => $spec) {
    if (!is_string($type) || strpos($type, '.') === false
        || !is_array($spec) || count($spec) !== 3
        || !is_string($spec[0]) || !is_string($spec[1]) || !is_string($spec[2])) {
        $wellFormed = false;
        echo "     malformed entry: $type\n";
    }
}
check('every entry is dotted-type => [label, entity, family] of strings', $wellFormed);
check('WEBHOOK_API_VERSION is "1"', WEBHOOK_API_VERSION === '1');

echo "\n2 — type validity\n";
check('song.updated is valid', webhookEventTypeValid('song.updated') === true);
check('webhook.verify is valid', webhookEventTypeValid('webhook.verify') === true);
check('song.exploded is NOT valid', webhookEventTypeValid('song.exploded') === false);
check('empty string is NOT valid', webhookEventTypeValid('') === false);

echo "\n2a — ingest-lifecycle events (#1135) are registered\n";
foreach (['ingest.linked', 'ingest.approved', 'ingest.rejected', 'ingest.conflicted'] as $ingestType) {
    check("$ingestType is a valid registered type", webhookEventTypeValid($ingestType) === true);
}
check("'ingest.*' is a valid selector (family prefix derived from the map)", webhookEventSelectorValid('ingest.*') === true);
check("'ingest.*' matches ingest.linked", webhookEventMatches('ingest.*', 'ingest.linked') === true);

echo "\n3 — selector validation (mirrors tblApiKeys.Scope)\n";
check("'*' is valid", webhookEventSelectorValid('*') === true);
check("'song.updated' is valid", webhookEventSelectorValid('song.updated') === true);
check("'song.*' (family wildcard) is valid", webhookEventSelectorValid('song.*') === true);
check("'song.updated songbook.* setlist.shared' is valid", webhookEventSelectorValid('song.updated songbook.* setlist.shared') === true);
check("'' (empty) is NOT valid — matches nothing", webhookEventSelectorValid('') === false);
check("'songg.*' (typo prefix) is NOT valid", webhookEventSelectorValid('songg.*') === false);
check("'song.exploded' (unknown exact) is NOT valid", webhookEventSelectorValid('song.exploded') === false);
check("a valid token + a garbage token is NOT valid", webhookEventSelectorValid('song.* not-a-thing') === false);

echo "\n4 — selector matching (the ONE pure matcher)\n";
check("'*' matches song.created", webhookEventMatches('*', 'song.created') === true);
check("'*' matches service.started", webhookEventMatches('*', 'service.started') === true);
check("exact 'song.updated' matches song.updated", webhookEventMatches('song.updated', 'song.updated') === true);
check("exact 'song.updated' does NOT match song.created", webhookEventMatches('song.updated', 'song.created') === false);
check("'song.*' matches song.created", webhookEventMatches('song.*', 'song.created') === true);
check("'song.*' does NOT match songbook.created (prefix is exact, not substring)", webhookEventMatches('song.*', 'songbook.created') === false);
check("'songbook.*' matches songbook.import_completed", webhookEventMatches('songbook.*', 'songbook.import_completed') === true);
check("multi-token 'setlist.shared service.*' matches service.ended", webhookEventMatches('setlist.shared service.*', 'service.ended') === true);
check("empty selector matches nothing", webhookEventMatches('', 'song.created') === false);

echo "\n5 — THE HARD RULE: payloads are identity + metadata, NEVER content\n";
/* Feed every builder rich facts INCLUDING banned content/capability keys and
   prove none leak. The banned fingerprints: lyrics, media bytes/urls, share
   tokens, service join codes, the internal base_url injection key. */
$banned = ['lyrics', 'lines', 'chords', 'audio', 'token', 'join_code', 'secret', 'password', 'base_url', 'presence'];

$songData = webhookBuildPayload('song.updated', [
    'song_id' => 'MP-1008', 'public_id' => 'XK3TQ9WMPN', 'songbook_abbr' => 'MP',
    'title' => 'Amazing Grace', 'changed_fields' => ['Title', 'Copyright'],
    'base_url' => 'https://ihymns.app',
    /* banned facts the builder must ignore: */
    'lyrics' => 'Amazing grace how sweet the sound', 'audio' => '/song-media/9', 'secret' => 'x',
]);
$songFlat = _flatten($songData);
check('song.updated carries song_id + public_id + url', isset($songData['song_id'], $songData['public_id'], $songData['url']));
check('song.updated changed_fields are NAMES (Title,Copyright), not values', $songData['changed_fields'] === ['Title', 'Copyright']);
check('song.updated url is the permalink built from base_url', $songData['url'] === 'https://ihymns.app/song/MP-1008');
$songLeak = false;
foreach ($banned as $b) { if (isset($songData[$b]) || stripos($songFlat, $b) !== false) { $songLeak = true; echo "     LEAK: $b in song payload\n"; } }
check('song.updated payload contains NO banned content/capability/internal keys', $songLeak === false);

$setlist = webhookBuildPayload('setlist.shared', [
    'setlist_title' => 'Sunday AM', 'scope' => 'edit', 'owner_org_id' => 7,
    /* banned: the share token/url must NEVER appear (rule #40) */
    'token' => 'shr_secrettoken', 'url' => 'https://ihymns.app/s/shr_secrettoken', 'share_id' => 42,
]);
check('setlist.shared carries scope + owner_org_id', $setlist['scope'] === 'edit' && $setlist['owner_org_id'] === 7);
check('setlist.shared NEVER carries the share token', !isset($setlist['token']) && stripos(_flatten($setlist), 'secrettoken') === false);
check('setlist.shared NEVER carries the share url', !isset($setlist['url']));

$service = webhookBuildPayload('service.started', [
    'session_kind' => 'service', 'org_id' => 3, 'venue_id' => 5, 'occurrence_date' => '2026-08-23',
    /* banned: the join code is proof-of-presence and must NEVER leave (rule #26) */
    'join_code' => 'K7QË2', 'code' => 'K7Q2',
]);
check('service.started carries org_id + venue_id + occurrence_date', $service['org_id'] === 3 && $service['venue_id'] === 5 && $service['occurrence_date'] === '2026-08-23');
check('service.started NEVER carries the join code', !isset($service['join_code']) && !isset($service['code']));

$ingestLinked = webhookBuildPayload('ingest.linked', [
    'song_id' => 'MP-1008', 'submission_id' => 42, 'ingest_source' => 'applemusic-ttml',
    'base_url' => 'https://ihymns.app',
    /* banned: the actual lyric/TTML content must NEVER appear */
    'lyrics' => 'Amazing grace how sweet the sound', 'ttml' => '<tt>…</tt>', 'secret' => 'x',
]);
check('ingest.linked carries song_id + submission_id + ingest_source + url', $ingestLinked['song_id'] === 'MP-1008' && $ingestLinked['submission_id'] === 42 && $ingestLinked['ingest_source'] === 'applemusic-ttml' && $ingestLinked['url'] === 'https://ihymns.app/song/MP-1008');
$ingestFlat = _flatten($ingestLinked);
$ingestLeak = false;
foreach ($banned as $b) { if (isset($ingestLinked[$b]) || stripos($ingestFlat, $b) !== false) { $ingestLeak = true; echo "     LEAK: $b in ingest.linked payload\n"; } }
check('ingest.linked payload contains NO banned content/capability/internal keys', $ingestLeak === false);
check('ingest.linked payload NEVER carries the raw TTML markup passed as a fact', stripos($ingestFlat, '<tt>') === false);

$ingestApproved = webhookBuildPayload('ingest.approved', ['song_id' => 'MP-1008', 'submission_id' => 42, 'ingest_source' => 'user-submission']);
$ingestRejected = webhookBuildPayload('ingest.rejected', ['song_id' => 'MP-1008', 'submission_id' => 42, 'ingest_source' => 'user-submission']);
check('ingest.approved carries ingest_source', $ingestApproved['ingest_source'] === 'user-submission');
check('ingest.rejected carries ingest_source', $ingestRejected['ingest_source'] === 'user-submission');
$ingestConflicted = webhookBuildPayload('ingest.conflicted', ['song_id' => 'MP-1008', 'submission_id' => null, 'ingest_source' => 'applemusic-ttml']);
check('ingest.conflicted allows a null submission_id (registered but not yet emitted — #1135 follow-up)', $ingestConflicted['submission_id'] === null);

echo "\n6 — envelope shape\n";
$env = webhookBuildEnvelope('song.updated', 'evt_abc', 'production', '2026-08-23T12:00:00Z', $songData);
check('envelope has id/type/api_version/channel/occurred_at/data', isset($env['id'], $env['type'], $env['api_version'], $env['channel'], $env['occurred_at'], $env['data']));
check('envelope api_version is "1"', $env['api_version'] === '1');
check('envelope channel is echoed for cross-channel assertion', $env['channel'] === 'production');
check('envelope data is the built payload', $env['data'] === $songData);

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures} webhook-events assertion(s) failed.\n");
    exit(1);
}
echo "All webhook-events assertions passed.\n";
exit(0);
