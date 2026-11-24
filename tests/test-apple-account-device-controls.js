/**
 * tests/test-apple-account-device-controls.js - the Apple account screen's device controls ask first and
 * only appear when they should (account rewrite PR 3b, step I2d, epic #2180)
 *
 * ELI5
 * ----
 * "Sign Out Everywhere" signs out every Apple device the person owns. A button that does that on a single
 * tap, or a section that shows when the site has the feature switched off, would be easy to ship by accident
 * and impossible to spot in a normal run. This guard reads the account screen's source and checks the three
 * promises: the destructive action only runs from inside the "are you sure" dialog, the whole section and
 * the automatic sign-in switch only draw when the status says they should, and the screen's calls go to
 * real methods that exist.
 *
 * Detailed (rule #34, #35)
 * ------------------------
 * Files are found from the tree, comments removed, call sites found by balanced-paren parsing. The checks:
 *   1. every call to the screen's own `signOutEverywhere(` sits inside the "Sign out everywhere?"
 *      `.confirmationDialog(` call (its arguments and its trailing closures) or inside `signOutAgain`, the one
 *      function the unsent-changes dialog (PR 3b, I5) retries through, which is itself only started from inside
 *      that dialog; never in a plain button action;
 *   2. `devicesSection` is only used under `deviceStatus?.switchedOn == true`, and the automatic sign-in
 *      `Toggle` only under `canOfferAutomaticSignIn`;
 *   3. a failed sign-out-everywhere tells the person the OTHER devices were not signed out (it must say so);
 *   4. the view model methods the screen calls exist, and each one calls a real session controller method
 *      (`signOutEverywhere`, `setAutomaticSignIn`, `deviceSignInStatus`);
 *   5. self-tests: a direct call, a missing gate and a missing method are each reported.
 *
 * Mutations tried when this was written (each turned it red, then was restored): calling
 * `signOutEverywhere()` directly from the destructive button; drawing `devicesSection` without the switch
 * check; renaming the controller's `signOutEverywhere`.
 *
 * Usage: node tests/test-apple-account-device-controls.js
 */
import { appSwiftFiles, read, stripSwift, callArguments, braceBlock, selfTest } from './lib/swift-source.js';

selfTest();

let failures = 0;
function check(name, cond, detail) {
    if (cond) { console.log('  PASS  ' + name); return; }
    failures++;
    console.log('  FAIL  ' + name + (detail ? '\n        ' + String(detail).slice(0, 700) : ''));
}

const files = appSwiftFiles();
const find = (suffix) => files.find((f) => f.endsWith(suffix));

