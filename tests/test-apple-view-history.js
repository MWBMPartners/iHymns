/**
 * tests/test-apple-view-history.js - the Apple app's song views and history match the server, and views are sent
 * signed in OR anonymous (account rewrite PR 3b, step I3, epic #2180; widened by the owner 2026-11-24)
 *
 * ELI5
 * ----
 * Opening a song tells the server. Signed in, the view joins the person's history on every device. Signed out,
 * it is still counted (like the website) but carries no person, only this install's random id so the server can
 * limit one device. Things that can quietly go wrong: the app sends a platform word, a period word or a field
 * name the server does not know (nothing fails, the view just is not counted or the list is empty); the signed-out
 * call gets refused by the app itself (the old all-or-nothing sign-in rule); the install id goes out while signed
 * in, or does not go out while signed out; a failure is thrown at the person; or the song-open code stops calling
 * it. This guard compares both sides from the real source.
 *
 * Detailed (rule #34, #35)
 * ------------------------
 * Swift files are found from the tree and have comments removed. Server lists are read from the PHP, never typed:
 *   1. the `platform` word the app sends is in `SONG_HISTORY_PLATFORMS`;
 *   2. the `MostViewedPeriod` raw values equal the keys of `MY_MOST_VIEWED_PERIODS` exactly;
 *   3. the body key the app sends (`song_id`) is read by the `song_view` case, and the three actions are cases;
 *   4. every field the app decodes from a row is a column alias the server's SQL emits;
 *   5. `reportSongView` is the ONLY caller of `apiClient.songView(`, has NO early return on being signed out,
 *      looks up the install id only when signed out and passes it as `deviceId:`, calls with `try?` (every
 *      failure, 429 included, is dropped) and has no `throws`; `recordRecentlyViewed` starts it;
 *   6. the `song_view` endpoint is `optionalAuth` and never `requiresAuth`; the body key it sends for the install
 *      id (`device_id`) is read by the server case, which feeds `songViewRatePlan`; every place the client
 *      attaches the bearer token that tests `requiresAuth` also tests `optionalAuth`;
 *   7. self-tests: each of the above broken in turn is reported.
 *
 * Mutations tried (each turned it red, then was restored): sending platform "ios"; adding a period case;
 * renaming `songbookName` in the Swift row; re-adding a signed-in-only guard; removing the hook from
 * `recordRecentlyViewed`; `optionalAuth` back to `requiresAuth: true`; dropping `deviceId:` from the call;
 * looking up the install id while signed in; `try?` to `try`; removing `optionalAuth` from one bearer site.
 *
 * Usage: node tests/test-apple-view-history.js
 */
import fs from 'node:fs';
import path from 'node:path';
import { appSwiftFiles, read, stripSwift, callArguments, selfTest, REPO } from './lib/swift-source.js';

selfTest();

let failures = 0;
function check(name, cond, detail) {
    if (cond) { console.log('  PASS  ' + name); return; }
    failures++;
    console.log('  FAIL  ' + name + (detail ? '\n        ' + String(detail).slice(0, 700) : ''));
}

const PUB = path.join(REPO, 'appWeb', 'public_html');
const api = fs.readFileSync(path.join(PUB, 'api.php'), 'utf8');
const provenance = fs.readFileSync(path.join(PUB, 'includes', 'song_history_provenance.php'), 'utf8');
const mostViewed = fs.readFileSync(path.join(PUB, 'includes', 'my_most_viewed.php'), 'utf8');

const phpList = (src, name) => {
    const m = new RegExp('const\\s+' + name + '\\s*=\\s*\\[([^\\]]*)\\]').exec(src);
    return m ? [...m[1].matchAll(/'([^']+)'/g)].map((x) => x[1]) : [];
};
const platforms = phpList(provenance, 'SONG_HISTORY_PLATFORMS');
const periods = phpList(mostViewed, 'MY_MOST_VIEWED_PERIODS');
/** The column aliases a server SELECT emits (`AS name`), from the case's SQL text. */
const aliases = (src) => new Set([...src.matchAll(/\bAS\s+([A-Za-z_][A-Za-z0-9_]*)/g)].map((m) => m[1]));
const slice = (src, marker, len) => { const i = src.indexOf(marker); return i < 0 ? '' : src.slice(i, i + len); };
const historySql = aliases(slice(api, "case 'song_history':", 2600));
const mostViewedSql = aliases(slice(mostViewed, 'function myMostViewedRows', 1400));
const songViewCase = slice(api, "case 'song_view':", 5200);

