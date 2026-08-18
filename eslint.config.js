/**
 * iHymns — ESLint flat config
 *
 * WHY THIS FILE DID NOT EXIST UNTIL NOW
 * ------------------------------------
 * `package.json` has advertised `lint`, `lint:js`, `lint:css` and `lint:html`
 * for a long time. **None of them could ever run.** ESLint, stylelint and
 * htmlhint were not declared as dependencies, no ESLint config existed in
 * either format, and no CI workflow invoked `npm run lint`. Running it produced
 * a crash, not a report.
 *
 * That is the same shape as every other failure this branch has been fixing: a
 * thing that LOOKS like a quality gate, is cited as one, and has never once
 * executed. `api-docs.yaml` documenting a header its own client never sent
 * (#1677) is the same species — documentation and configuration are not
 * mechanisms unless something runs them.
 *
 * COVERAGE (#1874)
 * ----------------
 * The FIRST version of this file linted `appWeb/public_html/js/**` only — the
 * public PWA tree — which silently left the entire `appWeb/public_html/manage/**`
 * tree (all of Editor2 v2, the #1862-rebuilt `metadata-tab.js`, and the legacy
 * v1 editor) OUTSIDE the lint net: a file that matches no `files` block is
 * skipped without a word, the exact wrong-but-green class this doc-block warns
 * about above. Running the same bug rules over the manage tree immediately found
 * a real `no-undef` bug in `metadata-tab.js` (an out-of-scope `song`). The manage
 * tree is now a first-class config block below, and `npm run lint:js` points at
 * both trees so CI covers them equally. Coverage is asserted, not assumed, by
 * `tests/test-eslint-coverage.js` (mutation-proven).
 *
 * WHAT THIS CONFIG DELIBERATELY DOES *NOT* DO
 * -------------------------------------------
 * It is not a style enforcer. Quotes, semicolons, indentation and line length
 * are left entirely alone: this codebase has a consistent hand-written voice,
 * and a mass auto-format would bury real history in `git blame` for zero
 * correctness gain. Rule #34's warning applies to linters too — a check so
 * noisy that people stop reading it is worse than no check.
 *
 * So the rules here are the ones that catch REAL BUGS, chosen because each has
 * a corresponding failure already in this repo's history:
 *
 *   no-undef            — a typo'd or never-defined identifier. This is the
 *                         class behind #1710 (`$currentUser` in a fragment
 *                         nothing populates) in its JavaScript form.
 *   no-unused-vars      — dead bindings, which is how orphaned code hides
 *                         (#1696: an exported helper with zero callers).
 *   no-dupe-keys /
 *   no-dupe-class-members / no-duplicate-case
 *                       — a later key silently wins; the earlier one reads as
 *                         live code and never runs.
 *   no-unreachable      — code after return/throw. Reads as behaviour, is not.
 *   no-fallthrough      — this app dispatches through very large switches.
 *   require-atomic-updates / no-async-promise-executor / no-await-in-loop(off)
 *                       — async correctness, and this client is async-heavy.
 *   eqeqeq              — `==` against a mixed-type API payload.
 *
 * @see https://eslint.org/docs/latest/use/configure/configuration-files
 */

/* Browser + PWA surface actually used by this codebase. Listed explicitly
   rather than pulled from a `globals` package so the config has no dependency
   of its own and cannot itself become a thing that fails to run. Extracted to a
   const (#1874) so the public `js/**` block and the `manage/**` block share ONE
   list rather than two copies that could drift (the modularity rule). */
const browserGlobals = {
    window: 'readonly',
    document: 'readonly',
    navigator: 'readonly',
    location: 'readonly',
    history: 'readonly',
    localStorage: 'readonly',
    sessionStorage: 'readonly',
    fetch: 'readonly',
    Request: 'readonly',
    Response: 'readonly',
    Headers: 'readonly',
    URL: 'readonly',
    URLSearchParams: 'readonly',
    FormData: 'readonly',
    Blob: 'readonly',
    File: 'readonly',
    FileReader: 'readonly',
    AbortController: 'readonly',
    CustomEvent: 'readonly',
    Event: 'readonly',
    EventTarget: 'readonly',
    MutationObserver: 'readonly',
    IntersectionObserver: 'readonly',
    ResizeObserver: 'readonly',
    console: 'readonly',
    setTimeout: 'readonly',
    clearTimeout: 'readonly',
    setInterval: 'readonly',
    clearInterval: 'readonly',
    requestAnimationFrame: 'readonly',
    cancelAnimationFrame: 'readonly',
    queueMicrotask: 'readonly',
    atob: 'readonly',
    btoa: 'readonly',
    crypto: 'readonly',
    TextEncoder: 'readonly',
    TextDecoder: 'readonly',
    Notification: 'readonly',
    PushManager: 'readonly',
    ServiceWorkerRegistration: 'readonly',
    caches: 'readonly',
    indexedDB: 'readonly',
    IDBKeyRange: 'readonly',
    matchMedia: 'readonly',
    getComputedStyle: 'readonly',
    DOMParser: 'readonly',
    XMLSerializer: 'readonly',
    Image: 'readonly',
    Audio: 'readonly',
    alert: 'readonly',
    confirm: 'readonly',
    prompt: 'readonly',
    structuredClone: 'readonly',
    performance: 'readonly',
    screen: 'readonly',
    CSS: 'readonly',
    HTMLElement: 'readonly',
    HTMLInputElement: 'readonly',
    HTMLSelectElement: 'readonly',
    HTMLTextAreaElement: 'readonly',
    HTMLAnchorElement: 'readonly',
    HTMLButtonElement: 'readonly',
    HTMLFormElement: 'readonly',
    HTMLImageElement: 'readonly',
    Node: 'readonly',
    NodeList: 'readonly',
    Element: 'readonly',
    DocumentFragment: 'readonly',
    /* Third-party globals loaded by <script> rather than imported. */
    bootstrap: 'readonly',
    protobuf: 'readonly',
};

