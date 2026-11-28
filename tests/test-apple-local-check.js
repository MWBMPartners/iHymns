/**
 * tests/test-apple-local-check.js - the owner's one-command Apple check/build/release script stays safe and complete
 * (Apple local builds, plan `.claude/apple-local-builds-plan.md` section 3; rules #34, #46, #56)
 *
 * ELI5
 * ----
 * `appApple/Scripts/check.sh` runs on the owner's own Mac, where it can sign apps and upload them to Apple. It can
 * only be run on a Mac with Xcode, never here, so this guard reads the script as text and makes sure the safety rules
 * cannot be quietly dropped:
 *   1. it still has every command it promises (doctor, lint, test, build, all, device, release, help);
 *   2. it stops at the first error (`set -euo pipefail`);
 *   3. it never prints or reads out the release settings file or the App Store Connect key;
 *   4. a release refuses unless HEAD is on origin/<branch>, the tree is clean and the settings file is mode 600,
 *      puts the stamped version file back, writes one log line and never makes a git tag or release;
 *   5. the Fastfile's "local mode" skips the throw-away keychain and never deletes the owner's key, while the way
 *      GitHub runs it stays exactly as it was when the switch is not set;
 *   6. `*.p8` key files are git-ignored and none is tracked.
 *
 * Detailed (rule #34, #35)
 * ------------------------
 * The command list is DERIVED from the script itself (the arms of `main()` and the list in `usage`) and must agree
 * with each other; the typed list below is only a floor that stops a command being deleted from both at once. The
 * shell is read with full-line comments and here-document text (the usage text) removed first, so a comment or the
 * help text that merely MENTIONS `cat` or a file name can neither trip nor satisfy a check. Each rule is a pure
 * function over the sources, and the self-tests at the bottom run every rule on a deliberately broken in-memory
 * copy of the real source and require it to report a problem (a guard that was never seen to fail proves nothing).
 *
 * Mutations tried when this was written (each turned it red, then was restored): see the commit message.
 *
 * Usage: node tests/test-apple-local-check.js
 */
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const REPO = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const rel = (p) => path.join(REPO, p);
const read = (p) => fs.readFileSync(rel(p), 'utf8');

const CHECK = 'appApple/Scripts/check.sh';
const FASTFILE = 'appApple/fastlane/Fastfile';
const FLOOR_COMMANDS = ['doctor', 'lint', 'test', 'build', 'all', 'device', 'release', 'help'];

let failures = 0;
function check(name, cond, detail) {
    if (cond) { console.log('  PASS  ' + name); return; }
    failures++;
    console.log('  FAIL  ' + name + (detail ? '\n        ' + String(detail).slice(0, 700) : ''));
}

// ---------------------------------------------------------------------------------------------------------------
// Reading helpers
// ---------------------------------------------------------------------------------------------------------------

