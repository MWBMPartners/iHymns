/**
 * tests/lib/swift-source.js - shared helpers for the guards that read the Apple app's Swift source
 * (account rewrite PR 3b, epic #2180)
 *
 * ELI5
 * ----
 * Swift cannot be compiled here, so the guards that keep the Apple sign-in code honest read the source
 * as text. To do that fairly they must (1) find the files by walking the tree rather than from a typed
 * list, (2) ignore comments (a comment that merely MENTIONS a forbidden thing must not fail the guard,
 * and a comment must never satisfy a check), and (3) pull out the arguments of a call so a check can ask
 * "does this call pass `since:`?". This file does those three things once, so no guard re-writes them.
 *
 * Detailed (rule #34 / #35)
 * -------------------------
 * - `swiftFiles()` walks `appApple/` and returns every `.swift` file, skipping build output. Callers pick
 *   "app code" (no `Tests/` folder) or "tests" from the path.
 * - `stripSwift(src, {blankStrings})` removes `//` and nested block comments (Swift block comments nest)
 *   while leaving string literals alone, so a `"https://..."` string does not look like a comment. It
 *   understands normal, multi-line (`"""`) and raw (`#"..."#`) strings and `\( ... )` interpolation. With
 *   `blankStrings` the string CONTENTS are replaced by nothing, which is what a "does the code call X"
 *   check wants; without it the contents stay, which is what a "which keychain service name" check wants.
 * - `callArguments(code, name)` returns the text between the parentheses of every call to `name(`,
 *   with balanced parentheses, skipping declarations (`func name(`).
 * - `selfTest()` proves those functions can fail: a comment-only mention is invisible, a nested comment
 *   is fully removed, a URL string survives, and an unbalanced slice is caught.
 *
 * Used by: tests/test-apple-setlist-protocol.js, test-apple-keychain-custody.js, and the later Apple guards.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
export const REPO = path.resolve(HERE, '..', '..');
export const APPLE = path.join(REPO, 'appApple');
export const KIT_SOURCES = path.join(APPLE, 'Packages', 'iHymnsKit', 'Sources');

/** Every .swift file under appApple/, as repo-relative paths (sorted), skipping build output. */
export function swiftFiles() {
    const out = [];
    const walk = (dir) => {
        for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
            if (['.build', 'DerivedData', 'node_modules'].includes(entry.name) || entry.name.endsWith('.xcodeproj')) continue;
            const full = path.join(dir, entry.name);
            if (entry.isDirectory()) walk(full);
            else if (entry.name.endsWith('.swift')) out.push(path.relative(REPO, full));
        }
    };
    walk(APPLE);
    return out.sort();
}