/* The bug-catching rule set (see the doc-block above for the why of each).
   Extracted to a const (#1874) so the public and manage blocks apply the SAME
   rules — the whole point of extending the net is to run the identical checks
   over the previously-unlinted tree, not a second, weaker variant. */
const bugRules = {
    'no-undef': 'error',
    'no-unused-vars': ['error', {
        args: 'none',                 // callback signatures document intent
        caughtErrors: 'none',         // `catch (e) {}` with an ignored e is idiomatic here
        varsIgnorePattern: '^_',
    }],
    'no-dupe-keys': 'error',
    'no-dupe-class-members': 'error',
    'no-duplicate-case': 'error',
    'no-unreachable': 'error',
    'no-fallthrough': 'error',
    'no-async-promise-executor': 'error',
    /* require-atomic-updates is OFF, deliberately.
       It flags the idiomatic `btn.disabled = true; await work();
       btn.disabled = false;` pattern as a race — six times in this
       codebase, every one of them CORRECT code. The rule cannot tell a
       DOM property write from shared mutable state.
       A guard that fails on correct code gets weakened or deleted
       rather than fixed (rule #34), and that is exactly what would
       happen to `npm run lint` on its first real run. Off, with the
       reason recorded, beats on-and-ignored. */
    'require-atomic-updates': 'off',
    /* Catches `foo === bar;` written where `foo = bar;` was meant — a
       statement that computes and discards. The ONE legitimate use in
       this codebase is a forced reflow (`el.offsetHeight;`), which
       already carries an inline disable comment explaining itself;
       enabling the rule is what makes that comment true again. */
    'no-unused-expressions': 'error',
    'no-self-compare': 'error',
    'no-constant-condition': ['error', { checkLoops: false }],
    'no-cond-assign': ['error', 'except-parens'],
    'no-sparse-arrays': 'error',
    'valid-typeof': 'error',
    'use-isnan': 'error',
    eqeqeq: ['error', 'smart'],
};

export default [
    {
        /* Global ignores (#1874) — vendored + generated JS under the manage
           tree is not ours to lint. `protobuf.min.js` is a minified third-party
           bundle (237 spurious errors); `pp7-proto-static.js` is generated
           protobuf output. A bare `ignores`-only object is flat config's
           project-wide ignore list. */
        ignores: [
            'appWeb/public_html/manage/editor/vendor/**',
            'appWeb/public_html/manage/editor/protos/**',
        ],
    },
    {
        files: ['appWeb/public_html/js/**/*.js'],
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'module',
            globals: browserGlobals,
        },
        rules: bugRules,
    },
    {
        /* The service worker is a DIFFERENT global scope — no window, no
           localStorage — and that distinction is load-bearing (rule #31 names
           it as the one deliberate exception to apiFetch). Linting it with the
           window globals above would hide exactly the mistake worth catching:
           reaching for a window-only API from inside the worker. */
        files: ['appWeb/public_html/js/**/service-worker*.js', 'appWeb/public_html/service-worker*.js'],
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'module',
            globals: {
                self: 'readonly',
                caches: 'readonly',
                clients: 'readonly',
                fetch: 'readonly',
                Request: 'readonly',
                Response: 'readonly',
                Headers: 'readonly',
                URL: 'readonly',
                console: 'readonly',
                skipWaiting: 'readonly',
                registration: 'readonly',
            },
        },
    },
    {
        /* The `/manage/**` admin tree (#1874) — Editor2 v2 modules, the
           #1862 metadata tab and the legacy v1 editor. SAME bug rules as the
           public tree; two extra browser globals these files legitimately use:
           `XMLHttpRequest` (editor.js) and `MouseEvent` (tags-tab.js). */
        files: ['appWeb/public_html/manage/**/*.js'],
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: 'module',
            globals: {
                ...browserGlobals,
                XMLHttpRequest: 'readonly',
                MouseEvent: 'readonly',
            },
        },
        rules: bugRules,
    },
    {
        /* Two export modules carry a UMD footer so the SAME source can be
           required by the Node test harness (test-propresenter-export.js /
           test-export-ui.js) as well as loaded in the browser. The footer
           references `module`, `global` and `Buffer` behind `typeof` guards —
           real, intentional, and only reachable under Node. Declaring them here
           (readonly, scoped to just these two files) keeps `no-undef` honest for
           the rest of the manage tree rather than weakening the whole block. */
        files: [
            'appWeb/public_html/manage/editor/format-export.js',
            'appWeb/public_html/manage/editor/propresenter-export.js',
        ],
        languageOptions: {
            globals: {
                module: 'readonly',
                global: 'readonly',
                Buffer: 'readonly',
            },
        },
    },
];