check('read the server lists (platforms: ' + platforms.join(',') + '; periods: ' + periods.join(',') + ')', platforms.includes('apple') && periods.length === 4);
check('read the server column aliases', historySql.has('songId') && historySql.has('viewedAt') && mostViewedSql.has('views'));

const files = appSwiftFiles();
const find = (s) => files.find((f) => f.endsWith(s));
const epFile = find('IHAPI/ViewHistoryEndpoints.swift');
const modelFile = find('IHModels/ViewedSong.swift');
const vmFile = find('IHFeatures/AppRootViewModel+ViewHistory.swift');
const activityFile = find('IHFeatures/AppRootViewModel+Activity.swift');
check('found the endpoint, model, view model and activity files', !!epFile && !!modelFile && !!vmFile && !!activityFile);

const sources = {
    endpoints: stripSwift(read(epFile)),
    model: stripSwift(read(modelFile)),
    vm: stripSwift(read(vmFile), { blankStrings: true }),
    activity: stripSwift(read(activityFile), { blankStrings: true }),
    /** Every IHAPI source (comments out): where the bearer token is attached. */
    apiSources: new Map(files.filter((f) => /IHAPI\//.test(f)).map((f) => [f, stripSwift(read(f), { blankStrings: true })])),
    all: new Map(files.map((f) => [f, stripSwift(read(f), { blankStrings: true })])),
};

/** Judge the Swift side against the server's facts. PURE. */
function judge(src, server) {
    const problems = [];
    // 1. platform word
    const platform = (/platform\s*:\s*"([^"]+)"/.exec(src.endpoints) || [])[1];
    if (!platform || !server.platforms.includes(platform)) problems.push('the app sends platform "' + platform + '", not one of ' + server.platforms.join(','));
    // 2. periods
    const periodEnum = (/enum\s+MostViewedPeriod\b[\s\S]*?\n\}/.exec(src.model) || [''])[0];
    const swiftPeriods = [...periodEnum.matchAll(/\bcase\s+[A-Za-z_]+\s*=\s*"([^"]+)"/g)].map((m) => m[1]);
    if (JSON.stringify([...swiftPeriods].sort()) !== JSON.stringify([...server.periods].sort())) problems.push('period words differ: app [' + swiftPeriods + '] server [' + server.periods + ']');
    // 3. body key and actions
    const bodyKey = (/case\s+songId\s*=\s*"([^"]+)"/.exec(src.endpoints) || [])[1];
    if (!bodyKey || !server.songViewCase.includes("['" + bodyKey + "']")) problems.push('the app sends body key "' + bodyKey + '" that song_view never reads');
    for (const action of [...src.endpoints.matchAll(/action\s*:\s*"([a-z_]+)"/g)].map((m) => m[1])) {
        if (!server.cases.has(action)) problems.push('the app calls "' + action + '" but api.php has no such case');
    }
    // 4. decoded row fields vs SQL aliases (the wire name is the CodingKeys string when there is one, else the property name)
    const fieldsOf = (structName) => {
        const m = new RegExp('struct\\s+' + structName + '\\b[\\s\\S]*?\\n\\}').exec(src.model);
        if (!m) return null;
        const renamed = new Map([...m[0].matchAll(/case\s+([A-Za-z_]+)\s*=\s*"([^"]+)"/g)].map((x) => [x[1], x[2]]));
        const keys = [...m[0].matchAll(/^\s*case\s+([A-Za-z_]+)(?:\s*=\s*"[^"]+")?\s*$/gm)].map((x) => renamed.get(x[1]) ?? x[1]);
        return keys;
    };
    for (const [structName, sql] of [['ViewedSong', server.historySql], ['MostViewedSong', server.mostViewedSql]]) {
        const keys = fieldsOf(structName);
        if (!keys || keys.length === 0) { problems.push('could not read the wire fields of ' + structName); continue; }
        for (const k of keys) if (!sql.has(k)) problems.push(structName + ' decodes "' + k + '" which the server query never emits');
    }
    // 5. who may report, and when
    const callers = [];
    for (const [f, code] of src.all) {
        for (const c of callArguments(code, 'apiClient.songView')) callers.push({ f, at: c.index, code });
    }
    if (callers.length !== 1) problems.push('expected exactly one caller of apiClient.songView(, found ' + callers.length);
    const reportFn = /func\s+reportSongView\s*\(([^)]*)\)\s*([^{]*)\{/.exec(src.vm);
    if (!reportFn) problems.push('reportSongView not found');
    else {
        if (/\bthrows\b/.test(reportFn[2])) problems.push('reportSongView must not throw (a failure is never shown)');
        const end = src.vm.indexOf('\n    }', reportFn.index);
        const body = src.vm.slice(reportFn.index, end < 0 ? undefined : end);
        const callAt = body.search(/apiClient\.songView\s*\(/);
        if (callAt < 0) problems.push('reportSongView no longer calls apiClient.songView(');
        // owner decision 2026-11-24: anonymous views are reported, so there must be no early return on being signed out
        if (/guard\s+(?:sessionState\.)?isSignedIn\b[^{]*else\s*\{\s*return/.test(body) || /if\s+!\s*sessionState\.isSignedIn\s*\{\s*return/.test(body)) problems.push('reportSongView must not return early when signed out (anonymous views are reported)');
        // the install id is looked up ONLY while signed out, and is passed on
        const lookups = [...body.matchAll(/installIdIfAvailable\s*\(/g)];
        if (lookups.length !== 1) problems.push('reportSongView must look up the install id exactly once, found ' + lookups.length);
        else if (!/if\s+!\s*sessionState\.isSignedIn\s*\{[^}]*installIdIfAvailable\s*\(/.test(body)) problems.push('the install id must be looked up only while signed out (inside `if !sessionState.isSignedIn`)');
        if (!/apiClient\.songView\s*\(\s*songId\s*:\s*songId\s*,\s*deviceId\s*:/.test(body)) problems.push('reportSongView must pass deviceId: to apiClient.songView(');
        // fire-and-forget: every failure (429 included) is dropped
        if (!/try\?\s+await\s+apiClient\.songView\s*\(/.test(body)) problems.push('reportSongView must call apiClient.songView with `try?` (every failure is ignored)');
    }
    // 6. optional sign-in on the endpoint, and the client honours it everywhere it attaches the bearer
    const songViewFn = /static\s+func\s+songView\s*\([\s\S]*?\n    \}/.exec(src.endpoints);
    if (!songViewFn) problems.push('Endpoint.songView not found');
    else {
        if (/requiresAuth\s*:\s*true/.test(songViewFn[0])) problems.push('song_view must not be requiresAuth (an anonymous view would be refused by the app itself)');
        if (!/optionalAuth\s*:\s*true/.test(songViewFn[0])) problems.push('song_view must be optionalAuth: true');
    }
    const deviceKey = (/case\s+deviceId\s*=\s*"([^"]+)"/.exec(src.endpoints) || [])[1];
    if (!deviceKey || !server.songViewCase.includes("['" + deviceKey + "']")) problems.push('the app sends install-id body key "' + deviceKey + '" that song_view never reads');
    if (!server.songViewCase.includes('songViewRatePlan(')) problems.push('song_view no longer feeds the device id to songViewRatePlan()');
    let bearerSites = 0;
    for (const [f, code] of src.apiSources) {
        for (const line of code.split('\n')) {
            if (!/endpoint\.requiresAuth/.test(line) || /!\s*endpoint\.requiresAuth/.test(line)) continue;
            if (!/bearerToken|Bearer/.test(line)) continue;
            bearerSites++;
            if (!/optionalAuth/.test(line)) problems.push('a place that attaches the bearer token tests requiresAuth but not optionalAuth: ' + path.basename(f) + ': ' + line.trim());
        }
    }
    if (bearerSites < 2) problems.push('expected at least two places that attach the bearer token, found ' + bearerSites);
    if (!/Task\s*\{\s*await\s+reportSongView\s*\(/.test(src.activity)) problems.push('recordRecentlyViewed no longer starts reportSongView');
    return problems;
}

const server = {
    platforms, periods, cases: new Set([...api.matchAll(/case\s+'([a-z0-9_]+)'\s*:/g)].map((m) => m[1])),
    historySql, mostViewedSql, songViewCase,
};
const real = judge(sources, server);
check('the app and the server agree, and views are sent signed in or anonymous', real.length === 0, real.join('\n        '));

/* ---- self-tests ---- */
const mutate = (src, from, to) => { const out = src.replace(from, to); if (out === src) throw new Error('self-test mutation changed nothing: ' + from); return out; };
const withSrc = (key, fn) => ({ ...sources, [key]: fn(sources[key]) });
check('self-test: a wrong platform word is caught', judge(withSrc('endpoints', (s) => mutate(s, 'platform: "apple"', 'platform: "ios"')), server).length > 0);
check('self-test: an extra period case is caught', judge(withSrc('model', (s) => mutate(s, 'case allTime = "all"', 'case allTime = "all"\n    case forever = "9y"')), server).length > 0);
check('self-test: a renamed row field is caught', judge(withSrc('model', (s) => s.replace(/songbookName/g, 'bookName')), server).length > 0);
check('self-test: a signed-in-only guard coming back is caught', judge(withSrc('vm', (s) => mutate(s, /var deviceId: String\?/, 'guard sessionState.isSignedIn else { return }\n        var deviceId: String?')), server).length > 0);
check('self-test: song_view going back to requiresAuth is caught', judge(withSrc('endpoints', (s) => mutate(s, /optionalAuth\s*:\s*true/, 'requiresAuth: true')), server).length > 0);
check('self-test: dropping deviceId: from the call is caught', judge(withSrc('vm', (s) => mutate(s, /songView\(songId: songId, deviceId: deviceId\)/, 'songView(songId: songId)')), server).length > 0);
check('self-test: looking the install id up while signed in is caught', judge(withSrc('vm', (s) => mutate(s, /if !sessionState\.isSignedIn \{/, 'if true {')), server).length > 0);
check('self-test: a throwing call instead of try? is caught', judge(withSrc('vm', (s) => mutate(s, /try\? await apiClient\.songView/, 'try await apiClient.songView')), server).length > 0);
check('self-test: a bearer site that ignores optionalAuth is caught', judge({ ...sources, apiSources: new Map([...sources.apiSources].map(([f, c]) => [f, c.replace(/endpoint\.requiresAuth \|\| endpoint\.optionalAuth/g, 'endpoint.requiresAuth')])) }, server).length > 0);
check('self-test: a server that stops reading device_id is caught', judge(sources, { ...server, songViewCase: songViewCase.replace(/\['device_id'\]/g, "['x']") }).length > 0);
check('self-test: a throwing reportSongView is caught', judge(withSrc('vm', (s) => mutate(s, /func reportSongView\(_ songId: SongID\) async \{/, 'func reportSongView(_ songId: SongID) async throws {')), server).length > 0);
check('self-test: dropping the hook from recordRecentlyViewed is caught', judge(withSrc('activity', (s) => mutate(s, /Task \{ await reportSongView\(detail\.songId\) \}/, '')), server).length > 0);
check('self-test: a server that stops reading song_id is caught', judge(sources, { ...server, songViewCase: songViewCase.replace(/\['song_id'\]/g, "['x']") }).length > 0);
check('self-test: a server without the apple platform is caught', judge(sources, { ...server, platforms: ['web', 'android'] }).length > 0);

if (failures > 0) {
    console.log('\n' + failures + ' check(s) FAILED');
    process.exit(1);
}
console.log('\nAll checks passed.');
