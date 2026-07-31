/**
 * iHymns — push-notification client helpers (#311, #1671 F6)
 * ==========================================================
 *
 * ELI5: the notifications card in Settings has a handful of small
 * decision-making functions. This file IMPORTS them, CALLS them, and checks the
 * answers.
 *
 * WHY THESE PARTICULAR FUNCTIONS ARE WORTH PINNING
 * ------------------------------------------------
 * Push has FOUR independent pieces of state that can disagree — browser
 * support, `Notification.permission`, whether a `PushSubscription` exists, and
 * whether the server still has a row for it. Every combination below happens in
 * the wild, and getting one wrong shows the user a button that cannot do
 * anything (or, worse, hides the one explanation that would have helped). So
 * `pushCardState()` is asked about each combination directly rather than being
 * read for the right words.
 *
 * THE CROSS-FILE AGREEMENTS, both of which are mechanisms rather than comments:
 *   §4 re-reads the SERVER's opt-in rule out of `includes/web_push.php` and
 *      asserts that `kindEnabled()` here answers the same way for the same
 *      inputs — the two must agree or a checkbox says one thing while the
 *      sender does another.
 *   §2 pins the field names `push_subscribe` reads (`p256dh_key` / `auth_key`,
 *      NOT the browser's `p256dh` / `auth`) by re-reading api.php, so a rename
 *      on either side goes red instead of producing rows the sender can never
 *      encrypt for.
 *
 * `push-notifications.js` imports `js/utils/api-client.js`, `js/utils/html.js`,
 * `js/utils/announce.js` and `js/constants.js`, none of which touches a browser
 * global at module scope, so a plain `import()` under Node works with no DOM
 * stubs. `vapidKeyToBytes()` uses `atob`, which Node has had globally since
 * v16 — asserted in §1 rather than assumed, so a runtime without it fails
 * loudly instead of silently skipping.
 *
 * ⚠️ THE DOM HALF OF THAT MODULE IS EXERCISED BY NOTHING HERE, and neither is
 * any actual push. A green run does not mean a notification has ever arrived.
 *
 * USAGE:
 *   node tests/test-push-helpers.js
 *
 * Exit status 0 = all pass, 1 = a failure.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
const ROOT       = path.resolve(__dirname, '..');
const MODULES    = path.join(ROOT, 'appWeb', 'public_html', 'js', 'modules');
const WEB_PUSH_PHP = path.join(ROOT, 'appWeb', 'public_html', 'includes', 'web_push.php');
const API_PHP      = path.join(ROOT, 'appWeb', 'public_html', 'api.php');

let failed = 0;
function ok(label, cond, detail = '') {
    console.log((cond ? '  ✅ ' : '  ❌ ') + label);
    if (!cond) {
        failed++;
        if (detail) console.log('       ' + detail);
    }
}

const push = await import(pathToFileURL(path.join(MODULES, 'push-notifications.js')).href);

/* ---------------------------------------------------------------------------
 * 1. vapidKeyToBytes() — the conversion PushManager.subscribe() needs
 * ------------------------------------------------------------------------- */
console.log('\nvapidKeyToBytes()\n');

ok('this runtime provides atob (the module depends on it)', typeof atob === 'function');

/* A real 65-byte uncompressed P-256 point, base64url, unpadded — exactly the
   shape webPushGenerateVapidKeys() emits. Taken from RFC 8291 §5 so it is a
   genuine point rather than 65 arbitrary bytes. */
const REAL_KEY = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';
const bytes = push.vapidKeyToBytes(REAL_KEY);
ok('a real VAPID key converts to 65 bytes', bytes.length === 65, `got ${bytes.length}`);
ok('the result is a Uint8Array', bytes instanceof Uint8Array);
ok('the first byte is the 0x04 uncompressed-point marker', bytes[0] === 4);

/* base64url is emitted WITHOUT padding by every VAPID tool including this
   project's own encoder, and atob rejects an unpadded string in some engines —
   so the padding step is load-bearing, not cosmetic. */
