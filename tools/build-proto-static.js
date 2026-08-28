/**
 * iHymns — ProPresenter Proto STATIC module builder (#1788)
 * =========================================================
 *
 * Emits a CSP-safe, precompiled `protobufjs` **static** encoder for the
 * ProPresenter 7+ (`rv.data.Presentation`) schema.
 *
 * WHY (the bug this fixes):
 * The runtime exporter used to load the schema as a REFLECTION descriptor
 * (`protobuf.Root.fromJSON(proto-bundle.json)`) and then call
 * `Presentation.encode(...)`. protobufjs builds a reflected type's
 * encoder/decoder LAZILY by generating a function via `new Function(...)`
 * (its `@protobufjs/codegen` path). The public site sends an ENFORCING nonce
 * CSP (`script-src 'self' 'nonce-…'`, no `'unsafe-eval'` — #117), so that
 * `new Function()` is refused and every ProPresenter 7+ export died with
 * "Evaluating a string as JavaScript violates … 'unsafe-eval'". (Only PP7
 * hit it — every other format is plain text/XML and never evals.)
 *
 * THE FIX (protobufjs's own recommendation for eval-disallowed environments):
 * pre-generate the encoder as STATIC code (`pbjs -t static`), which writes the
 * encode/verify/create logic out longhand against the hand-written
 * `protobuf.Writer` — NO `new Function`, NO `eval`. See
 * https://github.com/protobufjs/protobuf.js#pbjs-for-javascript ("If you can't
 * use eval … use the static code generator").
 *
 * SHAPE: we emit the BARE `-t static` body (which references a free `$protobuf`
 * runtime and builds a `$root` namespace) and wrap it in a tiny IIFE that
 * feeds it the already-loaded global `protobuf` (the full `protobuf.min.js`
 * runtime the pages already ship) and exposes the built tree as
 * `window.iHymnsPP7Proto`. This keeps it loadable from a plain `<script>` tag
 * — no bundler, no AMD/CommonJS — exactly like the reflection bundle it
 * replaces, and reusing the SAME runtime the app already loads.
 *
 * TRIM: the exporter only ENCODES (never decodes) and builds its payload
 * internally, so we keep `.create()` + `.encode()` and drop `--no-decode`,
 * `--no-verify` (the exporter's redundant pre-encode guard is removed with it —
 * ~500 KB saved), `--no-convert` (fromObject/toObject/toJSON) and
 * `--no-delimited` to keep the artifact as small as the encode path allows.
 *
 * `--keep-case` MUST match the reflection bundle (built with `keepCase: true`)
 * so the field names the exporter's `buildPresentation()` writes line up with
 * the static classes' fields exactly.
 *
 * Output: appWeb/public_html/manage/editor/protos/pp7-proto-static.js
 *
 * Usage:  node tools/build-proto-static.js   (or: npm run build:proto-static)
 * Re-run whenever the vendored `.proto` files change (alongside
 * build-proto-bundle.js — the JSON bundle stays for any reflection reader).
 *
 * PREREQUISITE (build-time only, deliberately NOT a committed devDependency):
 *   npm i -D protobufjs-cli        # protobuf.js 8 split the CLI into this pkg
 * The GENERATED artifact (protos/pp7-proto-static.js) IS committed, so runtime
 * and CI never need the CLI — only someone regenerating after a schema change
 * does. Keeping it out of package.json avoids dragging ~115 transitive build
 * packages (and their known-vulnerable sub-deps) into the committed lock for a
 * tool that runs maybe once a ProPresenter version.
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved. Proprietary.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import pbjs from 'protobufjs-cli/pbjs.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const PROJECT_ROOT = path.resolve(__dirname, '..');

const PROTO_DIR = path.join(
    PROJECT_ROOT, 'appWeb', 'public_html', 'manage', 'editor', 'protos', 'proto-7.16'
);
const OUTPUT_PATH = path.join(
    PROJECT_ROOT, 'appWeb', 'public_html', 'manage', 'editor', 'protos', 'pp7-proto-static.js'
);

/* Same entry set as build-proto-bundle.js — presentation.proto pulls its full
   transitive closure; the rest are belt-and-braces so a future export that
   touches a new top-level type still compiles in. */