/** Shell code with here-document bodies and full-line comments removed (the shebang line is kept). */
function shellCode(src) {
    let s = src.replace(/<<-?\s*'?"?(\w+)"?'?[^\n]*\n[\s\S]*?\n\s*\1\n/g, (m) => m.split('\n')[0] + '\n');
    s = s.split('\n').filter((l) => !/^\s*#(?!!)/.test(l)).join('\n');
    return s;
}

/** The text between `name() {` and its closing `}` at column 0, or null. */
function shellFunc(code, name) {
    const m = code.match(new RegExp('^' + name + '\\(\\) \\{\\n([\\s\\S]*?)\\n\\}$', 'm'));
    return m ? m[1] : null;
}

/** Ruby code with full-line comments removed. */
function rubyCode(src) {
    return src.split('\n').filter((l) => !/^\s*#/.test(l)).join('\n');
}

/** Commands the dispatcher in `main()` accepts, and the ones the `usage` text lists. Both derived from the script. */
function dispatchCommands(code) {
    const body = shellFunc(code, 'main');
    if (body === null) return null;
    const names = new Set();
    for (const m of body.matchAll(/^\s+([a-z][a-z |-]*)\) /gm)) {
        for (const part of m[1].split('|')) {
            const n = part.trim();
            if (/^[a-z]+$/.test(n)) names.add(n);
        }
    }
    return names;
}
function usageCommands(raw) {
    const m = raw.match(/\nusage\(\) \{[\s\S]*?\nEOF\n/);
    if (!m) return null;
    const block = m[0].split('\nCommands\n')[1];
    if (!block) return null;
    const names = new Set();
    for (const line of block.split('\n')) {
        if (/^Settings/.test(line)) break;
        const c = line.match(/^ {2}([a-z]+)(?: |$)/);
        if (c) names.add(c[1]);
    }
    return names;
}

// ---------------------------------------------------------------------------------------------------------------
// The rules. Each takes sources and returns a list of problems (empty = fine). PURE.
// ---------------------------------------------------------------------------------------------------------------

function judgeCommands(raw) {
    const problems = [];
    const code = shellCode(raw);
    const dispatch = dispatchCommands(code);
    const usage = usageCommands(raw);
    if (!dispatch) return ['main() dispatcher not found'];
    if (!usage) return ['usage text not found'];
    for (const c of FLOOR_COMMANDS) {
        if (!dispatch.has(c)) problems.push('command "' + c + '" is not dispatched by main()');
        if (!usage.has(c)) problems.push('command "' + c + '" is not in the usage text');
    }
    for (const c of dispatch) if (!usage.has(c)) problems.push('"' + c + '" is dispatched but not listed in usage');
    for (const c of usage) if (!dispatch.has(c)) problems.push('"' + c + '" is listed in usage but not dispatched');
    return problems;
}

function judgeStrictMode(raw) {
    return /^set -euo pipefail$/m.test(shellCode(raw)) ? [] : ['`set -euo pipefail` is missing'];
}

function judgeNoSecretLeaks(raw) {
    const problems = [];
    const code = shellCode(raw);
    const lines = code.split('\n');
    lines.forEach((l, i) => {
        const where = 'line ' + (i + 1) + ': ' + l.trim();
        if (/\bset\s+-[a-z]*x/.test(l) || /\bxtrace\b/.test(l) || /\bbash\s+-[a-z]*x/.test(l)) problems.push('shell tracing is switched on, ' + where);
        if (/\b(cat|head|tail|base64|xxd|openssl|strings|less|more|od|hexdump)\b[^|;&]*(RELEASE_ENV_FILE|key_file|ASC_KEY_PATH|ASC_KEY_DIR|\.p8)/.test(l)) problems.push('a secret file is read out, ' + where);
        if (/(^|[;&|(]\s*|\s)(source|\.)\s+["$]*\{?(RELEASE_ENV_FILE|key_file)/.test(l)) problems.push('the settings file is run as a script, ' + where);
        if (/\b(echo|printf)\b[^\n]*\$\{?(APPLE_TEAM_ID|ASC_KEY_ID|ASC_ISSUER_ID|ASC_API_KEY)\b/.test(l)) problems.push('a release setting value is printed, ' + where);
        if (/\b(echo|printf)\b[^\n]*(key_content|ASC_API_KEY)/.test(l)) problems.push('key content is printed, ' + where);
    });
    // The settings file is read in exactly one place: the parsing loop's redirect.
    const reads = (code.match(/<\s*"\$RELEASE_ENV_FILE"/g) || []).length;
    if (reads !== 1) problems.push('expected exactly one read of the settings file (the parsing loop), found ' + reads);
    // Only the three known names are taken from it.
    const loader = shellFunc(code, 'load_release_env') || '';
    for (const k of ['APPLE_TEAM_ID', 'ASC_KEY_ID', 'ASC_ISSUER_ID']) {
        if (!new RegExp('\\b' + k + '\\) ' + k + '=').test(loader)) problems.push('load_release_env does not pick up ' + k);
    }
    if (/\bexport\s+"\$/.test(loader) || /\beval\b/.test(code)) problems.push('settings are exported or evaluated generically');
    return problems;
}

function judgeFixedPaths(raw) {
    const problems = [];
    const code = shellCode(raw);
    if (!/^RELEASE_ENV_FILE="\$\{HOME\}\/\.config\/ihymns\/apple-release\.env"$/m.test(code)) problems.push('settings file path is not the fixed ~/.config/ihymns/apple-release.env');
    if (!/^ASC_KEY_DIR="\$\{HOME\}\/\.appstoreconnect\/private_keys"$/m.test(code)) problems.push('key directory is not the fixed ~/.appstoreconnect/private_keys');
    return problems;
}

function judgeRelease(raw) {
    const problems = [];
    const code = shellCode(raw);
    const release = shellFunc(code, 'cmd_release');
    const guard = shellFunc(code, 'release_git_guard');
    const loader = shellFunc(code, 'load_release_env');
    const restore = shellFunc(code, 'restore_xcconfig');
    if (release === null) return ['cmd_release not found'];
    if (guard === null) problems.push('release_git_guard not found');
    if (loader === null) problems.push('load_release_env not found');
    if (restore === null) problems.push('restore_xcconfig not found');
    if (guard !== null) {
        if (!/merge-base --is-ancestor HEAD "origin\/\$\{branch\}"/.test(guard)) problems.push('release does not check that HEAD is on origin/<branch>');
        if (!/\bfetch\b/.test(guard)) problems.push('release does not fetch origin/<branch> first');
        if (!/status --porcelain/.test(guard)) problems.push('release does not check for a clean tree');
        if (!/\[ -z "\$dirty" \] \|\| die/.test(guard)) problems.push('release does not refuse on a dirty tree');
    }
    if (loader !== null && !(/file_mode "\$RELEASE_ENV_FILE"/.test(loader) && /\[ "\$mode" = "600" \] \|\| die/.test(loader))) problems.push('the settings file is not required to be mode 600');
    if (!(/key_mode="\$\(file_mode "\$key_file"\)"/.test(release) && /\[ "\$key_mode" = "600" \] \|\| die/.test(release))) problems.push('the key file is not required to be mode 600');
    // Branch mapping.
    for (const [t, b] of [['alpha', 'alpha'], ['beta', 'beta'], ['appstore', 'main']]) {
        if (!new RegExp(t + '\\) branch="' + b + '"').test(release)) problems.push('release ' + t + ' is not tied to origin/' + b);
    }
    // Order: guard -> settings -> trap armed -> fastlane -> restore -> log line.
    const at = (re) => { const m = release.match(re); return m ? m.index : -1; };
    const iGuard = at(/release_git_guard "\$branch"/);
    const iEnv = at(/load_release_env\n/);
    const iArm = at(/RELEASE_RESTORE_ARMED=1/);
    const iTrap = at(/trap restore_xcconfig EXIT/);
    const iLocal = at(/export IHYMNS_LOCAL_SIGNING=1/);
    const iRun = at(/fastlane "\$lane"/);
    const iRestore = at(/\n\s*restore_xcconfig\n/);
    const iLog = at(/>> "\$RELEASE_LOG"/);
    const order = [['git guard', iGuard], ['settings', iEnv], ['arm restore', iArm], ['trap', iTrap], ['local-signing switch', iLocal], ['fastlane', iRun], ['restore', iRestore], ['log line', iLog]];
    for (const [n, i] of order) if (i < 0) problems.push('release step missing: ' + n);
    if (!order.some(([, i]) => i < 0)) {
        if (!(iGuard < iEnv && iEnv < iArm && iArm < iRun && iTrap < iRun && iLocal < iRun && iRun < iRestore && iRestore < iLog)) {
            problems.push('release steps are out of order (need: git guard, settings, arm restore + trap, local switch, fastlane, restore, log)');
        }
    }
    // Nothing that tags, releases, pushes or commits.
    if (/\bgit\b[^\n]*\b(tag|push|commit)\b/.test(code) || /\bgh\s+release\b/.test(code)) problems.push('the script makes a git tag/push/commit or a GitHub release (rule #46)');
    // The version file is put back from git.
    if (restore !== null && !/checkout -- "\$XCCONFIG_REL"/.test(restore)) problems.push('restore_xcconfig does not put the committed file back');
    if (!/^XCCONFIG_REL="appApple\/Config\/Versioning\.xcconfig"$/m.test(code)) problems.push('XCCONFIG_REL does not name Versioning.xcconfig');
    // Dirty-tree check must not hide anything except the log.
    if (guard !== null && !/-- \. ":\(exclude\)\$\{RELEASE_LOG_REL\}"/.test(guard)) problems.push('the clean-tree check excludes something other than the release log');
    return problems;
}

function judgeFastfile(raw) {
    const problems = [];
    const code = rubyCode(raw);
    if (!/^LOCAL_SIGNING = ENV\["IHYMNS_LOCAL_SIGNING"\] == "1"$/m.test(code)) problems.push('LOCAL_SIGNING is not defined from IHYMNS_LOCAL_SIGNING == "1" (it must be off unless exactly "1")');
    // The certificate lane skips in local mode, before it makes a keychain.
    const cert = code.match(/private_lane :import_distribution_certificate do\n([\s\S]*?)\nend\n/);
    if (!cert) problems.push('import_distribution_certificate lane not found');
    else {
        const iSkip = cert[1].indexOf('next if LOCAL_SIGNING');
        const iMake = cert[1].indexOf('create_keychain(');
        if (iSkip < 0) problems.push('import_distribution_certificate does not skip in local mode');
        else if (iMake < 0 || iSkip > iMake) problems.push('the local-mode skip must come before create_keychain');
    }
    // Every use of LOCAL_SIGNING is one of the reviewed shapes.
    const allowed = [
        /^LOCAL_SIGNING = ENV\["IHYMNS_LOCAL_SIGNING"\] == "1"$/,
        /^\s*return local_asc_key_path if LOCAL_SIGNING\b/,
        /^\s*next if LOCAL_SIGNING$/,
        /^\s*if LOCAL_SIGNING$/,
        /^\s*delete_keychain\(name: CI_KEYCHAIN_NAME\) if !LOCAL_SIGNING && File\.exist\?\(CI_KEYCHAIN_PATH\)$/,
    ];
    code.split('\n').forEach((l) => {
        if (/LOCAL_SIGNING/.test(l) && !/^\s*end\b/.test(l) && !allowed.some((re) => re.test(l))) problems.push('unreviewed use of LOCAL_SIGNING: ' + l.trim());
    });
    // Every keychain delete must be switched off in local mode (it would remove the owner's real keychain otherwise).
    for (const l of code.split('\n')) {
        if (/delete_keychain\(/.test(l) && !/!LOCAL_SIGNING/.test(l)) problems.push('delete_keychain is not skipped in local mode: ' + l.trim());
    }
    // Order inside asc_key_file_path: the local return comes before the throw-away copy is made.
    const fn = code.match(/^def asc_key_file_path\n([\s\S]*?)\nend\n/m);
    if (!fn) problems.push('asc_key_file_path not found');
    else if (!(fn[1].indexOf('return local_asc_key_path if LOCAL_SIGNING') >= 0 && fn[1].indexOf('return local_asc_key_path') < fn[1].indexOf('@asc_key_file ||='))) problems.push('asc_key_file_path must return the local key before @asc_key_file ||=');
    // The owner's real key is never deleted: the local lookup must not be stored in @asc_key_file, and the only rm_f calls are on @asc_key_file.
    const local = code.match(/^def local_asc_key_path\n([\s\S]*?)\nend\n/m);
    if (!local) problems.push('local_asc_key_path not found');
    else if (/@asc_key_file/.test(local[1])) problems.push('local_asc_key_path stores the owner key in @asc_key_file (the clean-up hooks delete that file)');
    for (const m of code.matchAll(/rm_f\(([^)]*)\)/g)) {
        if (!/^(@asc_key_file|cert_path)$/.test(m[1].trim())) problems.push('rm_f on something other than the throw-away files: ' + m[1]);
    }
    // The local key lane reads the file in place and never carries key content.
    const asc = code.match(/private_lane :asc_api_key do\n([\s\S]*?)\nend\n/);
    if (!asc) problems.push('asc_api_key lane not found');
    else {
        const localBranch = (asc[1].match(/if LOCAL_SIGNING\n([\s\S]*?)\n  end\n/) || [])[1];
        if (!localBranch) problems.push('asc_api_key has no local branch');
        else {
            if (!/key_filepath: local_asc_key_path/.test(localBranch)) problems.push('the local asc_api_key branch does not read the key file in place');
            if (/key_content|ASC_API_KEY/.test(localBranch)) problems.push('the local asc_api_key branch uses key content');
        }
    }
    // CI path, byte for byte where it matters.
    const ci = [
        'create_keychain(\n    name: CI_KEYCHAIN_NAME,\n    password: CI_KEYCHAIN_PASSWORD,\n    default_keychain: true,\n    unlock: true,\n    timeout: 3600,\n    lock_when_sleeps: false\n  )',
        'File.binwrite(cert_path, Base64.decode64(ENV.fetch("APPLE_CERTIFICATE")))',
        'certificate_password: ENV.fetch("APPLE_CERTIFICATE_PASSWORD", ""),',
        'File.write(path, ENV.fetch("ASC_API_KEY"))',
        'key_content: ENV.fetch("ASC_API_KEY"),\n    is_key_content_base64: false,\n    in_house: false',
        'File.join(Dir.tmpdir, "ihymns-asc-#{SecureRandom.hex(4)}.p8")',
        'FileUtils.rm_f(@asc_key_file) if @asc_key_file',
        'after_all do |_lane|',
        'error do |_lane, _exception|',
        'ios_ipa = archive_scheme(scheme: "iHymns",   destination: "generic/platform=iOS")',
    ];
    for (const s of ci) if (!code.includes(s)) problems.push('the GitHub (CI) path changed: missing `' + s.split('\n')[0] + '`');
    return problems;
}

function judgeGitignore(gi, p8Tracked) {
    const problems = [];
    const lines = gi.split('\n').map((l) => l.trim());
    if (!lines.includes('*.p8')) problems.push('.gitignore does not ignore *.p8');
    if (!lines.includes('apple-release*.env')) problems.push('.gitignore does not ignore apple-release*.env');
    if (!lines.includes('*.p12')) problems.push('.gitignore no longer ignores *.p12');
    if (p8Tracked.length) problems.push('a .p8 key file is in the tree: ' + p8Tracked.join(', '));
    return problems;
}

function judgeDocs(raw, logMd, guide) {
    const problems = [];
    if (!logMd || !/^## Builds$/m.test(logMd)) problems.push('release-log.md is missing or has no "## Builds" heading');
    if (!guide) problems.push('Local-Builds.md is missing');
    else {
        const dispatch = dispatchCommands(shellCode(raw)) || new Set();
        for (const c of dispatch) {
            if (c === 'help') continue;
            if (!guide.includes('check.sh ' + c)) problems.push('Local-Builds.md does not mention "check.sh ' + c + '"');
        }
    }
    return problems;
}

/** Folder walk (no git needed) for any .p8 file, skipping dependency and build folders. */
function findP8() {
    const out = [];
    const walk = (dir) => {
        for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
            if (['node_modules', '.git', 'vendor'].includes(e.name)) continue;
            const full = path.join(dir, e.name);
            if (e.isDirectory()) walk(full);
            else if (e.name.endsWith('.p8')) out.push(path.relative(REPO, full));
        }
    };
    walk(REPO);
    return out;
}

// ---------------------------------------------------------------------------------------------------------------
// Run on the real tree
// ---------------------------------------------------------------------------------------------------------------
const real = {
    check: read(CHECK),
    fastfile: read(FASTFILE),
    gitignore: read('.gitignore'),
    logMd: fs.existsSync(rel('appApple/dev-docs/release-log.md')) ? read('appApple/dev-docs/release-log.md') : '',
    guide: fs.existsSync(rel('appApple/dev-docs/Local-Builds.md')) ? read('appApple/dev-docs/Local-Builds.md') : '',
};
const p8 = findP8();

function report(name, problems) { check(name, problems.length === 0, problems.join('\n        ')); }

console.log('check.sh');
report('every promised command is dispatched and listed in usage (derived from the script)', judgeCommands(real.check));
report('stops at the first error: set -euo pipefail', judgeStrictMode(real.check));
report('never prints or reads out the release settings or the key; no shell tracing', judgeNoSecretLeaks(real.check));
report('settings file and key folder are the fixed paths in the owner\'s home', judgeFixedPaths(real.check));
report('release: git guard, clean tree, mode 600, order, restore, log line, no tag/push', judgeRelease(real.check));
check('check.sh is executable', (fs.statSync(rel(CHECK)).mode & 0o111) !== 0);

console.log('Fastfile');
report('local mode skips the throw-away keychain and never deletes the owner key; CI path unchanged', judgeFastfile(real.fastfile));

console.log('files');
report('.gitignore ignores *.p8 and apple-release*.env; no .p8 in the tree', judgeGitignore(real.gitignore, p8));
report('release-log.md and Local-Builds.md exist and cover every command', judgeDocs(real.check, real.logMd, real.guide));

console.log('syntax (skipped when the tool is not installed)');
{
    const bash = spawnSync('bash', ['-n', rel(CHECK)], { encoding: 'utf8' });
    if (bash.error) console.log('  SKIP  bash not available');
    else check('bash -n check.sh', bash.status === 0, bash.stderr);
    const ruby = spawnSync('ruby', ['-c', rel(FASTFILE)], { encoding: 'utf8' });
    if (ruby.error) console.log('  SKIP  ruby not available');
    else check('ruby -c Fastfile', ruby.status === 0, ruby.stderr || ruby.stdout);
}

// ---------------------------------------------------------------------------------------------------------------
// Self-tests (rule #34): every rule must go red on a deliberately broken copy of the real source.
// ---------------------------------------------------------------------------------------------------------------
console.log('self-tests');
function mut(src, from, to) {
    const out = typeof from === 'string' ? src.replace(from, to) : src.replace(from, to);
    if (out === src) throw new Error('self-test mutation did not apply: ' + String(from).slice(0, 60));
    return out;
}
const bad = (name, problems) => check('self-test: ' + name, problems.length > 0);

bad('a deleted command is caught', judgeCommands(mut(real.check, /^ {8}device\) cmd_device "\$@" ;;\n/m, '')));
bad('a command missing from usage is caught', judgeCommands(mut(real.check, /^ {2}release alpha\|beta\|appstore\n/m, '')));
bad('a missing strict mode is caught', judgeStrictMode(mut(real.check, /^set -euo pipefail$/m, 'set -eu')));
bad('cat of the settings file is caught', judgeNoSecretLeaks(mut(real.check, 'load_release_env() {\n', 'load_release_env() {\n    cat "$RELEASE_ENV_FILE" > /dev/null\n')));
bad('printing a setting value is caught', judgeNoSecretLeaks(mut(real.check, 'load_release_env() {\n', 'load_release_env() {\n    printf "%s\\n" "$ASC_KEY_ID"\n')));
bad('shell tracing is caught', judgeNoSecretLeaks(mut(real.check, 'set -euo pipefail\n', 'set -euo pipefail\nset -x\n')));
bad('running the settings file as a script is caught', judgeNoSecretLeaks(mut(real.check, 'load_release_env() {\n', 'load_release_env() {\n    source "$RELEASE_ENV_FILE"\n')));
bad('a second read of the settings file is caught', judgeNoSecretLeaks(mut(real.check, 'load_release_env() {\n', 'load_release_env() {\n    while read -r _x; do :; done < "$RELEASE_ENV_FILE"\n')));
bad('a moved settings path is caught', judgeFixedPaths(mut(real.check, '${HOME}/.config/ihymns/apple-release.env', '${HOME}/apple-release.env')));
bad('release without the branch check is caught', judgeRelease(mut(real.check, /merge-base --is-ancestor HEAD/, 'merge-base HEAD')));
bad('release without the clean-tree refusal is caught', judgeRelease(mut(real.check, '[ -z "$dirty" ] || die', '[ -z "$dirty" ] || true #')));
bad('a settings file that may be group-readable is caught', judgeRelease(mut(real.check, '[ "$mode" = "600" ] || die', '[ -n "$mode" ] || die')));
bad('a key file with loose permissions is caught', judgeRelease(mut(real.check, '[ "$key_mode" = "600" ] || die', '[ -n "$key_mode" ] || die')));
bad('the appstore lane pointing at the wrong branch is caught', judgeRelease(mut(real.check, 'appstore) branch="main"', 'appstore) branch="alpha"')));
bad('forgetting to put the version file back is caught', judgeRelease(mut(real.check, '    restore_xcconfig\n\n    local who stamp', '    local who stamp')));
bad('a missing trap is caught', judgeRelease(mut(real.check, 'trap restore_xcconfig EXIT', 'true')));
bad('a missing log line is caught', judgeRelease(mut(real.check, '>> "$RELEASE_LOG"', '> /dev/null')));
bad('making a git tag is caught', judgeRelease(mut(real.check, 'local who stamp', 'git tag "v$version"\n    local who stamp')));
bad('local mode not skipping the keychain is caught', judgeFastfile(mut(real.fastfile, '  next if LOCAL_SIGNING\n', '')));
bad('the skip placed after create_keychain is caught', judgeFastfile(mut(real.fastfile, /  next if LOCAL_SIGNING\n\n  create_keychain\(([\s\S]*?)\n  \)\n/, '  create_keychain($1\n  )\n  next if LOCAL_SIGNING\n')));
bad('a switch that is on unless unset is caught', judgeFastfile(mut(real.fastfile, 'ENV["IHYMNS_LOCAL_SIGNING"] == "1"', 'ENV["IHYMNS_LOCAL_SIGNING"] != "0"')));
bad('the owner key being recorded for deletion is caught', judgeFastfile(mut(real.fastfile, '  path = File.expand_path(ENV.fetch("ASC_KEY_PATH"))\n', '  path = @asc_key_file = File.expand_path(ENV.fetch("ASC_KEY_PATH"))\n')));
bad('a changed CI keychain is caught', judgeFastfile(mut(real.fastfile, 'default_keychain: true', 'default_keychain: false')));
bad('a changed CI key source is caught', judgeFastfile(mut(real.fastfile, 'key_content: ENV.fetch("ASC_API_KEY"),', 'key_content: ENV.fetch("ASC_API_KEY_X"),')));
bad('a keychain delete that ignores local mode is caught', judgeFastfile(mut(real.fastfile, 'after_all do |_lane|\n  delete_keychain(name: CI_KEYCHAIN_NAME) if !LOCAL_SIGNING && File.exist?', 'after_all do |_lane|\n  delete_keychain(name: CI_KEYCHAIN_NAME) if File.exist?')));
bad('a clean-up that deletes the owner key is caught', judgeFastfile(mut(real.fastfile, 'FileUtils.rm_f(@asc_key_file) if @asc_key_file\nend\n\nerror', 'FileUtils.rm_f(File.expand_path(ENV.fetch("ASC_KEY_PATH")))\nend\n\nerror')));
bad('a missing *.p8 ignore is caught', judgeGitignore(mut(real.gitignore, /^\*\.p8$/m, ''), []));
bad('a missing env-file ignore is caught', judgeGitignore(mut(real.gitignore, /^apple-release\*\.env$/m, ''), []));
bad('a tracked .p8 is caught', judgeGitignore(real.gitignore, ['appApple/AuthKey_X.p8']));
bad('a guide that forgets a command is caught', judgeDocs(real.check, real.logMd, real.guide.replace(/check\.sh device/g, 'check.sh dev')));

if (failures > 0) {
    console.log('\n' + failures + ' check(s) FAILED');
    process.exit(1);
}
console.log('\nAll checks passed.');