ok('base64url - and _ are translated (not left to fail as invalid base64)',
    (() => { try { push.vapidKeyToBytes(REAL_KEY); return true; } catch { return false; } })());

/* Refusals. A silently-empty array here produces a subscription bound to the
   wrong key, which fails much later and much more confusingly: every push
   rejected by the push service, with nothing in this app to point at. */
const throws = (fn) => { try { fn(); return false; } catch { return true; } };
ok('an empty key throws', throws(() => push.vapidKeyToBytes('')));
ok('a null key throws', throws(() => push.vapidKeyToBytes(null)));
ok('a short key throws (not a 65-byte point)', throws(() => push.vapidKeyToBytes('YWJj')));
ok('a 64-byte key throws — the unpadded-coordinate bug the server guards too',
    throws(() => push.vapidKeyToBytes(Buffer.alloc(64).toString('base64url'))));
ok('a 65-byte key NOT starting 0x04 throws',
    throws(() => push.vapidKeyToBytes(Buffer.concat([Buffer.from([3]), Buffer.alloc(64)]).toString('base64url'))));

/* ---------------------------------------------------------------------------
 * 2. subscriptionToBody() — the field-name mapping, pinned against api.php
 * ------------------------------------------------------------------------- */
console.log('\nsubscriptionToBody()\n');

const fakeSub = {
    toJSON: () => ({ endpoint: 'https://push.example/abc', keys: { p256dh: 'PPP', auth: 'AAA' } }),
};
const body = push.subscriptionToBody(fakeSub);
ok('the endpoint is carried through', body.endpoint === 'https://push.example/abc');
ok('p256dh is renamed to the SERVER\'s p256dh_key', body.p256dh_key === 'PPP');
ok('auth is renamed to the SERVER\'s auth_key', body.auth_key === 'AAA');
ok('no extra keys are sent', Object.keys(body).sort().join(',') === 'auth_key,endpoint,p256dh_key');

/* A subscription missing either key is a browser returning something unusable.
   POSTing it would store a row the sender can never encrypt for — a
   subscription that exists and can never receive anything. */
ok('a subscription with no auth key is refused',
    push.subscriptionToBody({ toJSON: () => ({ endpoint: 'x', keys: { p256dh: 'P' } }) }) === null);
ok('a subscription with no p256dh key is refused',
    push.subscriptionToBody({ toJSON: () => ({ endpoint: 'x', keys: { auth: 'A' } }) }) === null);
ok('a subscription with no endpoint is refused',
    push.subscriptionToBody({ toJSON: () => ({ keys: { p256dh: 'P', auth: 'A' } }) }) === null);
ok('a plain object without toJSON still works (some polyfills)',
    push.subscriptionToBody({ endpoint: 'e', keys: { p256dh: 'P', auth: 'A' } })?.p256dh_key === 'P');
ok('null is refused rather than throwing', push.subscriptionToBody(null) === null);

/* ⭐ CROSS-FILE: the names must be the ones push_subscribe actually reads.
   Re-read from api.php each run rather than copied — a copy is a second thing
   to keep in step with nothing enforcing it (rule #35). */
const apiSrc = fs.readFileSync(API_PHP, 'utf8');
const subCase = apiSrc.slice(apiSrc.indexOf("case 'push_subscribe':"), apiSrc.indexOf("case 'push_unsubscribe':"));
ok('the push_subscribe case body was located (a failed slice must not read as "all clean")',
    subCase.length > 200 && subCase.includes('push_subscribe'));
for (const field of Object.keys(body)) {
    ok(`api.php's push_subscribe reads the field \`${field}\` this module sends`,
        subCase.includes(`'${field}'`) || subCase.includes(`"${field}"`));
}

/* ---------------------------------------------------------------------------
 * 3. pushCardState() — every combination that exists in the wild
 * ------------------------------------------------------------------------- */
