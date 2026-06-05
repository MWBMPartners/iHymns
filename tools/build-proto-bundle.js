/**
 * iHymns — ProPresenter Proto Bundle Builder
 * ==========================================
 *
 * Compiles the vendored ProPresenter 7+ `.proto` schema files into a
 * single JSON descriptor that the browser editor loads at runtime.
 *
 * The schema is sourced verbatim from greyshirtguy/ProPresenter7-Proto
 * (Proto 7.16) and lives in
 * `appWeb/private_html/editor/protos/proto-7.16/`. We use the full set
 * of `.proto` files (not just `presentation.proto`) because protobufjs
 * resolves imports lazily and we want all transitive dependencies
 * baked into the descriptor.
 *
 * Output:
 *   appWeb/private_html/editor/protos/proto-bundle.json
 *
 * Usage:
 *   node tools/build-proto-bundle.js
 *   npm run build:proto
 *
 * Re-run this whenever the vendored `.proto` files change.
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved.
 * This software is proprietary.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import protobuf from 'protobufjs';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const PROJECT_ROOT = path.resolve(__dirname, '..');
const PROTO_DIR = path.join(
    PROJECT_ROOT, 'appWeb', 'private_html', 'editor', 'protos', 'proto-7.16'
);
const OUTPUT_PATH = path.join(
    PROJECT_ROOT, 'appWeb', 'private_html', 'editor', 'protos', 'proto-bundle.json'
);

/* Load presentation.proto first — it transitively imports the
   dependencies the exporter actually needs. We then explicitly load
   any further files the exporter touches so a future schema change
   that introduces a new top-level type still gets included. */
const ENTRY_POINTS = [
    'presentation.proto',
    /* Defensive belt-and-braces in case a future export touches these
       directly without going through `Presentation`. */
    'cue.proto',
    'action.proto',
    'slide.proto',
    'presentationSlide.proto',
    'graphicsData.proto',
    'groups.proto',
    'uuid.proto',
    'ccli.proto'
];

async function main() {
    if (!fs.existsSync(PROTO_DIR)) {
        console.error(`Proto directory not found: ${PROTO_DIR}`);
        process.exit(1);
    }

    const root = new protobuf.Root();

    /* Resolve imports against the local proto directory. protobufjs
       walks the imports graph automatically; we just point it at the
       right folder for everything that isn't a google/* well-known
       proto (which protobufjs ships internally). */
    root.resolvePath = (origin, target) => {
        if (target.startsWith('google/')) return null;

        /* If the target is an absolute path (the entry point we pass
           to root.load below), use it directly. */
        if (path.isAbsolute(target)) {
            return fs.existsSync(target) ? target : null;
        }

        /* Otherwise resolve relative to the proto directory. */
        const local = path.join(PROTO_DIR, target);
        if (fs.existsSync(local)) return local;

        /* And fall back to resolving against the importing file. */
        if (origin) {
            const sibling = path.join(path.dirname(origin), target);
            if (fs.existsSync(sibling)) return sibling;
        }
        return null;
    };

    for (const entry of ENTRY_POINTS) {
        const entryPath = path.join(PROTO_DIR, entry);
        if (!fs.existsSync(entryPath)) {
            console.warn(`Skipping missing entry: ${entry}`);
            continue;
        }
        await root.load(entryPath, { keepCase: true });
    }

    /* Ensure every type is fully resolved before serialising — this
       catches missing imports up-front rather than at export time in
       the browser. */
    root.resolveAll();

    const json = root.toJSON({ keepComments: false });

    /* Sanity-check: the type the exporter relies on must be present. */
    const probe = protobuf.Root.fromJSON(json);
    const Presentation = probe.lookupType('rv.data.Presentation');
    if (!Presentation) {
        console.error('rv.data.Presentation not present in the bundle.');
        process.exit(1);
    }

    fs.writeFileSync(OUTPUT_PATH, JSON.stringify(json, null, 2) + '\n', 'utf8');
    const size = fs.statSync(OUTPUT_PATH).size;
    console.log(`Wrote ${path.relative(PROJECT_ROOT, OUTPUT_PATH)} (${size} bytes)`);
    console.log(`rv.data.Presentation has ${Object.keys(Presentation.fields).length} fields.`);
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});