const ENTRY_POINTS = [
    'presentation.proto', 'cue.proto', 'action.proto', 'slide.proto',
    'presentationSlide.proto', 'graphicsData.proto', 'groups.proto',
    'uuid.proto', 'ccli.proto',
    /* #1968 P3 — .proplaylist EXPORT (set-list export). Adds encode/create for
       rv.data.PlaylistDocument (propresenter.proto) + rv.data.Playlist /
       rv.data.PlaylistItem (playlist.proto, transitively imported by
       propresenter.proto but listed explicitly for the same belt-and-braces
       reason as every other entry above). Every dependency message (URL, UUID,
       Cue, Action, Color, HotKey, MusicKeyScale, PlanningCenterPlan, …) resolves
       via the .proto `import` graph automatically — see build-proto-bundle.js's
       matching comment, kept in lockstep with this list by design (both files
       must stay in sync; see this file's own header doc-block). This module
       stays ENCODE-ONLY (`--no-decode` below, unchanged) — the .proplaylist
       IMPORT side already has its own independent PHP wire decoder
       (includes/propresenter7_playlist.php, #1968 P3 landed in #1973); nothing
       about this regen adds decode capability to the browser. */
    'propresenter.proto', 'playlist.proto',
];

function generateBareStatic() {
    return new Promise((resolve, reject) => {
        const args = [
            '-t', 'static',
            '--keep-case',
            '--no-decode',      // exporter never decodes
            '--no-verify',      // payload is built internally; guard removed in exporter too
            '--no-convert',     // no fromObject/toObject/toJSON
            '--no-delimited',   // no *Delimited helpers
            '--no-comments',    // shrink
            '-p', PROTO_DIR,
            ...ENTRY_POINTS.map((f) => path.join(PROTO_DIR, f)),
        ];
        pbjs.main(args, (err, output) => {
            if (err) { reject(err); return; }
            resolve(output);
        });
    });
}

const HEADER = `/**
 * iHymns — ProPresenter 7+ protobuf STATIC encoder (GENERATED, do not edit).
 *
 * GENERATED by tools/build-proto-static.js via \`pbjs -t static\` (#1788).
 * Re-generate with: node tools/build-proto-static.js
 *
 * CSP-safe drop-in for the old reflection path (protobuf.Root.fromJSON +
 * lazy runtime code-generation, which the enforcing nonce CSP #117 refuses).
 * This precompiled encoder uses only the hand-written protobuf.Writer.
 * Exposes the schema tree as window.iHymnsPP7Proto; access the message
 * type as \`window.iHymnsPP7Proto.rv.data.Presentation\` (with static
 * .verify()/.create()/.encode()). Requires the global \`protobuf\` runtime
 * (protobuf.min.js) to be loaded first — it reuses that same runtime.
 */
(function (global) {
    'use strict';
    var $protobuf = global.protobuf;
    if (!$protobuf || !$protobuf.Writer) {
        throw new Error('iHymnsPP7Proto: global protobuf runtime (protobuf.min.js) must load first');
    }
    /* ---- BEGIN pbjs -t static output ---- */
`;

const FOOTER = `
    /* ---- END pbjs -t static output ---- */
    global.iHymnsPP7Proto = $root;
})(typeof self !== 'undefined' ? self : this);
`;

async function main() {
    if (!fs.existsSync(PROTO_DIR)) {
        console.error(`Proto directory not found: ${PROTO_DIR}`);
        process.exit(1);
    }
    const bare = await generateBareStatic();
    if (!/\$root\b/.test(bare) || !/rv\s*=\s*\(function/.test(bare)) {
        console.error('Unexpected pbjs output — missing $root / rv namespace.');
        process.exit(1);
    }
    /* Indent the generated body two spaces so it reads as part of the IIFE. */
    const body = bare.replace(/^/gm, '    ');
    const wrapped = HEADER + body + FOOTER;
    fs.writeFileSync(OUTPUT_PATH, wrapped, 'utf8');
    const size = fs.statSync(OUTPUT_PATH).size;
    console.log(`Wrote ${path.relative(PROJECT_ROOT, OUTPUT_PATH)} (${size} bytes)`);
}

main().catch((err) => { console.error(err); process.exit(1); });