console.log('\npushCardState()\n');

const S = (o) => push.pushCardState({ supported: true, configured: true, permission: 'default', subscribed: false, ...o });

const unsupported = S({ supported: false });
ok('an unsupported browser gets NO button (there is nothing to press)', unsupported.button === null);
ok('an unsupported browser is told about the iOS Home Screen requirement',
    /Home Screen/i.test(unsupported.message));

const unconfigured = S({ configured: false });
ok('an unconfigured site gets no button', unconfigured.button === null);
ok('an unconfigured site says so rather than showing a dead Enable button',
    /not switched on/i.test(unconfigured.message));

/* THE ONE MOST WORTH GETTING RIGHT. A denial is a dead end no button can fix —
   browsers do not re-prompt. Offering "Enable" here produces a click that does
   nothing at all, with no error, which reads as a broken app. */
const denied = S({ permission: 'denied' });
ok('a DENIED permission offers no button', denied.button === null);
ok('a DENIED permission explains the site-settings route out', /site settings/i.test(denied.message));
ok('a DENIED permission is toned as a warning, not as normal', denied.tone === 'warning');

const off = S({ permission: 'default', subscribed: false });
ok('an unsubscribed device is offered "turn on"', /turn on/i.test(off.button));
ok('an unsubscribed device is told it is off', /off for this device/i.test(off.message));

const on = S({ permission: 'granted', subscribed: true });
ok('a subscribed device is offered "turn off"', /turn off/i.test(on.button));
ok('a subscribed device is told it is on', /on for this device/i.test(on.message));
ok('a subscribed device reads as success', on.tone === 'success');

/* The states that CAN disagree — permission granted but the subscription gone
   (site data cleared), and permission still 'default' while a subscription
   survives (a stale registration). Both must follow the SUBSCRIPTION, because
   that is what determines whether a push can actually arrive. */
ok('permission granted + no subscription still offers "turn on"',
    /turn on/i.test(S({ permission: 'granted', subscribed: false }).button));
ok('permission default + a live subscription offers "turn off"',
    /turn off/i.test(S({ permission: 'default', subscribed: true }).button));

/* Ordering: "unsupported" must beat everything, and "denied" must beat
   "subscribed" — a denied permission with a stale subscription cannot deliver. */
ok('unsupported outranks every other state',
    S({ supported: false, permission: 'granted', subscribed: true }).button === null);
ok('denied outranks subscribed', S({ permission: 'denied', subscribed: true }).button === null);
ok('every state produces a non-empty message',
    [
        { supported: false }, { configured: false }, { permission: 'denied' },
        { subscribed: true }, {},
    ].every((o) => S(o).message.trim() !== ''));

/* ---------------------------------------------------------------------------
 * 4. kindEnabled() — must answer identically to the SERVER's rule
 * ------------------------------------------------------------------------- */
console.log('\nkindEnabled() vs the server\n');

const kindOnByDefault  = { key: 'announcement', default: true };
const kindOffByDefault = { key: 'quiet', default: false };

ok('never chosen => the kind\'s default (on)', push.kindEnabled({}, kindOnByDefault) === true);
ok('never chosen => the kind\'s default (off)', push.kindEnabled({}, kindOffByDefault) === false);
ok('an explicit true is honoured', push.kindEnabled({ announcement: true }, kindOnByDefault) === true);
ok('an explicit false is honoured', push.kindEnabled({ announcement: false }, kindOnByDefault) === false);

/* STRICT === true, exactly as webPushKindEnabled() is. Reading a stored "0" as
   truthy is how an opt-OUT silently becomes an opt-in — and the two sides
   disagreeing means the checkbox says one thing while the sender does another. */
for (const bad of ['0', 0, '', null, undefined, 'false', [], {}]) {
    ok(`a non-boolean stored value reads as OFF: ${JSON.stringify(bad)}`,
        push.kindEnabled({ announcement: bad }, kindOnByDefault) === false);
}
ok('a null prefs object falls back to the default', push.kindEnabled(null, kindOnByDefault) === true);
ok('a non-object prefs value falls back to the default', push.kindEnabled('nope', kindOnByDefault) === true);