/** Judge the three sources (already stripped). PURE. */
function judge(view, vm, controller) {
    const problems = [];

    // 1. the destructive call only inside the confirmation dialog (or its "sign out anyway" second step)
    const dialogs = callArguments(view, '.confirmationDialog');
    const regionOf = (d) => {
        const argsStart = d.index;
        const afterArgs = view.indexOf('{', argsStart + d.args.length);
        const trailing = afterArgs >= 0 ? braceBlock(view, afterArgs) : null;
        // the message closure follows the action closure: include a second trailing closure when present
        let end = trailing ? trailing.end : argsStart + d.args.length;
        const next = view.slice(end + 1).match(/^\s*message\s*:\s*\{/);
        if (next) {
            const open = end + 1 + next[0].length - 1;
            const second = braceBlock(view, open);
            if (second) end = second.end;
        }
        return { start: argsStart, end };
    };
    const everywhereDialogs = dialogs.filter((d) => /Sign out everywhere\?/.test(d.args));
    const unsentDialogs = dialogs.filter((d) => /have not reached your account/.test(d.args));
    if (everywhereDialogs.length !== 1) problems.push('expected exactly one "Sign out everywhere?" confirmation dialog, found ' + everywhereDialogs.length);
    if (unsentDialogs.length !== 1) problems.push('expected exactly one unsent-changes dialog, found ' + unsentDialogs.length);
    const everywhereRegion = everywhereDialogs.length === 1 ? regionOf(everywhereDialogs[0]) : null;
    const unsentRegion = unsentDialogs.length === 1 ? regionOf(unsentDialogs[0]) : null;
    // `signOutAgain` is the one function the unsent dialog uses to retry; its body is the second allowed place
    const againAt = view.search(/func\s+signOutAgain\s*\(/);
    const againOpen = againAt >= 0 ? view.indexOf('{', againAt) : -1;
    const againBlock = againOpen >= 0 ? braceBlock(view, againOpen) : null;
    const inRegion = (r, at) => r && at >= r.start && at <= r.end;
    const own = [...view.matchAll(/(^|[^.A-Za-z0-9_])signOutEverywhere\s*\(/g)]
        .filter((m) => !/\bfunc\s+$/.test(view.slice(Math.max(0, m.index - 12), m.index + m[1].length)));
    if (own.length === 0) problems.push('the screen never calls its own signOutEverywhere()');
    for (const m of own) {
        const at = m.index + m[1].length;
        const allowed = inRegion(everywhereRegion, at) || (againBlock && at > againBlock.start && at < againBlock.end);
        if (!allowed) problems.push('signOutEverywhere() is called outside the confirmation dialog');
    }
    // and signOutAgain itself may only be started from inside the unsent-changes dialog
    for (const m of view.matchAll(/(^|[^.A-Za-z0-9_])signOutAgain\s*\(/g)) {
        const at = m.index + m[1].length;
        if (/\bfunc\s+$/.test(view.slice(Math.max(0, m.index - 12), m.index + m[1].length))) continue;
        if (!inRegion(unsentRegion, at)) problems.push('signOutAgain() is called outside the unsent-changes dialog');
    }

    // 2. gates
    const sectionUses = [...view.matchAll(/\bdevicesSection\b/g)].map((m) => m.index);
    const gated = sectionUses.filter((i) => /deviceStatus\?\.switchedOn\s*==\s*true/.test(view.slice(Math.max(0, i - 120), i)));
    // one use is the declaration (`var devicesSection`), the others are uses
    const uses = sectionUses.filter((i) => !/\bvar\s+$/.test(view.slice(Math.max(0, i - 6), i)));
    if (uses.length === 0) problems.push('devicesSection is never used');
    if (uses.some((i) => !gated.includes(i))) problems.push('devicesSection is drawn without checking deviceStatus?.switchedOn == true');
    const toggleAt = view.search(/\bToggle\s*\(/);
    if (toggleAt < 0) problems.push('no automatic sign-in Toggle found');
    else if (!/canOfferAutomaticSignIn\s*==\s*true/.test(view.slice(Math.max(0, toggleAt - 120), toggleAt))) problems.push('the automatic sign-in Toggle is not gated on canOfferAutomaticSignIn');

    // 3. the failure wording
    if (!/other devices were not signed out/i.test(view.replace(/\s+/g, ' ')) && !/other devices were not signed out/i.test(view)) {
        problems.push('the failure message must say the other devices were not signed out');
    }

    // 4. the view model pass-throughs
    for (const [vmName, ctlName] of [['signOutEverywhere', 'signOutEverywhere'], ['setAutomaticSignIn', 'setAutomaticSignIn'], ['deviceSignInStatus', 'deviceSignInStatus']]) {
        if (!new RegExp('func\\s+' + vmName + '\\s*\\(').test(vm)) problems.push('the view model has no ' + vmName + '()');
        if (!new RegExp('sessionController\\.' + ctlName + '\\s*\\(').test(vm)) problems.push('the view model ' + vmName + '() does not call sessionController.' + ctlName + '()');
        if (!new RegExp('func\\s+' + ctlName + '\\s*\\(').test(controller)) problems.push('the session controller has no ' + ctlName + '()');
    }
    for (const call of ['signOutEverywhere', 'setAutomaticSignIn', 'deviceSignInStatus']) {
        if (!new RegExp('rootViewModel\\.' + call + '\\s*\\(').test(view)) problems.push('the screen never calls rootViewModel.' + call + '()');
    }
    return problems;
}

const viewFile = find('IHFeatures/AccountView.swift');
const vmFile = find('IHFeatures/AppRootViewModel+DeviceSignIn.swift');
const ctlFile = find('IHAuth/SessionController+DeviceSignIn.swift');
check('found the account view, the view model extension and the controller extension', !!viewFile && !!vmFile && !!ctlFile);
const view = stripSwift(read(viewFile), { blankStrings: false });
const vm = stripSwift(read(vmFile), { blankStrings: true });
const controller = stripSwift(read(ctlFile), { blankStrings: true });
const real = judge(view, vm, controller);
check('the account screen keeps its three promises', real.length === 0, real.join('\n        '));

/* ---- self-tests ---- */
const mutate = (src, from, to) => { const out = src.replace(from, to); if (out === src) throw new Error('self-test mutation changed nothing: ' + from); return out; };
check('self-test: a direct call from a plain button is caught',
    judge(mutate(view, /isConfirmingSignOutEverywhere = true/, 'Task { await signOutEverywhere() }'), vm, controller).length > 0);
check('self-test: starting the retry from a plain button (skipping the unsent-changes question) is caught',
    judge(mutate(view, /isConfirmingSignOutEverywhere = true/, 'Task { await signOutAgain(UnsentPrompt(everywhere: true, unsent: .none), discardUnsent: true) }'), vm, controller).length > 0);
check('self-test: drawing the section without the switch check is caught',
    judge(mutate(view, /if deviceStatus\?\.switchedOn == true \{/, 'if true {'), vm, controller).length > 0);
check('self-test: an ungated Toggle is caught',
    judge(mutate(view, /if deviceStatus\?\.canOfferAutomaticSignIn == true \{/, 'if true {'), vm, controller).length > 0);
check('self-test: a failure message that stops saying the other devices were not signed out is caught',
    judge(mutate(view, /Your other devices were not signed out\./, 'Something failed.'), vm, controller).length > 0);
check('self-test: a missing controller method is caught',
    judge(view, vm, mutate(controller, /func signOutEverywhere\s*\(/, 'func signOutEverywhereX(')).length > 0);
check('self-test: a view model that stops calling the controller is caught',
    judge(view, mutate(vm, /sessionController\.setAutomaticSignIn/, 'sessionController.somethingElse'), controller).length > 0);

if (failures > 0) {
    console.log('\n' + failures + ' check(s) FAILED');
    process.exit(1);
}
console.log('\nAll checks passed.');