/** App (non-test) Swift files: anything not under a `Tests/` folder. */
export function appSwiftFiles() {
    return swiftFiles().filter((f) => !/(^|\/)Tests\//.test(f));
}

/** Read a repo-relative file. */
export function read(rel) {
    return fs.readFileSync(path.join(REPO, rel), 'utf8');
}

/**
 * Remove comments from Swift source, leaving strings alone.
 * @param {string} src
 * @param {{blankStrings?: boolean}} [opts]  replace string contents with nothing
 */
export function stripSwift(src, opts = {}) {
    const blank = !!opts.blankStrings;
    let out = '';
    let i = 0;
    const n = src.length;

    /** Copy a string literal starting at i (at the opening quote or the # run). Returns the new index. */
    const copyString = () => {
        let hashes = 0;
        let j = i;
        while (src[j] === '#') { hashes++; j++; }
        const multi = src.startsWith('"""', j);
        const open = multi ? '"""' : '"';
        const close = open + '#'.repeat(hashes);
        const escape = '\\' + '#'.repeat(hashes);
        out += src.slice(i, j) + open;
        j += open.length;
        while (j < n) {
            if (src.startsWith(escape, j)) {
                if (src[j + escape.length] === '(') {
                    // interpolation: copy the balanced expression, which may itself hold strings
                    let depth = 1;
                    let k = j + escape.length + 1;
                    if (!blank) out += src.slice(j, k);
                    while (k < n && depth > 0) {
                        if (src[k] === '"') {
                            const save = i; i = k;
                            const savedOut = out; out = '';
                            const next = copyString();
                            const inner = out; out = savedOut;
                            if (!blank) out += inner;
                            i = save; k = next; continue;
                        }
                        if (src[k] === '(') depth++;
                        if (src[k] === ')') depth--;
                        if (!blank || depth > 0) { /* expression text is code, not string content */ }
                        if (!blank) out += src[k];
                        k++;
                    }
                    j = k;
                    continue;
                }
                if (!blank) out += src.slice(j, j + escape.length + 1);
                j += escape.length + 1;
                continue;
            }
            if (src.startsWith(close, j)) {
                out += close;
                return j + close.length;
            }
            if (!blank) out += src[j];
            j++;
        }
        return j;
    };

    while (i < n) {
        const c = src[i];
        if (c === '/' && src[i + 1] === '/') {
            while (i < n && src[i] !== '\n') i++;
            continue;
        }
        if (c === '/' && src[i + 1] === '*') {
            let depth = 1;
            i += 2;
            while (i < n && depth > 0) {
                if (src[i] === '/' && src[i + 1] === '*') { depth++; i += 2; continue; }
                if (src[i] === '*' && src[i + 1] === '/') { depth--; i += 2; continue; }
                i++;
            }
            continue;
        }
        if (c === '"' || (c === '#' && /^#+"/.test(src.slice(i, i + 12)))) {
            i = copyString();
            continue;
        }
        out += c;
        i++;
    }
    return out;
}

/**
 * The argument text of every call to `name(` in already-stripped `code`, with balanced parentheses.
 * Declarations (`func name(`) are skipped. Returns [{args, index}].
 */
export function callArguments(code, name) {
    const out = [];
    const re = new RegExp('(^|[^A-Za-z0-9_])' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*\\(', 'g');
    let m;
    while ((m = re.exec(code)) !== null) {
        const before = code.slice(Math.max(0, m.index - 40), m.index + m[1].length);
        if (/\bfunc\s*$/.test(before)) continue;
        const start = m.index + m[0].length;
        let depth = 1;
        let k = start;
        while (k < code.length && depth > 0) {
            if (code[k] === '(') depth++;
            else if (code[k] === ')') depth--;
            k++;
        }
        if (depth !== 0) throw new Error('unbalanced parentheses after ' + name + '( at offset ' + m.index);
        out.push({ args: code.slice(start, k - 1), index: m.index });
    }
    return out;
}

/** Throws if the helpers above cannot do their job. Run by each guard before trusting them. */
export function selfTest() {
    const fail = (m) => { throw new Error('swift-source self-test: ' + m); };
    const a = stripSwift('let x = 1 // setlistsSync(\nlet y = 2');
    if (a.includes('setlistsSync')) fail('a line comment leaked');
    const b = stripSwift('a /* one /* nested */ still comment */ b');
    if (b.replace(/\s+/g, '') !== 'ab') fail('a nested block comment leaked: ' + JSON.stringify(b));
    const c = stripSwift('let u = "https://ihymns.app/api" // trailing');
    if (!c.includes('https://ihymns.app/api')) fail('a URL string was treated as a comment');
    if (c.includes('trailing')) fail('a comment after a string leaked');
    const d = stripSwift('let s = "app.ihymns.token"', { blankStrings: true });
    if (d.includes('app.ihymns.token')) fail('blankStrings kept string contents');
    const e = stripSwift('let r = #"a // not a comment"#\nlet z = 1');
    if (!e.includes('not a comment')) fail('a raw string lost its content');
    const f = stripSwift('let m = """\n  x // inside\n  """\nlet w = 1');
    if (!f.includes('x // inside')) fail('a multi-line string lost its content');
    const calls = callArguments('foo(a, bar(b), c)\nfunc foo(x: Int) {}', 'foo');
    if (calls.length !== 1 || calls[0].args !== 'a, bar(b), c') fail('callArguments mis-sliced: ' + JSON.stringify(calls));
    let threw = false;
    try { callArguments('foo(a, (b', 'foo'); } catch { threw = true; }
    if (!threw) fail('an unbalanced call was not caught');
}