/* ⭐ CROSS-FILE: the server's own rule, re-read each run. `webPushKindEnabled()`
   compares with `=== true`; if it ever loosened, this goes red rather than the
   two silently disagreeing. Fails LOUDLY when the extraction stops finding the
   function — a check that quietly stops checking is the wrong-but-green scanner
   this codebase keeps rediscovering. */
const phpSrc = fs.readFileSync(WEB_PUSH_PHP, 'utf8');
const fnStart = phpSrc.indexOf('function webPushKindEnabled(');
ok('webPushKindEnabled() was FOUND in web_push.php', fnStart !== -1);
if (fnStart !== -1) {
    const fnBody = phpSrc.slice(fnStart, phpSrc.indexOf('\n}', fnStart));
    ok('the SERVER compares the stored opt-in with === true, exactly as this module does',
        /\$subtree\[\$kind\]\s*===\s*true/.test(fnBody),
        'if the server loosened to (bool), a stored "0" would mean opposite things on the two sides');
    ok('the SERVER also refuses an unregistered kind',
        /array_key_exists\(\$kind, \$kinds\)/.test(fnBody));
}

/* ---------------------------------------------------------------------------
 * 5. parsePushKinds() — the registry arrives from the server, never hardcoded
 * ------------------------------------------------------------------------- */
console.log('\nparsePushKinds()\n');

const kinds = push.parsePushKinds(JSON.stringify({
    announcement: { label: 'Announcements', description: 'News', default: true },
    test:         { label: 'Tests',         description: '',     default: false },
}));
ok('both kinds are parsed', kinds.length === 2);
ok('the key is carried', kinds[0].key === 'announcement');
ok('the label is carried', kinds[0].label === 'Announcements');
ok('the description is carried', kinds[0].description === 'News');
ok('the default is carried as a boolean', kinds[0].default === true && kinds[1].default === false);
ok('a non-true default is normalised to false',
    push.parsePushKinds('{"a":{"default":"yes"}}')[0].default === false);
ok('a missing label falls back to the key', push.parsePushKinds('{"a":{}}')[0].label === 'a');

/* The on/off switch is the important half of the card and must survive an
   unreadable kind list rather than the whole card throwing. */
ok('malformed JSON yields an empty list, not a throw', push.parsePushKinds('{not json').length === 0);
ok('an empty attribute yields an empty list', push.parsePushKinds('').length === 0);
ok('null yields an empty list', push.parsePushKinds(null).length === 0);
ok('a JSON ARRAY yields an empty list (the contract is an object map)',
    push.parsePushKinds('[1,2,3]').length === 0);

/* ---------------------------------------------------------------------------
 * 6. pushErrorForStatus() — branch on the NUMBER, never the sentence
 * ------------------------------------------------------------------------- */
console.log('\npushErrorForStatus()\n');

ok('401 asks the user to sign in', /sign in/i.test(push.pushErrorForStatus(401)));
ok('400 blames the subscription, not the user', /subscription/i.test(push.pushErrorForStatus(400)));
ok('429 mentions waiting', /wait/i.test(push.pushErrorForStatus(429)));
ok('500 is distinguished from a client error',
    push.pushErrorForStatus(500) !== push.pushErrorForStatus(400));
ok('a server-supplied message wins over the canned one',
    push.pushErrorForStatus(403, 'Server says no') === 'Server says no');
ok('every status maps to a non-empty sentence',
    [0, 400, 401, 403, 404, 409, 413, 429, 500, 503].every(s => push.pushErrorForStatus(s).trim() !== ''));

console.log('');
if (failed > 0) {
    console.error(`${failed} assertion(s) failed.`);
    process.exit(1);
}
console.log('All push helper assertions passed.');
